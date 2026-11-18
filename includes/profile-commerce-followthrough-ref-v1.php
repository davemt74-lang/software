<?php
declare(strict_types=1);

require_once __DIR__.'/profile-analytics-period-ref-v1.php';

const VP3_PROFILE_COMMERCE_FOLLOWTHROUGH_V1='vp3.profile-commerce-followthrough.v1';

/** Publication and completed owner-local period form one immutable read identity. */
function vp3_profile_commerce_followthrough_ref_v1(string $publicationId,string $periodRef): string
{
    if(!preg_match('/\A[a-f0-9]{32}\z/D',$publicationId))throw new RuntimeException('Choose an exact promotion publication.',422);
    $period=vp3_profile_analytics_period_decode_v1($periodRef);
    return 'marketing:ft:v1:'.$publicationId.':'.substr($period['ref'],strlen('profile:analytics:v1:'));
}

function vp3_profile_commerce_followthrough_decode_v1(string $canonicalRef,?DateTimeImmutable $now=null): array
{
    if(strlen($canonicalRef)>165||!preg_match('/\Amarketing:ft:v1:([a-f0-9]{32}):((?:1|7|30):[1-9][0-9]{8,9}:[esu]:[A-Za-z0-9_-]{1,100})\z/D',$canonicalRef,$match))
        throw new RuntimeException('Choose an exact promotion reporting period.',422);
    $periodRef='profile:analytics:v1:'.$match[2];
    $period=vp3_profile_analytics_period_decode_v1($periodRef,$now);
    $options=$period['period_options'];
    foreach($options as &$option)$option['ref']='marketing:ft:v1:'.$match[1].':'.substr($option['ref'],strlen('profile:analytics:v1:'));
    unset($option);
    return array_replace($period,['ref'=>$canonicalRef,'publication_id'=>$match[1],'period_ref'=>$periodRef,'period_options'=>$options]);
}

/** Translate only the released predecessor's exact identity; never refresh its period. */
function vp3_profile_commerce_followthrough_legacy_ref_v1(string $ref,?DateTimeImmutable $now=null): ?string
{
    if(strlen($ref)>165||!preg_match('/\Amarketing:product-outcome:([a-f0-9]{32}):v1:(.+)\z/D',$ref,$match))return null;
    $canonical='marketing:ft:v1:'.$match[1].':'.$match[2];
    try{return vp3_profile_commerce_followthrough_decode_v1($canonical,$now)['ref'];}
    catch(RuntimeException $error){if($error->getCode()!==422)throw $error;return null;}
}

function vp3_profile_commerce_followthrough_href_v1(int $agent,int $chat,string $canonicalRef): string
{
    if($agent<0||$agent>9007199254740991||$chat<1||$chat>9007199254740991)
        throw new InvalidArgumentException('Invalid promotion reporting destination.');
    vp3_profile_commerce_followthrough_decode_v1($canonicalRef);
    $path='/chat.php?agent='.($agent>0?$agent:'system').'&conversation_id='.$chat
        .'&marketing_followthrough='.rawurlencode($canonicalRef);
    return function_exists('url')?url($path):$path;
}
