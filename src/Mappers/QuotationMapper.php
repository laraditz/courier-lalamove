<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Carbon\Carbon;
use Laraditz\Courier\DTOs\Results\QuotationResult;

final class QuotationMapper
{
    public static function map(array $response): QuotationResult
    {
        $data      = $response['data'] ?? $response;
        $breakdown = $data['priceBreakdown'] ?? [];

        return new QuotationResult(
            quotationId: $data['quotationId'] ?? '',
            price:       (float) ($breakdown['total'] ?? 0),
            currency:    $breakdown['currency'] ?? '',
            expiresAt:   isset($data['expiresAt']) ? Carbon::parse($data['expiresAt']) : null,
            meta: [
                'stops' => $data['stops'] ?? [],
            ],
        );
    }
}
