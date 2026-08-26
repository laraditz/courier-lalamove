<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class PopStatusChanged
{
    public function __construct(
        public string $orderId,
        public string $stopId,
        public array  $raw,
    ) {}
}
