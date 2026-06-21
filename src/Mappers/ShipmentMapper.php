<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Laraditz\Courier\DTOs\Results\ShipmentResult;

final class ShipmentMapper
{
    private static array $statusMap = [
        'ASSIGNING_DRIVER' => 'pending',
        'ON_GOING'         => 'processing',
        'PICKED_UP'        => 'in_transit',
        'COMPLETED'        => 'delivered',
        'CANCELED'         => 'cancelled',
        'REJECTED'         => 'failed',
        'EXPIRED'          => 'failed',
    ];

    public static function map(array $response): ShipmentResult
    {
        $data = $response['data'] ?? $response;

        return new ShipmentResult(
            waybillNumber:     $data['orderId'],
            status:            self::$statusMap[$data['status'] ?? ''] ?? 'unknown',
            estimatedDelivery: null,
            meta: [
                'share_link'   => $data['shareLink'] ?? null,
                'quotation_id' => $data['quotationId'] ?? null,
            ],
        );
    }
}
