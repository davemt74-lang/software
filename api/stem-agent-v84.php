<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();
$pdo=db();
if(!$user || !$pdo){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sign in required.']); exit; }
$input=json_decode((string)file_get_contents('php://input'),true); if(!is_array($input))$input=$_POST;
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??''))){ http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Session expired.']); exit; }
$trackId=(int)($input['track_id']??0);
$track=get_track_by_id($trackId);
if(!$track || !agent_tool_can_studio($track,$user)){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'This Stem Studio project is not available to your account.']); exit; }
if(!table_exists('agent_studio_sessions') || !table_exists('agent_studio_history')){ http_response_code(503); echo json_encode(['ok'=>false,'error'=>'Run the v84 database upgrade first.']); exit; }

function studio_agent_stems(int $trackId): array {
    $pdo=db(); if(!$pdo)return [];
    $stmt=$pdo->prepare('SELECT id,stem_name,stem_role FROM track_stems WHERE track_id=? AND is_active=1 ORDER BY sort_order,id');
    $stmt->execute([$trackId]); return $stmt->fetchAll();
}
function studio_agent_target(string $text,array $stems): array {
    $q=mb_strtolower($text); $best=null; $score=0;
    foreach($stems as $stem){
        $s=0; $name=mb_strtolower((string)$stem['stem_name']); $role=mb_strtolower((string)$stem['stem_role']);
        if($name!=='' && str_contains($q,$name))$s+=30;
        if($role!=='' && str_contains($q,$role))$s+=15;
        foreach(preg_split('/[^\pL\pN]+/u',$name.' '.$role)?:[] as $term){ if(mb_strlen($term)>=3 && str_contains($q,$term))$s+=3; }
        if($s>$score){$score=$s;$best=$stem;}
    }
    return $best ?: [];
}
function studio_agent_parse(string $text,array $stems): array {
    $q=mb_strtolower(trim($text)); $target=studio_agent_target($text,$stems); $stemId=(int)($target['id']??0);
    $commands=[];
    if(preg_match('/\b(play|start playback|start playing)\b/',$q))$commands[]=['type'=>'play'];
    if(preg_match('/\b(pause|stop playback|stop playing)\b/',$q))$commands[]=['type'=>'pause'];
    if(preg_match('/\bsave as\b/',$q))$commands[]=['type'=>'save_as']; elseif(preg_match('/\bsave\b/',$q))$commands[]=['type'=>'save'];
    if(preg_match('/\btempo\D{0,12}(\d{2,3}(?:\.\d+)?)\b/',$q,$m))$commands[]=['type'=>'tempo','value'=>max(40,min(300,(float)$m[1]))];
    if(str_contains($q,'reset tempo')||str_contains($q,'source tempo'))$commands[]=['type'=>'reset_tempo'];
    if(str_contains($q,'track library')||str_contains($q,'stem library'))$commands[]=['type'=>'library'];
    if($stemId>0 && str_contains($q,'inspector'))$commands[]=['type'=>'inspector','stem_id'=>$stemId];
    if($stemId>0 && preg_match('/\bautomation\b/',$q))$commands[]=['type'=>'automation','stem_id'=>$stemId];
    if($stemId>0 && preg_match('/\bunmute\b/',$q))$commands[]=['type'=>'unmute','stem_id'=>$stemId]; elseif($stemId>0 && preg_match('/\bmute\b/',$q))$commands[]=['type'=>'mute','stem_id'=>$stemId];
    if($stemId>0 && preg_match('/\bunsolo\b/',$q))$commands[]=['type'=>'unsolo','stem_id'=>$stemId]; elseif($stemId>0 && preg_match('/\bsolo\b/',$q))$commands[]=['type'=>'solo','stem_id'=>$stemId];
    if($stemId>0 && preg_match('/\b(?:volume|level|fader)\D{0,18}(\d{1,3})\s*%/',$q,$m))$commands[]=['type'=>'volume','stem_id'=>$stemId,'value'=>max(0,min(1.5,((float)$m[1])/100))];
    if($stemId>0 && preg_match('/\bpan\s+(?:to\s+)?(left|right|center)(?:\s+(\d{1,3})\s*%)?/',$q,$m)){
        $value=$m[1]==='center'?0.0:min(1.0,((float)($m[2]??100))/100); if($m[1]==='left')$value*=-1; $commands[]=['type'=>'pan','stem_id'=>$stemId,'value'=>$value];
    }
    if($stemId>0 && preg_match('/\b(select|focus)\b/',$q))$commands[]=['type'=>'select','stem_id'=>$stemId];
    if($stemId>0 && preg_match('/\b(add|insert|open)\b.*\b(eq|compressor|delay|reverb|limiter|plugin)\b/',$q,$m))$commands[]=['type'=>'plugin_picker','stem_id'=>$stemId,'plugin'=>($m[2]??'')];
    if($stemId>0 && preg_match('/\barm\b/',$q))$commands[]=['type'=>'arm','stem_id'=>$stemId];
    if(str_contains($q,'live mix'))$commands[]=['type'=>(preg_match('/\b(off|disable|disarm|stop)\b/',$q)?'live_mix_off':'live_mix_on')];
    if($stemId>0 && (str_contains($q,'live recording')||str_contains($q,'record output')))$commands[]=['type'=>(preg_match('/\b(off|disable|disarm)\b/',$q)?'live_track_off':'live_track_on'),'stem_id'=>$stemId];
    if(preg_match('/\bmonitor\b/',$q))$commands[]=['type'=>'monitor'];
    if(preg_match('/\brecord\b/',$q))$commands[]=['type'=>'record','requires_confirmation'=>true];
    return $commands;
}
function studio_agent_answer(array $commands,string $text,array $track): string {
    if(!$commands)return 'I can control this Stem Studio session. Ask me to play or pause, set tempo, select/mute/solo/pan/level a stem, open its inspector or automation, add a native EQ/compressor/delay/reverb/limiter, open the stem library, arm tracks, monitor, record, or save the project.';
    $labels=[]; foreach($commands as $c)$labels[]=(string)$c['type'];
    return 'I queued these Studio actions for '.(string)$track['title'].': '.implode(', ',$labels).'. The session log will record the result of each action.';
}
try{
    $action=(string)($input['action']??'send'); $userId=(int)$user['id']; $sessionId=(int)($input['session_id']??0);
    if($sessionId>0){ $s=$pdo->prepare('SELECT * FROM agent_studio_sessions WHERE id=? AND user_id=? AND track_id=?'); $s->execute([$sessionId,$userId,$trackId]); if(!$s->fetch())$sessionId=0; }
    if($sessionId<1){ $s=$pdo->prepare('INSERT INTO agent_studio_sessions (user_id,track_id,status,started_at,last_activity_at) VALUES (?,?,\'active\',NOW(),NOW())'); $s->execute([$userId,$trackId]); $sessionId=(int)$pdo->lastInsertId(); }
    if($action==='history'){
        $s=$pdo->prepare('SELECT id,role,message_text,command_json,status,created_at FROM agent_studio_history WHERE session_id=? ORDER BY id ASC LIMIT 300'); $s->execute([$sessionId]);
        echo json_encode(['ok'=>true,'session_id'=>$sessionId,'history'=>$s->fetchAll()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
    }
    if($action==='result'){
        $historyId=(int)($input['history_id']??0); $status=in_array((string)($input['status']??''),['success','failed','cancelled'],true)?(string)$input['status']:'success';
        $s=$pdo->prepare('UPDATE agent_studio_history SET status=?,result_text=? WHERE id=? AND session_id=?'); $s->execute([$status,mb_substr((string)($input['result']??''),0,2000),$historyId,$sessionId]);
        $pdo->prepare('UPDATE agent_studio_sessions SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]); echo json_encode(['ok'=>true]); exit;
    }
    $message=trim((string)($input['message']??'')); if($message==='')throw new RuntimeException('Enter a Studio request.');
    $pdo->prepare('INSERT INTO agent_studio_history (session_id,user_id,role,message_text,status,created_at) VALUES (?,?,\'user\',?,\'complete\',NOW())')->execute([$sessionId,$userId,$message]);
    $commands=studio_agent_parse($message,studio_agent_stems($trackId)); $answer=studio_agent_answer($commands,$message,$track);
    $s=$pdo->prepare('INSERT INTO agent_studio_history (session_id,user_id,role,message_text,command_json,status,created_at) VALUES (?,?,\'assistant\',?,?,\'pending\',NOW())');
    $s->execute([$sessionId,$userId,$answer,json_encode($commands,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]); $historyId=(int)$pdo->lastInsertId();
    agent_tool_log($user,'stem_studio.command',$message,'queued',['track_id'=>$trackId,'commands'=>$commands]);
    echo json_encode(['ok'=>true,'session_id'=>$sessionId,'history_id'=>$historyId,'answer'=>$answer,'commands'=>$commands],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
