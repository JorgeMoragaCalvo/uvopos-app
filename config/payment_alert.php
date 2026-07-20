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

];
