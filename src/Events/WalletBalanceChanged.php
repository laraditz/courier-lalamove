<?php
namespace Laraditz\Courier\Lalamove\Events;
readonly class WalletBalanceChanged
{
    public function __construct(
        public string $amount,
        public string $currency,
        public array  $raw,
    ) {}
}
