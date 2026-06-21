<?php

namespace Laraditz\Courier\Lalamove\Tests;

use Illuminate\Support\Facades\Event;
use Laraditz\Courier\Events\WebhookReceived;
use Laraditz\Courier\Lalamove\Events\DriverAssigned;
use Laraditz\Courier\Lalamove\Events\OrderStatusChanged;
use Laraditz\Courier\Lalamove\Events\PodStatusChanged;

class WebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['courier.drivers.lalamove.webhook_secret' => 'test-secret-token']);
        config(['courier.default' => 'lalamove']);
    }

    public function test_returns_401_when_token_missing(): void
    {
        $this->postJson('/courier/webhook/lalamove', ['eventType' => 'order.status.updated'])
            ->assertStatus(401);
    }

    public function test_returns_401_when_token_invalid(): void
    {
        $this->withHeaders(['X-LLM-Token' => 'wrong-token'])
            ->postJson('/courier/webhook/lalamove', ['eventType' => 'order.status.updated'])
            ->assertStatus(401);
    }

    public function test_returns_200_and_fires_generic_event_on_valid_request(): void
    {
        Event::fake([WebhookReceived::class, OrderStatusChanged::class]);

        $this->withHeaders(['X-LLM-Token' => 'test-secret-token'])
            ->postJson('/courier/webhook/lalamove', [
                'eventType' => 'order.status.updated',
                'data'      => ['orderId' => 'ORD-001', 'status' => 'COMPLETED'],
            ])
            ->assertStatus(200);

        Event::assertDispatched(WebhookReceived::class,
            fn ($e) => $e->driver === 'lalamove');
    }

    public function test_fires_order_status_changed_event(): void
    {
        Event::fake([WebhookReceived::class, OrderStatusChanged::class]);

        $this->withHeaders(['X-LLM-Token' => 'test-secret-token'])
            ->postJson('/courier/webhook/lalamove', [
                'eventType' => 'order.status.updated',
                'data'      => ['orderId' => 'ORD-001', 'status' => 'COMPLETED'],
            ]);

        Event::assertDispatched(OrderStatusChanged::class, function ($e) {
            return $e->orderId      === 'ORD-001'
                && $e->status       === 'COMPLETED'
                && $e->mappedStatus === 'delivered';
        });
    }

    public function test_fires_driver_assigned_event(): void
    {
        Event::fake([WebhookReceived::class, DriverAssigned::class]);

        $this->withHeaders(['X-LLM-Token' => 'test-secret-token'])
            ->postJson('/courier/webhook/lalamove', [
                'eventType' => 'driver.assigned',
                'data'      => ['orderId' => 'ORD-001', 'driverId' => 'DRV-1', 'driver' => ['name' => 'Ali']],
            ]);

        Event::assertDispatched(DriverAssigned::class, function ($e) {
            return $e->orderId === 'ORD-001'
                && $e->driverId === 'DRV-1'
                && $e->driverInfo['name'] === 'Ali';
        });
    }

    public function test_fires_pod_status_changed_event(): void
    {
        Event::fake([WebhookReceived::class, PodStatusChanged::class]);

        $this->withHeaders(['X-LLM-Token' => 'test-secret-token'])
            ->postJson('/courier/webhook/lalamove', [
                'eventType' => 'pod.status.updated',
                'data'      => ['orderId' => 'ORD-001', 'stopId' => 'STP-1', 'podStatus' => 'DELIVERED'],
            ]);

        Event::assertDispatched(PodStatusChanged::class, function ($e) {
            return $e->podStatus === 'DELIVERED' && $e->stopId === 'STP-1';
        });
    }

    public function test_unknown_event_type_returns_200_silently(): void
    {
        Event::fake();

        $this->withHeaders(['X-LLM-Token' => 'test-secret-token'])
            ->postJson('/courier/webhook/lalamove', ['eventType' => 'some.future.event', 'data' => []])
            ->assertStatus(200);
    }
}
