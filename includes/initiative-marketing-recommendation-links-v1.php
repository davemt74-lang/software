<?php
declare(strict_types=1);

require_once __DIR__.'/agent-marketing-outcome-learning-v1.php';
require_once __DIR__.'/agent-marketing-experiment-proposal-v1.php';

/**
 * Phase 2B.6D: owner-approved private recommendation-to-product creative linkage.
 * Reuses Campaigns' existing private campaign_creative_assets store. This role is
 * never a Campaign landing asset, public publication, Reward or send instruction.
 */
const VP3_MARKETING_RECOMMENDATION_LINK_ROLE_V1='private_marketing_plan_review_v1';
const VP3_MARKETING_RECOMMENDATION_LINK_CONTRACT_V1='vp3.marketing-recommendation-link.v1';

function vp3_marketing_recommendation_id_v1(mixed $id,string $label): int
{
    if(!is_int($id)||$id<1||$id>9007199254740991)throw new DomainException('Choose an exact '.$label.'.',422);
    return $id;
}

function vp3_marketing_recommendation_scope_v1(PDO $pdo,int $owner,array $scope): void
{
    if($owner<1||(int)($scope['user']['id']??0)!==$owner
        ||!function_exists('has_permission')||!has_permission('account.access',$scope['user']))
        throw new DomainException('Marketing owner access is required.',403);
    if(!function_exists('vp3_coordination_marketing_owner_v1'))require_once __DIR__.'/agent-coordination-marketing-v1.php';
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner)
        throw new DomainException('Marketing Agent or Chat access changed.',403);
    if(!function_exists('schema_metadata_table_exists'))require_once __DIR__.'/schema-metadata.php';
    if(!schema_metadata_table_exists($pdo,'campaign_creative_assets')
        ||!schema_metadata_table_exists($pdo,'agent_product_launches_v1')
        ||!schema_metadata_table_exists($pdo,'agent_commerce_marketing_drafts_v1'))
        throw new DomainException('Run Database Upgrade to link saved Marketing creative.',503);
}

/** Canonical Campaign and audited owner-accepted recommendation are the authority. */
function vp3_marketing_recommendation_campaign_v1(PDO $pdo,int $owner,array $scope,int $campaignId,
    int $recommendationId,bool $write=false,bool $lock=false): array
{
    vp3_marketing_recommendation_scope_v1($pdo,$owner,$scope);
    if(!function_exists('campaigns_rewards_campaign_platform_v100'))require_once __DIR__.'/campaigns-rewards-domain-v100.php';
    $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,$lock);
    $merchant=(int)($campaign['merchant_id']??0);
    if(!$campaign||$merchant<1||(int)($campaign['merchant_owner_user_id']??0)!==$owner
        ||($campaign['merchant_status']??null)!=='active'||!empty($campaign['merchant_sandbox_mode'])
        ||($campaign['environment']??null)!=='production'
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.view')
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'analytics.view')
        ||($write&&!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.edit')))
        throw new DomainException('This Campaign review is unavailable.',403);
    if(!function_exists('vp3_marketing_campaign_outcomes_v1'))require_once __DIR__.'/agent-marketing-outcomes-v1.php';
    $outcomes=vp3_marketing_campaign_outcomes_v1($pdo,$owner,$merchant,$campaignId,
        new DateTimeImmutable('now',new DateTimeZone('UTC')));
    $accepted=null;
    foreach(($outcomes['followthrough']??[]) as $record){
        if(($record['recommendation_id']??null)===$recommendationId){$accepted=$record;break;}
    }
    if($accepted===null)throw new DomainException('The owner-accepted Campaign review is no longer verified.',409);
    if($write&&!in_array($campaign['status'],['draft','scheduled','active','paused'],true))
        throw new DomainException('This Campaign is not editable for creative preparation.',409);
    return ['campaign'=>$campaign,'merchant'=>$merchant,'accepted'=>$accepted,'outcomes'=>$outcomes];
}

