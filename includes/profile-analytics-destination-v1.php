<?php
declare(strict_types=1);
require_once __DIR__.'/profile-analytics-period-ref-v1.php';

/** Parse the original query, before PHP page selection or another return route can rewrite it. */
function vp3_profile_analytics_destination_request_v1(string $query,?DateTimeImmutable $now=null): ?array
{
    if($query===''||strlen($query)>8192)return null;
    $keys=['agent','conversation_id','profile_analytics'];$values=[];
    foreach(explode('&',$query) as $pair){
        [$name,$value]=array_pad(explode('=',$pair,2),2,'');
        if(preg_match('/%(?![0-9a-fA-F]{2})/',$name))return null;
        $name=urldecode($name);
        $normalized=str_replace([' ','.'],'_',trim($name));
        if(preg_match('/\A(?:agent|conversation_id|profile_analytics)\[/D',$name)
            ||(in_array($normalized,$keys,true)&&$name!==$normalized))return null;
        // A fixed destination has exactly these three keys. Competing return/tool hints are not inherited.
        if(!in_array($name,$keys,true))return null;
        if(isset($values[$name])||strlen($value)>1024||preg_match('/%(?![0-9a-fA-F]{2})/',$value))return null;
        $values[$name]=urldecode($value);
    }
    if(count($values)!==3)return null;
    $positive=static function(string $value): ?int {
        if(!preg_match('/\A[1-9][0-9]{0,15}\z/D',$value))return null;
        $number=(int)$value;
        return $number>0&&$number<=9007199254740991?$number:null;
    };
    $agent=$values['agent']==='system'?0:$positive($values['agent']);
    $conversation=$positive($values['conversation_id']);
    if($agent===null||$conversation===null)return null;
    try{$period=vp3_profile_analytics_period_decode_v1($values['profile_analytics'],$now);}
    catch(Throwable $e){return null;}
    return ['agent_id'=>$agent,'conversation_id'=>$conversation,'ref'=>$period['ref']];
}

/** Bind only to the authorized scope that the page actually selected; never inherit a fallback. */
function vp3_profile_analytics_destination_bind_v1(?array $requested,int $owner,int $agent,int $conversation,
    string $binding,string $chatUrl,bool $allowed): ?array
{
    if(!$allowed||$requested===null||$owner<=0||$owner>9007199254740991||$agent<0||$conversation<=0
        ||$binding===''||($requested['agent_id']??null)!==$agent||($requested['conversation_id']??null)!==$conversation
        ||!is_string($requested['ref']??null)||!str_starts_with($chatUrl,'/')||str_starts_with($chatUrl,'//')
        ||!str_ends_with($chatUrl,'/chat.php')||parse_url($chatUrl,PHP_URL_PATH)!==$chatUrl
        ||preg_match('/[\x00-\x20\\\\]/',$chatUrl))return null;
    $href=$chatUrl.'?'.http_build_query(['agent'=>$agent===0?'system':(string)$agent,
        'conversation_id'=>(string)$conversation,'profile_analytics'=>$requested['ref']],'','&',PHP_QUERY_RFC3986);
    return ['ownerUserId'=>$owner,'chatAgentId'=>$agent,'chatConversationId'=>$conversation,
        'workingAttentionBinding'=>$binding,'ref'=>$requested['ref'],'href'=>$href];
}
