<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\DTOs\Results\CancelResult;
use Laraditz\Courier\Lalamove\Mappers\CancelMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class CancelMapperTest extends TestCase
{
    public function test_204_maps_to_success(): void
    {
        $result = CancelMapper::map(204);
        $this->assertInstanceOf(CancelResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('Cancelled.', $result->message);
    }

    public function test_409_maps_to_failure(): void
    {
        $result = CancelMapper::map(409, ['message' => 'ERR_CANCELLATION_FORBIDDEN']);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('ERR_CANCELLATION_FORBIDDEN', $result->message);
    }

    public function test_meta_contains_status_code(): void
    {
        $result = CancelMapper::map(204);
        $this->assertSame(204, $result->meta()['status_code']);
    }
}
