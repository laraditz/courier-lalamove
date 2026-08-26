<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class OrderAmountChanged
{
    public function __construct(
        public string $orderId,
        public string $totalPrice,
        public string $priorityFee,
        public string $currency,
        public array  $raw,
    ) {}
}
