<?php

namespace App\Support;

use RuntimeException;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus;

/**
 * Builds the Transbank SDK Options object from config/webpay.php, so the
 * Livewire components and the return controller configure the same way.
 */
class Webpay
{
    public const ENV_INTEGRATION = 'integration';
    public const ENV_PRODUCTION = 'production';

    public static function options(): Options
    {
        $environment = config('webpay.environment');
        $commerceCode = config('webpay.commerce_code');
        $apiKey = config('webpay.api_key');

        // An unrecognised value used to fall through to the sandbox, so a
        // deploy that set WEBPAY_ENVIRONMENT=prod believed it was live and
        // took no real money at all.
        if (!in_array($environment, [self::ENV_INTEGRATION, self::ENV_PRODUCTION], true)) {
            throw new RuntimeException(
                'WEBPAY_ENVIRONMENT must be "' . self::ENV_INTEGRATION . '" or "' . self::ENV_PRODUCTION
                . '", got "' . $environment . '".'
            );
        }

        if ($environment === self::ENV_PRODUCTION) {
            self::assertRealCredentials($commerceCode, $apiKey);

            return Options::forProduction($commerceCode, $apiKey);
        }

        return Options::forIntegration($commerceCode, $apiKey);
    }

    /**
     * The sandbox credentials are the config *default*, so a production
     * deploy that forgets WEBPAY_COMMERCE_CODE / WEBPAY_API_KEY would
     * otherwise point at the live host with Transbank's public test keys
     * — every transaction failing at the gateway, or worse, appearing to
     * succeed against a sandbox that charges nobody. Fail at startup of
     * the payment instead, before the customer sees a payment form.
     */
    private static function assertRealCredentials(?string $commerceCode, ?string $apiKey): void
    {
        if ($commerceCode === null || $commerceCode === '' || $commerceCode === WebpayPlus::DEFAULT_COMMERCE_CODE) {
            throw new RuntimeException(
                'WEBPAY_ENVIRONMENT is "production" but WEBPAY_COMMERCE_CODE is still Transbank\'s '
                . 'integration commerce code. Set the real one issued for your merchant contract.'
            );
        }

        if ($apiKey === null || $apiKey === '' || $apiKey === Options::DEFAULT_API_KEY) {
            throw new RuntimeException(
                'WEBPAY_ENVIRONMENT is "production" but WEBPAY_API_KEY is still Transbank\'s '
                . 'public integration key. Set the real llave secreta from the merchant portal.'
            );
        }
    }
}
