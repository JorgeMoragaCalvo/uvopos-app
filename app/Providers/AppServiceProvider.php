<?php

namespace App\Providers;

use App\Support\Cartola\CartolaReader;
use App\Support\Reconciliation\MatchEngine;
use Illuminate\Support\ServiceProvider;

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
