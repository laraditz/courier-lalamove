<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\DTOs\Results\QuotationResult;
use Laraditz\Courier\Lalamove\Mappers\QuotationMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class QuotationMapperTest extends TestCase
{
    private function quotationResponse(): array
    {
        return [
            'data' => [
                'quotationId'    => 'QUO-XYZ789',
                'expiresAt'      => '2026-06-20T10:05:00Z',
                'priceBreakdown' => ['total' => '15.00', 'currency' => 'MYR'],
                'stops'          => [
                    ['stopId' => 'STOP-1', 'address' => 'KL'],
                    ['stopId' => 'STOP-2', 'address' => 'Shah Alam'],
                ],
            ],
        ];
    }

    public function test_returns_quotation_result_instance(): void
    {
        $result = QuotationMapper::map($this->quotationResponse());
        $this->assertInstanceOf(QuotationResult::class, $result);
    }

    public function test_maps_fields(): void
    {
        $result = QuotationMapper::map($this->quotationResponse());

        $this->assertSame('QUO-XYZ789', $result->quotationId);
        $this->assertSame(15.00, $result->price);
        $this->assertSame('MYR', $result->currency);
        $this->assertSame('2026-06-20T10:05:00Z', $result->expiresAt->toIso8601ZuluString());
    }

    public function test_meta_contains_stops(): void
    {
        $result = QuotationMapper::map($this->quotationResponse());
        $this->assertCount(2, $result->meta()['stops']);
        $this->assertSame('STOP-1', $result->meta()['stops'][0]['stopId']);
    }

    public function test_missing_expires_at_maps_to_null(): void
    {
        $response = $this->quotationResponse();
        unset($response['data']['expiresAt']);

        $result = QuotationMapper::map($response);
        $this->assertNull($result->expiresAt);
    }
}
