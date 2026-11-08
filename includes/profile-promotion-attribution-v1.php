<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/schema-metadata.php';

function profile_promotion_attribution_schema_v1(PDO $pdo): void
{
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_promotion_publications_v1 (
        publication_id CHAR(32) NOT NULL PRIMARY KEY,
        promotion_id CHAR(32) NOT NULL,
        owner_user_id INT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        source_asset_id BIGINT UNSIGNED NOT NULL,
        draft_key CHAR(64) NOT NULL,
        campaign_id BIGINT UNSIGNED NULL,
        review_request CHAR(64) NULL,
        created_at DATETIME NOT NULL,
        next_review_at DATETIME NULL,
        last_review_fingerprint CHAR(64) NOT NULL DEFAULT '',
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE"
        .($sqlite ? '' : ', INDEX idx_promotion_review_due (next_review_at,publication_id),
            INDEX idx_promotion_review_chat (owner_user_id,agent_id,conversation_id,next_review_at),
            INDEX idx_promotion_campaign (owner_user_id,campaign_id,publication_id)') . ')'.$engine);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_promotion_clicks_v1 (
        click_id CHAR(32) NOT NULL PRIMARY KEY,
        publication_id CHAR(32) NOT NULL,
        placement VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (publication_id) REFERENCES agent_promotion_publications_v1(publication_id) ON DELETE CASCADE"
        .($sqlite ? '' : ', INDEX idx_promotion_click_publication (publication_id,placement)') . ')'.$engine);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_promotion_orders_v1 (
        order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        click_id CHAR(32) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
        FOREIGN KEY (click_id) REFERENCES agent_promotion_clicks_v1(click_id) ON DELETE CASCADE"
        .($sqlite ? '' : ', INDEX idx_promotion_order_click (click_id,order_id)') . ')'.$engine);
    if ($sqlite) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promotion_review_due ON agent_promotion_publications_v1(next_review_at,publication_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promotion_review_chat ON agent_promotion_publications_v1(owner_user_id,agent_id,conversation_id,next_review_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promotion_campaign ON agent_promotion_publications_v1(owner_user_id,campaign_id,publication_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promotion_click_publication ON agent_promotion_clicks_v1(publication_id,placement)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promotion_order_click ON agent_promotion_orders_v1(click_id,order_id)');
    }
}

function profile_promotion_attribution_ready_v1(PDO $pdo): bool
{
    return schema_metadata_table_exists($pdo,'agent_promotion_publications_v1')
        && schema_metadata_table_exists($pdo,'agent_promotion_clicks_v1')
        && schema_metadata_table_exists($pdo,'agent_promotion_orders_v1');
}

function profile_promotion_attribution_id_v1(mixed $id): bool
{
    return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D',$id) === 1;
}

function profile_promotion_attribution_publication_v1(PDO $pdo, int $owner, string $publicationId): ?array
{
    if ($owner < 1 || !profile_promotion_attribution_id_v1($publicationId) || !profile_promotion_attribution_ready_v1($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM agent_promotion_publications_v1 WHERE owner_user_id=? AND publication_id=?');
    $stmt->execute([$owner,$publicationId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function profile_promotion_attribution_campaign_v1(PDO $pdo, int $owner, string $publicationId): ?int
{
    $row = profile_promotion_attribution_publication_v1($pdo,$owner,$publicationId);
    return isset($row['campaign_id']) ? (int)$row['campaign_id'] : null;
}

function profile_promotion_attribution_review_v1(PDO $pdo, int $owner, string $publicationId): ?string
{
    return profile_promotion_attribution_publication_v1($pdo,$owner,$publicationId)['review_request'] ?? null;
}

/** Capture immutable scope during publication, or on the first verified visit to an older live card. */
function profile_promotion_attribution_register_v1(PDO $pdo, array $promotion, ?int $campaignId=null, ?string $reviewRequest=null): void
{
    if (!profile_promotion_attribution_ready_v1($pdo)) {
        if ($campaignId !== null) throw new DomainException('Run Database Upgrade to link a campaign.',503);
        return;
    }
    if (!$pdo->inTransaction()) throw new LogicException('Record attribution in the publication transaction.');
    if (($promotion['status'] ?? '') !== 'published'
        || !profile_promotion_attribution_id_v1($promotion['public_key'] ?? null)
        || !profile_promotion_attribution_id_v1($promotion['id'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/D',(string)($promotion['draft_key'] ?? ''))
        || (int)($promotion['owner_user_id'] ?? 0)<1 || (int)($promotion['product_id'] ?? 0)<1
        || (int)($promotion['agent_id'] ?? -1)<0 || (int)($promotion['conversation_id'] ?? 0)<1
        || (int)($promotion['source_asset_id'] ?? 0)<1 || ($campaignId !== null && $campaignId < 1)
        || ($reviewRequest !== null && !preg_match('/\A[a-f0-9]{64}\z/D',$reviewRequest))) {
        throw new DomainException('The promotion publication could not be verified.',409);
    }
    $binding = ['publication_id'=>$promotion['public_key'],'promotion_id'=>$promotion['id'],
        'owner_user_id'=>(int)$promotion['owner_user_id'],'product_id'=>(int)$promotion['product_id'],
        'agent_id'=>(int)$promotion['agent_id'],'conversation_id'=>(int)$promotion['conversation_id'],
        'source_asset_id'=>(int)$promotion['source_asset_id'],'draft_key'=>$promotion['draft_key'],
        'campaign_id'=>$campaignId,'review_request'=>$reviewRequest];
    $existing = profile_promotion_attribution_publication_v1($pdo,$binding['owner_user_id'],$binding['publication_id']);
    if ($existing) {
        foreach ($binding as $key=>$value) {
            if (($existing[$key] === null) !== ($value === null) || (string)$existing[$key] !== (string)$value) {
                throw new DomainException('This publication already has a different attribution scope.',409);
            }
        }
        return;
    }
    $pdo->prepare('INSERT INTO agent_promotion_publications_v1 (publication_id,promotion_id,owner_user_id,product_id,
        agent_id,conversation_id,source_asset_id,draft_key,campaign_id,review_request,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([...array_values($binding),agent_commerce_time_pa2()]);
}

function profile_promotion_attribution_wake_v1(PDO $pdo, string $publicationId): void
{
    $pdo->prepare('UPDATE agent_promotion_publications_v1 SET next_review_at=? WHERE publication_id=? AND next_review_at IS NULL')
        ->execute([gmdate('Y-m-d H:i:s',time()+86400),$publicationId]);
}

/** Placement and visit identity are issued by a first-party surface. No visitor identity is stored. */
function profile_promotion_attribution_record_click_v1(PDO $pdo, int $owner, string $publicationId, string $placement, string $clickId): bool
{
    if ($owner < 1 || !profile_promotion_attribution_id_v1($publicationId) || !profile_promotion_attribution_id_v1($clickId)
        || !preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D',$placement) || !profile_promotion_attribution_ready_v1($pdo)) return false;
    require_once __DIR__.'/profile-product-promotion-v1.php';
    $legacy = !profile_promotion_attribution_publication_v1($pdo,$owner,$publicationId);
    return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$owner,$publicationId,$placement,$clickId,$legacy): bool {
        // A legacy adoption inserts an owner FK. Match the existing publication lock order.
        if ($legacy) {
            $user = $pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
            $user->execute([$owner]);
            if (!$user->fetchColumn()) return false;
        }
        $find = $pdo->prepare("SELECT * FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND public_key=? AND status='published'"
            .agent_commerce_lock_pa2($pdo));
        $find->execute([$owner,$publicationId]);
        $promotion = $find->fetch(PDO::FETCH_ASSOC);
        if (!$promotion) return false;
        $profile = profile_for_user($pdo,$owner,false);
        $card = $profile ? profile_product_promotion_public_v1($pdo,$profile,$publicationId) : null;
        if (!$card || (int)$card['product_id'] !== (int)$promotion['product_id']) return false;
        $publication = profile_promotion_attribution_publication_v1($pdo,$owner,$publicationId);
        if (!$publication) profile_promotion_attribution_register_v1($pdo,$promotion);
        elseif ($publication['promotion_id'] !== $promotion['id'] || (int)$publication['product_id'] !== (int)$promotion['product_id']) return false;

        $find = $pdo->prepare('SELECT publication_id,placement FROM agent_promotion_clicks_v1 WHERE click_id=?');
        $find->execute([$clickId]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) return $existing['publication_id'] === $publicationId && $existing['placement'] === $placement;
        $pdo->prepare('INSERT INTO agent_promotion_clicks_v1 (click_id,publication_id,placement,created_at) VALUES (?,?,?,?)')
            ->execute([$clickId,$publicationId,$placement,agent_commerce_time_pa2()]);
        profile_promotion_attribution_wake_v1($pdo,$publicationId);
        return true;
    });
}

/** The order and its single line item must both carry the server-frozen visit. */
function profile_promotion_attribution_bind_order_v1(PDO $pdo, int $orderId, string $clickId): bool
{
    if ($orderId<1 || !profile_promotion_attribution_id_v1($clickId) || !profile_promotion_attribution_ready_v1($pdo)) return false;
    return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$orderId,$clickId): bool {
        $q = $pdo->prepare('SELECT id,owner_user_id,metadata_json FROM agent_commerce_orders_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $q->execute([$orderId]);
        $order = $q->fetch(PDO::FETCH_ASSOC);
        $metadata = json_decode((string)($order['metadata_json'] ?? ''),true);
        if (!$order || ($metadata['product_promotion_click_v1'] ?? null) !== $clickId) return false;
        $q = $pdo->prepare('SELECT click_id FROM agent_promotion_orders_v1 WHERE order_id=?');
        $q->execute([$orderId]);
        $existing = $q->fetchColumn();
        if ($existing !== false) return $existing === $clickId;
        $q = $pdo->prepare('SELECT c.publication_id,c.created_at,p.product_id FROM agent_promotion_clicks_v1 c
            JOIN agent_promotion_publications_v1 p ON p.publication_id=c.publication_id
            WHERE c.click_id=? AND p.owner_user_id=?');
        $q->execute([$clickId,(int)$order['owner_user_id']]);
        $visit = $q->fetch(PDO::FETCH_ASSOC);
        if (!$visit) return false;
        $clickTime = agent_commerce_date_pa2($visit['created_at']);
        $orderTime = agent_commerce_date_pa2($metadata['product_promotion_order_utc_v1'] ?? null);
        if ($clickTime<1 || $orderTime<$clickTime || $orderTime>$clickTime+86400) return false;
        $q = $pdo->prepare('SELECT product_id,metadata_json FROM agent_commerce_order_items_v800 WHERE order_id=? LIMIT 2');
        $q->execute([$orderId]);
        $items = $q->fetchAll(PDO::FETCH_ASSOC);
        $itemMetadata = json_decode((string)($items[0]['metadata_json'] ?? ''),true);
        if (count($items)!==1 || (int)$items[0]['product_id']!==(int)$visit['product_id']
            || ($itemMetadata['product_promotion_click_v1'] ?? null) !== $clickId
            || ($itemMetadata['product_promotion_order_utc_v1'] ?? null) !== $metadata['product_promotion_order_utc_v1']) return false;
        $pdo->prepare('INSERT INTO agent_promotion_orders_v1 (order_id,click_id) VALUES (?,?)')->execute([$orderId,$clickId]);
        return true;
    });
}

/** Observe a durable Commerce change. Failures in measurement leave the Commerce result usable. */
function profile_promotion_attribution_touch_order_v1(PDO $pdo, array $order): void
{
    $clickId = json_decode((string)($order['metadata_json'] ?? ''),true)['product_promotion_click_v1'] ?? null;
    if (!profile_promotion_attribution_id_v1($clickId)) return;
    $alreadyInTransaction = $pdo->inTransaction();
    try {
        agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$order,$clickId): void {
            if (!profile_promotion_attribution_bind_order_v1($pdo,(int)$order['id'],$clickId)) return;
            $q = $pdo->prepare('SELECT publication_id FROM agent_promotion_clicks_v1 WHERE click_id=?');
            $q->execute([$clickId]);
            $publicationId = $q->fetchColumn();
            if (is_string($publicationId)) profile_promotion_attribution_wake_v1($pdo,$publicationId);
        });
    } catch (Throwable $error) {
        if ($alreadyInTransaction && !$pdo->inTransaction()) throw $error;
        error_log('VP3 promotion outcome refresh failed: '.get_class($error));
    }
}

/** Aggregate accepted visits and the existing Commerce ledger, once per canonical order. */
function profile_promotion_attribution_totals_v1(PDO $pdo, int $owner, string|int $scope): array
{
    $column = is_int($scope) ? 'campaign_id' : 'publication_id';
    $payment = "payment.order_id=o.id AND payment.status='completed' AND payment.received_at IS NOT NULL
        AND payment.external_payment_id<>'' AND payment.amount_cents>0 AND payment.currency=o.currency
        AND attempt.order_id=o.id AND attempt.provider=payment.provider AND attempt.status='completed'
        AND attempt.external_payment_id=payment.external_payment_id AND attempt.amount_cents=payment.amount_cents
        AND attempt.currency=payment.currency";
    $q = $pdo->prepare("SELECT c.placement,COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM agent_commerce_checkout_attempts_v800 start_attempt
            WHERE start_attempt.order_id=o.id AND start_attempt.external_session_id<>''
              AND start_attempt.external_session_id NOT LIKE 'pending:%' AND start_attempt.checkout_url<>''
              AND start_attempt.status IN ('open','completed','expired','cancelled')
              AND start_attempt.last_error_class<>'checkout_terms_changed') THEN 1 ELSE 0 END) AS checkout_starts,
        SUM(CASE WHEN o.amount_due_cents=0 AND o.amount_paid_cents>0 AND o.amount_paid_cents>=o.total_cents AND o.paid_at IS NOT NULL
            AND o.order_status<>'payment_review' AND o.payment_status IN ('paid','partially_refunded','refunded')
            AND o.amount_paid_cents=(SELECT COALESCE(SUM(payment.amount_cents),0) FROM agent_commerce_payments_v800 payment
                JOIN agent_commerce_checkout_attempts_v800 attempt ON attempt.id=payment.checkout_attempt_id WHERE $payment)
            THEN 1 ELSE 0 END) AS paid_orders,
        SUM(CASE WHEN o.amount_refunded_cents>0 AND EXISTS (SELECT 1 FROM agent_commerce_refunds_v800 refund
            JOIN agent_commerce_payments_v800 payment ON payment.id=refund.payment_id
            JOIN agent_commerce_checkout_attempts_v800 attempt ON attempt.id=payment.checkout_attempt_id
            WHERE refund.order_id=o.id AND refund.checkout_attempt_id=attempt.id AND refund.provider=payment.provider
              AND refund.status='completed' AND refund.completed_at IS NOT NULL AND refund.external_refund_id<>''
              AND refund.amount_cents>0 AND refund.amount_cents<=payment.amount_cents AND $payment) THEN 1 ELSE 0 END) AS refunded_orders
        FROM agent_promotion_publications_v1 p
        JOIN agent_promotion_clicks_v1 c ON c.publication_id=p.publication_id
        LEFT JOIN agent_promotion_orders_v1 binding ON binding.click_id=c.click_id
        LEFT JOIN agent_commerce_orders_v800 o ON o.id=binding.order_id AND o.owner_user_id=p.owner_user_id
        WHERE p.owner_user_id=? AND p.$column=? GROUP BY c.placement ORDER BY c.placement");
    $q->execute([$owner,$scope]);
    $totals = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (['clicks','checkout_starts','paid_orders','refunded_orders'] as $metric) {
            $totals[$row['placement']][$metric] = (int)$row[$metric];
        }
    }
    return $totals;
}

function profile_promotion_attribution_summary_v1(PDO $pdo, int $owner, string $publicationId): array
{
    $publication = profile_promotion_attribution_publication_v1($pdo,$owner,$publicationId);
    return $publication ? ['publication'=>$publication,'placements'=>profile_promotion_attribution_totals_v1($pdo,$owner,$publicationId)] : [];
}

function profile_promotion_attribution_campaign_summary_v1(PDO $pdo, int $owner, int $campaignId): array
{
    return $owner > 0 && $campaignId > 0 && profile_promotion_attribution_ready_v1($pdo)
        ? profile_promotion_attribution_totals_v1($pdo,$owner,$campaignId) : [];
}
