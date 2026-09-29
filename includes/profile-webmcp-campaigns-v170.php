<?php
declare(strict_types=1);

require_once __DIR__.'/campaigns-rewards-v126.php';
require_once __DIR__.'/profile-webmcp-actions-v150.php';
require_once __DIR__.'/profile-webmcp-scheduling-v150.php';

const VP3_PROFILE_WEBMCP_CAMPAIGNS_V170='profile-webmcp-campaigns-v170-20260928';
const VP3_PROFILE_WEBMCP_CAMPAIGN_INTENT_TTL_V170=600;

function vp3_profile_webmcp_campaigns_tool_catalog_v170(): array
{
    return [
        'vp3.campaigns.list'=>[
            'title'=>'List public campaigns',
            'description'=>'List active, published Campaigns shown on this public VP3 Profile.',
            'capability'=>'campaigns',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.campaign.get'=>[
            'title'=>'Get public campaign',
            'description'=>'Return one public Campaign with public terms, location, participation requirements, and public Rewards.',
            'capability'=>'campaigns',
            'input_schema'=>['type'=>'object','properties'=>['campaign_slug'=>['type'=>'string','minLength'=>1,'maxLength'=>120]],'required'=>['campaign_slug'],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.campaign.eligibility.get'=>[
            'title'=>'Get campaign participation requirements',
            'description'=>'Describe whether a public Campaign accepts participation and which user-supplied fields or consent are required. This does not evaluate private CRM targeting.',
            'capability'=>'campaigns',
            'input_schema'=>['type'=>'object','properties'=>['campaign_slug'=>['type'=>'string','minLength'=>1,'maxLength'=>120]],'required'=>['campaign_slug'],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.campaign.participation.prepare'=>[
            'title'=>'Prepare Campaign participation',
            'description'=>'Validate and preview participation in a public Campaign without enrolling, creating CRM records, issuing Rewards, or starting Journeys.',
            'capability'=>'campaigns',
            'input_schema'=>vp3_profile_webmcp_campaign_participation_input_schema_v170(),
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.campaign.participation.confirm'=>[
            'title'=>'Confirm Campaign participation',
            'description'=>'Complete the exact prepared Campaign participation through the canonical Campaigns & Rewards runtime.',
            'capability'=>'campaigns',
            'input_schema'=>vp3_profile_webmcp_confirmation_schema_v150(),
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
    ];
}

function vp3_profile_webmcp_campaign_public_v170(PDO $pdo,array $profile,string $slug): array
{
    $campaign=campaigns_rewards_campaign_by_slug_v100($pdo,$slug,true);
    if(!$campaign||(int)($campaign['profile_user_id']??0)!==(int)($profile['user_id']??0)){
        throw new RuntimeException('This Campaign is not available.');
    }
    if(empty($campaign['profile_is_public']))throw new RuntimeException('This Campaign is not available.');
    return $campaign;
}

function vp3_profile_webmcp_campaign_reward_projection_v170(array $reward): array
{
    return [
        'public_id'=>(string)($reward['public_id']??''),
        'title'=>(string)($reward['title']??$reward['name']??''),
        'description'=>(string)($reward['description']??''),
        'reward_type'=>(string)($reward['reward_type']??''),
        'value_label'=>(string)($reward['value_label']??''),
        'per_customer_limit'=>max(1,(int)($reward['per_customer_limit']??$reward['claim_limit']??1)),
        'starts_at'=>$reward['starts_at']??null,
        'ends_at'=>$reward['ends_at']??null,
    ];
}

function vp3_profile_webmcp_campaign_projection_v170(PDO $pdo,array $campaign,bool $includeRewards=true): array
{
    $behavior=campaigns_rewards_campaign_type_behavior_v118($pdo,(int)$campaign['merchant_id'],(string)$campaign['campaign_type_key']);
    $location=array_filter([
        'name'=>(string)($campaign['location_name']??''),
        'city'=>(string)($campaign['city']??''),
        'region'=>(string)($campaign['region']??''),
        'country'=>(string)($campaign['country']??''),
    ],static fn($v)=>$v!=='');
    $out=[
        'public_id'=>(string)$campaign['public_id'],
        'slug'=>(string)$campaign['slug'],
        'title'=>(string)$campaign['title'],
        'subtitle'=>(string)($campaign['subtitle']??''),
        'description'=>(string)($campaign['description']??''),
        'campaign_type'=>(string)$campaign['campaign_type_key'],
        'campaign_type_label'=>(string)($campaign['campaign_type_name']??''),
        'cta_label'=>(string)($campaign['cta_label']??$behavior['default_cta']??'Continue'),
        'terms'=>(string)($campaign['terms']??''),
        'starts_at'=>$campaign['starts_at']??null,
        'ends_at'=>$campaign['ends_at']??null,
        'public_url'=>campaigns_rewards_campaign_url_v100((string)$campaign['slug']),
        'location'=>$location?:null,
        'participation'=>[
            'public_signup_supported'=>!empty($behavior['public']),
            'public_action'=>(string)($behavior['public_action']??'none'),
            'required_fields'=>array_values(array_map('strval',(array)($behavior['required_fields']??[]))),
            'marketing_consent'=>(string)($behavior['marketing']??'optional'),
            'requires_reward'=>!empty($behavior['requires_reward']),
        ],
    ];
    if($includeRewards){
        $rewards=campaigns_rewards_rewards_v100($pdo,(int)$campaign['id'],true);
        $out['rewards']=array_values(array_map('vp3_profile_webmcp_campaign_reward_projection_v170',$rewards));
    }
    return $out;
}

function vp3_profile_webmcp_campaigns_list_v170(PDO $pdo,array $profile): array
{
    $rows=campaigns_rewards_profile_campaigns_v100($pdo,(int)$profile['user_id'],50);
    $out=[];
    foreach($rows as $row){
        $slug=(string)($row['slug']??'');
        if($slug==='')continue;
        try{$campaign=vp3_profile_webmcp_campaign_public_v170($pdo,$profile,$slug);}
        catch(Throwable $e){continue;}
        $out[]=vp3_profile_webmcp_campaign_projection_v170($pdo,$campaign,false);
    }
    return $out;
}

function vp3_profile_webmcp_campaign_get_v170(PDO $pdo,array $profile,string $slug): array
{
    return ['campaign'=>vp3_profile_webmcp_campaign_projection_v170($pdo,vp3_profile_webmcp_campaign_public_v170($pdo,$profile,$slug),true)];
}

function vp3_profile_webmcp_campaign_eligibility_v170(PDO $pdo,array $profile,string $slug): array
{
    $campaign=vp3_profile_webmcp_campaign_public_v170($pdo,$profile,$slug);
    $behavior=campaigns_rewards_campaign_type_behavior_v118($pdo,(int)$campaign['merchant_id'],(string)$campaign['campaign_type_key']);
    return [
        'campaign'=>[
            'public_id'=>(string)$campaign['public_id'],
            'slug'=>(string)$campaign['slug'],
            'title'=>(string)$campaign['title'],
        ],
        'public_participation'=>[
            'accepted'=>!empty($behavior['public']),
            'action'=>(string)($behavior['public_action']??'none'),
            'required_fields'=>array_values(array_map('strval',(array)($behavior['required_fields']??[]))),
            'marketing_consent'=>(string)($behavior['marketing']??'optional'),
            'requires_reward'=>!empty($behavior['requires_reward']),
        ],
        'personalized_eligibility_evaluated'=>false,
        'private_targeting_exposed'=>false,
    ];
}


function vp3_profile_webmcp_campaign_participation_input_schema_v170(): array
{
    return [
        'type'=>'object',
        'properties'=>[
            'campaign_slug'=>['type'=>'string','minLength'=>1,'maxLength'=>120],
            'name'=>['type'=>'string','maxLength'=>190],
            'email'=>['type'=>'string','maxLength'=>190],
            'phone'=>['type'=>'string','maxLength'=>80],
            'birthday'=>['type'=>'string','maxLength'=>40],
            'social_handle'=>['type'=>'string','maxLength'=>190],
            'proof_url'=>['type'=>'string','maxLength'=>2048],
            'referral_ref'=>['type'=>'string','maxLength'=>190],
            'marketing_consent'=>['type'=>'boolean'],
            'reward_public_id'=>['type'=>'string','maxLength'=>100],
        ],
        'required'=>['campaign_slug'],
        'additionalProperties'=>false,
    ];
}

function vp3_profile_webmcp_campaign_context_v170(
    array $profile,string $surface,array $telemetry,string $nativeProof='',?array $property=null,string $origin=''
): array {
    $owner=(int)($profile['user_id']??0);
    $username=(string)($profile['username']??'');
    $session=vp3_profile_webmcp_transport_id_v130((string)($telemetry['webmcp_session_id']??''));
    if($owner<1||$username===''||$session==='')throw new RuntimeException('WebMCP Campaign session is unavailable.');
    $propertyId=$surface==='external_site'?(int)($property['id']??0):0;
    if($surface==='native_profile'){
        if(!preg_match('/^[a-f0-9]{64}$/',trim($nativeProof)))throw new RuntimeException('Native Campaign authority is unavailable.');
        $secret=hash_hmac('sha256','campaigns-v170',vp3_profile_webmcp_native_signing_secret_v150($owner),true);
    }elseif($surface==='external_site'&&$property){
        $verification=strtolower(trim((string)($property['verification_token']??'')));
        $publicKey=strtolower(trim((string)($property['public_key']??'')));
        if($propertyId<1||$origin===''||empty($property['is_active'])||!preg_match('/^[a-f0-9]{64}$/',$verification)||!preg_match('/^[a-f0-9]{40}$/',$publicKey)){
            throw new RuntimeException('Connected-site Campaign authority is unavailable.');
        }
        $secret=hash('sha256','vp3-webmcp-campaign-v170|'.$publicKey.'|'.$verification,true);
    }else{
        throw new RuntimeException('WebMCP Campaign authority is unavailable.');
    }
    return [
        'owner_user_id'=>$owner,'profile_username'=>$username,'surface'=>$surface,'property_id'=>$propertyId,
        'session_hash'=>hash('sha256',$session),'origin_hash'=>$surface==='external_site'?hash('sha256',$origin):'',
        'secret'=>$secret,
    ];
}

function vp3_profile_webmcp_campaign_token_v170(array $action,array $context): string
{
    $payload=[
        'v'=>1,'owner_user_id'=>(int)$action['owner_user_id'],'profile_username'=>(string)$action['profile_username'],
        'surface'=>(string)$action['surface'],'property_id'=>(int)($action['property_id']??0),
        'session_hash'=>(string)$action['session_hash'],'origin_hash'=>(string)($context['origin_hash']??''),
        'intent_id'=>(string)$action['intent_id'],'operation'=>(string)$action['operation'],
        'payload_hash'=>(string)$action['payload_hash'],'exp'=>(int)$action['expires_at_unix'],
    ];
    $encoded=vp3_profile_webmcp_b64url_encode_v140(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $sig=hash_hmac('sha256',$encoded,(string)$context['secret'],true);
    return $encoded.'.'.vp3_profile_webmcp_b64url_encode_v140($sig);
}

function vp3_profile_webmcp_campaign_token_verify_v170(string $token,array $context,string $operation,array $intent): array
{
    $parts=explode('.',$token);if(count($parts)!==2)throw new RuntimeException('Campaign confirmation token is invalid.');
    [$encoded,$sigEncoded]=$parts;$sig=vp3_profile_webmcp_b64url_decode_v140($sigEncoded);
    $expected=hash_hmac('sha256',$encoded,(string)$context['secret'],true);
    if($sig===''||!hash_equals(vp3_profile_webmcp_b64url_encode_v140($sig),$sigEncoded)||!hash_equals($expected,$sig)){
        throw new RuntimeException('Campaign confirmation token is invalid.');
    }
    $json=vp3_profile_webmcp_b64url_decode_v140($encoded);$payload=$json!==''?json_decode($json,true):null;
    if(!is_array($payload)||($payload['v']??null)!==1)throw new RuntimeException('Campaign confirmation token is invalid.');
    if((int)($payload['exp']??0)<time())throw new RuntimeException('Campaign confirmation expired. Prepare participation again.');
    $checks=[
        (int)($payload['owner_user_id']??0)===(int)$context['owner_user_id'],
        hash_equals((string)($payload['profile_username']??''),(string)$context['profile_username']),
        hash_equals((string)($payload['surface']??''),(string)$context['surface']),
        (int)($payload['property_id']??0)===(int)($context['property_id']??0),
        hash_equals((string)($payload['session_hash']??''),(string)$context['session_hash']),
        hash_equals((string)($payload['origin_hash']??''),(string)($context['origin_hash']??'')),
        hash_equals((string)($payload['operation']??''),$operation),
        hash_equals((string)($payload['payload_hash']??''),vp3_profile_webmcp_payload_hash_v150($intent)),
    ];
    if(in_array(false,$checks,true)||!preg_match('/^[a-f0-9]{32}$/',(string)($payload['intent_id']??''))){
        throw new RuntimeException('Campaign confirmation does not match this session or action.');
    }
    return $payload;
}

function vp3_profile_webmcp_campaign_reward_v170(PDO $pdo,array $campaign,string $publicId): ?array
{
    $publicId=trim($publicId);if($publicId==='')return null;
    foreach(campaigns_rewards_rewards_v100($pdo,(int)$campaign['id'],true) as $reward){
        if(hash_equals((string)($reward['public_id']??''),$publicId))return $reward;
    }
    throw new RuntimeException('That Reward is not currently available for this Campaign.');
}

function vp3_profile_webmcp_campaign_state_hash_v170(PDO $pdo,array $campaign,?array $reward=null): string
{
    $behavior=campaigns_rewards_campaign_type_behavior_v118($pdo,(int)$campaign['merchant_id'],(string)$campaign['campaign_type_key']);
    return hash('sha256',json_encode([
        'campaign'=>[
            'id'=>(int)$campaign['id'],'public_id'=>(string)$campaign['public_id'],'slug'=>(string)$campaign['slug'],
            'status'=>(string)$campaign['status'],'environment'=>(string)$campaign['environment'],
            'starts_at'=>$campaign['starts_at']??null,'ends_at'=>$campaign['ends_at']??null,
            'current_version_no'=>(int)($campaign['current_version_no']??0),'updated_at'=>(string)($campaign['updated_at']??''),
            'is_published'=>(int)($campaign['is_published']??0),'visibility'=>(string)($campaign['visibility']??''),
            'published_at'=>(string)($campaign['published_at']??''),
        ],
        'behavior'=>[
            'public'=>!empty($behavior['public']),'public_action'=>(string)($behavior['public_action']??'none'),
            'required_fields'=>array_values((array)($behavior['required_fields']??[])),
            'marketing'=>(string)($behavior['marketing']??'optional'),'reward_timing'=>(string)($behavior['reward_timing']??'manual'),
            'requires_reward'=>!empty($behavior['requires_reward']),
        ],
        'reward'=>$reward?[
            'id'=>(int)($reward['id']??0),'public_id'=>(string)($reward['public_id']??''),
            'status'=>(string)($reward['status']??''),'starts_at'=>$reward['starts_at']??null,'ends_at'=>$reward['ends_at']??null,
            'per_customer_limit'=>(int)($reward['per_customer_limit']??$reward['claim_limit']??1),
        ]:null,
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function vp3_profile_webmcp_campaign_normalize_participation_v170(PDO $pdo,array $profile,array $input): array
{
    $campaign=vp3_profile_webmcp_campaign_public_v170($pdo,$profile,(string)($input['campaign_slug']??''));
    $behavior=campaigns_rewards_campaign_type_behavior_v118($pdo,(int)$campaign['merchant_id'],(string)$campaign['campaign_type_key']);
    if(empty($behavior['public']))throw new RuntimeException('This Campaign does not accept public participation.');
    $normalized=[
        'campaign_id'=>(int)$campaign['id'],'campaign_slug'=>(string)$campaign['slug'],
        'name'=>mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)($input['name']??''))??''),0,190,''),
        'email'=>mb_strimwidth(strtolower(trim((string)($input['email']??''))),0,190,''),
        'phone'=>mb_strimwidth(trim((string)($input['phone']??'')),0,80,''),
        'birthday'=>mb_strimwidth(trim((string)($input['birthday']??'')),0,40,''),
        'social_handle'=>mb_strimwidth(trim((string)($input['social_handle']??'')),0,190,''),
        'proof_url'=>mb_strimwidth(trim((string)($input['proof_url']??'')),0,2048,''),
        'referral_ref'=>mb_strimwidth(trim((string)($input['referral_ref']??'')),0,190,''),
        'marketing_consent'=>!empty($input['marketing_consent']),
        'reward_public_id'=>mb_strimwidth(trim((string)($input['reward_public_id']??'')),0,100,''),
    ];
    if($normalized['proof_url']!==''){
        $parts=parse_url($normalized['proof_url']);
        $scheme=strtolower((string)($parts['scheme']??''));
        if(!filter_var($normalized['proof_url'],FILTER_VALIDATE_URL)||!in_array($scheme,['http','https'],true)){
            throw new RuntimeException('Add a valid HTTP or HTTPS proof or content link.');
        }
    }
    if(in_array('referral_ref',(array)($behavior['required_fields']??[]),true)&&$normalized['referral_ref']===''){
        throw new RuntimeException('This Referral Campaign requires an explicit referral link or code.');
    }
    campaigns_rewards_public_validate_v118($behavior,$normalized,$campaign);
    $reward=vp3_profile_webmcp_campaign_reward_v170($pdo,$campaign,$normalized['reward_public_id']);
    $timing=(string)($behavior['reward_timing']??'manual');
    if(in_array($timing,['immediate','immediate_if_attached'],true)&&!empty($behavior['requires_reward'])&&!$reward){
        throw new RuntimeException('Choose an available Reward for this Campaign.');
    }
    $normalized['campaign_state_hash']=vp3_profile_webmcp_campaign_state_hash_v170($pdo,$campaign,$reward);
    return $normalized;
}

function vp3_profile_webmcp_campaign_prepare_v170(PDO $pdo,array $profile,array $context,array $input): array
{
    if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo))throw new RuntimeException('Campaign confirmation ledger is unavailable.');
    $intent=vp3_profile_webmcp_campaign_normalize_participation_v170($pdo,$profile,$input);
    $campaign=vp3_profile_webmcp_campaign_public_v170($pdo,$profile,(string)$intent['campaign_slug']);
    $reward=vp3_profile_webmcp_campaign_reward_v170($pdo,$campaign,(string)$intent['reward_public_id']);
    $action=vp3_profile_webmcp_action_prepare_v150($pdo,$context,'campaign.participate',$intent,VP3_PROFILE_WEBMCP_CAMPAIGN_INTENT_TTL_V170);
    return [
        'intent_id'=>$action['intent_id'],'confirmation_token'=>vp3_profile_webmcp_campaign_token_v170($action,$context),
        'expires_at_unix'=>$action['expires_at_unix'],'intent'=>$intent,
        'preview'=>[
            'campaign'=>vp3_profile_webmcp_campaign_projection_v170($pdo,$campaign,false),
            'reward'=>$reward?vp3_profile_webmcp_campaign_reward_projection_v170($reward):null,
            'public_action'=>(string)(campaigns_rewards_campaign_type_behavior_v118($pdo,(int)$campaign['merchant_id'],(string)$campaign['campaign_type_key'])['public_action']??'participate'),
            'marketing_consent'=>$intent['marketing_consent'],
        ],
        'confirmation_required'=>true,
    ];
}

function vp3_profile_webmcp_campaign_enrollment_projection_v170(PDO $pdo,array $profile,int $enrollmentId): array
{
    if($enrollmentId<1)throw new RuntimeException('Campaign participation result is unavailable.');
    $stmt=$pdo->prepare("SELECT e.id,e.status,e.created_at,e.updated_at,e.completed_at,c.public_id campaign_public_id,c.slug campaign_slug,c.name campaign_title,c.campaign_type_id,c.merchant_id,ct.type_key campaign_type_key
      FROM campaign_enrollments e
      INNER JOIN campaigns c ON c.id=e.campaign_id
      INNER JOIN merchant_accounts m ON m.id=c.merchant_id
      INNER JOIN campaign_types ct ON ct.id=c.campaign_type_id
      WHERE e.id=? AND m.owner_user_id=? LIMIT 1");
    $stmt->execute([$enrollmentId,(int)$profile['user_id']]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Campaign participation result is unavailable.');
    return [
        'campaign'=>[
            'public_id'=>(string)$row['campaign_public_id'],'slug'=>(string)$row['campaign_slug'],
            'title'=>(string)$row['campaign_title'],'campaign_type'=>(string)$row['campaign_type_key'],
        ],
        'participation'=>[
            'status'=>(string)$row['status'],'created_at'=>(string)$row['created_at'],
            'updated_at'=>(string)$row['updated_at'],'completed_at'=>$row['completed_at']?:null,
        ],
    ];
}

function vp3_profile_webmcp_campaign_confirm_v170(
    PDO $pdo,array $profile,array $context,array $intent,string $confirmationToken,string $idempotencyKey
): array {
    $operation='campaign.participate';
    $verified=vp3_profile_webmcp_campaign_token_verify_v170($confirmationToken,$context,$operation,$intent);
    $owner=(int)$profile['user_id'];$intentId=(string)$verified['intent_id'];
    $idem=vp3_profile_webmcp_idempotency_hash_v150($owner,$operation,$idempotencyKey);
    $lock='vp3_webmcp_idem_'.substr($idem,0,32);
    $lockStmt=$pdo->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute([$lock]);
    if((int)$lockStmt->fetchColumn()!==1)throw new RuntimeException('That Campaign confirmation is already in progress. Retry with the same idempotency key.');
    $started=!$pdo->inTransaction();
    try{
        if($started)$pdo->beginTransaction();
        $action=vp3_profile_webmcp_action_row_v150($pdo,$owner,$intentId,true);
        if(!$action)throw new RuntimeException('Prepared Campaign action was not found.');
        if((string)$action['operation']!==$operation||!hash_equals((string)$action['profile_username'],(string)$context['profile_username'])){
            throw new RuntimeException('Prepared Campaign authority changed.');
        }
        if(!hash_equals((string)$action['payload_hash'],vp3_profile_webmcp_payload_hash_v150($intent))){
            throw new RuntimeException('Prepared Campaign payload changed.');
        }
        if(!hash_equals((string)$action['session_hash'],(string)$context['session_hash'])||(string)$action['surface']!==(string)$context['surface']||(int)($action['property_id']??0)!==(int)($context['property_id']??0)){
            throw new RuntimeException('Prepared Campaign action belongs to a different WebMCP session.');
        }
        if((string)$action['state']==='committed'){
            if(!hash_equals((string)$action['idempotency_hash'],$idem))throw new RuntimeException('This Campaign intent was already confirmed with a different idempotency key.');
            $result=vp3_profile_webmcp_campaign_enrollment_projection_v170($pdo,$profile,(int)($action['result_id']??0));
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        if((string)$action['state']!=='prepared')throw new RuntimeException('Prepared Campaign action is no longer executable.');
        $expires=(new DateTimeImmutable((string)$action['expires_at'],new DateTimeZone('UTC')))->getTimestamp();
        if($expires<time())throw new RuntimeException('Campaign confirmation expired. Prepare participation again.');
        $existing=vp3_profile_webmcp_action_by_idempotency_v150($pdo,$owner,$operation,$idem,true);
        if($existing&&(int)$existing['id']!==(int)$action['id']){
            if(!hash_equals((string)$existing['payload_hash'],(string)$action['payload_hash']))throw new RuntimeException('Idempotency key was already used for different Campaign participation.');
            if((string)$existing['state']!=='committed')throw new RuntimeException('That Campaign participation is still in progress.');
            $result=vp3_profile_webmcp_campaign_enrollment_projection_v170($pdo,$profile,(int)($existing['result_id']??0));
            $result['idempotent_replay']=true;if($started)$pdo->commit();return $result;
        }
        $claim=$pdo->prepare("UPDATE profile_webmcp_actions SET idempotency_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND state='prepared' AND idempotency_hash IS NULL");
        $claim->execute([$idem,(int)$action['id']]);
        if($claim->rowCount()!==1&&!hash_equals((string)($action['idempotency_hash']??''),$idem))throw new RuntimeException('Campaign idempotency claim changed before confirmation.');

        $campaign=vp3_profile_webmcp_campaign_public_v170($pdo,$profile,(string)($intent['campaign_slug']??''));
        $reward=vp3_profile_webmcp_campaign_reward_v170($pdo,$campaign,(string)($intent['reward_public_id']??''));
        if(!hash_equals((string)($intent['campaign_state_hash']??''),vp3_profile_webmcp_campaign_state_hash_v170($pdo,$campaign,$reward))){
            throw new RuntimeException('This Campaign or selected Reward changed. Prepare participation again.');
        }
        $publicInput=[
            'name'=>(string)($intent['name']??''),'email'=>(string)($intent['email']??''),'phone'=>(string)($intent['phone']??''),
            'birthday'=>(string)($intent['birthday']??''),'social_handle'=>(string)($intent['social_handle']??''),
            'proof_url'=>(string)($intent['proof_url']??''),'referral_ref'=>(string)($intent['referral_ref']??''),
            'marketing_consent'=>!empty($intent['marketing_consent']),
        ];
        $canonical=campaigns_rewards_public_participate_v118($pdo,$campaign,$publicInput,'webmcp-v170:'.$intentId,$reward);
        $enrollmentId=(int)($canonical['enrollment']['id']??0);
        if($enrollmentId<1)throw new RuntimeException('Canonical Campaign participation did not return an enrollment.');
        $result=vp3_profile_webmcp_campaign_enrollment_projection_v170($pdo,$profile,$enrollmentId);
        vp3_profile_webmcp_action_commit_v150($pdo,(int)$action['id'],$idem,'campaign_enrollment',$enrollmentId,$result);
        if($started)$pdo->commit();
        return $result;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }finally{
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}catch(Throwable $ignored){}
    }
}
