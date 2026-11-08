<?php
declare(strict_types=1);

require_once __DIR__.'/profile-promotion-attribution-v1.php';

/** Public profile promotion can only associate with the owner's production Campaigns. */
function initiative_work_marketing_campaigns_ready_v1(PDO $pdo, int $owner): bool
{
    if (!profile_promotion_attribution_ready_v1($pdo) || !function_exists('campaigns_rewards_plugin_state_v100')) return false;
    $user = campaigns_rewards_user_row_v100($pdo,$owner);
    if (!$user || empty($user['is_active'])) return false;
    $state = campaigns_rewards_plugin_state_v100($user,$pdo);
    return !empty($state['enabled']) && !empty($state['schema_ready']);
}

function initiative_work_marketing_campaign_choices_v1(PDO $pdo, int $owner): array
{
    if (!initiative_work_marketing_campaigns_ready_v1($pdo,$owner)) return [];
    $select = $pdo->prepare("SELECT c.id,c.name,c.merchant_id,m.name AS merchant_name
        FROM campaigns c JOIN merchant_accounts m ON m.id=c.merchant_id
        WHERE m.owner_user_id=? AND m.status='active' AND m.sandbox_mode=0
            AND c.status='active' AND c.environment='production'
            AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP())
            AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())
        ORDER BY c.name,c.id LIMIT 100");
    $select->execute([$owner]);
    $allowed = [];
    $choices = [];
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $merchant = (int)$row['merchant_id'];
        $allowed[$merchant] ??= campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.publish');
        if ($allowed[$merchant]) {
            $choices[] = ['id'=>(int)$row['id'],'name'=>$row['name'],'merchant_name'=>$row['merchant_name']];
        }
    }
    return $choices;
}

function initiative_work_marketing_campaign_select_v1(PDO $pdo, int $owner, array $body, ?int $current, bool $lock=false): ?array
{
    $id = array_key_exists('campaign_id',$body) ? $body['campaign_id'] : $current;
    if ($id === null) return null;
    if (!is_int($id) || $id < 1) throw new DomainException('Choose an eligible campaign or publish without a campaign.',422);
    if (!initiative_work_marketing_campaigns_ready_v1($pdo,$owner)) {
        throw new DomainException('Campaigns & Rewards is unavailable for this publication.',409);
    }
    $forUpdate = $lock && !agent_commerce_sqlite_pa2($pdo);
    $campaign = campaigns_rewards_campaign_platform_v100($pdo,$id,$forUpdate);
    $now = agent_commerce_time_pa2();
    if (!$campaign || (int)$campaign['merchant_owner_user_id'] !== $owner || $campaign['merchant_status'] !== 'active'
        || !empty($campaign['merchant_sandbox_mode']) || $campaign['status'] !== 'active' || $campaign['environment'] !== 'production'
        || ($campaign['starts_at'] !== null && $campaign['starts_at'] > $now)
        || ($campaign['ends_at'] !== null && $campaign['ends_at'] <= $now)
        || !campaigns_rewards_platform_can_v100($pdo,(int)$campaign['merchant_id'],$owner,'campaigns.publish',$forUpdate)) {
        throw new DomainException('That campaign is unavailable for this publication.',409);
    }
    return ['id'=>$id,'name'=>$campaign['name'],'merchant_id'=>(int)$campaign['merchant_id'],
        'campaign_public_id'=>$campaign['public_id'],'merchant_public_id'=>$campaign['merchant_public_id'],
        'version'=>(int)$campaign['current_version_no'],'starts_at'=>$campaign['starts_at'],'ends_at'=>$campaign['ends_at']];
}
