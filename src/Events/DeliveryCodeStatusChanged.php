<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class DeliveryCodeStatusChanged
{
    public function __construct(
        public string $orderId,
        public string $stopId,
        public string $deliveryCodeStatus,
        public string $deliveryCodeValue,
        public array  $raw,
    ) {}
}
