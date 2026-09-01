<?php

namespace Laraditz\Courier\Lalamove;

use Illuminate\Http\Request;
use Laraditz\Courier\Contracts\CourierDriver;
use Laraditz\Courier\Contracts\ExtractsWebhookReference;
use Laraditz\Courier\Contracts\HandlesWebhooks;
use Laraditz\Courier\Contracts\LooksUpQuotations;
use Laraditz\Courier\Contracts\ManagesAssignedDriver;
use Laraditz\Courier\Contracts\SupportsOrderEditing;
use Laraditz\Courier\Contracts\TracksDriverLocation;
use Laraditz\Courier\DTOs\Payloads\AvailabilityPayload;
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;
use Laraditz\Courier\DTOs\Results\CancelResult;
use Laraditz\Courier\DTOs\Results\DriverLocationResult;
use Laraditz\Courier\DTOs\Results\LabelResult;
use Laraditz\Courier\DTOs\Results\QuotationResult;
use Laraditz\Courier\DTOs\Results\RateCollection;
use Laraditz\Courier\DTOs\Results\ServiceCollection;
use Laraditz\Courier\DTOs\Results\ShipmentResult;
use Laraditz\Courier\DTOs\Results\TrackingResult;
use Laraditz\Courier\DTOs\Shared\Address;
use Laraditz\Courier\Enums\DeliveryMode;
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
use Laraditz\Courier\Lalamove\Http\LalamoveClient;
use Laraditz\Courier\Lalamove\Mappers\AvailabilityMapper;
use Laraditz\Courier\Lalamove\Mappers\CancelMapper;
use Laraditz\Courier\Lalamove\Mappers\DriverLocationMapper;
use Laraditz\Courier\Lalamove\Mappers\LabelMapper;
use Laraditz\Courier\Lalamove\Mappers\QuotationMapper;
use Laraditz\Courier\Lalamove\Mappers\RateMapper;
use Laraditz\Courier\Lalamove\Mappers\ShipmentMapper;
use Laraditz\Courier\Lalamove\Mappers\TrackingMapper;

