<?php
declare(strict_types=1);

require_once __DIR__.'/campaigns-rewards-v126.php';

const VP3_PROFILE_WEBMCP_CAMPAIGNS_V170='profile-webmcp-campaigns-v170-20260928';

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
