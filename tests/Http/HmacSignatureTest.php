<?php

namespace Laraditz\Courier\Lalamove\Tests\Http;

use Illuminate\Support\Facades\Http;
use Laraditz\Courier\Lalamove\Http\LalamoveClient;
use Laraditz\Courier\Lalamove\Tests\TestCase;

// Characterization test. Lalamove signs the exact request body, so the signature
// only verifies if what headers() hashed is byte-for-byte what went on the wire.
// Routing transport through CourierHttpClient hands Guzzle an array to encode
// instead of a pre-encoded string; Guzzle uses json_encode($value, 0, 512) while
// headers() uses JSON_THROW_ON_ERROR, which changes error handling but not output.
// This test is the only thing standing between that assumption and a 401 on every
// call with a cause nobody would find.
class HmacSignatureTest extends TestCase
{
    private const SECRET = 'sk_test_secret';

    private function makeClient(): LalamoveClient
    {
        return new LalamoveClient([
            'key'     => 'pk_test_key',
            'secret'  => self::SECRET,
            'sandbox' => true,
            'market'  => 'MY',
        ]);
    }

    /** Recompute the HMAC from the body actually sent and compare to the header. */
    private function assertSignatureMatchesSentBody(string $method, string $path): void
    {
        Http::assertSent(function ($request) use ($method, $path) {
            $auth = $request->header('Authorization')[0] ?? '';

            $this->assertMatchesRegularExpression('/^hmac [^:]+:\d+:[a-f0-9]{64}$/', $auth);

            [, $timestamp, $signature] = explode(':', substr($auth, strlen('hmac ')));

            $expected = hash_hmac(
                'sha256',
                "{$timestamp}\r\n{$method}\r\n{$path}\r\n\r\n{$request->body()}",
                self::SECRET
            );

            $this->assertSame(
                $expected,
                $signature,
                'HMAC does not match the body actually sent — the signed string and the wire body have diverged.'
            );

            return true;
        });
    }

    public function test_post_signature_matches_the_body_actually_sent(): void
    {
        Http::fake(['*' => Http::response(['data' => ['quotationId' => 'Q1']], 200)]);

        $this->makeClient()->createQuotation([
            'serviceType' => 'MOTORCYCLE',
            'stops'       => [['coordinates' => ['lat' => '3.1', 'lng' => '101.6']]],
        ]);

        $this->assertSignatureMatchesSentBody('POST', '/v3/quotations');
    }

    public function test_patch_signature_matches_the_body_actually_sent(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->makeClient()->setWebhookUrl('https://example.test/courier/webhook/lalamove');

        $this->assertSignatureMatchesSentBody('PATCH', '/v3/webhook');
    }

    public function test_get_signature_matches_the_empty_body_actually_sent(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->makeClient()->getOrder('ORD-001');

        $this->assertSignatureMatchesSentBody('GET', '/v3/orders/ORD-001');
    }

    // The signed path must be the requested path. CourierHttpClient::get() always
    // passes a query option where the current code passes none; an empty query that
    // appended "?" would leave the signature valid but the request subtly different.
    public function test_get_request_url_carries_no_trailing_question_mark(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->makeClient()->getOrder('ORD-001');

        Http::assertSent(fn ($r) => $r->url() === 'https://rest.sandbox.lalamove.com/v3/orders/ORD-001');
    }
}
