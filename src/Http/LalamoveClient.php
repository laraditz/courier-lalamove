<?php

namespace Laraditz\Courier\Lalamove\Http;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laraditz\Courier\Exceptions\AuthenticationException;
use Laraditz\Courier\Exceptions\CancellationException;
use Laraditz\Courier\Exceptions\CourierException;
use Laraditz\Courier\Http\CourierHttpClient;

class LalamoveClient
{
    private CourierHttpClient $http;

    // $config is NOT readonly — withMarket() needs to mutate the clone.
    // CourierHttpClient is not container-bound, so constructing one here is correct;
    // the parameter exists so tests can inject their own.
    public function __construct(private array $config, ?CourierHttpClient $http = null)
    {
        $this->http = $http ?? new CourierHttpClient();
    }

    public function withMarket(string $market): static
    {
        $clone = clone $this;
        $clone->config['market'] = $market;
        return $clone;
    }

    // ── Named API methods ────────────────────────────────────────────────

    public function createQuotation(array $body, ?string $reference = null): array
    {
        return $this->post('/v3/quotations', $body, 'create_quotation', reference: $reference);
    }

    public function getQuotation(string $quotationId): array
    {
        return $this->get("/v3/quotations/{$quotationId}", 'get_quotation');
    }

    public function createOrder(array $body, ?string $reference = null): array
    {
        return $this->post('/v3/orders', $body, 'create_order', reference: $reference);
    }

    public function getOrder(string $orderId): array
    {
        return $this->get("/v3/orders/{$orderId}", 'get_order', $orderId);
    }

    public function cancelOrder(string $orderId): void
    {
        $this->delete("/v3/orders/{$orderId}");
    }

    public function getCities(): array
    {
        return $this->get('/v3/cities', 'get_cities');
    }

    public function removeDriver(string $orderId, string $driverId): void
    {
        $this->delete("/v3/orders/{$orderId}/drivers/{$driverId}");
    }

    public function addPriorityFee(string $orderId, array $body): array
    {
        return $this->post("/v3/orders/{$orderId}/priority-fee", $body, 'add_priority_fee', $orderId);
    }

    public function getDriverLocation(string $orderId, string $driverId): array
    {
        return $this->get("/v3/orders/{$orderId}/drivers/{$driverId}", 'get_driver_location', $orderId);
    }

    // Lalamove has no per-stop endpoint: editing replaces the entire stops array in
    // one PATCH, is allowed once per order, only while status is ONGOING, and the
    // pickup stop's values must stay identical to the original.
    public function editOrder(string $orderId, array $stops): array
    {
        return $this->patch("/v3/orders/{$orderId}", ['stops' => $stops], 'edit_order', $orderId);
    }

    public function setWebhookUrl(string $url): array
    {
        return $this->patch('/v3/webhook', ['url' => $url], 'set_webhook_url');
    }

    // ── Transport ────────────────────────────────────────────────────────

    // $json is the signature input only — CourierHttpClient takes the array and lets
    // Guzzle encode it. Guzzle uses json_encode($value, 0, 512); JSON_THROW_ON_ERROR
    // changes error handling, not output, so the two are byte-identical. HmacSignatureTest
    // pins that: if it ever stops holding, every call 401s for a non-obvious reason.
    private function post(string $path, array $body, string $action, ?string $waybillNumber = null, ?string $reference = null): array
    {
        $payload = ['data' => $body];
        $json    = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->http
            ->forLog('lalamove', $action, $reference, $waybillNumber)
            ->post($this->baseUrl() . $path, $payload, $this->headers('POST', $path, $json));

        return $this->handleResponse($response);
    }

    private function patch(string $path, array $body, string $action, ?string $waybillNumber = null, ?string $reference = null): array
    {
        $payload = ['data' => $body];
        $json    = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->http
            ->forLog('lalamove', $action, $reference, $waybillNumber)
            ->patch($this->baseUrl() . $path, $payload, $this->headers('PATCH', $path, $json));

        return $this->handleResponse($response);
    }

    // forLog() is called on every request, immediately before the verb. It mutates
    // and returns $this, and leaves configured = true, so an inherited context would
    // log silently against the previous call's action. Never rely on it persisting.
    private function get(string $path, string $action, ?string $waybillNumber = null, ?string $reference = null): array
    {
        $response = $this->http
            ->forLog('lalamove', $action, $reference, $waybillNumber)
            ->get($this->baseUrl() . $path, [], $this->headers('GET', $path, ''));

        return $this->handleResponse($response);
    }

    private function delete(string $path): void
    {
        $response = Http::withHeaders($this->headers('DELETE', $path, ''))
            ->delete($this->baseUrl() . $path);

        $this->handleResponse($response, expectBody: false);
    }

    private function handleResponse(\Illuminate\Http\Client\Response $response, bool $expectBody = true): array
    {
        if ($response->status() === 401) {
            throw new AuthenticationException('Lalamove authentication failed: ' . $response->body());
        }

        if ($response->status() === 402) {
            throw new CourierException('Insufficient Lalamove credit.');
        }

        if ($response->status() === 409) {
            throw new CancellationException('Lalamove cancellation not allowed: ' . $response->body());
        }

        if ($response->failed()) {
            throw new CourierException("Lalamove API error ({$response->status()}): {$response->body()}");
        }

        return $expectBody ? ($response->json() ?? []) : [];
    }

    private function headers(string $method, string $path, string $body): array
    {
        $timestamp = (string) (int) (microtime(true) * 1000);
        $signature = hash_hmac(
            'sha256',
            "{$timestamp}\r\n{$method}\r\n{$path}\r\n\r\n{$body}",
            $this->config['secret']
        );

        return [
            'Authorization' => "hmac {$this->config['key']}:{$timestamp}:{$signature}",
            'Market'        => $this->config['market'],
            'Request-ID'    => Str::uuid()->toString(),
            'Content-Type'  => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        return ($this->config['sandbox'] ?? false)
            ? 'https://rest.sandbox.lalamove.com'
            : 'https://rest.lalamove.com';
    }
}
