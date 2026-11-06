<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-followup-origin-v1.php';

function vp3_initiative_followups_ready_v1(PDO $pdo): bool
{
    return vp3_initiative_followup_action_ready_v1($pdo)&&agent_job_engine_schema_ready_v1900($pdo);
}

/** Called with the canonical owner/reminder/occurrence locks, never inference.
 * One occurrence converges on the existing workflow ledger across all tickers. */
function vp3_initiative_followup_due_v1(PDO $pdo,array $reminder,array $occurrence): bool
{
    if(!$pdo->inTransaction()||!vp3_initiative_followups_ready_v1($pdo))throw new RuntimeException('Follow-up runtime is unavailable.',503);
    if(empty($reminder['followup_action_id'])||$reminder['status']!=='active'||$occurrence['status']!=='pending'||$occurrence['message_id']!==null)return false;
    $uid=(int)$reminder['owner_user_id'];$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||empty($user['is_active']))throw new RuntimeException('Follow-up owner is unavailable.',403);
    $q=$pdo->prepare('SELECT r.agent_id FROM agent_workflow_actions a JOIN agent_workflow_runs r ON r.id=a.run_id AND r.owner_user_id=a.owner_user_id WHERE a.id=? AND a.owner_user_id=?');
    $q->execute([(int)$reminder['followup_action_id'],$uid]);$agent=$q->fetch(PDO::FETCH_ASSOC);
    if(!$agent)throw new RuntimeException('Follow-up creating action is unavailable.',409);
    $aid=(int)($agent['agent_id']??0);$principal=vp3_initiative_principal_v1($pdo,$user,$aid);
    $linked=vp3_initiative_followup_origin_v1($pdo,$user,$principal,(int)$reminder['id']);
    $fresh=vp3_initiative_followup_refresh_v1($pdo,$linked);$binding=$fresh['binding'];
    $scope=['user'=>$fresh['user'],'principal'=>$fresh['principal'],'agent_id'=>$aid,'conversation_id'=>$binding['conversation_id'],'verification_only'=>true];
    $ref='reminder:'.(int)$reminder['id'];$coverage=[];
    $evidence=vp3_initiative_capabilities_evidence_v1($pdo,$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),[$ref],$coverage);
    if(!isset($evidence[$ref]))throw new RuntimeException('Follow-up schedule is unavailable.',409);
    $followup=['version'=>1,'source_origin'=>$linked['source_origin'],'reminder_id'=>(int)$reminder['id'],'origin_action_id'=>(int)$reminder['followup_action_id'],
        'reminder_revision'=>(int)$reminder['revision'],'occurrence_id'=>(int)$occurrence['id'],
        'scheduled_at'=>(string)$occurrence['scheduled_at'],'wake_at'=>(string)$occurrence['wake_at'],
        'origin_hash'=>$fresh['origin_hash'],'context_hash'=>$fresh['context_hash']];
    $goal=['binding'=>$binding,'sources'=>[$ref=>$evidence[$ref]['revision']],'canonical_sources'=>[],
        'capability_sources'=>vp3_initiative_capability_source_map_v1($evidence,[$ref]),'tool'=>'work.prepare_private',
        'primary'=>$ref,'focus'=>$fresh['focus'],'report'=>true,'followup'=>$followup];
    $key=hash('sha256',json_encode(['proposal-revisit-v1',$binding,$followup],JSON_THROW_ON_ERROR));
    $queued=vp3_initiative_enqueue_v1($pdo,$fresh['user'],'initiative.prepare',$goal,$key);
    if(!$queued){
        $q=$pdo->prepare('SELECT status FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');$q->execute([$uid,hash('sha256','initiative.prepare|'.$key)]);
        if(in_array($q->fetchColumn(),['failed','cancelled'],true))throw new RuntimeException('The scheduled reassessment needs its existing workflow reviewed.',409);
    }
    return $queued;
}

/** Rechecked before inference, completion, delayed Chat delivery and voice.
 * A changed source means a new assessment, never delivery of an old draft. */
