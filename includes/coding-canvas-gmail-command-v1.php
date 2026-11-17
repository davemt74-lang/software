<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-command-grants-v1.php';
require_once __DIR__.'/agent-connections-v1.php';

/** A saved grant selects the account and query. Generated worker code cannot pass either. */
function vp3_coding_canvas_gmail_command_data_v1(
    PDO $pdo, array $user, int $agentId, int $conversationId, string $canvasId, int $revision, ?callable $http = null
): array {
    $unavailable = ['status'=>'unavailable','rows'=>[]];
    try {
        $granted = static fn(): array =>
            vp3_coding_canvas_command_grant_v1($pdo,$user,$agentId,$conversationId,$canvasId,$revision,'gmail');
        $grant = $granted();
        $owner = (int)$user['id'];
        $connectionId = $grant['connection_id'];
        $verifyConnection = static function () use ($pdo,$user,$owner,$connectionId,$grant): array {
            $connection = agent_connections_find_v1($pdo,$owner,$connectionId);
            if (!agent_connection_gmail_inbox_authority_v1($user,$connection)
                || !hash_equals($grant['connection_version'],hash('sha256',(string)$connection['authorization_key']))
                || !hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) {
                throw new RuntimeException('The Canvas Gmail connection is no longer available.',403);
            }
            return $connection;
        };
        $assertCurrent = static function () use ($granted,$grant,$verifyConnection): void {
            if ($granted() !== $grant) throw new RuntimeException('The Canvas Gmail grant changed while loading.',409);
            $verifyConnection();
        };
        $connection = $verifyConnection();
        $access = agent_connections_access_v1($owner,$connectionId,'read',$http,$connection['authorization_key']);
        if (!hash_equals($grant['connection_version'],$access['revision'])) return $unavailable;

        // The same scope is checked before each private provider read and after the
        // final response. An app switch, revocation, or reconnect discards the data.
        $transport = $http ?? 'agent_connection_gmail_http_v1';
        $deadline = microtime(true) + 15;
        $guardedRead = static function (string $method,string $url,array $headers,?array $body,bool $form)
            use ($assertCurrent,$transport,$deadline): array {
            if ($method !== 'GET' || $body !== null || microtime(true) > $deadline) {
                throw new RuntimeException('The Canvas Gmail read could not complete.',503);
            }
            $assertCurrent();
            return $transport($method,$url,$headers,$body,$form);
        };
        $scope = $grant['scope'];
        $token = $access['access_token'];
        $messages = [];
        if ($scope['mode'] === 'search') {
            $messages = agent_connection_gmail_search_v1($token,$scope['query'],8,$guardedRead);
        } elseif ($scope['mode'] === 'threads') {
            foreach ($scope['thread_ids'] as $thread) {
                try {
                    $threadMessages = agent_connection_gmail_thread_v1($token,$thread,$guardedRead);
                    $messages = [...$messages,...array_slice($threadMessages,-12)];
                } catch (AgentConnectionGmailExceptionV1 $error) {
                    if ($error->getCode() !== 404) throw $error;
                }
            }
        } else {
            return $unavailable;
        }
        $assertCurrent();

        usort($messages,static fn (array $a,array $b): int =>
            [$b['received_at_ms'],$b['message_id']] <=> [$a['received_at_ms'],$a['message_id']]);
        $rows = [];
        foreach ($messages as $mail) {
            $id = $mail['message_id'];
            if (isset($rows[$id])) continue;
            $received = $mail['received_at_ms'];
            $rows[$id] = [
                'id'=>$id,
                'subject'=>vp3_coding_canvas_gmail_command_text_v1($mail['subject'],250),
                'sender'=>vp3_coding_canvas_gmail_command_text_v1($mail['from'],250),
                'date'=>$received > 0 && $received <= 253402300799999 ? gmdate('Y-m-d\TH:i:s\Z',intdiv($received,1000)) : '',
                'snippet'=>vp3_coding_canvas_gmail_command_text_v1(html_entity_decode($mail['snippet'],ENT_QUOTES | ENT_HTML5,'UTF-8'),400),
            ];
            if (count($rows) >= 12) break;
        }
        return ['status'=>'ready','rows'=>array_values($rows)];
    } catch (RuntimeException|InvalidArgumentException $error) {
        return $unavailable;
    }
}

function vp3_coding_canvas_gmail_command_text_v1(string $text,int $limit): string
{
    $text = mb_scrub($text,'UTF-8');
    $text = preg_replace('/[\p{Cc}\p{Cf}\p{Z}\s]+/u',' ',$text) ?? '';
    return mb_substr(trim($text),0,$limit,'UTF-8');
}
