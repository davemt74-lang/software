<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-onboarding-skill-v100.php';
require_once __DIR__.'/onboarding-intelligence.php';
require_once __DIR__.'/profile-commerce-v900.php';

/** Read-only evidence over canonical setup and Commerce records; never an authority or task store. */
const VP3_INITIATIVE_READINESS_V1='initiative-readiness-v1';

function vp3_initiative_readiness_text_v1(mixed $value,int $limit=1000): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',strip_tags((string)$value))??''),0,$limit,'…');
}

function vp3_initiative_readiness_row_v1(string $ref,string $kind,string $title,string $detail,string $path,array $state): array
{
    return ['ref'=>$ref,'kind'=>$kind,'title'=>vp3_initiative_readiness_text_v1($title,170),
        'detail'=>vp3_initiative_readiness_text_v1($detail,1500),'url'=>url($path),
        'readiness'=>$state,'revision'=>hash('sha256',json_encode([VP3_INITIATIVE_READINESS_V1,$ref,$state],JSON_THROW_ON_ERROR))];
}

function vp3_initiative_readiness_selected_v1(?array $selectedRefs,string $prefix): ?array
{
    return $selectedRefs===null?null:array_values(array_filter($selectedRefs,
        static fn($ref):bool=>is_string($ref)&&str_starts_with($ref,$prefix.':')));
}

/** Essential setup uses the same canonical skill projection without invoking the provisioning owner-state path. */
function vp3_initiative_readiness_account_v1(PDO $pdo,array $user,array $principal,?array $selected): array
{
    $owner=(int)$user['id'];$ref='account-readiness:'.$owner;
    if($selected!==null&&!in_array($ref,$selected,true))return ['ready',[]];
    if(!function_exists('profile_for_user')||!function_exists('user_agents_list_v236')
        ||!function_exists('table_exists')||!table_exists('user_profiles')||!table_exists('user_agents')
        ||!onboarding_intelligence_schema_ready($pdo))return ['unavailable',[]];
    if(($principal['kind']??'')==='user_agent'&&(!function_exists('user_data_policy_can_use_v236')
        ||!user_data_policy_can_use_v236($pdo,$principal,$owner,'profile','*')))return ['forbidden',[]];
    $preferences=onboarding_intelligence_preferences($pdo,$owner);
    if(!empty($preferences['onboarding_dismissed']))return ['ready',[]];
    $profile=profile_for_user($pdo,$owner,false)??[];
    $q=$pdo->prepare('SELECT id FROM user_agents WHERE owner_user_id=? AND is_active=1 ORDER BY id LIMIT 1');
    $q->execute([$owner]);
    $setup=['agent_named'=>$q->fetchColumn()!==false,'profile_username'=>trim((string)($profile['username']??''))!==''];
    $skill=vp3_agent_onboarding_skill_state_v100(['user'=>['id'=>$owner],'setup'=>$setup]);
    if(!empty($skill['task']['required_complete']))return ['ready',[]];
    $missing=[];
    foreach($skill['required'] as $item)if(empty($item['verified']))$missing[]=$item['key'];
    $state=['owner_user_id'=>$owner,'missing_fields'=>$missing,'next_step'=>$skill['task']['next']['key']??null,
        'requires_owner_input'=>true,'auto_provision'=>false];
    $row=vp3_initiative_readiness_row_v1($ref,'account_readiness','Account setup',
        'The canonical onboarding skill has unfinished required identity setup. Ask for the missing owner choices; do not invent or save them.',
        '/chat.php?setup=1',$state);
    return ['ready',[$ref=>$row]];
}

