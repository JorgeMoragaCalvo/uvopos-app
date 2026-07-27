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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff reconciliation screen at /conciliacion-bancaria: importing a
 * cartola, and turning a suggested match into a recorded payment.
 *
 * The invariant these tests protect is that importing never moves money
 * and confirming moves it exactly once.
 */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('cartolas');

        // The cartola fixtures cover the first week of March 2026; freeze
        // "today" just after them so the scenario stays coherent as real
        // time moves on.
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

    private function companyUser(): User
    {
        return User::create([
            'name' => 'Cliente',
            'email' => 'cliente@example.test',
            'password' => bcrypt('password'),
            'empresa_id' => $this->company('Empresa Cliente SpA', '773533989')->id,
            'status' => 1,
        ]);
    }

    /**
     * A company due on 2026-03-01, priced at $35.000 — the amount and the
     * window the banco-chile.csv fixture deposits into.
     */
    private function company(
        string $name,
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

    // ---------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/conciliacion-bancaria')->assertRedirect('/login');
    }

    public function test_non_admin_users_are_sent_to_their_own_page(): void
    {
        $this->actingAs($this->companyUser())
            ->get('/conciliacion-bancaria')
            ->assertRedirect('/mi-cuenta');
    }

    public function test_an_admin_sees_the_pending_movements_and_the_tabs(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $response = $this->actingAs($admin)->get('/conciliacion-bancaria');

        $response->assertOk();
        $response->assertSee('Importar cartola');
        $response->assertSee('Liquidaciones Transbank');
        $response->assertSee('Comercial Andes SpA');
        $response->assertSee('TRANSFERENCIA DE 76.543.210-3 COMERCIAL ANDES SPA');
    }

    public function test_the_settlement_tab_renders(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $this->actingAs($admin)
            ->get('/conciliacion-bancaria?tab=liquidaciones')
            ->assertOk()
            ->assertSee('Depositado');
    }

    public function test_the_staff_page_links_to_reconciliation_with_a_pending_count(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $this->actingAs($admin)
            ->get('/payment-alert')
            ->assertOk()
            ->assertSee('Conciliación bancaria');
    }

    /**
     * Livewire 2 re-runs only the persistent middleware on its own
     * requests, so the route's admin gate does not protect these actions.
     * They record payments, so each one has to check for itself.
     */
    public function test_a_non_admin_cannot_confirm_a_match_through_livewire(): void
    {
        $customer = $this->company('Comercial Andes SpA');
        $this->import();

        $movement = BankMovement::where('empresa_id', $customer->id)->firstOrFail();

        Livewire::actingAs($this->companyUser())
            ->test(BankReconciliation::class)
            ->call('confirmMatch', $movement->id)
            ->assertForbidden();

        $this->assertSame(0, SuscriptorPayment::count());
    }

    public function test_a_non_admin_cannot_ignore_a_movement_through_livewire(): void
    {
        $this->company('Comercial Andes SpA');
        $this->import();

        $movement = BankMovement::first();

        Livewire::actingAs($this->companyUser())
            ->test(BankReconciliation::class)
            ->call('ignoreMovement', $movement->id)
            ->assertForbidden();

        $this->assertNotSame(BankMovement::STATUS_IGNORED, $movement->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Import
    // ---------------------------------------------------------------

    public function test_importing_stores_the_movements_without_recording_any_payment(): void
    {
        $customer = $this->company('Comercial Andes SpA');

        $statement = $this->import($this->admin());

        $this->assertSame(5, $statement->movement_count);
        $this->assertSame(5, BankMovement::count());

        // Importing is a suggestion, never a payment.
        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame('2026-03-01', $customer->fresh()->proximoPago->toDateString());
    }

    public function test_the_deposit_carrying_a_rut_is_suggested_for_that_company(): void
    {
        $customer = $this->company('Comercial Andes SpA');

        $this->import($this->admin());

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        $this->assertSame(BankMovement::STATUS_SUGGESTED, $movement->status);
        $this->assertSame($customer->id, $movement->empresa_id);
        $this->assertStringContainsString('RUT en la glosa', $movement->match_reason);
    }

    public function test_a_transbank_deposit_is_kept_out_of_the_customer_queue(): void
    {
        $this->company('Comercial Andes SpA');

        $this->import($this->admin());

        $deposit = BankMovement::where('amount', 120000)->firstOrFail();

        $this->assertSame(BankMovement::STATUS_MATCHED, $deposit->status);
        $this->assertNull($deposit->empresa_id);
        $this->assertSame('Liquidación Transbank', $deposit->match_reason);
    }

    public function test_the_same_file_cannot_be_imported_twice(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $this->expectException(\App\Support\Cartola\CartolaException::class);
        $this->import($admin);
    }

    /**
     * Cartolas are exported by date range and those ranges overlap, so a
     * second file legitimately contains movements already imported from
     * the first. Those must not reappear in the queue.
     */
    public function test_movements_already_imported_are_skipped_in_a_later_cartola(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin, 'banco-chile.csv', 'chile');

        $before = BankMovement::count();

        // A second export whose date range overlaps the first: its 05-03
        // and 06-03 lines are movements we already have.
        $second = $this->import($admin, 'banco-chile-overlap.csv', 'chile');

        $this->assertSame(1, $second->movement_count);
        $this->assertSame($before + 1, BankMovement::count());
    }

    // ---------------------------------------------------------------
    // Confirming
    // ---------------------------------------------------------------

    public function test_confirming_records_the_payment_and_extends_the_due_date(): void
    {
        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startConfirm', $movement->id)
            ->call('confirmMatch', $movement->id);

        $this->assertSame(1, SuscriptorPayment::count());

        $payment = SuscriptorPayment::first();
        $this->assertSame($customer->id, (int) $payment->empresa_id);
        $this->assertSame(35000.0, (float) $payment->amount);
        $this->assertSame($admin->id, (int) $payment->user_id);
        $this->assertSame($admin->id, (int) $payment->responsable);

        // The deposit landed on 2026-03-02, one day after the due date,
        // so the new period runs from the deposit: + 30 days of plan.
        $this->assertSame('2026-04-01', $customer->fresh()->proximoPago->toDateString());

        $movement->refresh();
        $this->assertSame(BankMovement::STATUS_MATCHED, $movement->status);
        $this->assertSame($payment->id, (int) $movement->suscriptor_payment_id);
        $this->assertSame($admin->id, (int) $movement->reconciled_by);
    }

    public function test_confirming_reactivates_a_suspended_company(): void
    {
        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA', '76543210-3', 35000, '2026-03-01', '0');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startConfirm', $movement->id)
            ->call('confirmMatch', $movement->id);

        $this->assertTrue($customer->fresh()->is_active);
    }

    /**
     * Confirming records a payment, so it is a two-step action like
     * suspension: a bare confirmMatch — a stale tab, a replayed Livewire
     * request — must not move money on its own.
     */
    public function test_confirming_without_opening_the_confirmation_step_does_nothing(): void
    {
        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('confirmMatch', $movement->id);

        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame(BankMovement::STATUS_SUGGESTED, $movement->fresh()->status);
        $this->assertSame('2026-03-01', $customer->fresh()->proximoPago->toDateString());
    }

    /**
     * A double click, a stale tab or a browser back button must not buy
     * the customer a second month.
     */
    public function test_confirming_the_same_movement_twice_is_a_no_op(): void
    {
        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        $component = Livewire::actingAs($admin)->test(BankReconciliation::class);
        $component->call('startConfirm', $movement->id);
        $component->call('confirmMatch', $movement->id);
        $component->call('confirmMatch', $movement->id);

        $this->assertSame(1, SuscriptorPayment::count());
        $this->assertSame('2026-04-01', $customer->fresh()->proximoPago->toDateString());
    }

    /**
     * The same deposit open in two tabs. Both hold a confirmation step —
     * the second click is not a repeat of the first, it is a separate
     * component that never saw the movement resolved. The conditional
     * claim in confirmMatch() is what makes it a no-op.
     */
    public function test_two_tabs_confirming_the_same_deposit_record_one_payment(): void
    {
        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        $first = Livewire::actingAs($admin)->test(BankReconciliation::class)
            ->call('startConfirm', $movement->id);
        $second = Livewire::actingAs($admin)->test(BankReconciliation::class)
            ->call('startConfirm', $movement->id);

        $first->call('confirmMatch', $movement->id);
        $second->call('confirmMatch', $movement->id);

        $this->assertSame(1, SuscriptorPayment::count());
        $this->assertSame('2026-04-01', $customer->fresh()->proximoPago->toDateString());
    }

    /**
     * The engine only scores on the amount; nothing stops staff confirming
     * a deposit that is short of the plan charge, and doing so buys a full
     * period. So the confirmation step has to say how short it is.
     */
    public function test_a_short_deposit_is_flagged_before_confirming(): void
    {
        $admin = $this->admin();

        // Priced at $35.000, but "TRANSF SERVICIOS DEL SUR" deposits $19.900.
        $customer = $this->company('Servicios Del Sur Ltda', '96789012-K', 35000, '2026-03-01');

        $this->import($admin);

        $movement = BankMovement::where('amount', 19900)->firstOrFail();

        $component = Livewire::actingAs($admin)->test(BankReconciliation::class)
            ->call('startConfirm', $movement->id, $customer->id);

        $component->assertSee('faltan $15.100');

        // And it is a warning, not a block: staff can still decide to take it.
        $component->call('confirmMatch', $movement->id, $customer->id);

        $this->assertSame(1, SuscriptorPayment::count());
        $this->assertSame(19900.0, (float) SuscriptorPayment::first()->amount);
    }

    public function test_a_deposit_matching_the_charge_is_not_flagged(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startConfirm', $movement->id)
            ->assertDontSee('faltan');
    }

    public function test_ignoring_a_movement_writes_nothing_to_the_customer(): void
    {
        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('ignoreMovement', $movement->id);

        $this->assertSame(BankMovement::STATUS_IGNORED, $movement->fresh()->status);
        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame('2026-03-01', $customer->fresh()->proximoPago->toDateString());
    }

    /**
     * "DEPOSITO EN EFECTIVO" carries no RUT, no name and an amount that
     * matches nobody — the case the engine is supposed to leave alone and
     * hand to a human.
     */
    public function test_an_unmatched_deposit_can_be_assigned_by_rut(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $other = $this->company('Servicios Del Sur Ltda', '96789012-K', 7500, '2026-09-01');

        $this->import($admin);

        $movement = BankMovement::where('amount', 7500)->firstOrFail();
        $this->assertSame(BankMovement::STATUS_UNMATCHED, $movement->status);
        $this->assertNull($movement->empresa_id);

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startAssign', $movement->id)
            ->set('assignSearch', '96789012-K')
            ->call('assignMovement')
            ->call('confirmMatch', $movement->id);

        $this->assertSame(1, SuscriptorPayment::count());
        $this->assertSame($other->id, (int) $movement->fresh()->empresa_id);
    }

    /**
     * Resolving the RUT is not the decision — it opens the same
     * confirmation step a suggested match goes through.
     */
    public function test_assigning_by_rut_does_not_record_a_payment_on_its_own(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->company('Servicios Del Sur Ltda', '96789012-K', 7500, '2026-09-01');
        $this->import($admin);

        $movement = BankMovement::where('amount', 7500)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startAssign', $movement->id)
            ->set('assignSearch', '96789012-K')
            ->call('assignMovement');

        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame(BankMovement::STATUS_UNMATCHED, $movement->fresh()->status);
    }

    public function test_assigning_to_an_invalid_rut_is_rejected(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 7500)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startAssign', $movement->id)
            ->set('assignSearch', '76543210-9')
            ->call('assignMovement')
            ->assertHasErrors('assignSearch');

        $this->assertSame(0, SuscriptorPayment::count());
    }

    public function test_a_debit_is_never_turned_into_a_payment(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $debit = BankMovement::where('direction', BankMovement::DIRECTION_DEBIT)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('startConfirm', $debit->id, 1)
            ->call('confirmMatch', $debit->id, 1);

        $this->assertSame(0, SuscriptorPayment::count());
    }

    /**
     * The Transbank tag resolves a movement without anyone confirming it,
     * so it has to match whole words: "SETBK INGENIERIA" is a customer,
     * not a payout, and must stay in the queue.
     */
    public function test_a_glosa_merely_containing_tbk_is_not_a_transbank_payout(): void
    {
        $admin = $this->admin();
        $this->company('Setbk Ingenieria SpA');

        $this->import($admin, 'banco-chile-tbk.csv', 'chile');

        $transfer = BankMovement::where('amount', 35000)->firstOrFail();
        $this->assertNotSame('Liquidación Transbank', $transfer->match_reason);
        $this->assertFalse($transfer->is_resolved);

        // …while the real payout, where TBK is its own word, still is one.
        $payout = BankMovement::where('amount', 98000)->firstOrFail();
        $this->assertSame('Liquidación Transbank', $payout->match_reason);
    }

    // ---------------------------------------------------------------
    // Undo
    // ---------------------------------------------------------------

    public function test_an_ignored_movement_can_be_returned_to_the_queue(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        $component = Livewire::actingAs($admin)->test(BankReconciliation::class);
        $component->call('ignoreMovement', $movement->id);
        $this->assertSame(BankMovement::STATUS_IGNORED, $movement->fresh()->status);

        // The way back is offered on the row itself, under the "Ignorados" filter.
        $component->set('statusFilter', BankMovement::STATUS_IGNORED)
            ->assertSee('Devolver a la lista');

        $component->call('returnToQueue', $movement->id);

        $movement->refresh();
        $this->assertSame(BankMovement::STATUS_SUGGESTED, $movement->status);
        $this->assertNull($movement->reconciled_at);
        $this->assertSame(0, SuscriptorPayment::count());
    }

    /**
     * The importer tags Transbank payouts silently. A real transfer caught
     * by that rule would otherwise be lost, so it has to be reversible.
     */
    public function test_a_transbank_tagged_movement_can_be_returned_to_the_queue(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $payout = BankMovement::where('match_reason', 'Liquidación Transbank')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(BankReconciliation::class)
            ->call('returnToQueue', $payout->id);

        $payout->refresh();
        $this->assertSame(BankMovement::STATUS_UNMATCHED, $payout->status);
        $this->assertNull($payout->match_reason);
    }

    /**
     * Undo stops where money starts: unwinding a recorded payment is a
     * financial decision, not a queue correction.
     */
    public function test_a_confirmed_payment_cannot_be_returned_to_the_queue(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        $component = Livewire::actingAs($admin)->test(BankReconciliation::class);
        $component->call('startConfirm', $movement->id);
        $component->call('confirmMatch', $movement->id);
        $component->call('returnToQueue', $movement->id);

        $this->assertSame(BankMovement::STATUS_MATCHED, $movement->fresh()->status);
        $this->assertSame(1, SuscriptorPayment::count());

        // The row shows the payment it created instead of a way back.
        // (The Transbank payout in the same fixture is also `matched` but
        // wrote nothing, so it does still offer one — that is the point.)
        $component->set('statusFilter', BankMovement::STATUS_MATCHED)
            ->assertSee('Pago #' . SuscriptorPayment::first()->id);
    }

    public function test_a_non_admin_cannot_return_a_movement_to_the_queue(): void
    {
        $admin = $this->admin();
        $this->company('Comercial Andes SpA');
        $this->import($admin);

        $movement = BankMovement::where('amount', 35000)->firstOrFail();

        Livewire::actingAs($admin)->test(BankReconciliation::class)
            ->call('ignoreMovement', $movement->id);

        Livewire::actingAs($this->companyUser())
            ->test(BankReconciliation::class)
            ->call('returnToQueue', $movement->id)
            ->assertForbidden();

        $this->assertSame(BankMovement::STATUS_IGNORED, $movement->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Settlement comparison
    // ---------------------------------------------------------------

    public function test_a_transbank_deposit_is_compared_against_that_days_webpay_payments(): void
    {
        config([
            'bank_reconciliation.settlement_lag_days' => 2,
            'bank_reconciliation.settlement_tolerance_clp' => 100,
        ]);

        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        // The fixture deposits $120.000 from Transbank on 03-03-2026, so
        // the charges it settles were taken on 01-03-2026.
        SuscriptorPayment::create([
            'amount' => 120000,
            'user_id' => $admin->id,
            'empresa_id' => $customer->id,
            'external_reference' => 'PA' . $customer->id . '-1772000000',
            'fecha_pago' => Carbon::parse('2026-03-01 10:00:00'),
        ]);

        $rows = app(\App\Services\SettlementReport::class)->forDeposits();

        $this->assertCount(1, $rows);
        $this->assertSame(120000, $rows[0]['charged']);
        $this->assertSame(120000, $rows[0]['deposited']);
        $this->assertTrue($rows[0]['matches']);
    }

    /**
     * A charge the app recorded that Transbank never deposited is the
     * failure this half of reconciliation exists to catch.
     */
    public function test_a_missing_settlement_shows_as_a_difference(): void
    {
        config([
            'bank_reconciliation.settlement_lag_days' => 2,
            'bank_reconciliation.settlement_tolerance_clp' => 100,
        ]);

        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        SuscriptorPayment::create([
            'amount' => 155000,
            'user_id' => $admin->id,
            'empresa_id' => $customer->id,
            'external_reference' => 'PA' . $customer->id . '-1772000000',
            'fecha_pago' => Carbon::parse('2026-03-01 10:00:00'),
        ]);

        $rows = app(\App\Services\SettlementReport::class)->forDeposits();

        $this->assertFalse($rows[0]['matches']);
        $this->assertSame(-35000, $rows[0]['difference']);
    }

    /**
     * A reconciled bank transfer was never part of a Transbank deposit,
     * so it must not inflate the expected settlement.
     */
    public function test_a_reconciled_transfer_is_not_counted_as_a_webpay_charge(): void
    {
        config(['bank_reconciliation.settlement_lag_days' => 2]);

        $admin = $this->admin();
        $customer = $this->company('Comercial Andes SpA');
        $this->import($admin);

        SuscriptorPayment::create([
            'amount' => 90000,
            'user_id' => $admin->id,
            'empresa_id' => $customer->id,
            'external_reference' => '998877',   // a bank reference, not a buy order
            'fecha_pago' => Carbon::parse('2026-03-01 10:00:00'),
        ]);

        $rows = app(\App\Services\SettlementReport::class)->forDeposits();

        $this->assertSame(0, $rows[0]['charged']);
    }
}
