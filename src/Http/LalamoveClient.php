<?php

namespace Laraditz\Courier\Lalamove\Http;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laraditz\Courier\Exceptions\AuthenticationException;
use Laraditz\Courier\Exceptions\CancellationException;
use Laraditz\Courier\Exceptions\CourierException;

class LalamoveClient
{
    public function __construct(private array $config) {}  // NOT readonly — withMarket() needs to mutate the clone

    public function withMarket(string $market): static
    {
        $clone = clone $this;
        $clone->config['market'] = $market;
        return $clone;
    }

    // ── Named API methods ────────────────────────────────────────────────

    public function createQuotation(array $body): array
    {
        return $this->post('/v3/quotations', $body);
    }

    public function getQuotation(string $quotationId): array
    {
        return $this->get("/v3/quotations/{$quotationId}");
    }

    public function createOrder(array $body): array
    {
        return $this->post('/v3/orders', $body);
    }

    public function getOrder(string $orderId): array
    {
        return $this->get("/v3/orders/{$orderId}");
    }

    public function cancelOrder(string $orderId): void
    {
        $this->delete("/v3/orders/{$orderId}");
    }

    public function getCities(): array
    {
        return $this->get('/v3/cities');
    }

    public function removeDriver(string $orderId, string $driverId): void
    {
        $this->delete("/v3/orders/{$orderId}/drivers/{$driverId}");
    }

    public function addPriorityFee(string $orderId, array $body): array
    {
        return $this->post("/v3/orders/{$orderId}/priority-fee", $body);
    }

    public function getDriverLocation(string $orderId, string $driverId): array
    {
        return $this->get("/v3/orders/{$orderId}/drivers/{$driverId}");
    }

    // Lalamove has no per-stop endpoint: editing replaces the entire stops array in
    // one PATCH, is allowed once per order, only while status is ONGOING, and the
    // pickup stop's values must stay identical to the original.
    public function editOrder(string $orderId, array $stops): array
    {
        return $this->patch("/v3/orders/{$orderId}", ['stops' => $stops]);
    }

    public function setWebhookUrl(string $url): array
    {
        return $this->patch('/v3/webhook', ['url' => $url]);
    }

    // ── Transport ────────────────────────────────────────────────────────

    private function post(string $path, array $body): array
    {
        $json      = json_encode(['data' => $body], JSON_THROW_ON_ERROR);
        $response  = Http::withHeaders($this->headers('POST', $path, $json))
            ->withBody($json, 'application/json')
            ->post($this->baseUrl() . $path);

        return $this->handleResponse($response);
    }

    private function patch(string $path, array $body): array
    {
        $json     = json_encode(['data' => $body], JSON_THROW_ON_ERROR);
        $response = Http::withHeaders($this->headers('PATCH', $path, $json))
            ->withBody($json, 'application/json')
            ->patch($this->baseUrl() . $path);

        return $this->handleResponse($response);
    }

    private function get(string $path): array
    {
        $response = Http::withHeaders($this->headers('GET', $path, ''))
            ->get($this->baseUrl() . $path);

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
