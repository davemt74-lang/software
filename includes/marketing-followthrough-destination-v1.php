<?php
declare(strict_types=1);
require_once __DIR__.'/profile-commerce-followthrough-ref-v1.php';

/** Read the original URL before Chat applies a fallback or another return route. */
function vp3_marketing_followthrough_destination_request_v1(string $query,?DateTimeImmutable $now=null): ?array
{
    if($query===''||strlen($query)>8192)return null;
    $keys=['agent','conversation_id','marketing_followthrough','marketing_plan','marketing_publication','marketing_period'];$values=[];
    foreach(explode('&',$query) as $pair){
        [$name,$value]=array_pad(explode('=',$pair,2),2,'');
        if(preg_match('/%(?![0-9a-fA-F]{2})/',$pair))return null;
        $name=urldecode($name);$value=urldecode($value);
        if(!in_array($name,$keys,true)||array_key_exists($name,$values)||strlen($value)>1024)return null;
        $values[$name]=$value;
    }
    $legacy=count($values)===5&&!isset($values['marketing_followthrough'])
        &&!array_diff(['agent','conversation_id','marketing_plan','marketing_publication','marketing_period'],array_keys($values));
    if(!$legacy&&(count($values)!==3||array_diff(['agent','conversation_id','marketing_followthrough'],array_keys($values))))return null;
    $positive=static function(string $value): ?int{
        if(!preg_match('/\A[1-9][0-9]{0,15}\z/D',$value))return null;
        $number=(int)$value;return $number>0&&$number<=9007199254740991?$number:null;
    };
    $agent=$values['agent']==='system'?0:$positive($values['agent']);
    $conversation=$positive($values['conversation_id']);
    if($agent===null||$conversation===null)return null;
    $extra=[];
    if($legacy){
        $product=$positive($values['marketing_plan']);
        if($product===null||!preg_match('/\A[a-f0-9]{32}\z/D',$values['marketing_publication'])
            ||!str_starts_with($values['marketing_period'],'profile:analytics:v1:'))return null;
        $ref='marketing:ft:v1:'.$values['marketing_publication'].':'.substr($values['marketing_period'],strlen('profile:analytics:v1:'));
        $extra=['legacy_product_id'=>$product,'legacy_values'=>$values];
    }else{
        $ref=$values['marketing_followthrough'];
        $translated=vp3_profile_commerce_followthrough_legacy_ref_v1($ref,$now);
        if($translated!==null){$ref=$translated;$extra=['legacy_values'=>$values];}
    }
    try{$period=vp3_profile_commerce_followthrough_decode_v1($ref,$now);}
    catch(Throwable){return null;}
    return ['agent_id'=>$agent,'conversation_id'=>$conversation,'ref'=>$period['ref']]+$extra;
}

function vp3_marketing_followthrough_destination_bind_v1(?array $requested,int $owner,int $agent,int $conversation,
    string $binding,string $chatUrl,bool $allowed,?PDO $pdo=null): ?array
{
    if(!$allowed||$requested===null||$owner<1||$owner>9007199254740991||$agent<0||$conversation<1
        ||$binding===''||($requested['agent_id']??null)!==$agent||($requested['conversation_id']??null)!==$conversation
        ||!is_string($requested['ref']??null)||!str_starts_with($chatUrl,'/')||str_starts_with($chatUrl,'//')
        ||!str_ends_with($chatUrl,'/chat.php')||parse_url($chatUrl,PHP_URL_PATH)!==$chatUrl
        ||preg_match('/[\x00-\x20\\\\]/',$chatUrl))return null;
    if(isset($requested['legacy_product_id'])){
        // This hint is not authorization to read the report. Still prove the
        // old URL's extra product selector rather than silently ignoring it.
        if($pdo===null)return null;
        try{
            $period=vp3_profile_commerce_followthrough_decode_v1($requested['ref']);
            $q=$pdo->prepare('SELECT product_id FROM agent_promotion_publications_v1
                WHERE publication_id=? AND owner_user_id=? AND agent_id=? AND conversation_id=? AND product_id=?');
            $q->execute([$period['publication_id'],$owner,$agent,$conversation,$requested['legacy_product_id']]);
            $product=$q->fetchColumn();$q->closeCursor();
            if($product===false||(int)$product!==$requested['legacy_product_id'])return null;
        }catch(Throwable){return null;}
    }
    $href=$chatUrl.'?'.http_build_query(['agent'=>$agent===0?'system':(string)$agent,'conversation_id'=>(string)$conversation,
        'marketing_followthrough'=>$requested['ref']],'','&',PHP_QUERY_RFC3986);
    $extra=isset($requested['legacy_values'])?['legacyHref'=>$chatUrl.'?'.http_build_query($requested['legacy_values'],'','&',PHP_QUERY_RFC3986)]:[];
    return ['ownerUserId'=>$owner,'chatAgentId'=>$agent,'chatConversationId'=>$conversation,
        'workingAttentionBinding'=>$binding,'ref'=>$requested['ref'],'href'=>$href]+$extra;
}
