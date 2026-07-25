<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Http\Livewire\PaymentAlert;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff page at /payment-alert: admin-only access plus the
 * all-customers list (counts, status filter, click-to-open).
 */
class AdminPaymentAlertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creates a company due `$daysPastDue` days ago. A null $daysPastDue
     * means no payment date on file.
     */
    private function company(string $name, ?int $daysPastDue, string $estado = '1', ?string $tipoPlan = 'Mensual'): Customer
    {
        $id = DB::table('empresas')->insertGetId([
            'rut' => '20000001-8',
            'RazonSocial' => $name,
            'nombre_fantasia' => $name,
            'proximoPago' => $daysPastDue === null ? null : Carbon::today()->subDays($daysPastDue),
            'tipoPlan' => $tipoPlan,
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Customer::find($id);
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
            'empresa_id' => $this->company('Empresa Cliente SpA', -10)->id,
            'status' => 1,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/payment-alert')->assertRedirect('/login');
    }

    public function test_non_admin_users_are_sent_to_their_own_page(): void
    {
        $this->actingAs($this->companyUser())
            ->get('/payment-alert')
            ->assertRedirect('/mi-cuenta');
    }

    public function test_admin_sees_every_customer_with_its_status(): void
    {
        config(['payment_alert.due_soon_days' => 3]);

        $this->company('Empresa Al Dia SpA', -10);
        $this->company('Empresa Por Vencer SpA', -2);
        $this->company('Empresa Atrasada SpA', 5);

        $response = $this->actingAs($this->admin())->get('/payment-alert');

        $response->assertOk();
        $response->assertSee('Empresa Al Dia SpA');
        $response->assertSee('Empresa Por Vencer SpA');
        $response->assertSee('Empresa Atrasada SpA');
        $response->assertSee('Pago al día', false);
        $response->assertSee('Pago próximo a vencer', false);
        $response->assertSee('Pago atrasado', false);
    }

    /**
     * The plan type is display-only: an annual company is still listed,
     * counted and filtered exactly like a monthly one.
     */
    public function test_annual_plan_customers_are_unchanged_for_staff(): void
    {
        config(['payment_alert.due_soon_days' => 3]);

        $this->company('Empresa Anual Atrasada SpA', 5, '1', 'Anual');

        $response = $this->actingAs($this->admin())->get('/payment-alert');

        $response->assertSee('Empresa Anual Atrasada SpA');
        $response->assertSee('Pago atrasado', false);

        $this->assertSame(1, Customer::withPaymentStatus(PaymentStatus::OVERDUE)->count());
    }

    /**
     * `empresas.tipoPlan` is free text in production, so the list shows
     * whatever is stored, tidying only the known typo and the blanks.
     */
    public function test_admin_list_shows_the_plan_type(): void
    {
        $this->company('Empresa Anual SpA', -10, '1', 'Anual');
        $this->company('Empresa Con Typo SpA', -10, '1', 'Menusal');
        $this->company('Empresa Rara SpA', -10, '1', 'Anual 2x1');
        $this->company('Empresa Sin Plan SpA', -10, '1', null);

        $response = $this->actingAs($this->admin())->get('/payment-alert');

        $response->assertOk();
        $response->assertSee('Anual');
        $response->assertSee('Mensual');
        $response->assertDontSee('Menusal');
        $response->assertSee('Anual 2x1');
        $response->assertSee('Sin plan');
    }

    public function test_selected_customer_panel_shows_the_plan_type(): void
    {
        $customer = $this->company('Empresa Detalle SpA', -10, '1', 'Lifetime');

        Livewire::actingAs($this->admin())
            ->test(PaymentAlert::class)
            ->call('selectCustomer', $customer->id)
            ->assertSee('Plan: Lifetime');
    }

    public function test_admin_login_lands_on_the_staff_page(): void
    {
        $admin = $this->admin();

        Livewire::test(\App\Http\Livewire\Auth\Login::class)
            ->set('email', $admin->email)
            ->set('password', 'password')
            ->call('authenticate')
            ->assertRedirect('/payment-alert');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        config(['payment_alert.due_soon_days' => 3]);

        $this->company('Empresa Al Dia SpA', -10);
        $this->company('Empresa Atrasada SpA', 5);

        Livewire::actingAs($this->admin())
            ->test(PaymentAlert::class)
            ->call('filterByStatus', PaymentStatus::OVERDUE)
            ->assertSee('Empresa Atrasada SpA')
            ->assertDontSee('Empresa Al Dia SpA');
    }

    public function test_counts_respect_the_status_boundaries(): void
    {
        config(['payment_alert.due_soon_days' => 3]);

        $this->company('Vence hoy SpA', 0);              // due_soon
        $this->company('Ultimo dia por vencer SpA', -3); // due_soon (today + 3)
        $this->company('Al dia SpA', -4);                // on_time  (today + 4)
        $this->company('Atrasada SpA', 1);               // overdue
        $this->company('Sin fecha SpA', null);           // on_time

        $this->assertSame(2, Customer::withPaymentStatus(PaymentStatus::ON_TIME)->count());
        $this->assertSame(2, Customer::withPaymentStatus(PaymentStatus::DUE_SOON)->count());
        $this->assertSame(1, Customer::withPaymentStatus(PaymentStatus::OVERDUE)->count());
    }

    public function test_a_customer_without_a_payment_date_is_on_time_in_both_the_scope_and_the_accessor(): void
    {
        $customer = $this->company('Sin fecha SpA', null);

        $this->assertSame(PaymentStatus::ON_TIME, $customer->payment_status);
        $this->assertTrue(
            Customer::withPaymentStatus(PaymentStatus::ON_TIME)->whereKey($customer->id)->exists()
        );
    }

    public function test_the_list_paginates(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->company('Empresa ' . $i . ' SpA', $i);
        }

        Livewire::actingAs($this->admin())
            ->test(PaymentAlert::class)
            ->assertSee('Empresa 20 SpA')   // most overdue, first page
            ->assertDontSee('Empresa 1 SpA')
            ->call('gotoPage', 2)
            ->assertSee('Empresa 1 SpA');
    }

    public function test_clicking_a_row_opens_it_in_the_detail_panel(): void
    {
        $customer = $this->company('Empresa Atrasada SpA', 5);

        Livewire::actingAs($this->admin())
            ->test(PaymentAlert::class)
            ->call('selectCustomer', $customer->id)
            ->assertSet('search', (string) $customer->id)
            ->assertSet('customer.id', $customer->id);
    }
}
