<?php

namespace Laraditz\Courier\Lalamove\Tests;

use Illuminate\Support\Facades\Http;
use Laraditz\Courier\DTOs\Payloads\AvailabilityPayload;
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;
use Laraditz\Courier\DTOs\Results\CancelResult;
use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\DTOs\Results\ServiceCollection;
use Laraditz\Courier\DTOs\Results\ShipmentResult;
use Laraditz\Courier\DTOs\Results\TrackingResult;
use Laraditz\Courier\DTOs\Shared\Address;
use Laraditz\Courier\DTOs\Shared\Location;
use Laraditz\Courier\DTOs\Shared\Parcel;
use Laraditz\Courier\Exceptions\UnsupportedOperationException;
use Laraditz\Courier\Lalamove\LalamoveDriver;

class LalamoveDriverTest extends TestCase
{
    private function config(): array
    {
        return ['key' => 'pk_test', 'secret' => 'sk_test', 'sandbox' => true, 'market' => 'MY', 'webhook_secret' => 'secret'];
    }

    private function makeAddress(): Address
    {
        return new Address('Name', '+60123456789', null, 'Line 1', null, null, 'KL', 'WP', '50000', 'MY', lat: 3.139, lng: 101.686);
    }

    private function makeParcel(): Parcel
    {
        return new Parcel(1.0, 10.0, 10.0, 10.0, 50.0, 'Goods', 1);
    }

    private function quotationResponse(): array
    {
        return ['data' => ['quotationId' => 'QUO-001', 'expiresAt' => '2026-06-20T10:05:00Z', 'priceBreakdown' => ['total' => '10.00', 'currency' => 'MYR']]];
    }

    private function orderResponse(): array
    {
        return ['data' => ['orderId' => 'ORD-001', 'quotationId' => 'QUO-001', 'status' => 'ASSIGNING_DRIVER', 'shareLink' => 'https://share.lalamove.com/ORD-001', 'driverId' => '']];
    }

    // ── getRates ─────────────────────────────────────────────────────────

    public function test_get_rates_returns_rate_collection(): void
    {
        Http::fake(['*/v3/quotations' => Http::response($this->quotationResponse(), 201)]);

        $driver = new LalamoveDriver($this->config());
        $result = $driver->getRates(new RatePayload(
            origin:      new Location('50000', 'KL', 'WP', 'MY', lat: 3.139, lng: 101.686),
            destination: new Location('40150', 'Shah Alam', 'Selangor', 'MY', lat: 3.085, lng: 101.532),
            parcel:      $this->makeParcel(),
            serviceCode: 'MOTORCYCLE',
        ));

        $this->assertInstanceOf(RateCollection::class, $result);
        $this->assertCount(1, $result->items);
        $this->assertSame('QUO-001', $result->items[0]->meta()['quotation_id']);
    }

    // ── createShipment (two-step) ─────────────────────────────────────────

    public function test_create_shipment_calls_quotation_then_order(): void
    {
        Http::fake([
            '*/v3/quotations' => Http::response($this->quotationResponse(), 201),
            '*/v3/orders'     => Http::response($this->orderResponse(), 201),
        ]);

        $driver  = new LalamoveDriver($this->config());
        $result  = $driver->createShipment(new ShipmentPayload(
            sender:      $this->makeAddress(),
            recipient:   $this->makeAddress(),
            parcel:      $this->makeParcel(),
            serviceCode: 'MOTORCYCLE',
        ));

        $this->assertInstanceOf(ShipmentResult::class, $result);
        $this->assertSame('ORD-001', $result->waybillNumber);
        Http::assertSentCount(2);
    }

    public function test_create_shipment_skips_quotation_when_id_provided(): void
    {
        Http::fake(['*/v3/orders' => Http::response($this->orderResponse(), 201)]);

        $driver = (new LalamoveDriver($this->config()))->withQuotationId('QUO-REUSED');
        $driver->createShipment(new ShipmentPayload(
            sender: $this->makeAddress(), recipient: $this->makeAddress(),
            parcel: $this->makeParcel(), serviceCode: 'MOTORCYCLE',
        ));

        Http::assertSentCount(1);  // only order creation, no quotation
    }

    // ── track ─────────────────────────────────────────────────────────────

    public function test_track_returns_tracking_result(): void
    {
        Http::fake(['*/v3/orders/ORD-001' => Http::response($this->orderResponse(), 200)]);

        $result = (new LalamoveDriver($this->config()))->track('ORD-001');

        $this->assertInstanceOf(TrackingResult::class, $result);
        $this->assertSame('ORD-001', $result->waybillNumber);
        $this->assertSame('pending', $result->status);
    }

    // ── getShipment ──────────────────────────────────────────────────────

    public function test_get_shipment_throws_unsupported_exception(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        (new LalamoveDriver($this->config()))->getShipment('ORD-001');
    }

    // ── cancelShipment ───────────────────────────────────────────────────

    public function test_cancel_shipment_returns_cancel_result(): void
    {
        Http::fake(['*/v3/orders/ORD-001' => Http::response(null, 204)]);

        $result = (new LalamoveDriver($this->config()))->cancelShipment('ORD-001');

        $this->assertInstanceOf(CancelResult::class, $result);
        $this->assertTrue($result->success);
    }

    // ── getLabel ─────────────────────────────────────────────────────────

    public function test_get_label_throws_unsupported_exception(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        (new LalamoveDriver($this->config()))->getLabel('ORD-001');
    }

    // ── getAvailability ──────────────────────────────────────────────────

    public function test_get_availability_returns_service_collection(): void
    {
        Http::fake(['*/v3/cities' => Http::response([
            'data' => ['cities' => [['locode' => 'MY KUL', 'services' => [['key' => 'MOTORCYCLE', 'description' => 'Bike']]]]]
        ], 200)]);

        $result = (new LalamoveDriver($this->config()))->getAvailability(new AvailabilityPayload(
            origin:      new Location('50000', 'KL', 'WP', 'MY'),
            destination: new Location('40150', 'Shah Alam', 'Selangor', 'MY'),
        ));

        $this->assertInstanceOf(ServiceCollection::class, $result);
        $this->assertCount(1, $result->items);
    }

    // ── market() fluent method ───────────────────────────────────────────

    public function test_market_returns_clone(): void
    {
        $driver = new LalamoveDriver($this->config());
        $clone  = $driver->market('SG');
        $this->assertNotSame($driver, $clone);
    }

    public function test_market_override_used_in_request(): void
    {
        Http::fake(['*/v3/cities' => Http::response(['data' => ['cities' => []]], 200)]);
        (new LalamoveDriver($this->config()))->market('SG')->getAvailability(
            new AvailabilityPayload(new Location('1', 'SG', 'SG', 'SG'), new Location('1', 'SG', 'SG', 'SG'))
        );
        Http::assertSent(fn ($r) => $r->header('Market')[0] === 'SG');
    }
}