/** Verified private product plan in an owner Chat of the same Agent. */
function vp3_marketing_recommendation_plan_v1(PDO $pdo,int $owner,array $scope,int $productId): array
{
    if(!function_exists('initiative_marketing_plans_query_v1'))require_once __DIR__.'/initiative-marketing-plans-v1.php';
    $q=$pdo->prepare(initiative_marketing_plans_query_v1().' AND l.product_id=? LIMIT 1');
    $q->execute([$owner,$scope['agent_id'],$productId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    $draft=$row['draft_key']??null;
    if(!$row||!is_string($draft)||preg_match('/\A[a-f0-9]{64}\z/D',$draft)!==1)
        throw new DomainException('Choose an existing saved private Marketing draft.',409);
    $view=initiative_marketing_plan_view_v1($pdo,$owner,$row);
    if(($view['stage']??null)==='needs_attention'||($view['needs_attention']??false)===true)
        throw new DomainException('The saved product Marketing plan needs review in its original Chat.',409);
    $creative=$row['creative_id']??null;
    if($creative!==null&&(!is_string($creative)||preg_match('/\A[a-f0-9]{32}\z/D',$creative)!==1))
        throw new DomainException('The saved creative revision changed.',409);
    $proof=hash('sha256',json_encode(['marketing.plan.v1',$owner,(int)$row['agent_id'],
        (int)$row['conversation_id'],$productId,$draft,$creative,$view['stage']],JSON_THROW_ON_ERROR));
    return ['product_id'=>$productId,'title'=>mb_substr((string)$view['product_title'],0,140),
        'stage'=>$view['stage'],'revision'=>$proof,'draft_key'=>$draft,
        'creative_id'=>$creative,'agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id']];
}

function vp3_marketing_recommendation_choices_v1(PDO $pdo,int $owner,array $scope,int $campaignId,
    int $recommendationId): array
{
    $checked=vp3_marketing_recommendation_campaign_v1($pdo,$owner,$scope,$campaignId,$recommendationId,true);
    $q=$pdo->prepare(initiative_marketing_plans_query_v1()." AND l.draft_key IS NOT NULL ORDER BY l.product_id DESC LIMIT 13");
    $q->execute([$owner,$scope['agent_id']]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $options=[];
    foreach(array_slice($rows,0,12) as $row){
        try{$plan=vp3_marketing_recommendation_plan_v1($pdo,$owner,$scope,(int)$row['product_id']);}
        catch(DomainException $e){if(!in_array($e->getCode(),[403,404,409],true))throw $e;continue;}
        $options[]=array_intersect_key($plan,array_flip(['product_id','title','stage','revision']));
    }
    vp3_marketing_recommendation_campaign_v1($pdo,$owner,$scope,$campaignId,$recommendationId,true);
    return ['work_choices'=>['campaign_id'=>$campaignId,'recommendation_id'=>$recommendationId,
        'campaign_version'=>(int)$checked['campaign']['current_version_no'],
        'plans'=>$options,'limited'=>count($rows)>12,
        'disclaimer'=>'Private association only. Linking or approving a draft does not publish, send, or apply a Campaign edit.']];
}

/** Reject unexpected metadata or rebound Chat instead of treating it as a source. */
function vp3_marketing_recommendation_record_v1(array $row,int $owner,int $campaign): ?array
{
    if((int)($row['created_by_user_id']??0)!==$owner||(int)($row['campaign_id']??0)!==$campaign
        ||($row['asset_role']??null)!==VP3_MARKETING_RECOMMENDATION_LINK_ROLE_V1
        ||(int)($row['is_active']??0)!==1||!is_string($row['content_json']??null)
        ||strlen($row['content_json'])>4096)return null;
    try{$record=json_decode($row['content_json'],true,12,JSON_THROW_ON_ERROR);}catch(JsonException){return null;}
    if(!is_array($record)||($record['contract']??null)!==VP3_MARKETING_RECOMMENDATION_LINK_CONTRACT_V1
        ||!is_int($record['recommendation_id']??null)||$record['recommendation_id']<1
        ||!is_int($record['product_id']??null)||$record['product_id']<1
        ||!is_int($record['campaign_version']??null)||$record['campaign_version']<0
        ||!is_int($record['agent_id']??null)||$record['agent_id']<0
        ||!is_int($record['conversation_id']??null)||$record['conversation_id']<1
        ||!is_string($record['draft_key']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$record['draft_key'])
        ||!in_array($record['review_state']??null,['linked','approved_for_use'],true)
        ||$row['media_ref']!=='marketing-plan:'.$record['product_id'])return null;
    return $record;
}

/** Current owner-authorized source facts only; a stale link never proves creative use. */
function vp3_marketing_recommendation_links_v1(PDO $pdo,int $owner,array $scope,int $campaignId,
    array $outcomes): array
{
    vp3_marketing_recommendation_scope_v1($pdo,$owner,$scope);
    $accepted=[];
    foreach($outcomes['followthrough']??[] as $record){
        if(is_int($record['recommendation_id']??null))$accepted[$record['recommendation_id']]=$record;
    }
    if(!$accepted)return [];
    $q=$pdo->prepare('SELECT id,campaign_id,created_by_user_id,asset_role,media_ref,content_json,is_active
        FROM campaign_creative_assets WHERE campaign_id=? AND created_by_user_id=? AND asset_role=? AND is_active=1
        ORDER BY id DESC LIMIT 13');
    $q->execute([$campaignId,$owner,VP3_MARKETING_RECOMMENDATION_LINK_ROLE_V1]);
    $out=[];$seen=[];
    foreach(array_slice($q->fetchAll(PDO::FETCH_ASSOC),0,12) as $row){
        $record=vp3_marketing_recommendation_record_v1($row,$owner,$campaignId);
        $id=$record['recommendation_id']??null;
        if($record===null||!isset($accepted[$id])||isset($seen[$id]))continue;
        $seen[$id]=true;
        $state='source_changed';$title='Saved Marketing draft';$stage='needs_attention';
        if($record['agent_id']===$scope['agent_id']){
            try{
                $plan=vp3_marketing_recommendation_plan_v1($pdo,$owner,$scope,$record['product_id']);
                if(hash_equals($record['draft_key'],$plan['draft_key'])){
                    $state=$record['review_state'];$title=$plan['title'];$stage=$plan['stage'];
                }
            }catch(DomainException $e){if(!in_array($e->getCode(),[403,404,409],true))throw $e;}
        }
        $out[]=['id'=>(int)$row['id'],'recommendation_id'=>$id,'product_id'=>$record['product_id'],
            'product_title'=>$title,'plan_stage'=>$stage,'review_state'=>$state,
            'campaign_version'=>$record['campaign_version'],
            'evidence_state'=>$accepted[$id]['state']??'unavailable',
            'approved_at'=>$record['review_state']==='approved_for_use' ? ($record['approved_at']??null):null,
            'implementation'=>'not_verified'];
        if(count($out)>=8)break;
    }
    vp3_marketing_recommendation_scope_v1($pdo,$owner,$scope);
    return $out;
}

function vp3_marketing_recommendation_write_v1(PDO $pdo,int $owner,array $scope,array $body): array
{
    vp3_marketing_recommendation_scope_v1($pdo,$owner,$scope);
    $action=(string)$body['action'];
    $campaign=vp3_marketing_recommendation_id_v1($body['campaign_id']??null,'Campaign');
    $recommendation=vp3_marketing_recommendation_id_v1($body['recommendation_id']??null,'review');
    $version=$body['expected_campaign_version']??null;
    if(!is_int($version)||$version<0||$version>9007199254740991)
        throw new DomainException('Refresh the saved Campaign revision.',422);
    $linkId=$action==='marketing_work_link'?null:vp3_marketing_recommendation_id_v1($body['link_id']??null,'creative association');
    $product=$action==='marketing_work_link' ? vp3_marketing_recommendation_id_v1($body['product_id']??null,'product') : null;
    $expected=$body['expected_plan_revision']??null;
    if($action==='marketing_work_link'&&(!is_string($expected)||!preg_match('/\A[a-f0-9]{64}\z/D',$expected)))
        throw new DomainException('Refresh the selected Marketing plan.',422);
    if($pdo->inTransaction())throw new DomainException('Finish the existing Campaign review first.',409);
    $pdo->beginTransaction();
    try{
        $checked=vp3_marketing_recommendation_campaign_v1($pdo,$owner,$scope,$campaign,$recommendation,true,true);
        if((int)$checked['campaign']['current_version_no']!==$version)
            throw new DomainException('Campaign changed. Refresh the creative review.',409);
        $q=$pdo->prepare('SELECT id,campaign_id,created_by_user_id,asset_role,media_ref,content_json,is_active
            FROM campaign_creative_assets WHERE campaign_id=? AND created_by_user_id=? AND asset_role=? AND is_active=1
            ORDER BY id DESC LIMIT 13'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$campaign,$owner,VP3_MARKETING_RECOMMENDATION_LINK_ROLE_V1]);
        $links=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $link=vp3_marketing_recommendation_record_v1($row,$owner,$campaign);
            if($link!==null&&$link['recommendation_id']===$recommendation)$links[]=['row'=>$row,'record'=>$link];
        }
        if($action==='marketing_work_link'){
            $plan=vp3_marketing_recommendation_plan_v1($pdo,$owner,$scope,$product);
            if(!hash_equals($expected,$plan['revision']))throw new DomainException('The saved plan changed. Review it again.',409);
            $record=['contract'=>VP3_MARKETING_RECOMMENDATION_LINK_CONTRACT_V1,
                'recommendation_id'=>$recommendation,'product_id'=>$product,'draft_key'=>$plan['draft_key'],
                'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],
                'campaign_version'=>$version,'review_state'=>'linked'];
            if(count($links)===1 && array_intersect_key($links[0]['record'],$record)===$record){
                $resultId=(int)$links[0]['row']['id'];
            }else{
                foreach($links as $link)$pdo->prepare('UPDATE campaign_creative_assets SET is_active=0 WHERE id=? AND created_by_user_id=?')
                    ->execute([(int)$link['row']['id'],$owner]);
                $pdo->prepare('INSERT INTO campaign_creative_assets
                    (campaign_id,asset_role,media_ref,content_json,created_by_user_id) VALUES(?,?,?,?,?)')
                    ->execute([$campaign,VP3_MARKETING_RECOMMENDATION_LINK_ROLE_V1,
                        'marketing-plan:'.$product,json_encode($record,JSON_THROW_ON_ERROR),$owner]);
                $resultId=(int)$pdo->lastInsertId();
            }
        }else{
            $match=null;
            foreach($links as $link)if((int)$link['row']['id']===$linkId)$match=$link;
            if($match===null)throw new DomainException('The linked creative changed. Refresh Marketing.',409);
            $record=$match['record'];
            // Unlinking a private association remains possible even after its
            // original draft was replaced or deleted. Approval never does.
            if($action==='marketing_work_approve'){
                $plan=vp3_marketing_recommendation_plan_v1($pdo,$owner,$scope,$record['product_id']);
                if($record['agent_id']!==$scope['agent_id']||!hash_equals($record['draft_key'],$plan['draft_key'])
                    ||$record['campaign_version']!==$version)
                    throw new DomainException('Creative or Campaign source changed.',409);
                if($record['review_state']!=='approved_for_use'){
                    $record['review_state']='approved_for_use';$record['approved_at']=gmdate(DATE_ATOM);
                    $pdo->prepare('UPDATE campaign_creative_assets SET content_json=? WHERE id=? AND campaign_id=? AND created_by_user_id=? AND is_active=1')
                        ->execute([json_encode($record,JSON_THROW_ON_ERROR),$linkId,$campaign,$owner]);
                }
            }else{
                $pdo->prepare('UPDATE campaign_creative_assets SET is_active=0 WHERE id=? AND campaign_id=? AND created_by_user_id=?')
                    ->execute([$linkId,$campaign,$owner]);
            }
            $resultId=$linkId;
        }
        vp3_marketing_recommendation_scope_v1($pdo,$owner,$scope);
        $pdo->commit();
        return ['work_saved'=>['id'=>$resultId,'state'=>$action==='marketing_work_unlink'?'unlinked':
            ($action==='marketing_work_approve'?'approved_for_use':'linked'),
            'published'=>false,'campaign_mutated'=>false]];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function vp3_marketing_recommendation_action_v1(PDO $pdo,int $owner,array $scope,array $body): array
{
    $action=$body['action']??null;
    if(!in_array($action,['marketing_work_choices','marketing_work_link','marketing_work_approve','marketing_work_unlink'],true))
        throw new DomainException('Choose a Marketing creative review action.',422);
    $campaign=vp3_marketing_recommendation_id_v1($body['campaign_id']??null,'Campaign');
    $recommendation=vp3_marketing_recommendation_id_v1($body['recommendation_id']??null,'review');
    return $action==='marketing_work_choices'
        ?vp3_marketing_recommendation_choices_v1($pdo,$owner,$scope,$campaign,$recommendation)
        :vp3_marketing_recommendation_write_v1($pdo,$owner,$scope,$body);
}

/**
 * A saved association re-enters the normal Marketing specialist loop only at
 * meaningful review, owner-approval, or verified observation milestones.
 * The source revision is semantic: routine scanner timestamps do not trigger posts.
 */
function vp3_marketing_recommendation_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    $out=['items'=>[],'coverage'=>'unconfigured'];
    $owner=vp3_coordination_marketing_owner_v1($pdo,$scope);
    if($owner===null)return $out;
    foreach(['campaign_creative_assets','campaigns','merchant_accounts','agent_product_launches_v1',
        'agent_commerce_marketing_drafts_v1'] as $table){
        if(!schema_metadata_table_exists($pdo,$table))return $out;
    }
    if(!function_exists('vp3_marketing_campaign_json_v1'))require_once __DIR__.'/agent-marketing-outcomes-v1.php';
    $ids=[];
    foreach($refs??[] as $ref){
        if(preg_match('/\Acampaign-work:([1-9][0-9]{0,17})\z/D',(string)$ref,$m))$ids[]=(int)$m[1];
    }
    if($refs!==null&&!$ids)return $out;
    $filter='';$params=[$owner,VP3_MARKETING_RECOMMENDATION_LINK_ROLE_V1];
    if($ids){
        $json=vp3_marketing_campaign_json_v1($pdo,'a.content_json','$.recommendation_id');
        $filter=' AND CAST('.$json.' AS UNSIGNED) IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        array_push($params,...$ids);
    }
    $q=$pdo->prepare('SELECT a.id,a.campaign_id,a.created_by_user_id,a.asset_role,a.media_ref,a.content_json,a.is_active
        FROM campaign_creative_assets a JOIN campaigns c ON c.id=a.campaign_id
        JOIN merchant_accounts m ON m.id=c.merchant_id
        WHERE a.created_by_user_id=? AND a.asset_role=? AND a.is_active=1
          AND m.owner_user_id=a.created_by_user_id AND m.status=\'active\' AND m.sandbox_mode=0
          AND c.environment=\'production\' '.$filter.' ORDER BY a.id DESC LIMIT '.($refs===null?'13':'25'));
    $q->execute($params);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $out['coverage']=($refs===null&&count($rows)>12)?'limited':'ready';
    $seen=[];
    foreach(array_slice($rows,0,$refs===null?12:24) as $row){
        $campaign=(int)$row['campaign_id'];
        $record=vp3_marketing_recommendation_record_v1($row,$owner,$campaign);
        if($record===null||isset($seen[$record['recommendation_id']]))continue;
        $seen[$record['recommendation_id']]=true;
        try{
            $checked=vp3_marketing_recommendation_campaign_v1($pdo,$owner,$scope,$campaign,$record['recommendation_id']);
            $links=vp3_marketing_recommendation_links_v1($pdo,$owner,$scope,$campaign,$checked['outcomes']);
            $link=null;
            foreach($links as $candidate){if($candidate['id']===(int)$row['id']){$link=$candidate;break;}}
            if($link===null||$link['review_state']==='source_changed'||$link['campaign_version']!==
                (int)$checked['campaign']['current_version_no'])continue;
            $state=$link['review_state'];$stage=$link['plan_stage'];
            $followthrough=$link['evidence_state'];
            $learning=['state'=>'unavailable'];$brief=null;
            if($state==='approved_for_use' && $followthrough==='observed'){
                $learning=vp3_marketing_outcome_learning_v1($followthrough,
                    is_array($checked['accepted']['before']??null)?$checked['accepted']['before']:null,
                    is_array($checked['accepted']['after']??null)?$checked['accepted']['after']:null);
                $brief=vp3_marketing_outcome_learning_brief_v1($learning);
                $experiment=vp3_marketing_experiment_proposal_v1($campaign,
                    (int)$checked['campaign']['current_version_no'],$checked['accepted']);
                $trigger='creative_results';
                $summary=$brief['summary']??'A separately audited post-Campaign observation window is available for review. It does not prove the linked creative caused the change.';
                $next=$brief['next_step']??'Compare current results and review which approved creative was actually used.';
                if($experiment!==null){
                    $next='Review the source-bound, owner-only controlled experiment design in /marketing. '.
                        'A native, audited assignment path is still required before any test can run.';
                    $question='Would you like to review the controlled experiment proposal in Marketing Analytics?';
                }else $question='';
            }elseif($state==='approved_for_use'){
                $trigger='creative_approved';
                $summary='The saved product creative was explicitly approved for private use. No Campaign application or publication has been verified.';
                $next='Review implementation separately in the Campaign Manager before making live changes.';
                $question='';
            }elseif(in_array($stage,['creative_ready','publication_review','published'],true)){
                $trigger='creative_review_ready';
                $summary='A private product Marketing creative linked to an accepted Campaign recommendation is ready for your review.';
                $next='Review the saved creative in Marketing and explicitly approve it for private use if appropriate.';
                $question='Would you like to review the linked creative before it is used in this Campaign?';
            }else continue;
            $ref='marketing:campaign-work:'.$record['recommendation_id'];
            if(!vp3_coordination_source_ref_valid_v1($ref))continue;
            $businessId=$checked['merchant'];
            $url=url('/campaigns.php?merchant='.$businessId.'&edit_campaign='.$campaign);
            $title=vp3_coordination_source_item_v1($ref,'marketing',$checked['campaign']['name'].' · Saved creative follow-through',
                $summary,$next,$question,$url,'open','normal',[
                    $row['id'],$campaign,$record['recommendation_id'],$record['draft_key'],
                    $link['review_state'],$stage,(int)$checked['campaign']['current_version_no'],
                    $trigger,$trigger==='creative_results' ? ($checked['accepted']['after']['window_end']??'none'):'',
                    $brief===null?'no_rate_learning':[$learning['contract'],$learning['direction'],
                        $learning['prior_rate_bps'],$learning['later_rate_bps']],
                    $trigger==='creative_results' ? ($experiment['source_revision']??'no_experiment') : 'no_experiment',
                ])+['trigger'=>$trigger];
            $out['items'][$ref]=$title;
        }catch(DomainException $error){
            if(!in_array($error->getCode(),[403,404,409,503],true))throw $error;
            $out['coverage']='limited';
        }
    }
    vp3_marketing_recommendation_scope_v1($pdo,$owner,$scope);
    return $out;
}
