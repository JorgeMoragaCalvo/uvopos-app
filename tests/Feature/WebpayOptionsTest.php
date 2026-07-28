<?php

namespace Tests\Feature;

use App\Support\Webpay;
use RuntimeException;
use Tests\TestCase;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus;

/**
 * The sandbox credentials are the config *default*, which is convenient
 * locally and dangerous on deploy: a production release that forgot
 * WEBPAY_COMMERCE_CODE / WEBPAY_API_KEY used to point at the live host
 * with Transbank's public test keys, and an unrecognised environment
 * value fell through to the sandbox silently.
 */
class WebpayOptionsTest extends TestCase
{
    public function test_integration_uses_the_sandbox_host(): void
    {
        config(['webpay.environment' => 'integration']);

        $this->assertStringContainsString('webpay3gint', Webpay::options()->getApiBaseUrl());
    }

    public function test_production_with_real_credentials_uses_the_live_host(): void
    {
        config([
            'webpay.environment' => 'production',
            'webpay.commerce_code' => '597000000001',
            'webpay.api_key' => 'a-real-secret',
        ]);

        $options = Webpay::options();

        $this->assertSame('597000000001', $options->getCommerceCode());
        $this->assertStringNotContainsString('webpay3gint', $options->getApiBaseUrl());
    }

    public function test_production_refuses_the_default_commerce_code(): void
    {
        config([
            'webpay.environment' => 'production',
            'webpay.commerce_code' => WebpayPlus::DEFAULT_COMMERCE_CODE,
            'webpay.api_key' => 'a-real-secret',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WEBPAY_COMMERCE_CODE');

        Webpay::options();
    }

    public function test_production_refuses_the_default_api_key(): void
    {
        config([
            'webpay.environment' => 'production',
            'webpay.commerce_code' => '597000000001',
            'webpay.api_key' => Options::DEFAULT_API_KEY,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WEBPAY_API_KEY');

        Webpay::options();
    }

    /**
     * "prod" used to be treated as the sandbox, so the deploy believed it
     * was live and charged nobody.
     */
    public function test_an_unrecognised_environment_is_rejected(): void
    {
        config(['webpay.environment' => 'prod']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WEBPAY_ENVIRONMENT');

        Webpay::options();
    }
}
