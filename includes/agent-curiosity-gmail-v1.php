<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-link-source-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';

/** Read recent reviewed link activity only for the same private Chat and current Gmail grant. */
function vp3_gmail_curiosity_link_observations_v1(PDO $pdo,array $user,int $agentId,int $cid,int $since,int $limit=24,bool $includeUnlabeled=false): array
{
    if($cid<1||!agent_brain_schema_ready())return [];
    $scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if(($scope['state']??null)!=='ready'||empty($scope['provider_ready'])||empty($scope['generation'])
        ||!agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$cid))return [];
    $scopeRef=agent_connection_gmail_inbox_update_scope_ref_v1($scope);
    [$agentWhere,$agentArgs]=vp3_agent_memory_scope_sql_v410($agentId);
    $safeJson="CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END";
    $sqlite=agent_commerce_sqlite_pa2($pdo);
    $integer=$sqlite?'INTEGER':'UNSIGNED';
    $chat="CAST(JSON_EXTRACT($safeJson,'$.conversation_id') AS $integer)";
    $clicked="CAST(JSON_EXTRACT($safeJson,'$.clicked_at') AS $integer)";
    $grant="JSON_EXTRACT($safeJson,'$.scope_ref')";
    if(!$sqlite)$grant="JSON_UNQUOTE($grant)";
    $limit=max(1,min(24,$limit));
    $query=$pdo->prepare("SELECT metadata_json FROM agent_memory_items WHERE user_id=? AND $agentWhere
        AND memory_type='initiative_sweep' AND is_active=1 AND subject LIKE 'gmail-link:%'
        AND $grant=? AND $chat=CAST(? AS $integer) AND $clicked BETWEEN CAST(? AS $integer) AND CAST(? AS $integer)
        AND (JSON_EXTRACT($safeJson,'$.title') IS NOT NULL OR JSON_EXTRACT($safeJson,'$.excerpt') IS NOT NULL)
        ORDER BY $clicked DESC,id DESC LIMIT $limit");
    $now=time();
    $query->execute([$scope['owner'],...$agentArgs,$scopeRef,$cid,max($since,$now-30*86400),$now]);
    $observations=[];$verified=[];
    foreach($query->fetchAll(PDO::FETCH_COLUMN) as $raw){
        $row=json_decode((string)$raw,true,12);
        $message=$row['message_id']??null;$target=$row['target_hash']??null;
        $kind=$row['kind']??null;$item=$row['item_ref']??null;
        if(($row['conversation_id']??null)!==$cid||($row['scope_ref']??null)!==$scopeRef
            ||!is_int($message)||$message<1||!is_int($row['clicked_at']??null)||!is_string($kind)
            ||($item!==null&&(!is_string($item)||strlen($item)>160))
            ||!is_string($target)||preg_match('/\A[a-f0-9]{64}\z/D',$target)!==1||isset($observations[$target]))continue;
        $title=is_string($row['title']??null)?mb_strcut($row['title'],0,280,'UTF-8'):'';
        $excerpt=is_string($row['excerpt']??null)?mb_strcut($row['excerpt'],0,700,'UTF-8'):'';
        if($title===''&&$excerpt===''&&!$includeUnlabeled)continue;
        $origin=$message.'|'.$item;
        if(!array_key_exists($origin,$verified)){
            try{
                $source=vp3_gmail_chat_link_source_v1($pdo,$scope['user'],$agentId,$cid,$message,$item);
                $current=agent_connection_gmail_inbox_update_scope_ref_v1($source['scope']);
                $verified[$origin]=$current===$scopeRef&&$source['item_ref']===$item?$source['kind']:null;
            }catch(RuntimeException|JsonException $error){$verified[$origin]=null;}
        }
        if($verified[$origin]!==$kind)continue;
        $observations[$target]=['message_id'=>$message,'item_ref'=>$item,'clicked_at'=>$row['clicked_at'],'target_hash'=>$target,
            'title'=>$title,'excerpt'=>$excerpt];
    }
    return array_values($observations);
}
