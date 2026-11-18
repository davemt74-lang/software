<?php
declare(strict_types=1);

/** An explicit private Chat may summarize existing plans and fixed publication periods. */
function vp3_catch_up_product_marketing_v1(PDO $pdo,array $user,int $agent,int $chat,DateTimeImmutable $now): array
{
    if($chat<1)return ['unconfigured',[]];
    require_once __DIR__.'/schema-metadata.php';
    foreach(['agent_product_launches_v1','agent_commerce_products_v800','agent_commerce_marketing_drafts_v1',
        'agent_commerce_promotions_v1','user_media_assets'] as $table)
        if(!schema_metadata_table_exists($pdo,$table))return ['unconfigured',[]];
    require_once __DIR__.'/agent-coordination-product-marketing-v1.php';
    $resolved=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agent);
    $scope=['user'=>$user,'agent_id'=>$agent,'conversation_id'=>$chat,'agent'=>$resolved,
        'principal'=>vp3_agent_chat_principal_v380($user,$resolved)];
    $review=vp3_product_marketing_review_v1($pdo,$scope,$now);
    $coverage=$review['coverage']??'unavailable';
    if(!in_array($coverage,['ready','limited'],true))return [in_array($coverage,['unavailable','unconfigured'],true)?$coverage:'unavailable',[]];
    $items=[];
    foreach($review['sources'] as $ref=>$source){
        $identity=vp3_product_marketing_ref_parse_v1($ref,$now);
        if($identity===null||($source['ref']??null)!==$ref||!is_string($source['revision']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$source['revision'])){$coverage='limited';continue;}
        $period=str_starts_with($ref,'marketing:product-outcome:');
        $items[]=['id'=>$ref,'title'=>$source['title'],'reason'=>$source['summary'],
            'source_label'=>$period?'Product promotion period':'Product marketing plan','url'=>$source['url'],
            'priority'=>$period?18:12,'time'=>0,'status'=>$period?'completed_period':'saved_plan',
            '_source_revision'=>$source['revision']];
    }
    return [$coverage,$items];
}
