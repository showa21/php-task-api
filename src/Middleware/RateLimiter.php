<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\ApiException;
use PDO;

/** Counts failed attempts per key in the database (survives restarts, works across processes). */
final class RateLimiter
{
    public function __construct(private PDO $pdo, private int $maxAttempts = 5, private int $windowSeconds = 60) {}

    public function assertAllowed(string $key, ?int $now = null): void
    {
        $now ??= time();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE key = ? AND at > ?');
        $stmt->execute([$key, $now - $this->windowSeconds]);
        if ((int) $stmt->fetchColumn() >= $this->maxAttempts) {
            throw new ApiException(429, 'Too many failed attempts. Try again in a minute.');
        }
    }

    public function recordFailure(string $key, ?int $now = null): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (key, at) VALUES (?, ?)')->execute([$key, $now ?? time()]);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([($now ?? time()) - $this->windowSeconds * 10]);
    }

    public function clear(string $key): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE key = ?')->execute([$key]);
    }
}
