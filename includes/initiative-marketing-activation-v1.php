<?php
declare(strict_types=1);

/**
 * Marketing Chat activation V2B.4.
 * Read and approve an exact Campaign lifecycle transition using the existing
 * Campaigns & Rewards authority, readiness and idempotency ledger.
 * Never adds a parallel scheduler, CRM, reward issuer or messaging path.
 */

function vp3_marketing_activation_scope_v1(PDO $pdo,int $owner,array $scope): void
{
    if($owner<1||(int)($scope['user']['id']??0)!==$owner
        ||!function_exists('has_permission')||!has_permission('account.access',$scope['user'])) {
        throw new DomainException('Current Marketing owner access is required.',403);
    }
    if(!function_exists('vp3_coordination_marketing_owner_v1')) {
        require_once __DIR__.'/agent-coordination-marketing-v1.php';
    }
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner) {
        throw new DomainException('Your Marketing account or Chat access changed.',403);
    }
    if(!function_exists('campaigns_rewards_platform_schema_ready_v100')
        || !campaigns_rewards_platform_schema_ready_v100($pdo)) {
        throw new DomainException('Campaigns & Rewards storage is not available.',503);
    }
}

function vp3_marketing_activation_id_v1(mixed $value): int
{
    if(!is_int($value)||$value<1||$value>9007199254740991) {
        throw new DomainException('Choose an exact saved Campaign.',422);
    }
    return $value;
}

/** Canonical Merchant and Campaign launch/publish capabilities, never Agent self-authorization. */
function vp3_marketing_activation_campaign_v1(PDO $pdo,int $owner,int $campaignId,bool $lock=false): array
{
    $row=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,$lock);
    $merchant=(int)($row['merchant_id']??0);
    if(!$row||$merchant<1||(int)($row['merchant_owner_user_id']??0)!==$owner
        ||($row['merchant_status']??'')!=='active'||!empty($row['merchant_sandbox_mode'])
        ||($row['environment']??'')!=='production'
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.view',$lock)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.launch',$lock)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.publish',$lock)) {
        throw new DomainException('Current Campaign activation permission is unavailable.',403);
    }
    if(!in_array($row['status']??null,['draft','scheduled','paused'],true)) {
        throw new DomainException('This Campaign is no longer eligible for activation review.',409);
    }
    return $row;
}

