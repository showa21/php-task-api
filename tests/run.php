<?php
declare(strict_types=1);

// Dependency-free test runner:  php tests/run.php
require __DIR__ . '/../src/autoload.php';

use App\App;
use App\Auth\Jwt;
use App\Http\{ApiException, Request, Response};
use App\Middleware\RateLimiter;
use App\Repository\Database;
use App\Validation\Validator;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    $ok || $failures++;
}

$pdo = Database::connect(':memory:');
$app = new App($pdo, 'test-secret');

function call(App $app, string $method, string $path, array $body = [], ?string $token = null, array $query = []): Response
{
    $headers = $token ? ['authorization' => "Bearer $token"] : [];
    return $app->handle(new Request($method, $path, $query, $headers, $body));
}

// ---- JWT ----
$jwt = new Jwt('s3cret', 60);
$t = $jwt->issue(7, 1000);
check('jwt round-trips the user id', $jwt->verify($t, 1010) === 7);
check('jwt expires', $jwt->verify($t, 1061) === null);
check('jwt rejects a tampered payload', $jwt->verify(explode('.', $t)[0] . '.' . base64_encode('{"sub":1,"exp":9999999999}') . '.' . explode('.', $t)[2], 1010) === null);
check('jwt rejects a different secret', (new Jwt('other'))->verify($t, 1010) === null);
$none = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=') . '.' . explode('.', $t)[1] . '.';
check('jwt rejects alg=none attack', $jwt->verify($none, 1010) === null);

// ---- Validator ----
check('validator reports bad fields', (function () {
    try { Validator::validate(['title' => ''], ['title' => 'required|string|min:1']); } catch (ApiException $e) { return isset($e->errors['title']); }
    return false;
})());
check('validator rejects impossible dates', (function () {
    try { Validator::validate(['d' => '2026-02-31'], ['d' => 'date']); } catch (ApiException) { return true; }
    return false;
})());

// ---- Auth ----
$r = call($app, 'POST', '/auth/register', ['email' => 'a@example.com', 'password' => 'password123']);
check('register returns 201 and a token', $r->status === 201 && !empty($r->data['token']));
$tokenA = $r->data['token'];
check('duplicate email gives 409', call($app, 'POST', '/auth/register', ['email' => 'A@example.com', 'password' => 'password123'])->status === 409);
check('bad email gives 422', call($app, 'POST', '/auth/register', ['email' => 'nope', 'password' => 'password123'])->status === 422);
check('short password gives 422', call($app, 'POST', '/auth/register', ['email' => 'b@example.com', 'password' => 'short'])->status === 422);
check('password stored hashed', $pdo->query('SELECT password_hash FROM users')->fetchColumn() !== 'password123');
check('login works', call($app, 'POST', '/auth/login', ['email' => 'a@example.com', 'password' => 'password123'])->status === 200);
$bad1 = call($app, 'POST', '/auth/login', ['email' => 'a@example.com', 'password' => 'wrong']);
$bad2 = call($app, 'POST', '/auth/login', ['email' => 'ghost@example.com', 'password' => 'wrong']);
check('wrong password gives 401, same message as unknown user', $bad1->status === 401 && $bad1->data === $bad2->data);

// ---- Rate limiting ----
$limiter = new RateLimiter($pdo, 3, 60);
for ($i = 0; $i < 3; $i++) $limiter->recordFailure('k', 1000);
check('limiter blocks after max failures', (function () use ($limiter) {
    try { $limiter->assertAllowed('k', 1010); } catch (ApiException $e) { return $e->status === 429; }
    return false;
})());
$limiter->assertAllowed('k', 1100);
check('limiter allows again after the window', true);
for ($i = 0; $i < 6; $i++) $last = call($app, 'POST', '/auth/login', ['email' => 'rl@example.com', 'password' => 'x']);
check('login endpoint returns 429 after repeated failures', $last->status === 429);

