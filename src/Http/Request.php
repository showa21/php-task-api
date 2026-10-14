<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string,mixed> Filled by the router with {placeholders}; AuthMiddleware adds user_id. */
    public array $attributes = [];

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-cased names
     * @param array<string,mixed>  $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly array $body = [],
        public readonly string $ip = '127.0.0.1',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach (getallheaders() ?: [] as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new ApiException(400, 'Request body must be valid JSON.');
            }
            $body = $decoded;
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/',
            $_GET,
            $headers,
            $body,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        );
    }

    public function bearerToken(): ?string
    {
        $h = $this->headers['authorization'] ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
    }
}
