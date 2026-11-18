<?php
declare(strict_types=1);

/** Explicit catch-up reuses the same current source catalog as Marketing. */
function vp3_catch_up_product_marketing_v1(PDO $pdo,array $user,int $agent,int $chat,DateTimeImmutable $now): array
{
    if($chat<1)return ['unconfigured',[]];
    require_once __DIR__.'/agent-coordination-product-marketing-v1.php';
    $resolved=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agent);
    $scope=['user'=>$user,'agent_id'=>$agent,'conversation_id'=>$chat,'agent'=>$resolved,
        'principal'=>vp3_agent_chat_principal_v380($user,$resolved)];
    $snapshot=vp3_coordination_product_marketing_sources_v1($pdo,$scope,null,$now);
    $coverage=$snapshot['coverage']??'unavailable';
    if(!in_array($coverage,['ready','limited'],true))return [in_array($coverage,['unavailable','unconfigured'],true)?$coverage:'unavailable',[]];
    $items=[];
    foreach($snapshot['items'] as $ref=>$source){
        $kind=vp3_product_marketing_ref_kind_v1($ref);
        if($kind===null||($source['ref']??null)!==$ref||!is_string($source['revision']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$source['revision'])){$coverage='limited';continue;}
        $period=$kind==='followthrough';
        $items[]=['id'=>$ref,'title'=>$source['title'],'reason'=>$source['summary'],
            'source_label'=>$period?'Product promotion period':'Product marketing plan','url'=>$source['url'],
            'priority'=>$period?18:12,'time'=>0,'status'=>$period?'completed_period':'saved_plan',
            '_source_revision'=>$source['revision']];
    }
    return [$coverage,$items];
}
