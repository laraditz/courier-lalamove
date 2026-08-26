<?php

namespace Laraditz\Courier\Lalamove\Tests;

use Illuminate\Support\Facades\Event;
use Laraditz\Courier\Events\WebhookReceived;
use Laraditz\Courier\Lalamove\Events\DeliveryCodeStatusChanged;
use Laraditz\Courier\Lalamove\Events\DriverAssigned;
use Laraditz\Courier\Lalamove\Events\OrderAmountChanged;
use Laraditz\Courier\Lalamove\Events\OrderCreated;
use Laraditz\Courier\Lalamove\Events\OrderEdited;
use Laraditz\Courier\Lalamove\Events\OrderReplaced;
use Laraditz\Courier\Lalamove\Events\OrderStatusChanged;
use Laraditz\Courier\Lalamove\Events\PodStatusChanged;
use Laraditz\Courier\Lalamove\Events\PopStatusChanged;
use Laraditz\Courier\Lalamove\Events\WalletBalanceChanged;

class WebhookTest extends TestCase
{
    private const SECRET = 'test-api-secret';
    private const PATH   = '/courier/webhook/lalamove';

    protected function setUp(): void
    {
        parent::setUp();
        config(['courier.drivers.lalamove.secret' => self::SECRET]);
        config(['courier.default' => 'lalamove']);
    }

    private function signedPayload(string $eventType, array $data, ?string $secret = null): array
    {
        $timestamp    = (string) (int) (microtime(true) * 1000);
        $body         = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rawSignature = "{$timestamp}\r\nPOST\r\n" . self::PATH . "\r\n\r\n{$body}";
        $signature    = hash_hmac('sha256', $rawSignature, $secret ?? self::SECRET);

        return [
            'apiKey'    => 'pk_test',
            'timestamp' => $timestamp,
            'signature' => $signature,
            'eventId'   => 'EVT-001',
            'eventType' => $eventType,
            'data'      => $data,
        ];
    }

    // Two stops shaped like Lalamove's real webhook: pickup (index 0) is inert,
    // dropoff (index 1) carries whichever POD/POP/deliveryCode changed.
    private function stopsWith(array $dropoffExtra): array
    {
        return [
            [
                'address'      => 'Innocentre, 72 Tat Chee Ave, Kowloon Tong',
                'name'         => 'Michal',
                'deliveryCode' => ['value' => '', 'status' => 'Not Applicable'],
            ],
            array_merge([
                'address'      => 'Canton Rd, Tsim Sha Tsui',
                'name'         => 'Katrina',
                'deliveryCode' => ['value' => '', 'status' => 'Not Applicable'],
            ], $dropoffExtra),
        ];
    }

    public function test_returns_401_when_signature_missing(): void
    {
        $this->postJson(self::PATH, ['eventType' => 'ORDER_STATUS_CHANGED', 'data' => []])
            ->assertStatus(401);
    }

    public function test_returns_401_when_signature_invalid(): void
    {
        $payload = $this->signedPayload('ORDER_STATUS_CHANGED', [
            'order' => ['orderId' => 'ORD-001', 'status' => 'COMPLETED'],
        ]);
        $payload['signature'] = 'deadbeef';

        $this->postJson(self::PATH, $payload)
            ->assertStatus(401);
    }

    public function test_returns_401_when_signed_with_wrong_secret(): void
    {
        $payload = $this->signedPayload('ORDER_STATUS_CHANGED', [
            'order' => ['orderId' => 'ORD-001', 'status' => 'COMPLETED'],
        ], 'wrong-secret');

        $this->postJson(self::PATH, $payload)
            ->assertStatus(401);
    }

    public function test_returns_200_and_fires_generic_event_on_valid_request(): void
    {
        Event::fake([WebhookReceived::class, OrderStatusChanged::class]);

        $payload = $this->signedPayload('ORDER_STATUS_CHANGED', [
            'order' => ['orderId' => 'ORD-001', 'status' => 'COMPLETED'],
        ]);

        $this->postJson(self::PATH, $payload)
            ->assertStatus(200);

        Event::assertDispatched(WebhookReceived::class,
            fn ($e) => $e->driver === 'lalamove');
    }

    public function test_verifies_when_payload_contains_empty_string_fields(): void
    {
        // Regression: $request->all() passes through Laravel's ConvertEmptyStringsToNull
        // middleware, which rewrites "" to null and silently breaks byte-for-byte
        // signature reconstruction. verifyWebhook() must read the raw body instead.
        Event::fake([WebhookReceived::class, DriverAssigned::class]);

        $payload = $this->signedPayload('DRIVER_ASSIGNED', [
            'order'  => ['orderId' => 'ORD-001'],
            'driver' => ['driverId' => 'DRV-1', 'name' => 'Ali', 'photo' => ''],
        ]);

        $this->postJson(self::PATH, $payload)
            ->assertStatus(200);
    }

