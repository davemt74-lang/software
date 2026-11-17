<?php
declare(strict_types=1);

require_once __DIR__.'/agent-top-of-mind-v1.php';
require_once __DIR__.'/agent-initiative-followthrough-v1.php';
require_once __DIR__.'/agent-initiative-engagement-status-v1.php';

const VP3_COORDINATION_REMINDER_CURSOR_V1='coordination-reminder-scan';

function vp3_coordination_reminder_interval_v1(string $priority): int
{
    return match($priority){
        'high'=>6*3600,
        'low'=>48*3600,
        default=>24*3600,
    };
}

/** Reminder messages only link to engagement records that can still accept a reply. */
function vp3_coordination_reminder_open_v1(array $state): bool
{
    return ($state['state']??null)==='open'||(($state['state']??null)==='discussed'
        &&trim((string)($state['followup_clarification']??''))!==''
        &&($state['continuation_run_id']??null)===0);
}

/** Give active conversations more time and space repeated reminders further apart. */
function vp3_coordination_gmail_reminder_delay_v1(string $priority,int $count,int $traffic): int
{
    $base=$priority==='low'?12*3600:4*3600;
    $backoff=1<<min(4,max(0,$count));
    return min(7*86400,intdiv($base*$backoff*(6+min(6,max(0,$traffic))),6));
}

