<?php

namespace Laraditz\Courier\Lalamove\Tests\Logging;

use Laraditz\Courier\Lalamove\Tests\TestCase;
use Laraditz\Courier\Logging\ApiLogWriter;
use Laraditz\Courier\Models\CourierApiLog;

class ApiLogTest extends TestCase
{
    // Guards the harness itself, not the driver. ApiLogWriter::record() wraps its
    // insert in catch (Throwable), so without core's migrations loaded every log
    // write fails into a Log::error nobody reads while the suite stays green.
    // If this test cannot see a row, no later logging assertion means anything.
    public function test_harness_observes_a_written_api_log_row(): void
    {
        (new ApiLogWriter())->record([
            'driver'      => 'lalamove',
            'action'      => 'harness_check',
            'method'      => 'GET',
            'url'         => 'https://rest.sandbox.lalamove.com/v3/cities',
            'duration_ms' => 1,
            'successful'  => true,
        ]);

        $this->assertSame(1, CourierApiLog::count());
    }
}