function vp3_initiative_followup_job_current_v1(PDO $pdo,array $user,array $goal,?int $runId=null): ?array
{
    if(!isset($goal['followup']))return null;$f=$goal['followup'];$binding=$goal['binding']??null;
    if(!is_array($f)||($f['version']??null)!==1||!is_array($binding)||!is_int($f['reminder_id']??null)||!is_int($f['occurrence_id']??null)
        ||!is_int($f['reminder_revision']??null)||!is_int($f['origin_action_id']??null))throw new RuntimeException('Follow-up job binding changed.',409);
    $aid=vp3_initiative_binding_agent_v1($binding);$principal=vp3_initiative_principal_v1($pdo,$user,$aid);
    $linked=vp3_initiative_followup_origin_v1($pdo,$user,$principal,$f['reminder_id']);$r=$linked['reminder'];
    if(($f['source_origin']??null)!==$linked['source_origin']||$r['recurrence']!=='none'||$r['delivery']!=='chat'||$r['status']!=='active'||(int)$r['revision']!==$f['reminder_revision']||(int)$r['followup_action_id']!==$f['origin_action_id']
        ||!hash_equals($linked['origin_hash'],(string)($f['origin_hash']??'')))throw new RuntimeException('Follow-up schedule changed.',409);
    $q=$pdo->prepare('SELECT * FROM agent_reminder_occurrences_v1 WHERE id=? AND reminder_id=? AND owner_user_id=?');
    $q->execute([$f['occurrence_id'],$f['reminder_id'],(int)$user['id']]);$o=$q->fetch(PDO::FETCH_ASSOC);
    if(!$o||$o['status']!=='pending'||$o['wake_at']!==$f['wake_at']||$o['scheduled_at']!==$f['scheduled_at'])throw new RuntimeException('Follow-up occurrence changed.',409);
    if($o['message_id']!==null){
        $q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=?');$q->execute([(int)$o['message_id'],$binding['conversation_id']]);$ctx=json_decode((string)$q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
        $deliveredRun=(int)($ctx['initiative_run_id']??0);$row=agent_workflow_row_v1400($pdo,(int)$user['id'],$deliveredRun,$pdo->inTransaction());
        if(!$row||$row['status']!=='completed'||($ctx['source']??null)!=='agent_initiative'||($runId!==null&&$runId!==$deliveredRun)
            ||json_decode((string)$row['goal'],true,64,JSON_THROW_ON_ERROR)!==$goal)throw new RuntimeException('Follow-up was already delivered elsewhere.',409);
    }
    $fresh=vp3_initiative_followup_refresh_v1($pdo,$linked);
    if($fresh['binding']!==$binding||!hash_equals($fresh['context_hash'],(string)($f['context_hash']??'')))throw new RuntimeException('Follow-up context changed.',409);
    return $fresh+['linked'=>$linked,'occurrence'=>$o];
}

/** One atomic natural Chat delivery owns the occurrence. Generic reminder
 * bubbles/notifications/speech never claim a linked occurrence. */
function vp3_initiative_followup_delivered_v1(PDO $pdo,array $user,array $goal,int $runId,int $messageId): void
{
    if(!isset($goal['followup']))return;
    if(!$pdo->inTransaction()||$messageId<1)throw new RuntimeException('Follow-up delivery requires the owner transaction.',409);
    $fresh=vp3_initiative_followup_job_current_v1($pdo,$user,$goal,$runId);$o=$fresh['occurrence'];
    if($o['message_id']!==null){if((int)$o['message_id']===$messageId)return;throw new RuntimeException('Follow-up already has its canonical Chat result.',409);}
    $q=$pdo->prepare('UPDATE agent_reminder_occurrences_v1 SET message_id=?,notified_at=?,presented_at=?,lease_token=?,lease_until=NULL WHERE id=? AND reminder_id=? AND owner_user_id=? AND status=? AND message_id IS NULL AND wake_at=?');
    $stamp=gmdate('Y-m-d\TH:i:s\Z');$q->execute([$messageId,$stamp,$stamp,'',$o['id'],$o['reminder_id'],(int)$user['id'],'pending',$o['wake_at']]);
    if($q->rowCount()!==1)throw new RuntimeException('Follow-up delivery changed.',409);
    agent_workflow_event_v1400($pdo,(int)$user['id'],$runId,'proposal_revisit_delivered','executing','executing','system','Scheduled reassessment reached its exact owner Chat.',
        ['reminder_id'=>(int)$o['reminder_id'],'occurrence_id'=>(int)$o['id'],'message_id'=>$messageId]);
}

function vp3_initiative_followup_worker_maintenance_v1(PDO $pdo,int $limit=50): array
{
    if(!reminder_ready_v1($pdo))return ['state'=>'unavailable','reason'=>'reminder_upgrade_required','processed'=>0];
    try{$processed=reminder_tick_v1($pdo,null,null,$limit);$counts=$GLOBALS['VP3_FOLLOWUP_TICK_V1']??[];return ['state'=>empty($counts['blocked'])?'ready':'limited','reason'=>empty($counts['blocked'])?'':'followup_sources_blocked','processed'=>$processed]+$counts;}
    catch(Throwable $error){return ['state'=>'unavailable','reason'=>'followup_tick_unavailable','processed'=>0];}
}
