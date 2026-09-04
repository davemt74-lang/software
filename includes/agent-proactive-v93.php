<?php
declare(strict_types=1);

function agent_proactive_v93_schema_ready(): bool
{
    return table_exists('agent_proactive_events');
}

function agent_proactive_v93_query(string $sql,array $params=[]): array
{
    $pdo=db();
    if(!$pdo)return [];
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll()?:[];}catch(Throwable $e){return [];}
}

function agent_proactive_v93_scalar(string $sql,array $params=[]): int
{
    $pdo=db();
    if(!$pdo)return 0;
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();}catch(Throwable $e){return 0;}
}

function agent_proactive_v93_text(string $value,int $max=140): string
{
    $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
    return mb_strimwidth($value,0,$max,'…');
}

function agent_proactive_v93_item(string $key,string $title,string $prompt,string $reason,int $priority,string $source,string $url=''): array
{
    $hash=sha1($key.'|'.agent_brain_normalize($title).'|'.agent_brain_normalize($prompt));
    return [
        'hash'=>$hash,'key'=>$key,'title'=>agent_proactive_v93_text($title,100),
        'prompt'=>agent_proactive_v93_text($prompt,700),'reason'=>agent_proactive_v93_text($reason,180),
        'priority'=>$priority,'source'=>$source,'url'=>$url,
    ];
}

function agent_proactive_v93_suppressed(int $userId): array
{
    if(!agent_proactive_v93_schema_ready())return [];
    $rows=agent_proactive_v93_query(
        "SELECT suggestion_hash,event_type,created_at FROM agent_proactive_events
         WHERE user_id=? AND event_type IN ('dismissed','acted') AND created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY)
         ORDER BY id DESC",[$userId]
    );
    $out=[];$now=time();
    foreach($rows as $row){
        $age=$now-(strtotime((string)$row['created_at'])?:$now);
        $ttl=((string)$row['event_type']==='dismissed')?7*86400:2*86400;
        if($age<=$ttl&&!isset($out[(string)$row['suggestion_hash']]))$out[(string)$row['suggestion_hash']]=true;
    }
    return $out;
}

function agent_proactive_v93_profile(array $user): array
{
    $uid=(int)($user['id']??0);
    $archive=table_exists('agent_chat_archive')?agent_proactive_v93_scalar('SELECT COUNT(*) FROM agent_chat_archive WHERE user_id=?',[$uid]):0;
    $memory=table_exists('agent_memory_items')?agent_proactive_v93_scalar('SELECT COALESCE(SUM(LEAST(occurrence_count,5)),0) FROM agent_memory_items WHERE user_id=? AND is_active=1',[$uid]):0;
    $edits=table_exists('agent_edit_events')?agent_proactive_v93_scalar('SELECT COUNT(*) FROM agent_edit_events WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)',[$uid]):0;
    $tools=table_exists('agent_tool_history')?agent_proactive_v93_scalar('SELECT COUNT(*) FROM agent_tool_history WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)',[$uid]):0;
    $media=table_exists('user_media_assets')?agent_proactive_v93_scalar('SELECT COUNT(*) FROM user_media_assets WHERE user_id=?',[$uid]):0;
    $sessions=table_exists('agent_studio_sessions')?agent_proactive_v93_scalar('SELECT COUNT(*) FROM agent_studio_sessions WHERE user_id=?',[$uid]):0;
    $score=min(200,$archive+($memory*2)+($edits*2)+$tools+($media*2)+($sessions*3));
    if($score<8)return ['score'=>$score,'level'=>'starter','label'=>'Getting started','limit'=>1];
    if($score<30)return ['score'=>$score,'level'=>'learning','label'=>'Learning your workflow','limit'=>3];
    if($score<90)return ['score'=>$score,'level'=>'personalized','label'=>'Personalized from Agent Brain','limit'=>4];
    return ['score'=>$score,'level'=>'specialized','label'=>'Specialized to your workflow','limit'=>5];
}

