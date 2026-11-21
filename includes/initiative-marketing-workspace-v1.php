<?php
declare(strict_types=1);

/**
 * Phase 2A: one owner-private, read-only projection for the Marketing Chat Canvas.
 * Campaign status and permission always come from canonical Campaigns & Rewards.
 * This endpoint does not create, publish, message, transfer, or schedule anything.
 */
function vp3_initiative_marketing_workspace_overview_v1(PDO $pdo,int $owner,array $scope): array
{
    $empty=['coverage'=>'unconfigured','campaigns'=>[],'links'=>['campaigns'=>null,'products'=>null,'rewards'=>null]];
    if($owner<1 || (int)($scope['user']['id']??0)!==$owner
        || !function_exists('has_permission') || !has_permission('account.access',$scope['user']))return $empty;
    if(!function_exists('vp3_coordination_marketing_owner_v1'))require_once __DIR__.'/agent-coordination-marketing-v1.php';
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner)return $empty;
    if(!function_exists('vp3_marketing_review_v1'))require_once __DIR__.'/agent-marketing-review-v1.php';
    $report=vp3_marketing_review_v1($pdo,$scope,new DateTimeImmutable('now',new DateTimeZone('UTC')),null,false,true);
    $campaigns=[];
    foreach(array_slice((array)($report['campaigns']??[]),0,5) as $item) {
        if(!is_array($item)||(int)($item['id']??0)<1
            ||!in_array($item['status']??null,['draft','scheduled','active','paused','completed','archived'],true))continue;
        $issues=[];
        foreach((array)($item['readiness']['checks']??[]) as $check) {
            if(!is_array($check)||!in_array($check['state']??null,['missing','limited'],true))continue;
            $detail=$check['detail']??null;
            if(is_string($detail)&&trim($detail)!=='')$issues[]=mb_substr(trim($detail),0,240);
            if(count($issues)>=4)break;
        }
        $observations=[];
        foreach(['claims','redemptions','promotion'] as $key) {
            $state=$item['outcomes']['observations'][$key]['state']??null;
            $observations[$key]=in_array($state,['observed','unavailable','unconfigured','limited','not_started'],true)
                ?$state:'unavailable';
        }
        $campaigns[]=[
            'id'=>(int)$item['id'],
            'name'=>mb_substr((string)($item['name']??''),0,160),
            'business'=>mb_substr((string)($item['business']??''),0,140),
            'status'=>$item['status'],
            'version'=>max(0,(int)($item['version']??0)),
            'href'=>is_string($item['href']??null)?$item['href']:null,
            'readiness'=>in_array($item['readiness']['state']??null,['ready','limited','needs_setup'],true)
                ?$item['readiness']['state']:'unavailable',
            'issues'=>$issues,
            'window_state'=>in_array($item['window_state']??null,['open','closed','upcoming','not_open'],true)
                ?$item['window_state']:'unavailable',
            'observations'=>$observations,
            'performance'=>vp3_marketing_workspace_performance_v1((array)($item['outcomes']??[])),
            'followthrough'=>vp3_marketing_workspace_followthrough_v1((array)($item['outcomes']['followthrough']??[])),
            'linked_creative'=>vp3_marketing_workspace_linked_creative_v1($pdo,$owner,$scope,$item),
        ];
    }
    // Revalidate current owner/plugin authority after the read, including empty or revoked businesses.
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner)return $empty;
    return [
        'coverage'=>in_array($report['coverage']??null,['ready','limited','unavailable','unconfigured'],true)
            ?$report['coverage']:'unavailable',
        'campaigns'=>$campaigns,
        'links'=>[
            'campaigns'=>url('/campaigns.php'),
            'products'=>url('/profile-commerce-products.php'),
            'rewards'=>url('/rewards.php'),
        ],
    ];
}


/**
 * A bounded, aggregate-only projection of existing, independently verified
 * Campaigns/Rewards and Profile-promotion observation sources. Never total
 * these sources together: their windows and attributions can overlap.
 */
