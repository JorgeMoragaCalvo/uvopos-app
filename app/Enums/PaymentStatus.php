<?php

namespace App\Enums;

/**
 * Payment status derived from how many days a customer is past
 * their payment date. Plain class with constants (not a native enum)
 * so it runs on PHP 8.0, the minimum for Laravel 9.
 */
class PaymentStatus
{
    public const ON_TIME  = 'on_time';
    public const DUE_SOON = 'due_soon';
    public const OVERDUE  = 'overdue';

    /**
     * Resolve a status from the number of days past the payment date.
     * Negative or zero means the payment is not yet due.
     */
    public static function fromDaysPastDue(int $days, ?int $dueSoonDays = null): string
    {
        $dueSoonDays = $dueSoonDays ?? (int) config('payment_alert.due_soon_days', 3);

        if ($days > 0) {
            return self::OVERDUE;
        }

        if ($days >= -$dueSoonDays) {
            return self::DUE_SOON;
        }

        return self::ON_TIME;
    }

    public static function label(string $status): string
    {
        return [
            self::ON_TIME  => 'Pago al día',
            self::DUE_SOON => 'Pago próximo a vencer',
            self::OVERDUE  => 'Pago atrasado',
        ][$status] ?? $status;
    }

    /**
     * Bootstrap 4 alert class for the status. "alert-orange" is a small
     * custom class shipped with the component view (Bootstrap 4 has no
     * orange alert variant).
     */
    public static function alertClass(string $status): string
    {
        return [
            self::ON_TIME  => 'alert-success',
            self::DUE_SOON => 'alert-orange',
            self::OVERDUE  => 'alert-danger',
        ][$status] ?? 'alert-secondary';
    }

    /** Badge class, handy if the app prefers badges over alerts. */
    public static function badgeClass(string $status): string
    {
        return [
            self::ON_TIME  => 'badge-success',
            self::DUE_SOON => 'badge-orange',
            self::OVERDUE  => 'badge-danger',
        ][$status] ?? 'badge-secondary';
    }
}
