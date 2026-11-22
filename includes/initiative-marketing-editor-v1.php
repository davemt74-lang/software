<?php
declare(strict_types=1);

/**
 * Private Marketing Canvas draft editor, V2B1.
 * No endpoint in this module activates, publishes or sends anything. Canonical
 * Campaigns & Rewards owns every mutation, permission and Reward association.
 */
function vp3_marketing_editor_scope_v1(PDO $pdo,int $owner,array $scope): void
{
    if($owner<1 || (int)($scope['user']['id']??0)!==$owner || !has_permission('account.access',$scope['user'])) {
        throw new DomainException('Marketing owner access is required.',403);
    }
    if(!function_exists('vp3_coordination_marketing_owner_v1')) require_once __DIR__.'/agent-coordination-marketing-v1.php';
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner) {
        throw new DomainException('This Marketing workspace is no longer available.',403);
    }
    if(!function_exists('campaigns_rewards_platform_schema_ready_v100')
       || !campaigns_rewards_platform_schema_ready_v100($pdo)) {
        throw new DomainException('Campaigns & Rewards needs Database Upgrade.',503);
    }
}

function vp3_marketing_editor_id_v1(mixed $raw,bool $optional=false): int
{
    if($optional && $raw===null)return 0;
    if(!is_int($raw)||$raw<($optional?0:1)||$raw>9007199254740991) {
        throw new DomainException('Choose a valid Campaign or Merchant.',422);
    }
    return $raw;
}

function vp3_marketing_editor_merchants_v1(PDO $pdo,int $owner): array
{
    $query=$pdo->prepare("SELECT id,name FROM merchant_accounts
        WHERE owner_user_id=? AND status='active' AND sandbox_mode=0
        ORDER BY id DESC LIMIT 21");
    $query->execute([$owner]);
    $out=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC)?:[] as $row) {
        $id=(int)$row['id'];
        if(campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.view')
           && campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.create')
           && campaigns_rewards_platform_can_v100($pdo,$id,$owner,'campaigns.edit')) {
            $out[]=['id'=>$id,'name'=>mb_substr((string)$row['name'],0,140)];
        }
    }
    return $out;
}

function vp3_marketing_editor_merchant_v1(PDO $pdo,int $owner,int $merchantId): array
{
    $rows=vp3_marketing_editor_merchants_v1($pdo,$owner);
    foreach($rows as $row)if($row['id']===$merchantId)return $row;
    throw new DomainException('This Merchant is unavailable for draft editing.',403);
}

/** Never return private reward cost, claim keys, contact records or Campaign automation. */
function vp3_marketing_editor_catalog_v1(PDO $pdo,int $owner,array $scope,int $merchantId=0): array
{
    vp3_marketing_editor_scope_v1($pdo,$owner,$scope);
    $merchants=vp3_marketing_editor_merchants_v1($pdo,$owner);
    $limited=count($merchants)>20;
    $merchants=array_slice($merchants,0,20);
    if($merchantId===0)$merchantId=(int)($merchants[0]['id']??0);
    $selected=null;
    foreach($merchants as $merchant)if($merchant['id']===$merchantId)$selected=$merchant;
    if($selected===null)return ['merchants'=>$merchants,'merchant_id'=>0,'types'=>[],'rewards'=>[],'locations'=>[],'limited'=>$limited];
    $query=$pdo->prepare("SELECT type_key,name,description,reward_rules_schema_json,landing_schema_json
        FROM campaign_types WHERE is_active=1 AND (merchant_id=? OR merchant_id IS NULL)
        ORDER BY is_system DESC,name LIMIT 60");
    $query->execute([$merchantId]);
    $types=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC)?:[] as $row) {
        $rules=json_decode((string)($row['reward_rules_schema_json']??''),true);
        $landing=json_decode((string)($row['landing_schema_json']??''),true);
        $key=(string)$row['type_key'];
        if(!preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/D',$key))continue;
        $types[]=[
            'key'=>$key,'label'=>mb_substr((string)$row['name'],0,100),
            'description'=>mb_substr((string)$row['description'],0,240),
            'requires_reward'=>!empty($rules['requires_reward']),
            'default_cta'=>mb_substr((string)($landing['cta']??'Continue'),0,80),
        ];
    }
    if(!function_exists('campaigns_rewards_reward_products_v110'))require_once __DIR__.'/campaigns-rewards-v110.php';
    $rewardRows=campaigns_rewards_reward_products_v110($pdo,$merchantId,$owner);
    $rewards=[];
    foreach($rewardRows as $row) {
        if(empty($row['is_active']))continue;
        $rewards[]=['id'=>(int)$row['id'],'name'=>mb_substr((string)$row['name'],0,140),
            'type'=>mb_substr((string)($row['reward_type_name']??''),0,80)];
        if(count($rewards)>=60)break;
    }
    $locations=[];
    foreach(campaigns_rewards_locations_v100($pdo,$merchantId) as $row) {
        $locations[]=['id'=>(int)$row['id'],'name'=>mb_substr((string)$row['name'],0,140)];
        if(count($locations)>=60)break;
    }
    vp3_marketing_editor_scope_v1($pdo,$owner,$scope);
    vp3_marketing_editor_merchant_v1($pdo,$owner,$merchantId);
    return ['merchants'=>$merchants,'merchant_id'=>$merchantId,
        'types'=>$types,'rewards'=>$rewards,'locations'=>$locations,'limited'=>$limited];
}

