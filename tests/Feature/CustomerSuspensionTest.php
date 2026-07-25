<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * is_suspendable / days_until_suspendable depend on config(), so these
 * run as Feature tests (booted container) rather than pure Unit tests,
 * unlike PaymentStatusTest.
 */
class CustomerSuspensionTest extends TestCase
{
    private function customerDaysPastDue(int $days): Customer
    {
        $customer = new Customer();
        $customer->estado = '1';
        $customer->proximoPago = Carbon::today()->subDays($days)->toDateString();

        return $customer;
    }

    public function test_not_yet_suspendable_within_grace_period(): void
    {
        config(['payment_alert.overdue_grace_days' => 3]);

        $this->assertFalse($this->customerDaysPastDue(1)->is_suspendable);
        $this->assertFalse($this->customerDaysPastDue(3)->is_suspendable);
    }

    public function test_suspendable_past_grace_period(): void
    {
        config(['payment_alert.overdue_grace_days' => 3]);

        $this->assertTrue($this->customerDaysPastDue(4)->is_suspendable);
        $this->assertTrue($this->customerDaysPastDue(30)->is_suspendable);
    }

    public function test_suspended_company_is_never_suspendable_again(): void
    {
        config(['payment_alert.overdue_grace_days' => 3]);

        $customer = $this->customerDaysPastDue(10);
        $customer->estado = '0';

        $this->assertFalse($customer->is_suspendable);
    }

    public function test_days_until_suspendable_counts_down_from_grace_period(): void
    {
        config(['payment_alert.overdue_grace_days' => 3]);

        $this->assertSame(3, $this->customerDaysPastDue(1)->days_until_suspendable);
        $this->assertSame(2, $this->customerDaysPastDue(2)->days_until_suspendable);
        $this->assertSame(1, $this->customerDaysPastDue(3)->days_until_suspendable);
        $this->assertSame(0, $this->customerDaysPastDue(4)->days_until_suspendable);
    }

    public function test_days_until_suspendable_is_null_when_not_overdue(): void
    {
        $this->assertNull($this->customerDaysPastDue(-5)->days_until_suspendable);
        $this->assertNull($this->customerDaysPastDue(0)->days_until_suspendable);
    }
}
