<?php
declare(strict_types=1);

function agent_activity_v94_schema_ready(): bool
{
    return table_exists('agent_activity_state') && table_exists('agent_activity_events');
}

/** These stored times and the comparison time come from the same SQL session clock. */
function agent_activity_v94_age_seconds(?string $at, ?string $checkedAt): ?int
{
    if ($at === null || $checkedAt === null) return null;
    $zone = new DateTimeZone('UTC');
    $seen = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$at,$zone);
    $checked = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$checkedAt,$zone);
    if (!$seen || !$checked || $seen->format('Y-m-d H:i:s') !== $at || $checked->format('Y-m-d H:i:s') !== $checkedAt) return null;
    return $checked->getTimestamp()-$seen->getTimestamp();
}

function agent_activity_v94_busy_reason(mixed $value): ?string
{
    return in_array($value,['restore','draft','typed_draft','restored_draft','voice_preview','chat_request','voice_turn','voice_playback'],true)?$value:null;
}

/** Keep only timestamps for the current authorized Chat, outside the durable event feed. */
function agent_activity_v94_owner_idle_v1(array $context,array $details,array $previous,int $owner,int $conversation,
    bool $previousFresh,int $nowMs): array
{
    $binding=$context['_vp3_live_binding']??null;
    $agent=$binding['agent_id']??null;
    $observed=$context['activity_observed_at']??null;
    if($conversation<1||!is_array($binding)||($binding['owner_user_id']??null)!==$owner
        ||($binding['conversation_id']??null)!==$conversation||!is_int($agent)||$agent<0
        ||($binding['activity_tab']??null)!==($details['activity_tab']??null)||($details['activity_tab']??'')===''
        ||!is_int($observed)||abs($observed-$nowMs)>90000)return [];
    $key=['agent_id'=>$agent,'conversation_id'=>$conversation];
    $read=static function(mixed $value)use($key,$nowMs):int{
        if(!is_array($value)||($value['agent_id']??null)!==$key['agent_id']
            ||($value['conversation_id']??null)!==$key['conversation_id']||!is_int($value['at_ms']??null)
            ||$value['at_ms']<1||$value['at_ms']>$nowMs)return 0;
        return $value['at_ms'];
    };
    $out=[];
    $focused=($details['chat_focused']??null)===true&&($details['visible']??null)===true;
    $last=$read($previous['owner_interaction']??null);
    $action=$context['owner_interaction_at_ms']??null;
    if($focused&&is_int($action)&&$action<=$observed&&$action<=$nowMs&&$action>$nowMs-90000)$last=max($last,$action);
    if($last>0)$out['owner_interaction']=$key+['at_ms'=>$last];
    if($focused&&($details['chat_busy']??null)===false&&($details['media_playing']??false)===false){
        $prior=$previousFresh?$read($previous['quiet_chat_since']??null):0;
        $out['quiet_chat_since']=$key+['at_ms'=>$prior?:$nowMs];
    }
    return $out;
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
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
    // The same owner lock used by Initiative serializes observation ordering
    // with report delivery, including the first activity row for this owner.
    $ownerLock=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));
    $ownerLock->execute([$uid]);
    if(!$ownerLock->fetchColumn())throw new RuntimeException('Activity owner is unavailable.',403);
    $s=$pdo->prepare('SELECT *,CURRENT_TIMESTAMP AS checked_at FROM agent_activity_state WHERE user_id=? LIMIT 1');$s->execute([$uid]);$prev=$s->fetch()?:null;
    $prevDetails=json_decode((string)($prev['details_json']??''),true);if(!is_array($prevDetails))$prevDetails=[];
    $tab=is_string($context['activity_tab']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$context['activity_tab'])?$context['activity_tab']:'';
    $focused=is_bool($context['focused']??null)?($context['focused']&&!empty($context['visible'])):null;
    $observed=$context['activity_observed_at']??null;$sequence=$context['activity_sequence']??null;
    $newSignal=array_intersect(['activity_tab','activity_observed_at','activity_sequence','focused','chat_busy','chat_focused'],array_keys($context))!==[];
    $validObservation=is_int($observed)&&abs($observed-(int)floor(microtime(true)*1000))<=90000
        &&is_int($sequence)&&$sequence>0&&$sequence<=2147483647;
    $previousAge=agent_activity_v94_age_seconds($prev['last_heartbeat_at']??null,$prev['checked_at']??null);
    $sameTab=$tab!==''&&is_string($prevDetails['activity_tab']??null)&&hash_equals($prevDetails['activity_tab'],$tab);
    $previousFresh=$previousAge!==null&&$previousAge>=-5&&$previousAge<=90;
    $previousObserved=$prevDetails['activity_observed_at']??null;$previousSequence=$prevDetails['activity_sequence']??null;
    $stale=$validObservation&&$previousFresh&&is_int($previousObserved)
        &&($sameTab&&is_int($previousSequence)?$sequence<=$previousSequence:$observed<=$previousObserved);
    if($state!=='logged_out'&&(($newSignal&&(!$validObservation||$tab===''||$focused===null))||$stale)){
        if($ownsTransaction)$pdo->commit();
        return agent_activity_v94_snapshot($user,$surface,$context);
    }
    // A different background tab (including an older cached client) cannot
    // replace a fresh focused tab's busy/identity state. Its heartbeat also
    // cannot keep that focused tab alive after it disappears.
    if($state!=='logged_out'&&($prevDetails['focused']??null)===true&&!empty($prevDetails['visible'])
        &&$previousFresh&&$focused!==true&&!$sameTab){
        if($ownsTransaction)$pdo->commit();
        return agent_activity_v94_snapshot($user,$surface,$context);
    }
    $conversationId=max(0,(int)($context['conversation_id']??0));if($conversationId<1)$conversationId=max(0,(int)($prevDetails['conversation_id']??0));
    $context['conversation_id']=$conversationId;$contextKey=agent_activity_v94_context_key($surface,$context);
    $details=['track_id'=>max(0,(int)($context['track_id']??0)),'project_id'=>max(0,(int)($context['project_id']??0)),'conversation_id'=>$conversationId,'path'=>mb_substr((string)($context['path']??''),0,300),'visible'=>!empty($context['visible'])];
    if($tab!==''&&$focused!==null){
        $details['activity_tab']=$tab;$details['focused']=$focused;
        if($validObservation){$details['activity_observed_at']=$observed;$details['activity_sequence']=$sequence;}
        if($surface==='chat'&&(array_key_exists('chat_focused',$context)||array_key_exists('chat_busy',$context))){
            $valid=is_bool($context['chat_focused']??null)&&is_bool($context['chat_busy']??null);
            $details['chat_focused']=$valid&&$focused&&$context['chat_focused'];
            $details['chat_busy']=$valid?$context['chat_busy']:true;
            $busyReason=agent_activity_v94_busy_reason($context['chat_busy_reason']??null);
            if($valid&&$details['chat_busy']&&$busyReason!==null)$details['chat_busy_reason']=$busyReason;
            if(is_bool($context['media_playing']??null))$details['media_playing']=$context['media_playing'];
        }
    }elseif(array_key_exists('chat_focused',$context)||array_key_exists('chat_busy',$context)){
        // Partial/invalid new signals fail closed instead of regaining the
        // legacy paused/idle fallback through truthy strings or a missing key.
        $details['chat_focused']=false;$details['chat_busy']=true;
    }
    if($state!=='logged_out'&&$surface==='chat'){
        $details+=agent_activity_v94_owner_idle_v1($context,$details,$prevDetails,$uid,$conversationId,$previousFresh,
            (int)floor(microtime(true)*1000));
    }
    if($state!=='logged_out'&&$surface==='chat'&&is_array($context['_vp3_live_binding']??null)
        &&($context['_vp3_live_binding']['owner_user_id']??null)===$uid
        &&($context['_vp3_live_binding']['conversation_id']??null)===$conversationId
        &&is_array($context['live_context']??null)){
        require_once __DIR__.'/agent-live-context-v1.php';
        $packet=vp3_live_context_sanitize_v1($context['live_context'],$context['_vp3_live_binding']);
        if($packet!==null)$details['live_context']=$packet;
    }
    $source=$context['transcript_source_id']??null;
    $sourceBinding=$context['_vp3_live_binding']??null;
    if($state!=='logged_out'&&$surface==='chat'&&($details['chat_focused']??false)===true
        &&is_int($source)&&$source>0&&is_array($sourceBinding)
        &&($sourceBinding['owner_user_id']??null)===$uid&&($sourceBinding['conversation_id']??null)===$conversationId
        &&($sourceBinding['activity_tab']??null)===$tab&&is_int($sourceBinding['agent_id']??null)){
        // Selection is operational context only; the transcript reader checks
        // ownership, current source text and processing policy before use.
        $details['transcript_focus']=['session_id'=>$source,'agent_id'=>$sourceBinding['agent_id'],'conversation_id'=>$conversationId];
    }
    $sql="INSERT INTO agent_activity_state (user_id,surface,context_key,task_kind,task_title,activity_state,last_input_at,last_task_at,last_heartbeat_at,idle_since_at,details_json,updated_at) VALUES (?,?,?,?,?,?,NOW(),COALESCE(?,NOW()),NOW(),IF(?='idle',NOW(),NULL),?,NOW()) ON DUPLICATE KEY UPDATE surface=VALUES(surface),context_key=VALUES(context_key),task_kind=VALUES(task_kind),task_title=VALUES(task_title),activity_state=VALUES(activity_state),last_input_at=IF(VALUES(activity_state)='working',NOW(),last_input_at),last_task_at=IF(VALUES(activity_state)='working',NOW(),last_task_at),last_heartbeat_at=NOW(),idle_since_at=IF(VALUES(activity_state)='idle',COALESCE(idle_since_at,NOW()),NULL),details_json=VALUES(details_json),updated_at=NOW()";
    $s=$pdo->prepare($sql);$s->execute([$uid,$surface,$contextKey,$taskKind,$taskTitle,$state,$prev['last_task_at']??null,$state,json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $changed=!$prev||(string)$prev['activity_state']!==$state||(string)$prev['context_key']!==$contextKey||(string)$prev['task_title']!==$taskTitle;
    if($changed){
        // Live observations belong only to the bounded current-context cache.
        // Activity history records navigation/state, never a sensory packet.
        $historyDetails=$details;unset($historyDetails['live_context'],$historyDetails['transcript_focus'],
            $historyDetails['owner_interaction'],$historyDetails['quiet_chat_since']);
        $e=$pdo->prepare('INSERT INTO agent_activity_events (user_id,surface,context_key,task_kind,task_title,previous_state,activity_state,reason,details_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $e->execute([$uid,$surface,$contextKey,$taskKind,$taskTitle,(string)($prev['activity_state']??''),$state,mb_substr($reason,0,120),json_encode($historyDetails,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }
    if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    // The legacy live-session bridge owns its transaction; never let it commit
    // or roll back a caller's still-active transaction.
    if(!$pdo->inTransaction()&&function_exists('vp3_live_session_record_activity_v2370')){
        $liveContext=$context;
        unset($liveContext['live_context'],$liveContext['_vp3_live_binding'],$liveContext['transcript_source_id']);
        $liveContext['task_title']=$taskTitle;
        $liveContext['task_kind']=$taskKind;
        try{vp3_live_session_record_activity_v2370($user,$surface,$state,$liveContext,$reason,$changed);}catch(Throwable $e){error_log('VP3 live session bridge failed: '.$e->getMessage());}
    }
    return agent_activity_v94_snapshot($user,$surface,$context);
}

function agent_activity_v94_snapshot(array $user,string $surface='chat',array $context=[]): array
{
    $uid=(int)($user['id']??0);$pdo=db();$surface=preg_replace('/[^a-z0-9_-]/','',strtolower($surface))?:'chat';
    $base=['state'=>'idle','label'=>'Idle','surface'=>$surface,'context_key'=>agent_activity_v94_context_key($surface,$context),'task_title'=>agent_activity_v94_task_title($surface,$context),'seconds_since_activity'=>0,'idle_for_seconds'=>0,'interruptible'=>true,'tracking_ready'=>agent_activity_v94_schema_ready()];
    if(!$pdo||$uid<1)return $base;
    $row=null;
    if(agent_activity_v94_schema_ready()){try{$s=$pdo->prepare('SELECT *,CURRENT_TIMESTAMP AS checked_at FROM agent_activity_state WHERE user_id=? LIMIT 1');$s->execute([$uid]);$row=$s->fetch()?:null;}catch(Throwable $e){}}
    $state=(string)($row['activity_state']??'idle');$heartbeatAge=agent_activity_v94_age_seconds($row['last_heartbeat_at']??null,$row['checked_at']??null);
    $age=$heartbeatAge===null?999999:max(0,$heartbeatAge);
    if($age>600&&$state!=='logged_out')$state='idle';
    $taskTitle=trim((string)($row['task_title']??''))?:$base['task_title'];$contextKey=trim((string)($row['context_key']??''))?:$base['context_key'];
    // Corroborate active production work from the durable edit ledger.
    if(table_exists('agent_edit_events')){
        try{$params=[$uid];$where='user_id=?';if($surface==='stem'&&($id=(int)($context['track_id']??0))>0){$where.=" AND editor_kind='stem' AND project_id=?";$params[]=$id;}elseif($surface==='video'&&($id=(int)($context['project_id']??0))>0){$where.=" AND editor_kind='video' AND project_id=?";$params[]=$id;}$s=$pdo->prepare("SELECT created_at,CURRENT_TIMESTAMP AS checked_at FROM agent_edit_events WHERE $where ORDER BY id DESC LIMIT 1");$s->execute($params);$edit=$s->fetch(PDO::FETCH_ASSOC);$editAge=agent_activity_v94_age_seconds($edit['created_at']??null,$edit['checked_at']??null);if($editAge!==null&&$editAge>=0){if($editAge<=120){$state='working';$age=min($age,$editAge);}elseif($editAge<=480&&$state==='idle'){$state='paused';$age=min($age,$editAge);}}}catch(Throwable $e){}
    }
    if(!in_array($state,['working','paused','idle','logged_out'],true))$state='idle';
    $idleAge=agent_activity_v94_age_seconds($row['idle_since_at']??null,$row['checked_at']??null);
    $idleFor=$state==='idle'?max(0,$idleAge??0):0;
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