class LalamoveDriver implements
    CourierDriver,
    HandlesWebhooks,
    ExtractsWebhookReference,
    ManagesAssignedDriver,
    LooksUpQuotations,
    TracksDriverLocation,
    SupportsOrderEditing
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
        } else {
            // A reused quotationId (via withQuotationId()) was never fetched by this
            // instance, so its stops/stopId values — required below — are unknown yet.
            $quotationResponse = $this->client->getQuotation($quotationId);
        }

        // Lalamove requires sender/recipients to reference the quotation's own stopId
        // per stop; buildShipmentQuotationBody() always orders stops [sender, recipient].
        $stops = $quotationResponse['data']['stops'] ?? [];

        $orderResponse = $this->client->createOrder([
            'quotationId' => $quotationId,
            'sender'      => [
                'stopId' => $stops[0]['stopId'] ?? '',
                'name'   => $payload->sender->name,
                'phone'  => $payload->sender->phone ?? '',
            ],
            'recipients'  => [[
                'stopId'  => $stops[1]['stopId'] ?? '',
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

    public function getDeliveryModes(): array
    {
        return [DeliveryMode::OnDemand];
    }

    // ── HandlesWebhooks ───────────────────────────────────────────────────

    public function verifyWebhook(Request $request): bool
    {
        // Registration mode: the Lalamove partner portal probes a new webhook URL
        // unsigned and refuses to save it unless the URL answers a plain 200. Flip
        // LALAMOVE_WEBHOOK_VERIFY off just long enough to register, then back on —
        // while it is off, every webhook is accepted without a signature check.
        if (!($this->config['webhook_verify'] ?? true)) {
            return true;
        }

        $secret = $this->config['secret'] ?? null;

        if ($secret === null) {
            return false;
        }

        // Read the untouched raw body: $request->all() passes through Laravel's
        // input-sanitizing middleware (e.g. ConvertEmptyStringsToNull), which rewrites
        // "" to null and would silently break byte-for-byte signature reconstruction.
        $payload   = json_decode($request->getContent(), true) ?? [];
        $timestamp = $payload['timestamp'] ?? null;
        $signature = $payload['signature'] ?? null;
        $data      = $payload['data']      ?? null;

        if ($timestamp === null || $signature === null || $data === null) {
            return false;
        }

        $body         = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rawSignature = "{$timestamp}\r\nPOST\r\n{$request->getPathInfo()}\r\n\r\n{$body}";
        $expected     = hash_hmac('sha256', $rawSignature, $secret);

        return hash_equals($expected, (string) $signature);
    }

    public function handleWebhook(Request $request): void
    {
        $payload   = $request->all();
        $eventType = $payload['eventType'] ?? '';

        match ($eventType) {
            'ORDER_STATUS_CHANGED'         => $this->dispatchOrderStatusChanged($payload),
            'DRIVER_ASSIGNED'              => $this->dispatchDriverAssigned($payload),
            'ORDER_AMOUNT_CHANGED'         => $this->dispatchOrderAmountChanged($payload),
            'ORDER_REPLACED'               => $this->dispatchOrderReplaced($payload),
            'WALLET_BALANCE_CHANGED'       => $this->dispatchWalletBalanceChanged($payload),
            'ORDER_EDITED'                 => $this->dispatchOrderEdited($payload),
            'POD_STATUS_CHANGED'           => $this->dispatchPodStatusChanged($payload),
            'POP_STATUS_CHANGED'           => $this->dispatchPopStatusChanged($payload),
            'DELIVERY_CODE_STATUS_CHANGED' => $this->dispatchDeliveryCodeStatusChanged($payload),
            'ORDER_CREATED'                => $this->dispatchOrderCreated($payload),
            default                        => null,
        };
    }

    // ── ExtractsWebhookReference ────────────────────────────────────────────

    public function extractWebhookReference(Request $request): array
    {
        // orderId doubles as the waybill number here (see ShipmentMapper); Lalamove has no separate merchant reference field.
        return [
            'reference'     => null,
            'waybillNumber' => $request->input('data.order.orderId'),
        ];
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

    public function getDriverLocation(string $orderId, string $driverId): DriverLocationResult
    {
        return DriverLocationMapper::map($this->client->getDriverLocation($orderId, $driverId));
    }

    public function getQuotation(string $quotationId): QuotationResult
    {
        return QuotationMapper::map($this->client->getQuotation($quotationId));
    }

    // Replaces every stop in one call (no per-stop endpoint exists); once per order, only while ONGOING, pickup values must stay identical.
    /** @param Address[] $stops */
    public function editOrder(string $orderId, array $stops): ShipmentResult
    {
        $rawStops = array_map($this->addressToStop(...), $stops);
        return ShipmentMapper::map($this->client->editOrder($orderId, $rawStops));
    }

    public function setWebhookUrl(string $url): array
    {
        return $this->client->setWebhookUrl($url);
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
            'stops'       => array_map($this->addressToStop(...), [$payload->sender, $payload->recipient]),
        ];

        if ($payload->scheduledAt !== null) {
            $body['scheduleAt'] = $payload->scheduledAt->utc()->toIso8601String();
        }

        return $body;
    }

    private function addressToStop(Address $address): array
    {
        return [
            'coordinates' => ['lat' => (string) $address->lat, 'lng' => (string) $address->lng],
            'address'     => implode(', ', array_filter([$address->line1, $address->city])),
        ];
    }

    // Lalamove nests order/driver/wallet data under data.order / data.driver / data.balance;
    // stops carry no stable id, so POD/POP/deliveryCode events resolve the array index instead.

    private function dispatchOrderStatusChanged(array $payload): void
    {
        $order  = $payload['data']['order'] ?? [];
        $status = $order['status'] ?? '';
        event(new OrderStatusChanged(
            orderId:      $order['orderId'] ?? '',
            status:       $status,
            mappedStatus: TrackingMapper::mapStatus($status),
            raw:          $payload,
        ));
    }

    private function dispatchDriverAssigned(array $payload): void
    {
        $data = $payload['data'] ?? [];
        event(new DriverAssigned(
            orderId:    $data['order']['orderId']    ?? '',
            driverId:   $data['driver']['driverId']  ?? '',
            driverInfo: $data['driver']               ?? [],
            raw:        $payload,
        ));
    }

    private function dispatchOrderAmountChanged(array $payload): void
    {
        $order = $payload['data']['order'] ?? [];
        $price = $order['price'] ?? [];
        event(new OrderAmountChanged(
            orderId:     $order['orderId']     ?? '',
            totalPrice:  $price['totalPrice']  ?? '',
            priorityFee: $price['priorityFee'] ?? '',
            currency:    $price['currency']    ?? '',
            raw:         $payload,
        ));
    }

    private function dispatchOrderReplaced(array $payload): void
    {
        $data = $payload['data'] ?? [];
        event(new OrderReplaced(
            orderId:         $data['order']['orderId'] ?? '',
            previousOrderId: $data['prevOrderId']       ?? '',
            raw:             $payload,
        ));
    }

    private function dispatchWalletBalanceChanged(array $payload): void
    {
        $balance = $payload['data']['balance'] ?? [];
        event(new WalletBalanceChanged(
            amount:   $balance['amount']   ?? '',
            currency: $balance['currency'] ?? '',
            raw:      $payload,
        ));
    }

    private function dispatchOrderEdited(array $payload): void
    {
        $data = $payload['data'] ?? [];
        event(new OrderEdited(
            orderId:    $data['order']['orderId'] ?? '',
            editReason: $data['editReason']       ?? '',
            editParty:  $data['editParty']        ?? '',
            raw:        $payload,
        ));
    }

    private function dispatchPodStatusChanged(array $payload): void
    {
        $order = $payload['data']['order'] ?? [];
        $stops = $order['stops'] ?? [];
        $index = $this->findStopIndex($stops, fn (array $stop) => isset($stop['POD']));

        event(new PodStatusChanged(
            orderId:   $order['orderId'] ?? '',
            stopId:    $index !== null ? (string) $index : '',
            podStatus: $index !== null ? ($stops[$index]['POD']['status'] ?? '') : '',
            raw:       $payload,
        ));
    }

    private function dispatchPopStatusChanged(array $payload): void
    {
        $order = $payload['data']['order'] ?? [];
        $stops = $order['stops'] ?? [];
        $index = $this->findStopIndex($stops, fn (array $stop) => isset($stop['POP']));

        event(new PopStatusChanged(
            orderId: $order['orderId'] ?? '',
            stopId:  $index !== null ? (string) $index : '',
            raw:     $payload,
        ));
    }

    private function dispatchDeliveryCodeStatusChanged(array $payload): void
    {
        $order = $payload['data']['order'] ?? [];
        $stops = $order['stops'] ?? [];
        $index = $this->findStopIndex(
            $stops,
            fn (array $stop) => ($stop['deliveryCode']['status'] ?? 'Not Applicable') !== 'Not Applicable'
        );
        $deliveryCode = $index !== null ? ($stops[$index]['deliveryCode'] ?? []) : [];

        event(new DeliveryCodeStatusChanged(
            orderId:            $order['orderId']       ?? '',
            stopId:             $index !== null ? (string) $index : '',
            deliveryCodeStatus: $deliveryCode['status']  ?? '',
            deliveryCodeValue:  $deliveryCode['value']   ?? '',
            raw:                $payload,
        ));
    }

    private function dispatchOrderCreated(array $payload): void
    {
        $order = $payload['data']['order'] ?? [];
        event(new OrderCreated(
            orderId: $order['orderId'] ?? '',
            market:  $order['market']  ?? '',
            raw:     $payload,
        ));
    }

    /** @param array<int, array<string, mixed>> $stops */
    private function findStopIndex(array $stops, callable $predicate): ?int
    {
        foreach ($stops as $index => $stop) {
            if ($predicate($stop)) {
                return $index;
            }
        }

        return null;
    }
}
