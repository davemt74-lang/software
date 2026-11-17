<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/participant-updates-v1.php';

const VP3_MARKETING_OFFER_REWARD_PURPOSE_V1 = 'marketing_offer_reward_draft_v1';

/** Use the Merchant owner's existing location assignments for this private offer. */
function vp3_marketing_offer_locations_v1(PDO $pdo, array $user, int $merchantId, ?int $locationId=null, bool $lock=false): array
{
    $owner=(int)($user['id']??0);
    if($owner<1||!has_permission('account.access',$user)||vp3_participant_scope_v1()!==null
        ||!campaigns_rewards_enabled_v100($user,$pdo))return [];
    $merchant=campaigns_rewards_platform_merchant_v100($pdo,$merchantId,$lock);
    if(!$merchant||(int)$merchant['owner_user_id']!==$owner||$merchant['status']!=='active')return [];
    $member=campaigns_rewards_platform_member_v100($pdo,$merchantId,$owner,$lock);
    if(($member['status']??null)!=='active'||empty($member['is_owner'])
        ||!campaigns_rewards_platform_can_v100($pdo,$merchantId,$owner,'campaigns.create',$lock)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchantId,$owner,'campaigns.edit',$lock))return [];
    return campaigns_rewards_claim_locations_v100($pdo,$merchantId,$owner,$locationId,$lock);
}

/** The planned product is never attached as an issuable Reward. */
function vp3_marketing_offer_reward_v1(PDO $pdo, int $merchantId, int $campaignId): ?array
{
    if($campaignId<1)return null;
    $query=$pdo->prepare("SELECT p.id,p.name,p.is_active FROM campaigns_rewards_object_bindings b
        JOIN reward_products p ON p.merchant_id=b.merchant_id AND p.id=CAST(b.target_id AS UNSIGNED)
            AND BINARY p.public_id=BINARY b.target_public_id
        WHERE b.merchant_id=? AND b.subject_type='campaign' AND b.subject_id=? AND b.purpose=?
            AND b.target_type='reward_product' LIMIT 1");
    $query->execute([$merchantId,$campaignId,VP3_MARKETING_OFFER_REWARD_PURPOSE_V1]);
    return $query->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_marketing_offer_text_v1(mixed $value, string $label, int $limit, bool $required=true): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value)){
        throw new DomainException('Enter valid text for '.$label.'.',422);
    }
    $value=trim($value);
    if(($required&&$value==='')||mb_strlen($value,'UTF-8')>$limit)throw new DomainException('Check the length of '.$label.'.',422);
    return $value;
}

/** Owner-confirmed creation using the same Campaign and Reward operations as their workspaces. */
function vp3_marketing_offer_create_draft_v1(PDO $pdo, array $user, int $merchantId, array $input): array
{
    $owner=(int)($user['id']??0);
    $key=$input['draft_request']??null;
    $locationId=filter_var($input['location_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||$locationId===false){
        throw new DomainException('Reload the draft form and choose an active Merchant Location.',422);
    }
    $name=vp3_marketing_offer_text_v1($input['campaign_name']??null,'the Campaign name',190);
    $objective=vp3_marketing_offer_text_v1($input['objective']??null,'the objective',500);
    $reward=vp3_marketing_offer_text_v1($input['reward_name']??'','the Reward name',190,false);
    $start=$input['starts_at']??'';
    $end=$input['ends_at']??'';
    foreach([$start,$end] as $time){
        if(!is_string($time)||($time!==''&&!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$time))){
            throw new DomainException('Enter valid start and end times.',422);
        }
    }
    if(($start==='')!==($end===''))throw new DomainException('Enter both offer times, or leave both blank.',422);

    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$owner,$merchantId,$locationId,$key,$name,$objective,$reward,$start,$end):array{
        $location=vp3_marketing_offer_locations_v1($pdo,$user,$merchantId,$locationId,true)[0]??null;
        if(!$location)throw new DomainException('Choose a permitted active Location for a Merchant you own.',403);
        if($reward!=='')campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$owner,'rewards.manage',true);
        $receipt=campaigns_rewards_idempotency_begin_v100($pdo,$merchantId,'marketing.weekend_offer_draft',$owner.':'.$key,
            [$locationId,$name,$objective,$reward,$start,$end],true);
        if(!$receipt['new']){
            $campaignId=(int)($receipt['result_ref_id']??0);
            $campaign=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);
            if(($receipt['status']??null)!=='completed'||($receipt['result_ref_type']??null)!=='campaign'
                ||!$campaign||(int)$campaign['merchant_id']!==$merchantId||(int)$campaign['owner_user_id']!==$owner){
                throw new DomainException('The previous draft request could not be verified.',409);
            }
            $planned=vp3_marketing_offer_reward_v1($pdo,$merchantId,$campaignId);
            return ['campaign_id'=>$campaignId,'location_id'=>$locationId,'reward_product_id'=>isset($planned['id'])?(int)$planned['id']:null,'created'=>false];
        }

        $campaignInput=['name'=>$name,'objective'=>$objective,'campaign_type'=>'flash_drop','visibility'=>'private'];
        if($start!==''){
            try{$zone=new DateTimeZone((string)$location['timezone']);}
            catch(Exception $error){throw new DomainException('Update the Location’s time zone before setting offer times.',422,$error);}
            foreach(['starts_at'=>$start,'ends_at'=>$end] as $field=>$time){
                $parsed=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$time,$zone);
                if(!$parsed||$parsed->format('Y-m-d\TH:i')!==$time)throw new DomainException('Enter a valid time in the Location’s time zone.',422);
                $campaignInput[$field]=$parsed->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            }
            if($campaignInput['ends_at']<=$campaignInput['starts_at'])throw new DomainException('The offer end must be after its start.',422);
        }
        $campaign=campaigns_rewards_create_campaign_v100($pdo,$merchantId,$owner,$campaignInput);
        $campaignId=(int)$campaign['id'];
        $bind=$pdo->prepare("INSERT INTO campaigns_rewards_object_bindings
            (merchant_id,subject_type,subject_id,purpose,target_type,target_id,target_public_id,settings_json)
            VALUES (?,'campaign',?,?,?,?,?,'{}')");
        $bind->execute([$merchantId,$campaignId,'location','merchant_location',(string)$locationId,(string)$location['public_id']]);

        $rewardId=null;
        if($reward!==''){
            $product=campaigns_rewards_save_reward_product_v100($pdo,$merchantId,$owner,
                ['name'=>$reward,'reward_type'=>'promotional_product','is_active'=>false]);
            $rewardId=(int)$product['id'];
            $bind->execute([$merchantId,$campaignId,VP3_MARKETING_OFFER_REWARD_PURPOSE_V1,'reward_product',(string)$rewardId,(string)$product['public_id']]);
        }
        campaigns_rewards_idempotency_complete_v100($pdo,(int)$receipt['id'],'campaign',$campaignId);
        return ['campaign_id'=>$campaignId,'location_id'=>$locationId,'reward_product_id'=>$rewardId,'created'=>true];
    });
}
