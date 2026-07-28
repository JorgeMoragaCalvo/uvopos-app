<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Transbank sends the customer's browser back here with a
        // cross-site form POST after Webpay Plus checkout. That request
        // cannot carry our CSRF token, so without this exemption every
        // real payment ends in a 419 — after the card has been charged.
        //
        // Safe because the route trusts nothing in the request body: it
        // authenticates the payment by committing the token with
        // Transbank and looking the buy order up in `webpay_transactions`.
        'payment-alert/webpay/return',
    ];
}
