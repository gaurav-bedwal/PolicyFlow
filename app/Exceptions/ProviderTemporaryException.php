<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderTemporaryException extends RuntimeException
{
    public function __construct(
        string $message = 'Provider temporarily unavailable.',
        int $code = 503,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
