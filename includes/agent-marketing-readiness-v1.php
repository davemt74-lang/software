<?php
declare(strict_types=1);

if (!function_exists('campaigns_rewards_platform_can_v100')) require_once __DIR__.'/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/campaigns-rewards-v120.php';
require_once __DIR__.'/agent-activity-scope-v1.php';

function vp3_marketing_readiness_check_v1(string $state,string $detail,string $next=''): array
{
    return ['state'=>$state,'detail'=>$detail,'next_step'=>$next];
}

/** Only the location saved on this campaign identifies its planned venue. */
function vp3_marketing_readiness_location_v1(PDO $pdo,int $owner,int $merchant,int $campaign): array
{
    $pick='Choose the campaign’s intended location in Campaigns & Rewards.';
    if (!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'locations.view')) {
        return ['unverified',null,vp3_marketing_readiness_check_v1('limited','Location access could not be verified.',$pick)];
    }
    $q=$pdo->prepare("SELECT target_type,target_id,target_public_id FROM campaigns_rewards_object_bindings
        WHERE merchant_id=? AND subject_type='campaign' AND subject_id=? AND purpose='location' ORDER BY id LIMIT 2");
    $q->execute([$merchant,$campaign]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['unverified',null,vp3_marketing_readiness_check_v1('missing','This campaign has no selected location.',$pick)];
    }
    $row=$rows[0];$id=(int)$row['target_id'];
    if (count($rows)!==1||$row['target_type']!=='merchant_location'||$id<1||(string)$id!==(string)$row['target_id']) {
        return ['unverified',null,vp3_marketing_readiness_check_v1('limited','The saved campaign location could not be verified.',$pick)];
    }
    $q=$pdo->prepare('SELECT public_id,name,location_type,is_active FROM merchant_locations WHERE merchant_id=? AND id=?');
    $q->execute([$merchant,$id]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    if (!$saved||($saved['public_id']??null)!==($row['target_public_id']??null)) {
        return ['unverified',null,vp3_marketing_readiness_check_v1('missing','The selected location is unavailable for this merchant.',$pick)];
    }
    if ($saved['location_type']==='online') {
        $check=(int)$saved['is_active']===1
            ? vp3_marketing_readiness_check_v1('ready','The campaign is assigned to an active online location.')
            : vp3_marketing_readiness_check_v1('missing','The selected online location is inactive.',$pick);
        return ['online',$id,$check,$check['state']==='ready'?vp3_agent_activity_text_v1($saved['name'],120):null];
    }
    $location=campaigns_rewards_claim_locations_v100($pdo,$merchant,$owner,$id)[0]??null;
    if ($location===null) {
        return ['unverified',null,vp3_marketing_readiness_check_v1('missing','The selected location is unavailable for in-person claims.',$pick)];
    }
    return ['in_person',$id,vp3_marketing_readiness_check_v1('ready','The selected location is active and eligible for in-person claims.'),
        vp3_agent_activity_text_v1($location['name'],120)];
}

/** Read bounded catalog evidence. Never call issuance or inventory reservation. */
function vp3_marketing_readiness_rewards_v1(PDO $pdo,int $owner,int $merchant,array $campaign,string $mode,?int $location): array
{
    $choose='Review the campaign’s reward mappings, fulfillment and inventory.';
    if (!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'rewards.view')) {
        return [vp3_marketing_readiness_check_v1('limited','Reward access could not be verified.',$choose),[]];
    }
    $id=(int)$campaign['id'];$versionNo=(int)$campaign['current_version_no'];$frozen=null;
    if ($versionNo>0) {
        $q=$pdo->prepare('SELECT reward_snapshot_json FROM campaign_versions WHERE campaign_id=? AND version_no=?');
        $q->execute([$id,$versionNo]);$frozen=$q->fetch(PDO::FETCH_ASSOC);
        if (!$frozen||!is_string($frozen['reward_snapshot_json'])||strlen($frozen['reward_snapshot_json'])>65536
            ||!is_array(json_decode($frozen['reward_snapshot_json'],true,16))) {
            return [vp3_marketing_readiness_check_v1('limited','The current campaign reward version could not be verified.',$choose),[]];
        }
    } elseif ($campaign['status']==='active') {
        return [vp3_marketing_readiness_check_v1('limited','There is no verified published reward version.',$choose),[]];
    }
    $q=$pdo->prepare("SELECT i.reward_product_id,i.variant_id,i.quantity,i.conditions_json,
        r.retail_value_minor,r.currency,r.inventory_mode,r.pickup_enabled,r.shipping_enabled,r.digital_enabled,
        r.claim_limit
        FROM campaign_reward_sets s JOIN campaign_reward_set_items i ON i.reward_set_id=s.id
        JOIN reward_products r ON r.id=i.reward_product_id AND r.merchant_id=? AND r.is_active=1
        JOIN reward_types t ON t.id=r.reward_type_id AND t.is_active=1 AND (t.merchant_id IS NULL OR t.merchant_id=r.merchant_id)
        LEFT JOIN reward_product_variants v ON v.id=i.variant_id AND v.reward_product_id=r.id AND v.is_active=1
        WHERE s.campaign_id=? AND (i.variant_id=0 OR i.variant_id IS NULL OR v.id IS NOT NULL)
        ORDER BY i.priority,i.reward_product_id,i.variant_id LIMIT 33");
    $q->execute([$merchant,$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $limited=count($rows)>32;$usable=[];
    $capacity=$campaign['max_rewards']===null?null:(int)$campaign['max_rewards'];
    if ($capacity!==null) {
        $used=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM reward_issuances
            WHERE campaign_id=? AND merchant_id=? AND status NOT IN ('voided','expired')");
        $used->execute([$id,$merchant]);
        $capacity-=(int)$used->fetchColumn();
    }
    foreach (array_slice($rows,0,32) as $row) {
        $quantity=(int)$row['quantity'];$variant=(int)$row['variant_id'];
        if ($quantity<1||($capacity!==null&&$capacity<$quantity)||(int)$row['claim_limit']<1) continue;
        if ($frozen!==null) {
            $mapped=campaigns_rewards_version_reward_snapshot_v100($frozen,(int)$row['reward_product_id']);
            if (!$mapped||empty($mapped['is_active'])||(int)($mapped['variant_id']??0)!==$variant) continue;
            $terms=['claim_limit','pickup_enabled','shipping_enabled','digital_enabled'];
            if ((int)($mapped['quantity']??0)!==$quantity||($mapped['currency']??null)!==$row['currency']
                ||($mapped['inventory_mode']??null)!==$row['inventory_mode']
                ||(string)($mapped['retail_value_minor']??'')!==(string)($row['retail_value_minor']??'')
                ||array_filter($terms,static fn(string $key):bool=>!isset($mapped[$key])||(int)$mapped[$key] !== (int)$row[$key])) {
                $limited=true;
                continue;
            }
        }
        $conditions=$row['conditions_json'];
        if ($conditions!==null&&$conditions!==''&&json_decode((string)$conditions,true)!==[]) {
            $limited=true;
            continue;
        }
        if (($mode==='in_person'&&!(int)$row['pickup_enabled'])
            ||($mode==='online'&&!(int)$row['shipping_enabled']&&!(int)$row['digital_enabled'])) continue;
        if ($row['inventory_mode']==='tracked') {
            $inventoryLocation=$mode==='in_person'?($location??0):0;
            $stock=$pdo->prepare('SELECT 1 FROM reward_inventory_balances WHERE reward_product_id=?
                AND (variant_id=0 OR variant_id=?) AND on_hand>=reserved+?'
                .($inventoryLocation>0?' AND (location_id=0 OR location_id=?)':'').' LIMIT 1');
            $params=[(int)$row['reward_product_id'],$variant,$quantity];
            if ($inventoryLocation>0) $params[]=$inventoryLocation;
            $stock->execute($params);
            if ($stock->fetchColumn()===false) continue;
        } elseif ($row['inventory_mode']!=='none') {
            $limited=true;
            continue;
        }
        $usable[]=['quantity'=>$quantity,'currency'=>strtoupper((string)$row['currency']),
            'value'=>$row['retail_value_minor']===null?null:(int)$row['retail_value_minor']];
    }
    if ($usable) {
        return [vp3_marketing_readiness_check_v1('ready',$frozen===null
            ? 'At least one usable reward is mapped in the draft. A live campaign still needs a published version.'
            : 'At least one active mapped reward passes the recorded fulfillment and inventory checks.'),$usable];
    }
    return [vp3_marketing_readiness_check_v1($limited?'limited':'missing',$limited
        ? 'Conditional or additional reward mappings need review.'
        : 'No usable mapped reward was verified for this campaign.',$choose),[]];
}

function vp3_marketing_readiness_claim_v1(PDO $pdo,int $owner,int $merchant,array $campaign,?int $location,
    string $mode,DateTimeImmutable $now,array $rewards): array
{
    $review='Review the claim code’s campaign, location, expiry and capacity in Claims.';
    if ($mode==='online') return vp3_marketing_readiness_check_v1('not_applicable','The planned location is online. In-person claim code checks do not apply.');
    if ($location===null) return vp3_marketing_readiness_check_v1('limited','Confirm an eligible in-person location before checking claim codes.',$review);
    if (!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'claim_codes.manage')
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'claims.process')) {
        return vp3_marketing_readiness_check_v1('limited','Claim code or operator access could not be verified.',$review);
    }
    $id=(int)$campaign['id'];$at=$now->format('Y-m-d H:i:s');
    $from=max($at,$campaign['starts_at']??$at);$ends=$campaign['ends_at']??null;
    $q=$pdo->prepare("SELECT c.id,c.merchant_member_id,c.active_until,c.daily_claim_limit,c.total_claim_limit,c.max_value_minor,c.currency
        FROM merchant_claim_codes c WHERE c.merchant_id=? AND c.status='active' AND (c.location_id IS NULL OR c.location_id=?)
        AND (c.active_from IS NULL OR c.active_from<=?) AND (c.active_until IS NULL OR c.active_until>?)
        AND (NOT EXISTS (SELECT 1 FROM merchant_claim_code_campaigns x WHERE x.claim_code_id=c.id)
            OR EXISTS (SELECT 1 FROM merchant_claim_code_campaigns x WHERE x.claim_code_id=c.id AND x.campaign_id=?))
        ORDER BY c.id LIMIT 26");
    $q->execute([$merchant,$location,$at,$from,$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $limited=count($rows)>25;
    foreach (array_slice($rows,0,25) as $code) {
        if ($code['merchant_member_id']!==null) {
            $member=$pdo->prepare("SELECT user_id FROM merchant_members WHERE merchant_id=? AND id=? AND status='active'");
            $member->execute([$merchant,(int)$code['merchant_member_id']]);$operator=(int)$member->fetchColumn();
            if ($operator<1||!campaigns_rewards_platform_can_v100($pdo,$merchant,$operator,'claims.process')
                ||!campaigns_rewards_claim_locations_v100($pdo,$merchant,$operator,$location)) continue;
        }
        if ($code['daily_claim_limit']!==null||$code['total_claim_limit']!==null) {
            $count=$pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN claimed_at>=? THEN 1 ELSE 0 END) today
                FROM reward_claims WHERE merchant_id=? AND claim_code_id=? AND status='claimed'");
            $count->execute([$now->format('Y-m-d 00:00:00'),$merchant,(int)$code['id']]);$use=$count->fetch(PDO::FETCH_ASSOC);
            if (($code['daily_claim_limit']!==null&&(int)$use['today']>=(int)$code['daily_claim_limit'])
                ||($code['total_claim_limit']!==null&&(int)$use['total']>=(int)$code['total_claim_limit'])) continue;
        }
        if ($code['max_value_minor']!==null) {
            if (!$rewards) {$limited=true;continue;}
            if (!array_filter($rewards,static fn(array $reward):bool=>$reward['value']!==null
                &&$reward['currency']===strtoupper((string)$code['currency'])
                &&$reward['value']<=intdiv((int)$code['max_value_minor'],$reward['quantity']))) continue;
        }
        if (is_string($ends)&&$code['active_until']!==null&&$code['active_until']<$ends) {
            $limited=true;
            continue;
        }
        return vp3_marketing_readiness_check_v1('ready','An active claim code covers this campaign and location with capacity available at the time of review.');
    }
    return vp3_marketing_readiness_check_v1($limited?'limited':'missing',$limited
        ? 'The available claim code coverage or value limits need review.'
        : 'No active claim code matching this campaign, location and capacity was verified.',$review);
}

/** The saved static audience is the bounded consent sample. No identities or addresses are read into the report. */
function vp3_marketing_readiness_audience_v1(PDO $pdo,int $owner,int $merchant,array $campaign): array
{
    $review='Review the campaign’s saved audience, intended channels and consent before sending.';
    if (!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.audience.preview')
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'crm.view')||$campaign['audience_mode']!=='static') {
        return vp3_marketing_readiness_check_v1('limited','Current audience eligibility or consent could not be verified.',$review);
    }
    $id=(int)$campaign['id'];
    $q=$pdo->prepare("SELECT m.channel,m.template_json FROM campaign_messages m
        WHERE m.campaign_id=? AND m.status='active' AND NOT EXISTS (SELECT 1 FROM campaign_messages newer
            WHERE newer.campaign_id=m.campaign_id AND newer.message_key=m.message_key AND newer.version_no>m.version_no)
        ORDER BY m.id LIMIT 26");
    $q->execute([$id]);$steps=$q->fetchAll(PDO::FETCH_ASSOC);$channels=[];
    if (count($steps)>25) return vp3_marketing_readiness_check_v1('limited','Additional message channels need a separate consent review.',$review);
    foreach ($steps as $step) {
        $template=json_decode((string)$step['template_json'],true,16);
        $kind=$template['kind']??null;
        if (is_array($template)&&($kind==='journey_step'||($kind==='journey_node'&&($template['node_type']??null)==='message'))
            &&($template['purpose']??'marketing')==='marketing'&&isset(campaigns_rewards_message_channels_v120()[$step['channel']])) {
            $channels[$step['channel']]=true;
        }
    }
    if (!$channels) return vp3_marketing_readiness_check_v1('limited','No active marketing channel was verified for this campaign.',$review);
    $dynamic=$pdo->prepare("SELECT 1 FROM campaign_audiences WHERE campaign_id=? AND status='active' AND mode<>'static' LIMIT 1");
    $dynamic->execute([$id]);$unknownMembership=$dynamic->fetchColumn()!==false;
    $q=$pdo->prepare("SELECT c.status,c.marketing_status,r.marketing_status merchant_marketing_status,
        p.marketing_email,p.marketing_sms,p.marketing_push,
        CASE WHEN c.email_normalized<>'' THEN 1 ELSE 0 END has_email,
        CASE WHEN c.phone<>'' THEN 1 ELSE 0 END has_phone,
        CASE WHEN u.is_active=1 THEN 1 ELSE 0 END has_vp3
        FROM crm_merchant_relationships r JOIN crm_contacts c ON c.id=r.contact_id AND c.owner_user_id=?
        LEFT JOIN crm_contact_preferences p ON p.contact_id=c.id LEFT JOIN users u ON u.id=c.vp3_user_id
        WHERE r.merchant_id=? AND EXISTS (SELECT 1 FROM campaign_audiences a
            JOIN campaign_audience_members am ON am.audience_id=a.id AND am.contact_id=c.id
            WHERE a.campaign_id=? AND a.status='active' AND a.mode='static') ORDER BY c.id LIMIT 101");
    $q->execute([$owner,$merchant,$id]);$audience=$q->fetchAll(PDO::FETCH_ASSOC);
    $destinations=['email'=>'has_email','sms'=>'has_phone','agent'=>'has_vp3'];
    $optOut=['unsubscribed','opted_out','suppressed','blocked','revoked'];
    foreach (array_slice($audience,0,100) as $member) {
        if (array_intersect($optOut,[strtolower((string)$member['marketing_status']),strtolower((string)$member['merchant_marketing_status'])])) continue;
        foreach ($channels as $channel=>$_) {
            if ((int)$member[$destinations[$channel]]===1
                && campaigns_rewards_message_consent_v120($member,$channel,'marketing')['allowed']) unset($channels[$channel]);
        }
    }
    if (!$channels) return vp3_marketing_readiness_check_v1('ready','At least one saved audience member passes the current consent check for each reviewed marketing channel.');
    $limited=$unknownMembership||count($audience)>100;
    return vp3_marketing_readiness_check_v1($limited?'limited':'missing',$limited
        ? 'The bounded sample did not verify eligible recipients on every reviewed channel.'
        : 'No eligible saved audience member was verified for one or more reviewed marketing channels.',$review);
}

function vp3_marketing_readiness_campaign_scope_v1(PDO $pdo,int $ownerId,int $merchantId,int $campaignId): ?array
{
    if (min($ownerId,$merchantId,$campaignId)<1 || !function_exists('campaigns_rewards_campaign_platform_v100')
        || (function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)) return null;
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);
    if (!$campaign||(int)$campaign['merchant_id']!==$merchantId||(int)$campaign['merchant_owner_user_id']!==$ownerId
        ||$campaign['merchant_status']!=='active'||!empty($campaign['merchant_sandbox_mode'])||$campaign['environment']!=='production'
        ||!in_array($campaign['status'],['draft','scheduled','active','paused'],true)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchantId,$ownerId,'campaigns.view')) return null;
    return $campaign;
}

/** Owner-private readiness for one exact production campaign. This path only reads records. */
function vp3_marketing_campaign_readiness_v1(PDO $pdo,int $ownerId,int $merchantId,int $campaignId,DateTimeImmutable $now): ?array
{
    $campaign=vp3_marketing_readiness_campaign_scope_v1($pdo,$ownerId,$merchantId,$campaignId);
    if ($campaign===null) return null;
    $now=$now->setTimezone(new DateTimeZone('UTC'));
    if ($campaign['ends_at']!==null&&$campaign['ends_at']<=$now->format('Y-m-d H:i:s')
        &&in_array($campaign['status'],['active','paused'],true)) return null;
    $mode='unverified';$location=null;$locationLabel=null;$checks=[];$rewards=[];
    $transactional=$pdo->inTransaction();
    foreach (['location','reward','claim_code','audience'] as $key) {
        try {
            switch ($key) {
                case 'location':
                    $venue=vp3_marketing_readiness_location_v1($pdo,$ownerId,$merchantId,$campaignId);
                    [$mode,$location,$checks[$key]]=$venue;
                    $locationLabel=$venue[3]??null;
                    break;
                case 'reward': [$checks[$key],$rewards]=vp3_marketing_readiness_rewards_v1($pdo,$ownerId,$merchantId,$campaign,$mode,$location);break;
                case 'claim_code': $checks[$key]=vp3_marketing_readiness_claim_v1($pdo,$ownerId,$merchantId,$campaign,$location,$mode,$now,$rewards);break;
                case 'audience': $checks[$key]=vp3_marketing_readiness_audience_v1($pdo,$ownerId,$merchantId,$campaign);break;
            }
        } catch (PDOException $error) {
            if (($transactional&&!$pdo->inTransaction())
                ||(!in_array((string)$error->getCode(),['42S02','42S22'],true)
                    &&!($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&(int)($error->errorInfo[1]??0)===1))) throw $error;
            $checks[$key]=vp3_marketing_readiness_check_v1('limited','The saved records for this check could not be verified.','Review this section in Campaigns & Rewards.');
        }
    }
    if (vp3_marketing_readiness_campaign_scope_v1($pdo,$ownerId,$merchantId,$campaignId)!==$campaign) return null;
    $states=array_column($checks,'state');
    $state=in_array('missing',$states,true)?'needs_setup':(in_array('limited',$states,true)?'limited':'ready');
    return ['state'=>$state,'mode'=>$mode,'checks'=>$checks]
        +(is_string($locationLabel)&&$locationLabel!==''?['location'=>$locationLabel]:[]);
}
