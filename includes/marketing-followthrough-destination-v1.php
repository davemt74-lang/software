<?php
declare(strict_types=1);
require_once __DIR__.'/profile-commerce-followthrough-ref-v1.php';

/** Read the original URL before Chat applies a fallback or another return route. */
function vp3_marketing_followthrough_destination_request_v1(string $query,?DateTimeImmutable $now=null): ?array
{
    if($query===''||strlen($query)>8192)return null;
    $keys=['agent','conversation_id','marketing_followthrough'];$values=[];
    foreach(explode('&',$query) as $pair){
        [$name,$value]=array_pad(explode('=',$pair,2),2,'');
        if(preg_match('/%(?![0-9a-fA-F]{2})/',$pair))return null;
        $name=urldecode($name);$value=urldecode($value);
        if(!in_array($name,$keys,true)||array_key_exists($name,$values)||strlen($value)>1024)return null;
        $values[$name]=$value;
    }
    if(count($values)!==3)return null;
    $positive=static function(string $value): ?int{
        if(!preg_match('/\A[1-9][0-9]{0,15}\z/D',$value))return null;
        $number=(int)$value;return $number>0&&$number<=9007199254740991?$number:null;
    };
    $agent=$values['agent']==='system'?0:$positive($values['agent']);
    $conversation=$positive($values['conversation_id']);
    if($agent===null||$conversation===null)return null;
    try{$period=vp3_profile_commerce_followthrough_decode_v1($values['marketing_followthrough'],$now);}
    catch(Throwable){return null;}
    return ['agent_id'=>$agent,'conversation_id'=>$conversation,'ref'=>$period['ref']];
}

function vp3_marketing_followthrough_destination_bind_v1(?array $requested,int $owner,int $agent,int $conversation,
    string $binding,string $chatUrl,bool $allowed): ?array
{
    if(!$allowed||$requested===null||$owner<1||$owner>9007199254740991||$agent<0||$conversation<1
        ||$binding===''||($requested['agent_id']??null)!==$agent||($requested['conversation_id']??null)!==$conversation
        ||!is_string($requested['ref']??null)||!str_starts_with($chatUrl,'/')||str_starts_with($chatUrl,'//')
        ||!str_ends_with($chatUrl,'/chat.php')||parse_url($chatUrl,PHP_URL_PATH)!==$chatUrl
        ||preg_match('/[\x00-\x20\\\\]/',$chatUrl))return null;
    $href=$chatUrl.'?'.http_build_query(['agent'=>$agent===0?'system':(string)$agent,'conversation_id'=>(string)$conversation,
        'marketing_followthrough'=>$requested['ref']],'','&',PHP_QUERY_RFC3986);
    return ['ownerUserId'=>$owner,'chatAgentId'=>$agent,'chatConversationId'=>$conversation,
        'workingAttentionBinding'=>$binding,'ref'=>$requested['ref'],'href'=>$href];
}
