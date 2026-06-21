<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class DriverAssigned
{
    public function __construct(
        public string $orderId,
        public string $driverId,
        public array  $driverInfo,
        public array  $raw,
    ) {}
}
