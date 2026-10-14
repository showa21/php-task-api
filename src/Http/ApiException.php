<?php
declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Thrown anywhere in the app to produce a clean JSON error response. */
final class ApiException extends RuntimeException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