/** Booking readiness is relevant only when the owner selected that workflow; an empty calendar is not a setup failure. */
function vp3_initiative_readiness_scheduling_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,?array $selected): array
{
    $owner=(int)$user['id'];$agent=max(0,(int)($principal['agent_id']??0));$ref='scheduling-readiness:'.$owner.':'.$agent;
    if($selected!==null&&!in_array($ref,$selected,true))return ['ready',[]];
    if(!onboarding_intelligence_schema_ready($pdo))return ['unavailable',[]];
    $preferences=onboarding_intelligence_preferences($pdo,$owner);
    $interests=$preferences['feature_interests']??[];
    if(empty($interests['workflow.booking'])||!empty($interests['activation.dismiss.workflow.booking']))return ['ready',[]];
    $until=$interests['activation.defer.workflow.booking']??null;
    if(is_string($until)&&($at=strtotime($until))!==false&&$at>$now->getTimestamp())return ['ready',[]];
    if(!function_exists('agent_scheduling_tools_ready_v460')||!agent_scheduling_tools_ready_v460($pdo))return ['unavailable',[]];
    $schedule=agent_scheduling_tools_schedule_v460($pdo,$user,$agent);
    $events=$schedule?agent_scheduling_tools_events_v460($pdo,$user,$schedule):[];
    if($events)return ['ready',[]];
    $state=['owner_user_id'=>$owner,'agent_id'=>$agent,'workflow_selected'=>true,
        'schedule_id'=>(int)($schedule['id']??0),'schedule_revision'=>$schedule?hash('sha256',json_encode($schedule,JSON_THROW_ON_ERROR)):null,'active_event_types'=>false,
        'missing_fields'=>$schedule?['meeting_type']:['schedule','meeting_type'],
        'requires_owner_input'=>true,'auto_provision'=>false];
    $row=vp3_initiative_readiness_row_v1($ref,'scheduling_readiness','Appointment setup',
        'The owner selected booking, but the canonical active schedule has no active appointment type. Obtain the owner’s offer and availability choices before creating anything.',
        '/scheduling.php',$state);
    return ['ready',[$ref=>$row]];
}

/** Preserve the existing System-only Commerce source boundary; a named Agent opt-in does not expand it. */
function vp3_initiative_readiness_products_v1(PDO $pdo,array $user,array $principal,?array $selected): array
{
    if(($principal['kind']??'')!=='system')return ['forbidden',[]];
    if(!function_exists('agent_commerce_schema_ready_v800')||!agent_commerce_schema_ready_v800($pdo)
        ||!function_exists('agent_commerce_products_for_owner_v800')||!function_exists('profile_commerce_owner_product_v900'))return ['unavailable',[]];
    $owner=(int)$user['id'];$rows=[];
    if($selected===null){
        $found=agent_commerce_products_for_owner_v800($pdo,$owner,26);$limited=count($found)>=26;
        foreach($found as $row)if((int)($row['owner_user_id']??0)===$owner)$rows[(int)$row['id']]=$row;
    }else{
        $limited=false;
        foreach($selected as $ref){
            if(!preg_match('/\Aproduct-readiness:([1-9][0-9]*)\z/',$ref,$match))continue;
            $row=profile_commerce_owner_product_v900($pdo,$owner,(int)$match[1]);
            if($row&&(int)$row['owner_user_id']===$owner)$rows[(int)$row['id']]=$row;
        }
    }
    $evidence=[];
    foreach($rows as $id=>$row){
        $meta=profile_commerce_metadata_v900($row);$draft=($meta['listing_draft']??null)==='product_photo_v1';
        if(empty($row['is_active'])&&!$draft)continue;
        $missing=[];
        if(trim((string)($row['description']??''))==='')$missing[]='description';
        if(($row['payment_mode']??'free')!=='free'&&(int)($row['price_cents']??0)<1)$missing[]='price';
        if(($row['fulfillment_type']??'none')==='none')$missing[]='fulfillment';
        if($draft)$missing=array_values(array_unique([...$missing,'price','fulfillment','payment_provider']));
        $photoId=max(0,(int)($meta['source_asset_id']??0));$photo=null;
        if($photoId>0){
            if(!function_exists('table_exists')||!table_exists('user_media_assets'))return ['unavailable',[]];
            $q=$pdo->prepare("SELECT id,title,mime_type,updated_at FROM user_media_assets WHERE id=? AND user_id=? AND media_type='photo'");
            $q->execute([$photoId,$owner]);$photo=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$photo)$missing[]='source_photo';
        }
        $visibility=profile_commerce_visibility_v900($row);
        $state=['owner_user_id'=>$owner,'product_id'=>$id,'listing_draft'=>$draft,'is_active'=>!empty($row['is_active']),
            'profile_visibility'=>$visibility,'product_type'=>(string)($row['product_type']??'other'),
            'description'=>vp3_initiative_readiness_text_v1($row['description']??'',1000),
            'missing_fields'=>$missing,'source_asset_id'=>$photo?(int)$photo['id']:null,
            'source_photo_revision'=>$photo?hash('sha256',json_encode($photo,JSON_THROW_ON_ERROR)):null,
            'product_media_link'=>$photo?'owned_photo_record':($photoId>0?'record_missing':'not_assessed'),
            'promotion_state'=>'not_assessed','requires_owner_review'=>true,
            'canonical_revision'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))];
        $detail=$draft?'A private photo-based listing awaits owner review and its commercial terms.':
            ($missing?'This existing listing has unfinished recorded fields.':
                ($visibility==='hidden'?'This active listing is hidden; publication is an owner decision.':'This public listing can be considered for a private promotion or content proposal.'));
        $detail.=' No inference is made that a campaign, gallery or image is absent outside these records. Publishing, prices and spending require their existing approvals.';
        $ref='product-readiness:'.$id;
        $evidence[$ref]=vp3_initiative_readiness_row_v1($ref,'product_readiness',(string)($row['title']??'Product listing'),$detail,
            '/profile-commerce-products.php?workspace_product='.$id,$state);
        if($selected===null&&count($evidence)>=12){$limited=true;break;}
    }
    return [$limited?'limited':'ready',$evidence];
}

