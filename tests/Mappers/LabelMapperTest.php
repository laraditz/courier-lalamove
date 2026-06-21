<?php

namespace Laraditz\Courier\Lalamove\Tests\Mappers;

use Laraditz\Courier\Exceptions\UnsupportedOperationException;
use Laraditz\Courier\Lalamove\Mappers\LabelMapper;
use Laraditz\Courier\Lalamove\Tests\TestCase;

class LabelMapperTest extends TestCase
{
    public function test_always_throws_unsupported_operation_exception(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('Lalamove does not support shipping labels.');
        LabelMapper::map();
    }
}
