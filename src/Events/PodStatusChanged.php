<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class PodStatusChanged
{
    public function __construct(
        public string $orderId,
        public string $stopId,
        public string $podStatus,
        public array  $raw,
    ) {}
}
