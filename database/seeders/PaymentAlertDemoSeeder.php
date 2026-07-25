<?php

namespace Database\Seeders;

use App\Support\Rut;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Local-only test companies covering every Payment Alert / Webpay / suspend
 * UI state, so the whole feature can be exercised by hand without ever
 * touching the production `empresas` table.
 */
class PaymentAlertDemoSeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            ['body' => '20000001', 'name' => 'Demo On Time SpA',        'days_past_due' => -10, 'estado' => '1', 'tipoPlan' => 'Mensual'],
            ['body' => '20000002', 'name' => 'Demo Due Soon SpA',       'days_past_due' => -2,  'estado' => '1', 'tipoPlan' => 'Anual'],
            ['body' => '20000003', 'name' => 'Demo Overdue Day 1 SpA',  'days_past_due' => 1,   'estado' => '1', 'tipoPlan' => 'Lifetime'],
            // Real production spelling mistake, kept here so the display fix stays covered.
            ['body' => '20000004', 'name' => 'Demo Overdue In Grace SpA', 'days_past_due' => 3, 'estado' => '1', 'tipoPlan' => 'Menusal'],
            // No plan type on file: the UI must fall back to "Sin plan".
            ['body' => '20000005', 'name' => 'Demo Overdue Suspendable SpA', 'days_past_due' => 5, 'estado' => '1', 'tipoPlan' => null],
            ['body' => '20000006', 'name' => 'Demo Already Suspended SpA', 'days_past_due' => 30, 'estado' => '0', 'tipoPlan' => 'Anual 2x1'],
        ];

        foreach ($companies as $company) {
            $rut = $company['body'] . Rut::checkDigit($company['body']);

            $empresaId = DB::table('empresas')->insertGetId([
                'rut' => Rut::format($rut),
                'RazonSocial' => $company['name'],
                'nombre_fantasia' => $company['name'],
                'proximoPago' => Carbon::today()->subDays($company['days_past_due']),
                'tipoPlan' => $company['tipoPlan'],
                'estado' => $company['estado'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('datos_plan')->insert([
                'empresa_id' => $empresaId,
                'plan_id' => 11,
                'monto_plan' => 40000,
                'monto_hardware' => 0,
                'fecha_vencimiento' => Carbon::today()->subDays($company['days_past_due']),
                'periodo_plan' => 'Mensual',
                'periodo_days' => 30,
                'estado' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('users')->insert([
                'name' => $company['name'] . ' - Usuario',
                'email' => 'demo' . $empresaId . '@example.test',
                'password' => bcrypt('password'),
                'empresa_id' => $empresaId,
                'status' => $company['estado'] === '1' ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Staff account for /payment-alert. No empresa_id: an admin is
        // not tied to a company, they see all of them.
        DB::table('users')->insert([
            'name' => 'Demo Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'empresa_id' => null,
            'status' => 1,
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