/** A draft is the only editable lifecycle here. Active Campaigns use the canonical lifecycle review. */
function vp3_marketing_editor_draft_v1(PDO $pdo,int $owner,int $merchantId,int $campaignId,bool $lock=false): array
{
    $merchant=vp3_marketing_editor_merchant_v1($pdo,$owner,$merchantId);
    if($lock)campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$owner,'campaigns.edit',true);
    $campaign=campaigns_rewards_campaign_v100($pdo,$campaignId,$lock);
    if(!$campaign||(int)$campaign['merchant_id']!==$merchant['id']
        ||(int)$campaign['merchant_owner_user_id']!==$owner
        ||(string)$campaign['status']!=='draft'
        ||(string)$campaign['environment']!=='production') {
        throw new DomainException('Only your current private Campaign drafts can be edited here.',409);
    }
    $rewards=campaigns_rewards_campaign_reward_ids_v118($pdo,$campaignId);
    sort($rewards,SORT_NUMERIC);
    $fields=[
        'title'=>(string)$campaign['title'],
        'campaign_type'=>(string)$campaign['campaign_type_key'],
        'slug'=>(string)$campaign['slug'],
        'subtitle'=>(string)($campaign['subtitle']??''),
        'cta_label'=>(string)($campaign['cta_label']??''),
        'objective'=>(string)($campaign['objective']??''),
        'description'=>(string)($campaign['description']??''),
        'terms'=>(string)($campaign['terms']??''),
        'starts_at'=>(string)($campaign['starts_at']??''),
        'ends_at'=>(string)($campaign['ends_at']??''),
        'location_id'=>(int)($campaign['location_id']??0),
        'reward_ids'=>$rewards,
    ];
    $revision=hash('sha256',json_encode([
        $owner,$merchantId,$campaignId,$campaign['updated_at']??null,
        $campaign['current_version_no']??null,$fields,
    ],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    return [
        'id'=>$campaignId,'merchant_id'=>$merchantId,'status'=>'draft',
        'revision'=>$revision,'fields'=>$fields,
        'manage_url'=>url('/campaigns.php?merchant='.$merchantId.'&edit_campaign='.$campaignId.'#campaign-editor'),
    ];
}

/** Bounded normalized proposal; never accept status, environment, issuance, approval or owner IDs from the browser. */
function vp3_marketing_editor_fields_v1(mixed $raw,array $catalog): array
{
    if(!is_array($raw)||array_is_list($raw))throw new DomainException('Complete the Campaign draft form.',422);
    $keys=['title','campaign_type','slug','subtitle','cta_label','objective','description','terms','starts_at','ends_at','location_id','reward_ids'];
    if(array_diff(array_keys($raw),$keys))throw new DomainException('The Campaign draft contains an unsupported field.',422);
    $text=static function(string $key,int $max,bool $required=false)use($raw):string{
        $value=$raw[$key]??'';
        if(!is_string($value)||strlen($value)>$max*4+64
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)) {
            throw new DomainException('Review the Campaign '.$key.' field.',422);
        }
        $value=trim($value);
        if(mb_strlen($value)>$max||($required&&$value===''))throw new DomainException('Review the Campaign '.$key.' field.',422);
        return $value;
    };
    $title=$text('title',190,true);
    $type=$text('campaign_type',80,true);
    $allowedTypes=array_column($catalog['types'],'key');
    if(!in_array($type,$allowedTypes,true))throw new DomainException('This Campaign Type is unavailable.',422);
    $cta=$text('cta_label',80);
    if($cta==='') {
        $matched=array_search($type,$allowedTypes,true);
        $cta=(string)($catalog['types'][$matched]['default_cta']??'Continue');
        if($cta==='')$cta='Continue';
    }
    $slug=$text('slug',120);
    if($slug==='' )$slug=campaigns_rewards_slug_v100($title,120);
    if(!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)||strlen($slug)>120) {
        throw new DomainException('Use lowercase words and hyphens for the Campaign URL.',422);
    }
    $datetime=static function(string $key)use($raw):string{
        $rawDate=$raw[$key]??'';
        if(!is_string($rawDate))throw new DomainException('Review the Campaign UTC schedule.',422);
        if($rawDate==='')return '';
        if(!preg_match('/\A20[2-9][0-9]-[0-1][0-9]-[0-3][0-9]T[0-2][0-9]:[0-5][0-9]\z/D',$rawDate)) {
            throw new DomainException('Choose an exact UTC date and time.',422);
        }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$rawDate,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d\TH:i')!==$rawDate)throw new DomainException('The UTC date and time is invalid.',422);
        return $date->format('Y-m-d H:i:s');
    };
    $starts=$datetime('starts_at');$ends=$datetime('ends_at');
    if($starts!==''&&$ends!==''&&$ends<=$starts)throw new DomainException('Campaign end must be later than its start.',422);
    $location=$raw['location_id']??0;
    if(!is_int($location)||$location<0||($location>0&&!in_array($location,array_column($catalog['locations'],'id'),true))) {
        throw new DomainException('Choose an active Merchant Location.',422);
    }
    $rewards=$raw['reward_ids']??[];
    if(!is_array($rewards)||!array_is_list($rewards)||count($rewards)>60) {
        throw new DomainException('Choose valid Campaign rewards.',422);
    }
    $allowedRewards=array_column($catalog['rewards'],'id');
    foreach($rewards as $id)if(!is_int($id)||!in_array($id,$allowedRewards,true))throw new DomainException('Choose only active Merchant Rewards.',422);
    if(count($rewards)!==count(array_unique($rewards)))throw new DomainException('Do not attach the same Reward twice.',422);
    sort($rewards,SORT_NUMERIC);
    return [
        'title'=>$title,'campaign_type'=>$type,'slug'=>$slug,
        'subtitle'=>$text('subtitle',500),'cta_label'=>$cta,
        'objective'=>$text('objective',500),'description'=>$text('description',4000),
        'terms'=>$text('terms',6000),
        'starts_at'=>$starts,'ends_at'=>$ends,
        'location_id'=>$location,'reward_ids'=>$rewards,
    ];
}

