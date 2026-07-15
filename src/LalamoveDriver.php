<?php

namespace Laraditz\Courier\Lalamove;

use Illuminate\Http\Request;
use Laraditz\Courier\Contracts\CourierDriver;
use Laraditz\Courier\Contracts\HandlesWebhooks;
use Laraditz\Courier\DTOs\Payloads\AvailabilityPayload;
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;
use Laraditz\Courier\DTOs\Results\CancelResult;
use Laraditz\Courier\DTOs\Results\LabelResult;
use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\DTOs\Results\ServiceCollection;
use Laraditz\Courier\DTOs\Results\ShipmentResult;
use Laraditz\Courier\DTOs\Results\TrackingResult;
use Laraditz\Courier\Lalamove\Events\DriverAssigned;
use Laraditz\Courier\Lalamove\Events\OrderStatusChanged;
use Laraditz\Courier\Lalamove\Events\PodStatusChanged;
use Laraditz\Courier\Lalamove\Http\LalamoveClient;
use Laraditz\Courier\Lalamove\Mappers\AvailabilityMapper;
use Laraditz\Courier\Lalamove\Mappers\CancelMapper;
use Laraditz\Courier\Lalamove\Mappers\LabelMapper;
use Laraditz\Courier\Lalamove\Mappers\RateMapper;
use Laraditz\Courier\Lalamove\Mappers\ShipmentMapper;
use Laraditz\Courier\Lalamove\Mappers\TrackingMapper;

class LalamoveDriver implements CourierDriver, HandlesWebhooks
{
    private LalamoveClient $client;
    private ?string $quotationId = null;
    private array $config;

    public function __construct(array $config, ?LalamoveClient $client = null)
    {
        $this->config = $config;
        $this->client = $client ?? new LalamoveClient($config);
    }

    // ── Fluent API ────────────────────────────────────────────────────────

    public function market(string $market): static
    {
        $clone         = clone $this;
        $clone->client = $this->client->withMarket($market);
        return $clone;
    }

    public function withQuotationId(string $id): static
    {
        $clone              = clone $this;
        $clone->quotationId = $id;
        return $clone;
    }

    public function getClient(): LalamoveClient
    {
        return $this->client;
    }

    // ── CourierDriver interface ───────────────────────────────────────────

    public function getRates(RatePayload $payload): RateCollection
    {
        $response = $this->client->createQuotation($this->buildQuotationBody($payload));
        return RateMapper::map($response, $payload->serviceCode);
    }

    public function createShipment(ShipmentPayload $payload): ShipmentResult
    {
        $quotationId = $this->quotationId;

        if ($quotationId === null) {
            $quotationResponse = $this->client->createQuotation($this->buildShipmentQuotationBody($payload));
            $quotationId       = $quotationResponse['data']['quotationId'];
        }

        $orderResponse = $this->client->createOrder([
            'quotationId' => $quotationId,
            'sender'      => [
                'name'  => $payload->sender->name,
                'phone' => $payload->sender->phone ?? '',
            ],
            'recipients'  => [[
                'name'    => $payload->recipient->name,
                'phone'   => $payload->recipient->phone ?? '',
                'remarks' => $payload->remarks ?? '',
            ]],
        ]);

        return ShipmentMapper::map($orderResponse);
    }

    public function getShipment(string $reference): ShipmentResult
    {
        throw new \Laraditz\Courier\Exceptions\UnsupportedOperationException(
            'Lalamove does not support order inquiry.'
        );
    }

    public function track(string $trackingNumber): TrackingResult
    {
        $response = $this->client->getOrder($trackingNumber);
        return TrackingMapper::map($trackingNumber, $response);
    }

    public function cancelShipment(string $waybillNumber, ?string $reference = null): CancelResult
    {
        try {
            $this->client->cancelOrder($waybillNumber);
            return CancelMapper::map(204);
        } catch (\Laraditz\Courier\Exceptions\CancellationException $e) {
            return CancelMapper::map(409, ['message' => $e->getMessage()]);
        }
    }

    public function getLabel(string $waybillNumber, ?string $reference = null): LabelResult
    {
        return LabelMapper::map();
    }

