<?php
declare(strict_types=1);

require_once __DIR__.'/profile-analytics-period-ref-v1.php';

/** Pure canonical identities; no database, schema installation or presenter. */
function vp3_product_marketing_ref_parse_v1(string $ref,?DateTimeImmutable $now=null): ?array
{
    if(strlen($ref)>165)return null;
    if(preg_match('/\Amarketing:product-plan:([1-9][0-9]{0,15})\z/D',$ref,$m)){
        $id=(int)$m[1];return $id<=9007199254740991?['kind'=>'plan','ref'=>$ref,'product_id'=>$id]:null;
    }
    if(!preg_match('/\Amarketing:product-outcome:([a-f0-9]{32}):(v1:.+)\z/D',$ref,$m))return null;
    try{$period=vp3_profile_analytics_period_decode_v1('profile:analytics:'.$m[2],$now);}
    catch(RuntimeException $error){if($error->getCode()!==422)throw $error;return null;}
    return ['kind'=>'outcome','ref'=>$ref,'publication_id'=>$m[1],'period_ref'=>$period['ref'],'period'=>$period];
}
function vp3_product_marketing_plan_ref_v1(int $product): string
{
    $ref='marketing:product-plan:'.$product;
    if(vp3_product_marketing_ref_parse_v1($ref)===null)throw new RuntimeException('Choose an exact product marketing plan.',422);
    return $ref;
}
function vp3_product_marketing_outcome_ref_v1(string $publication,string $periodRef,?DateTimeImmutable $now=null): string
{
    $ref='marketing:product-outcome:'.$publication.':'.substr($periodRef,strlen('profile:analytics:'));
    $parsed=vp3_product_marketing_ref_parse_v1($ref,$now);
    if(!$parsed||$parsed['period_ref']!==$periodRef)throw new RuntimeException('Choose an exact publication period.',422);
    return $ref;
}