    public function test_fires_order_status_changed_event(): void
    {
        Event::fake([WebhookReceived::class, OrderStatusChanged::class]);

        $payload = $this->signedPayload('ORDER_STATUS_CHANGED', [
            'order' => ['orderId' => 'ORD-001', 'status' => 'COMPLETED'],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(OrderStatusChanged::class, function ($e) {
            return $e->orderId      === 'ORD-001'
                && $e->status       === 'COMPLETED'
                && $e->mappedStatus === 'delivered';
        });
    }

    public function test_fires_driver_assigned_event(): void
    {
        Event::fake([WebhookReceived::class, DriverAssigned::class]);

        $payload = $this->signedPayload('DRIVER_ASSIGNED', [
            'order'  => ['orderId' => 'ORD-001'],
            'driver' => ['driverId' => 'DRV-1', 'name' => 'Ali'],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(DriverAssigned::class, function ($e) {
            return $e->orderId === 'ORD-001'
                && $e->driverId === 'DRV-1'
                && $e->driverInfo['name'] === 'Ali';
        });
    }

    public function test_fires_order_amount_changed_event(): void
    {
        Event::fake([WebhookReceived::class, OrderAmountChanged::class]);

        $payload = $this->signedPayload('ORDER_AMOUNT_CHANGED', [
            'order' => [
                'orderId' => 'ORD-001',
                'price'   => ['totalPrice' => '104', 'priorityFee' => '10', 'currency' => 'HKD', 'subTotal' => '94'],
            ],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(OrderAmountChanged::class, function ($e) {
            return $e->orderId === 'ORD-001'
                && $e->totalPrice === '104'
                && $e->priorityFee === '10'
                && $e->currency === 'HKD';
        });
    }

    public function test_fires_order_replaced_event(): void
    {
        Event::fake([WebhookReceived::class, OrderReplaced::class]);

        $payload = $this->signedPayload('ORDER_REPLACED', [
            'order'       => ['orderId' => 'ORD-NEW'],
            'prevOrderId' => 'ORD-OLD',
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(OrderReplaced::class, function ($e) {
            return $e->orderId === 'ORD-NEW' && $e->previousOrderId === 'ORD-OLD';
        });
    }

    public function test_fires_wallet_balance_changed_event(): void
    {
        Event::fake([WebhookReceived::class, WalletBalanceChanged::class]);

        $payload = $this->signedPayload('WALLET_BALANCE_CHANGED', [
            'balance' => ['amount' => '99858', 'currency' => 'HKD'],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(WalletBalanceChanged::class, function ($e) {
            return $e->amount === '99858' && $e->currency === 'HKD';
        });
    }

    public function test_fires_order_edited_event(): void
    {
        Event::fake([WebhookReceived::class, OrderEdited::class]);

        $payload = $this->signedPayload('ORDER_EDITED', [
            'editReason'   => 'CLIENT_REQUEST',
            'editParty'    => 'USER',
            'previousData' => ['stops' => []],
            'order'        => ['orderId' => 'ORD-001', 'status' => 'ON_GOING'],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(OrderEdited::class, function ($e) {
            return $e->orderId === 'ORD-001'
                && $e->editReason === 'CLIENT_REQUEST'
                && $e->editParty === 'USER';
        });
    }

    public function test_fires_pod_status_changed_event(): void
    {
        Event::fake([WebhookReceived::class, PodStatusChanged::class]);

        $payload = $this->signedPayload('POD_STATUS_CHANGED', [
            'order' => [
                'orderId' => 'ORD-001',
                'stops'   => $this->stopsWith(['POD' => ['status' => 'DELIVERED', 'deliveredAt' => '2026-04-01T16:19:00Z']]),
            ],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(PodStatusChanged::class, function ($e) {
            return $e->orderId === 'ORD-001' && $e->stopId === '1' && $e->podStatus === 'DELIVERED';
        });
    }

    public function test_fires_pop_status_changed_event(): void
    {
        Event::fake([WebhookReceived::class, PopStatusChanged::class]);

        $payload = $this->signedPayload('POP_STATUS_CHANGED', [
            'order' => [
                'orderId' => 'ORD-001',
                'stops'   => $this->stopsWith(['POP' => ['imageUrls' => ['http://example.com/a.jpg'], 'pickedUpAt' => '2026-04-13T10:35:00Z']]),
            ],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(PopStatusChanged::class, function ($e) {
            return $e->orderId === 'ORD-001' && $e->stopId === '1';
        });
    }

    public function test_fires_delivery_code_status_changed_event(): void
    {
        Event::fake([WebhookReceived::class, DeliveryCodeStatusChanged::class]);

        $payload = $this->signedPayload('DELIVERY_CODE_STATUS_CHANGED', [
            'order' => [
                'orderId' => 'ORD-001',
                'stops'   => $this->stopsWith(['deliveryCode' => ['value' => '6472', 'status' => 'Verified']]),
            ],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(DeliveryCodeStatusChanged::class, function ($e) {
            return $e->orderId === 'ORD-001'
                && $e->stopId === '1'
                && $e->deliveryCodeStatus === 'Verified'
                && $e->deliveryCodeValue === '6472';
        });
    }

    public function test_fires_order_created_event(): void
    {
        Event::fake([WebhookReceived::class, OrderCreated::class]);

        $payload = $this->signedPayload('ORDER_CREATED', [
            'order' => ['orderId' => 'ORD-001', 'market' => 'HK_HKG'],
        ]);

        $this->postJson(self::PATH, $payload);

        Event::assertDispatched(OrderCreated::class, function ($e) {
            return $e->orderId === 'ORD-001' && $e->market === 'HK_HKG';
        });
    }

    public function test_unknown_event_type_returns_200_silently(): void
    {
        Event::fake();

        $payload = $this->signedPayload('SOME_FUTURE_EVENT', []);

        $this->postJson(self::PATH, $payload)
            ->assertStatus(200);
    }
}
