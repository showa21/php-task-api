<?php
declare(strict_types=1);

namespace App\Repository;

use PDO;

final class TaskRepository
{
    private const SORTABLE = ['created_at', 'due_date', 'priority', 'title'];

    public function __construct(private PDO $pdo) {}

    /** @param array<string,mixed> $data validated fields */
    public function create(int $userId, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (user_id, title, description, status, priority, due_date) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['title'],
            $data['description'] ?? '',
            $data['status'] ?? 'todo',
            $data['priority'] ?? 2,
            $data['due_date'] ?? null,
        ]);
        return $this->find($userId, (int) $this->pdo->lastInsertId());
    }

    /** Ownership is part of every query: users can never reach each other's tasks. */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{status?:string,q?:string,sort?:string,order?:string} $filters
     * @return array{items:list<array>,total:int}
     */
    public function list(int $userId, array $filters, int $page, int $perPage): array
    {
        $where = ['user_id = :uid'];
        $params = [':uid' => $userId];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(title LIKE :q OR description LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        $sort = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $order = strtolower($filters['order'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        $clause = implode(' AND ', $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM tasks WHERE $clause");
        $count->execute($params);

        // $sort/$order come from allow-lists above, never straight from the user, so they are safe to interpolate.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM tasks WHERE $clause ORDER BY $sort $order, id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /** @param array<string,mixed> $data validated fields; only the given ones change (PATCH) */
    public function update(int $userId, int $id, array $data): ?array
    {
        $columns = array_intersect_key($data, array_flip(['title', 'description', 'status', 'priority', 'due_date']));
        if ($columns) {
            $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($columns)));
            $stmt = $this->pdo->prepare("UPDATE tasks SET $set, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :uid");
            $stmt->execute($columns + ['id' => $id, 'uid' => $userId]);
        }
        return $this->find($userId, $id);
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<string,int> */
    public function statsFor(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS n FROM tasks WHERE user_id = ? GROUP BY status');
        $stmt->execute([$userId]);
        $stats = ['todo' => 0, 'doing' => 0, 'done' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['status']] = (int) $row['n'];
        }
        $overdue = $this->pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'done' AND due_date < date('now')");
        $overdue->execute([$userId]);
        $stats['overdue'] = (int) $overdue->fetchColumn();
        return $stats;
    }
}
