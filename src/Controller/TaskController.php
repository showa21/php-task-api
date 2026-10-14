<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\{ApiException, Request, Response};
use App\Repository\TaskRepository;
use App\Validation\Validator;

final class TaskController
{
    private const RULES = [
        'title' => 'string|min:1|max:100',
        'description' => 'string|max:2000',
        'status' => 'in:todo,doing,done',
        'priority' => 'int|in:1,2,3',
        'due_date' => 'date',
    ];

    public function __construct(private TaskRepository $tasks) {}

    public function index(Request $r): Response
    {
        $page = max(1, (int) ($r->query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($r->query['per_page'] ?? 10)));
        $filters = array_intersect_key($r->query, array_flip(['status', 'q', 'sort', 'order']));
        if (isset($filters['status']) && !in_array($filters['status'], ['todo', 'doing', 'done'], true)) {
            throw new ApiException(422, 'Validation failed.', ['status' => 'Must be one of: todo,doing,done.']);
        }
        $result = $this->tasks->list($this->userId($r), $filters, $page, $perPage);
        return new Response(200, [
            'data' => $result['items'],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total'],
                       'pages' => max(1, (int) ceil($result['total'] / $perPage))],
        ]);
    }

    public function show(Request $r): Response
    {
        return new Response(200, $this->findOr404($r));
    }

    public function store(Request $r): Response
    {
        $rules = self::RULES;
        $rules['title'] = 'required|' . $rules['title'];
        $data = Validator::validate($r->body, $rules);
        return new Response(201, $this->tasks->create($this->userId($r), $data));
    }

    public function update(Request $r): Response
    {
        $this->findOr404($r);
        $data = Validator::validate($r->body, self::RULES);
        return new Response(200, $this->tasks->update($this->userId($r), (int) $r->attributes['id'], $data));
    }

    public function destroy(Request $r): Response
    {
        $this->findOr404($r);
        $this->tasks->delete($this->userId($r), (int) $r->attributes['id']);
        return new Response(204);
    }

    public function stats(Request $r): Response
    {
        return new Response(200, $this->tasks->statsFor($this->userId($r)));
    }

    private function userId(Request $r): int
    {
        return (int) $r->attributes['user_id'];
    }

    private function findOr404(Request $r): array
    {
        $task = $this->tasks->find($this->userId($r), (int) ($r->attributes['id'] ?? 0));
        // 404 (not 403) for other people's tasks: don't reveal that the id exists.
        return $task ?? throw new ApiException(404, 'Task not found.');
    }
}
