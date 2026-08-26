<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Carbon\Carbon;
use Laraditz\Courier\DTOs\Results\DriverLocationResult;

final class DriverLocationMapper
{
    public static function map(array $response): DriverLocationResult
    {
        $data        = $response['data'] ?? $response;
        $coordinates = $data['coordinates'] ?? [];

        return new DriverLocationResult(
            driverId:  $data['driverId'] ?? '',
            lat:       (float) ($coordinates['lat'] ?? 0),
            lng:       (float) ($coordinates['lng'] ?? 0),
            updatedAt: isset($coordinates['updatedAt']) ? Carbon::parse($coordinates['updatedAt']) : null,
        );
    }
}
