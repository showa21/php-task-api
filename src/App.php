<?php
declare(strict_types=1);

namespace App;

use App\Auth\Jwt;
use App\Controller\{AuthController, TaskController};
use App\Http\{ApiException, Request, Response, Router};
use App\Middleware\{AuthMiddleware, RateLimiter};
use App\Repository\{TaskRepository, UserRepository};
use PDO;
use Throwable;

/** Wires everything together. handle() turns a Request into a Response, so tests need no web server. */
final class App
{
    private Router $router;

    public function __construct(PDO $pdo, string $jwtSecret)
    {
        $jwt = new Jwt($jwtSecret);
        $auth = new AuthController(new UserRepository($pdo), $jwt, new RateLimiter($pdo));
        $tasks = new TaskController(new TaskRepository($pdo));
        $guard = new AuthMiddleware($jwt);

        $this->router = new Router();
        $r = $this->router;
        $r->add('GET', '/', fn() => new Response(200, ['name' => 'PHP Task API', 'docs' => 'See README.md']));
        $r->add('POST', '/auth/register', $auth->register(...));
        $r->add('POST', '/auth/login', $auth->login(...));
        $r->add('GET', '/tasks', $tasks->index(...), [$guard]);
        $r->add('POST', '/tasks', $tasks->store(...), [$guard]);
        $r->add('GET', '/tasks/stats', $tasks->stats(...), [$guard]);
        $r->add('GET', '/tasks/{id}', $tasks->show(...), [$guard]);
        $r->add('PATCH', '/tasks/{id}', $tasks->update(...), [$guard]);
        $r->add('DELETE', '/tasks/{id}', $tasks->destroy(...), [$guard]);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ApiException $e) {
            $body = ['error' => $e->getMessage()];
            if ($e->errors) {
                $body['fields'] = $e->errors;
            }
            return new Response($e->status, $body);
        } catch (Throwable $e) {
            error_log((string) $e); // log details, never show them to the client
            return new Response(500, ['error' => 'Internal server error.']);
        }
    }
}
