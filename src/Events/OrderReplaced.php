<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class OrderReplaced
{
    public function __construct(
        public string $orderId,
        public string $previousOrderId,
        public array  $raw,
    ) {}
}
