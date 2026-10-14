<?php
declare(strict_types=1);

// Run locally:  JWT_SECRET=change-me php -S localhost:8000 -t public public/index.php
require __DIR__ . '/../src/autoload.php';

use App\App;
use App\Http\{ApiException, Request, Response};
use App\Repository\Database;

$secret = getenv('JWT_SECRET') ?: 'dev-only-secret-change-me-please-0123456789';
$app = new App(Database::connect(__DIR__ . '/../data/app.sqlite'), $secret);

try {
    $request = Request::fromGlobals();
} catch (ApiException $e) {
    (new Response($e->status, ['error' => $e->getMessage()]))->send();
    exit;
}
$app->handle($request)->send();
