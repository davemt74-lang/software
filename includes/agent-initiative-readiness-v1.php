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
    $profile=profile_for_user($pdo,$owner,false)??[];
    $q=$pdo->prepare('SELECT id FROM user_agents WHERE owner_user_id=? AND is_active=1 ORDER BY id LIMIT 1');
    $q->execute([$owner]);
    $setup=['agent_named'=>$q->fetchColumn()!==false,'profile_username'=>trim((string)($profile['username']??''))!==''];
    $skill=vp3_agent_onboarding_skill_state_v100(['user'=>['id'=>$owner],'setup'=>$setup]);
    if(!empty($skill['task']['required_complete'])){
        $state=['owner_user_id'=>$owner,'profile_username'=>vp3_initiative_readiness_text_v1($profile['username'],80),
            'display_name'=>vp3_initiative_readiness_text_v1($profile['display_name']??'',100),
            'bio'=>vp3_initiative_readiness_text_v1($profile['bio']??'',500),
            'profile_is_public'=>!empty($profile['is_public']),'requires_owner_input'=>false,'auto_provision'=>false];
        $row=vp3_initiative_readiness_row_v1($ref,'account_readiness','Your saved profile',
            'Required identity setup is complete. Use the saved profile only as private discussion context. If the owner has not indicated a goal, one focused question may help establish what they want to do.',
            '/account.php',$state);
        return ['ready',[$ref=>$row]];
    }
    if(!empty($preferences['onboarding_dismissed']))return ['ready',[]];
    $missing=[];
    foreach($skill['required'] as $item)if(empty($item['verified']))$missing[]=$item['key'];
    $state=['owner_user_id'=>$owner,'missing_fields'=>$missing,'next_step'=>$skill['task']['next']['key']??null,
        'requires_owner_input'=>true,'auto_provision'=>false];
    $row=vp3_initiative_readiness_row_v1($ref,'account_readiness','Account setup',
        'The canonical onboarding skill has unfinished required identity setup. Ask for the missing owner choices; do not invent or save them.',
        '/chat.php?setup=1',$state);
    return ['ready',[$ref=>$row]];
}

