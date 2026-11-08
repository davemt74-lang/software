<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';

/** Deliver cumulative verified activity for one publication into its original private Chat. */
function initiative_promotion_outcome_deliver_v1(PDO $pdo, string $publicationId): bool
{
    if ($pdo->inTransaction() || !table_exists('agent_promotion_publications_v1')
        || !preg_match('/\A[a-f0-9]{32}\z/D',$publicationId)) return false;
    require_once __DIR__.'/profile-promotion-attribution-v1.php';
    require_once __DIR__.'/initiative-work-marketing-promotion-v1.php';

    $now = agent_commerce_time_pa2();
    $due = 'SELECT * FROM agent_promotion_publications_v1 WHERE publication_id=? AND next_review_at<=?';
    $find = $pdo->prepare($due);
    $find->execute([$publicationId,$now]);
    $binding = $find->fetch(PDO::FETCH_ASSOC);
    if (!$binding) return false;
    $owner = (int)$binding['owner_user_id'];
    $scope = ['agent_id'=>(int)$binding['agent_id'],'conversation_id'=>(int)$binding['conversation_id']];

    // The summary must see evidence committed before we acquired its publication lock.
    if (!agent_commerce_sqlite_pa2($pdo)) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$publicationId,$now,$due,$owner,$scope): bool {
        // Reviewed launch writes use the same owner and Chat lock order.
        $available = true;
        try {
            initiative_work_product_scope_v1($pdo,$owner,$scope,agent_commerce_lock_pa2($pdo));
        } catch (DomainException $error) {
            if (!in_array($error->getCode(),[403,404,422],true)) throw $error;
            $available = false;
        }
        $find = $pdo->prepare($due.agent_commerce_lock_pa2($pdo));
        $find->execute([$publicationId,$now]);
        $publication = $find->fetch(PDO::FETCH_ASSOC);
        if (!$publication || (int)$publication['owner_user_id'] !== $owner || (int)$publication['agent_id'] !== $scope['agent_id']
            || (int)$publication['conversation_id'] !== $scope['conversation_id']) return false;

        $fingerprint = (string)$publication['last_review_fingerprint'];
        $delivered = false;
        $creative = null;
        if ($available) {
            try {
                $creative = initiative_work_marketing_promotion_row_v1($pdo,$owner,(string)$publication['promotion_id']);
                initiative_work_marketing_promotion_scope_v1($creative,$scope);
            } catch (DomainException $error) {
                if (!in_array($error->getCode(),[403,404,409,422],true)) throw $error;
                $creative = null;
            }
        }
        if ($creative && in_array($creative['status'],['published','paused'],true)
            && $creative['product_id'] === (int)$publication['product_id']
            && $creative['source_asset_id'] === (int)$publication['source_asset_id']
            && $creative['draft_key'] === $publication['draft_key']) {
            $report = profile_promotion_attribution_summary_v1($pdo,$owner,$publicationId);
            $placements = $report['placements'];
            ksort($placements,SORT_STRING);
            $lines = [];
            foreach ($placements as $placement=>$counts) {
                $clicks = $counts['clicks'];
                $checkouts = $counts['checkout_starts'];
                $paid = $counts['paid_orders'];
                $refunds = $counts['refunded_orders'];
                if (!$clicks && !$checkouts && !$paid && !$refunds) continue;
                $lines[] = ucwords(str_replace('_',' ',$placement)).' · CTA clicks '.$clicks.' · Checkout starts '.$checkouts
                    .' · Verified paid orders '.$paid.' · Orders with refunds '.$refunds;
            }
            $revision = hash('sha256',json_encode($placements,JSON_THROW_ON_ERROR));
            if ($lines && !hash_equals($fingerprint,$revision)) {
                $marker = ['owner_user_id'=>$owner]+$scope+['product_id'=>$creative['product_id'],
                    'source_id'=>(string)$creative['source_asset_id'],'draft_id'=>$creative['draft_key'],
                    'creative_id'=>$creative['id'],'stage'=>'promotion_outcomes'];
                $context = json_encode(['source'=>'agent_promotion_outcome','skip_brain_archive'=>true,
                    'product_launch'=>$marker,'promotion_outcome'=>['publication_id'=>$publicationId,
                        'as_of_utc'=>$now,'placements'=>$placements]],JSON_THROW_ON_ERROR);
                $title = mb_substr((string)$creative['settings']['headline'],0,120);
                $message = "Promotion results for “{$title}”\n\n".implode("\n",$lines)
                    ."\n\nTracking began {$publication['created_at']} UTC. Open the creative to review a revision.";
                $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
                    VALUES (?,NULL,'assistant',?,?,?)")->execute([$scope['conversation_id'],$message,$context,$now]);
                $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')
                    ->execute([$now,$scope['conversation_id'],$owner]);
                $fingerprint = $revision;
                $delivered = true;
            }
        }
        $pdo->prepare('UPDATE agent_promotion_publications_v1 SET next_review_at=NULL,last_review_fingerprint=? WHERE publication_id=?')
            ->execute([$fingerprint,$publicationId]);
        return $delivered;
    });
}

function initiative_promotion_outcome_sweep_v1(PDO $pdo, int $limit, ?int $owner=null, ?int $agentId=null, ?int $conversationId=null): int
{
    if ($pdo->inTransaction() || !table_exists('agent_promotion_publications_v1')) return 0;
    $sql = 'SELECT publication_id,next_review_at FROM agent_promotion_publications_v1 WHERE next_review_at<=?';
    $params = [agent_commerce_time_pa2()];
    if ($owner !== null) {
        if ($owner < 1 || $agentId === null || $agentId < 0 || $conversationId === null || $conversationId < 1) return 0;
        $sql .= ' AND owner_user_id=? AND agent_id=? AND conversation_id=?';
        array_push($params,$owner,$agentId,$conversationId);
    }
    $find = $pdo->prepare($sql.' ORDER BY next_review_at,publication_id LIMIT '.max(1,min(50,$limit)));
    $find->execute($params);
    $delivered = 0;
    foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            if (initiative_promotion_outcome_deliver_v1($pdo,$row['publication_id'])) $delivered++;
        } catch (Throwable $error) {
            $pdo->prepare('UPDATE agent_promotion_publications_v1 SET next_review_at=? WHERE publication_id=? AND next_review_at=?')
                ->execute([gmdate('Y-m-d H:i:s',time()+60),$row['publication_id'],$row['next_review_at']]);
            if (function_exists('agent_runtime_v125_trace')) {
                agent_runtime_v125_trace('worker.promotion_outcome.delivery_failed',['error_class'=>get_class($error)]);
            }
        }
    }
    return $delivered;
}
