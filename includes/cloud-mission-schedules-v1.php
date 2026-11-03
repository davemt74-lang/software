<?php
declare(strict_types=1);
require_once __DIR__.'/cloud-missions-v1.php';

/** Persistent reviewed plans. Execution, leases and receipts stay in the canonical job engine. */
function cloud_mission_schedules_schema_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_schedules_v1 (
        schedule_id CHAR(36) NOT NULL PRIMARY KEY,owner_user_id BIGINT NOT NULL,template_mission_id CHAR(36) NOT NULL,
        agent_id BIGINT NOT NULL,objective TEXT NOT NULL,config_json TEXT NOT NULL,grant_hash CHAR(64) NOT NULL,
        timing_json TEXT NOT NULL,folder_id BIGINT NOT NULL,status VARCHAR(16) NOT NULL,revision INT NOT NULL DEFAULT 1,
        next_run_at VARCHAR(32) NOT NULL,last_run_at VARCHAR(32) NOT NULL DEFAULT '',last_error VARCHAR(500) NOT NULL DEFAULT '',
        created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_schedule_runs_v1 (
        schedule_id CHAR(36) NOT NULL,owner_user_id BIGINT NOT NULL,due_at VARCHAR(32) NOT NULL,mission_id CHAR(36) NOT NULL,
        grant_hash CHAR(64) NOT NULL,report_state VARCHAR(16) NOT NULL DEFAULT 'pending',knowledge_id BIGINT NOT NULL DEFAULT 0,
        evidence_hash CHAR(64) NOT NULL DEFAULT '',evidence_changed INT NOT NULL DEFAULT -1,last_error VARCHAR(500) NOT NULL DEFAULT '',
        created_at VARCHAR(32) NOT NULL,PRIMARY KEY(schedule_id,due_at),UNIQUE(mission_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_schedule_ops_v1 (
        owner_user_id BIGINT NOT NULL,request_id CHAR(36) NOT NULL,request_hash CHAR(64) NOT NULL,schedule_id CHAR(36) NOT NULL,
        PRIMARY KEY(owner_user_id,request_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_scheduler_v1 (
        owner_user_id BIGINT NOT NULL PRIMARY KEY,last_tick_at VARCHAR(32) NOT NULL,last_success_at VARCHAR(32) NOT NULL DEFAULT '',
        background_at VARCHAR(32) NOT NULL DEFAULT '')");
    foreach([
        ['agent_cloud_mission_schedules_v1','idx_cloud_mission_schedule_due','owner_user_id,status,next_run_at'],
        ['agent_cloud_mission_schedule_runs_v1','idx_cloud_mission_report_due','owner_user_id,report_state,due_at'],
    ] as [$table,$name,$columns]){
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
        elseif(!agent_job_engine_index_exists_v1900($pdo,$table,$name))$pdo->exec("CREATE INDEX $name ON $table ($columns)");
    }
}
function cloud_mission_schedule_now_v1(?DateTimeImmutable $now=null): string
{
    return ($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function cloud_mission_schedule_timing_v1(mixed $timing): array
{
    if(!is_array($timing)||count($timing)!==5||!in_array($timing['frequency']??null,['daily','weekly'],true))throw new InvalidArgumentException('Choose a daily or weekly Mission schedule.');
    foreach(['weekday'=>6,'hour'=>23,'minute'=>59] as $field=>$max)if(!is_int($timing[$field]??null)||$timing[$field]<0||$timing[$field]>$max)throw new InvalidArgumentException('Schedule time is invalid.');
    if(!is_string($timing['timezone']??null)||!in_array($timing['timezone'],DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))throw new InvalidArgumentException('Choose a valid schedule timezone.');
    return array_intersect_key($timing,array_flip(['frequency','weekday','hour','minute','timezone']));
}
function cloud_mission_schedule_next_v1(array $timing,string $after): string
{
    $zone=new DateTimeZone($timing['timezone']);$utc=new DateTimeZone('UTC');$at=new DateTimeImmutable($after,$utc);
    $local=$at->setTimezone($zone);
    for($days=0;$days<9;$days++){
        $date=$local->modify('+'.$days.' days');
        if($timing['frequency']==='weekly'&&(int)$date->format('N')-1!==$timing['weekday'])continue;
        // PHP moves a nonexistent spring-forward wall time forward into the valid hour.
        $candidate=new DateTimeImmutable($date->format('Y-m-d').' '.sprintf('%02d:%02d:00',$timing['hour'],$timing['minute']),$zone);
        if($candidate>$at)return $candidate->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    throw new RuntimeException('The next Mission time could not be calculated.');
}
function cloud_mission_schedule_row_v1(PDO $pdo,array $user,string $id,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_schedules_v1 WHERE schedule_id=? AND owner_user_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$id,(int)$user['id']]);$s=$q->fetch(PDO::FETCH_ASSOC);
    if(!$s)throw new RuntimeException('Cloud Mission schedule not found.',404);return $s;
}
function cloud_mission_schedule_permission_v1(array $user): void
{
    if(!has_permission('chat.access',$user)||!personal_knowledge_available($user)||!personal_capability_has_v242('personal_knowledge.manage',$user))throw new RuntimeException('Cloud Missions need current Chat and My Knowledge management access.',403);
}
function cloud_mission_schedule_plan_v1(array $config): array
{
    return array_map(static fn($a)=>['tools'=>$a['tools'],'max_calls'=>$a['max_calls'],'actions'=>[],'max_actions'=>0,'output'=>$a['output'],'browser_url'=>''],$config['assignments']);
}
function cloud_mission_schedule_link_v1(PDO $pdo,string $missionId): ?array
{
    if(!table_exists('agent_cloud_mission_schedule_runs_v1'))return null;
    $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_schedule_runs_v1 WHERE mission_id=?');$q->execute([$missionId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
/** A scheduled run inherits the exact durable read/save grant, never an arbitrary extended review timestamp. */
function cloud_mission_schedule_authority_v1(PDO $pdo,array $user,array $m): bool
{
    $link=cloud_mission_schedule_link_v1($pdo,$m['mission_id']);if(!$link)return false;
    cloud_mission_schedule_permission_v1($user);$s=cloud_mission_schedule_row_v1($pdo,$user,$link['schedule_id']);
    $config=json_decode($m['config_json'],true,16,JSON_THROW_ON_ERROR);
    $run=cloud_mission_run_v1($pdo,$user,$m);
    if(!hash_equals($s['grant_hash'],$link['grant_hash'])||(int)$s['agent_id']!==(int)$m['agent_id']||$run['goal']!==$s['objective']||cloud_mission_schedule_plan_v1($config)!==json_decode($s['config_json'],true,16,JSON_THROW_ON_ERROR))throw new RuntimeException('The recurring Mission grant no longer matches this run.',409);
    if(!personal_knowledge_folder($pdo,$user,(int)$s['folder_id']))throw new RuntimeException('The Mission Knowledge folder was removed. Create a schedule with a current folder.',409);
    $template=cloud_mission_row_v1($pdo,$user,$s['template_mission_id']);
    cloud_mission_authority_v1($pdo,$user,$template); // includes shared-screen evidence correction/deletion checks
    return true;
}
function cloud_mission_schedule_create_v1(PDO $pdo,array $user,array $input,string $now): string
{
    cloud_mission_schedule_permission_v1($user);
    if(($input['save_reports']??false)!==true||!is_int($input['folder_id']??null)||$input['folder_id']<0)throw new InvalidArgumentException('Review the My Knowledge report destination.');
    $timing=cloud_mission_schedule_timing_v1($input['timing']??null);
    $m=cloud_mission_row_v1($pdo,$user,(string)($input['mission_id']??''),true);
    if(cloud_mission_schedule_link_v1($pdo,$m['mission_id']))throw new RuntimeException('Schedule the original reviewed plan, not a generated run.',409);
    cloud_mission_authority_v1($pdo,$user,$m,true);
    if((int)$m['revision']<1||($input['expected_revision']??null)!==(int)$m['revision'])throw new RuntimeException('Review the current Mission assignments before scheduling.',409);
    $run=cloud_mission_run_v1($pdo,$user,$m);$config=json_decode($m['config_json'],true,16,JSON_THROW_ON_ERROR);$plan=cloud_mission_schedule_plan_v1($config);
    if(!array_filter($plan,fn($a)=>!empty($a['tools'])))throw new RuntimeException('Select at least one Knowledge or Brain read before scheduling collection.',409);
    foreach($plan as $a){if(in_array('knowledge.search',$a['tools'],true)&&!personal_knowledge_available($user))throw new RuntimeException('Knowledge access changed.',403);if(in_array('brain.recall',$a['tools'],true)&&!personal_capability_has_v242('agent_brain.access',$user))throw new RuntimeException('Brain access changed.',403);}
    $q=$pdo->prepare("SELECT COUNT(*) FROM agent_cloud_mission_schedules_v1 WHERE owner_user_id=? AND status IN ('active','paused')");$q->execute([(int)$user['id']]);if((int)$q->fetchColumn()>=20)throw new RuntimeException('Cancel an unneeded Cloud schedule before adding another.',409);
    $id=cloud_mission_uuid_v1('schedule:'.$user['id'].':'.$input['request_id']);
    $fid=$input['folder_id'];
    if($fid===0){$folder=personal_knowledge_create_folder($pdo,$user,'Mission · '.mb_substr($run['goal'],0,35).' · '.substr($id,-8));$fid=(int)$folder['id'];}
    elseif(!personal_knowledge_folder($pdo,$user,$fid))throw new RuntimeException('Choose a folder from your own My Knowledge library.',403);
    $json=json_encode($plan,JSON_THROW_ON_ERROR);$hash=hash('sha256',json_encode([$m['mission_id'],$m['agent_id'],$run['goal'],$plan,$fid],JSON_THROW_ON_ERROR));
    $pdo->prepare('INSERT INTO agent_cloud_mission_schedules_v1(schedule_id,owner_user_id,template_mission_id,agent_id,objective,config_json,grant_hash,timing_json,folder_id,status,next_run_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,(int)$user['id'],$m['mission_id'],(int)$m['agent_id'],$run['goal'],$json,$hash,json_encode($timing,JSON_THROW_ON_ERROR),$fid,'active',cloud_mission_schedule_next_v1($timing,$now),$now,$now]);
    agent_workflow_event_v1400($pdo,(int)$user['id'],(int)$run['id'],'mission_schedule_created',$run['status'],$run['status'],'user','Recurring Cloud reads and private Knowledge reports explicitly approved.',['schedule_id'=>$id]);
    return $id;
}
function cloud_mission_schedule_mutate_v1(PDO $pdo,array $user,string $action,array $input,?DateTimeImmutable $clock=null): array
{
    $rid=$input['request_id']??null;
    if(!is_string($rid)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$rid)||($input['confirmed']??false)!==true)throw new InvalidArgumentException('Review and confirm this schedule operation.');
    $hash=hash('sha256',json_encode([$action,$input['mission_id']??null,$input['schedule_id']??null,$input['expected_revision']??null,$input['timing']??null,$input['folder_id']??null,$input['save_reports']??null],JSON_THROW_ON_ERROR));$now=cloud_mission_schedule_now_v1($clock);
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_schedule_ops_v1 WHERE owner_user_id=? AND request_id=?');$q->execute([(int)$user['id'],$rid]);$op=$q->fetch(PDO::FETCH_ASSOC);
        if($op){if(!hash_equals($op['request_hash'],$hash))throw new RuntimeException('This request ID belongs to another schedule operation.',409);$pdo->commit();return cloud_mission_schedule_list_v1($pdo,$user);}
        if($action==='schedule.create')$id=cloud_mission_schedule_create_v1($pdo,$user,$input,$now);
        else{
            $id=(string)($input['schedule_id']??'');$s=cloud_mission_schedule_row_v1($pdo,$user,$id,true);
            if(($input['expected_revision']??null)!==(int)$s['revision'])throw new RuntimeException('The schedule changed. Refresh before reviewing it.',409);
            $next=$s['next_run_at'];
            if($action==='schedule.pause'&&$s['status']==='active')$state='paused';
            elseif($action==='schedule.cancel'&&$s['status']!=='cancelled')$state='cancelled';
            elseif($action==='schedule.resume'&&$s['status']==='paused'){
                cloud_mission_schedule_permission_v1($user);cloud_mission_authority_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$s['template_mission_id']));
                if(!personal_knowledge_folder($pdo,$user,(int)$s['folder_id']))throw new RuntimeException('The report folder was removed. Create a new schedule.',409);
                $state='active';$next=cloud_mission_schedule_next_v1(json_decode($s['timing_json'],true),$now);
            }else throw new RuntimeException('This schedule operation is unavailable in its current state.',409);
            $pdo->prepare('UPDATE agent_cloud_mission_schedules_v1 SET status=?,revision=revision+1,next_run_at=?,last_error=?,updated_at=? WHERE schedule_id=? AND owner_user_id=?')->execute([$state,$next,'',$now,$id,(int)$user['id']]);
        }
        $pdo->prepare('INSERT INTO agent_cloud_mission_schedule_ops_v1(owner_user_id,request_id,request_hash,schedule_id) VALUES(?,?,?,?)')->execute([(int)$user['id'],$rid,$hash,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return cloud_mission_schedule_list_v1($pdo,$user);
}
function cloud_mission_schedule_health_v1(PDO $pdo,?DateTimeImmutable $clock=null): array
{
    $q=$pdo->query('SELECT * FROM agent_cloud_mission_scheduler_v1 WHERE owner_user_id=0');$h=$q->fetch(PDO::FETCH_ASSOC)?:[];$now=cloud_mission_schedule_now_v1($clock);
    $live=!empty($h['background_at'])&&strtotime($now)-strtotime($h['background_at'])<180;
    return ['state'=>$live?'healthy':'browser_assisted','execution_target'=>'cloud','checked_at'=>$now,'last_tick_at'=>$h['last_tick_at']??'','last_success_at'=>$h['last_success_at']??'','background_at'=>$h['background_at']??'','background_ready'=>$live];
}
function cloud_mission_schedule_list_v1(PDO $pdo,array $user): array
{
    $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_schedules_v1 WHERE owner_user_id=? ORDER BY created_at DESC,schedule_id DESC LIMIT 50');$q->execute([(int)$user['id']]);$items=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $s){
        $allowed=true;try{cloud_mission_schedule_permission_v1($user);cloud_mission_authority_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$s['template_mission_id']));}catch(Throwable $e){$allowed=false;}
        $runs=[];$r=$pdo->prepare('SELECT * FROM agent_cloud_mission_schedule_runs_v1 WHERE schedule_id=? AND owner_user_id=? ORDER BY due_at DESC LIMIT 8');$r->execute([$s['schedule_id'],(int)$user['id']]);
        foreach($r->fetchAll(PDO::FETCH_ASSOC) as $link){$m=cloud_mission_view_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$link['mission_id']));$runs[]=['mission_id'=>$link['mission_id'],'due_at'=>$link['due_at'],'status'=>$m['status'],'mission_status'=>$m['status'],'created_at'=>$link['created_at'],'completed_at'=>in_array($m['status'],['completed','failed','cancelled'],true)?$m['updated_at']:'','needs_review'=>in_array($m['status'],['failed','waiting_review'],true)||$link['report_state']==='blocked','reason'=>$allowed?$link['last_error']:'Source access changed','report_state'=>$link['report_state'],'knowledge_id'=>$m['authority_current']?(int)$link['knowledge_id']:0,'evidence_changed'=>(int)$link['evidence_changed']];}
        $folder=$allowed?personal_knowledge_folder($pdo,$user,(int)$s['folder_id']):null;
        $items[]=['id'=>$s['schedule_id'],'execution_target'=>'cloud','objective'=>$allowed?$s['objective']:'Cloud Mission · access changed','status'=>$s['status'],'revision'=>(int)$s['revision'],'next_run_at'=>$s['status']==='active'?$s['next_run_at']:'','last_run_at'=>$s['last_run_at'],'last_error'=>$allowed?$s['last_error']:'Access changed. Restore permission or cancel future runs.','updated_at'=>$s['updated_at'],'folder_id'=>$folder?(int)$folder['id']:0,'folder_name'=>$folder['folder_name']??'Unavailable','runs'=>$runs]+json_decode($s['timing_json'],true);
    }
    return ['ok'=>true,'contract'=>'vp3.agent-missions.cloud.v1','schedules'=>$items,'cloud_scheduler_health'=>cloud_mission_schedule_health_v1($pdo),'knowledge_folders'=>personal_knowledge_available($user)?personal_knowledge_folders($pdo,$user):[]];
}
function cloud_mission_schedule_pause_error_v1(PDO $pdo,array $user,string $id,string $message): void
{
    $pdo->prepare("UPDATE agent_cloud_mission_schedules_v1 SET status='paused',revision=revision+1,last_error=?,updated_at=? WHERE schedule_id=? AND owner_user_id=? AND status='active'")->execute([mb_substr($message,0,500),cloud_mission_schedule_now_v1(),$id,(int)$user['id']]);
}
/** Save once, transactionally with the canonical Knowledge note and its search index. */
function cloud_mission_schedule_sync_v1(PDO $pdo,array $user,string $missionId): void
{
    $link=cloud_mission_schedule_link_v1($pdo,$missionId);if(!$link||in_array($link['report_state'],['saved','failed'],true))return;
    $m=cloud_mission_row_v1($pdo,$user,$missionId);$run=cloud_mission_run_v1($pdo,$user,$m);
    if(!in_array($run['status'],['completed','failed','cancelled'],true))return;
    if($run['status']!=='completed'){
        $pdo->prepare("UPDATE agent_cloud_mission_schedule_runs_v1 SET report_state='failed',last_error=? WHERE mission_id=? AND owner_user_id=?")->execute(['Run '.$run['status'].'. Inspect its receipts before resuming collection.',$missionId,(int)$user['id']]);
        cloud_mission_schedule_pause_error_v1($pdo,$user,$link['schedule_id'],'The last run '.$run['status'].'. Inspect it before resuming future runs.');return;
    }
    try{
        $pdo->beginTransaction();$s=cloud_mission_schedule_row_v1($pdo,$user,$link['schedule_id'],true);
        $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_schedule_runs_v1 WHERE mission_id=? AND owner_user_id=? FOR UPDATE');$q->execute([$missionId,(int)$user['id']]);$link=$q->fetch(PDO::FETCH_ASSOC);
        if($link['report_state']==='saved'){$pdo->commit();return;}
        cloud_mission_schedule_authority_v1($pdo,$user,$m);$view=cloud_mission_view_v1($pdo,$user,$m);if(!$view['authority_current'])throw new RuntimeException('Source access changed. The report has not been saved.',403);
        $hashes=[];$refs=[];
        foreach(agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']) as $a){$result=json_decode((string)$a['result_json'],true)?:[];cloud_mission_result_authority_v1($pdo,$user,$m,$result);$hashes[]=$result['evidence_hash']??'';foreach(array_filter(explode(',',(string)($result['source_refs']??''))) as $ref)$refs[]=$ref;}
        $evidence=hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR));$q=$pdo->prepare("SELECT evidence_hash FROM agent_cloud_mission_schedule_runs_v1 WHERE schedule_id=? AND owner_user_id=? AND due_at<? AND report_state='saved' ORDER BY due_at DESC LIMIT 1");$q->execute([$s['schedule_id'],(int)$user['id'],$link['due_at']]);$prior=$q->fetchColumn();$changed=$prior===false?-1:(hash_equals($prior,$evidence)?0:1);
        $comparison=$changed<0?'First saved run; comparison baseline.':($changed?'Retrieved source evidence changed since the last saved run.':'Retrieved source evidence is unchanged since the last saved run.');
        $body="Cloud Mission report · model-generated draft\nObjective: ".$s['objective']."\nScheduled: ".$link['due_at']." UTC\nRun: ".$missionId."\n".$comparison."\nThis compares the retrieved evidence, not every possible source.\nSources: ".implode(', ',array_unique($refs))."\n\n".$view['result'];
        $kid=personal_knowledge_store($user,'cloud-mission-report:'.$missionId,mb_substr($s['objective'],0,120).' · '.$link['due_at'],$body,'Recurring Cloud Mission · '.$s['schedule_id'],(int)$s['folder_id']);
        $pdo->prepare("UPDATE agent_cloud_mission_schedule_runs_v1 SET report_state='saved',knowledge_id=?,evidence_hash=?,evidence_changed=?,last_error='' WHERE mission_id=? AND owner_user_id=?")->execute([$kid,$evidence,$changed,$missionId,(int)$user['id']]);
        agent_workflow_event_v1400($pdo,(int)$user['id'],(int)$run['id'],'mission_report_saved','completed','completed','scheduler','Private Mission report saved in My Knowledge.',['knowledge_id'=>$kid,'schedule_id'=>$s['schedule_id']]);$pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();$message=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Report storage is unavailable. Inspect this run before resuming.';
        $pdo->prepare("UPDATE agent_cloud_mission_schedule_runs_v1 SET report_state='blocked',last_error=? WHERE mission_id=? AND owner_user_id=?")->execute([mb_substr($message,0,500),$missionId,(int)$user['id']]);cloud_mission_schedule_pause_error_v1($pdo,$user,$link['schedule_id'],$message);
    }
}
function cloud_mission_schedule_prior_v1(PDO $pdo,array $user,array $m): array
{
    $link=cloud_mission_schedule_link_v1($pdo,$m['mission_id']);if(!$link)return [];
    $q=$pdo->prepare("SELECT mission_id FROM agent_cloud_mission_schedule_runs_v1 WHERE schedule_id=? AND owner_user_id=? AND due_at<? AND report_state='saved' ORDER BY due_at DESC LIMIT 1");$q->execute([$link['schedule_id'],(int)$user['id'],$link['due_at']]);$id=$q->fetchColumn();if(!$id)return [];
    $previous=cloud_mission_row_v1($pdo,$user,$id);$run=cloud_mission_run_v1($pdo,$user,$previous);$out=[];$refs=[];
    foreach(agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']) as $a){$r=json_decode((string)($a['result_json']??''),true)?:[];cloud_mission_result_authority_v1($pdo,$user,$previous,$r);if(!empty($r['mission_output']))$out=['source'=>'previous-mission:'.$id,'title'=>'Previous completed run — compare with current evidence; do not treat prior conclusions as new evidence','text'=>$r['mission_output']];foreach(array_filter(explode(',',(string)($r['source_refs']??''))) as $ref)$refs[]=$ref;}
    return ['context'=>$out,'refs'=>array_values(array_unique($refs))];
}
/** One owner/slot is serialized in MySQL. Advancing the slot and creating its canonical job are atomic. */
function cloud_mission_schedule_tick_v1(PDO $pdo,array $user,?DateTimeImmutable $clock=null): array
{
    $now=cloud_mission_schedule_now_v1($clock);$created=0;$skipped=0;
    $q=$pdo->prepare("SELECT mission_id FROM agent_cloud_mission_schedule_runs_v1 WHERE owner_user_id=? AND report_state IN ('pending','blocked') ORDER BY due_at LIMIT 40");$q->execute([(int)$user['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)cloud_mission_schedule_sync_v1($pdo,$user,$id);
    $q=$pdo->prepare("SELECT schedule_id FROM agent_cloud_mission_schedules_v1 WHERE owner_user_id=? AND status='active' AND next_run_at<=? ORDER BY next_run_at LIMIT 5");$q->execute([(int)$user['id'],$now]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        try{
            $pdo->beginTransaction();$s=cloud_mission_schedule_row_v1($pdo,$user,$id,true);if($s['status']!=='active'||$s['next_run_at']>$now){$pdo->commit();continue;}
            cloud_mission_schedule_permission_v1($user);$template=cloud_mission_row_v1($pdo,$user,$s['template_mission_id']);cloud_mission_authority_v1($pdo,$user,$template);
            if(!personal_knowledge_folder($pdo,$user,(int)$s['folder_id']))throw new RuntimeException('The report folder was removed. Create a new schedule.',409);
            $q=$pdo->prepare("SELECT COUNT(*) FROM agent_cloud_mission_schedule_runs_v1 l INNER JOIN agent_cloud_missions_v1 m ON m.mission_id=l.mission_id AND m.owner_user_id=l.owner_user_id INNER JOIN agent_workflow_runs r ON r.id=m.run_id AND r.owner_user_id=m.owner_user_id WHERE l.schedule_id=? AND l.owner_user_id=? AND (r.status NOT IN ('completed','failed','cancelled') OR l.report_state IN ('pending','blocked'))");$q->execute([$id,(int)$user['id']]);
            if((int)$q->fetchColumn()>0){$skipped++;$pdo->prepare('UPDATE agent_cloud_mission_schedules_v1 SET next_run_at=?,last_error=?,updated_at=? WHERE schedule_id=? AND owner_user_id=?')->execute([cloud_mission_schedule_next_v1(json_decode($s['timing_json'],true),$now),'Previous run or report still needs attention. This slot was skipped.',$now,$id,(int)$user['id']]);$pdo->commit();continue;}
            $slot=$s['next_run_at'];$config=json_decode($template['config_json'],true,16,JSON_THROW_ON_ERROR);
            $m=cloud_mission_prepare_v1($pdo,$user,['objective'=>$s['objective'],'thread_id'=>$config['thread_id']??0,'agent_id'=>(int)$s['agent_id'],'request_id'=>'scheduled:'.$id.':'.strtotime($slot)]);
            $plan=json_decode($s['config_json'],true,16,JSON_THROW_ON_ERROR);foreach($plan as $i=>&$a)$a=['task_id'=>$m['tasks'][$i]['id']]+$a;unset($a);
            $pdo->prepare('UPDATE agent_cloud_missions_v1 SET config_json=?,revision=1,reviewed_at=?,review_until=? WHERE mission_id=? AND owner_user_id=?')->execute([json_encode(['thread_id'=>$config['thread_id']??0,'max_parallel'=>1,'assignments'=>$plan],JSON_THROW_ON_ERROR),$now,$now,$m['id'],(int)$user['id']]);
            $pdo->prepare('INSERT INTO agent_cloud_mission_schedule_runs_v1(schedule_id,owner_user_id,due_at,mission_id,grant_hash,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,(int)$user['id'],$slot,$m['id'],$s['grant_hash'],$now]);
            $canonical=cloud_mission_row_v1($pdo,$user,$m['id']);cloud_mission_schedule_authority_v1($pdo,$user,$canonical);agent_workflow_approve_v1400($pdo,$user,(int)$canonical['run_id'],'scheduler');
            $pdo->prepare("UPDATE agent_cloud_mission_schedules_v1 SET next_run_at=?,last_run_at=?,last_error='',updated_at=? WHERE schedule_id=? AND owner_user_id=?")->execute([cloud_mission_schedule_next_v1(json_decode($s['timing_json'],true),$now),$now,$now,$id,(int)$user['id']]);$pdo->commit();$created++;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();cloud_mission_schedule_pause_error_v1($pdo,$user,$id,$e instanceof RuntimeException?$e->getMessage():'Cloud scheduling is unavailable. Review this Mission before resuming.');}
    }
    return ['ok'=>true,'created'=>$created,'skipped'=>$skipped];
}
function cloud_mission_schedule_background_v1(PDO $pdo): void
{
    $now=cloud_mission_schedule_now_v1();$pdo->prepare("INSERT IGNORE INTO agent_cloud_mission_scheduler_v1(owner_user_id,last_tick_at,last_success_at,background_at) VALUES(0,'','','')")->execute();$pdo->prepare('UPDATE agent_cloud_mission_scheduler_v1 SET last_tick_at=?,last_success_at=?,background_at=? WHERE owner_user_id=0')->execute([$now,$now,$now]);
}
function cloud_mission_schedule_owner_ids_v1(PDO $pdo,int $limit=50): array
{
    $q=$pdo->prepare("SELECT owner_user_id FROM agent_cloud_mission_schedules_v1 WHERE status='active' AND next_run_at<=? UNION SELECT owner_user_id FROM agent_cloud_mission_schedule_runs_v1 WHERE report_state IN ('pending','blocked') LIMIT ".max(1,min(250,$limit)));$q->execute([cloud_mission_schedule_now_v1()]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
