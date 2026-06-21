<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\DTOs\Results\TrackingEvent;
use Laraditz\Courier\DTOs\Results\TrackingResult;
use Laraditz\Courier\Lalamove\Mappers\TrackingMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class TrackingMapperTest extends TestCase
{
    private function response(string $status): array
    {
        return ['data' => ['status' => $status, 'shareLink' => 'https://share.lalamove.com/ORD1', 'driverId' => 'DRV1']];
    }

    /** @dataProvider statusProvider */
    public function test_maps_lalamove_status_to_courier_status(string $lalamove, string $expected): void
    {
        $result = TrackingMapper::map('ORD1', $this->response($lalamove));
        $this->assertSame($expected, $result->status);
    }

    public static function statusProvider(): array
    {
        return [
            ['ASSIGNING_DRIVER', 'pending'],
            ['ON_GOING',         'processing'],
            ['PICKED_UP',        'in_transit'],
            ['COMPLETED',        'delivered'],
            ['CANCELED',         'cancelled'],
            ['REJECTED',         'failed'],
            ['EXPIRED',          'failed'],
        ];
    }

    public function test_produces_single_tracking_event(): void
    {
        $result = TrackingMapper::map('ORD1', $this->response('ON_GOING'));
        $this->assertCount(1, $result->events);
        $this->assertInstanceOf(TrackingEvent::class, $result->events[0]);
    }

    public function test_event_location_is_empty_string(): void
    {
        $result = TrackingMapper::map('ORD1', $this->response('ON_GOING'));
        $this->assertSame('', $result->events[0]->location);
    }

    public function test_waybill_number_matches_order_id(): void
    {
        $result = TrackingMapper::map('ORD-UNIQUE', $this->response('COMPLETED'));
        $this->assertSame('ORD-UNIQUE', $result->waybillNumber);
    }

    public function test_estimated_delivery_is_null(): void
    {
        $result = TrackingMapper::map('ORD1', $this->response('ON_GOING'));
        $this->assertNull($result->estimatedDelivery);
    }

    public function test_meta_contains_share_link_and_driver_id(): void
    {
        $result = TrackingMapper::map('ORD1', $this->response('ON_GOING'));
        $this->assertSame('https://share.lalamove.com/ORD1', $result->meta()['share_link']);
        $this->assertSame('DRV1', $result->meta()['driver_id']);
    }

    /** @dataProvider statusProvider */
    public function test_map_status_returns_correct_courier_status(string $lalamove, string $expected): void
    {
        $this->assertSame($expected, TrackingMapper::mapStatus($lalamove));
    }

    public function test_map_status_returns_unknown_for_unrecognised_status(): void
    {
        $this->assertSame('unknown', TrackingMapper::mapStatus('SOME_FUTURE_STATUS'));
    }
}
