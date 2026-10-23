<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderTimeoutException extends RuntimeException
{
    public function __construct(
        string $message = 'Provider connection timed out.',
        int $code = 504,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
