<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\DTOs\Results\DriverLocationResult;
use Laraditz\Courier\Lalamove\Mappers\DriverLocationMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class DriverLocationMapperTest extends TestCase
{
    // Shape verified against Lalamove's "Get Driver Details" docs — coordinates,
    // including updatedAt, are nested under data.coordinates, not top-level.
    private function driverResponse(): array
    {
        return [
            'data' => [
                'driverId'    => '33522',
                'name'        => 'David',
                'phone'       => '37013701',
                'plateNumber' => '**LAM0V*',
                'photo'       => '<PROFILE_PHOTO_URL>',
                'coordinates' => [
                    'lat'       => '13.740167',
                    'lng'       => '100.535237',
                    'updatedAt' => '2021-12-01T14:30:00Z',
                ],
            ],
        ];
    }

    public function test_returns_driver_location_result_instance(): void
    {
        $result = DriverLocationMapper::map($this->driverResponse());
        $this->assertInstanceOf(DriverLocationResult::class, $result);
    }

    public function test_maps_fields(): void
    {
        $result = DriverLocationMapper::map($this->driverResponse());

        $this->assertSame('33522', $result->driverId);
        $this->assertSame(13.740167, $result->lat);
        $this->assertSame(100.535237, $result->lng);
        $this->assertSame('2021-12-01T14:30:00Z', $result->updatedAt->toIso8601ZuluString());
    }

    public function test_missing_coordinates_maps_to_zero_and_null(): void
    {
        $response = $this->driverResponse();
        unset($response['data']['coordinates']);

        $result = DriverLocationMapper::map($response);

        $this->assertSame(0.0, $result->lat);
        $this->assertSame(0.0, $result->lng);
        $this->assertNull($result->updatedAt);
    }
}
