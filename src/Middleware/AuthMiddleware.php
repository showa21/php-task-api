<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Jwt;
use App\Http\{ApiException, Request, Response};
use Closure;

final class AuthMiddleware
{
    public function __construct(private Jwt $jwt) {}

    public function __invoke(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $userId = $token !== null ? $this->jwt->verify($token) : null;
        if ($userId === null) {
            throw new ApiException(401, 'Missing, invalid or expired token.');
        }
        $request->attributes['user_id'] = $userId;
        return $next($request);
    }
}
