<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-marketing-v1.php';
require_once __DIR__.'/agent-marketing-readiness-v1.php';
require_once __DIR__.'/agent-marketing-outcomes-v1.php';

/** One private Campaign review for both Top of Mind and Initiative Debug. */
function vp3_marketing_review_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $refs=null,
    bool $includeArtifacts=false,bool $includeChoices=false): array
{
    $result=['coverage'=>'unconfigured','campaigns'=>[]];
    $owner=vp3_coordination_marketing_owner_v1($pdo,$scope);
    if ($owner===null) return $result;
    foreach (['merchant_accounts','campaigns'] as $table) {
        if (!schema_metadata_table_exists($pdo,$table)) return ['coverage'=>'unavailable','campaigns'=>[]];
    }
    $ids=[];
    foreach ($refs??[] as $ref) {
        if (preg_match('/\Acampaign-(?:readiness|outcome|performance):([1-9][0-9]{0,17})\z/D',$ref,$match)) $ids[(int)$match[1]]=true;
    }
    if ($refs!==null && !$ids) return $result;
    $businesses=$pdo->prepare("SELECT id,name FROM merchant_accounts
        WHERE owner_user_id=? AND status='active' AND sandbox_mode=0 ORDER BY id".($refs===null?' LIMIT 21':''));
    $businesses->execute([$owner]);
    $merchants=[];
    $draftMerchants=[];
    $merchantRows=$businesses->fetchAll(PDO::FETCH_ASSOC);
    $limited=$refs===null && count($merchantRows)>20;
    foreach ($limited ? array_slice($merchantRows,0,20) : $merchantRows as $merchant) {
        $id=(int)$merchant['id'];
        if (campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.view')
            && campaigns_rewards_platform_can_v100($pdo,$id,$owner,'analytics.view')) {
            $merchants[$id]=$merchant['name'];
            if ($includeChoices && campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.create')
                && campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.edit')) $draftMerchants[$id]=true;
        }
    }
    if (!$merchants) return $result;
    $marks=implode(',',array_fill(0,count($merchants),'?'));
    $params=array_keys($merchants);
    $where='';
    if ($ids) {
        $where=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        array_push($params,...array_keys($ids));
    }
    $query=$pdo->prepare("SELECT id,merchant_id,name,status,current_version_no,starts_at,ends_at FROM campaigns
        WHERE merchant_id IN ($marks) AND environment='production'
          AND status IN ('draft','scheduled','active','paused','completed','archived') $where
        ORDER BY CASE WHEN status IN ('active','scheduled','draft') THEN 0 ELSE 1 END,updated_at DESC,id DESC"
        .($refs===null?' LIMIT 6':''));
    $query->execute($params);
    $rows=$query->fetchAll(PDO::FETCH_ASSOC);
    if ($refs===null && count($rows)>5) {
        $rows=array_slice($rows,0,5);
        $limited=true;
    }
    foreach ($rows as $row) {
        $id=(int)$row['id'];
        $merchant=(int)$row['merchant_id'];
        try {
            $readiness=vp3_marketing_campaign_readiness_v1($pdo,$owner,$merchant,$id,$now);
            $outcomes=vp3_marketing_campaign_outcomes_v1($pdo,$owner,$merchant,$id,$now);
        } catch (DomainException $error) {
            if (!in_array($error->getCode(),[403,404,409],true)) throw $error;
            $limited=true;
            continue;
        }
        if ($readiness===null && empty($outcomes['campaign'])) { $limited=true; continue; }
        if (($readiness['state']??null)==='limited' || empty($outcomes['campaign'])) $limited=true;
        $campaign=['id'=>$id,'name'=>vp3_agent_activity_text_v1($row['name'],160),
            'business'=>vp3_agent_activity_text_v1($merchants[$merchant],140),'status'=>$row['status'],
            'version'=>(int)$row['current_version_no'],
            'href'=>url('/campaigns.php?merchant='.$merchant.'&edit_campaign='.$id)];
        if (isset($draftMerchants[$merchant])) $campaign['draft_href']=url('/campaigns.php?merchant='.$merchant.'#marketing-offer-draft');
        if ($readiness!==null) {
            $campaign['readiness']=$readiness;
            if (!empty($readiness['location'])) $campaign['location']=vp3_agent_activity_text_v1($readiness['location'],120);
        }
        if (!empty($outcomes['campaign'])) {
            $campaign+=array_intersect_key($outcomes['campaign'],['starts_at'=>true,'ends_at'=>true,'window_state'=>true]);
            $fields=['decisions'=>true,'observations'=>true];
            if (!$includeArtifacts) $fields['handoff']=true;
            $campaign['outcomes']=array_intersect_key($outcomes,$fields);
        }
        if ($includeArtifacts) {
            $campaign['artifacts']=vp3_marketing_review_artifacts_v1($pdo,$scope,$campaign,$merchant);
            if ($includeChoices) $campaign['can_link_canvas']=campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.edit');
        }
        $result['campaigns'][]=$campaign;
    }
    $result['coverage']=$limited?'limited':'ready';
    if (count($draftMerchants)===1) $result['draft_href']=url('/campaigns.php?merchant='.array_key_first($draftMerchants).'#marketing-offer-draft');
    if (count($draftMerchants)>1 && !$result['campaigns']) $result['draft_href']=url('/campaigns.php#marketing-offer-draft');
    if ($includeChoices && $result['campaigns'] && array_filter($result['campaigns'],static fn(array $row):bool=>!empty($row['can_link_canvas']))) {
        try {
            require_once __DIR__.'/agent-marketing-canvas-link-v1.php';
            $result['canvas_choices']=vp3_marketing_canvas_choices_v1($pdo,$scope);
            if ($result['canvas_choices']) $result['link_endpoint']=url('/api/marketing-canvas-link-v1.php');
        } catch (RuntimeException $error) {
            if ($error instanceof PDOException || !in_array($error->getCode(),[403,404,409,503],true)) throw $error;
        }
    }
    return $result;
}

function vp3_marketing_review_artifacts_v1(PDO $pdo,array $scope,array $campaign,int $merchant): array
{
    require_once __DIR__.'/agent-marketing-canvas-link-v1.php';
    $artifacts=[];
    if ($campaign['status']==='draft') $artifacts[]=['label'=>'Campaign draft','state'=>'saved','href'=>$campaign['href']];
    if (schema_metadata_table_exists($pdo,'campaigns_rewards_object_bindings') && schema_metadata_table_exists($pdo,'reward_products')
        && campaigns_rewards_platform_can_v100($pdo,$merchant,(int)$scope['user']['id'],'rewards.view')) {
        require_once __DIR__.'/agent-marketing-offer-draft-v1.php';
        if ($reward=vp3_marketing_offer_reward_v1($pdo,$merchant,$campaign['id'])) {
            $artifacts[]=['label'=>(empty($reward['is_active'])?'Reward draft · ':'Reward product · ').vp3_agent_activity_text_v1($reward['name'],130),
                'state'=>'saved','href'=>url('/rewards.php?merchant='.$merchant.'&edit_reward='.(int)$reward['id'].'#reward-editor')];
        }
    }
    $canvas=vp3_marketing_canvas_artifact_v1($pdo,$scope,$campaign['id'],$campaign['version']);
    if ($canvas!==null) $artifacts[]=$canvas;
    return $artifacts;
}

/** Work on a confirmed blocker or report a recorded close, with stable evidence. */
function vp3_marketing_review_findings_v1(array $review,?array $refs=null,?DateTimeImmutable $now=null): array
{
    $findings=[];
    foreach ($review['campaigns'] as $campaign) {
        $id=$campaign['id'];
        $title=$campaign['business'].' · '.$campaign['name'];
        $blockers=array_filter($campaign['readiness']['checks']??[],static fn(array $check):bool=>
            ($check['state']??null)==='missing' && !empty($check['detail']) && !empty($check['next_step']));
        $ref='campaign-readiness:'.$id;
        if ($blockers && in_array($campaign['status'],['draft','scheduled','active'],true)
            && ($refs===null || in_array($ref,$refs,true))) {
            $next=$blockers[array_key_first($blockers)];
            $fullRef='marketing:'.$ref;
            $findings[$fullRef]=vp3_coordination_source_item_v1($fullRef,'marketing',$title.' needs a setup decision',
                implode(' ',array_column($blockers,'detail')),$next['next_step'],
                'Would you like to review the first setup issue for '.$campaign['name'].'?',
                $campaign['href'],'open','normal',[$id,$campaign['status'],$campaign['version'],$title,$blockers])
                +['trigger'=>'setup_blocker'];
        }
        $observed=$campaign['outcomes']['handoff']??null;
        $ref='campaign-outcome:'.$id;
        if (is_array($observed) && ($refs===null || in_array($ref,$refs,true))) {
            $count=$observed['reward_redemptions'];
            $fullRef='marketing:'.$ref;
            $summary=$count.' attributed reward '.($count===1?'redemption':'redemptions').' in the verified observation window from '
                .substr($observed['window_start'],0,10).' through '.substr($observed['window_end'],0,10).'.';
            $findings[$fullRef]=vp3_coordination_source_item_v1($fullRef,'marketing',$title.' · Campaign outcome',
                $summary,'Review this campaign before preparing the next offer.','',$campaign['href'],'open','normal',
                [$id,$observed['revision']])+['trigger'=>'campaign_outcome'];
        }
        // A live performance gap is only a review invitation from verified owner-scoped facts.
        // The stable semantic revision does not change on every new click.
        $performanceRef='campaign-performance:'.$id;
        if(($refs===null||in_array($performanceRef,$refs,true))
            &&($opportunity=vp3_marketing_performance_signal_v1($campaign,
                $now??new DateTimeImmutable('now',new DateTimeZone('UTC'))))!==null){
            $fullRef='marketing:'.$performanceRef;
            $promo=$campaign['outcomes']['observations']['promotion'];
            $findings[$fullRef]=vp3_coordination_source_item_v1($fullRef,'marketing',
                $title.' · '.$opportunity['title'],$opportunity['summary'],$opportunity['next_step'],
                $opportunity['question'],$campaign['href'],'open','normal',
                [$id,$campaign['version'],$campaign['status'],$title,$promo['window_start'],$opportunity['kind']])
                +['trigger'=>'performance_gap'];
        }
    }
    return $findings;
}


/**
 * A semantic, aggregate-only opportunity from a live production Campaign.
 * Never infer demand or revenue from missing/partial data; never create a
 * new source revision merely because a click counter increments.
 */
function vp3_marketing_performance_signal_v1(array $campaign,DateTimeImmutable $now): ?array
{
    if(($campaign['status']??null)!=='active'||($campaign['window_state']??null)!=='open'
        ||($campaign['readiness']['state']??null)!=='ready')return null;
    foreach((array)($campaign['outcomes']['decisions']??[]) as $decision){
        if(is_array($decision)&&($decision['type']??null)==='low-observed-conversion'
            &&($decision['status']??null)==='proposed')return null;
    }
    $promotion=$campaign['outcomes']['observations']['promotion']??null;
    if(!is_array($promotion)||($promotion['state']??null)!=='observed')return null;
    $rawStart=$promotion['window_start']??null;
    if(!is_string($rawStart)||strlen($rawStart)>35)return null;
    $start=DateTimeImmutable::createFromFormat(DATE_ATOM,$rawStart);
    if(!$start||$start->format(DATE_ATOM)!==$rawStart||$start>$now->modify('-48 hours'))return null;
    $metrics=$promotion['metrics']??null;
    if(!is_array($metrics))return null;
    foreach(['clicks','checkout_starts','paid_orders','refunded_orders'] as $key){
        if(!is_int($metrics[$key]??null)||$metrics[$key]<0)return null;
    }
    if($metrics['paid_orders']>0)return null;
    if($metrics['checkout_starts']>=10){
        return ['kind'=>'checkout_order_gap','title'=>'Review tracked checkout conversion',
            'summary'=>'At least 10 tracked checkout starts have no attributed paid order in this promotion evidence.',
            'next_step'=>'Review the checkout path and Campaign offer before drafting revised promotion copy.',
            'question'=>'Would you like to review the Campaign checkout evidence and prepare a private improvement idea?'];
    }
    if($metrics['clicks']>=40&&$metrics['checkout_starts']===0){
        return ['kind'=>'click_checkout_gap','title'=>'Review promotion click-to-checkout path',
            'summary'=>'At least 40 verified promotion clicks have no attributed checkout start in this tracking source.',
            'next_step'=>'Review the campaign creative, landing destination and checkout tracking before proposing changes.',
            'question'=>'Would you like to review the campaign creative and checkout path?'];
    }
    return null;
}
