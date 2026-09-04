<?php
declare(strict_types=1);

function agent_activity_v94_schema_ready(): bool
{
    return table_exists('agent_activity_state') && table_exists('agent_activity_events');
}

function agent_activity_v94_context_key(string $surface,array $context=[]): string
{
    $surface=preg_replace('/[^a-z0-9_-]/','',strtolower($surface))?:'chat';
    $track=max(0,(int)($context['track_id']??0));
    $project=max(0,(int)($context['project_id']??0));
    $conversation=max(0,(int)($context['conversation_id']??0));
    if($surface==='chat'&&$conversation>0)return 'chat:'.$conversation;
    if($surface==='stem'&&$track>0)return 'stem:'.$track;
    if($surface==='video'&&$project>0)return 'video:'.$project;
    return $surface;
}

function agent_activity_v94_task_title(string $surface,array $context=[]): string
{
    $title=trim((string)($context['task_title']??''));
    if($title!=='')return mb_strimwidth($title,0,190,'…');
    $pdo=db();if(!$pdo)return $surface==='chat'?'Agent Chat':'';
    try{
        if($surface==='stem'&&($id=(int)($context['track_id']??0))>0){$s=$pdo->prepare('SELECT title FROM tracks WHERE id=? LIMIT 1');$s->execute([$id]);$v=trim((string)$s->fetchColumn());return $v!==''?'Stem Studio · '.$v:'Stem Studio';}
        if($surface==='video'&&($id=(int)($context['project_id']??0))>0){$s=$pdo->prepare('SELECT title FROM video_editor_projects WHERE id=? LIMIT 1');$s->execute([$id]);$v=trim((string)$s->fetchColumn());return $v!==''?'Video Editor · '.$v:'Video Editor';}
    }catch(Throwable $e){}
    return $surface==='chat'?'Agent Chat':'';
}

