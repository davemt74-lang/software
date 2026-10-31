<?php
declare(strict_types=1);

function agent_brain_context(array $user, string $query, int $limit = 10, bool $strictMatch = false): array
{
    if (!agent_brain_schema_ready()) {
        return [];
    }
    $pdo = db();
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId < 1) {
        return [];
    }

    $agentId = vp3_agent_memory_scope_current_v410($user);
    [$scope,$scopeParams] = vp3_agent_memory_scope_sql_v410($agentId);
    $terms = array_values(array_filter(
        preg_split('/[^\pL\pN._-]+/u', agent_brain_normalize($query)) ?: [],
        static fn(string $term): bool => mb_strlen($term) >= 3
    ));
    $terms = array_slice(array_unique($terms), 0, 6);
    $params = array_merge([$userId], $scopeParams);
    $where = 'user_id=? AND ' . $scope . ' AND is_active=1';
    if ($terms) {
        $parts = [];
        foreach ($terms as $term) {
            $parts[] = '(subject LIKE ? OR memory_text LIKE ?)';
            $like = '%' . $term . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
    }
    $limit = max(1, min(30, $limit));
    $stmt = $pdo->prepare(
        "SELECT id,memory_type,subject,memory_text,occurrence_count,last_seen_at,confidence
         FROM agent_memory_items
         WHERE {$where}
         ORDER BY occurrence_count DESC,last_seen_at DESC,id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows && $terms && !$strictMatch) {
        $stmt = $pdo->prepare(
            "SELECT id,memory_type,subject,memory_text,occurrence_count,last_seen_at,confidence
             FROM agent_memory_items
             WHERE user_id=? AND {$scope} AND is_active=1
             ORDER BY last_seen_at DESC,id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(array_merge([$userId], $scopeParams));
        $rows = $stmt->fetchAll();
    }

    return array_map(static function(array $row): array {
        return [
            'memory_id'=>(int)$row['id'],
            'source'=>'agent-memory:' . (string)$row['memory_type'],
            'title'=>(string)$row['subject'],
            'text'=>(string)$row['memory_text'] . ' (seen ' . (int)$row['occurrence_count'] . ' time' . ((int)$row['occurrence_count'] === 1 ? '' : 's') . '; last ' . (string)$row['last_seen_at'] . ')',
        ];
    }, $rows);
}

