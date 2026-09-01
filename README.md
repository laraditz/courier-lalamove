# Courier Lalamove

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laraditz/courier-lalamove.svg?style=flat-square)](https://packagist.org/packages/laraditz/courier-lalamove)
[![Total Downloads](https://img.shields.io/packagist/dt/laraditz/courier-lalamove.svg?style=flat-square)](https://packagist.org/packages/laraditz/courier-lalamove)
[![License](https://img.shields.io/packagist/l/laraditz/courier-lalamove.svg?style=flat-square)](https://packagist.org/packages/laraditz/courier-lalamove)

Lalamove driver for [laraditz/courier](https://github.com/laraditz/courier). Provides rate quotes, order creation, tracking, cancellation, and real-time webhook events via the Lalamove v3 API.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- [laraditz/courier](https://github.com/laraditz/courier)

## Installation

```bash
composer require laraditz/courier-lalamove
```

The service provider is auto-discovered. Publish the config file if you need to customise defaults:

```bash
php artisan vendor:publish --tag=courier-lalamove-config
```

## Configuration

Add the following keys to your `.env` file:

```env
LALAMOVE_API_KEY=your-api-key
LALAMOVE_API_SECRET=your-api-secret
LALAMOVE_SANDBOX=false
LALAMOVE_MARKET=MY
```

Then register the driver in `config/courier.php` (or however your `laraditz/courier` is configured):

```php
'drivers' => [
    'lalamove' => [
        'key'     => env('LALAMOVE_API_KEY'),
        'secret'  => env('LALAMOVE_API_SECRET'),
        'sandbox' => env('LALAMOVE_SANDBOX', false),
        'market'  => env('LALAMOVE_MARKET', 'MY'),

        // Optional; defaults to true. See "Registering the webhook URL" below.
        'webhook_verify' => env('LALAMOVE_WEBHOOK_VERIFY', true),
    ],
],
```

## Usage

Resolve the driver through the `courier` manager:

```php
$lalamove = courier()->driver('lalamove');
```

### Get rates

```php
use Laraditz\Courier\DTOs\Payloads\RatePayload;
use Laraditz\Courier\DTOs\Address;

$payload = new RatePayload(
    serviceCode:  'MOTORCYCLE',
    origin:       new Address(lat: 3.1569, lng: 101.7123, city: 'Kuala Lumpur', country: 'MY'),
    destination:  new Address(lat: 3.0738, lng: 101.5183, city: 'Petaling Jaya', country: 'MY'),
);

$rates = $lalamove->getRates($payload);
```

### Create a shipment

```php
use Laraditz\Courier\DTOs\Payloads\ShipmentPayload;
use Laraditz\Courier\DTOs\Contact;

$payload = new ShipmentPayload(
    serviceCode: 'MOTORCYCLE',
    sender:      new Contact(name: 'Alice', phone: '+60123456789', lat: 3.1569, lng: 101.7123, line1: 'Jalan Ampang', city: 'Kuala Lumpur'),
    recipient:   new Contact(name: 'Bob',   phone: '+60198765432', lat: 3.0738, lng: 101.5183, line1: 'Jalan SS2',    city: 'Petaling Jaya'),
);

$shipment = $lalamove->createShipment($payload);
// $shipment->waybillNumber — use this as the order ID for subsequent calls
```

Pass a pre-fetched quotation ID to skip the auto-quotation step:

```php
$shipment = $lalamove->withQuotationId('QID-123')->createShipment($payload);
```

### Track an order

```php
$tracking = $lalamove->track($waybillNumber);

echo $tracking->status;      // pending | processing | in_transit | delivered | cancelled | failed
echo $tracking->meta['share_link'];
```

#### Status mapping

| Lalamove status   | Mapped status  |
|-------------------|----------------|
| ASSIGNING_DRIVER  | pending        |
| ON_GOING          | processing     |
| PICKED_UP         | in_transit     |
| COMPLETED         | delivered      |
| CANCELED          | cancelled      |
| REJECTED          | failed         |
| EXPIRED           | failed         |

### Cancel an order

```php
$result = $lalamove->cancelShipment($waybillNumber);
```

### Order inquiry

Lalamove has no order-inquiry endpoint, so `getShipment()` always throws `UnsupportedOperationException`. Use `track()` to look up an order's current status instead.

### Check service availability

```php
use Laraditz\Courier\DTOs\Payloads\AvailabilityPayload;

$services = $lalamove->getAvailability(new AvailabilityPayload());
```

### Switch market at runtime

```php
$sgDriver = $lalamove->market('SG');
```

### Delivery modes

Lalamove is on-demand only (auto-assigns a nearby driver — no scheduled next-day option):

```php
$lalamove->getDeliveryModes(); // [DeliveryMode::OnDemand]
```

### Capability interfaces

Beyond the base `CourierDriver` contract, `LalamoveDriver` implements four optional `laraditz/courier` capability interfaces, so calling code can type-check against the interface instead of reaching into `LalamoveDriver` directly:

| Interface | Method |
|---|---|
| `ManagesAssignedDriver` | `removeDriver(string $orderId, string $driverId): void` |
| `LooksUpQuotations` | `getQuotation(string $quotationId): QuotationResult` |
| `TracksDriverLocation` | `getDriverLocation(string $orderId, string $driverId): DriverLocationResult` |
| `SupportsOrderEditing` | `editOrder(string $orderId, Address[] $stops): ShipmentResult` |

```php
use Laraditz\Courier\DTOs\Shared\Address;

// Get live driver location
$location = $lalamove->getDriverLocation($orderId, $driverId); // DriverLocationResult

// Remove the assigned driver
$lalamove->removeDriver($orderId, $driverId);

// Retrieve a quotation (e.g. to inspect its stops before placing an order)
$quotation = $lalamove->getQuotation($quotationId); // QuotationResult

// Edit an order's stops — replaces the entire stops array in one call; Lalamove
// allows this once per order, only while status is ONGOING, and the pickup stop's
// values must stay identical to the original
$lalamove->editOrder($orderId, [
    new Address('Sender', '+60123456789', null, 'Line 1', null, null, 'KL', 'WP', '50000', 'MY', lat: 3.139, lng: 101.686),
    new Address('New Recipient', '+60123456780', null, 'Line 2', null, null, 'Shah Alam', 'Selangor', '40150', 'MY', lat: 3.085, lng: 101.532),
]); // ShipmentResult
```

### Other Lalamove-specific operations

```php
// Add a priority fee
$lalamove->addPriorityFee($orderId, ['amount' => '10', 'currency' => 'MYR']);

// Register/update the webhook URL Lalamove pushes events to
$lalamove->setWebhookUrl('https://your-app.test/courier/webhook/lalamove');
```

## Webhooks

The driver implements `HandlesWebhooks` and verifies incoming requests using Lalamove's HMAC-SHA256 webhook signature scheme. Each webhook body carries `timestamp`, `signature`, and `data` fields; the driver recomputes

```
HMAC-SHA256("{timestamp}\r\nPOST\r\n{webhook path}\r\n\r\n{json_encode(data)}", LALAMOVE_API_SECRET)
```

and compares it (constant-time) against the `signature` field, using the same `LALAMOVE_API_SECRET` used for signing outgoing API requests.

The driver also implements `ExtractsWebhookReference`, so if you're using `laraditz/courier`'s webhook audit logging, `courier_webhook_logs` rows are populated with `waybill_number` (Lalamove's `orderId`, taken from `data.order.orderId`) so logs stay queryable by shipment. `reference` is always `null` — Lalamove has no separate merchant reference field. `WALLET_BALANCE_CHANGED` events carry no order at all, so both fields stay `null` for that event type.

### Registering the webhook URL

The Lalamove partner portal validates a webhook URL before it will save it, by probing the URL with a request that carries no signature. Verification rejects that probe with a `401`, so registration fails. To get through it, turn verification off just long enough to register:

```env
LALAMOVE_WEBHOOK_VERIFY=false
```

Save the URL in the portal (or call `$lalamove->setWebhookUrl(...)`), then **remove the line or set it back to `true`**. It defaults to `true`, so verification is on unless you deliberately turn it off — and a config that predates this key keeps verifying.

> **While the switch is off every webhook is accepted unverified** — anyone who knows the URL can post a forged event and it will be processed and dispatched as real. Keep the window as short as you can, and prefer doing it against sandbox credentials.

Register a webhook route and delegate to the courier manager:

```php
// routes/api.php
Route::post('/webhooks/lalamove', function (Request $request) {
    courier()->driver('lalamove')->handleWebhook($request);
});
```

### Webhook events

Listen for these events in your `EventServiceProvider`:

| Event class | Fired when |
|---|---|
| `Laraditz\Courier\Lalamove\Events\OrderStatusChanged` | `ORDER_STATUS_CHANGED` received |
| `Laraditz\Courier\Lalamove\Events\DriverAssigned` | `DRIVER_ASSIGNED` received |
| `Laraditz\Courier\Lalamove\Events\OrderAmountChanged` | `ORDER_AMOUNT_CHANGED` received |
| `Laraditz\Courier\Lalamove\Events\OrderReplaced` | `ORDER_REPLACED` received (Cancel-and-Clone) |
| `Laraditz\Courier\Lalamove\Events\WalletBalanceChanged` | `WALLET_BALANCE_CHANGED` received |
| `Laraditz\Courier\Lalamove\Events\OrderEdited` | `ORDER_EDITED` received |
| `Laraditz\Courier\Lalamove\Events\PodStatusChanged` | `POD_STATUS_CHANGED` received |
| `Laraditz\Courier\Lalamove\Events\PopStatusChanged` | `POP_STATUS_CHANGED` received |
| `Laraditz\Courier\Lalamove\Events\DeliveryCodeStatusChanged` | `DELIVERY_CODE_STATUS_CHANGED` received |
| `Laraditz\Courier\Lalamove\Events\OrderCreated` | `ORDER_CREATED` received |

Every event carries a `raw` array with the full webhook payload — Lalamove warns that webhook fields are subject to change, so typed properties only expose the small set of fields documented as stable; read anything else from `raw`.

`PodStatusChanged`, `PopStatusChanged`, and `DeliveryCodeStatusChanged` carry a `stopId`. Lalamove's `stops` array has no real stop identifier, so `stopId` is the 0-based index of the stop the event applies to within `data.order.stops` — not a value Lalamove sends.

#### `OrderStatusChanged`

```php
public string $orderId;
public string $status;       // raw Lalamove status
public string $mappedStatus; // normalised status (see table above)
public array  $raw;
```

#### `DriverAssigned`

```php
public string $orderId;
public string $driverId;
public array  $driverInfo;
public array  $raw;
```

#### `OrderAmountChanged`

```php
public string $orderId;
public string $totalPrice;
public string $priorityFee;
public string $currency;
public array  $raw;
```

#### `OrderReplaced`

```php
public string $orderId;         // the new (cloned) order
public string $previousOrderId;
public array  $raw;
```

#### `WalletBalanceChanged`

```php
public string $amount;
public string $currency;
public array  $raw;
```

#### `OrderEdited`

```php
public string $orderId;
public string $editReason; // e.g. CLIENT_REQUEST, OTHERS
public string $editParty;  // e.g. USER, LALAMOVE_CUSTOMER_SUPPORT
public array  $raw;        // raw.data.previousData / raw.data.order hold the actual diff
```

#### `PodStatusChanged`

```php
public string $orderId;
public string $stopId;
public string $podStatus;
public array  $raw;
```

#### `PopStatusChanged`

```php
public string $orderId;
public string $stopId;
public array  $raw; // raw.data.order.stops[stopId].POP holds imageUrls / pickedUpAt
```

#### `DeliveryCodeStatusChanged`

```php
public string $orderId;
public string $stopId;
public string $deliveryCodeStatus; // e.g. Pending, Verified, Not Applicable
public string $deliveryCodeValue;
public array  $raw;
```

#### `OrderCreated`

```php
public string $orderId;
public string $market;
public array  $raw; // full order snapshot
```

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE) for details.
