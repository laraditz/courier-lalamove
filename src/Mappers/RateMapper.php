<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\DTOs\Results\RateOption;

final class RateMapper
{
    public static function map(array $response, string $serviceType): RateCollection
    {
        $data      = $response['data'] ?? $response;
        $breakdown = $data['priceBreakdown'] ?? [];

        $option = new RateOption(
            serviceCode:   $serviceType,
            serviceName:   $serviceType,
            price:         (float) ($breakdown['total'] ?? 0),
            currency:      $breakdown['currency'] ?? '',
            estimatedDays: null,
            meta: [
                'quotation_id' => $data['quotationId'] ?? null,
                'expires_at'   => $data['expiresAt']   ?? null,
            ],
        );

        return new RateCollection([$option]);
    }
}