function vp3_initiative_readiness_business_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,?array $selected): array
{
    if(($principal['kind']??'')!=='system')return ['forbidden',[]];
    if(!function_exists('campaigns_rewards_schema_ready_v100')||!campaigns_rewards_schema_ready_v100($pdo)
        ||!function_exists('campaigns_rewards_merchant_v100')||!function_exists('campaigns_rewards_can_manage_merchant_v100'))return ['unavailable',[]];
    $owner=(int)$user['id'];
    if(!function_exists('campaigns_rewards_owner_plugin_enabled_v100')||!campaigns_rewards_owner_plugin_enabled_v100($pdo,$owner))return ['forbidden',[]];
    $merchants=[];$limited=false;$evidence=[];
    if($selected===null){
        $q=$pdo->prepare("SELECT id FROM merchant_accounts WHERE owner_user_id=? AND status='active' ORDER BY id LIMIT 11");
        $q->execute([$owner]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$limited=count($ids)>10;
        $ids=array_slice($ids,0,10);
    }else{
        $ids=[];
        foreach($selected as $ref)if(preg_match('/\Abusiness-readiness:([1-9][0-9]*)\z/',$ref,$match))$ids[]=(int)$match[1];
    }
    foreach($ids as $id){
        // The canonical merchant reader is not owner-filtered. Verify its exact
        // ownership before asking it for profile data during ref reconstruction.
        $q=$pdo->prepare("SELECT id FROM merchant_accounts WHERE id=? AND owner_user_id=? AND status='active' LIMIT 1");
        $q->execute([$id,$owner]);
        if($q->fetchColumn()===false)continue;
        $merchant=campaigns_rewards_merchant_v100($pdo,$id);if($merchant)$merchants[]=$merchant;
    }
    foreach($merchants as $merchant){
        $id=(int)$merchant['id'];$ref='business-readiness:'.$id;
        if($selected!==null&&!in_array($ref,$selected,true))continue;
        if((int)($merchant['owner_user_id']??0)!==$owner||($merchant['status']??'')!=='active'
            ||!empty($merchant['sandbox_mode'])||!campaigns_rewards_can_manage_merchant_v100($pdo,$id,$owner))continue;
        $missing=[];
        foreach(['description','website_url'] as $field)if(trim((string)($merchant[$field]??''))==='')$missing[]=$field;
        $promotion='not_permitted';$campaigns=[];
        if(function_exists('campaigns_rewards_platform_can_v100')&&campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.view')){
            $q=$pdo->prepare("SELECT id,name,status,environment,starts_at,ends_at,updated_at FROM campaigns
                WHERE merchant_id=? AND status='active' AND environment='production'
                    AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>?) ORDER BY id LIMIT 26");
            $at=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$q->execute([$id,$at,$at]);
            $campaigns=$q->fetchAll(PDO::FETCH_ASSOC);$promotion=$campaigns?'active_campaign_recorded':'no_active_campaign_recorded';
            if(count($campaigns)>=26)$limited=true;
        }
        if(!$missing&&$promotion!=='no_active_campaign_recorded')continue;
        $state=['owner_user_id'=>$owner,'merchant_id'=>$id,'optional_profile_gaps'=>$missing,
            'description'=>vp3_initiative_readiness_text_v1($merchant['description']??'',1000),
            'promotion_state'=>$promotion,'requires_owner_review'=>true,
            'canonical_revision'=>hash('sha256',json_encode([$merchant,$campaigns],JSON_THROW_ON_ERROR))];
        $detail='An existing owned business has optional profile or recorded promotion opportunities. These are discussion context, not required setup or permission to publish.';
        if($promotion==='no_active_campaign_recorded')$detail.=' No currently active production campaign is recorded in this VP3 merchant workspace; external promotion has not been assessed.';
        $evidence[$ref]=vp3_initiative_readiness_row_v1($ref,'business_readiness',(string)($merchant['name']??'Business profile'),$detail,
            '/campaigns.php?merchant='.$id,$state);
        if($selected===null&&count($evidence)>=10){$limited=true;break;}
    }
    return [$limited?'limited':'ready',$evidence];
}

/** Same bound principal and selected refs are reused when execution revalidates the source snapshot. */
function vp3_initiative_readiness_evidence_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,array &$coverage,?array $selectedRefs=null): array
{
    $owner=(int)($user['id']??0);$kind=$principal['kind']??'';
    if($owner<1||!in_array($kind,['system','user_agent'],true)||!has_permission('account.access',$user)
        ||!has_permission('chat.access',$user)||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)
        ||(isset($principal['viewer_user_id'])&&(int)$principal['viewer_user_id']!==$owner)
        ||($kind==='user_agent'&&((int)($principal['owner_user_id']??0)!==$owner||(int)($principal['agent_id']??0)<1))){
        throw new RuntimeException('Readiness context is unavailable for this principal.',403);
    }
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
    if(!has_permission('account.access',$live))throw new RuntimeException('Readiness context access changed.',403);
    $sources=[
        'account_readiness'=>['account-readiness',fn($refs)=>vp3_initiative_readiness_account_v1($pdo,$live,$principal,$refs)],
        'scheduling_readiness'=>['scheduling-readiness',fn($refs)=>vp3_initiative_readiness_scheduling_v1($pdo,$live,$principal,$now,$refs)],
        'product_readiness'=>['product-readiness',fn($refs)=>vp3_initiative_readiness_products_v1($pdo,$live,$principal,$refs)],
        'business_readiness'=>['business-readiness',fn($refs)=>vp3_initiative_readiness_business_v1($pdo,$live,$principal,$now,$refs)],
    ];
    $evidence=[];
    foreach($sources as $family=>[$prefix,$read]){
        $refs=vp3_initiative_readiness_selected_v1($selectedRefs,$prefix);
        if($refs===[])continue;
        try{[$coverage[$family],$found]=$read($refs);$evidence+=$found;}
        catch(Throwable $e){$coverage[$family]=$e->getCode()===403?'forbidden':'unavailable';}
    }
    ksort($evidence);
    return $evidence;
}
