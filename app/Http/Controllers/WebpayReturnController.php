<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SuscriptorPayment;
use App\Models\User;
use App\Support\Webpay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Transbank\Webpay\WebpayPlus\Transaction;

/**
 * Transbank redirects the customer's browser back here (via POST) after
 * Webpay Plus checkout, so this must be a plain route — Livewire can't
 * receive a third-party redirect-form POST directly.
 */
class WebpayReturnController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $pending = session('webpay_pending');
        session()->forget('webpay_pending');

        $token = $request->input('token_ws');
        $search = $pending['search'] ?? '';

        if (!$token || !$pending) {
            return $this->back($search, 'failed');
        }

        try {
            $response = (new Transaction(Webpay::options()))->commit($token);
        } catch (\Throwable $e) {
            Log::error('Webpay commit failed', ['exception' => $e->getMessage(), 'token' => $token]);

            return $this->back($search, 'failed');
        }

        Log::info('Webpay commit response', (array) $response);

        if (!$response->isApproved()) {
            return $this->back($search, 'failed');
        }

        $this->applySuccessfulPayment($pending['empresa_id'], $response);

        return $this->back($search, 'success');
    }

    private function applySuccessfulPayment(int $empresaId, $response): void
    {
        $customer = Customer::find($empresaId);

        if ($customer === null) {
            return;
        }

        $plan = $customer->activePlan;
        $periodDays = $plan->periodo_days ?? 30;
        $oldDueDate = $customer->proximoPago;
        $newDueDate = Carbon::today()->max($oldDueDate ?? Carbon::today())->addDays($periodDays);

        SuscriptorPayment::create([
            'amount' => $response->getAmount(),
            'empresa_id' => $empresaId,
            'plan_id' => $plan->plan_id ?? null,
            'periodo_plan' => $plan->periodo_plan ?? null,
            'notes' => 'Pago online via Webpay Plus (orden ' . $response->getBuyOrder() . ')',
            'fecha_pago' => now(),
            'fecha_vencimiento_original' => $oldDueDate,
        ]);

        $customer->proximoPago = $newDueDate;
        $customer->estado = '1';
        $customer->save();

        if ($plan !== null) {
            $plan->fecha_vencimiento = $newDueDate;
            $plan->save();
        }

        User::where('empresa_id', $empresaId)->update(['status' => 1]);
    }

    private function back(string $search, string $result): RedirectResponse
    {
        return redirect()->route('payment-alert', ['search' => $search, 'payment' => $result]);
    }
}
