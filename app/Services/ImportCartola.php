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
use App\Support\Reconciliation\MatchResult;
use App\Support\Reconciliation\ScoredCandidate;
use App\Support\Text;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Imports an uploaded cartola: parses it, stores the file for audit,
 * writes one {@see BankMovement} per line, and asks the
 * {@see MatchEngine} which company each deposit might belong to.
 *
 * Almost everything it produces is a suggestion for the staff member on
 * /conciliacion-bancaria to decide. The exception is a deposit that
 * carries the payer's RUT *and* the exact plan amount with no rival
 * candidate: there is nothing left for a human to add, so the import
 * records that payment itself and the movement arrives conciliada. See
 * {@see self::qualifiesForAutoConfirm()} — and `auto_confirm.enabled` in
 * config/bank_reconciliation.php, which switches the behaviour off
 * entirely, because a recorded payment cannot be undone from the UI.
 */
class ImportCartola
{
    /** @var CartolaReader */
    private $reader;

    /** @var MatchEngine */
    private $engine;

    /** @var ConfirmMovement */
    private $confirmer;

    public function __construct(CartolaReader $reader, MatchEngine $engine, ConfirmMovement $confirmer)
    {
        $this->reader = $reader;
        $this->engine = $engine;
        $this->confirmer = $confirmer;
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

        // Movement id per company, for the deposits that qualify to be
        // reconciled without a human. Filled while storing, acted on once
        // the import has committed.
        $autoConfirm = [];

        try {
            $statement = $this->store($movements, $bank, $originalName, $storedPath, $hash, $userId, $autoConfirm);
        } catch (\Throwable $e) {
            // Nothing was imported, so the upload is now an orphan the
            // file-hash guard would never point at again.
            Storage::disk('cartolas')->delete($storedPath);

            throw $e;
        }

        // Deliberately outside the import transaction: recording a payment
        // touches three more tables, and a failure there must leave the
        // imported movements in the queue rather than roll the whole
        // cartola back.
        $statement->autoConfirmedCount = $this->confirmAll($autoConfirm, $userId);

        return $statement;
    }

    /**
     * @param  Movement[]  $movements
     * @param  array<int,int>  $autoConfirm  Out: movement id keyed by empresa id.
     */
    private function store(array $movements, string $bank, string $originalName, string $storedPath, string $hash, ?int $userId, array &$autoConfirm): BankStatement
    {
        return DB::transaction(function () use ($movements, $bank, $originalName, $storedPath, $hash, $userId, &$autoConfirm) {
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

                $autoConfirmEmpresaId = null;
                $attributes = $this->attributesFor($movement, $statement->id, $candidates, $autoConfirmEmpresaId);

                try {
                    $stored = BankMovement::create($attributes);
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

                // One automatic payment per company per cartola. A second
                // deposit of the same amount from the same payer is as
                // likely to be a duplicate transfer as a second month, and
                // that is a judgement call — it stays in the queue.
                if ($autoConfirmEmpresaId !== null && !isset($autoConfirm[$autoConfirmEmpresaId])) {
                    $autoConfirm[$autoConfirmEmpresaId] = $stored->id;
                }
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

    /**
     * @param  int|null  $autoConfirmEmpresaId  Out: the company to reconcile this
     *                                          movement against without asking a
     *                                          human, or null (the usual case).
     */
    private function attributesFor(Movement $movement, int $statementId, array $candidates, ?int &$autoConfirmEmpresaId = null): array
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

        if ($this->qualifiesForAutoConfirm($result, $best)) {
            $autoConfirmEmpresaId = (int) $best->company->id;
        }

        // Still written as a suggestion. Auto-confirmation happens after
        // this transaction commits, so a movement whose payment could not
        // be recorded is left where staff will see it.
        return array_merge($attributes, [
            'status' => BankMovement::STATUS_SUGGESTED,
            'empresa_id' => $best->company->id,
            'match_confidence' => min(100, $best->score),
            'match_reason' => $best->reasonText() . $this->caveat($result),
        ]);
    }

    /**
     * The evidence that leaves a human nothing to add: the payer's RUT in
     * the glosa (check digit validated by {@see \App\Support\Cartola\Glosa::extractRut()})
     * and the plan amount to the peso, with no other company within the
     * tie margin.
     *
     * Decided on which signals fired, not on the score — the score is a
     * blend, and 80 points of weak signals is not the same evidence as
     * these two. Requiring `amount_exact` also keeps a partial transfer
     * off this path: {@see RecordPayment} buys a full period whatever the
     * amount, so a shortfall has to stay a visible choice.
     */
    private function qualifiesForAutoConfirm(MatchResult $result, ScoredCandidate $best): bool
    {
        if (!config('bank_reconciliation.auto_confirm.enabled', false)) {
            return false;
        }

        // Two companies this close apart means the evidence points at more
        // than one of them, however strong it is.
        if ($result->isAmbiguous) {
            return false;
        }

        return $best->hasAllSignals(
            (array) config('bank_reconciliation.auto_confirm.require_signals', [])
        );
    }

    /**
     * Records the payment for each qualifying deposit, after the import
     * itself has committed.
     *
     * A failure here is logged and the movement left as `suggested`: it
     * reappears in the review queue, which is the outcome the whole module
     * defaults to. Silence is the one thing that isn't acceptable — a
     * deposit that neither paid a customer nor asked for review is money
     * lost from the books.
     *
     * @param  array<int,int>  $autoConfirm  Movement id keyed by empresa id.
     */
    private function confirmAll(array $autoConfirm, ?int $userId): int
    {
        // suscriptor_payments.user_id is NOT NULL, so an import with no
        // known user cannot record anything; the queue takes over.
        if ($autoConfirm === [] || $userId === null) {
            return 0;
        }

        $confirmed = 0;

        foreach ($autoConfirm as $empresaId => $movementId) {
            $movement = BankMovement::find($movementId);
            $customer = Customer::find($empresaId);

            if ($movement === null || $customer === null || $movement->is_resolved) {
                continue;
            }

            try {
                if ($this->confirmer->confirm($movement, $customer, $userId, true) !== null) {
                    $confirmed++;
                }
            } catch (\Throwable $e) {
                Log::error('Auto-conciliación de movimiento bancario falló', [
                    'movement_id' => $movementId,
                    'empresa_id' => $empresaId,
                    'amount' => $movement->amount_clp,
                    'posted_at' => $movement->posted_at->toDateString(),
                    'reference' => $movement->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $confirmed;
    }

    /**
     * Why the suggestion still needs a human. The two reasons are
     * different and the staff member acts on them differently: a tie
     * means check the other candidate, a weak match means check the
     * customer.
     */
    private function caveat(MatchResult $result): string
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
