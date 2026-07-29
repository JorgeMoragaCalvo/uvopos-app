<?php

namespace Database\Seeders;

use App\Support\Rut;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

        $this->seedReconciliationDemo();
    }

    /**
     * A cartola to feed /conciliacion-bancaria with.
     *
     * It has to be generated rather than committed as a fixture, because
     * the demo companies' due dates are relative to today and the date
     * signal is part of what the match engine scores.
     */
    private function seedReconciliationDemo(): void
    {
        $suspendable = DB::table('empresas')->where('RazonSocial', 'Demo Overdue Suspendable SpA')->first();
        $overdue = DB::table('empresas')->where('RazonSocial', 'Demo Overdue Day 1 SpA')->first();

        if ($suspendable === null || $overdue === null) {
            return;
        }

        // A Webpay payment for the Transbank settlement tab to compare
        // against: paid two days before the deposit lands.
        $admin = DB::table('users')->where('email', 'admin@example.test')->first();

        DB::table('suscriptor_payments')->insert([
            'amount' => 120000,
            'user_id' => $admin->id,
            'empresa_id' => $overdue->id,
            'external_reference' => 'PA' . $overdue->id . '-' . Carbon::today()->subDays(4)->timestamp,
            'notes' => 'Pago online via Webpay Plus (demo)',
            'fecha_pago' => Carbon::today()->subDays(4)->setTime(11, 30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $date = function (int $daysAgo) {
            return Carbon::today()->subDays($daysAgo)->format('d/m/Y');
        };

        $rows = [
            'Cartola Histórica;;;;',
            'Cuenta Corriente;000-98765-43;;;',
            ';;;;',
            'Fecha;Descripción;N° Documento;Cargos;Abonos',
            // Unambiguous: RUT in the glosa, exact plan amount, near the due
            // date — so the import reconciles this one itself and it arrives
            // as "Conciliado automáticamente", with the company reactivated.
            $date(3) . ';TRANSFERENCIA DE ' . $suspendable->rut . ' DEMO OVERDUE SUSPENDABLE;100234;;40.000',
            // The Transbank payout for the settlement tab.
            $date(2) . ';ABONO TRANSBANK LIQUIDACION DIARIA;100235;;120.000',
            // No RUT: matches on name, amount and date, so it is suggested
            // but must still be confirmed by hand.
            $date(2) . ';TRANSF DEMO OVERDUE DAY 1;100236;;40.000',
            // Money out — never a customer payment.
            $date(1) . ';PAGO ARRIENDO OFICINA;100237;350.000;',
            // Matches nobody: this is what manual assignment is for.
            $date(1) . ';DEPOSITO EN EFECTIVO;;;12.000',
            'Saldo final;;;;',
        ];

        Storage::disk('local')->put('demo/cartola-demo.csv', implode("\n", $rows) . "\n");

        if ($this->command !== null) {
            $this->command->info(
                'Cartola de demostración: ' . storage_path('app/demo/cartola-demo.csv')
                . ' (súbala en /conciliacion-bancaria, banco "Banco de Chile")'
            );
        }
    }
}
