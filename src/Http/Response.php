<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    public function __construct(
        public readonly int $status = 200,
        public readonly mixed $data = null,
    ) {}

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        if ($this->status !== 204) {
            echo json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}
