<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class OrderStatusChanged
{
    public function __construct(
        public string $orderId,
        public string $status,
        public string $mappedStatus,
        public array  $raw,
    ) {}
}
