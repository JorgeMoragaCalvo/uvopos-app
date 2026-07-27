<?php

namespace App\Services;

use App\Models\BankMovement;
use App\Models\BankStatement;
use App\Models\Customer;
use App\Support\Cartola\CartolaException;
use App\Support\Cartola\CartolaReader;
use App\Support\Cartola\Movement;
use App\Support\Reconciliation\CandidateCompany;
use App\Support\Reconciliation\MatchEngine;
use App\Support\Text;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Imports an uploaded cartola: parses it, stores the file for audit,
 * writes one {@see BankMovement} per line, and asks the
 * {@see MatchEngine} which company each deposit might belong to.
 *
 * Importing never moves money. It produces suggestions; the staff member
 * on /conciliacion-bancaria is the one who turns a suggestion into a
 * payment.
 */
class ImportCartola
{
    /** @var CartolaReader */
    private $reader;

    /** @var MatchEngine */
    private $engine;

    public function __construct(CartolaReader $reader, MatchEngine $engine)
    {
        $this->reader = $reader;
        $this->engine = $engine;
    }

    /**
     * @param  string  $path          Readable path to the uploaded CSV.
     * @param  string  $originalName  Filename as the user uploaded it.
     * @param  string  $bank          Key from config('bank_reconciliation.banks').
     *
     * @throws CartolaException when the file is unreadable or already imported.
     */
    public function import(string $path, string $originalName, string $bank, ?int $userId): BankStatement
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new CartolaException('No se pudo leer el archivo de la cartola.');
        }

        $hash = hash('sha256', $contents);

        if (BankStatement::where('file_hash', $hash)->exists()) {
            throw new CartolaException('Esta cartola ya fue importada anteriormente.');
        }

        // Parse before storing anything: a file we can't read shouldn't
        // leave a statement row or an orphaned upload behind.
        $movements = $this->reader->read($path, $bank);

        $storedPath = 'cartolas/' . date('Y-m') . '/' . $hash . '.csv';
        Storage::disk('cartolas')->put($storedPath, $contents);

        try {
            return $this->store($movements, $bank, $originalName, $storedPath, $hash, $userId);
        } catch (\Throwable $e) {
            // Nothing was imported, so the upload is now an orphan the
            // file-hash guard would never point at again.
            Storage::disk('cartolas')->delete($storedPath);

            throw $e;
        }
    }

    /**
     * @param  Movement[]  $movements
     */
    private function store(array $movements, string $bank, string $originalName, string $storedPath, string $hash, ?int $userId): BankStatement
    {
        return DB::transaction(function () use ($movements, $bank, $originalName, $storedPath, $hash, $userId) {
            $dates = array_map(function (Movement $movement) {
                return $movement->postedAt->toDateString();
            }, $movements);
            sort($dates);

            $statement = BankStatement::create([
                'bank' => $bank,
                'period_start' => reset($dates),
                'period_end' => end($dates),
                'original_filename' => $originalName,
                'stored_path' => $storedPath,
                'file_hash' => $hash,
                'imported_by' => $userId,
                'movement_count' => 0,
            ]);

            $candidates = $this->candidates();
            $imported = 0;

            foreach ($movements as $movement) {
                if ($this->alreadyImported($movement)) {
                    continue;
                }

                try {
                    BankMovement::create($this->attributesFor($movement, $statement->id, $candidates));
                } catch (QueryException $e) {
                    // Two overlapping cartolas imported at the same moment:
                    // the check above passed for both, the unique index on
                    // row_hash is what actually decides. Same outcome as a
                    // duplicate found by the check — skip it. Anything that
                    // is not a uniqueness failure is a real error.
                    if (!$this->isDuplicateRow($e)) {
                        throw $e;
                    }

                    continue;
                }

                $imported++;
            }

            $statement->movement_count = $imported;
            $statement->save();

            return $statement;
        });
    }

    /**
     * Cartolas are exported by date range and those ranges overlap, so
     * the same deposit routinely arrives in two files. The row hash keeps
     * it from being offered for reconciliation twice.
     */
    private function alreadyImported(Movement $movement): bool
    {
        return BankMovement::where('row_hash', $movement->rowHash())->exists();
    }

    /**
     * SQLSTATE 23000 is "integrity constraint violation", which on this
     * insert can only be the unique index on `row_hash`.
     */
    private function isDuplicateRow(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    private function attributesFor(Movement $movement, int $statementId, array $candidates): array
    {
        $attributes = [
            'bank_statement_id' => $statementId,
            'posted_at' => $movement->postedAt,
            'description' => $movement->description,
            'reference' => $movement->reference,
            'amount' => $movement->amount,
            'direction' => $movement->direction,
            'counterparty_rut' => $movement->counterpartyRut,
            'row_hash' => $movement->rowHash(),
            'status' => BankMovement::STATUS_UNMATCHED,
        ];

        // A Transbank payout is not a customer paying — it is the money
        // from Webpay charges already recorded. It belongs to the
        // settlement comparison, not the customer review queue.
        if ($this->isTransbankPayout($movement)) {
            return array_merge($attributes, [
                'status' => BankMovement::STATUS_MATCHED,
                'match_reason' => 'Liquidación Transbank',
            ]);
        }

        $result = $this->engine->match($movement, $candidates);

        if (!$result->hasSuggestion()) {
            return $attributes;
        }

        $best = $result->best();

        return array_merge($attributes, [
            'status' => BankMovement::STATUS_SUGGESTED,
            'empresa_id' => $best->company->id,
            'match_confidence' => min(100, $best->score),
            'match_reason' => $best->reasonText() . $this->caveat($result),
        ]);
    }

    /**
     * Why the suggestion still needs a human. The two reasons are
     * different and the staff member acts on them differently: a tie
     * means check the other candidate, a weak match means check the
     * customer.
     */
    private function caveat(\App\Support\Reconciliation\MatchResult $result): string
    {
        if ($result->isAmbiguous) {
            return ' (revisar: hay otro candidato igual de probable)';
        }

        if (!$result->isHighConfidence) {
            return ' (revisar: coincidencia parcial)';
        }

        return '';
    }

    private function isTransbankPayout(Movement $movement): bool
    {
        if ($movement->direction !== Movement::CREDIT) {
            return false;
        }

        $glosa = Text::normalize($movement->description);

        foreach ((array) config('bank_reconciliation.transbank_glosa_patterns', []) as $pattern) {
            $needle = Text::normalize($pattern);

            // Whole words only. Tagging is silent and takes the movement
            // out of the customer queue, so a company name that merely
            // contains "tbk" must not be mistaken for a payout.
            if ($needle !== '' && preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $glosa) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every company, scored against once per movement. Built a single
     * time per import: the whole table is ~1.300 rows, so loading it is
     * cheaper than a query per movement, and the plan relation has to be
     * eager-loaded or `charge_amount` would N+1.
     *
     * @return CandidateCompany[]
     */
    private function candidates(): array
    {
        return Customer::query()
            ->with('activePlan')
            ->get()
            ->map(function (Customer $customer) {
                return CandidateCompany::fromCustomer($customer);
            })
            ->all();
    }
}
