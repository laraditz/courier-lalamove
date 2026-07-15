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
LALAMOVE_WEBHOOK_SECRET=your-webhook-secret
```

Then register the driver in `config/courier.php` (or however your `laraditz/courier` is configured):

```php
'drivers' => [
    'lalamove' => [
        'key'            => env('LALAMOVE_API_KEY'),
        'secret'         => env('LALAMOVE_API_SECRET'),
        'sandbox'        => env('LALAMOVE_SANDBOX', false),
        'market'         => env('LALAMOVE_MARKET', 'MY'),
        'webhook_secret' => env('LALAMOVE_WEBHOOK_SECRET'),
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

### Lalamove-specific operations

```php
// Get live driver location
$location = $lalamove->getDriverLocation($orderId, $driverId);

// Remove the assigned driver
$lalamove->removeDriver($orderId, $driverId);

// Add a priority fee
$lalamove->addPriorityFee($orderId, ['amount' => '10', 'currency' => 'MYR']);

// Edit a stop
$lalamove->editStop($orderId, $stopId, ['address' => '...', 'coordinates' => [...]]);
```

## Webhooks

The driver implements `HandlesWebhooks` and verifies incoming requests using the `X-LLM-Token` header against `LALAMOVE_WEBHOOK_SECRET`.

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
| `Laraditz\Courier\Lalamove\Events\OrderStatusChanged` | `order.status.updated` received |
| `Laraditz\Courier\Lalamove\Events\DriverAssigned` | `driver.assigned` received |
| `Laraditz\Courier\Lalamove\Events\PodStatusChanged` | `pod.status.updated` received |

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

#### `PodStatusChanged`

```php
public string $orderId;
public string $stopId;
public string $podStatus;
public array  $raw;
```

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE) for details.
