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
}
