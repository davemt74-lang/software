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

function stem_agent_v105_json(bool $ok,array $extra=[],int $status=200): never{http_response_code($status);echo json_encode(['ok'=>$ok]+$extra,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function stem_agent_v105_stems(int $trackId): array{$s=db()?->prepare('SELECT id,stem_name,stem_role FROM track_stems WHERE track_id=? AND is_active=1 ORDER BY sort_order,id');$s?->execute([$trackId]);return $s?$s->fetchAll():[];}
function stem_agent_v105_target(string $text,array $stems): array{$q=mb_strtolower($text);$best=null;$score=0;foreach($stems as $stem){$s=0;$name=mb_strtolower((string)$stem['stem_name']);$role=mb_strtolower((string)$stem['stem_role']);if($name!==''&&str_contains($q,$name))$s+=30;if($role!==''&&str_contains($q,$role))$s+=15;foreach(preg_split('/[^\pL\pN]+/u',$name.' '.$role)?:[] as $term){if(mb_strlen($term)>=3&&str_contains($q,$term))$s+=3;}if($s>$score){$score=$s;$best=$stem;}}return $best?:[];}
function stem_agent_v105_seconds(string $raw,int $fallback=10): int{
    $raw=mb_strtolower(trim($raw));
    if(preg_match('/\d+/', $raw,$m))return max(1,min(300,(int)$m[0]));
    $words=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'fifteen'=>15,'twenty'=>20,'thirty'=>30,'forty'=>40,'forty-five'=>45,'sixty'=>60];
    foreach($words as $word=>$value){if(str_contains($raw,$word))return $value;}
    return $fallback;
}
function stem_agent_v159_number(string $raw,int $fallback=0): int{
    if(preg_match('/\d+/',mb_strtolower($raw),$match))return max(0,(int)$match[0]);
    $ones=['zero'=>0,'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19];
    $tens=['twenty'=>20,'thirty'=>30,'forty'=>40,'fifty'=>50,'sixty'=>60];$q=str_replace('-',' ',mb_strtolower(trim($raw)));
    foreach($tens as $word=>$value)if(preg_match('/\b'.preg_quote($word,'/').'(?:\s+([a-z]+))?\b/',$q,$m))return $value+(int)($ones[$m[1]??'']??0);
    foreach($ones as $word=>$value)if(preg_match('/\b'.preg_quote($word,'/').'\b/',$q))return $value;
    return $fallback;
}
function stem_agent_v159_targets(string $text,array $stems): array{
    $q=mb_strtolower($text);$ids=[];$count=count($stems);
    if(preg_match('/\btracks?(?:\s+(?:number|numbers|no\.?))?\s+([a-z\d-]+)(?:\s*(?:-|through|to|and)\s*([a-z\d-]+))?/',$q,$m)){
        $start=max(1,stem_agent_v159_number($m[1],0));$end=max($start,stem_agent_v159_number((string)($m[2]??$m[1]),$start));
        for($index=$start;$index<=$end;$index++)if(isset($stems[$index-1]))$ids[]=(int)$stems[$index-1]['id'];
    }
    if($ids)return array_values(array_unique($ids));
    $ordinals=['first'=>1,'second'=>2,'third'=>3,'fourth'=>4,'fifth'=>5,'sixth'=>6,'seventh'=>7,'eighth'=>8,'ninth'=>9,'tenth'=>10];
    foreach($ordinals as $word=>$index)if(preg_match('/\b(?:'.$word.'\s+track|track\s+'.$word.')\b/',$q)&&isset($stems[$index-1]))$ids[]=(int)$stems[$index-1]['id'];
    if($ids)return array_values(array_unique($ids));
    $pluralTarget=preg_match('/\b(?:all|every|vocals|drums|keys|guitars|synths)\b/',$q)>0;
    if(!$pluralTarget){
        foreach($stems as $stem){$name=trim(mb_strtolower((string)($stem['stem_name']??'')));if($name!==''&&preg_match('/\b'.preg_quote($name,'/').'\b/u',$q))$ids[]=(int)$stem['id'];}
        if($ids)return array_values(array_unique($ids));
    }
    $roleAliases=['vocal'=>['vocal','vocals','voice'],'drums'=>['drum','drums','percussion'],'bass'=>['bass'],'guitar'=>['guitar'],'keys'=>['keys','keyboard','piano'],'synth'=>['synth','synthesizer']];
    foreach($roleAliases as $role=>$aliases){
        if(!array_filter($aliases,static fn(string $alias): bool=>preg_match('/\b'.preg_quote($alias,'/').'\b/',$q)===1))continue;
        foreach($stems as $stem){$hay=mb_strtolower((string)($stem['stem_name']??'').' '.(string)($stem['stem_role']??''));if(str_contains($hay,$role)||array_filter($aliases,static fn(string $alias): bool=>str_contains($hay,$alias)))$ids[]=(int)$stem['id'];}
    }
    if(!$ids){
        foreach($stems as $stem){$role=trim(mb_strtolower((string)($stem['stem_role']??'')));if($role!==''&&preg_match('/\b'.preg_quote($role,'/').'s?\b/u',$q))$ids[]=(int)$stem['id'];}
    }
    $best=stem_agent_v105_target($text,$stems);if($best)$ids[]=(int)$best['id'];
    return array_values(array_unique(array_filter($ids,static fn(int $id): bool=>$id>0)));
}
function stem_agent_v159_measure_range(string $q): array{
    if(preg_match('/\bfirst\s+([a-z\d-]+)\s+measures?\b/',$q,$m)){ $last=stem_agent_v159_number($m[1],0);return $last>0?[1,$last]:[]; }
    if(preg_match('/\bmeasures?\s+([a-z\d-]+)\s*(?:-|through|to)\s*([a-z\d-]+)\b/',$q,$m)){ $first=stem_agent_v159_number($m[1],0);$last=stem_agent_v159_number($m[2],0);return $first>0&&$last>0?[min($first,$last),max($first,$last)]:[]; }
    if(preg_match('/\bmeasure\s+([a-z\d-]+)\b/',$q,$m)){ $value=stem_agent_v159_number($m[1],0);return $value>0?[$value,$value]:[]; }
    return [];
}
function stem_agent_v105_direct(string $text,array $stems=[]): array{
    $q=mb_strtolower(trim($text));
    if(preg_match('/\b(?:create|start|make|open)\b.*\bnew\b.*\bproject\b|\bnew\b.*\bproject\b/',$q)){
        $tempo=120.0;
        if(preg_match('/\b(\d{2,3}(?:\.\d+)?)\s*bpm\b/',$q,$m)||preg_match('/\b(?:tempo|bpm)\D{0,12}(\d{2,3}(?:\.\d+)?)\b/',$q,$m))$tempo=max(40,min(300,(float)$m[1]));
        $roles=[];
        if(preg_match('/\b(?:drum|drums|percussion|beat)\b/',$q))$roles[]='drum';
        if(preg_match('/\bbass\b/',$q))$roles[]='bass';
        if(preg_match('/\b(?:vocal|vocals|voice)\b/',$q))$roles[]='vocal';
        $command=['type'=>'v158_create_library_project','project_name'=>'Untitled Project','tempo_bpm'=>$tempo,'time_signature'=>'4/4','library_roles'=>$roles];
        $summary=$roles?' with one '.implode(', one ',$roles).' sample from the Track Library':'';
        return ['answer'=>'Creating a new project'.$summary.' at '.rtrim(rtrim(number_format($tempo,2,'.',''),'0'),'.').' BPM.','commands'=>[$command]];
    }
    if(preg_match('/\bredo(?:\s+(?:that|it|the last change))?\b/',$q)){$count=stem_agent_v159_number($q,1);return ['answer'=>'Redoing the requested Studio change.','commands'=>[['type'=>'v159_redo','count'=>max(1,$count)]]];}
    if(preg_match('/\bundo(?:\s+(?:that|it|the last change))?\b/',$q)){$count=stem_agent_v159_number($q,1);return ['answer'=>'Undoing the requested Studio change.','commands'=>[['type'=>'v159_undo','count'=>max(1,$count)]]];}
    if(preg_match('/\b(?:song\s+)?duration\D{0,24}([a-z\d-]+)\s*(?:measures?|bars?)\b/',$q,$m)||preg_match('/\b(?:make|set)\b.*\bsong\b.*?([a-z\d-]+)\s*(?:measures?|bars?)\b/',$q,$m)){
        $measures=stem_agent_v159_number($m[1],0);if($measures>0)return ['answer'=>'Setting the authoritative song duration to '.$measures.' measures and extending Track Library samples to the endpoint.','commands'=>[['type'=>'v159_set_duration','measures'=>$measures,'extend'=>true]]];
    }
    if(preg_match('/\b(?:create|add|make)\s+([a-z\d-]+)\s+empty\s+([a-z ]+?)\s+tracks?\b/',$q,$m)){
        $count=stem_agent_v159_number($m[1],0);$role=trim((string)$m[2]);$focusrite=preg_match('/\b(?:focusrite|focusright|scarlett|clarett|vocaster)\b/',$q)>0;
        if($count>0)return ['answer'=>'Creating '.$count.' empty '.$role.' tracks'.($focusrite?' after verifying distinct Focusrite inputs.':'.'),'commands'=>[['type'=>'v159_create_empty_tracks','count'=>$count,'role'=>$role,'base_name'=>ucwords($role),'input_provider'=>$focusrite?'focusrite':'']]];
    }
    if(preg_match('/\b(?:remove|clear|silence|delete)\b.*\bmeasures?\b/',$q)){
        $range=stem_agent_v159_measure_range($q);$ids=stem_agent_v159_targets($q,$stems);
        if($range&&$ids)return ['answer'=>'Clearing measures '.$range[0].'-'.$range[1].' without moving later clips.','commands'=>[['type'=>'v159_clear_measures','stem_ids'=>$ids,'start_measure'=>$range[0],'end_measure'=>$range[1]]]];
    }
    if(preg_match('/\b(?:loop|repeat)\b.*\bmeasures?\b/',$q)){
        $range=stem_agent_v159_measure_range($q);if($range)return ['answer'=>'Looping measures '.$range[0].'-'.$range[1].' inclusive.','commands'=>[['type'=>'v159_loop_measures','start_measure'=>$range[0],'end_measure'=>$range[1],'active'=>true]]];
    }
    if(preg_match('/\b(?:turn|switch|set)?\s*loop\s+(?:off|disable|disabled)\b|\bdisable\s+(?:the\s+)?loop\b/',$q))return ['answer'=>'Turning loop playback off without discarding its range.','commands'=>[['type'=>'v159_loop_measures','active'=>false]]];
    if(preg_match('/\b(?:list|show)\b.*\b(?:saved\s+)?versions?\b/',$q))return ['answer'=>'Listing saved project versions.','commands'=>[['type'=>'v159_list_versions']]];
    if(preg_match('/\b(?:load|restore|open)\b.*\bversion\b/',$q)){
        $which=str_contains($q,'previous')?'previous':'recent';$name='';if(preg_match('/\bversion\s+(?:named\s+)?[“"\']?([^“"\']+?)[”"\']?$/u',$text,$m))$name=trim($m[1]);
        return ['answer'=>'Loading the requested saved project version.','commands'=>[['type'=>'v159_load_version','which'=>$which,'name'=>$name]]];
    }
    if(preg_match('/\b(?:list|show)\b.*\bprojects?\b/',$q))return ['answer'=>'Listing your Studio projects.','commands'=>[['type'=>'v159_list_projects']]];
    if(preg_match('/\brename\s+(?:this\s+)?project\s+(?:to\s+)?(.+)$/',$text,$m))return ['answer'=>'Renaming this project.','commands'=>[['type'=>'v159_rename_project','name'=>trim($m[1]," \t\n\r\0\x0B\"'")]]];
    if(preg_match('/\b(?:load|open)\b.*\bproject\b/',$q)){
        $which=str_contains($q,'previous')?'previous':'recent';$name='';if(preg_match('/\bproject\s+(?:named\s+)?[“"\']?([^“"\']+?)[”"\']?$/u',$text,$m)&&!preg_match('/\b(?:recent|latest|last|previous)\s+project\b/',$q))$name=trim($m[1]);
        return ['answer'=>'Opening the requested Studio project.','commands'=>[['type'=>'v159_load_project','which'=>$which,'name'=>$name]]];
    }
    if(preg_match('/\bsave\s+as(?:\s+(.+))?$/',$text,$m))return ['answer'=>'Saving a new project version.','commands'=>[['type'=>'v159_save_as','name'=>trim((string)($m[1]??'')," \t\n\r\0\x0B\"'")]]];
    if(preg_match('/\bsave\s+(?:this\s+)?project\b|^\s*save\s*$/',$q))return ['answer'=>'Saving the current project version.','commands'=>[['type'=>'v159_save']]];
    $targetIds=stem_agent_v159_targets($q,$stems);
    if($targetIds&&preg_match('/\b(unmute|mute|unsolo|solo)\b/',$q,$m)){
        $word=$m[1];$action=str_contains($word,'mute')?'mute':'solo';$value=!str_starts_with($word,'un');return ['answer'=>ucfirst($word).' on the requested track'.(count($targetIds)===1?'':'s').'.','commands'=>[['type'=>'v159_track_state','action'=>$action,'value'=>$value,'exclusive'=>$action==='solo'&&$value,'stem_ids'=>$targetIds]]];
    }
    if($targetIds&&preg_match('/\b(?:volume|level|fader)\D{0,18}(\d{1,3})\s*%/',$q,$m))return ['answer'=>'Setting the requested track volume.','commands'=>[['type'=>'v159_track_state','action'=>'volume','value'=>max(0,min(1.5,((float)$m[1])/100)),'stem_ids'=>$targetIds]]];
    if($targetIds&&preg_match('/\b(?:volume|level|fader)\D{0,18}(-?\d+(?:\.\d+)?)\s*d\s*b\b/',$q,$m))return ['answer'=>'Setting the requested track volume.','commands'=>[['type'=>'v159_track_state','action'=>'volume','value'=>max(0,min(1.5,pow(10,((float)$m[1])/20))),'stem_ids'=>$targetIds]]];
    if($targetIds&&preg_match('/\btrim\D{0,18}(-?\d+(?:\.\d+)?)\s*d\s*b\b/',$q,$m))return ['answer'=>'Setting the requested track trim.','commands'=>[['type'=>'v159_track_state','action'=>'trim','value'=>max(-24,min(24,(float)$m[1])),'stem_ids'=>$targetIds]]];
    if($targetIds&&(preg_match('/\bpan\s+(?:to\s+)?(left|right|center)(?:\s+(\d{1,3})\s*%)?/',$q,$m)||preg_match('/\bpan\D{0,12}(\d{1,3})\s*%\s*(left|right)\b/',$q,$n))){if(isset($n)&&$n){$side=$n[2];$amount=(float)$n[1];}else{$side=$m[1];$amount=(float)($m[2]??100);}$value=$side==='center'?0.0:min(1.0,$amount/100);if($side==='left')$value*=-1;return ['answer'=>'Setting the requested track pan.','commands'=>[['type'=>'v159_track_state','action'=>'pan','value'=>$value,'stem_ids'=>$targetIds]]];}
    if(preg_match('/\b(?:are you listening|can you hear me|do you hear me|are you there|you listening)\b/',$q))return ['answer'=>'Yes. I’m listening, and voice conversation is active in Stem Studio.','commands'=>[]];
    if(preg_match('/\b(?:go|jump|move|skip)\s+(?:back|backward|backwards)\b(?:\s+(.*?))?(?:\s+seconds?)?$/',$q,$m)||preg_match('/\b(?:back|rewind)\b\s*(.*)$/',$q,$m)){$seconds=stem_agent_v105_seconds((string)($m[1]??''),10);return ['answer'=>'Going back '.$seconds.' seconds.','commands'=>[['type'=>'v105_seek_relative','seconds'=>-$seconds]]];}
    if(preg_match('/\b(?:go|jump|move|skip)\s+(?:forward|ahead)\b(?:\s+(.*?))?(?:\s+seconds?)?$/',$q,$m)||preg_match('/\bforward\b\s*(.*)$/',$q,$m)){$seconds=stem_agent_v105_seconds((string)($m[1]??''),10);return ['answer'=>'Going forward '.$seconds.' seconds.','commands'=>[['type'=>'v105_seek_relative','seconds'=>$seconds]]];}
    if(preg_match('/\b(?:mark|marker)\s+(?:this|here|this section|this spot|this part)\b/',$q))return ['answer'=>'Marking the current playhead position.','commands'=>[['type'=>'v105_marker_here','label'=>'Voice marker']]];
    if(preg_match('/\b(?:open|show|pull up)\b.*\b(?:last|latest|recent)\b.*\bnote\b|\b(?:last|latest|recent)\s+note\b/',$q))return ['answer'=>'Opening the latest production note for this track.','commands'=>[['type'=>'v105_open_last_note']]];
    if(str_contains($q,'metronome')||str_contains($q,'click track')){
        if(preg_match('/\b(?:volume|level)\D{0,12}(\d{1,3})\s*%/',$q,$m))return ['answer'=>'Setting metronome volume to '.max(0,min(100,(int)$m[1])).' percent.','commands'=>[['type'=>'v105_metronome_volume','value'=>max(0,min(1,((float)$m[1])/100))]]];
        if(preg_match('/\b(?:down|lower|quieter|decrease|reduce)\b/',$q))return ['answer'=>'Turning the metronome down.','commands'=>[['type'=>'v105_metronome_volume','delta'=>-0.10]]];
        if(preg_match('/\b(?:up|raise|louder|increase)\b/',$q))return ['answer'=>'Turning the metronome up.','commands'=>[['type'=>'v105_metronome_volume','delta'=>0.10]]];
    }
    return [];
}
function stem_agent_v105_fallback(string $text,array $stems): array{
    $q=mb_strtolower(trim($text));$target=stem_agent_v105_target($text,$stems);$stemId=(int)($target['id']??0);$commands=[];
    if(preg_match('/\b(play|start playback|start playing)\b/',$q))$commands[]=['type'=>'play'];
    if(preg_match('/\b(pause|stop playback|stop playing)\b/',$q))$commands[]=['type'=>'pause'];
    if(preg_match('/\bsave as\b/',$q))$commands[]=['type'=>'save_as'];elseif(preg_match('/\bsave\b/',$q))$commands[]=['type'=>'save'];
    if(preg_match('/\btempo\D{0,12}(\d{2,3}(?:\.\d+)?)\b/',$q,$m))$commands[]=['type'=>'tempo','value'=>max(40,min(300,(float)$m[1]))];
    if(str_contains($q,'reset tempo')||str_contains($q,'source tempo'))$commands[]=['type'=>'reset_tempo'];
    if($stemId>0&&preg_match('/\bunmute\b/',$q))$commands[]=['type'=>'unmute','stem_id'=>$stemId];elseif($stemId>0&&preg_match('/\bmute\b/',$q))$commands[]=['type'=>'mute','stem_id'=>$stemId];
    if($stemId>0&&preg_match('/\bunsolo\b/',$q))$commands[]=['type'=>'unsolo','stem_id'=>$stemId];elseif($stemId>0&&preg_match('/\bsolo\b/',$q))$commands[]=['type'=>'solo','stem_id'=>$stemId];
    if($stemId>0&&preg_match('/\b(?:volume|level|fader)\D{0,18}(\d{1,3})\s*%/',$q,$m))$commands[]=['type'=>'volume','stem_id'=>$stemId,'value'=>max(0,min(1.5,((float)$m[1])/100))];
    if($stemId>0&&preg_match('/\bpan\s+(?:to\s+)?(left|right|center)(?:\s+(\d{1,3})\s*%)?/',$q,$m)){$value=$m[1]==='center'?0.0:min(1.0,((float)($m[2]??100))/100);if($m[1]==='left')$value*=-1;$commands[]=['type'=>'pan','stem_id'=>$stemId,'value'=>$value];}
    if(str_contains($q,'metronome')||str_contains($q,'click track')){$style='';foreach(['classic','wood','rim','digital','soft'] as $candidate){if(str_contains($q,$candidate))$style=$candidate;}$accent='';if(str_contains($q,'backbeat')||str_contains($q,'2 and 4')||str_contains($q,'2 & 4'))$accent='backbeat';elseif(str_contains($q,'alternating'))$accent='alternating';elseif(str_contains($q,'no accent'))$accent='none';elseif(str_contains($q,'downbeat'))$accent='downbeat';$commands[]=['type'=>'metronome','enabled'=>!preg_match('/\b(off|disable|stop)\b/',$q),'free_run'=>preg_match('/\b(free|standalone|stand alone|continuous)\b/',$q)>0,'style'=>$style?:'classic','accent'=>$accent?:'downbeat'];}
    if(preg_match('/\brecord\b/',$q))$commands[]=['type'=>'record','requires_confirmation'=>true];
    return $commands;
}

function stem_agent_v131_conversation(PDO $pdo,int $userId,int $requested,bool $create=false,string $title='Stem Studio'): int{
    if(!table_exists('chat_conversations')||!table_exists('chat_messages'))return 0;
    if($requested>0){$s=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');$s->execute([$requested,$userId]);$found=(int)($s->fetchColumn()?:0);if($found>0)return $found;}
    if(function_exists('agent_chat_v101_latest_conversation_id')){$latest=agent_chat_v101_latest_conversation_id($pdo,$userId);if($latest>0)return $latest;}
    if(!$create)return 0;
    $s=$pdo->prepare('INSERT INTO chat_conversations (user_id,title,created_at,updated_at) VALUES (?,?,NOW(),NOW())');$s->execute([$userId,mb_strimwidth($title,0,190,'…')]);return (int)$pdo->lastInsertId();
}
function stem_agent_v131_chat_history(PDO $pdo,int $conversationId): array{
    if($conversationId<1)return [];$s=$pdo->prepare('SELECT role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');$s->execute([$conversationId]);return array_reverse($s->fetchAll());
}
function stem_agent_v131_append_chat(PDO $pdo,array $user,int $conversationId,string $role,string $message,string $inputMode,array $context=[]): int{
    if($conversationId<1)return 0;$uid=$role==='user'?(int)$user['id']:null;$contextJson=json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$s=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,?,?,?,?,NOW())');$s->execute([$conversationId,$uid,$role,$message,is_string($contextJson)?$contextJson:'{}']);$id=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conversationId,(int)$user['id']]);if(function_exists('agent_brain_archive_and_parse'))agent_brain_archive_and_parse($user,$conversationId,$id,$role,$message,$inputMode);return $id;
}
function stem_agent_v131_reconcile_brain(array $user,int $conversationId,int $messageId,string $message,string $inputMode): void{
    if($conversationId<1||$messageId<1||trim($message)==='')return;
    if(function_exists('agent_brain_archive_and_parse'))agent_brain_archive_and_parse($user,$conversationId,$messageId,'assistant',$message,$inputMode);
    if(function_exists('agent_brain_v122_update_state')){$state=agent_brain_v122_update_state($user,$conversationId,['id'=>$messageId,'role'=>'assistant','message'=>$message]);if(function_exists('agent_brain_v122_rollup'))agent_brain_v122_rollup($user,$conversationId,$state);}
}

try{
    if(!function_exists('agent_v91_plan_stem'))throw new RuntimeException('The v91 Agent Brain tools are not loaded.');
    $action=(string)($input['action']??'send');$userId=(int)$user['id'];$sessionId=(int)($input['session_id']??0);$requestedConversation=max(0,(int)($input['conversation_id']??0));
    if($sessionId>0){$s=$pdo->prepare('SELECT id FROM agent_studio_sessions WHERE id=? AND user_id=? AND track_id=?');$s->execute([$sessionId,$userId,$trackId]);if(!$s->fetchColumn())$sessionId=0;}
    if($sessionId<1){$s=$pdo->prepare("INSERT INTO agent_studio_sessions (user_id,track_id,status,started_at,last_activity_at) VALUES (?,?,'active',NOW(),NOW())");$s->execute([$userId,$trackId]);$sessionId=(int)$pdo->lastInsertId();}
    $conversationId=stem_agent_v131_conversation($pdo,$userId,$requestedConversation,false,'Stem Studio · '.(string)$track['title']);
    if($action==='history'){$s=$pdo->prepare('SELECT id,role,message_text,command_json,status,result_text,created_at FROM agent_studio_history WHERE session_id=? ORDER BY id ASC LIMIT 400');$s->execute([$sessionId]);stem_agent_v105_json(true,['session_id'=>$sessionId,'conversation_id'=>$conversationId,'history'=>$s->fetchAll()]);}
    if($action==='result'){
        $historyId=(int)($input['history_id']??0);$status=in_array((string)($input['status']??''),['success','failed','cancelled'],true)?(string)$input['status']:'success';
        $resultText=mb_strimwidth(trim((string)($input['result']??'')),0,8000,'…');$sharedReply=mb_strimwidth(trim((string)($input['result_text']??'')),0,8000,'…');$assistantMessageId=max(0,(int)($input['assistant_message_id']??0));
        $resultMode=(string)($input['input_mode']??'text');$resultMode=$resultMode==='voice'?'voice':'text';
        $s=$pdo->prepare('UPDATE agent_studio_history SET status=?,result_text=? WHERE id=? AND session_id=?');$s->execute([$status,$resultText,$historyId,$sessionId]);
        if($conversationId>0&&$assistantMessageId>0&&$sharedReply!==''){
            $owned=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');$owned->execute([$conversationId,$userId]);
            if($owned->fetchColumn()){$u=$pdo->prepare("UPDATE chat_messages SET message=? WHERE id=? AND conversation_id=? AND role='assistant'");$u->execute([$sharedReply,$assistantMessageId,$conversationId]);if($u->rowCount()>0)stem_agent_v131_reconcile_brain($user,$conversationId,$assistantMessageId,$sharedReply,$resultMode);$pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conversationId,$userId]);}
        }
        $pdo->prepare('UPDATE agent_studio_sessions SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);stem_agent_v105_json(true,['conversation_id'=>$conversationId,'assistant_message_id'=>$assistantMessageId]);
    }
    if($action!=='send')throw new RuntimeException('Unknown Studio Agent action.');
    $message=trim((string)($input['message']??''));if($message==='')throw new RuntimeException('Enter a Studio request.');if(mb_strlen($message)>8000)throw new RuntimeException('That request is too long.');
    $inputMode=(string)($input['input_mode']??'text');$inputMode=$inputMode==='voice'?'voice':'text';
    $conversationId=stem_agent_v131_conversation($pdo,$userId,$requestedConversation,true,'Stem Studio · '.(string)$track['title']);
    $recentHistory=stem_agent_v131_chat_history($pdo,$conversationId);
    $rawAgentContext=is_array($input['agent_context']??null)?$input['agent_context']:[];
    $rawAgentContext['conversation_id']=$conversationId;$rawAgentContext['track_id']=$trackId;
    $agentContext=agent_surface_v131_enrich($user,'stem',$rawAgentContext);
    $pdo->prepare("INSERT INTO agent_studio_history (session_id,user_id,role,message_text,status,created_at) VALUES (?,?,'user',?,'complete',NOW())")->execute([$sessionId,$userId,$message]);
    stem_agent_v131_append_chat($pdo,$user,$conversationId,'user',$message,$inputMode,['editor'=>'stem','track_id'=>$trackId,'session_id'=>$sessionId,'agent_context'=>$agentContext]);

    $studioStems=stem_agent_v105_stems($trackId);
    $direct=stem_agent_v105_direct($message,$studioStems);
    if($direct){$commands=$direct['commands'];$answer=(string)$direct['answer'];$model='deterministic-v105';$provider='local';$complexity='routine';}
    else{$fallback=stem_agent_v105_fallback($message,$studioStems);$rawState=is_array($input['state']??null)?$input['state']:[];$rawState['_recent_conversation']=$recentHistory;$rawState['agent_context']=$agentContext;$plan=agent_v91_plan_stem($message,$rawState,$track,$user,$fallback);$commands=is_array($plan['commands']??null)?$plan['commands']:[];$answer=(string)($plan['answer']??'');$model=(string)($plan['model']??'');$provider=(string)($plan['provider']??($model==='deterministic'?'local':ai_active_provider()));$complexity=(string)($plan['complexity']??'routine');}

    $s=$pdo->prepare("INSERT INTO agent_studio_history (session_id,user_id,role,message_text,command_json,status,created_at) VALUES (?,?,'assistant',?,?,'pending',NOW())");$s->execute([$sessionId,$userId,$answer,json_encode(['commands'=>$commands,'model'=>$model,'provider'=>$provider,'complexity'=>$complexity],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$historyId=(int)$pdo->lastInsertId();
    $assistantMessageId=stem_agent_v131_append_chat($pdo,$user,$conversationId,'assistant',$answer,$inputMode,['editor'=>'stem','track_id'=>$trackId,'session_id'=>$sessionId,'commands'=>$commands,'model'=>$model,'provider'=>$provider,'complexity'=>$complexity,'agent_context'=>$agentContext]);
    agent_tool_log($user,'stem_studio.v105.agent_plan',$message,'queued',['track_id'=>$trackId,'commands'=>$commands,'model'=>$model,'provider'=>$provider,'complexity'=>$complexity,'agent_context'=>$agentContext],$conversationId);
    $pdo->prepare('UPDATE agent_studio_sessions SET last_activity_at=NOW() WHERE id=?')->execute([$sessionId]);
    stem_agent_v105_json(true,['session_id'=>$sessionId,'conversation_id'=>$conversationId,'history_id'=>$historyId,'assistant_message_id'=>$assistantMessageId,'answer'=>$answer,'commands'=>$commands,'model'=>$model,'provider'=>$provider,'complexity'=>$complexity]);
}catch(Throwable $e){stem_agent_v105_json(false,['error'=>$e->getMessage()],400);}
