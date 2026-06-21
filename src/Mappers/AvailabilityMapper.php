<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Laraditz\Courier\DTOs\Results\ServiceCollection;
use Laraditz\Courier\DTOs\Results\ServiceOption;

final class AvailabilityMapper
{
    public static function map(array $response): ServiceCollection
    {
        $cities = $response['data']['cities'] ?? [];
        $seen   = [];
        $items  = [];

        foreach ($cities as $city) {
            foreach ($city['services'] ?? [] as $service) {
                $key = $service['key'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[]    = new ServiceOption(
                    code:          $key,
                    name:          $key,
                    description:   $service['description'] ?? '',
                    estimatedDays: null,
                );
            }
        }

        return new ServiceCollection($items);
    }
}