function agent_proactive_v93_suggestions(array $user,string $surface='chat',array $context=[]): array
{
    $uid=(int)($user['id']??0);$profile=agent_proactive_v93_profile($user);$items=[];
    $trackId=max(0,(int)($context['track_id']??0));$projectId=max(0,(int)($context['project_id']??0));
    $activity=function_exists('agent_activity_v94_snapshot')?agent_activity_v94_snapshot($user,$surface,$context):['state'=>'idle','label'=>'Idle','task_title'=>'','seconds_since_activity'=>0,'idle_for_seconds'=>0,'interruptible'=>true];
    $activityTask=trim((string)($activity['task_title']??''));
    if($activityTask!==''&&in_array((string)($activity['state']??''),['paused','idle'],true)){
        $idle=(string)$activity['state']==='idle';$secs=max(0,(int)($activity['idle_for_seconds']??$activity['seconds_since_activity']??0));$mins=max(1,(int)floor($secs/60));
        $items[]=agent_proactive_v93_item('activity-resume:'.sha1((string)($activity['context_key']??'').'|'.$activityTask),'Resume '.$activityTask,'Resume the task I was working on: '.$activityTask.'. Review where I stopped and help me continue with the most useful next step.',($idle?'Idle':'Paused').' on this task'.($mins>0?' for about '.$mins.' minute'.($mins===1?'':'s'):''),$idle?175:165,'activity_resume');
    }elseif($activityTask!==''&&(string)($activity['state']??'')==='working'){
        $items[]=agent_proactive_v93_item('activity-working:'.sha1((string)($activity['context_key']??'').'|'.$activityTask),'Next step on '.$activityTask,'Look at what I am actively doing on '.$activityTask.' and keep one useful next step ready without interrupting my current work.','Agent Brain sees active work on this task',112,'activity_working');
    }

    if(table_exists('agent_memory_items')){
        foreach(agent_proactive_v93_query("SELECT subject,memory_text,occurrence_count,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type='commitment' ORDER BY last_seen_at DESC,occurrence_count DESC LIMIT 4",[$uid]) as $row){
            $text=agent_proactive_v93_text((string)$row['memory_text'],240);
            $items[]=agent_proactive_v93_item('commitment:'.sha1($text),'Finish an open task','Help me continue and finish this open task from my Agent Brain: '.$text,'Unfinished commitment from your Agent Brain',125+(int)$row['occurrence_count'],'commitment');
        }
    }

    if(table_exists('agent_studio_history')&&table_exists('agent_studio_sessions')){
        $rows=agent_proactive_v93_query("SELECT h.status,h.message_text,h.result_text,s.track_id,t.title FROM agent_studio_history h JOIN agent_studio_sessions s ON s.id=h.session_id LEFT JOIN tracks t ON t.id=s.track_id WHERE h.user_id=? AND h.status IN ('pending','failed') ORDER BY h.id DESC LIMIT 3",[$uid]);
        foreach($rows as $row){$title=trim((string)($row['title']??''))?:'Stem Studio project';$msg=agent_proactive_v93_text((string)$row['message_text'],180);$items[]=agent_proactive_v93_item('unfinished-studio:'.(int)$row['track_id'].':'.sha1($msg),'Finish '.$title,'Open '.$title.' in Stem Studio and help me finish this incomplete task: '.$msg,'Incomplete Studio Agent work',140,'unfinished_studio',url('/admin/stems.php?track='.(int)$row['track_id']));}
    }

    if(table_exists('agent_studio_sessions')){
        foreach(agent_proactive_v93_query("SELECT s.track_id,s.last_activity_at,t.title FROM agent_studio_sessions s LEFT JOIN tracks t ON t.id=s.track_id WHERE s.user_id=? AND s.last_activity_at>=DATE_SUB(NOW(),INTERVAL 21 DAY) ORDER BY s.last_activity_at DESC LIMIT 3",[$uid]) as $row){
            $tid=(int)$row['track_id'];if($surface==='stem'&&$trackId===$tid)continue;$title=trim((string)($row['title']??''))?:'recent song';
            $items[]=agent_proactive_v93_item('studio-session:'.$tid,'Continue '.$title,'Open '.$title.' in Stem Studio and review where I left off. Suggest the most useful next production step and help me do it.','Recent Stem Studio session',105,'studio_session',url('/admin/stems.php?track='.$tid));
        }
    }

    if(table_exists('booking_agent_opportunities')){
        foreach(agent_proactive_v93_query("SELECT id,title,venue,city,region,status FROM booking_agent_opportunities WHERE user_id=? AND status NOT IN ('closed','booked','declined') ORDER BY updated_at DESC,id DESC LIMIT 2",[$uid]) as $row){
            $where=trim(implode(', ',array_filter([(string)$row['venue'],trim((string)$row['city'].' '.(string)$row['region'])])));
            $items[]=agent_proactive_v93_item('booking:'.(int)$row['id'],'Review booking opportunity','Review my open booking opportunity "'.(string)$row['title'].'"'.($where!==''?' at '.$where:'').'. Tell me the best next action and help me take it.','Open Booking Agent opportunity',110,'booking');
        }
    }

    if(table_exists('user_media_assets')){
        $mediaRows=agent_proactive_v93_query('SELECT id,media_type,title,created_at FROM user_media_assets WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 4',[$uid]);
        if($mediaRows){
            $latest=$mediaRows[0];$title=trim((string)$latest['title'])?:'recent capture';$type=(string)$latest['media_type'];
            $latestProject=table_exists('video_editor_projects')?agent_proactive_v93_query('SELECT id,title,updated_at FROM video_editor_projects WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 1',[$uid]):[];
            $needsContent=!$latestProject||strtotime((string)$latest['created_at'])>strtotime((string)($latestProject[0]['updated_at']??'1970-01-01'));
            if($needsContent)$items[]=agent_proactive_v93_item('media-content:'.(int)$latest['id'],'Turn '.$title.' into content','Use my recent '.$type.' "'.$title.'" as source material and help me turn it into a useful piece of content in the Video Editor.','Recent media has not been followed by a newer video project',102,'recent_media',url('/video-editor.php?asset='.(int)$latest['id']));
        }
    }

    if(table_exists('video_editor_projects')){
        foreach(agent_proactive_v93_query('SELECT id,title,updated_at FROM video_editor_projects WHERE user_id=? AND updated_at>=DATE_SUB(NOW(),INTERVAL 21 DAY) ORDER BY updated_at DESC,id DESC LIMIT 2',[$uid]) as $row){
            $pid=(int)$row['id'];if($surface==='video'&&$projectId===$pid)continue;$title=trim((string)$row['title'])?:'video project';
            $items[]=agent_proactive_v93_item('video-project:'.$pid,'Continue '.$title,'Open my video project "'.$title.'", review the current timeline, and suggest the best next edit to make.','Recent Video Editor project',95,'video_project',url('/video-editor.php?project='.$pid));
        }
    }

    if(table_exists('agent_memory_items')&&$profile['level']!=='starter'){
        foreach(agent_proactive_v93_query("SELECT subject,memory_text,occurrence_count,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type='theme' AND occurrence_count>=3 ORDER BY occurrence_count DESC,last_seen_at DESC LIMIT 4",[$uid]) as $row){
            $subject=agent_proactive_v93_text((string)$row['subject'],60);if($subject==='')continue;
            $items[]=agent_proactive_v93_item('theme:'.sha1($subject),'Go deeper on '.$subject,'Based on the recurring Agent Brain pattern around "'.$subject.'", identify one concrete thing I can make, finish, improve, or follow up on right now and help me do it.','Recurring Agent Brain theme · '.(int)$row['occurrence_count'].' occurrences',75+(int)$row['occurrence_count'],'pattern');
        }
    }

    if(table_exists('agent_edit_events')&&in_array($profile['level'],['personalized','specialized'],true)){
        foreach(agent_proactive_v93_query("SELECT editor_kind,action_key,COUNT(*) AS c,MAX(created_at) AS last_seen FROM agent_edit_events WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY editor_kind,action_key HAVING COUNT(*)>=4 ORDER BY c DESC,last_seen DESC LIMIT 3",[$uid]) as $row){
            $action=str_replace(['_','.'],' ',(string)$row['action_key']);
            $items[]=agent_proactive_v93_item('edit-pattern:'.(string)$row['editor_kind'].':'.(string)$row['action_key'],'Review repeated '.$action.' edits','Review my recent '.$action.' changes in the '.(string)$row['editor_kind'].' editor. Tell me whether there is a repeat pattern I should consolidate, automate, or improve.','Repeated edit pattern · '.(int)$row['c'].' changes in 14 days',88+(int)$row['c'],'edit_pattern');
        }
    }

    if(table_exists('agent_tool_history')&&in_array($profile['level'],['personalized','specialized'],true)){
        foreach(agent_proactive_v93_query("SELECT tool_key,COUNT(*) AS c,MAX(created_at) AS last_seen FROM agent_tool_history WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY tool_key HAVING COUNT(*)>=3 ORDER BY c DESC,last_seen DESC LIMIT 2",[$uid]) as $row){
            $label=str_replace(['_','.'],' ',(string)$row['tool_key']);
            $items[]=agent_proactive_v93_item('tool-pattern:'.(string)$row['tool_key'],'Keep momentum on '.$label,'I have used '.$label.' repeatedly recently. Look at that Agent Brain history and suggest the most useful next task related to it.','Repeated Agent tool usage · '.(int)$row['c'].' times',72+(int)$row['c'],'tool_pattern');
        }
    }

    if($surface==='stem'&&$trackId>0&&table_exists('agent_edit_events')){
        $count=agent_proactive_v93_scalar("SELECT COUNT(*) FROM agent_edit_events WHERE user_id=? AND editor_kind='stem' AND project_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",[$uid,$trackId]);
        if($count>=3)$items[]=agent_proactive_v93_item('current-stem-review:'.$trackId,'Review this mix','Review every recent edit on this Stem Studio project, identify what changed most, and suggest the next production move.','Current project has '.$count.' tracked edits this week',118,'current_project');
    }
    if($surface==='video'&&$projectId>0&&table_exists('agent_edit_events')){
        $count=agent_proactive_v93_scalar("SELECT COUNT(*) FROM agent_edit_events WHERE user_id=? AND editor_kind='video' AND project_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",[$uid,$projectId]);
        if($count>=3)$items[]=agent_proactive_v93_item('current-video-review:'.$projectId,'Review this cut','Review the current Video Editor timeline and my recent edit history. Suggest the single highest-value next edit and help me make it.','Current project has '.$count.' tracked edits this week',118,'current_project');
    }

    $starter=[
        agent_proactive_v93_item('starter:voice','Record a quick idea','Open the voice recorder so I can capture a quick audio idea for later.','Simple first task while Agent Brain learns your workflow',30,'starter'),
        agent_proactive_v93_item('starter:capture','Capture something for content','Open the camera workspace so I can take a photo or record a short video for my media library.','Build your media library so future suggestions can become more specific',28,'starter'),
        agent_proactive_v93_item('starter:video','Create a short video','Open the Video Editor and help me create a simple short video from my media library.','A basic production task to start building history',25,'starter',url('/video-editor.php')),
    ];
    if(has_permission('tracks.manage',$user)||has_permission('track_notes.manage',$user)||has_permission('producer.access',$user))$starter[]=agent_proactive_v93_item('starter:studio','Work on a song','Show me a song or Stem Studio project I can work on right now.','Start building production history for more specialized guidance',26,'starter');
    foreach($starter as $item)$items[]=$item;

    $dedup=[];foreach($items as $item){$k=$item['hash'];if(!isset($dedup[$k])||$item['priority']>$dedup[$k]['priority'])$dedup[$k]=$item;}
    $items=array_values($dedup);usort($items,static fn($a,$b)=>$b['priority']<=>$a['priority']);
    $suppressed=agent_proactive_v93_suppressed($uid);$visible=array_values(array_filter($items,static fn($item)=>empty($suppressed[$item['hash']])));
    if(!$visible)$visible=[agent_proactive_v93_item('fallback:next','Ask Stonefellow for a next move','Look across my Agent Brain history and current Stonefellow activity and give me one useful thing to work on right now.','Fresh fallback after recent suggestions were completed or dismissed',10,'fallback')];
    $visible=array_slice($visible,0,(int)$profile['limit']);
    return ['profile'=>$profile,'activity'=>$activity,'suggestions'=>$visible];
}

function agent_proactive_v93_event(array $user,string $hash,string $eventType,string $surface,array $payload=[]): void
{
    if(!agent_proactive_v93_schema_ready())return;
    $pdo=db();$uid=(int)($user['id']??0);$hash=preg_match('/^[a-f0-9]{40}$/',$hash)?$hash:sha1($hash);
    if(!$pdo||$uid<1||!in_array($eventType,['shown','acted','dismissed'],true))return;
    $stmt=$pdo->prepare('INSERT INTO agent_proactive_events (user_id,suggestion_hash,surface,event_type,title,prompt,source_kind,context_json,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
    $stmt->execute([
        $uid,$hash,mb_substr($surface,0,30),$eventType,
        mb_substr((string)($payload['title']??''),0,190),mb_substr((string)($payload['prompt']??''),0,1000),
        mb_substr((string)($payload['source']??''),0,60),
        json_encode($payload['context']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]);
}
