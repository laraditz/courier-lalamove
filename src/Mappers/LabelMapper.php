<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Laraditz\Courier\Exceptions\UnsupportedOperationException;

final class LabelMapper
{
    public static function map(): never
    {
        throw new UnsupportedOperationException('Lalamove does not support shipping labels.');
    }
}
