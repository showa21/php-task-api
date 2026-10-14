<?php
declare(strict_types=1);

namespace App\Controller;

use App\Auth\Jwt;
use App\Http\{ApiException, Request, Response};
use App\Middleware\RateLimiter;
use App\Repository\UserRepository;
use App\Validation\Validator;

final class AuthController
{
    public function __construct(
        private UserRepository $users,
        private Jwt $jwt,
        private RateLimiter $limiter,
    ) {}

    public function register(Request $r): Response
    {
        $data = Validator::validate($r->body, ['email' => 'required|string|max:254', 'password' => 'required|string|min:8|max:200']);
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(422, 'Validation failed.', ['email' => 'Must be a valid email address.']);
        }
        if ($this->users->findByEmail($data['email']) !== null) {
            throw new ApiException(409, 'That email is already registered.');
        }
        $id = $this->users->create($data['email'], $data['password']);
        return new Response(201, ['id' => $id, 'email' => $data['email'], 'token' => $this->jwt->issue($id)]);
    }

    public function login(Request $r): Response
    {
        $data = Validator::validate($r->body, ['email' => 'required|string', 'password' => 'required|string']);
        $key = strtolower($data['email']) . '|' . $r->ip;
        $this->limiter->assertAllowed($key);

        $user = $this->users->findByEmail($data['email']);
        // Same error for "no such user" and "wrong password" so attackers can't discover which emails exist.
        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            $this->limiter->recordFailure($key);
            throw new ApiException(401, 'Invalid email or password.');
        }
        $this->limiter->clear($key);
        return new Response(200, ['token' => $this->jwt->issue((int) $user['id'])]);
    }
}