/** Bounded evidence. Includes readiness details and whether activation makes a landing page available. */
function vp3_marketing_activation_evidence_v1(PDO $pdo,int $owner,array $scope,int $campaignId,bool $lock=false): array
{
    vp3_marketing_activation_scope_v1($pdo,$owner,$scope);
    $campaign=vp3_marketing_activation_campaign_v1($pdo,$owner,$campaignId,$lock);
    $merchant=(int)$campaign['merchant_id'];
    $stmt=$pdo->prepare('SELECT visibility,is_published,headline,cta_label,updated_at
        FROM campaign_landing_pages WHERE campaign_id=? LIMIT 1');
    $stmt->execute([$campaignId]);
    $landing=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!function_exists('vp3_marketing_campaign_readiness_v1')) {
        require_once __DIR__.'/agent-marketing-readiness-v1.php';
    }
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $readiness=vp3_marketing_campaign_readiness_v1($pdo,$owner,$merchant,$campaignId,$now);
    $rules=[];
    $checks=[];
    foreach((array)($readiness['checks']??[]) as $key=>$check) {
        if(!in_array($key,['location','reward','claim_code','audience'],true)||!is_array($check))continue;
        $state=(string)($check['state']??'limited');
        if(!in_array($state,['ready','limited','missing'],true))$state='limited';
        $detail=trim((string)($check['detail']??''));
        $checks[]=['name'=>$key,'state'=>$state,
            'detail'=>mb_substr($detail!==''?$detail:'Review this Campaign section.',0,260)];
    }
    $state=$readiness['state']??'unavailable';
    if(!in_array($state,['ready','limited','needs_setup'],true))$state='unavailable';
    $starts=$campaign['starts_at']??null;
    $ends=$campaign['ends_at']??null;
    $nowText=$now->format('Y-m-d H:i:s');
    if(is_string($starts)&&$starts!==''&&$starts>$nowText) {
        $rules[]='Campaign start time is in the future; leave it scheduled until it begins.';
    }
    if(is_string($ends)&&$ends!==''&&$ends<=$nowText) {
        $rules[]='Campaign end time has already passed. Update the schedule.';
    }
    $visibility=(string)($landing['visibility']??'');
    if(!in_array($visibility,['private','unlisted','profile_public'],true)) {
        $rules[]='The saved Campaign landing page is not verified.';
        $visibility='unavailable';
    }
    if($state!=='ready')$rules[]='Campaign readiness is '.$state.'. Review the listed setup requirements.';
    try {
        if(!function_exists('campaigns_rewards_validate_campaign_activation_v118')) {
            require_once __DIR__.'/campaigns-rewards-v118.php';
        }
        campaigns_rewards_validate_campaign_activation_v118($pdo,$campaign);
    } catch (DomainException|RuntimeException $error) {
        if($error instanceof PDOException)throw $error;
        $rules[]='Campaign Type activation requirements need review in Campaigns & Rewards.';
    }
    $rewardIds=campaigns_rewards_campaign_reward_ids_v118($pdo,$campaignId);
    if(!is_array($rewardIds))$rewardIds=[];
    $rewards=[];
    foreach($rewardIds as $id)if((int)$id>0)$rewards[]=(int)$id;
    sort($rewards,SORT_NUMERIC);
    $fingerprint=[
        'marketing-activation-v2b4',$owner,$merchant,$campaignId,
        (string)$campaign['status'],(int)$campaign['current_version_no'],
        (string)($campaign['updated_at']??''),(string)($campaign['name']??''),
        (string)($campaign['slug']??''),(string)($campaign['campaign_type_key']??''),
        (string)($campaign['starts_at']??''),(string)($campaign['ends_at']??''),
        $visibility,(int)($landing['is_published']??0),
        (string)($landing['updated_at']??''),(string)($landing['headline']??''),
        (string)($landing['cta_label']??''),$rewards,$state,$checks,$rules,
    ];
    $revision=hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    vp3_marketing_activation_scope_v1($pdo,$owner,$scope);
    $again=vp3_marketing_activation_campaign_v1($pdo,$owner,$campaignId,$lock);
    if((int)$again['current_version_no']!==(int)$campaign['current_version_no']
        ||(string)$again['status']!==(string)$campaign['status']
        ||(string)($again['updated_at']??'')!==(string)($campaign['updated_at']??'')) {
        throw new DomainException('Campaign changed during review. Reload current details.',409);
    }
    $summary=[
        'id'=>$campaignId,'merchant_id'=>$merchant,'name'=>mb_substr((string)($campaign['name']??''),0,160),
        'status'=>(string)$campaign['status'],'version'=>(int)$campaign['current_version_no'],
        'type'=>mb_substr((string)($campaign['campaign_type_key']??''),0,90),
        'starts_at'=>is_string($starts)?$starts:null,'ends_at'=>is_string($ends)?$ends:null,
        'readiness'=>$state,'checks'=>$checks,'blockers'=>array_slice($rules,0,6),
        'reward_count'=>count($rewards),'landing_visibility'=>$visibility,
        'landing_published_on_activation'=>$visibility!=='private'&&$visibility!=='unavailable',
        'profile_publication_is_separate'=>true,
        'may_enable_configured_automations'=>true,
        'will_send_messages_directly'=>false,
        'will_issue_rewards_directly'=>false,
        'can_activate'=>$state==='ready'&&$rules===[],
        'revision'=>$revision,
    ];
    return $summary;
}

function vp3_marketing_activation_intent_v1(int $owner,array $scope,int $campaignId,string $revision,string $nonce): string
{
    return hash('sha256',json_encode([
        'vp3.marketing.activation.v2b4',$owner,
        (int)$scope['agent_id'],(int)$scope['conversation_id'],
        $campaignId,$revision,$nonce,'active'
    ],JSON_THROW_ON_ERROR));
}

