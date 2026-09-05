<?php
declare(strict_types=1);

function knowledge_visibility_allowed(string $visibility, ?array $user = null): bool
{
    return can_view_visibility($visibility, $user);
}

function knowledge_extract_file_text(string $absolutePath, string $extension): string
{
    $extension = strtolower($extension);

    if (in_array($extension, ['txt','md','csv','json','xml','html','htm'], true)) {
        $text = (string)@file_get_contents($absolutePath);
        if (in_array($extension, ['html','htm','xml'], true)) {
            $text = strip_tags($text);
        }
        return trim($text);
    }

    if ($extension === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if (is_string($xml)) {
                $xml = preg_replace('/<\/w:p>/', "\n", $xml);
                $xml = preg_replace('/<w:tab\/>/', "\t", (string)$xml);
                return trim(html_entity_decode(strip_tags((string)$xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
        }
    }

    if ($extension === 'pdf' && function_exists('exec') && function_exists('shell_exec')) {
        $binary = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($binary !== '') {
            $temp = tempnam(sys_get_temp_dir(), 'sf_pdf_');
            if ($temp) {
                @exec(escapeshellcmd($binary) . ' ' . escapeshellarg($absolutePath) . ' ' . escapeshellarg($temp) . ' 2>/dev/null');
                $text = is_file($temp) ? (string)@file_get_contents($temp) : '';
                @unlink($temp);
                return trim($text);
            }
        }
    }

    if ($extension === 'doc' && function_exists('exec') && function_exists('shell_exec')) {
        $binary = trim((string)@shell_exec('command -v antiword 2>/dev/null'));
        if ($binary !== '') {
            $output = [];
            @exec(escapeshellcmd($binary) . ' ' . escapeshellarg($absolutePath) . ' 2>/dev/null', $output);
            return trim(implode("\n", $output));
        }
    }

    return '';
}

function knowledge_chunks_from_text(string $text, int $maxChars = 1400): array
{
    $text = trim(preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text)) ?? '');
    if ($text === '') return [];

    $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
    $chunks = [];
    $current = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') continue;

        if (mb_strlen($current . "\n\n" . $paragraph) <= $maxChars) {
            $current = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
            continue;
        }

        if ($current !== '') {
            $chunks[] = $current;
            $current = '';
        }

        while (mb_strlen($paragraph) > $maxChars) {
            $slice = mb_substr($paragraph, 0, $maxChars);
            $breakAt = mb_strrpos($slice, ' ');
            if ($breakAt !== false && $breakAt > (int)($maxChars * 0.6)) {
                $slice = mb_substr($slice, 0, $breakAt);
            }
            $chunks[] = trim($slice);
            $paragraph = trim(mb_substr($paragraph, mb_strlen($slice)));
        }
        $current = $paragraph;
    }

    if ($current !== '') $chunks[] = $current;
    return array_values(array_filter($chunks));
}

function reindex_knowledge_item(int $knowledgeId, string $content): void
{
    $pdo = db();
    if (!$pdo) return;
    $pdo->prepare('DELETE FROM knowledge_chunks WHERE knowledge_id = ?')->execute([$knowledgeId]);
    $chunks = knowledge_chunks_from_text($content);
    if (!$chunks) return;
    $stmt = $pdo->prepare('INSERT INTO knowledge_chunks (knowledge_id, chunk_index, chunk_text) VALUES (?, ?, ?)');
    foreach ($chunks as $index => $chunk) $stmt->execute([$knowledgeId, $index, $chunk]);
}

function get_knowledge_item(int $id): ?array
{
    $pdo = db();
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM knowledge_items WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function personal_knowledge_available(?array $user = null): bool
{
    $user ??= current_user();
    return is_array($user)
        && (int)($user['id'] ?? 0) > 0
        && function_exists('personal_capability_has_v242')
        && personal_capability_has_v242('personal_knowledge.access', $user)
        && table_exists('knowledge_items')
        && column_exists('knowledge_items', 'created_by_user_id')
        && column_exists('knowledge_items', 'knowledge_scope');
}

/** Store deterministic internal/user-agent knowledge under the current owner. */
function personal_knowledge_store(
    array $user,
    string $key,
    string $title,
    string $content,
    string $description = ''
): int {
    if (!personal_knowledge_available($user)) {
        throw new RuntimeException('Personal Knowledge Base storage is unavailable for this account.');
    }
    if (!personal_capability_has_v242('personal_knowledge.manage', $user)) {
        throw new RuntimeException('Personal Knowledge management is unavailable for this account.');
    }

    $pdo = db();
    $userId = max(0, (int)($user['id'] ?? 0));
    $key = trim($key);
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $content = trim($content);
    $description = trim($description);
    if (!$pdo || $userId < 1 || $key === '' || $title === '' || $content === '') {
        throw new RuntimeException('Personal knowledge needs an owner, key, title and content.');
    }

    $title = mb_strimwidth($title, 0, 190, '…');
    $description = mb_strimwidth($description, 0, 2000, '…');
    $marker = 'personal-' . sha1($key) . '.txt';

    $find = $pdo->prepare(
        "SELECT id FROM knowledge_items
         WHERE created_by_user_id=? AND knowledge_scope='personal' AND file_type='personal_note' AND file_name=?
         LIMIT 1"
    );
    $find->execute([$userId, $marker]);
    $knowledgeId = (int)$find->fetchColumn();

    if ($knowledgeId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE knowledge_items
             SET title=?,description=?,file_path='',mime_type='text/plain',file_size=0,
                 content_text=?,visibility='private',is_published=0,knowledge_scope='personal'
             WHERE id=? AND created_by_user_id=?"
        );
        $stmt->execute([$title, $description, $content, $knowledgeId, $userId]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO knowledge_items
             (track_id,title,description,file_name,file_path,file_type,mime_type,file_size,
              content_text,visibility,is_published,created_by_user_id,knowledge_scope)
             VALUES (NULL,?,?,?,'','personal_note','text/plain',0,?,'private',0,?,'personal')"
        );
        $stmt->execute([$title, $description, $marker, $content, $userId]);
        $knowledgeId = (int)$pdo->lastInsertId();
    }

    if ($knowledgeId < 1) throw new RuntimeException('Could not save personal knowledge.');
    reindex_knowledge_item($knowledgeId, $content);
    if (function_exists('shared_knowledge_index_sync_item_v236')) {
        shared_knowledge_index_sync_item_v236($pdo, $knowledgeId);
    }
    return $knowledgeId;
}