// ---- Tasks ----
check('tasks require a token', call($app, 'GET', '/tasks')->status === 401);
check('garbage token is rejected', call($app, 'GET', '/tasks', [], 'abc.def.ghi')->status === 401);

$c = call($app, 'POST', '/tasks', ['title' => 'Write tests', 'priority' => 1, 'due_date' => '2020-01-01'], $tokenA);
check('create task returns 201 with defaults', $c->status === 201 && $c->data['status'] === 'todo' && $c->data['priority'] === 1);
$id = $c->data['id'];
check('create without title gives 422', call($app, 'POST', '/tasks', ['priority' => 2], $tokenA)->status === 422);
check('invalid status gives 422', call($app, 'POST', '/tasks', ['title' => 'x', 'status' => 'wat'], $tokenA)->status === 422);
check('priority must be an integer 1-3', call($app, 'POST', '/tasks', ['title' => 'x', 'priority' => 9], $tokenA)->status === 422);

call($app, 'POST', '/tasks', ['title' => 'Buy milk', 'status' => 'done'], $tokenA);
call($app, 'POST', '/tasks', ['title' => 'Deploy', 'description' => 'milk the cache'], $tokenA);
$list = call($app, 'GET', '/tasks', [], $tokenA, ['per_page' => '2', 'sort' => 'title', 'order' => 'asc']);
check('pagination reports totals', $list->data['meta']['total'] === 3 && $list->data['meta']['pages'] === 2 && count($list->data['data']) === 2);
check('sorting works', $list->data['data'][0]['title'] === 'Buy milk');
check('filter by status', call($app, 'GET', '/tasks', [], $tokenA, ['status' => 'done'])->data['meta']['total'] === 1);
check('search matches title or description', call($app, 'GET', '/tasks', [], $tokenA, ['q' => 'milk'])->data['meta']['total'] === 2);
check('SQL injection via sort is ignored', call($app, 'GET', '/tasks', [], $tokenA, ['sort' => 'title; DROP TABLE tasks'])->status === 200);
check('SQL injection via search is harmless', call($app, 'GET', '/tasks', [], $tokenA, ['q' => "' OR 1=1 --"])->data['meta']['total'] === 0);

$p = call($app, 'PATCH', "/tasks/$id", ['status' => 'doing'], $tokenA);
check('PATCH changes only the given fields', $p->status === 200 && $p->data['status'] === 'doing' && $p->data['title'] === 'Write tests');
$stats = call($app, 'GET', '/tasks/stats', [], $tokenA)->data;
check('stats count statuses and overdue', $stats['doing'] === 1 && $stats['done'] === 1 && $stats['todo'] === 1 && $stats['overdue'] === 1);

// ---- Isolation between users ----
$tokenB = call($app, 'POST', '/auth/register', ['email' => 'b@example.com', 'password' => 'password123'])->data['token'];
check("user B cannot read A's task", call($app, 'GET', "/tasks/$id", [], $tokenB)->status === 404);
check("user B cannot edit A's task", call($app, 'PATCH', "/tasks/$id", ['title' => 'pwned'], $tokenB)->status === 404);
check("user B cannot delete A's task", call($app, 'DELETE', "/tasks/$id", [], $tokenB)->status === 404);
check("user B's list is empty", call($app, 'GET', '/tasks', [], $tokenB)->data['meta']['total'] === 0);

// ---- Delete & routing ----
check('owner can delete (204)', call($app, 'DELETE', "/tasks/$id", [], $tokenA)->status === 204);
check('deleted task is gone', call($app, 'GET', "/tasks/$id", [], $tokenA)->status === 404);
check('unknown route gives 404', call($app, 'GET', '/nope')->status === 404);
check('wrong method gives 405', call($app, 'PUT', '/tasks', [], $tokenA)->status === 405);
check('/tasks/stats is not captured by /tasks/{id}', call($app, 'GET', '/tasks/stats', [], $tokenA)->status === 200);

echo PHP_EOL . ($failures === 0 ? 'All tests passed.' : "$failures test(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
