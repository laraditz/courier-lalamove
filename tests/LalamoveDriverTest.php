<?php

namespace Laraditz\Courier\Lalamove\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laraditz\Courier\DTOs\Payloads\AvailabilityPayload;
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;
use Laraditz\Courier\Models\CourierApiLog;
use Laraditz\Courier\DTOs\Results\CancelResult;
use Laraditz\Courier\DTOs\Results\DriverLocationResult;
use Laraditz\Courier\DTOs\Results\QuotationResult;
use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\DTOs\Results\ServiceCollection;
use Laraditz\Courier\DTOs\Results\ShipmentResult;
use Laraditz\Courier\DTOs\Results\TrackingResult;
use Laraditz\Courier\DTOs\Shared\Address;
use Laraditz\Courier\DTOs\Shared\Location;
use Laraditz\Courier\DTOs\Shared\Parcel;
use Laraditz\Courier\Enums\DeliveryMode;
use Laraditz\Courier\Exceptions\UnsupportedOperationException;
use Laraditz\Courier\Lalamove\LalamoveDriver;

class LalamoveDriverTest extends TestCase
{
    private function config(): array
    {
        return ['key' => 'pk_test', 'secret' => 'sk_test', 'sandbox' => true, 'market' => 'MY'];
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
        return [
            'data' => [
                'quotationId'    => 'QUO-001',
                'expiresAt'      => '2026-06-20T10:05:00Z',
                'priceBreakdown' => ['total' => '10.00', 'currency' => 'MYR'],
                'stops'          => [
                    ['stopId' => 'STOP-1', 'coordinates' => ['lat' => '3.139', 'lng' => '101.686'], 'address' => 'KL'],
                    ['stopId' => 'STOP-2', 'coordinates' => ['lat' => '3.085', 'lng' => '101.532'], 'address' => 'Shah Alam'],
                ],
            ],
        ];
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
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v3/orders') || $request->method() !== 'POST') {
                return true;
            }
            $data = $request->data()['data'];
            return $data['sender']['stopId'] === 'STOP-1'
                && $data['recipients'][0]['stopId'] === 'STOP-2';
        });
    }

    public function test_create_shipment_reuses_quotation_id_via_lookup(): void
    {
        Http::fake([
            '*/v3/quotations/QUO-REUSED' => Http::response($this->quotationResponse(), 200),
            '*/v3/orders'                => Http::response($this->orderResponse(), 201),
        ]);

        $driver = (new LalamoveDriver($this->config()))->withQuotationId('QUO-REUSED');
        $driver->createShipment(new ShipmentPayload(
            sender: $this->makeAddress(), recipient: $this->makeAddress(),
            parcel: $this->makeParcel(), serviceCode: 'MOTORCYCLE',
        ));

        // No new quotation is created, but the existing one is looked up to resolve
        // the stopIds that order creation requires.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/quotations/QUO-REUSED') && $request->method() === 'GET');
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

    // ── extractWebhookReference ─────────────────────────────────────────

    private function webhookRequest(array $data): Request
    {
        $request = Request::create('/courier/webhook/lalamove', 'POST', content: json_encode([
            'eventType' => 'ORDER_STATUS_CHANGED',
            'data'      => $data,
        ]));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    public function test_extract_webhook_reference_returns_order_id_as_waybill_number(): void
    {
        $reference = (new LalamoveDriver($this->config()))->extractWebhookReference(
            $this->webhookRequest(['order' => ['orderId' => 'ORD-001', 'status' => 'COMPLETED']])
        );

        $this->assertSame(['reference' => null, 'waybillNumber' => 'ORD-001'], $reference);
    }

    public function test_extract_webhook_reference_returns_null_when_no_order_present(): void
    {
        $reference = (new LalamoveDriver($this->config()))->extractWebhookReference(
            $this->webhookRequest(['balance' => ['amount' => '100', 'currency' => 'HKD']])
        );

        $this->assertNull($reference['reference']);
        $this->assertNull($reference['waybillNumber']);
    }

    // ── getDeliveryModes ─────────────────────────────────────────────────

    public function test_get_delivery_modes_returns_on_demand(): void
    {
        $modes = (new LalamoveDriver($this->config()))->getDeliveryModes();
        $this->assertSame([DeliveryMode::OnDemand], $modes);
    }

    // ── getQuotation (LooksUpQuotations) ─────────────────────────────────

    public function test_get_quotation_returns_quotation_result(): void
    {
        Http::fake(['*/v3/quotations/QUO-001' => Http::response($this->quotationResponse(), 200)]);

        $result = (new LalamoveDriver($this->config()))->getQuotation('QUO-001');

        $this->assertInstanceOf(QuotationResult::class, $result);
        $this->assertSame('QUO-001', $result->quotationId);
        $this->assertSame(10.00, $result->price);
        $this->assertSame('MYR', $result->currency);
    }

    // ── getDriverLocation (TracksDriverLocation) ─────────────────────────

    public function test_get_driver_location_returns_driver_location_result(): void
    {
        Http::fake(['*/v3/orders/ORD-001/drivers/DRV-1' => Http::response([
            'data' => [
                'driverId'    => 'DRV-1',
                'coordinates' => ['lat' => '13.740167', 'lng' => '100.535237', 'updatedAt' => '2021-12-01T14:30:00Z'],
            ],
        ], 200)]);

        $result = (new LalamoveDriver($this->config()))->getDriverLocation('ORD-001', 'DRV-1');

        $this->assertInstanceOf(DriverLocationResult::class, $result);
        $this->assertSame('DRV-1', $result->driverId);
        $this->assertSame(13.740167, $result->lat);
        $this->assertSame(100.535237, $result->lng);
    }

    // ── editOrder (SupportsOrderEditing) ─────────────────────────────────

    public function test_edit_order_maps_addresses_to_stops_and_returns_shipment_result(): void
    {
        Http::fake(['*/v3/orders/ORD-001' => Http::response($this->orderResponse(), 200)]);

        $result = (new LalamoveDriver($this->config()))->editOrder('ORD-001', [
            $this->makeAddress(),
            $this->makeAddress(),
        ]);

        $this->assertInstanceOf(ShipmentResult::class, $result);
        $this->assertSame('ORD-001', $result->waybillNumber);
        Http::assertSent(function ($r) {
            if ($r->method() !== 'PATCH') {
                return true;
            }
            $stops = $r->data()['data']['stops'];
            return count($stops) === 2
                && $stops[0]['address'] === 'Line 1, KL'
                && $stops[0]['coordinates']['lat'] === '3.139';
        });
    }

    // ── API log reference threading ───────────────────────────────────────

    public function test_create_shipment_threads_reference_onto_both_log_rows(): void
    {
        Http::fake([
            '*/v3/quotations' => Http::response($this->quotationResponse(), 201),
            '*/v3/orders'     => Http::response($this->orderResponse(), 201),
        ]);

        (new LalamoveDriver($this->config()))->createShipment(new ShipmentPayload(
            sender:      $this->makeAddress(),
            recipient:   $this->makeAddress(),
            parcel:      $this->makeParcel(),
            serviceCode: 'MOTORCYCLE',
            reference:   'MERCHANT-REF-9',
        ));

        $logs = CourierApiLog::orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame(['create_quotation', 'create_order'], $logs->pluck('action')->all());
        $this->assertSame(['MERCHANT-REF-9', 'MERCHANT-REF-9'], $logs->pluck('reference')->all());

        // Lalamove assigns the order id in the response, so create_order can never
        // carry a waybill_number — reference is the only way to find this row.
        $this->assertNull($logs->last()->waybill_number);
    }

    public function test_create_shipment_threads_reference_when_reusing_a_quotation(): void
    {
        Http::fake([
            '*/v3/quotations/QUO-1' => Http::response($this->quotationResponse(), 200),
            '*/v3/orders'           => Http::response($this->orderResponse(), 201),
        ]);

        (new LalamoveDriver($this->config()))
            ->withQuotationId('QUO-1')
            ->createShipment(new ShipmentPayload(
                sender:      $this->makeAddress(),
                recipient:   $this->makeAddress(),
                parcel:      $this->makeParcel(),
                serviceCode: 'MOTORCYCLE',
                reference:   'MERCHANT-REF-9',
            ));

        $logs = CourierApiLog::orderBy('id')->get();

        $this->assertSame(['get_quotation', 'create_order'], $logs->pluck('action')->all());
        $this->assertSame(['MERCHANT-REF-9', 'MERCHANT-REF-9'], $logs->pluck('reference')->all());
    }
}