/** Use the saved Chat ledger for recurring slots; the caller owns the Initiative owner lock. */
function vp3_coordination_post_reminder_v1(PDO $pdo,array $user,array $binding,?int $now=null): bool
{
    $now??=time();$owner=(int)($user['id']??0);$cid=(int)($binding['conversation_id']??0);
    if(!$pdo->inTransaction()||$owner<1||$cid<1||($binding['enabled']??false)!==true
        ||vp3_participant_scope_v1()!==null)return false;
    $agent=vp3_initiative_binding_agent_v1($binding);
    if(vp3_initiative_report_gate_v1($pdo,$user,$cid,$agent)!=='ready')return false;
    $principal=vp3_initiative_principal_v1($pdo,$user,$agent);
    $source=vp3_initiative_engagement_json_v1($pdo,'m.context_json','source');
    $state=vp3_initiative_engagement_json_v1($pdo,'m.context_json','initiative_followthrough_state.state');
    $original=vp3_initiative_engagement_json_v1($pdo,'m.context_json','initiative_reminder.message_id');
    $reminderFamily=vp3_initiative_engagement_json_v1($pdo,'m.context_json','initiative_reminder.specialist');
    $priorPriority=vp3_initiative_engagement_json_v1($pdo,'m.context_json','initiative_reminder.priority');
    $time=vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at');
    $integer=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INTEGER':'UNSIGNED';
    $high=vp3_coordination_reminder_interval_v1('high');
    $normal=vp3_coordination_reminder_interval_v1('normal');
    $low=vp3_coordination_reminder_interval_v1('low');
    // Only inspect the current Chat's work and activity if it has an open engagement.
    $old=$pdo->prepare("SELECT 1 FROM chat_messages m WHERE m.conversation_id=? AND m.role='assistant'
        AND m.user_id IS NULL AND $source='agent_initiative' AND $state IN ('open','discussed') LIMIT 1");
    $old->execute([$cid]);
    if($old->fetchColumn()===false)return false;

    // The other specialists continue to use their existing fixed reminder spacing.
    $recent=$pdo->prepare("SELECT 1 FROM chat_conversations c JOIN chat_messages m ON m.conversation_id=c.id
        WHERE c.user_id=? AND m.role='assistant' AND m.user_id IS NULL AND $source='agent_initiative_reminder'
        AND COALESCE($reminderFamily,'')<>'gmail'
        AND CAST($time AS $integer)>CAST(? AS $integer) LIMIT 1");
    $recent->execute([$owner,(string)($now-2*3600)]);
    $fixedBefore=$recent->fetchColumn()===false?$now-$high:0;

    $reminders=$pdo->prepare("SELECT $original AS original_message_id,MAX(CAST($time AS $integer)) AS reminded_at,
        SUM(CASE WHEN $reminderFamily='gmail' THEN 1 ELSE 0 END) AS gmail_count,
        MAX(CASE WHEN $reminderFamily='gmail' THEN 0 ELSE
            CAST($time AS $integer)+CASE $priorPriority WHEN 'high' THEN $high WHEN 'low' THEN $low ELSE $normal END END) AS due_at
        FROM chat_messages m WHERE m.conversation_id=? AND m.role='assistant' AND m.user_id IS NULL
        AND $source='agent_initiative_reminder' AND ($reminderFamily='gmail' OR CAST($time AS $integer)>=CAST(? AS $integer))
        GROUP BY $original");
    $reminders->execute([$cid,(string)($now-$low)]);
    $prior=$reminders->fetchAll(PDO::FETCH_ASSOC);
    $reminded=array_column($prior,'reminded_at','original_message_id');
    $mailCounts=array_column($prior,'gmail_count','original_message_id');
    $mailTraffic=0;$mailBefore=0;
    if(!vp3_coordination_pending_v1($pdo,$user,$binding,chatOnly:true)){
        // Read a bounded set of recent turns. Periodic checks, drafts, and browser focus cannot change this count.
        $activity=$pdo->prepare("SELECT $time AS posted_at,m.role,m.user_id,$source AS post_source FROM chat_messages m
            WHERE m.conversation_id=? AND m.role IN ('user','assistant') ORDER BY m.id DESC LIMIT 18");
        $activity->execute([$cid]);
        foreach($activity->fetchAll(PDO::FETCH_ASSOC) as $turn){
            if((int)$turn['posted_at']<$now-86400||(int)$turn['posted_at']>$now)continue;
            if(($turn['role']==='user'&&(int)$turn['user_id']===$owner)
                ||($turn['role']==='assistant'&&$turn['user_id']===null&&in_array($turn['post_source'],
                    ['agent_initiative','agent_initiative_morning_brief','agent_initiative_reminder'],true)))$mailTraffic++;
        }
        $lastMail=0;
        foreach($prior as $row){
            if((int)$row['gmail_count']>0)$lastMail=max($lastMail,(int)$row['reminded_at']);
        }
        $earliest=vp3_coordination_gmail_reminder_delay_v1('normal',0,$mailTraffic);
        // Space separate mail reminders across this timeline even when a backlog becomes due together.
        if($lastMail<=0||$now-$lastMail>=intdiv($earliest,2))$mailBefore=$now-$earliest;
    }
    $postedBefore=max($fixedBefore,$mailBefore);
    if($postedBefore<1)return false;
    // Recently reminded rows need no new proof until their own cadence is due.
    $waiting=array_map('intval',array_column(array_filter($prior,static fn(array $row):bool=>(int)$row['due_at']>$now),'original_message_id'));
    $revision=$binding['revision']??null;
    $cursor=vp3_initiative_in_scope_v1($user,$agent,static fn():array=>
        agent_brain_v122_memory($user,'initiative_sweep',VP3_COORDINATION_REMINDER_CURSOR_V1)['metadata']??[]);
    $before=($cursor['conversation_id']??null)===$cid&&($cursor['revision']??null)===$revision
        &&is_int($cursor['before']??null)&&$cursor['before']>0?$cursor['before']:0;
    try{
        $window=vp3_top_of_mind_open_engagement_candidates_v1($pdo,$owner,$agent,$cid,before:$before,
            postedBefore:$postedBefore,excludedMessages:$waiting,includeMoreFamilies:false);
    }catch(RuntimeException $error){
        if($error instanceof PDOException||!$pdo->inTransaction())throw $error;
        vp3_initiative_health_diagnostic_v1($error,'reminder_candidates_read',$owner,$agent,$cid);
        return false;
    }
    $next=$window['next_before']??0;
    // Advance even if no item verifies. Newer messages cannot crowd the rest of this sweep.
    if($next!==$before){
        vp3_initiative_in_scope_v1($user,$agent,static fn()=>agent_brain_v122_upsert_system_memory(
            $user,'initiative_sweep',VP3_COORDINATION_REMINDER_CURSOR_V1,'Reminder candidate scan position.',
            ['conversation_id'=>$cid,'revision'=>$revision,'before'=>$next]));
    }

    $due=[];$settings=null;$mediaGate=null;
    $casualFamilies=vp3_top_of_mind_engagement_casual_specialists_v1();
    foreach($window['rows'] as $candidate){
        $id=(int)$candidate['id'];$family=$candidate['specialist'];$posted=(int)$candidate['posted_at'];
        if($posted<1||$family==='curiosity'
            ||$posted>($family==='gmail'?$mailBefore:$fixedBefore)
            ||($family==='media'&&($mediaGate??=vp3_media_offer_gate_v1($pdo,$user,$cid))!=='ready'))continue;
        try{$engagement=vp3_top_of_mind_open_engagement_v1($pdo,$user,$principal,$cid,$id,$family,$casualFamilies);}
        catch(JsonException){continue;}
        catch(Throwable $error){
            if($error instanceof PDOException&&(!$pdo->inTransaction()||in_array((int)($error->errorInfo[1]??0),[1020,1205,1213],true)
                ||(string)$error->getCode()==='40001'))throw $error;
            if(!($error instanceof RuntimeException)||!in_array($error->getCode(),[403,404,409],true)){
                vp3_initiative_health_diagnostic_v1($error,'reminder_source_read',$owner,$agent,$cid);
            }
            continue;
        }
        if($engagement===null)continue;
        $loaded=$engagement['loaded'];
        $goal=$loaded['goal'];$progress=$loaded['state'];
        $casual=($goal['coordination_route']['specialist']??null)==='casual';
        $mediaInvitation=$family==='media'&&in_array($goal['primary']??null,['casual:media','casual:music','casual:youtube','casual:youtube_history'],true);
        if(($goal['binding']??null)!==$binding||!vp3_coordination_reminder_open_v1($progress)
            ||!empty($progress['coordination_source_changed'])
            ||($casual&&!$mediaInvitation))continue;
        $primary=$loaded['evidence'][$goal['primary']??'']??[];
        // Historical recording questions stay replyable in Top of Mind.
        if($family==='transcripts'&&empty($primary['transcript_recent']))continue;
        $priority=$primary['priority']??$goal['coordination_priority']??'normal';
        if(!in_array($priority,['high','normal','low'],true))$priority='normal';
        if($family==='gmail'&&$priority==='high')continue;
        // A changed rule must never keep an already-posted question in the repeat path.
        if(($goal['tool']??null)==='coordination.review'){
            try{vp3_coordination_route_current_v1($pdo,$user,$goal,$loaded['evidence']);}
            catch(RuntimeException $error){
                if($error instanceof PDOException||!in_array($error->getCode(),[403,404,409],true))throw $error;
                continue;
            }
        }elseif($family==='tasks'){
            $settings??=vp3_coordination_rules_v1($pdo,$user,$agent);
            $route=vp3_coordination_route_v1($settings,'tasks',['waiting_owner']);
            if($route['action']!=='ask')continue;
        }
        $last=(int)($reminded[$id]??0);
        $lastState=$progress['state']==='discussed'?(strtotime($progress['updated_at']??'')?:0):0;
        $lastShown=max($posted,$last,$lastState);
        $interval=$family==='gmail'?vp3_coordination_gmail_reminder_delay_v1($priority,(int)($mailCounts[$id]??0),$mailTraffic)
            :vp3_coordination_reminder_interval_v1($priority);
        $dueAt=$lastShown+$interval;
        if($dueAt>$now)continue;
        $due[]=['id'=>$id,'family'=>$family,'title'=>$engagement['title'],'priority'=>$priority,'due_at'=>$dueAt,'last_shown'=>$lastShown];
    }
    if(!$due)return false;
    usort($due,static fn(array $a,array $b):int=>
        $a['due_at']<=>$b['due_at']?:$a['last_shown']<=>$b['last_shown']?:vp3_coordination_reminder_interval_v1($a['priority'])
            <=>vp3_coordination_reminder_interval_v1($b['priority'])?:$a['id']<=>$b['id']);
    $item=$due[0];
    $label=match($item['family']){
        'gmail'=>'Gmail','planning'=>'Planning','tasks'=>'Tasks & Lists','media'=>'Media & Entertainment',
        'canvas'=>'Canvas Apps','coding'=>'Coding','research'=>'Research','image-generator'=>'Image Generator',
        'mission'=>'Missions','connected-sites'=>'Connected Sites','transcripts'=>'Transcriptions',default=>'Top of Mind',
    };
    $title=vp3_top_of_mind_text_v1($item['title'],120);
    $message=$label.' still has a question'.($title!==''?' about “'.$title.'”':'').'.';
    $context=['source'=>'agent_initiative_reminder','skip_brain_archive'=>true,
        'initiative_reminder'=>['message_id'=>$item['id'],'specialist'=>$item['family'],'priority'=>$item['priority']],
        'actions'=>[['type'=>'open_url','label'=>'Open original question',
            'url'=>vp3_top_of_mind_media_href_v1($agent,$cid,$item['id'])]]];
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,NULL,'assistant',?,?,NOW())")->execute([$cid,$message,json_encode($context,JSON_THROW_ON_ERROR)]);
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,$owner]);
    return true;
}