/** A URL reviewed for a private draft must stay exact. A committed replay bypasses this new-write check. */
function vp3_marketing_editor_assert_slug_v1(PDO $pdo,string $slug,int $campaignId): void
{
    $query=$pdo->prepare('SELECT id FROM campaigns WHERE slug=? AND id<>? LIMIT 1');
    $query->execute([$slug,$campaignId]);
    if($query->fetchColumn()) {
        throw new DomainException('That Campaign URL slug is already reserved. Choose another.',409);
    }
}

function vp3_marketing_editor_intent_v1(int $owner,array $scope,int $merchantId,int $campaignId,
    string $revision,string $nonce,array $fields,?array $brief=null): string
{
    $intent=[
        'vp3.marketing-editor.v2b1',$owner,(int)$scope['agent_id'],(int)$scope['conversation_id'],
        $merchantId,$campaignId,$revision,$nonce,$fields,
    ];
    if($brief!==null)$intent[]=$brief;
    return hash('sha256',json_encode($intent,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
}

/**
 * Exact signed preview + idempotent canonical write. The save path never takes a status field
 * and refuses to change any campaign which has left Draft since its preview.
 */
function vp3_marketing_editor_action_v1(PDO $pdo,int $owner,array $scope,array $body,string $reviewKey): array
{
    vp3_marketing_editor_scope_v1($pdo,$owner,$scope);
    require_once __DIR__.'/initiative-marketing-brief-campaign-v1.php';
    $action=(string)($body['action']??'');
    if($action==='marketing_editor_options') {
        $merchantId=vp3_marketing_editor_id_v1($body['merchant_id']??null,true);
        return ['catalog'=>vp3_marketing_editor_catalog_v1($pdo,$owner,$scope,$merchantId)];
    }
    if($action==='marketing_editor_open') {
        $campaignId=vp3_marketing_editor_id_v1($body['campaign_id']??null);
        $row=$pdo->prepare("SELECT merchant_id FROM campaigns WHERE id=? LIMIT 1");
        $row->execute([$campaignId]);$merchantId=(int)$row->fetchColumn();
        if($merchantId<1)throw new DomainException('This Campaign draft is unavailable.',404);
        $catalog=vp3_marketing_editor_catalog_v1($pdo,$owner,$scope,$merchantId);
        return ['catalog'=>$catalog,'draft'=>vp3_marketing_editor_draft_v1($pdo,$owner,$merchantId,$campaignId)];
    }
    if(!in_array($action,['marketing_editor_preview','marketing_editor_save'],true)) {
        throw new DomainException('Choose a Marketing draft action.',422);
    }
    $merchantId=vp3_marketing_editor_id_v1($body['merchant_id']??null);
    $campaignId=vp3_marketing_editor_id_v1($body['campaign_id']??null,true);
    $revision=$body['revision']??null;
    $nonce=$body['request_key']??null;
    if(!is_string($revision)||!is_string($nonce)||!preg_match('/\A[a-f0-9]{32}\z/D',$nonce)
        || ($campaignId===0 ? $revision!=='new' : !preg_match('/\A[a-f0-9]{64}\z/D',$revision))) {
        throw new DomainException('Reopen the current Campaign draft before saving.',422);
    }
    $catalog=vp3_marketing_editor_catalog_v1($pdo,$owner,$scope,$merchantId);
    if($catalog['merchant_id']!==$merchantId)throw new DomainException('Merchant draft permission changed.',403);
    $fields=vp3_marketing_editor_fields_v1($body['fields']??null,$catalog);
    $brief=vp3_marketing_brief_binding_v1($body['brief_source']??null);
    if($brief!==null&&$campaignId!==0)throw new DomainException('A saved Campaign keeps its original source brief.',422);
    $source=null;
    if($action==='marketing_editor_preview'){
        vp3_marketing_editor_assert_slug_v1($pdo,$fields['slug'],$campaignId);
        if($brief!==null)$source=vp3_marketing_brief_source_v1($pdo,$owner,$scope,$brief);
    }
    if($campaignId>0 && $action==='marketing_editor_preview') {
        $draft=vp3_marketing_editor_draft_v1($pdo,$owner,$merchantId,$campaignId);
        if(!hash_equals($draft['revision'],$revision))throw new DomainException('This Campaign changed. Reload the latest draft before saving.',409);
    }
    $intent=vp3_marketing_editor_intent_v1($owner,$scope,$merchantId,$campaignId,$revision,$nonce,$fields,$brief);
    if($action==='marketing_editor_preview') {
        if($reviewKey==='')throw new RuntimeException('Review token unavailable.',503);
        $expires=time()+600;
        return ['preview'=>['title'=>$fields['title'],'campaign_type'=>$fields['campaign_type'],
            'description'=>$fields['description'],'subtitle'=>$fields['subtitle'],
            'objective'=>$fields['objective'],'terms'=>$fields['terms'],'slug'=>$fields['slug'],
            'cta_label'=>$fields['cta_label'],'reward_count'=>count($fields['reward_ids']),
            'reward_names'=>array_values(array_map(
                static fn(int $id):string => (string)($catalog['rewards'][array_search($id,array_column($catalog['rewards'],'id'),true)]['name']??'Reward'),
                $fields['reward_ids'])),
            'location'=>($fields['location_id']===0?'All locations':(string)(
                $catalog['locations'][array_search($fields['location_id'],array_column($catalog['locations'],'id'),true)]['name']??'Location')),
            'starts_at'=>$fields['starts_at'],'ends_at'=>$fields['ends_at'],
            'source_brief'=>$source===null?null:$source['title'],
            'status'=>'draft','will_publish'=>false],
            'review_token'=>initiative_workbench_review_token_v1($reviewKey,$intent,$expires)];
    }
    initiative_workbench_confirm_v1($body,$reviewKey,$intent,time());
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try {
        vp3_marketing_editor_scope_v1($pdo,$owner,$scope);
        vp3_marketing_editor_merchant_v1($pdo,$owner,$merchantId);
        // Check the committed idempotency receipt before demanding the old draft
        // revision. A network retry must return the first result, not create a second write.
        $key=$owner.':'.$nonce;
        $receipt=campaigns_rewards_idempotency_begin_v100($pdo,$merchantId,'marketing.chat_draft.v2b1',$key,
            ['intent'=>$intent],true);
        if(empty($receipt['new'])) {
            if(($receipt['status']??null)!=='completed'||($receipt['result_ref_type']??null)!=='campaign') {
                throw new DomainException('Previous draft save is not yet verified.',409);
            }
            $savedId=(int)($receipt['result_ref_id']??0);
            $saved=vp3_marketing_editor_draft_v1($pdo,$owner,$merchantId,$savedId);
        } else {
            if($brief!==null)$source=vp3_marketing_brief_source_v1($pdo,$owner,$scope,$brief);
            vp3_marketing_editor_assert_slug_v1($pdo,$fields['slug'],$campaignId);
            if($campaignId>0) {
                $current=vp3_marketing_editor_draft_v1($pdo,$owner,$merchantId,$campaignId,true);
                if(!hash_equals($current['revision'],$revision))throw new DomainException('This Campaign changed during review. Reload it.',409);
            }
            // A draft save must not publish on Profile; release remains a separate review.
            $input=$fields+['profile_visible'=>false,'reward_selection_present'=>1];
            $row=campaigns_rewards_save_campaign_v100($pdo,$merchantId,$owner,$input,$campaignId);
            $savedId=(int)$row['id'];
            $saved=vp3_marketing_editor_draft_v1($pdo,$owner,$merchantId,$savedId);
            // The legacy create path can auto-suffix a concurrent slug collision.
            // A signed review must never silently publish a different reserved URL.
            if(!hash_equals($fields['slug'],$saved['fields']['slug'])){
                throw new DomainException('The Campaign URL changed before saving. Review a different slug.',409);
            }
            if($source!==null)vp3_marketing_brief_save_v1($pdo,$owner,$scope,$savedId,$source);
            campaigns_rewards_idempotency_complete_v100($pdo,(int)$receipt['id'],'campaign',$savedId);
        }
        vp3_marketing_editor_scope_v1($pdo,$owner,$scope);
        if($owns)$pdo->commit();
        return ['saved'=>true,'draft'=>$saved,'published'=>false];
    }catch(Throwable $error) {
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
