<?php

namespace App\Providers;

use App\Support\Cartola\CartolaReader;
use App\Support\Reconciliation\MatchEngine;
use App\Support\Webpay;
use Illuminate\Support\ServiceProvider;
use Transbank\Webpay\WebpayPlus\Transaction;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Both take their tuning as constructor arguments rather than
        // reading config() internally, so that they stay unit-testable
        // without the container. That means the container needs to be
        // told how to build them.
        $this->app->bind(CartolaReader::class, function () {
            return CartolaReader::fromConfig();
        });

        $this->app->bind(MatchEngine::class, function () {
            return MatchEngine::fromConfig();
        });

        // Resolved rather than `new`ed at the call sites so the gateway
        // boundary can be faked in tests — the commit path is otherwise
        // impossible to exercise without talking to Transbank. Bound lazily
        // because Webpay::options() throws on a misconfigured production
        // environment, and that must surface when a payment starts, not
        // when the container boots.
        $this->app->bind(Transaction::class, function () {
            return new Transaction(Webpay::options());
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
