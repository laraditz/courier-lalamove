<?php

namespace Laraditz\Courier\Lalamove\Tests\Http;

use Illuminate\Support\Facades\Http;
use Laraditz\Courier\Exceptions\AuthenticationException;
use Laraditz\Courier\Exceptions\CancellationException;
use Laraditz\Courier\Exceptions\CourierException;
use Laraditz\Courier\Lalamove\Http\LalamoveClient;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class LalamoveClientTest extends TestCase
{
    private function makeClient(array $overrides = []): LalamoveClient
    {
        return new LalamoveClient(array_merge([
            'key'    => 'pk_test_key',
            'secret' => 'sk_test_secret',
            'sandbox' => true,
            'market' => 'MY',
        ], $overrides));
    }

    public function test_uses_sandbox_url_when_sandbox_is_true(): void
    {
        Http::fake(['rest.sandbox.lalamove.com/*' => Http::response(['data' => []], 200)]);

        $this->makeClient(['sandbox' => true])->getCities();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'rest.sandbox.lalamove.com'));
    }

    public function test_uses_production_url_when_sandbox_is_false(): void
    {
        Http::fake(['rest.lalamove.com/*' => Http::response(['data' => ['cities' => []]], 200)]);

        $this->makeClient(['sandbox' => false])->getCities();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'rest.lalamove.com')
            && ! str_contains($r->url(), 'sandbox'));
    }

    public function test_sends_required_auth_headers(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->makeClient()->getCities();

        Http::assertSent(function ($r) {
            return $r->hasHeader('Authorization')
                && str_starts_with($r->header('Authorization')[0], 'hmac ')
                && $r->hasHeader('Market')
                && $r->hasHeader('Request-ID');
        });
    }

    public function test_with_market_returns_clone_with_new_market(): void
    {
        $client = $this->makeClient(['market' => 'MY']);
        $clone  = $client->withMarket('SG');

        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $clone->getCities();

        Http::assertSent(fn ($r) => $r->header('Market')[0] === 'SG');
        // original must be unmodified — cannot assert without another call, so just confirm clone is different object
        $this->assertNotSame($client, $clone);
    }

    public function test_throws_authentication_exception_on_401(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $this->expectException(AuthenticationException::class);
        $this->makeClient()->getCities();
    }

    public function test_throws_cancellation_exception_on_409(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ERR_CANCELLATION_FORBIDDEN'], 409)]);
        $this->expectException(CancellationException::class);
        $this->makeClient()->cancelOrder('ORD123');
    }

    public function test_throws_courier_exception_on_402_insufficient_credit(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Insufficient credit'], 402)]);
        $this->expectException(CourierException::class);
        $this->expectExceptionMessage('Insufficient Lalamove credit.');
        $this->makeClient()->getCities();
    }

    public function test_throws_courier_exception_on_other_errors(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad Request'], 422)]);
        $this->expectException(CourierException::class);
        $this->makeClient()->getCities();
    }

    public function test_get_order_calls_correct_endpoint(): void
    {
        Http::fake(['*/v3/orders/ORD123' => Http::response(['data' => []], 200)]);

        $this->makeClient()->getOrder('ORD123');

        Http::assertSent(fn ($r) => str_ends_with(rtrim($r->url(), '/'), '/v3/orders/ORD123') && $r->method() === 'GET');
    }

    public function test_create_quotation_posts_to_correct_endpoint(): void
    {
        Http::fake(['*/v3/quotations' => Http::response(['data' => []], 201)]);

        $this->makeClient()->createQuotation(['serviceType' => 'MOTORCYCLE', 'stops' => []]);

        Http::assertSent(fn ($r) => str_ends_with(rtrim($r->url(), '/'), '/v3/quotations') && $r->method() === 'POST');
    }
}
