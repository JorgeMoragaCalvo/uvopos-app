<?php

namespace Tests\Feature;

use App\Http\Livewire\BankReconciliation;
use App\Models\BankMovement;
use App\Models\BankStatement;
use App\Models\Customer;
use App\Models\SuscriptorPayment;
use App\Models\User;
use App\Services\ImportCartola;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Importing reconciles the deposits whose evidence leaves nothing for a
 * human to decide: the payer's RUT in the glosa *and* the exact plan
 * amount, with no rival candidate.
 *
 * These payments are recorded with nobody reviewing them and cannot be
 * reversed from the UI, so what is tested here is mostly the boundary —
 * everything that must *not* auto-confirm. {@see BankReconciliationTest}
 * covers the same fixtures with the behaviour switched off.
 */
class AutoConfirmCartolaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('cartolas');

        config(['bank_reconciliation.auto_confirm.enabled' => true]);

        Carbon::setTestNow('2026-03-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../fixtures/cartolas/' . $name;
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 1,
        ]);
    }

    /**
     * A company due on 2026-03-01 at $35.000 — the RUT, amount and window
     * of the 02-03 deposit in banco-chile.csv.
     */
    private function company(
        string $name = 'Comercial Andes SpA',
        string $rut = '76543210-3',
        ?int $charge = 35000,
        string $due = '2026-03-01',
        string $estado = '1'
    ): Customer {
        $id = DB::table('empresas')->insertGetId([
            'rut' => $rut,
            'RazonSocial' => $name,
            'nombre_fantasia' => $name,
            'proximoPago' => $due,
            'tipoPlan' => 'Mensual',
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($charge !== null) {
            DB::table('datos_plan')->insert([
                'empresa_id' => $id,
                'plan_id' => 7,
                'monto_plan' => $charge,
                'monto_hardware' => 0,
                'fecha_vencimiento' => $due,
                'periodo_plan' => 'Mensual',
                'periodo_days' => 30,
                'estado' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Customer::find($id);
    }

    private function import(?User $user = null, string $fixture = 'banco-chile.csv', string $bank = 'chile'): BankStatement
    {
        return app(ImportCartola::class)->import(
            $this->fixture($fixture),
            $fixture,
            $bank,
            $user ? $user->id : null
        );
    }

    private function deposit(int $amount): BankMovement
    {
        return BankMovement::where('amount', $amount)->firstOrFail();
    }

    // ---------------------------------------------------------------
    // The one deposit that qualifies
    // ---------------------------------------------------------------

    public function test_a_rut_and_exact_amount_deposit_is_reconciled_on_import(): void
    {
        $admin = $this->admin();
        $customer = $this->company();

        $statement = $this->import($admin);

        $this->assertSame(1, $statement->autoConfirmedCount);

        $movement = $this->deposit(35000);
        $this->assertSame(BankMovement::STATUS_MATCHED, $movement->status);
        $this->assertTrue($movement->auto_confirmed);
        $this->assertSame($customer->id, (int) $movement->empresa_id);

        // Nobody decided it, so nobody is credited with the decision.
        $this->assertNull($movement->reconciled_by);
        $this->assertNotNull($movement->reconciled_at);

        $this->assertSame(1, SuscriptorPayment::count());

        $payment = SuscriptorPayment::first();
        $this->assertSame($payment->id, (int) $movement->suscriptor_payment_id);
        $this->assertSame($customer->id, (int) $payment->empresa_id);
        $this->assertSame(35000.0, (float) $payment->amount);

        // The payment is attributed to whoever uploaded the cartola:
        // suscriptor_payments.user_id is NOT NULL.
        $this->assertSame($admin->id, (int) $payment->user_id);
        $this->assertStringContainsString('automáticamente', $payment->notes);

        // Deposited 2026-03-02, one day after the due date, so the period
        // runs from the deposit — the same rule a staff confirmation gets.
        $this->assertSame('2026-04-01', $customer->fresh()->proximoPago->toDateString());
    }

    public function test_it_reactivates_a_suspended_company(): void
    {
        $customer = $this->company('Comercial Andes SpA', '76543210-3', 35000, '2026-03-01', '0');

        $this->import($this->admin());

        $this->assertSame('1', $customer->fresh()->estado);
    }

    // ---------------------------------------------------------------
    // Everything that must still wait for a human
    // ---------------------------------------------------------------

    public function test_an_exact_amount_without_the_rut_is_only_suggested(): void
    {
        // The 05-03 line, "TRANSF SERVICIOS DEL SUR", carries no RUT:
        // amount + date + name are enough to suggest, never to apply.
        $this->company('Servicios del Sur SpA', '77353398-9', 19900);

        $this->import($this->admin());

        $movement = $this->deposit(19900);

        $this->assertSame(BankMovement::STATUS_SUGGESTED, $movement->status);
        $this->assertFalse($movement->auto_confirmed);
        $this->assertSame(0, SuscriptorPayment::count());
    }

    /**
     * The same company registered twice — production has duplicates — puts
     * two identical candidates in front of the engine. The evidence is as
     * strong as ever and still points at two rows, so it is not ours to
     * resolve.
     */
    public function test_a_tie_is_never_auto_confirmed(): void
    {
        $this->company('Comercial Andes SpA');
        $this->company('Comercial Andes SpA');

        $this->import($this->admin());

        $this->assertSame(BankMovement::STATUS_SUGGESTED, $this->deposit(35000)->status);
        $this->assertSame(0, SuscriptorPayment::count());
    }

    /**
     * A partial transfer buys a full period through {@see \App\Services\RecordPayment},
     * so it has to stay a visible choice — which is what requiring the
     * exact amount guarantees.
     */
    public function test_a_short_deposit_is_never_auto_confirmed(): void
    {
        $this->company('Comercial Andes SpA', '76543210-3', 50000);

        $this->import($this->admin());

        $this->assertSame(BankMovement::STATUS_SUGGESTED, $this->deposit(35000)->status);
        $this->assertSame(0, SuscriptorPayment::count());
    }

    /**
     * Two deposits of the plan amount from the same payer are as likely to
     * be a duplicated transfer as two months paid up front. One is
     * recorded; the second is a judgement call.
     */
    public function test_only_one_deposit_per_company_is_auto_confirmed(): void
    {
        $this->company();

        $statement = $this->import($this->admin(), 'banco-chile-duplicado.csv');

        $this->assertSame(1, $statement->autoConfirmedCount);
        $this->assertSame(1, SuscriptorPayment::count());
        $this->assertSame(1, BankMovement::where('status', BankMovement::STATUS_MATCHED)->count());
        $this->assertSame(1, BankMovement::where('status', BankMovement::STATUS_SUGGESTED)->count());
    }

    /**
     * `suscriptor_payments.user_id` is NOT NULL, so an import with no known
     * user cannot record anything — the queue takes over rather than the
     * insert failing.
     */
    public function test_an_import_with_no_user_records_nothing(): void
    {
        $this->company();

        $statement = $this->import(null);

        $this->assertSame(0, $statement->autoConfirmedCount);
        $this->assertSame(BankMovement::STATUS_SUGGESTED, $this->deposit(35000)->status);
        $this->assertSame(0, SuscriptorPayment::count());
    }

    public function test_a_transbank_payout_is_still_only_tagged(): void
    {
        $this->company();

        $this->import($this->admin());

        $payout = $this->deposit(120000);

        $this->assertSame(BankMovement::STATUS_MATCHED, $payout->status);
        $this->assertFalse($payout->auto_confirmed);
        $this->assertNull($payout->empresa_id);
        $this->assertNull($payout->suscriptor_payment_id);
    }

    /**
     * The row hash already blocks the same deposit arriving in a second,
     * date-overlapping export. It must block the second payment too.
     */
    public function test_an_overlapping_cartola_does_not_pay_twice(): void
    {
        $admin = $this->admin();
        $this->company();

        $this->import($admin, 'banco-chile.csv');
        $this->import($admin, 'banco-chile-overlap.csv');

        $this->assertSame(1, SuscriptorPayment::count());
    }

    // ---------------------------------------------------------------
    // What the queue shows
    // ---------------------------------------------------------------

    /**
     * The point of the feature: the payment happened without anyone, so it
     * has to be visible where staff already look. A row nobody can find is
     * worse than no automation.
     */
    public function test_an_auto_confirmed_movement_is_listed_in_movimientos_with_no_actions(): void
    {
        $admin = $this->admin();
        $this->company();

        $this->import($admin);

        $movement = $this->deposit(35000);

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            // The default list, with no filter touched.
            ->assertSee('TRANSFERENCIA DE 76.543.210-3')
            ->assertSee('Conciliado automáticamente')
            ->assertSee('Pago #' . $movement->suscriptor_payment_id)
            // Confirming, assigning and ignoring are decisions that have
            // been made, and there is no way back once a payment exists.
            ->assertDontSee('startConfirm(' . $movement->id . ')', false)
            ->assertDontSee('startAssign(' . $movement->id . ')', false)
            ->assertDontSee('ignoreMovement(' . $movement->id . ')', false)
            ->assertDontSee('returnToQueue(' . $movement->id . ')', false);
    }

    /**
     * The default list is the queue plus the automatic work — not a full
     * history. A deposit a staff member confirmed has been seen already
     * and drops out, or the screen slowly becomes a ledger.
     */
    public function test_a_staff_confirmed_movement_is_not_listed_by_default(): void
    {
        $admin = $this->admin();
        $this->company('Servicios del Sur SpA', '77353398-9', 19900);

        $this->import($admin);

        $movement = $this->deposit(19900);

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startConfirm', $movement->id)
            ->call('confirmMatch', $movement->id)
            ->assertDontSee('TRANSF SERVICIOS DEL SUR');
    }

    /**
     * `auto` and `matched` both describe `status = matched` rows, so the
     * dropdown would count the same movement twice unless the entries are
     * kept disjoint.
     */
    public function test_the_automatic_and_manual_filters_do_not_overlap(): void
    {
        $admin = $this->admin();
        $this->company();

        $this->import($admin);

        // Only the automatic row, not the Transbank payout.
        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->set('statusFilter', BankReconciliation::FILTER_AUTO)
            ->assertSee('TRANSFERENCIA DE 76.543.210-3')
            ->assertDontSee('ABONO TRANSBANK');

        // And "Conciliados" keeps the payout without repeating the
        // automatic row.
        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->set('statusFilter', BankMovement::STATUS_MATCHED)
            ->assertSee('ABONO TRANSBANK')
            ->assertDontSee('TRANSFERENCIA DE 76.543.210-3');
    }

    /**
     * Payments recorded by the import are the one thing on this screen
     * nobody clicked for, so the person who uploaded the file is told
     * about them rather than left to spot rows they never confirmed.
     */
    public function test_the_import_message_reports_what_was_reconciled(): void
    {
        $admin = $this->admin();
        $this->company();

        $upload = UploadedFile::fake()->createWithContent(
            'banco-chile.csv',
            file_get_contents($this->fixture('banco-chile.csv'))
        );

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->set('bank', 'chile')
            ->set('cartola', $upload)
            ->call('import')
            ->assertSee('5 movimientos nuevos')
            ->assertSee('1 se concilió automáticamente');
    }
}
