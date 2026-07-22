<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The customer-facing payment status page at /mi-cuenta: a logged-in
 * user sees their own company's status and, when due, a Webpay button.
 */
class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creates a company (with an active plan) plus the user that belongs
     * to it, due `$daysPastDue` days ago.
     */
    private function userForCompany(string $name, int $daysPastDue, string $estado = '1'): User
    {
        $empresaId = DB::table('empresas')->insertGetId([
            'rut' => '20000001-8',
            'RazonSocial' => $name,
            'nombre_fantasia' => $name,
            'proximoPago' => Carbon::today()->subDays($daysPastDue),
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('datos_plan')->insert([
            'empresa_id' => $empresaId,
            'plan_id' => 11,
            'monto_plan' => 40000,
            'monto_hardware' => 0,
            'fecha_vencimiento' => Carbon::today()->subDays($daysPastDue),
            'periodo_plan' => 'Mensual',
            'periodo_days' => 30,
            'estado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::create([
            'name' => $name . ' - Usuario',
            'email' => 'user' . $empresaId . '@example.test',
            'password' => bcrypt('password'),
            'empresa_id' => $empresaId,
            'status' => $estado === '1' ? 1 : 0,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/mi-cuenta')->assertRedirect('/login');
    }

    public function test_on_time_customer_sees_status_without_payment_button(): void
    {
        $user = $this->userForCompany('Empresa Al Dia SpA', -10);

        $response = $this->actingAs($user)->get('/mi-cuenta');

        $response->assertOk();
        $response->assertSee('Pago al día', false);
        $response->assertSee('Empresa Al Dia SpA');
        $response->assertDontSee('Pagar con Webpay');
    }

    public function test_due_soon_customer_can_pay(): void
    {
        $user = $this->userForCompany('Empresa Por Vencer SpA', -2);

        $response = $this->actingAs($user)->get('/mi-cuenta');

        $response->assertSee('Pago próximo a vencer', false);
        $response->assertSee('Pagar con Webpay');
    }

    public function test_overdue_customer_sees_suspension_countdown_and_can_pay(): void
    {
        config(['payment_alert.overdue_grace_days' => 3]);

        $user = $this->userForCompany('Empresa Atrasada SpA', 1);

        $response = $this->actingAs($user)->get('/mi-cuenta');

        $response->assertSee('Pago atrasado', false);
        $response->assertSee('para regularizar el pago antes de la suspensión', false);
        $response->assertSee('Pagar con Webpay');
    }

    public function test_suspended_customer_still_sees_the_payment_button(): void
    {
        $user = $this->userForCompany('Empresa Suspendida SpA', 30, '0');

        $response = $this->actingAs($user)->get('/mi-cuenta');

        $response->assertSee('Servicio suspendido');
        $response->assertSee('Pagar con Webpay');
    }

    public function test_user_only_sees_their_own_company(): void
    {
        $user = $this->userForCompany('Empresa Propia SpA', -10);
        $this->userForCompany('Empresa Ajena SpA', 5);

        $response = $this->actingAs($user)->get('/mi-cuenta');

        $response->assertSee('Empresa Propia SpA');
        $response->assertDontSee('Empresa Ajena SpA');
    }

    public function test_user_without_a_company_gets_a_neutral_message(): void
    {
        $user = User::create([
            'name' => 'Sin empresa',
            'email' => 'sin-empresa@example.test',
            'password' => bcrypt('password'),
        ]);

        $response = $this->actingAs($user)->get('/mi-cuenta');

        $response->assertOk();
        $response->assertSee('No hay una empresa asociada a su cuenta.');
    }

    public function test_valid_credentials_log_the_user_in(): void
    {
        $user = $this->userForCompany('Empresa Login SpA', -10);

        \Livewire\Livewire::test(\App\Http\Livewire\Auth\Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('authenticate')
            ->assertRedirect('/mi-cuenta');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = $this->userForCompany('Empresa Login SpA', -10);

        \Livewire\Livewire::test(\App\Http\Livewire\Auth\Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('authenticate')
            ->assertHasErrors('email');

        $this->assertGuest();
    }
}
