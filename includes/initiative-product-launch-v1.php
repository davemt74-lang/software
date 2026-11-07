<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';

function initiative_product_launch_schema_v1(PDO $pdo): void
{
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_product_launches_v1 (
        product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        draft_key CHAR(64) NULL,
        creative_id CHAR(32) NULL,
        last_stage VARCHAR(24) NOT NULL DEFAULT '',
        last_notice_key CHAR(64) NOT NULL DEFAULT '',
        next_check_at DATETIME NULL,
        FOREIGN KEY (product_id) REFERENCES agent_commerce_products_v800(id) ON DELETE CASCADE"
        .($sqlite ? ')' : ', INDEX idx_product_launch_due (next_check_at,product_id),
            INDEX idx_product_launch_chat (owner_user_id,agent_id,conversation_id,next_check_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
}

/** Called within a confirmed domain write. The first binding keeps the original Chat. */
function initiative_product_launch_track_v1(PDO $pdo, int $owner, array $scope, int $productId, ?string $draftId=null, ?string $creativeId=null, bool $enrollOnly=false): void
{
    if (!table_exists('agent_product_launches_v1')) return;
    if (!$pdo->inTransaction()) throw new LogicException('Track the launch with its confirmed Commerce write.');
    $product = profile_commerce_owner_product_v900($pdo,$owner,$productId,true);
    if (!$product) throw new DomainException('The launch product is unavailable.',404);
    $metadata = profile_commerce_metadata_v900($product);
    $agent = $scope['agent_id'];
    $chat = $scope['conversation_id'];
    if (isset($metadata['conversation_id']) && ($metadata['conversation_id'] !== $chat || ($metadata['agent_id'] ?? null) !== $agent)) return;

    $find = $pdo->prepare('SELECT * FROM agent_product_launches_v1 WHERE product_id=?'.agent_commerce_lock_pa2($pdo));
    $find->execute([$productId]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    $now = agent_commerce_time_pa2();
    if (!$row) {
        $pdo->prepare('INSERT INTO agent_product_launches_v1
            (product_id,owner_user_id,agent_id,conversation_id,draft_key,creative_id,next_check_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$productId,$owner,$agent,$chat,$draftId,$creativeId,$now]);
        return;
    }
    if ($enrollOnly || (int)$row['owner_user_id'] !== $owner || (int)$row['agent_id'] !== $agent || (int)$row['conversation_id'] !== $chat) return;
    $pdo->prepare("UPDATE agent_product_launches_v1 SET draft_key=?,creative_id=?,last_stage='',next_check_at=? WHERE product_id=?")
        ->execute([$draftId ?? $row['draft_key'],$draftId === null ? $row['creative_id'] : $creativeId,$now,$productId]);
}

/** Recheck only the exact design this launch is following. A sibling job cannot move it. */
function initiative_product_launch_wake_v1(PDO $pdo, int $owner, int $productId, string $creativeId): void
{
    if (!table_exists('agent_product_launches_v1')) return;
    $pdo->prepare("UPDATE agent_product_launches_v1 SET last_stage='',next_check_at=?
        WHERE product_id=? AND owner_user_id=? AND creative_id=?")
        ->execute([agent_commerce_time_pa2(),$productId,$owner,$creativeId]);
}

function initiative_product_launch_notice_v1(array $state): ?string
{
    $title = mb_substr($state['product_title'],0,190);
    return match ($state['stage']) {
        'listing_review' => 'The private listing for '.$title.' is ready for your review in Profile Commerce.',
        'marketing_review' => 'The listing for '.$title.' is live. You can review marketing copy using its original photo.',
        'photo_needed' => 'The listing for '.$title.' is live. Choose a photo to start its promotion.',
        'design_review' => 'The private marketing draft for '.$title.' is ready to design in Canvas.',
        'creative_ready' => 'The design for '.$title.' is ready to preview in Canvas. Open this version to review and save the graphic.',
        'creative_failed' => 'The design for '.$title.' needs attention. Open this version in Canvas to see its status.',
        'publication_review' => 'The graphic for '.$title.' is saved. You can now review its promotion for publication.',
        'listing_unavailable' => 'The listing for '.$title.' needs attention before its promotion can go live.',
        'published' => 'The promotion for '.$title.' is live on your profile.',
        'paused' => 'The promotion for '.$title.' is paused.',
        default => null,
    };
}

/** Atomically deliver a verified checkpoint and its deduplication marker. No inference or approval. */
function initiative_product_launch_deliver_v1(PDO $pdo, int $productId): bool
{
    if ($pdo->inTransaction() || !table_exists('agent_product_launches_v1')) return false;
    require_once __DIR__.'/initiative-product-launch-state-v1.php';
    require_once __DIR__.'/initiative-work-product-v1.php';
    $query = 'SELECT * FROM agent_product_launches_v1 WHERE product_id=? AND next_check_at<=?';
    $now = agent_commerce_time_pa2();
    $find = $pdo->prepare($query);
    $find->execute([$productId,$now]);
    $binding = $find->fetch(PDO::FETCH_ASSOC);
    if (!$binding) return false;
    $owner = (int)$binding['owner_user_id'];
    $scope = ['agent_id'=>(int)$binding['agent_id'],'conversation_id'=>(int)$binding['conversation_id']];

    return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$query,$now,$productId,$owner,$scope): bool {
        // Use the same owner → Chat lock order as the reviewed domain writers.
        $unavailable = false;
        try {
            initiative_work_product_scope_v1($pdo,$owner,$scope,agent_commerce_lock_pa2($pdo));
        } catch (DomainException $error) {
            if ($error->getCode() !== 404) throw $error;
            $unavailable = true;
        }
        $find = $pdo->prepare($query.agent_commerce_lock_pa2($pdo));
        $find->execute([$productId,$now]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['owner_user_id'] !== $owner || (int)$row['agent_id'] !== $scope['agent_id']
            || (int)$row['conversation_id'] !== $scope['conversation_id']) return false;

        $state = [];
        if (!$unavailable) {
            try {
                $state = initiative_product_launch_state_v1($pdo,$owner,$scope,$productId,$row['draft_key'],$row['creative_id']);
            } catch (DomainException $error) {
                if (!in_array($error->getCode(),[403,404,409,422],true)) throw $error;
            }
        }
        $stage = $state['stage'] ?? '';
        $next = in_array($stage,['image_pending','image_starting'],true) ? gmdate('Y-m-d H:i:s',time()+30) : null;
        $notice = $row['last_notice_key'];
        $delivered = false;
        if ($state && ($message = initiative_product_launch_notice_v1($state)) !== null) {
            $marker = ['owner_user_id'=>$owner]+$scope+array_intersect_key($state,array_flip([
                'product_id','source_id','draft_id','creative_id','stage',
            ]));
            $key = hash('sha256',json_encode($marker,JSON_THROW_ON_ERROR));
            if ($key !== $notice) {
                $context = json_encode(['source'=>'agent_product_launch','skip_brain_archive'=>true,'product_launch'=>$marker],JSON_THROW_ON_ERROR);
                $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
                    VALUES (?,NULL,'assistant',?,?,?)")->execute([$scope['conversation_id'],$message,$context,$now]);
                $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')
                    ->execute([$now,$scope['conversation_id'],$owner]);
                $notice = $key;
                $delivered = true;
            }
        }
        $pdo->prepare('UPDATE agent_product_launches_v1 SET last_stage=?,last_notice_key=?,next_check_at=? WHERE product_id=?')
            ->execute([$stage,$notice,$next,$productId]);
        return $delivered;
    });
}

function initiative_product_launch_sweep_v1(PDO $pdo, int $limit, ?int $owner=null, ?int $agentId=null, ?int $conversationId=null): int
{
    if ($pdo->inTransaction() || !table_exists('agent_product_launches_v1')) return 0;
    $sql = 'SELECT product_id,next_check_at FROM agent_product_launches_v1 WHERE next_check_at<=?';
    $params = [agent_commerce_time_pa2()];
    if ($owner !== null) {
        if ($owner < 1 || $agentId === null || $agentId < 0 || $conversationId === null || $conversationId < 1) return 0;
        $sql .= ' AND owner_user_id=? AND agent_id=? AND conversation_id=?';
        array_push($params,$owner,$agentId,$conversationId);
    }
    $find = $pdo->prepare($sql.' ORDER BY next_check_at,product_id LIMIT '.max(1,min(50,$limit)));
    $find->execute($params);
    $delivered = 0;
    foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            if (initiative_product_launch_deliver_v1($pdo,(int)$row['product_id'])) $delivered++;
        } catch (Throwable $error) {
            // Keep the work due after a transient Chat/DB failure without blocking other launches.
            $pdo->prepare('UPDATE agent_product_launches_v1 SET next_check_at=? WHERE product_id=? AND next_check_at=?')
                ->execute([gmdate('Y-m-d H:i:s',time()+60),$row['product_id'],$row['next_check_at']]);
            if (function_exists('agent_runtime_v125_trace')) {
                agent_runtime_v125_trace('worker.product_launch.delivery_failed',['error_class'=>get_class($error)]);
            }
        }
    }
    return $delivered;
}

/** Catch up the current Chat when the host has no running background worker. */
function initiative_product_launch_chat_v1(PDO $pdo, int $owner, int $agentId, int $conversationId): void
{
    if ($conversationId < 1) return;
    try {
        initiative_product_launch_sweep_v1($pdo,3,$owner,$agentId,$conversationId);
    } catch (Throwable $error) {
        if (function_exists('agent_runtime_v125_trace')) {
            agent_runtime_v125_trace('chat.product_launch.catchup_failed',['error_class'=>get_class($error)]);
        }
    }
}
