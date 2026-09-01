<?php

namespace Laraditz\Courier\Lalamove\Tests;

use Laraditz\Courier\CourierServiceProvider;
use Laraditz\Courier\Lalamove\LalamoveServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CourierServiceProvider::class,
            LalamoveServiceProvider::class,
        ];
    }

    // courier_api_logs and courier_webhook_logs live in the core package. Without
    // them, ApiLogWriter's catch (Throwable) turns every failed write into a silent
    // Log::error and the suite goes green while logging nothing at all.
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laraditz/courier/database/migrations');
    }
}
