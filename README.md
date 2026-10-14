# PHP Task API

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE) ![Built with](https://img.shields.io/badge/PHP-777BB4?logo=php&logoColor=white)

A RESTful task-management API written in **plain PHP 8 with no framework**. It is built to show how frameworks like Laravel work underneath: a router, middleware, authentication, validation and a repository layer, all written by hand and covered by tests.

## Features
- **JWT authentication** (HS256 implemented from scratch: signature check with `hash_equals`, expiry, algorithm pinned so `alg=none` attacks fail)
- **Custom router** with `{id}` parameters, `404`/`405` handling and an onion-style **middleware** pipeline
- **Validation** layer returning `422` with per-field messages
- **Tasks:** create, read, partial update (`PATCH`), delete, plus **filtering, search, sorting and pagination**
- **Stats endpoint:** counts per status and overdue tasks
- **Rate limiting** on login (stored in the database, survives restarts)
- **Security:** bcrypt password hashing, prepared statements, allow-listed sort columns, ownership checks on every query (other users get `404`), identical error for unknown email and wrong password, no stack traces leaked
- Testable design: `App::handle(Request): Response` needs no web server

## Run
Requires PHP 8.1+ with `pdo_sqlite`.
```bash
JWT_SECRET=pick-a-long-random-string php -S localhost:8000 -t public public/index.php
```

## Test
```bash
php tests/run.php     # 40 checks
```

## API
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/register` | no | `{ "email", "password" }` → `201` + token |
| POST | `/auth/login` | no | → `200` + token (rate limited, `429`) |
| GET | `/tasks` | yes | Query: `status`, `q`, `sort` (`created_at`, `due_date`, `priority`, `title`), `order`, `page`, `per_page` |
| POST | `/tasks` | yes | `{ "title", "description?", "status?", "priority?" (1-3), "due_date?" (YYYY-MM-DD) }` |
| GET | `/tasks/stats` | yes | `{ todo, doing, done, overdue }` |
| GET | `/tasks/{id}` | yes | One task |
| PATCH | `/tasks/{id}` | yes | Change any subset of fields |
| DELETE | `/tasks/{id}` | yes | `204` |

```bash
TOKEN=$(curl -s -X POST localhost:8000/auth/register \
  -d '{"email":"me@example.com","password":"password123"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')

curl -X POST localhost:8000/tasks -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Learn PHP","priority":1,"due_date":"2026-12-01"}'

curl "localhost:8000/tasks?status=todo&sort=due_date&order=asc&per_page=5" -H "Authorization: Bearer $TOKEN"
```

## Structure
```text
public/index.php            entry point
src/App.php                 wiring + error handling (handle(): Request -> Response)
src/Http/                   Request, Response, Router, ApiException
src/Auth/Jwt.php            token issue / verify
src/Middleware/             AuthMiddleware, RateLimiter
src/Validation/Validator.php
src/Repository/             Database (schema), UserRepository, TaskRepository
src/Controller/             AuthController, TaskController
tests/run.php               tests (no dependencies)
```

## Ideas to extend it
Task tags, refresh tokens, an OpenAPI spec, swapping SQLite for MySQL, or porting it onto Composer + PHPUnit.
