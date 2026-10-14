<?php
declare(strict_types=1);

namespace App\Http;

use Closure;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:Closure,middleware:list<Closure>}> */
    private array $routes = [];

    /**
     * Register a route. "/tasks/{id}" becomes a regex with a named capture group.
     * Middleware are closures: fn(Request $r, Closure $next): Response.
     *
     * @param list<Closure> $middleware
     */
    public function add(string $method, string $pattern, Closure $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'middleware');
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $request->attributes[$key] = $value;
                }
            }
            // Wrap the handler in its middleware, innermost first (an onion).
            $next = $route['handler'];
            foreach (array_reverse($route['middleware']) as $mw) {
                $inner = $next;
                $next = static fn(Request $r): Response => $mw($r, $inner);
            }
            return $next($request);
        }
        if ($allowed) {
            throw new ApiException(405, 'Method not allowed. Allowed: ' . implode(', ', array_unique($allowed)));
        }
        throw new ApiException(404, 'Route not found.');
    }
}