function agent_activity_v94_record(array $user,string $surface,string $state,array $context=[],string $reason='heartbeat'): array
{
    $uid=(int)($user['id']??0);$pdo=db();
    $surface=preg_replace('/[^a-z0-9_-]/','',strtolower($surface))?:'chat';
    $state=in_array($state,['working','paused','idle','logged_out'],true)?$state:'idle';
    $taskTitle=agent_activity_v94_task_title($surface,$context);
    $taskKind=preg_replace('/[^a-z0-9_-]/','',strtolower((string)($context['task_kind']??$surface)))?:$surface;
    if(!$pdo||$uid<1||!agent_activity_v94_schema_ready())return ['state'=>$state,'surface'=>$surface,'context_key'=>agent_activity_v94_context_key($surface,$context),'task_title'=>$taskTitle,'tracking_ready'=>false];
    $prev=null;
    try{$s=$pdo->prepare('SELECT * FROM agent_activity_state WHERE user_id=? LIMIT 1');$s->execute([$uid]);$prev=$s->fetch()?:null;}catch(Throwable $e){}
    $prevDetails=json_decode((string)($prev['details_json']??''),true);if(!is_array($prevDetails))$prevDetails=[];
    $conversationId=max(0,(int)($context['conversation_id']??0));if($conversationId<1)$conversationId=max(0,(int)($prevDetails['conversation_id']??0));
    $context['conversation_id']=$conversationId;$contextKey=agent_activity_v94_context_key($surface,$context);
    $details=['track_id'=>max(0,(int)($context['track_id']??0)),'project_id'=>max(0,(int)($context['project_id']??0)),'conversation_id'=>$conversationId,'path'=>mb_substr((string)($context['path']??''),0,300),'visible'=>!empty($context['visible'])];
    $idleSince=$state==='idle' ? date('Y-m-d H:i:s') : null;
    $lastTask=$state==='working' ? date('Y-m-d H:i:s') : (string)($prev['last_task_at']??date('Y-m-d H:i:s'));
    $sql="INSERT INTO agent_activity_state (user_id,surface,context_key,task_kind,task_title,activity_state,last_input_at,last_task_at,last_heartbeat_at,idle_since_at,details_json,updated_at) VALUES (?,?,?,?,?,?,NOW(),?,NOW(),?,?,NOW()) ON DUPLICATE KEY UPDATE surface=VALUES(surface),context_key=VALUES(context_key),task_kind=VALUES(task_kind),task_title=VALUES(task_title),activity_state=VALUES(activity_state),last_input_at=IF(VALUES(activity_state)='working',NOW(),last_input_at),last_task_at=IF(VALUES(activity_state)='working',NOW(),last_task_at),last_heartbeat_at=NOW(),idle_since_at=IF(VALUES(activity_state)='idle',COALESCE(idle_since_at,NOW()),NULL),details_json=VALUES(details_json),updated_at=NOW()";
    $s=$pdo->prepare($sql);$s->execute([$uid,$surface,$contextKey,$taskKind,$taskTitle,$state,$lastTask?:date('Y-m-d H:i:s'),$idleSince,json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $changed=!$prev||(string)$prev['activity_state']!==$state||(string)$prev['context_key']!==$contextKey||(string)$prev['task_title']!==$taskTitle;
    if($changed){
        $e=$pdo->prepare('INSERT INTO agent_activity_events (user_id,surface,context_key,task_kind,task_title,previous_state,activity_state,reason,details_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $e->execute([$uid,$surface,$contextKey,$taskKind,$taskTitle,(string)($prev['activity_state']??''),$state,mb_substr($reason,0,120),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }
    return agent_activity_v94_snapshot($user,$surface,$context);
}

function agent_activity_v94_snapshot(array $user,string $surface='chat',array $context=[]): array
{
    $uid=(int)($user['id']??0);$pdo=db();$surface=preg_replace('/[^a-z0-9_-]/','',strtolower($surface))?:'chat';
    $base=['state'=>'idle','label'=>'Idle','surface'=>$surface,'context_key'=>agent_activity_v94_context_key($surface,$context),'task_title'=>agent_activity_v94_task_title($surface,$context),'seconds_since_activity'=>0,'idle_for_seconds'=>0,'interruptible'=>true,'tracking_ready'=>agent_activity_v94_schema_ready()];
    if(!$pdo||$uid<1)return $base;
    $row=null;
    if(agent_activity_v94_schema_ready()){try{$s=$pdo->prepare('SELECT * FROM agent_activity_state WHERE user_id=? LIMIT 1');$s->execute([$uid]);$row=$s->fetch()?:null;}catch(Throwable $e){}}
    $now=time();$state=(string)($row['activity_state']??'idle');$last=$row?strtotime((string)($row['last_heartbeat_at']??'')):false;$age=$last?max(0,$now-$last):999999;
    if($age>600&&$state!=='logged_out')$state='idle';
    $taskTitle=trim((string)($row['task_title']??''))?:$base['task_title'];$contextKey=trim((string)($row['context_key']??''))?:$base['context_key'];
    // Corroborate active production work from the durable edit ledger.
    if(table_exists('agent_edit_events')){
        try{$params=[$uid];$where='user_id=?';if($surface==='stem'&&($id=(int)($context['track_id']??0))>0){$where.=" AND editor_kind='stem' AND project_id=?";$params[]=$id;}elseif($surface==='video'&&($id=(int)($context['project_id']??0))>0){$where.=" AND editor_kind='video' AND project_id=?";$params[]=$id;}$s=$pdo->prepare("SELECT created_at FROM agent_edit_events WHERE $where ORDER BY id DESC LIMIT 1");$s->execute($params);$editAt=strtotime((string)$s->fetchColumn());if($editAt){$editAge=$now-$editAt;if($editAge<=120){$state='working';$age=min($age,$editAge);}elseif($editAge<=480&&$state==='idle'){$state='paused';$age=min($age,$editAge);}}}catch(Throwable $e){}
    }
    if(!in_array($state,['working','paused','idle','logged_out'],true))$state='idle';
    $idleAt=$row?strtotime((string)($row['idle_since_at']??'')):false;$idleFor=$state==='idle'&&$idleAt?max(0,$now-$idleAt):0;
    return ['state'=>$state,'label'=>$state==='working'?'Working':($state==='paused'?'Paused':($state==='logged_out'?'Logged out':'Idle')),'surface'=>(string)($row['surface']??$surface),'context_key'=>$contextKey,'task_title'=>$taskTitle,'seconds_since_activity'=>$age,'idle_for_seconds'=>$idleFor,'interruptible'=>$state!=='working','tracking_ready'=>agent_activity_v94_schema_ready()];
}

function agent_activity_v101_logout(array $user): void
{
    try {
        $context=['task_title'=>'Signed out','task_kind'=>'session','path'=>'/logout.php','visible'=>false];$surface='chat';$pdo=db();
        if($pdo&&agent_activity_v94_schema_ready()){$stmt=$pdo->prepare('SELECT surface,details_json FROM agent_activity_state WHERE user_id=? LIMIT 1');$stmt->execute([(int)$user['id']]);$row=$stmt->fetch()?:[];$surface=(string)($row['surface']??'chat');$details=json_decode((string)($row['details_json']??''),true);if(is_array($details))$context=array_merge($details,$context);}
        agent_activity_v94_record($user,$surface,'logged_out',$context,'explicit_logout');
    } catch (Throwable $e) {
        error_log('Stonefellow logout activity tracking failed: '.$e->getMessage());
    }
}
