<?php

namespace Laraditz\Courier\Lalamove\Mappers;

use Laraditz\Courier\DTOs\Results\CancelResult;

final class CancelMapper
{
    public static function map(int $statusCode, ?array $errorBody = null): CancelResult
    {
        $success = $statusCode === 204;
        $message = $success
            ? 'Cancelled.'
            : ($errorBody['message'] ?? 'Cancellation not allowed.');

        return new CancelResult(
            success: $success,
            message: $message,
            meta:    ['status_code' => $statusCode],
        );
    }
}
