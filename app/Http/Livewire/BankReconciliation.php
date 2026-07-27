<?php

namespace App\Http\Livewire;

use App\Models\BankMovement;
use App\Models\Customer;
use App\Services\ImportCartola;
use App\Services\RecordPayment;
use App\Services\SettlementReport;
use App\Support\Cartola\CartolaException;
use App\Support\Rut;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Staff screen for bank reconciliation: import a cartola, review the
 * deposits it contains, and confirm which customer each one paid for.
 *
 * Confirming is what records the payment, and it is always a deliberate
 * click — the import only ever produces suggestions. That mirrors the
 * module's existing rule for suspension: the system proposes, staff
 * decide, because both directions of a wrong guess cost a real customer
 * real money.
 */
class BankReconciliation extends Component
{
    use WithPagination;
    use WithFileUploads;

    protected $paginationTheme = 'bootstrap';

    public const TAB_MOVEMENTS = 'movimientos';
    public const TAB_IMPORT = 'importar';
    public const TAB_SETTLEMENTS = 'liquidaciones';

    /** @var string Which section of the page is showing. */
    public $tab = self::TAB_MOVEMENTS;

    /** @var string Bank key from config('bank_reconciliation.banks'). */
    public $bank = 'chile';

    /** @var \Livewire\TemporaryUploadedFile|null */
    public $cartola;

    /** @var string A BankMovement::STATUS_* constant, or '' for everything pending. */
    public $statusFilter = '';

    /** @var string|null Shown after a successful import. */
    public $importMessage = null;

    /** @var int|null Movement currently being assigned to a company by hand. */
    public $assigningMovementId = null;

    /** @var string RUT or ID typed into the manual assignment box. */
    public $assignSearch = '';

    /** @var int|null Movement awaiting the staff confirmation click. */
    public $confirmingMovementId = null;

    /** @var int|null Company chosen by hand for the movement above, if any. */
    public $confirmingEmpresaId = null;

    protected $queryString = [
        'tab' => ['except' => self::TAB_MOVEMENTS],
        'statusFilter' => ['except' => ''],
    ];

    protected $messages = [
        'cartola.required' => 'Seleccione el archivo de la cartola.',
        'cartola.mimes' => 'La cartola debe ser un archivo CSV.',
        'cartola.max' => 'El archivo supera el tamaño máximo permitido (5 MB).',
    ];

