<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class OrderCreated
{
    public function __construct(
        public string $orderId,
        public string $market,
        public array  $raw,
    ) {}
}
