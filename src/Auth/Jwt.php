<?php
declare(strict_types=1);

namespace App\Auth;

/** Hand-written HS256 JSON Web Tokens, to show exactly how they work. */
final class Jwt
{
    public function __construct(private string $secret, private int $ttl = 3600) {}

    public function issue(int $userId, ?int $now = null): string
    {
        $now ??= time();
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode(['sub' => $userId, 'iat' => $now, 'exp' => $now + $this->ttl]));
        return "$header.$payload." . $this->b64($this->sign("$header.$payload"));
    }

    /** @return int|null the user id, or null if the token is invalid or expired */
    public function verify(string $token, ?int $now = null): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode($this->unb64($h), true);
        // Pin the algorithm: never trust the token to choose how it is verified.
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        if (!hash_equals($this->sign("$h.$p"), $this->unb64($s))) {
            return null;
        }
        $payload = json_decode($this->unb64($p), true);
        if (!is_array($payload) || !isset($payload['sub'], $payload['exp'])) {
            return null;
        }
        if (($now ?? time()) >= $payload['exp']) {
            return null;
        }
        return (int) $payload['sub'];
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), false);
    }
}