function vp3_marketing_workspace_performance_v1(array $outcomes): array
{
    $allowed=[
        'claims'=>['reward_redemptions'],
        'redemptions'=>['reward_redemptions','decisions_sampled'],
        'promotion'=>['clicks','checkout_starts','paid_orders','refunded_orders'],
    ];
    $result=[];
    foreach($allowed as $source=>$keys) {
        $row=$outcomes['observations'][$source]??null;
        $state=is_array($row)?($row['state']??null):null;
        if(!in_array($state,['observed','unobserved','unavailable'],true)) $state='unavailable';
        $metrics=[];
        if($state==='observed') {
            foreach($keys as $key) {
                $value=$row['metrics'][$key]??null;
                // Counters must come from the canonical source, not arbitrary
                // strings or a fallback zero in a missing/invalid response.
                if(!is_int($value)||$value<0) { $state='unavailable';$metrics=[];break; }
                $metrics[$key]=$value;
            }
        }
        $window=['start'=>null,'end'=>null];
        if($state==='observed') {
            foreach(['window_start'=>'start','window_end'=>'end'] as $key=>$target) {
                $value=$row[$key]??null;
                if(is_string($value)&&strlen($value)<=35
                    &&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\z/D',$value)) {
                    $window[$target]=$value;
                }
            }
        }
        $result[$source]=['state'=>$state,'metrics'=>$metrics,'window'=>$window];
    }
    $types=['offer-eligibility','fatigue','low-observed-conversion','conflict-volume'];
    $decisions=[];
    foreach((array)($outcomes['decisions']??[]) as $decision) {
        if(!is_array($decision)||!is_int($decision['id']??null)||$decision['id']<1
            ||!in_array($decision['type']??null,$types,true)
            ||!in_array($decision['status']??null,['proposed','accepted','dismissed'],true))continue;
        $decisions[]=['id'=>$decision['id'],'type'=>$decision['type'],'status'=>$decision['status']];
        if(count($decisions)>=5)break;
    }
    return ['sources'=>$result,'decisions'=>$decisions];
}


/** Emit only aggregate counts and trusted lifecycle states from canonical Campaigns. */
function vp3_marketing_workspace_followthrough_window_v1(mixed $value): ?array
{
    if(!is_array($value)||!is_int($value['window_days']??null)
        ||$value['window_days']<7||$value['window_days']>365)return null;
    foreach(['window_start','window_end'] as $key){
        if(!is_string($value[$key]??null)||strlen($value[$key])>35
            ||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D',$value[$key])!==1)return null;
    }
    $metrics=[];
    foreach(['treatment_entries','converted','reward_claimed'] as $key){
        $amount=$value['metrics'][$key]??null;
        if(!is_int($amount)||$amount<0||$amount>9007199254740991)return null;
        $metrics[$key]=$amount;
    }
    return ['window_start'=>$value['window_start'],'window_end'=>$value['window_end'],
        'window_days'=>$value['window_days'],'metrics'=>$metrics];
}

function vp3_marketing_workspace_followthrough_v1(array $rows): array
{
    $types=['offer-eligibility','fatigue','low-observed-conversion','conflict-volume'];
    $states=['awaiting_change','awaiting_window','observed','insufficient_sample','unavailable'];
    $result=[];
    foreach(array_slice($rows,0,3) as $row){
        if(!is_array($row)||($row['scope']??null)!=='observational_not_causal'
            ||!is_int($row['recommendation_id']??null)||$row['recommendation_id']<1
            ||!in_array($row['type']??null,$types,true)||!in_array($row['state']??null,$states,true)
            ||!is_string($row['reviewed_at']??null))continue;
        $reviewed=$row['reviewed_at'];
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D',$reviewed))continue;
        $changed=$row['change_at']??null;
        if($changed!==null&&(!is_string($changed)
            ||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D',$changed)!==1))continue;
        $before=vp3_marketing_workspace_followthrough_window_v1($row['before']??null);
        $after=vp3_marketing_workspace_followthrough_window_v1($row['after']??null);
        if($before===null||($row['state']==='observed'&&$after===null)
            ||($changed===null&&$row['state']!=='awaiting_change')
            ||($changed!==null&&$row['state']==='awaiting_change'))continue;
        $result[]=['recommendation_id'=>$row['recommendation_id'],'type'=>$row['type'],
            'state'=>$row['state'],'reviewed_at'=>$reviewed,'change_at'=>$changed,
            'before'=>$before,'after'=>$after,'scope'=>'observational_not_causal'];
    }
    return $result;
}


/** Saved private associations use canonical owner & Agent/Chat source reads. */
function vp3_marketing_workspace_linked_creative_v1(PDO $pdo,int $owner,array $scope,array $campaign): array
{
    if(empty($campaign['outcomes']['followthrough']) || !function_exists('schema_metadata_table_exists'))return [];
    if(!schema_metadata_table_exists($pdo,'campaign_creative_assets')
        ||!schema_metadata_table_exists($pdo,'agent_product_launches_v1')
        ||!schema_metadata_table_exists($pdo,'agent_commerce_marketing_drafts_v1'))return [];
    if(!function_exists('vp3_marketing_recommendation_links_v1'))require_once __DIR__.'/initiative-marketing-recommendation-links-v1.php';
    try{return vp3_marketing_recommendation_links_v1($pdo,$owner,$scope,(int)$campaign['id'],
        (array)($campaign['outcomes']??[]));}
    catch(DomainException $error){
        if(in_array($error->getCode(),[403,404,409,503],true))return [];
        throw $error;
    }
}
