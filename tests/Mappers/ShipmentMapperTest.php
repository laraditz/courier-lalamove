<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\DTOs\Results\ShipmentResult;
use Laraditz\Courier\Lalamove\Mappers\ShipmentMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class ShipmentMapperTest extends TestCase
{
    private function sampleResponse(): array
    {
        return [
            'data' => [
                'orderId'     => 'ORD-20260620-001',
                'quotationId' => 'QUO-ABC123',
                'status'      => 'ASSIGNING_DRIVER',
                'shareLink'   => 'https://share.lalamove.com/ORD-20260620-001',
                'driverId'    => '',
            ],
        ];
    }

    public function test_maps_order_id_as_waybill_number(): void
    {
        $result = ShipmentMapper::map($this->sampleResponse());
        $this->assertSame('ORD-20260620-001', $result->waybillNumber);
    }

    public function test_maps_status_to_pending(): void
    {
        $result = ShipmentMapper::map($this->sampleResponse());
        $this->assertSame('pending', $result->status);
    }

    public function test_estimated_delivery_is_null(): void
    {
        $result = ShipmentMapper::map($this->sampleResponse());
        $this->assertNull($result->estimatedDelivery);
    }

    public function test_meta_contains_share_link_and_quotation_id(): void
    {
        $result = ShipmentMapper::map($this->sampleResponse());
        $this->assertSame('https://share.lalamove.com/ORD-20260620-001', $result->meta()['share_link']);
        $this->assertSame('QUO-ABC123', $result->meta()['quotation_id']);
    }

    public function test_returns_shipment_result_instance(): void
    {
        $this->assertInstanceOf(ShipmentResult::class, ShipmentMapper::map($this->sampleResponse()));
    }
}
