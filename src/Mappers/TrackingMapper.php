<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Carbon\Carbon;
use Laraditz\Courier\DTOs\Results\TrackingEvent;
use Laraditz\Courier\DTOs\Results\TrackingResult;

final class TrackingMapper
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

    private static array $descriptions = [
        'ASSIGNING_DRIVER' => 'Looking for a driver',
        'ON_GOING'         => 'Driver assigned and on the way',
        'PICKED_UP'        => 'Items collected by driver',
        'COMPLETED'        => 'Delivered successfully',
        'CANCELED'         => 'Order cancelled',
        'REJECTED'         => 'Order rejected',
        'EXPIRED'          => 'No driver found',
    ];

    public static function map(string $orderId, array $response): TrackingResult
    {
        $data          = $response['data'] ?? $response;
        $rawStatus     = $data['status'] ?? '';
        $mappedStatus  = self::$statusMap[$rawStatus] ?? 'unknown';

        $event = new TrackingEvent(
            timestamp:   Carbon::now(),
            location:    '',
            description: self::$descriptions[$rawStatus] ?? $rawStatus,
            status:      $mappedStatus,
        );

        return new TrackingResult(
            waybillNumber:     $orderId,
            status:            $mappedStatus,
            estimatedDelivery: null,
            events:            [$event],
            meta: [
                'share_link' => $data['shareLink'] ?? null,
                'driver_id'  => $data['driverId']  ?? null,
            ],
        );
    }

    public static function mapStatus(string $status): string
    {
        return self::$statusMap[$status] ?? 'unknown';
    }
}
