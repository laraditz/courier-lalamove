<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\Lalamove\Mappers\RateMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class RateMapperTest extends TestCase
{
    private function quotationResponse(): array
    {
        return [
            'data' => [
                'quotationId' => 'QUO-XYZ789',
                'expiresAt'   => '2026-06-20T10:05:00Z',
                'priceBreakdown' => [
                    'total'    => '15.00',
                    'currency' => 'MYR',
                ],
            ],
        ];
    }

    public function test_returns_rate_collection(): void
    {
        $result = RateMapper::map($this->quotationResponse(), 'MOTORCYCLE');
        $this->assertInstanceOf(RateCollection::class, $result);
    }

    public function test_collection_has_one_item(): void
    {
        $result = RateMapper::map($this->quotationResponse(), 'MOTORCYCLE');
        $this->assertCount(1, $result->items);
    }

    public function test_rate_option_fields(): void
    {
        $option = RateMapper::map($this->quotationResponse(), 'MOTORCYCLE')->items[0];
        $this->assertSame('MOTORCYCLE', $option->serviceCode);
        $this->assertSame('MOTORCYCLE', $option->serviceName);
        $this->assertSame(15.00, $option->price);
        $this->assertSame('MYR', $option->currency);
        $this->assertNull($option->estimatedDays);
    }

    public function test_meta_contains_quotation_id_and_expires_at(): void
    {
        $option = RateMapper::map($this->quotationResponse(), 'MOTORCYCLE')->items[0];
        $this->assertSame('QUO-XYZ789', $option->meta()['quotation_id']);
        $this->assertSame('2026-06-20T10:05:00Z', $option->meta()['expires_at']);
    }
}