/** Reuse the same scoped active schedule and appointment types as Agent Chat. */
function vp3_initiative_readiness_scheduling_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,?array $selected): array
{
    $owner=(int)$user['id'];$agent=max(0,(int)($principal['agent_id']??0));$ref='scheduling-readiness:'.$owner.':'.$agent;
    if($selected!==null&&!in_array($ref,$selected,true))return ['ready',[]];
    if(!onboarding_intelligence_schema_ready($pdo))return ['unavailable',[]];
    $preferences=onboarding_intelligence_preferences($pdo,$owner);
    $interests=$preferences['feature_interests']??[];
    if(!empty($interests['activation.dismiss.workflow.booking']))return ['ready',[]];
    $until=$interests['activation.defer.workflow.booking']??null;
    if(is_string($until)&&($at=strtotime($until))!==false&&$at>$now->getTimestamp())return ['ready',[]];
    if(!function_exists('agent_scheduling_tools_ready_v460')||!agent_scheduling_tools_ready_v460($pdo)
        ||!table_exists('agent_scheduling_availability'))return ['unavailable',[]];
    $schedule=agent_scheduling_tools_schedule_v460($pdo,$user,$agent);
    $events=$schedule?agent_scheduling_tools_events_v460($pdo,$user,$schedule):[];
    $eventCount=count($events);$scheduleId=(int)($schedule['id']??0);
    $hasWeeklyHours=false;$typesWithHours=0;
    if($schedule){
        $q=$pdo->prepare('SELECT MAX(CASE WHEN a.event_type_id IS NULL THEN 1 ELSE 0 END) AS shared_hours,
            COUNT(DISTINCT e.id) AS types_with_hours
            FROM agent_scheduling_availability a LEFT JOIN agent_scheduling_event_types e
                ON e.id=a.event_type_id AND e.schedule_id=a.schedule_id AND e.is_active=1
            WHERE a.schedule_id=? AND a.is_active=1 AND a.weekday BETWEEN 0 AND 6
                AND a.start_minute>=0 AND a.end_minute<=1440 AND a.end_minute>a.start_minute');
        $q->execute([$scheduleId]);$hours=$q->fetch(PDO::FETCH_ASSOC);
        if(!$hours)return ['unavailable',[]];
        $sharedHours=!empty($hours['shared_hours']);
        $typesWithHours=$sharedHours?$eventCount:(int)$hours['types_with_hours'];
        $hasWeeklyHours=$sharedHours||($eventCount>0&&$typesWithHours===$eventCount);
    }
    if($events&&$hasWeeklyHours)return ['ready',[]];
    $selectedBooking=!empty($interests['workflow.booking']);
    $missing=[];
    if($selectedBooking){
        if(!$schedule)$missing[]='schedule';
        if(!$events)$missing[]='meeting_type';
        if(!$hasWeeklyHours)$missing[]='weekly_hours';
    }
    $state=['owner_user_id'=>$owner,'agent_id'=>$agent,'workflow_selected'=>$selectedBooking,
        'schedule_id'=>$scheduleId,'schedule_revision'=>$schedule?hash('sha256',json_encode($schedule,JSON_THROW_ON_ERROR)):null,
        'active_event_types'=>$eventCount>0,'active_event_type_count'=>$eventCount,'active_event_types_with_weekly_hours'=>$typesWithHours,
        'weekly_hours_configured'=>$hasWeeklyHours,'missing_fields'=>$missing,
        'requires_owner_input'=>$selectedBooking,'auto_provision'=>false];
    $detail=match(true){
        !$events&&!$hasWeeklyHours=>'The schedule available in this Chat has no active appointment type or weekly booking hours.',
        !$events=>'The schedule available in this Chat has weekly booking hours but no active appointment type.',
        default=>'At least one active appointment type on the schedule available in this Chat has no weekly booking hours.',
    };
    $detail.=$selectedBooking
        ?' The owner selected booking. Offer help choosing the appointment type, duration, or weekly hours and time zone as needed, and use Scheduling to save the owner’s choices.'
        :' Booking has not been selected. Consider this only if it relates to the owner’s goals.';
    $path=match(true){
        !$schedule=>'/scheduling.php',
        !$events=>'/scheduling-type.php?schedule='.$scheduleId,
        default=>'/scheduling.php?schedule='.$scheduleId.'&tab=availability',
    };
    $row=vp3_initiative_readiness_row_v1($ref,'scheduling_readiness','Appointment setup',
        $detail,$path,$state);
    return ['ready',[$ref=>$row]];
}

/** Observe the canonical Cloud calendar only, including its pending and confirmed bookings. */
function vp3_initiative_readiness_calendar_v1(PDO $pdo,array $user,DateTimeImmutable $now,?array $selected): array
{
    $owner=(int)$user['id'];$ref='calendar-readiness:'.$owner;
    if($selected!==null&&!in_array($ref,$selected,true))return ['ready',[]];
    if(!function_exists('user_calendar_events_v1300')||!user_calendar_schema_ready_v1300($pdo)
        ||!agent_scheduling_schema_ready_v430($pdo))return ['unavailable',[]];
    $from=$now->setTimezone(new DateTimeZone('UTC'));
    $events=user_calendar_events_v1300($pdo,$user,$from->format('Y-m-d H:i:s'),$from->modify('+7 days')->format('Y-m-d H:i:s'),false);
    if($events)return ['ready',[]];
    $state=['owner_user_id'=>$owner,'scope'=>'cloud_calendar','horizon_days'=>7,'upcoming_events'=>0];
    $row=vp3_initiative_readiness_row_v1($ref,'calendar_readiness','Cloud Calendar',
        'No active events or pending or confirmed bookings are recorded in the Cloud Calendar for the next seven days. HomeServer and external calendars were not checked. This is planning context and does not establish booking availability.',
        '/calendar.php',$state);
    return ['ready',[$ref=>$row]];
}

/** Preserve the existing System-only Commerce source boundary; a named Agent opt-in does not expand it. */
function vp3_initiative_readiness_products_v1(PDO $pdo,array $user,array $principal,?array $selected): array
{
    if(($principal['kind']??'')!=='system')return ['forbidden',[]];
    if(!function_exists('agent_commerce_schema_ready_v800')||!agent_commerce_schema_ready_v800($pdo)
        ||!function_exists('agent_commerce_products_for_owner_v800')||!function_exists('profile_commerce_owner_product_v900'))return ['unavailable',[]];
    $owner=(int)$user['id'];$rows=[];$emptyRef='product-readiness:owner:'.$owner;
    $limited=false;$empty=false;
    if($selected===null||in_array($emptyRef,$selected,true)){
        $found=agent_commerce_products_for_owner_v800($pdo,$owner,26);$limited=count($found)>=26;
        $owned=array_filter($found,static fn(array $row):bool=>(int)($row['owner_user_id']??0)===$owner);
        $empty=!$owned&&!$limited;
        if($selected===null)foreach($owned as $row)$rows[(int)$row['id']]=$row;
    }
    if($selected!==null){
        foreach($selected as $ref){
            if(!preg_match('/\Aproduct-readiness:([1-9][0-9]*)\z/',$ref,$match))continue;
            $row=profile_commerce_owner_product_v900($pdo,$owner,(int)$match[1]);
            if($row&&(int)$row['owner_user_id']===$owner)$rows[(int)$row['id']]=$row;
        }
    }
    $evidence=[];
    if($empty){
        $evidence[$emptyRef]=vp3_initiative_readiness_row_v1($emptyRef,'product_readiness','Product catalog',
            'No product records are owned by this account in VP3. If selling is relevant, a first listing could be discussed. Nothing is required or authorized for publication.',
            '/profile-commerce-products.php',['owner_user_id'=>$owner,'owned_products'=>0,'requires_owner_review'=>true]);
    }
    foreach($rows as $id=>$row){
        $meta=profile_commerce_metadata_v900($row);$draft=profile_commerce_private_listing_draft_v900($row);
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
        $detail=$draft?'A private listing awaits owner review and its commercial terms.':
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

/** Rebuild one exact setup step without reading Claim Code material or creating records. */
function vp3_initiative_readiness_merchant_setup_v1(PDO $pdo,int $merchant,int $owner,string $at): ?array
{
    $steps=[
        'location'=>['merchant_locations','locations.manage',
            'SELECT 1 FROM merchant_locations WHERE merchant_id=? AND is_active=1 LIMIT 1',[$merchant],
            '/campaigns.php?merchant='.$merchant.'#location-editor',
            'No active Merchant Location is recorded. For in-person rewards, ask which location the owner wants to use and help collect its name and details.'],
        'claim_code'=>['merchant_claim_codes','claim_codes.manage',
            "SELECT 1 FROM merchant_claim_codes WHERE merchant_id=? AND status='active'
                AND (active_from IS NULL OR active_from<=?) AND (active_until IS NULL OR active_until>?) LIMIT 1",[$merchant,$at,$at],
            '/rewards.php?merchant='.$merchant.'#claim-code-editor',
            'The merchant has a location and no currently active Claim Code. For in-person claims, discuss the location, device and limits, then offer the secure Claim Code editor.'],
        'reward_product'=>['reward_products','rewards.manage',
            'SELECT 1 FROM reward_products WHERE merchant_id=? AND is_active=1 LIMIT 1',[$merchant],
            '/rewards.php?merchant='.$merchant.'#reward-editor',
            'The merchant has a location and an active Claim Code but no active Reward Product. Ask what customers should receive and discuss fulfillment before opening the Reward Product editor.'],
    ];
    foreach($steps as $key=>[$table,$permission,$sql,$params,$path,$detail]){
        if(!table_exists($table)||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,$permission))return null;
        $q=$pdo->prepare($sql);$q->execute($params);
        if($q->fetchColumn()===false)return ['next_step'=>$key,'path'=>$path,'detail'=>$detail];
    }
    return null;
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
    $at=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    foreach($merchants as $merchant){
        $id=(int)$merchant['id'];$ref='business-readiness:'.$id;
        if($selected!==null&&!in_array($ref,$selected,true))continue;
        if((int)($merchant['owner_user_id']??0)!==$owner||($merchant['status']??'')!=='active'
            ||!empty($merchant['sandbox_mode'])||!campaigns_rewards_can_manage_merchant_v100($pdo,$id,$owner))continue;
        $missing=[];
        foreach(['description','website_url'] as $field)if(trim((string)($merchant[$field]??''))==='')$missing[]=$field;
        $promotion='not_permitted';$campaigns=[];
        $setup=null;
        if(function_exists('campaigns_rewards_platform_can_v100')){
            $setup=vp3_initiative_readiness_merchant_setup_v1($pdo,$id,$owner,$at);
        }
        if(function_exists('campaigns_rewards_platform_can_v100')&&campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.view')){
            $q=$pdo->prepare("SELECT id,name,status,environment,starts_at,ends_at,updated_at FROM campaigns
                WHERE merchant_id=? AND status='active' AND environment='production'
                    AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>?) ORDER BY id LIMIT 26");
            $q->execute([$id,$at,$at]);
            $campaigns=$q->fetchAll(PDO::FETCH_ASSOC);$promotion=$campaigns?'active_campaign_recorded':'no_active_campaign_recorded';
            if(count($campaigns)>=26)$limited=true;
        }
        if(!$setup&&!$missing&&$promotion!=='no_active_campaign_recorded')continue;
        $state=['owner_user_id'=>$owner,'merchant_id'=>$id,'optional_profile_gaps'=>$missing,
            'description'=>vp3_initiative_readiness_text_v1($merchant['description']??'',1000),
            'promotion_state'=>$promotion,'requires_owner_review'=>true,
            'canonical_revision'=>hash('sha256',json_encode([$merchant,$campaigns],JSON_THROW_ON_ERROR))];
        if($setup)$state['next_setup_step']=$setup['next_step'];
        $detail=$setup ? $setup['detail'].' Guide this one step at a time and wait for owner input.'
            :'An existing owned business has optional profile or recorded promotion opportunities. These are discussion context, not required setup or permission to publish.';
        if($promotion==='no_active_campaign_recorded')$detail.=' No currently active production campaign is recorded in this VP3 merchant workspace; external promotion has not been assessed.';
        $evidence[$ref]=vp3_initiative_readiness_row_v1($ref,'business_readiness',(string)($merchant['name']??'Business profile'),$detail,
            $setup['path']??'/campaigns.php?merchant='.$id,$state);
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
        'calendar_readiness'=>['calendar-readiness',fn($refs)=>vp3_initiative_readiness_calendar_v1($pdo,$live,$now,$refs)],
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
