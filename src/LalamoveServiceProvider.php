<?php

namespace Laraditz\Courier\Lalamove;

use Illuminate\Support\ServiceProvider;

class LalamoveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lalamove.php', 'courier.drivers.lalamove');
    }

    public function boot(): void
    {
        $this->app->make('courier')->extend(
            'lalamove',
            fn ($app, $config) => new LalamoveDriver($config)
        );

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lalamove.php' => config_path('lalamove.php'),
            ], 'courier-lalamove-config');
        }
    }
}
