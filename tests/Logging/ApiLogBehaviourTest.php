<?php

namespace Laraditz\Courier\Lalamove\Tests\Logging;

use Illuminate\Support\Facades\Http;
use Laraditz\Courier\Exceptions\CourierException;
use Laraditz\Courier\Lalamove\Http\LalamoveClient;
use Laraditz\Courier\Lalamove\Tests\TestCase;
use Laraditz\Courier\Models\CourierApiLog;

class ApiLogBehaviourTest extends TestCase
{
    private function client(): LalamoveClient
    {
        return new LalamoveClient([
            'key'     => 'pk_test_key',
            'secret'  => 'sk_test_secret',
            'sandbox' => true,
            'market'  => 'MY',
        ]);
    }

    // The log write has to happen before handleResponse() throws, or every failed
    // call — the ones worth auditing most — would be missing from the table.
    public function test_failed_call_is_logged_and_still_throws(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad Request'], 422)]);

        try {
            $this->client()->getCities();
            $this->fail('Expected CourierException was not thrown.');
        } catch (CourierException) {
            // expected
        }

        $log = CourierApiLog::sole();

        $this->assertSame('get_cities', $log->action);
        $this->assertSame(422, $log->status_code);
        $this->assertFalse($log->successful);
    }

    public function test_no_row_is_written_when_logging_is_disabled(): void
    {
        config(['courier.logging.enabled' => false]);
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->client()->getCities();

        $this->assertSame(0, CourierApiLog::count());
        Http::assertSentCount(1);   // the call itself must still happen
    }

    public function test_one_request_writes_exactly_one_row(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->client()->getCities();

        $log = CourierApiLog::sole();

        $this->assertSame('GET', $log->method);
        $this->assertSame('https://rest.sandbox.lalamove.com/v3/cities', $log->url);
        $this->assertIsInt($log->duration_ms);
    }

    // forLog() mutates the instance and leaves configured = true, so a request that
    // forgets to call it passes the guard and logs against the PREVIOUS call's
    // context. A single-call test cannot catch that; this needs two.
    public function test_context_is_not_inherited_between_requests(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $client = $this->client();
        $client->getOrder('ORD-001');
        $client->getCities();

        $logs = CourierApiLog::orderBy('id')->get();

        $this->assertSame(['get_order', 'get_cities'], $logs->pluck('action')->all());
        $this->assertSame('ORD-001', $logs->first()->waybill_number);
        $this->assertNull($logs->last()->waybill_number, 'get_cities inherited the previous call waybill_number.');
    }
}
