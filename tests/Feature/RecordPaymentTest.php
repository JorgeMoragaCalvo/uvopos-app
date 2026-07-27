<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DatosPlan;
use App\Models\SuscriptorPayment;
use App\Services\RecordPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The single write path for money, shared by the Webpay return and by
 * bank reconciliation. It was previously inlined in
 * WebpayReturnController and had no test at all, which mattered because
 * it is the only code in the module that mutates a due date.
 */
class RecordPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function company(?string $due, int $periodoDays = 30, string $estado = '1'): Customer
    {
        $id = DB::table('empresas')->insertGetId([
            'rut' => '76543210-3',
            'RazonSocial' => 'Comercial Andes SpA',
            'nombre_fantasia' => 'Comercial Andes SpA',
            'proximoPago' => $due,
            'tipoPlan' => 'Mensual',
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('datos_plan')->insert([
            'empresa_id' => $id,
            'plan_id' => 7,
            'monto_plan' => 35000,
            'monto_hardware' => 0,
            'fecha_vencimiento' => $due,
            'periodo_plan' => 'Mensual',
            'periodo_days' => $periodoDays,
            'estado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Customer::find($id);
    }

    private function apply(Customer $customer, string $paidAt = '2026-03-10'): SuscriptorPayment
    {
        return app(RecordPayment::class)->apply(
            $customer,
            35000,
            Carbon::parse($paidAt),
            RecordPayment::SOURCE_WEBPAY,
            'PA' . $customer->id . '-1772000000',
            99,
            'Pago de prueba'
        );
    }

    public function test_paying_while_overdue_starts_the_period_at_the_payment_date(): void
    {
        $customer = $this->company('2026-03-01');

        $this->apply($customer);

        $this->assertSame('2026-04-09', $customer->fresh()->proximoPago->toDateString());
    }

    /**
     * Paying before the due date must not forfeit the days still paid
     * for — the new period is stacked on the old one.
     */
    public function test_paying_early_extends_from_the_existing_due_date(): void
    {
        $customer = $this->company('2026-03-20');

        $this->apply($customer);

        $this->assertSame('2026-04-19', $customer->fresh()->proximoPago->toDateString());
    }

    public function test_a_company_without_a_due_date_gets_one(): void
    {
        $customer = $this->company(null);

        $this->apply($customer);

        $this->assertSame('2026-04-09', $customer->fresh()->proximoPago->toDateString());
    }

    /**
     * `datos_plan.periodo_days` holds 0 and 99999999 in production. Left
     * unguarded the first would leave the customer overdue immediately
     * after paying, and the second would push the due date past the year
     * 275000.
     */
    public function test_a_nonsense_period_falls_back_to_thirty_days(): void
    {
        foreach ([0, -5, 99999999] as $periodoDays) {
            $customer = $this->company('2026-03-01', $periodoDays);

            $this->apply($customer);

            $this->assertSame(
                '2026-04-09',
                $customer->fresh()->proximoPago->toDateString(),
                'periodo_days = ' . $periodoDays
            );
        }
    }

    public function test_a_valid_annual_period_is_honoured(): void
    {
        $customer = $this->company('2026-03-01', 365);

        $this->apply($customer);

        $this->assertSame('2027-03-10', $customer->fresh()->proximoPago->toDateString());
    }

    public function test_the_plan_expiry_moves_with_the_due_date(): void
    {
        $customer = $this->company('2026-03-01');

        $this->apply($customer);

        $plan = DatosPlan::where('empresa_id', $customer->id)->firstOrFail();
        $this->assertSame('2026-04-09', $plan->fecha_vencimiento->toDateString());
    }

    public function test_paying_reactivates_a_suspended_company(): void
    {
        $customer = $this->company('2026-03-01', 30, '0');

        $this->apply($customer);

        $this->assertTrue($customer->fresh()->is_active);
    }

    /**
     * `suscriptor_payments.user_id` is NOT NULL in production and means
     * "who took the payment", so it has to be written, not left to the
     * database default.
     */
    public function test_the_audit_trail_records_who_took_the_payment(): void
    {
        $customer = $this->company('2026-03-01');

        $payment = $this->apply($customer);

        $this->assertSame(99, (int) $payment->user_id);
        $this->assertSame(99, (int) $payment->responsable);
        $this->assertSame('PA' . $customer->id . '-1772000000', $payment->external_reference);
        $this->assertSame('2026-03-01', $payment->fecha_vencimiento_original->toDateString());
    }

    /**
     * 28 active plans in production charge more than the old
     * decimal(8,2) column could hold.
     */
    public function test_a_plan_larger_than_the_old_column_limit_is_recorded(): void
    {
        $customer = $this->company('2026-03-01');

        $payment = app(RecordPayment::class)->apply(
            $customer,
            6910380,
            Carbon::parse('2026-03-10'),
            RecordPayment::SOURCE_WEBPAY,
            'PA1-1772000000',
            99,
            'Plan grande'
        );

        $this->assertSame(6910380.0, (float) $payment->fresh()->amount);
    }
}
