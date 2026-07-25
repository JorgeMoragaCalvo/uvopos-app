<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transbank Webpay Plus credentials
    |--------------------------------------------------------------------------
    |
    | Defaults to Transbank's published integration (sandbox) commerce code
    | and API key, so local dev works without any setup. Set real values via
    | env for production and flip WEBPAY_ENVIRONMENT to "production".
    |
    */

    'commerce_code' => env('WEBPAY_COMMERCE_CODE', \Transbank\Webpay\WebpayPlus::DEFAULT_COMMERCE_CODE),

    'api_key' => env('WEBPAY_API_KEY', \Transbank\Webpay\Options::DEFAULT_API_KEY),

    'environment' => env('WEBPAY_ENVIRONMENT', 'integration'),

];
