<?php
declare(strict_types=1);

require_once __DIR__.'/initiative-work-product-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/profile-analytics-authority-v1.php';
require_once __DIR__.'/profile-commerce-followthrough-v1.php';

const VP3_MARKETING_FOLLOWTHROUGH_V1='vp3.marketing-followthrough.v1';

/** Keep duplicate keys and PHP-normalized names from changing an exact read. */
function vp3_marketing_followthrough_query_v1(string $query): array
{
    if($query===''||strlen($query)>8192)throw new RuntimeException('Choose an exact marketing report.',422);
    $input=[];
    foreach(explode('&',$query) as $pair){
        [$key,$value]=array_pad(explode('=',$pair,2),2,'');
        if(preg_match('/%(?![a-fA-F0-9]{2})/',$pair))throw new RuntimeException('Choose a valid marketing query.',422);
        $key=urldecode($key);$value=urldecode($value);
        if(!preg_match('/\A[a-z][a-z_]{0,40}\z/D',$key)||array_key_exists($key,$input)||strlen($value)>2048)
            throw new RuntimeException('Choose a valid marketing query.',422);
        $input[$key]=$value;
    }
    return $input;
}

function vp3_marketing_followthrough_id_v1(mixed $value,bool $allowZero=false): int
{
    if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D',$value))$value=(int)$value;
    if(!is_int($value)||$value<($allowZero?0:1)||$value>9007199254740991)
        throw new RuntimeException('Choose an exact original Chat and product.',422);
    return $value;
}

function vp3_marketing_followthrough_scope_v1(PDO $pdo,array $user,int $agent,int $chat): array
{
    $owner=(int)($user['id']??0);
    $scope=['agent_id'=>$agent,'conversation_id'=>$chat];
    $fresh=initiative_work_product_scope_v1($pdo,$owner,$scope);
    $resolved=vp3_agent_chat_resolve_agent_v380($pdo,$fresh,$agent);
    return $scope+['user'=>$fresh,'agent'=>$resolved,'principal'=>vp3_agent_chat_principal_v380($fresh,$resolved)];
}

/** Query-only endpoint projection. No plan opening, provider dispatch or delivery ACK. */
function vp3_marketing_followthrough_request_v1(PDO $pdo,array $user,array $input,string $binding,?callable $sessionGuard=null): array
{
    $action=$input['action']??null;
    if(!is_string($action)||!in_array($action,['publications','report'],true))
        throw new RuntimeException('Choose a valid marketing read.',422);
    $allowed=$action==='publications'?['product_id','cursor']:['ref','publication_id','period'];
    if(array_diff(array_keys($input),array_merge(['action','chat_agent_id','chat_conversation_id'],$allowed)))
        throw new RuntimeException('Unexpected marketing request fields.',422);
    if($binding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$binding))
        throw new RuntimeException('Your Chat session changed.',403);
    $agent=vp3_marketing_followthrough_id_v1($input['chat_agent_id']??null,true);
    $chat=vp3_marketing_followthrough_id_v1($input['chat_conversation_id']??null);
    $scope=vp3_marketing_followthrough_scope_v1($pdo,$user,$agent,$chat);
    if($action==='publications'){
        $cursor=$input['cursor']??'';
        if(!is_string($cursor)||strlen($cursor)>2048)throw new RuntimeException('Choose a valid publication page.',422);
        $data=['publications'=>vp3_profile_commerce_publications_v1($pdo,$scope,
            vp3_marketing_followthrough_id_v1($input['product_id']??null),$cursor)];
    }else{
        $selection=array_intersect_key($input,array_flip(['ref','publication_id','period']));
        if(array_key_exists('period',$selection))$selection['period']=vp3_marketing_followthrough_id_v1($selection['period']);
        if(isset($selection['ref'])&&(array_key_exists('publication_id',$selection)||array_key_exists('period',$selection)))
            throw new RuntimeException('Choose either a fixed report or a publication period.',422);
        if(is_string($selection['ref']??null))$selection['ref']=vp3_profile_commerce_followthrough_legacy_ref_v1($selection['ref'])??$selection['ref'];
        $data=['followthrough'=>vp3_profile_commerce_followthrough_v1($pdo,$scope,$selection)];
    }
    // The principal and browser session must still be current after all source
    // reads. This fence is outside a collector-owned repeatable-read snapshot.
    $owner=(int)$scope['user']['id'];
    $fresh=$sessionGuard?$sessionGuard():$user;
    if((int)($fresh['id']??0)!==$owner||!hash_equals(vp3_catch_up_page_binding_v1($fresh),$binding))
        throw new RuntimeException('Your Chat session changed.',403);
    $scope['user']=$fresh;$own=!$pdo->inTransaction();
    if($own)$pdo->beginTransaction();
    try{
        vp3_profile_analytics_authority_current_v1($pdo,$scope,$action==='report');
        if($own)$pdo->commit();
    }catch(Throwable $error){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    return ['contract'=>VP3_MARKETING_FOLLOWTHROUGH_V1,'action'=>$action,
        'scope'=>['owner_user_id'=>$owner,'chat_agent_id'=>$agent,'chat_conversation_id'=>$chat],
        'working_attention_binding'=>$binding,'data'=>$data];
}