    public function getAvailability(AvailabilityPayload $payload): ServiceCollection
    {
        $response = $this->client->getCities();
        return AvailabilityMapper::map($response);
    }

    // ── HandlesWebhooks ───────────────────────────────────────────────────

    public function verifyWebhook(Request $request): bool
    {
        $secret = $this->config['webhook_secret'] ?? null;
        $token  = $request->header('X-LLM-Token');

        if ($secret === null || $token === null) {
            return false;
        }

        return hash_equals($secret, $token);
    }

    public function handleWebhook(Request $request): void
    {
        $payload   = $request->all();
        $eventType = $payload['eventType'] ?? '';

        match ($eventType) {
            'order.status.updated' => $this->dispatchOrderStatusChanged($payload),
            'driver.assigned'      => $this->dispatchDriverAssigned($payload),
            'pod.status.updated'   => $this->dispatchPodStatusChanged($payload),
            default                => null,
        };
    }

    // ── Lalamove-specific proxy methods ───────────────────────────────────

    public function removeDriver(string $orderId, string $driverId): void
    {
        $this->client->removeDriver($orderId, $driverId);
    }

    public function addPriorityFee(string $orderId, array $body): array
    {
        return $this->client->addPriorityFee($orderId, $body);
    }

    public function getDriverLocation(string $orderId, string $driverId): array
    {
        return $this->client->getDriverLocation($orderId, $driverId);
    }

    public function editStop(string $orderId, string $stopId, array $body): array
    {
        return $this->client->editStop($orderId, $stopId, $body);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function buildQuotationBody(RatePayload $payload): array
    {
        return [
            'serviceType' => $payload->serviceCode,
            'language'    => 'en_MY',
            'stops'       => [
                [
                    'coordinates' => ['lat' => (string) $payload->origin->lat, 'lng' => (string) $payload->origin->lng],
                    'address'     => $payload->origin->city . ', ' . $payload->origin->country,
                ],
                [
                    'coordinates' => ['lat' => (string) $payload->destination->lat, 'lng' => (string) $payload->destination->lng],
                    'address'     => $payload->destination->city . ', ' . $payload->destination->country,
                ],
            ],
        ];
    }

    private function buildShipmentQuotationBody(ShipmentPayload $payload): array
    {
        $body = [
            'serviceType' => $payload->serviceCode,
            'language'    => 'en_MY',
            'stops'       => [
                [
                    'coordinates' => ['lat' => (string) $payload->sender->lat, 'lng' => (string) $payload->sender->lng],
                    'address'     => implode(', ', array_filter([$payload->sender->line1, $payload->sender->city])),
                ],
                [
                    'coordinates' => ['lat' => (string) $payload->recipient->lat, 'lng' => (string) $payload->recipient->lng],
                    'address'     => implode(', ', array_filter([$payload->recipient->line1, $payload->recipient->city])),
                ],
            ],
        ];

        if ($payload->scheduledAt !== null) {
            $body['scheduleAt'] = $payload->scheduledAt->utc()->toIso8601String();
        }

        return $body;
    }

    private function dispatchOrderStatusChanged(array $payload): void
    {
        $raw    = $payload['data'] ?? [];
        $status = $raw['status'] ?? '';
        event(new OrderStatusChanged(
            orderId:      $raw['orderId']  ?? '',
            status:       $status,
            mappedStatus: TrackingMapper::mapStatus($status),
            raw:          $payload,
        ));
    }

    private function dispatchDriverAssigned(array $payload): void
    {
        $raw = $payload['data'] ?? [];
        event(new DriverAssigned(
            orderId:    $raw['orderId']  ?? '',
            driverId:   $raw['driverId'] ?? '',
            driverInfo: $raw['driver']   ?? [],
            raw:        $payload,
        ));
    }

    private function dispatchPodStatusChanged(array $payload): void
    {
        $raw = $payload['data'] ?? [];
        event(new PodStatusChanged(
            orderId:   $raw['orderId']   ?? '',
            stopId:    $raw['stopId']    ?? '',
            podStatus: $raw['podStatus'] ?? '',
            raw:       $payload,
        ));
    }
}
