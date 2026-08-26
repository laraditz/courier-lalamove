<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class OrderEdited
{
    public function __construct(
        public string $orderId,
        public string $editReason,
        public string $editParty,
        public array  $raw,
    ) {}
}
