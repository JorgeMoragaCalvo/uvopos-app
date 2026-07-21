<?php

namespace App\Support;

use Transbank\Webpay\Options;

/**
 * Builds the Transbank SDK Options object from config/webpay.php, so the
 * Livewire component and the return controller configure the same way.
 */
class Webpay
{
    public static function options(): Options
    {
        $commerceCode = config('webpay.commerce_code');
        $apiKey = config('webpay.api_key');

        return config('webpay.environment') === 'production'
            ? Options::forProduction($commerceCode, $apiKey)
            : Options::forIntegration($commerceCode, $apiKey);
    }
}
