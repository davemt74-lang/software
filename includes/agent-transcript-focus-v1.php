<?php
declare(strict_types=1);

/** A current selection is a bounded source hint, never a transcript access grant. */
function vp3_transcript_focus_id_v1(PDO $pdo,int $owner,int $agent,int $conversation,?DateTimeImmutable $now=null): int
{
    if($owner<1||$agent<0||$conversation<1||!table_exists('agent_activity_state'))return 0;
    $q=$pdo->prepare('SELECT surface,context_key,last_heartbeat_at,details_json FROM agent_activity_state WHERE user_id=? LIMIT 1');
    $q->execute([$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['surface']!=='chat'||$row['context_key']!=='chat:'.$conversation)return 0;
    $heartbeat=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$row['last_heartbeat_at'],new DateTimeZone('UTC'));
    $clock=$now?->getTimestamp()??time();
    if(!$heartbeat||$heartbeat->format('Y-m-d H:i:s')!==$row['last_heartbeat_at']
        ||$heartbeat->getTimestamp()<$clock-90||$heartbeat->getTimestamp()>$clock+5)return 0;
    $details=json_decode((string)$row['details_json'],true,32);
    $focus=$details['transcript_focus']??null;
    if(($details['visible']??null)!==true||($details['focused']??null)!==true||($details['chat_focused']??null)!==true
        ||($details['conversation_id']??null)!==$conversation||!is_array($focus)
        ||($focus['agent_id']??null)!==$agent||($focus['conversation_id']??null)!==$conversation
        ||!is_int($focus['session_id']??null)||$focus['session_id']<1)return 0;
    return $focus['session_id'];
}

/** UI projection is sourced from the authorized saved record, not the browser. */
function vp3_transcript_focus_view_v1(PDO $pdo,array $user,array $principal,int $conversation,?DateTimeImmutable $now=null): ?array
{
    if(($principal['kind']??'')!=='system'&&($principal['kind']??'')!=='user_agent')return null;
    $agent=(int)($principal['agent_id']??0);
    $id=vp3_transcript_focus_id_v1($pdo,(int)($user['id']??0),$agent,$conversation,$now);
    if($id<1)return null;
    require_once __DIR__.'/transcript-agent-v1.php';
    try{$session=vp3_transcript_session_v1($pdo,$user,$principal,$id);}
    catch(RuntimeException $error){if(in_array($error->getCode(),[403,404,409],true))return null;throw $error;}
    return ['session_id'=>$id,'title'=>mb_substr((string)$session['title'],0,170),
        'url'=>url('/artist-listening.php?session='.$id)];
}
