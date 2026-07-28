<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SuscriptorPayment;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\WebpayTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Transbank\Webpay\WebpayPlus\Responses\TransactionCommitResponse;
use Transbank\Webpay\WebpayPlus\Transaction;

/**
 * The gateway boundary itself — previously the only part of the payment
 * path with no test, which is how two blockers survived: the return route
 * was not exempt from CSRF (419 on every real payment) and the pending
 * payload lived in a SameSite=Lax session cookie that a cross-site POST
 * never sends.
 *
 * Both are regression-tested here, along with the three return shapes
 * that are not payments and the replay that must not buy a second month.
 */
class WebpayReturnTest extends TestCase
{
    use RefreshDatabase;

    private const AMOUNT = 35000;

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

    private function company(string $due = '2026-03-12', string $estado = '1'): Customer
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
            'monto_plan' => self::AMOUNT,
            'monto_hardware' => 0,
            'fecha_vencimiento' => $due,
            'periodo_plan' => 'Mensual',
            'periodo_days' => 30,
            'estado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Customer::find($id);
    }

    private function pending(Customer $customer, string $returnTo = 'payment-alert'): WebpayTransaction
    {
        return WebpayTransaction::create([
            'buy_order' => 'PA' . $customer->id . '-1772000000-AB',
            'session_id' => 'pa-test-session',
            'empresa_id' => $customer->id,
            'user_id' => 99,
            'amount' => self::AMOUNT,
            'search' => (string) $customer->id,
            'return_to' => $returnTo,
        ]);
    }

    /**
     * Binds a fake gateway in place of the real Transaction, so commit()
     * returns a canned Transbank response instead of calling out.
     */
    private function fakeCommit(array $overrides = []): void
    {
        $json = array_merge([
            'buy_order' => 'PA1-1772000000-AB',
            'session_id' => 'pa-test-session',
            'amount' => self::AMOUNT,
            'status' => 'AUTHORIZED',
            'response_code' => 0,
        ], $overrides);

        $this->app->instance(Transaction::class, new class($json) extends Transaction {
            private $json;

            public function __construct($json)
            {
                $this->json = $json;
            }

            public function commit($token)
            {
                return new TransactionCommitResponse($this->json);
            }
        });
    }

    private function fakeCommitThrows(): void
    {
        $this->app->instance(Transaction::class, new class extends Transaction {
            public function __construct()
            {
            }

            public function commit($token)
            {
                throw new \RuntimeException('Transbank unreachable');
            }
        });
    }

    /**
     * The route sits in the `web` middleware group, so without the
     * VerifyCsrfToken exemption Transbank's cross-site POST is a 419 and
     * the controller never runs — after the card has been charged.
     *
     * Asserted against the middleware directly rather than over HTTP:
     * Laravel's VerifyCsrfToken short-circuits on runningUnitTests()
     * *before* it consults the except list, so a plain $this->post() here
     * would pass whether or not the exemption exists.
     */
    public function test_the_return_route_is_exempt_from_csrf(): void
    {
        $middleware = new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            public function isExempt($request): bool
            {
                return $this->inExceptArray($request);
            }
        };

        $this->assertTrue(
            $middleware->isExempt(Request::create(route('webpay.return'), 'POST')),
            'webpay.return must be in VerifyCsrfToken::$except or every real payment 419s.'
        );

        // And the exemption must stay narrow.
        $this->assertFalse($middleware->isExempt(Request::create(route('payment-alert'), 'POST')));
    }

    /**
     * The controller must find the pending payment with nothing but the
     * request body — no session survives the cross-site POST.
     */
    public function test_an_approved_payment_is_recorded_without_any_session(): void
    {
        $customer = $this->company('2026-03-12');
        $pending = $this->pending($customer);
        $this->fakeCommit(['buy_order' => $pending->buy_order]);

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123'])
            ->assertRedirect(route('payment-alert', [
                'search' => (string) $customer->id,
                'payment' => 'success',
            ]));

        $payment = SuscriptorPayment::sole();
        $this->assertEquals(self::AMOUNT, $payment->amount);
        $this->assertSame($pending->buy_order, $payment->external_reference);
        $this->assertSame(99, (int) $payment->user_id);

        $this->assertSame('2026-04-11', $customer->fresh()->proximoPago->toDateString());
        $this->assertSame(WebpayTransaction::STATUS_AUTHORIZED, $pending->fresh()->status);
        $this->assertSame($payment->id, (int) $pending->fresh()->suscriptor_payment_id);
    }

    public function test_paying_reactivates_a_suspended_company(): void
    {
        $customer = $this->company('2026-03-01', '0');
        $pending = $this->pending($customer);
        $this->fakeCommit(['buy_order' => $pending->buy_order]);

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123']);

        $this->assertSame('1', $customer->fresh()->estado);
    }

    /**
     * The one that silently cost a month before: a refreshed tab, a
     * retried delivery or two concurrent requests all replay this POST.
     */
    public function test_a_replayed_return_does_not_record_a_second_payment(): void
    {
        $customer = $this->company('2026-03-12');
        $pending = $this->pending($customer);
        $this->fakeCommit(['buy_order' => $pending->buy_order]);

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123']);
        $this->post(route('webpay.return'), ['token_ws' => 'tok-123']);
        $this->post(route('webpay.return'), ['token_ws' => 'tok-123']);

        $this->assertSame(1, SuscriptorPayment::count());
        $this->assertSame('2026-04-11', $customer->fresh()->proximoPago->toDateString());
    }

    /**
     * Transbank sends TBK_TOKEN alongside token_ws when the customer
     * backed out after the card was authorized. Committing it would book
     * a payment they explicitly abandoned.
     */
    public function test_an_abort_carrying_both_tokens_is_never_committed(): void
    {
        $customer = $this->company('2026-03-12');
        $pending = $this->pending($customer);
        $this->fakeCommit(['buy_order' => $pending->buy_order]);

        $this->post(route('webpay.return'), [
            'token_ws' => 'tok-123',
            'TBK_TOKEN' => 'abort-tok',
            'TBK_ORDEN_COMPRA' => $pending->buy_order,
            'TBK_ID_SESION' => 'pa-test-session',
        ])->assertRedirect(route('payment-alert', [
            'search' => (string) $customer->id,
            'payment' => 'aborted',
        ]));

        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame(WebpayTransaction::STATUS_ABORTED, $pending->fresh()->status);
        $this->assertSame('2026-03-12', $customer->fresh()->proximoPago->toDateString());
    }

    public function test_a_cancelled_checkout_records_nothing(): void
    {
        $customer = $this->company();
        $pending = $this->pending($customer);

        $this->post(route('webpay.return'), [
            'TBK_TOKEN' => 'abort-tok',
            'TBK_ORDEN_COMPRA' => $pending->buy_order,
            'TBK_ID_SESION' => 'pa-test-session',
        ]);

        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame(WebpayTransaction::STATUS_ABORTED, $pending->fresh()->status);
    }

    /**
     * Form timeout: no token of any kind, only the session and order.
     */
    public function test_a_timed_out_payment_form_records_nothing(): void
    {
        $customer = $this->company();
        $pending = $this->pending($customer);

        $this->post(route('webpay.return'), [
            'TBK_ID_SESION' => 'pa-test-session',
            'TBK_ORDEN_COMPRA' => $pending->buy_order,
        ]);

        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame(WebpayTransaction::STATUS_ABORTED, $pending->fresh()->status);
    }

    public function test_a_declined_card_records_nothing(): void
    {
        $customer = $this->company();
        $pending = $this->pending($customer);
        $this->fakeCommit([
            'buy_order' => $pending->buy_order,
            'status' => 'FAILED',
            'response_code' => -1,
        ]);

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123'])
            ->assertRedirect(route('payment-alert', [
                'search' => (string) $customer->id,
                'payment' => 'declined',
            ]));

        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame(WebpayTransaction::STATUS_DECLINED, $pending->fresh()->status);
    }

    /**
     * Should be impossible — the amount is ours. If it happens, guessing
     * which figure to book is worse than flagging it for a human.
     */
    public function test_an_amount_mismatch_is_not_recorded(): void
    {
        $customer = $this->company();
        $pending = $this->pending($customer);
        $this->fakeCommit([
            'buy_order' => $pending->buy_order,
            'amount' => 1000,
        ]);

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123'])
            ->assertRedirect(route('payment-alert', [
                'search' => (string) $customer->id,
                'payment' => 'error',
            ]));

        $this->assertSame(0, SuscriptorPayment::count());
        $this->assertSame(WebpayTransaction::STATUS_FAILED, $pending->fresh()->status);
    }

    /**
     * A commit we could not complete is not a decline: the charge may
     * exist, so the UI must not tell anyone to simply try again.
     */
    public function test_an_unreachable_gateway_reports_an_error_not_a_decline(): void
    {
        $customer = $this->company();
        $this->pending($customer);
        $this->fakeCommitThrows();

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123'])
            ->assertRedirect(route('payment-alert', ['search' => '', 'payment' => 'error']));

        $this->assertSame(0, SuscriptorPayment::count());
    }

    public function test_a_commit_for_an_unknown_buy_order_records_nothing(): void
    {
        $this->company();
        $this->fakeCommit(['buy_order' => 'PA999-nope']);

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123'])
            ->assertRedirect(route('payment-alert', ['search' => '', 'payment' => 'failed']));

        $this->assertSame(0, SuscriptorPayment::count());
    }

    public function test_a_return_with_no_recognisable_parameters_records_nothing(): void
    {
        $this->company();

        $this->post(route('webpay.return'), [])
            ->assertRedirect(route('payment-alert', ['search' => '', 'payment' => 'failed']));

        $this->assertSame(0, SuscriptorPayment::count());
    }

    /**
     * The customer portal and the staff page share this controller; the
     * landing page comes off the stored row, not the (absent) session.
     */
    public function test_a_customer_portal_payment_returns_to_mi_cuenta(): void
    {
        $customer = $this->company();
        $pending = $this->pending($customer, 'mi-cuenta');
        $this->fakeCommit(['buy_order' => $pending->buy_order]);

        $this->post(route('webpay.return'), ['token_ws' => 'tok-123'])
            ->assertRedirect(route('mi-cuenta', ['payment' => 'success']));
    }
}
