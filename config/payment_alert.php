<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment status thresholds (days past the payment date)
    |--------------------------------------------------------------------------
    |
    | on time  : days past due <= -(due_soon_days + 1)
    | due soon : -due_soon_days .. 0 (i.e. within due_soon_days of the due date, up to and including it)
    | overdue  : > 0, indefinitely
    |
    */

    'due_soon_days' => env('PAYMENT_ALERT_DUE_SOON_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Suspension grace period (days past due, on top of overdue)
    |--------------------------------------------------------------------------
    |
    | Once a company is overdue, staff still can't suspend it until it has
    | been overdue for more than this many days (days_past_due > overdue_grace_days).
    | Suspension itself is always a manual, staff-confirmed action — this
    | threshold only controls when the "Suspender" button becomes available.
    |
    */

    'overdue_grace_days' => env('PAYMENT_ALERT_OVERDUE_GRACE_DAYS', 3),

];