    protected function rules(): array
    {
        return [
            'cartola' => 'required|file|mimes:csv,txt|max:5120',
            'bank' => 'required|string|in:' . implode(',', array_keys($this->banks())),
        ];
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Parses and stores the uploaded cartola. Everything that can go
     * wrong with a bank export — wrong bank selected, an already imported
     * file, a layout we can't read — surfaces as a message on the form
     * rather than an exception page.
     */
    public function import(): void
    {
        $this->assertAdmin();
        $this->validate();

        try {
            $statement = app(ImportCartola::class)->import(
                $this->cartola->getRealPath(),
                $this->cartola->getClientOriginalName(),
                $this->bank,
                Auth::id()
            );
        } catch (CartolaException $e) {
            $this->addError('cartola', $e->getMessage());

            return;
        }

        $this->reset('cartola');
        $this->importMessage = 'Cartola importada: ' . $statement->movement_count . ' movimientos nuevos.';
        $this->tab = self::TAB_MOVEMENTS;
        $this->resetPage();
    }

    /**
     * Opens the confirmation step for a deposit. Nothing is written yet:
     * confirming records a payment, which is as consequential as
     * suspending an account, so it gets the same click-then-confirm
     * treatment {@see PaymentAlert::confirmSuspend()} gives suspension.
     */
    public function startConfirm(int $movementId, ?int $empresaId = null): void
    {
        $this->confirmingMovementId = $movementId;
        $this->confirmingEmpresaId = $empresaId;
        $this->assigningMovementId = null;
        $this->assignSearch = '';
    }

    public function cancelConfirm(): void
    {
        $this->confirmingMovementId = null;
        $this->confirmingEmpresaId = null;
    }

    /**
     * Records the payment this deposit represents — the only action on
     * this screen that moves money.
     *
     * Two things keep it from doing so twice. The staff member must have
     * opened the confirmation step for this exact movement, and the
     * movement is claimed with a conditional UPDATE inside the
     * transaction: a second click, a stale tab or a second staff member
     * finds nothing left to claim and does nothing.
     */
    public function confirmMatch(int $movementId, ?int $empresaId = null): void
    {
        $this->assertAdmin();

        if ($this->confirmingMovementId !== $movementId) {
            return;
        }

        $movement = BankMovement::find($movementId);

        if ($movement === null || $movement->is_resolved || $movement->direction !== BankMovement::DIRECTION_CREDIT) {
            $this->cancelConfirm();

            return;
        }

        $customer = Customer::find($empresaId ?? $this->confirmingEmpresaId ?? $movement->empresa_id);

        if ($customer === null) {
            $this->addError('assignSearch', 'No se encontró la empresa seleccionada.');

            return;
        }

        DB::transaction(function () use ($movement, $customer) {
            $claimed = BankMovement::whereKey($movement->id)
                ->whereNotIn('status', [BankMovement::STATUS_MATCHED, BankMovement::STATUS_IGNORED])
                ->update([
                    'status' => BankMovement::STATUS_MATCHED,
                    'empresa_id' => $customer->id,
                    'reconciled_by' => Auth::id(),
                    'reconciled_at' => now(),
                ]);

            if ($claimed === 0) {
                return;
            }

            $payment = app(RecordPayment::class)->apply(
                $customer,
                $movement->amount_clp,
                Carbon::parse($movement->posted_at),
                RecordPayment::SOURCE_BANK_TRANSFER,
                $movement->reference,
                (int) Auth::id(),
                'Transferencia bancaria conciliada (' . $movement->posted_at->format('d/m/Y') . ')'
            );

            BankMovement::whereKey($movement->id)->update(['suscriptor_payment_id' => $payment->id]);
        });

        $this->cancelConfirm();
        $this->assigningMovementId = null;
        $this->assignSearch = '';
    }

    /**
     * Not a subscription payment — a refund, a supplier, an internal
     * transfer. Ignoring only clears it from the queue; nothing is
     * written to the customer.
     */
    public function ignoreMovement(int $movementId): void
    {
        $this->assertAdmin();

        $movement = BankMovement::find($movementId);

        if ($movement === null || $movement->is_resolved) {
            return;
        }

        $movement->status = BankMovement::STATUS_IGNORED;
        $movement->reconciled_by = Auth::id();
        $movement->reconciled_at = now();
        $movement->save();
    }

    /**
     * Undo for the two decisions that didn't move money: an ignore, and
     * the automatic "Liquidación Transbank" tag the importer applies.
     * Both remove a movement from the queue without anyone confirming
     * anything, so both need a way back — a customer transfer whose glosa
     * merely mentions Transbank would otherwise be lost silently.
     *
     * A movement that produced a payment is deliberately not reversible
     * here: unwinding a recorded payment is a financial decision, not a
     * queue correction.
     */
    public function returnToQueue(int $movementId): void
    {
        $this->assertAdmin();

        $movement = BankMovement::find($movementId);

        if ($movement === null || $movement->suscriptor_payment_id !== null) {
            return;
        }

        $movement->status = $movement->empresa_id === null
            ? BankMovement::STATUS_UNMATCHED
            : BankMovement::STATUS_SUGGESTED;
        $movement->match_reason = $movement->empresa_id === null ? null : $movement->match_reason;
        $movement->reconciled_by = null;
        $movement->reconciled_at = null;
        $movement->save();
    }

    public function startAssign(int $movementId): void
    {
        $this->assigningMovementId = $movementId;
        $this->assignSearch = '';
        $this->cancelConfirm();
        $this->resetErrorBag('assignSearch');
    }

    public function cancelAssign(): void
    {
        $this->assigningMovementId = null;
        $this->assignSearch = '';
        $this->resetErrorBag('assignSearch');
    }

    /**
     * Manual assignment for a deposit the engine couldn't place. Uses the
     * same RUT-or-ID rule as the lookup on /payment-alert.
     *
     * Resolving the RUT does not record anything: it hands the movement
     * to the same confirmation step a suggested match goes through, so a
     * hand-assigned deposit is checked against the plan charge too.
     */
    public function assignMovement(): void
    {
        $this->assertAdmin();

        $term = trim($this->assignSearch);

        if ($term === '') {
            $this->addError('assignSearch', 'Ingrese un RUT o ID de cliente.');

            return;
        }

        if (!(ctype_digit($term) && strlen($term) <= 6) && !Rut::isValid($term)) {
            $this->addError('assignSearch', 'El RUT ingresado no es válido.');

            return;
        }

        $customer = Customer::byRutOrId($term)->first();

        if ($customer === null) {
            $this->addError('assignSearch', 'No se encontró ninguna empresa con ese RUT o ID.');

            return;
        }

        $this->startConfirm((int) $this->assigningMovementId, (int) $customer->id);
    }

    /**
     * What the confirmation step has to say before money moves: which
     * company, how much arrived, and — the part staff can't see anywhere
     * else — whether the deposit falls short of the plan charge.
     *
     * The engine only ever *scores* on the amount; without this, a
     * customer who transfers half the charge would still buy a whole
     * period on a single click.
     *
     * @return array{movement: BankMovement, customer: Customer, shortfall: int}|null
     */
    public function confirmation(): ?array
    {
        if ($this->confirmingMovementId === null) {
            return null;
        }

        $movement = BankMovement::find($this->confirmingMovementId);

        if ($movement === null) {
            return null;
        }

        $customer = Customer::find($this->confirmingEmpresaId ?? $movement->empresa_id);

        if ($customer === null) {
            return null;
        }

        return [
            'movement' => $movement,
            'customer' => $customer,
            'shortfall' => $this->shortfall($movement->amount_clp, $customer->charge_amount),
        ];
    }

    /**
     * How far below the plan charge a deposit is, in CLP, or 0 when it is
     * close enough. Reuses the tolerance the match engine scores with, so
     * the queue and the warning agree on what "the right amount" means.
     */
    private function shortfall(int $paid, ?int $charge): int
    {
        if ($charge === null || $charge <= 0 || $paid >= $charge) {
            return 0;
        }

        $tolerance = max(
            (int) config('bank_reconciliation.amount_tolerance_clp', 1000),
            (int) round($charge * (float) config('bank_reconciliation.amount_tolerance_pct', 2) / 100)
        );

        $missing = $charge - $paid;

        return $missing > $tolerance ? $missing : 0;
    }

    /**
     * Livewire 2 re-runs only the persistent middleware on its own
     * requests, and EnsureUserIsAdmin is not among them — the route gate
     * protects the initial page load, not these actions. This screen
     * records payments, so every mutation re-checks for itself.
     */
    private function assertAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user !== null && $user->isAdmin(), 403);
    }

    private function banks(): array
    {
        return (array) config('bank_reconciliation.banks', []);
    }

    public function render()
    {
        $movements = BankMovement::query()
            ->with('customer')
            ->when($this->statusFilter !== '', function ($query) {
                return $query->where('status', $this->statusFilter);
            }, function ($query) {
                return $query->pending();
            })
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.bank-reconciliation', [
            'movements' => $movements,
            'confirmation' => $this->confirmation(),
            'banks' => $this->banks(),
            'counts' => [
                BankMovement::STATUS_SUGGESTED => BankMovement::where('status', BankMovement::STATUS_SUGGESTED)->count(),
                BankMovement::STATUS_UNMATCHED => BankMovement::where('status', BankMovement::STATUS_UNMATCHED)->count(),
                BankMovement::STATUS_MATCHED => BankMovement::where('status', BankMovement::STATUS_MATCHED)->count(),
                BankMovement::STATUS_IGNORED => BankMovement::where('status', BankMovement::STATUS_IGNORED)->count(),
            ],
            'settlements' => $this->tab === self::TAB_SETTLEMENTS
                ? app(SettlementReport::class)->forDeposits()
                : [],
        ]);
    }
}
