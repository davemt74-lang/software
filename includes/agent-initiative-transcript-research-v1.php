<?php
declare(strict_types=1);

require_once __DIR__.'/agent-transcript-research-policy-v1.php';

/** Rotate through saved analyses, including older recordings, without a write cursor. */
function vp3_initiative_transcript_research_scan_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    [$where, $args] = vp3_transcript_scope_v1($scope['user'], $scope['principal']);
    $source = "FROM artist_transcript_sessions_v172 s
        JOIN artist_transcript_master_analysis_v237 m ON m.session_id=s.id
        WHERE $where AND s.status='draft'";
    $count = $pdo->prepare('SELECT COUNT(*) '.$source);
    $count->execute($args);
    $pages = max(1, (int)ceil((int)$count->fetchColumn() / 4));
    $slot = max(0, intdiv($now->getTimestamp(), 300));
    $offset = ($slot % $pages) * 4;
    $page = $pdo->prepare('SELECT s.id '.$source.' ORDER BY s.id DESC LIMIT 4 OFFSET '.$offset);
    $page->execute($args);
    $ids = array_map('intval', $page->fetchAll(PDO::FETCH_COLUMN));
    $focus = vp3_transcript_focus_id_v1($pdo, (int)$scope['user']['id'], $scope['agent_id'], $scope['conversation_id'], $now);
    if ($focus > 0) $ids = array_values(array_unique([$focus, ...$ids]));
    return ['ids' => $ids, 'turn' => intdiv($slot, $pages), 'limited' => $pages > 1];
}

/** Rebuild the same bound candidate from current records for planning or delivery verification. */
function vp3_initiative_transcript_research_evidence_v1(PDO $pdo, array $scope, array $binding, DateTimeImmutable $now, ?array $refs, array &$coverage): array
{
    $selected = [];
    if ($refs !== null) {
        foreach ($refs as $ref) {
            if (!is_string($ref) || !str_starts_with($ref, 'transcript-research:')) continue;
            if (!preg_match('/\Atranscript-research:([1-9][0-9]{0,17}):([a-f0-9]{64})\z/D', $ref, $match)) {
                throw new InvalidArgumentException('Invalid transcript research source.');
            }
            $selected[(int)$match[1]][$match[2]] = true;
        }
        if ($selected === []) return [];
        if (array_sum(array_map('count', $selected)) > 12) throw new InvalidArgumentException('Too many transcript research sources.');
    }

    $family = 'transcript_research';
    $coverage[$family] = 'forbidden';
    try {
        $owner = (int)$scope['user']['id'];
        $grant = vp3_transcript_research_grant_v1($pdo, $owner);
        if ($grant === '') return [];
        require_once __DIR__.'/agent-initiative-transcripts-v1.php';
        $scope = vp3_initiative_transcript_capabilities_scope_v1($pdo, $scope, $binding);
        if (!table_exists('artist_transcript_sessions_v172') || !table_exists('artist_transcript_segments_v172')
            || !artist_listening_v237_schema_ready()) {
            $coverage[$family] = 'unavailable';
            return [];
        }

        $scan = $refs === null ? vp3_initiative_transcript_research_scan_v1($pdo, $scope, $now)
            : ['ids' => array_keys($selected), 'turn' => 0, 'limited' => false];
        $queues = $sessionRevisions = [];
        $denied = $unavailable = false;
        foreach ($scan['ids'] as $id) {
            try {
                $session = vp3_transcript_session_v1($pdo, $scope['user'], $scope['principal'], $id);
                if ($session['status'] !== 'draft') continue;
                vp3_initiative_transcript_capabilities_cloud_v1($pdo, $scope, $session);
                $analysis = vp3_transcript_intelligence_v1($pdo, $scope['user'], $scope['principal'], $id, 'research_candidates');
                $candidates = $analysis['research_candidates'];
                if ($refs !== null) $candidates = array_intersect_key($candidates, $selected[$id]);
                if ($candidates === []) continue;
                $map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, $id));
                if (!hash_equals($analysis['source_hash'], $map['source_hash'])) continue;
                $session = vp3_transcript_session_v1($pdo, $scope['user'], $scope['principal'], $id);
                if ($session['status'] !== 'draft') continue;
                $sessionRevisions[$id] = hash('sha256', json_encode([
                    $owner, $scope['agent_id'], $scope['conversation_id'], $grant, $map['source_hash'],
                    vp3_initiative_transcript_capabilities_semantic_session_v1($pdo, $scope, $session),
                ], JSON_THROW_ON_ERROR));
                if ($refs === null && count($candidates) > 8) {
                    $start = ($scan['turn'] % (int)ceil(count($candidates) / 8)) * 8;
                    $candidates = array_slice($candidates, $start, null, true) + array_slice($candidates, 0, $start, true);
                    $scan['limited'] = true;
                }
                $queues[$id] = new ArrayIterator($candidates);
            } catch (Throwable $error) {
                $unavailable = $unavailable || !in_array($error->getCode(), [403, 404, 409], true);
                $denied = $denied || $error->getCode() === 403;
                if ($refs === null) $scan['limited'] = true;
            }
        }

        if ($queues) require_once __DIR__.'/agent-transcript-research-knowledge-v1.php';
        $out = $topics = [];
        while ($queues) {
            foreach ($queues as $id => $queue) {
                if (!$queue->valid()) {
                    unset($queues[$id]);
                    continue;
                }
                $itemKey = $queue->key();
                $item = $queue->current();
                $queue->next();
                if ($refs === null && isset($topics[$item['topic_key']])) continue;
                if ($refs === null && count($out) >= 8) {
                    $scan['limited'] = true;
                    break 2;
                }
                $topics[$item['topic_key']] = true;
                $research = array_intersect_key($item, array_flip([
                    'session_id', 'plugin', 'section', 'item_id', 'question', 'next_step', 'source_url', 'topic_key', 'review_state', 'kind',
                ]));
                $research['question'] = vp3_initiative_transcript_capabilities_text_v1($item['question'], 240, 480);
                $research['next_step'] = vp3_initiative_transcript_capabilities_text_v1($item['next_step'], 200, 400);
                $aliases = vp3_transcript_research_knowledge_v1($pdo, $scope, $binding, $id, $item);
                if ($aliases) $research['knowledge'] = $aliases;
                $ref = 'transcript-research:'.$id.':'.$itemKey;
                $out[$ref] = [
                    'ref' => $ref,
                    'kind' => $family,
                    'title' => vp3_initiative_transcript_capabilities_text_v1($research['question'], 170, 300),
                    'detail' => 'A saved transcript plugin identified an open question or follow-up. Consider public research only when public sources could help.',
                    'url' => $item['source_url'],
                    'revision' => hash('sha256', json_encode([
                        $ref, $sessionRevisions[$id], $item['item_revision'], $research,
                    ], JSON_THROW_ON_ERROR)),
                    'research' => $research,
                ];
            }
        }
        if (!hash_equals($grant, vp3_transcript_research_grant_v1($pdo, $owner))) return [];
        $coverage[$family] = match (true) {
            $unavailable && ($refs !== null || !$out) => 'unavailable',
            $denied && !$out => 'forbidden',
            $scan['limited'] => 'limited',
            default => 'ready',
        };
        return $out;
    } catch (Throwable $error) {
        $coverage[$family] = in_array($error->getCode(), [403, 404], true) ? 'forbidden' : 'unavailable';
        return [];
    }
}