function vp3_marketing_activation_action_v1(PDO $pdo,int $owner,array $scope,array $body,string $reviewKey): array
{
    vp3_marketing_activation_scope_v1($pdo,$owner,$scope);
    $action=$body['action']??'';
    if(!in_array($action,['marketing_activation_open','marketing_activation_preview','marketing_activation_confirm'],true)) {
        throw new DomainException('Choose a Campaign activation action.',422);
    }
    $campaignId=vp3_marketing_activation_id_v1($body['campaign_id']??null);
    if($action==='marketing_activation_open') {
        return ['activation'=>vp3_marketing_activation_evidence_v1($pdo,$owner,$scope,$campaignId)];
    }
    $revision=$body['revision']??null;$nonce=$body['request_key']??null;
    if(!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision)
        ||!is_string($nonce)||!preg_match('/\A[a-f0-9]{32}\z/D',$nonce)) {
        throw new DomainException('Reopen the exact Campaign and review its current state.',422);
    }
    $intent=vp3_marketing_activation_intent_v1($owner,$scope,$campaignId,$revision,$nonce);
    if($action==='marketing_activation_preview') {
        $summary=vp3_marketing_activation_evidence_v1($pdo,$owner,$scope,$campaignId);
        if(!hash_equals($summary['revision'],$revision)) {
            throw new DomainException('The Campaign changed. Reopen its activation review.',409);
        }
        if(!$summary['can_activate'])return ['activation'=>$summary,'review_token'=>null];
        if($reviewKey==='')throw new DomainException('Review service unavailable.',503);
        return ['activation'=>$summary,
            'review_token'=>initiative_workbench_review_token_v1($reviewKey,$intent,time()+600)];
    }
    initiative_workbench_confirm_v1($body,$reviewKey,$intent,time());
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try {
        vp3_marketing_activation_scope_v1($pdo,$owner,$scope);
        // The signed receipt is checked first: a repeated browser request cannot
        // activate twice, even though the first request changed the Campaign status.
        $row=campaigns_rewards_campaign_platform_v100($pdo,$campaignId,true);
        $merchant=(int)($row['merchant_id']??0);
        if(!$row||$merchant<1||(int)$row['merchant_owner_user_id']!==$owner
            ||$row['merchant_status']!=='active'||!empty($row['merchant_sandbox_mode'])
            ||$row['environment']!=='production'
            ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.view',true)
            ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.launch',true)
            ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.publish',true)) {
            throw new DomainException('Campaign activation access changed.',403);
        }
        $receipt=campaigns_rewards_idempotency_begin_v100($pdo,$merchant,'marketing.chat_activation.v2b4',
            $owner.':'.$nonce,['intent'=>$intent],true);
        if(empty($receipt['new'])) {
            if(($receipt['status']??null)!=='completed'
                ||($receipt['result_ref_type']??null)!=='campaign'
                ||(int)($receipt['result_ref_id']??0)!==$campaignId
                ||(string)$row['status']!=='active') {
                throw new DomainException('The prior Campaign activation could not be verified.',409);
            }
            $activated=$row;
            $replayed=true;
        } else {
            $summary=vp3_marketing_activation_evidence_v1($pdo,$owner,$scope,$campaignId,true);
            if(!hash_equals($summary['revision'],$revision)||!$summary['can_activate']) {
                throw new DomainException('Campaign readiness or version changed. Review again before activating.',409);
            }
            $activated=campaigns_rewards_set_campaign_lifecycle_v100($pdo,$campaignId,$owner,'active');
            if((int)($activated['id']??0)!==$campaignId
                ||(string)($activated['status']??'')!=='active'
                ||(int)($activated['merchant_owner_user_id']??0)!==$owner) {
                throw new DomainException('Canonical Campaign activation could not be verified.',409);
            }
            campaigns_rewards_idempotency_complete_v100($pdo,(int)$receipt['id'],'campaign',$campaignId);
            $replayed=false;
        }
        vp3_marketing_activation_scope_v1($pdo,$owner,$scope);
        if($owns)$pdo->commit();
        return ['activated'=>true,'campaign'=>[
            'id'=>$campaignId,'status'=>'active',
            'name'=>mb_substr((string)($activated['name']??''),0,160)
        ],'replayed'=>$replayed,'outbound_sent_by_this_action'=>false];
    }catch(Throwable $error){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