/**
 * Legacy/general search remains available to callers that have not yet moved to
 * chat-agent-policy-v236. Personal rows are owner-only; system rows are the only
 * globally discoverable library here. Cross-user personal sharing must go
 * through shared_knowledge_index_v236 and live policy revalidation.
 */
function search_knowledge(string $query, ?array $user = null, int $limit = 8): array
{
    $pdo = db();
    $user ??= current_user();
    if (!$pdo || !is_array($user)) return [];

    $userId = max(0, (int)($user['id'] ?? 0));
    $scoped = column_exists('knowledge_items', 'knowledge_scope');
    $canPersonal = $scoped && $userId > 0 && personal_knowledge_available($user);
    $canSystem = has_permission('knowledge.access', $user);
    if (!$canPersonal && !$canSystem) return [];

    $query = trim($query);
    if ($query === '') return [];
    $terms = array_values(array_filter(
        preg_split('/[^\pL\pN]+/u', mb_strtolower($query)) ?: [],
        static fn(string $term): bool => mb_strlen($term) >= 2
    ));
    $terms = array_slice(array_unique($terms), 0, 8);
    if (!$terms) return [];

    try {
        if ($scoped) {
            $clauses=[];$params=[];
            if($canPersonal){$clauses[]="(i.knowledge_scope='personal' AND i.created_by_user_id=?)";$params[]=$userId;}
            if($canSystem){$clauses[]="(i.knowledge_scope='system' AND i.is_published=1)";}
            $where=$clauses?'('.implode(' OR ',$clauses).')':'0=1';
            $stmt=$pdo->prepare(
                "SELECT i.id,i.title,i.description,i.file_name,i.file_type,i.visibility,i.is_published,
                        i.created_by_user_id,i.knowledge_scope,c.chunk_text,t.title AS track_title
                 FROM knowledge_items i
                 LEFT JOIN knowledge_chunks c ON c.knowledge_id=i.id
                 LEFT JOIN tracks t ON t.id=i.track_id
                 WHERE {$where}
                 ORDER BY (i.created_by_user_id=?) DESC,i.updated_at DESC,c.chunk_index ASC
                 LIMIT 500"
            );
            $params[]=$userId;$stmt->execute($params);$rows=$stmt->fetchAll()?:[];
        } else {
            // Pre-upgrade compatibility: preserve legacy published knowledge only.
            if(!$canSystem)return [];
            $rows=$pdo->query(
                'SELECT i.id,i.title,i.description,i.file_name,i.file_type,i.visibility,i.is_published,
                        i.created_by_user_id,c.chunk_text,t.title AS track_title
                 FROM knowledge_items i
                 LEFT JOIN knowledge_chunks c ON c.knowledge_id=i.id
                 LEFT JOIN tracks t ON t.id=i.track_id
                 WHERE i.is_published=1
                 ORDER BY i.updated_at DESC,c.chunk_index ASC LIMIT 500'
            )->fetchAll()?:[];
        }
    } catch (Throwable $e) {
        return [];
    }

    $scored=[];
    foreach($rows as $row){
        $scope=$scoped?(string)($row['knowledge_scope']??'system'):'system';
        $personal=$scope==='personal'&&(int)($row['created_by_user_id']??0)===$userId;
        if($personal){if(!$canPersonal)continue;}
        else{
            if(!$canSystem||$scope!=='system'||empty($row['is_published']))continue;
            if(!knowledge_visibility_allowed((string)($row['visibility']??''),$user))continue;
        }
        $haystack=mb_strtolower((string)$row['title'].' '.(string)($row['track_title']??'').' '.(string)$row['description'].' '.(string)$row['chunk_text']);
        $score=0;foreach($terms as $term){$score+=substr_count($haystack,$term);if(str_contains(mb_strtolower((string)$row['title']),$term))$score+=3;}
        if($score<1)continue;
        $row['knowledge_scope']=$personal?'personal':'system';
        $row['score']=$score+($personal?1:0);
        $scored[]=$row;
    }
    usort($scored,static fn(array $a,array $b):int=>($b['score']<=>$a['score']));
    return array_slice($scored,0,max(1,$limit));
}