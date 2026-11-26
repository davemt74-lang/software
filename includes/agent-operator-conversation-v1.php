<?php
declare(strict_types=1);
require_once __DIR__.'/phone-voice-conversation-v140.php';

function vp3_operator_conversation_url_v1(int $step):string
{
    if($step<1||$step>8)throw new InvalidArgumentException('Invalid conversation step.');
    return vp3_phone_browser_callback_v1('operator_answer',['event'=>'turn'.$step]);
}
function vp3_operator_conversation_gather_v1(string $spoken,int $step):string
{
    return '<?xml version="1.0" encoding="UTF-8"?><Response><Gather input="speech" action="'.
        vp3_phone_browser_xml_v1(vp3_operator_conversation_url_v1($step)).
        '" method="POST" timeout="5" speechTimeout="auto" actionOnEmptyResult="true">'.
        vp3_phone_voice_say_v140($spoken).'</Gather><Hangup/></Response>';
}
function vp3_operator_conversation_context_v1(PDO $pdo,int $owner,string $sid,int $now,bool $lock=false):array
{
    $q=$pdo->prepare("SELECT o.* FROM phone_operator_occurrences_v1 o JOIN phone_operator_carrier_attempts_v1 a
        ON a.owner_user_id=o.owner_user_id AND a.event_id=o.event_id AND a.start_epoch=o.start_epoch
        WHERE a.owner_user_id=? AND a.provider_sid=? AND a.state='submitted' AND a.recording_state='started'
        AND a.recording_sid IS NOT NULL AND o.state='submitted'".
        ($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$owner,$sid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!in_array($row['reason'],['answer_consented','conversation_active'],true))return [];
    try{$current=vp3_operator_current_request_v1($pdo,$row,$now,$sid);}
    catch(VP3PhoneWorkspaceException){return [];}
    return $current['reason']==='eligible'?['row'=>$row,'current'=>$current]:[];
}
function vp3_operator_conversation_turn_v1(PDO $pdo,int $owner,string $sid,int $step,array $form,callable $clock,?callable $generator=null):string
{
    $hangup=vp3_phone_browser_hangup_v1();
    if(isset($form['SpeechResult'])&&!is_string($form['SpeechResult']))throw new InvalidArgumentException('Invalid carrier speech text.');
    $speech=trim((string)($form['SpeechResult']??''));
    if(mb_strlen($speech)>1200)$speech=mb_substr($speech,0,1200);
    $prepared=section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$sid,$step,$speech,$clock){
        $context=vp3_operator_conversation_context_v1($pdo,$owner,$sid,$clock(),true);if(!$context)return ['kind'=>'hangup'];
        $row=$context['row'];$key=[$owner,$row['event_id'],$row['start_epoch']];
        $hash=hash('sha256',$speech);$q=$pdo->prepare('SELECT * FROM phone_voice_turns_v140 WHERE call_sid=? ORDER BY step_number DESC LIMIT 1');
        $q->execute([$sid]);$latest=$q->fetch(PDO::FETCH_ASSOC);$last=$latest?(int)$latest['step_number']:0;
        if($step>$last+1||$step<$last)return ['kind'=>'hangup'];
        if($step===$last){
            if(!hash_equals((string)$latest['speech_hash'],$hash)||$latest['state']!=='ready')return ['kind'=>'hangup'];
            return ['kind'=>'ready','reply'=>(string)$latest['response_text']];
        }
        if($latest&&$latest['state']!=='ready')return ['kind'=>'hangup'];
        if($speech===''||preg_match('/^\s*(?:goodbye|bye|hang up|end call|stop call)[.! ]*$/iu',$speech)){
            $reason=$speech===''?'conversation_silent':'conversation_completed';
            $pdo->prepare("UPDATE phone_operator_occurrences_v1 SET reason=? WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted'")->execute([$reason,...$key]);
            return ['kind'=>'end','reason'=>$reason];
        }
        $q=$pdo->prepare("INSERT INTO phone_voice_turns_v140(call_sid,step_number,owner_user_id,agent_id,speech_hash,speech_text,state) VALUES (?,?,?,?,?,?,'processing')");
        $q->execute([$sid,$step,$owner,$context['current']['agent_id'],$hash,$speech]);
        $pdo->prepare("UPDATE phone_operator_occurrences_v1 SET reason='conversation_active' WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted'")->execute($key);
        $prior=$pdo->prepare("SELECT speech_text,response_text FROM phone_voice_turns_v140 WHERE call_sid=? AND step_number<? AND state='ready' ORDER BY step_number DESC LIMIT 4");
        $prior->execute([$sid,$step]);$history=[];
        foreach(array_reverse($prior->fetchAll(PDO::FETCH_ASSOC)) as $item){$history[]=['role'=>'user','message'=>$item['speech_text']];$history[]=['role'=>'assistant','message'=>$item['response_text']];}
        return ['kind'=>'generate','speech'=>$speech,'history'=>$history,'current'=>$context['current']];
    });
    if($prepared['kind']==='hangup')return $hangup;
    if($prepared['kind']==='end')return vp3_phone_voice_end_v140($prepared['reason']==='conversation_silent'?'I did not hear a response. Goodbye.':'Thank you. Goodbye.');
    $reply=$prepared['reply']??'';
    if($prepared['kind']==='generate'){
        try{$result=$generator?$generator($prepared['speech'],$prepared['history'],$prepared['current']):
            vp3_phone_voice_ai_v140($pdo,$owner,$prepared['current']['agent_id'],$prepared['speech'],$prepared['history'],$prepared['current']['objectives']);}
        catch(Throwable){$result=['ok'=>false];}
        $reply=mb_strimwidth(trim((string)($result['answer']??'')),0,650,'…');
        $hasReply=!empty($result['ok'])&&$reply!=='';
        $saved=section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$sid,$step,$clock,$reply,$hasReply){
            $authorized=(bool)vp3_operator_conversation_context_v1($pdo,$owner,$sid,$clock(),true);
            $state=$authorized?($hasReply?'ready':'failed'):'withheld';
            $q=$pdo->prepare("UPDATE phone_voice_turns_v140 SET response_text=?,state=? WHERE owner_user_id=? AND call_sid=? AND step_number=? AND state='processing'");
            $q->execute([$state==='ready'?$reply:null,$state,$owner,$sid,$step]);
            return $authorized&&$q->rowCount()===1;
        });
        if(!$saved)return $hangup;
        if(!$hasReply)return vp3_phone_voice_end_v140('The assistant is unavailable. Goodbye.');
    }
    return $step===8?vp3_phone_voice_end_v140($reply.' Thank you. Goodbye.'):vp3_operator_conversation_gather_v1($reply,$step+1);
}
