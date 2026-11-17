<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-capability-chat-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/user-agent-system-v236.php';

/** Saved preferences can veto an idle offer. They never authorize playback, search, or delivery. */
function vp3_idle_media_preferences_v1(PDO $pdo,array $user,int $agentId): array
{
    $defaults=['allow_music'=>true,'allow_youtube'=>true];
    $owner=(int)($user['id']??0);
    if($owner<1||$agentId<0||vp3_participant_scope_v1()!==null)return $defaults;
    try{
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
        $principal=vp3_agent_chat_principal_v380($user,$agent);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
        if(!has_permission('account.access',$live)||!personal_capability_has_v242('agent_brain.access',$live))return $defaults;

        [$archiveScope,$archiveParams]=vp3_agent_memory_scope_sql_v410($agentId,'a');
        [$chatScope,$chatParams]=vp3_agent_chat_scope_sql_v380($agent,'c');
        $read=$pdo->prepare("SELECT a.message_text,m.message,m.context_json
            FROM agent_chat_archive a
            JOIN chat_conversations c ON c.id=a.conversation_id AND c.user_id=a.user_id
            JOIN chat_messages m ON m.id=a.source_message_id AND m.conversation_id=c.id AND m.user_id=c.user_id AND m.role='user'
            WHERE a.user_id=? AND $archiveScope AND $chatScope AND a.role='user'
              AND a.created_at<=? AND LENGTH(a.message_text)<=720 AND LENGTH(m.message)<=720
              AND (LOWER(a.message_text) LIKE '%music%' OR LOWER(a.message_text) LIKE '%youtube%' OR LOWER(a.message_text) LIKE '%disturb%')
            ORDER BY a.created_at DESC,a.id DESC LIMIT 60");
        $read->execute([$owner,...$archiveParams,...$chatParams,date('Y-m-d H:i:s')]);
        $decisions=[];
        while($row=$read->fetch(PDO::FETCH_ASSOC)){
            if($row['message_text']!==$row['message'])continue;
            $context=$row['context_json']===null||$row['context_json']===''?[]:json_decode($row['context_json'],true);
            if(!is_array($context)||!empty($context['skip_brain_archive']))continue;
            $directive=vp3_idle_media_preference_directive_v1($row['message_text']);
            $decisions+=$directive;
            if(count($decisions)===3)break;
        }
        $available=$decisions['all']??true;
        return ['allow_music'=>$available&&($decisions['music']??true),'allow_youtube'=>$available&&($decisions['youtube']??true)];
    }catch(RuntimeException $error){
        return $defaults;
    }
}

/** Only standalone, unambiguous owner instructions change passive behavior. */
function vp3_idle_media_preference_directive_v1(string $message): array
{
    if(!mb_check_encoding($message,'UTF-8')||mb_strlen($message)>180||preg_match('/[\r\n\p{Cc}\p{Cf}]/u',$message))return [];
    $text=mb_strtolower(str_replace(['’','-'],["'",' '],trim($message)));
    $text=preg_replace('/\Aplease,? +/u','',rtrim($text,' .!'))??'';
    if(in_array($text,['do not disturb','do not disturb me',"don't disturb me",'enable do not disturb','turn on do not disturb'],true)){
        return ['all'=>false];
    }
    if(in_array($text,['disable do not disturb','turn off do not disturb'],true))return ['all'=>true];
    $channel='(?<channel>music|youtube(?: videos?)?)';
    $suggestions='(?:suggestions|recommendations)';
    $disable="(?:(?:do not|don't) (?:suggest|recommend) $channel|stop (?:suggesting|recommending) $channel|"
        ."(?:turn off|disable|no(?: more)?|i prefer no) $channel $suggestions)";
    $enable="(?:(?:turn on|enable|resume) $channel $suggestions|you can (?:suggest|recommend) $channel again)";
    if(preg_match('~\A'.$disable.'\z~uJ',$text,$match)===1){
        return [str_starts_with($match['channel'],'youtube')?'youtube':'music'=>false];
    }
    if(preg_match('~\A'.$enable.'\z~uJ',$text,$match)===1){
        return [str_starts_with($match['channel'],'youtube')?'youtube':'music'=>true];
    }
    return [];
}
