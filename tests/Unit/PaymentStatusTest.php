<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use App\Support\Rut;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    /**
     * Threshold passed explicitly so the test does not need the
     * Laravel container (config() helper).
     */
    private function status(int $days): string
    {
        return PaymentStatus::fromDaysPastDue($days, 3);
    }

    public function test_not_yet_due_is_on_time(): void
    {
        $this->assertSame(PaymentStatus::ON_TIME, $this->status(-10));
        $this->assertSame(PaymentStatus::ON_TIME, $this->status(-4));
    }

    public function test_due_soon_boundaries(): void
    {
        $this->assertSame(PaymentStatus::DUE_SOON, $this->status(-3));
        $this->assertSame(PaymentStatus::DUE_SOON, $this->status(-1));
        $this->assertSame(PaymentStatus::DUE_SOON, $this->status(0));
    }

    public function test_overdue_has_no_upper_cutoff(): void
    {
        $this->assertSame(PaymentStatus::OVERDUE, $this->status(1));
        $this->assertSame(PaymentStatus::OVERDUE, $this->status(30));
        $this->assertSame(PaymentStatus::OVERDUE, $this->status(365));
    }

    public function test_rut_normalization(): void
    {
        $this->assertSame('123456785', Rut::normalize('12.345.678-5'));
        $this->assertSame('87654321K', Rut::normalize('8.765.432-1k'));
    }

    public function test_rut_formatting(): void
    {
        $this->assertSame('12.345.678-5', Rut::format('123456785'));
        $this->assertSame('1.111.111-1', Rut::format('11111111'));
    }

    public function test_valid_ruts(): void
    {
        $this->assertTrue(Rut::isValid('12.345.678-5'));
        $this->assertTrue(Rut::isValid('11.111.111-1'));
        $this->assertTrue(Rut::isValid('123456785'));
    }

    public function test_invalid_ruts(): void
    {
        $this->assertFalse(Rut::isValid('12.345.678-6')); // wrong check digit
        $this->assertFalse(Rut::isValid('not-a-rut'));
        $this->assertFalse(Rut::isValid(''));
        $this->assertFalse(Rut::isValid('123'));
    }
}
