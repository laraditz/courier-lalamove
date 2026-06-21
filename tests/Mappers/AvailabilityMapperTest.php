<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\DTOs\Results\ServiceCollection;
use Laraditz\Courier\DTOs\Results\ServiceOption;
use Laraditz\Courier\Lalamove\Mappers\AvailabilityMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class AvailabilityMapperTest extends TestCase
{
    private function citiesResponse(): array
    {
        return [
            'data' => [
                'cities' => [
                    [
                        'locode'   => 'MY KUL',
                        'services' => [
                            ['key' => 'MOTORCYCLE', 'description' => 'Small parcels up to 20 kg'],
                            ['key' => 'CAR',        'description' => 'Medium parcels up to 200 kg'],
                        ],
                    ],
                    [
                        'locode'   => 'MY PEN',
                        'services' => [
                            ['key' => 'MOTORCYCLE', 'description' => 'Small parcels up to 20 kg'],  // duplicate key
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_returns_service_collection(): void
    {
        $result = AvailabilityMapper::map($this->citiesResponse());
        $this->assertInstanceOf(ServiceCollection::class, $result);
    }

    public function test_deduplicates_by_service_key(): void
    {
        $result = AvailabilityMapper::map($this->citiesResponse());
        $this->assertCount(2, $result->items);  // MOTORCYCLE appears twice but deduped to one
    }

    public function test_service_option_fields(): void
    {
        $result  = AvailabilityMapper::map($this->citiesResponse());
        $options = collect($result->items)->keyBy('code');

        $this->assertArrayHasKey('MOTORCYCLE', $options);
        $this->assertSame('MOTORCYCLE', $options['MOTORCYCLE']->code);
        $this->assertSame('MOTORCYCLE', $options['MOTORCYCLE']->name);
        $this->assertSame('Small parcels up to 20 kg', $options['MOTORCYCLE']->description);
        $this->assertNull($options['MOTORCYCLE']->estimatedDays);
    }

    public function test_empty_response_returns_empty_collection(): void
    {
        $result = AvailabilityMapper::map(['data' => ['cities' => []]]);
        $this->assertCount(0, $result->items);
    }
}
