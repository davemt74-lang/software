<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();$pdo=db();
if(!$user||!$pdo){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sign in required.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??''))){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired.']);exit;}
$trackId=(int)($input['track_id']??0);$track=get_track_by_id($trackId);
if(!$track||!agent_tool_can_studio($track,$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'This Stem Studio project is not available to your account.']);exit;}
if(!table_exists('agent_studio_sessions')||!table_exists('agent_studio_history')){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Run the v84 database upgrade first.']);exit;}

function stem_agent_v91_json(bool $ok,array $extra=[],int $status=200): never{http_response_code($status);echo json_encode(['ok'=>$ok]+$extra,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function stem_agent_v91_stems(int $trackId): array{$s=db()?->prepare('SELECT id,stem_name,stem_role FROM track_stems WHERE track_id=? AND is_active=1 ORDER BY sort_order,id');$s?->execute([$trackId]);return $s?$s->fetchAll():[];}
function stem_agent_v91_target(string $text,array $stems): array{$q=mb_strtolower($text);$best=null;$score=0;foreach($stems as $stem){$s=0;$name=mb_strtolower((string)$stem['stem_name']);$role=mb_strtolower((string)$stem['stem_role']);if($name!==''&&str_contains($q,$name))$s+=30;if($role!==''&&str_contains($q,$role))$s+=15;foreach(preg_split('/[^\pL\pN]+/u',$name.' '.$role)?:[] as $term){if(mb_strlen($term)>=3&&str_contains($q,$term))$s+=3;}if($s>$score){$score=$s;$best=$stem;}}return $best?:[];}
function stem_agent_v91_fallback(string $text,array $stems): array{
    $q=mb_strtolower(trim($text));$target=stem_agent_v91_target($text,$stems);$stemId=(int)($target['id']??0);$commands=[];
    if(preg_match('/\b(play|start playback|start playing)\b/',$q))$commands[]=['type'=>'play'];
    if(preg_match('/\b(pause|stop playback|stop playing)\b/',$q))$commands[]=['type'=>'pause'];
    if(preg_match('/\bsave as\b/',$q))$commands[]=['type'=>'save_as'];elseif(preg_match('/\bsave\b/',$q))$commands[]=['type'=>'save'];
    if(preg_match('/\btempo\D{0,12}(\d{2,3}(?:\.\d+)?)\b/',$q,$m))$commands[]=['type'=>'tempo','value'=>max(40,min(300,(float)$m[1]))];
    if(str_contains($q,'reset tempo')||str_contains($q,'source tempo'))$commands[]=['type'=>'reset_tempo'];
    if($stemId>0&&preg_match('/\bunmute\b/',$q))$commands[]=['type'=>'unmute','stem_id'=>$stemId];elseif($stemId>0&&preg_match('/\bmute\b/',$q))$commands[]=['type'=>'mute','stem_id'=>$stemId];
    if($stemId>0&&preg_match('/\bunsolo\b/',$q))$commands[]=['type'=>'unsolo','stem_id'=>$stemId];elseif($stemId>0&&preg_match('/\bsolo\b/',$q))$commands[]=['type'=>'solo','stem_id'=>$stemId];
    if($stemId>0&&preg_match('/\b(?:volume|level|fader)\D{0,18}(\d{1,3})\s*%/',$q,$m))$commands[]=['type'=>'volume','stem_id'=>$stemId,'value'=>max(0,min(1.5,((float)$m[1])/100))];
    if($stemId>0&&preg_match('/\bpan\s+(?:to\s+)?(left|right|center)(?:\s+(\d{1,3})\s*%)?/',$q,$m)){$value=$m[1]==='center'?0.0:min(1.0,((float)($m[2]??100))/100);if($m[1]==='left')$value*=-1;$commands[]=['type'=>'pan','stem_id'=>$stemId,'value'=>$value];}
    if(str_contains($q,'metronome')||str_contains($q,'click track')){
        $style='';foreach(['classic','wood','rim','digital','soft'] as $candidate){if(str_contains($q,$candidate))$style=$candidate;}
        $accent='';if(str_contains($q,'backbeat')||str_contains($q,'2 and 4')||str_contains($q,'2 & 4'))$accent='backbeat';elseif(str_contains($q,'alternating'))$accent='alternating';elseif(str_contains($q,'no accent'))$accent='none';elseif(str_contains($q,'downbeat'))$accent='downbeat';
        $commands[]=['type'=>'metronome','enabled'=>!preg_match('/\b(off|disable|stop)\b/',$q),'free_run'=>preg_match('/\b(free|standalone|stand alone|continuous)\b/',$q)>0,'style'=>$style?:'classic','accent'=>$accent?:'downbeat'];
    }
    if(preg_match('/\brecord\b/',$q))$commands[]=['type'=>'record','requires_confirmation'=>true];
    return $commands;
}

try{
    if(!function_exists('agent_v91_plan_stem'))throw new RuntimeException('The v91 Agent Brain tools are not loaded.');
    $action=(string)($input['action']??'send');$userId=(int)$user['id'];$sessionId=(int)($input['session_id']??0);
    if($sessionId>0){$s=$pdo->prepare('SELECT id FROM agent_studio_sessions WHERE id=? AND user_id=? AND track_id=?');$s->execute([$sessionId,$userId,$trackId]);if(!$s->fetchColumn())$sessionId=0;}
    if($sessionId<1){$s=$pdo->prepare("INSERT INTO agent_studio_sessions (user_id,track_id,status,started_at,last_activity_at) VALUES (?,?,'active',NOW(),NOW())");$s->execute([$userId,$trackId]);$sessionId=(int)$pdo->lastInsertId();}
    if($action==='history'){$s=$pdo->prepare('SELECT id,role,message_text,command_json,status,result_text,created_at FROM agent_studio_history WHERE session_id=? ORDER BY id ASC LIMIT 400');$s->execute([$sessionId]);stem_agent_v91_json(true,['session_id'=>$sessionId,'history'=>$s->fetchAll()]);}
    if($action==='result'){$historyId=(int)($input['history_id']??0);$status=in_array((string)($input['status']??''),['success','failed','cancelled'],true)?(string)$input['status']:'success';$s=$pdo->prepare('UPDATE agent_studio_history SET status=?,result_text=? WHERE id=? AND session_id=?');$s->execute([$status,mb_substr((string)($input['result']??''),0,8000),$historyId,$sessionId]);$pdo->prepare('UPDATE agent_studio_sessions SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);stem_agent_v91_json(true);}
    if($action!=='send')throw new RuntimeException('Unknown Studio Agent action.');
    $message=trim((string)($input['message']??''));if($message==='')throw new RuntimeException('Enter a Studio request.');if(mb_strlen($message)>8000)throw new RuntimeException('That request is too long.');
    $pdo->prepare("INSERT INTO agent_studio_history (session_id,user_id,role,message_text,status,created_at) VALUES (?,?,'user',?,'complete',NOW())")->execute([$sessionId,$userId,$message]);
    $fallback=stem_agent_v91_fallback($message,stem_agent_v91_stems($trackId));$rawState=is_array($input['state']??null)?$input['state']:[];$plan=agent_v91_plan_stem($message,$rawState,$track,$user,$fallback);
    $commands=is_array($plan['commands']??null)?$plan['commands']:[];$answer=(string)($plan['answer']??'');$model=(string)($plan['model']??'');$provider=(string)($plan['provider']??($model==='deterministic'?'local':ai_active_provider()));$complexity=(string)($plan['complexity']??'routine');
    $s=$pdo->prepare("INSERT INTO agent_studio_history (session_id,user_id,role,message_text,command_json,status,created_at) VALUES (?,?,'assistant',?,?,'pending',NOW())");$s->execute([$sessionId,$userId,$answer,json_encode(['commands'=>$commands,'model'=>$model,'provider'=>$provider,'complexity'=>$complexity],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$historyId=(int)$pdo->lastInsertId();
    agent_tool_log($user,'stem_studio.v91.agent_plan',$message,'queued',['track_id'=>$trackId,'commands'=>$commands,'model'=>$model,'provider'=>$provider,'complexity'=>$complexity],max(0,(int)($input['conversation_id']??0))?:null);
    $pdo->prepare('UPDATE agent_studio_sessions SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);
    stem_agent_v91_json(true,['session_id'=>$sessionId,'history_id'=>$historyId,'answer'=>$answer,'commands'=>$commands,'model'=>$model,'provider'=>$provider,'complexity'=>$complexity]);
}catch(Throwable $e){stem_agent_v91_json(false,['error'=>$e->getMessage()],400);}
