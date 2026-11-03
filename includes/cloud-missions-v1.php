<?php
declare(strict_types=1);
require_once __DIR__.'/agent-worker-cloud-v1910.php';
require_once __DIR__.'/agent-work-control-v173.php';

/* Mission UUIDs and review metadata bind to the existing workflow ledger. No second queue. */
function cloud_mission_ready_v1(PDO $pdo): bool{return agent_job_engine_schema_ready_v1900($pdo)&&agent_job_control_ready_v1900($pdo);}
function cloud_mission_schema_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_missions_v1 (mission_id CHAR(36) NOT NULL PRIMARY KEY,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL DEFAULT 0,request_id VARCHAR(128) NOT NULL,request_hash CHAR(64) NOT NULL,run_id BIGINT NOT NULL,revision INT NOT NULL DEFAULT 0,config_json TEXT NOT NULL,reviewed_at VARCHAR(32) NOT NULL DEFAULT '',review_until VARCHAR(32) NOT NULL DEFAULT '',created_at VARCHAR(32) NOT NULL,UNIQUE(owner_user_id,request_id),UNIQUE(run_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_operations_v1 (owner_user_id BIGINT NOT NULL,request_id CHAR(36) NOT NULL,mission_id CHAR(36) NOT NULL,request_hash CHAR(64) NOT NULL,PRIMARY KEY(owner_user_id,request_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_reads_v1 (owner_user_id BIGINT NOT NULL,event_id BIGINT NOT NULL,read_at VARCHAR(32) NOT NULL,PRIMARY KEY(owner_user_id,event_id))");
}
function cloud_mission_uuid_v1(string $key): string{$h=hash('sha256',$key);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);}
function cloud_mission_row_v1(PDO $pdo,array $user,string $id,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM agent_cloud_missions_v1 WHERE mission_id=? AND owner_user_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id,(int)$user['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Cloud Mission not found.',404);return $r;
}
function cloud_mission_run_v1(PDO $pdo,array $user,array $m,bool $lock=false): array
{
    $r=agent_workflow_row_v1400($pdo,(int)$user['id'],(int)$m['run_id'],$lock);if(!$r||$r['source_kind']!=='cloud_mission'||$r['source_key']!==$m['mission_id']||$r['execution_target']!=='cloud')throw new RuntimeException('Cloud Mission job binding is unavailable.',409);return $r;
}
function cloud_mission_authority_v1(PDO $pdo,array $user,array $m,bool $activeReview=false): void
{
    if(!has_permission('chat.access',$user))throw new RuntimeException('Mission access changed.',403);
    if((int)$m['agent_id']>0){$a=user_agent_get_v236($pdo,(int)$user['id'],(int)$m['agent_id']);if(!$a||empty($a['is_active']))throw new RuntimeException('Mission Agent is unavailable.',403);}
    $scheduled=false;if(table_exists('agent_cloud_mission_schedule_runs_v1')){require_once __DIR__.'/cloud-mission-schedules-v1.php';$scheduled=cloud_mission_schedule_authority_v1($pdo,$user,$m);}
    if($activeReview&&!$scheduled&&($m['review_until']===''||strtotime($m['review_until'])<time()))throw new RuntimeException('Mission assignment review expired. Prepare a fresh Mission.',409);
    if(table_exists('agent_screen_handoffs_v1'))require_once __DIR__.'/agent-screen-handoffs-v1.php';
    if(function_exists('screen_handoff_guard_mission_v1'))screen_handoff_guard_mission_v1($pdo,$user,$m['mission_id']);
}
function cloud_mission_prepare_v1(PDO $pdo,array $user,array $input): array
{
    if(!cloud_mission_ready_v1($pdo))throw new RuntimeException('Cloud job engine needs its current database upgrade.',503);
    $objective=$input['objective']??null;$request=$input['request_id']??null;$cid=$input['thread_id']??0;$aid=(int)($input['agent_id']??0);
    if(!is_string($objective)||trim($objective)===''||mb_strlen($objective)>4000||!is_string($request)||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$request)||!is_int($cid)||$cid<0)throw new InvalidArgumentException('Use a Mission objective, stable request ID and current conversation.');
    if($cid>0){$resolved=vp3_agent_memory_scope_from_conversation_v410($user,$cid,false);if($aid>0&&$aid!==$resolved)throw new RuntimeException('Conversation Agent changed.',409);$aid=$resolved;}
    if($aid>0&&!user_agent_get_v236($pdo,(int)$user['id'],$aid))throw new RuntimeException('Mission Agent is unavailable.',403);
    $objective=trim($objective);$hash=hash('sha256',json_encode([$objective,$cid,$aid],JSON_THROW_ON_ERROR));$id=cloud_mission_uuid_v1('cloud-mission:'.$user['id'].':'.$request);$now=gmdate('Y-m-d H:i:s');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM agent_cloud_missions_v1 WHERE owner_user_id=? AND request_id=? FOR UPDATE');$q->execute([(int)$user['id'],$request]);$old=$q->fetch(PDO::FETCH_ASSOC);if($old){if(!hash_equals($old['request_hash'],$hash))throw new RuntimeException('This request ID was used for a different Mission.',409);if($ownsTransaction)$pdo->commit();return cloud_mission_view_v1($pdo,$user,$old);}
        $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,retry_backoff_seconds,timeout_seconds,current_action_id) VALUES(?,?,'cloud_mission','chat','cloud_mission',?,?,?,?,?,?,'approval_pending','low',1,'pending','cloud','missions.analyze',3,60,240,NULL)")->execute([(int)$user['id'],$aid?:null,$id,sha1($objective),hash('sha256','cloud-mission:'.$user['id'].':'.$request),mb_substr($objective,0,190),$objective,'Cloud analysis using explicitly reviewed read capabilities.']);$run=(int)$pdo->lastInsertId();
        $assignments=[];$q=$pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,max_attempts,timeout_seconds) VALUES(?,?,?,?,'execute',?,?,'approval_pending',1,'cloud','missions.analyze',3,240)");
        foreach(['Evidence analyst','Synthesis reviewer'] as $i=>$label){$tid=cloud_mission_uuid_v1($id.':task:'.$i);$q->execute([$run,(int)$user['id'],$i+1,$tid,$label,$i===0?'Analyze the reviewed source evidence for this objective.':'Review the prior analysis, identify uncertainty and produce the final answer.']);$assignments[]=['task_id'=>$tid,'tools'=>[],'max_calls'=>0,'actions'=>[],'max_actions'=>0,'output'=>'analysis','browser_url'=>''];}
        $config=['thread_id'=>$cid,'max_parallel'=>1,'assignments'=>$assignments];$pdo->prepare('INSERT INTO agent_cloud_missions_v1(mission_id,owner_user_id,agent_id,request_id,request_hash,run_id,config_json,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,(int)$user['id'],$aid,$request,$hash,$run,json_encode($config,JSON_THROW_ON_ERROR),$now]);
        agent_workflow_event_v1400($pdo,(int)$user['id'],$run,'mission_prepared','','approval_pending','user','Cloud Mission prepared for assignment review.',['mission_id'=>$id]);if($ownsTransaction)$pdo->commit();return cloud_mission_view_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$id));
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function cloud_mission_tools_v1(array $m): array
{
    $config=json_decode($m['config_json'],true,16,JSON_THROW_ON_ERROR);$active=$m['review_until']!==''&&strtotime($m['review_until'])>=time();
    return ['revision'=>(int)$m['revision'],'configured'=>(int)$m['revision']>0,'active'=>$active,'expires_at'=>$m['review_until'],'assignments'=>$config,'capabilities'=>['knowledge.search','brain.recall'],'action_capabilities'=>[],'max_calls_per_worker'=>3,'max_actions_per_worker'=>0,'max_parallel_limit'=>1,'browser_requirements'=>[],'execution_target'=>'cloud'];
}
function cloud_mission_result_authority_v1(PDO $pdo,array $user,array $m,array $result): void
{
    cloud_mission_authority_v1($pdo,$user,$m);$refs=explode(',',(string)($result['source_refs']??''));
    foreach(array_filter($refs) as $ref){[$kind,$id]=array_pad(explode(':',$ref,2),2,'');
        if($kind==='knowledge'){$k=get_knowledge_item((int)$id);if(!$k||!personal_knowledge_available($user)||($k['knowledge_scope']==='personal'?(int)$k['created_by_user_id']!==(int)$user['id']:(!has_permission('knowledge.access',$user)||empty($k['is_published'])||!knowledge_visibility_allowed((string)$k['visibility'],$user))))throw new RuntimeException('A Mission Knowledge source was removed or access changed.',403);}
        elseif($kind==='brain'){$q=$pdo->prepare('SELECT user_id,user_agent_id,is_active FROM agent_memory_items WHERE id=?');$q->execute([(int)$id]);$b=$q->fetch(PDO::FETCH_ASSOC);if(!$b||(int)$b['user_id']!==(int)$user['id']||(int)($b['user_agent_id']??0)!==(int)$m['agent_id']||empty($b['is_active'])||!personal_capability_has_v242('agent_brain.access',$user))throw new RuntimeException('A Mission Brain source was removed or access changed.',403);}
    }
}
function cloud_mission_view_v1(PDO $pdo,array $user,array $m): array
{
    $run=cloud_mission_run_v1($pdo,$user,$m);$rows=agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']);$authority=true;try{cloud_mission_authority_v1($pdo,$user,$m);foreach($rows as $a){$result=json_decode((string)($a['result_json']??''),true)?:[];if(!empty($result['mission_output']))cloud_mission_result_authority_v1($pdo,$user,$m,$result);}}catch(Throwable $e){$authority=false;}
    $tasks=[];$combined='';foreach($rows as $a){$result=json_decode((string)($a['result_json']??''),true)?:[];$status=match($a['status']){'executing'=>'running','approval_pending'=>'queued',default=>$a['status']};$text=$authority?(string)($result['mission_output']??''):'';if($text!=='')$combined=$text;$tasks[]=['id'=>$a['action_key'],'title'=>$a['label'],'role'=>$a['sequence_no']==1?'investigator':'reviewer','status'=>$status,'result'=>$text,'error'=>$a['status']==='failed'?(string)($a['result_summary']??$a['error_class']??''):'','read_calls_used'=>(int)($result['read_calls']??0),'started_at'=>$a['started_at']??'','completed_at'=>$a['completed_at']??'','activity'=>['stage'=>$status,'last_activity_at'=>$a['heartbeat_at']??$a['completed_at']??$run['updated_at']]];}
    $status=match($run['status']){'approval_pending','queued'=>'planned','approved','executing'=>'running','paused'=>'waiting_review',default=>$run['status']};$tools=cloud_mission_tools_v1($m);$config=json_decode($m['config_json'],true);$provider=function_exists('ai_active_provider')?ai_active_provider():'configured';
    $recurrence=function_exists('cloud_mission_schedule_link_v1')?cloud_mission_schedule_link_v1($pdo,$m['mission_id']):null;
    return ['id'=>$m['mission_id'],'objective'=>$authority?$run['goal']:'Cloud Mission · source access changed','status'=>$status,'execution_target'=>'cloud','authority_current'=>$authority,'recurrence'=>$recurrence&&$authority?['schedule_id'=>$recurrence['schedule_id'],'report_state'=>$recurrence['report_state'],'knowledge_id'=>(int)$recurrence['knowledge_id'],'evidence_changed'=>(int)$recurrence['evidence_changed']]:null,'private'=>!$authority,'tasks'=>$authority?$tasks:[],'result'=>$authority?$combined:'','created_at'=>$m['created_at'],'updated_at'=>$run['updated_at']??$m['created_at'],'tools_enabled'=>true,'tools_configured'=>$tools['configured'],'chat_task'=>['draft'=>$config,'prepared_at'=>$m['created_at'],'provider_key'=>$provider,'model'=>function_exists('ai_provider_model')?ai_provider_model($provider):'configured','automatic_reason'=>'Cloud analysis with reviewed read capabilities'],'progress'=>['state'=>$authority?$status:'blocked','completed_workers'=>count(array_filter($tasks,fn($t)=>$t['status']==='completed')),'checked_at'=>gmdate('Y-m-d H:i:s')],'job_id'=>(int)$run['id']];
}
function cloud_mission_configure_v1(PDO $pdo,array $user,array $input): array
{
    $id=(string)($input['mission_id']??'');$rid=(string)($input['request_id']??'');if(!preg_match('/^[a-f0-9-]{36}$/D',$rid)||($input['confirmed']??false)!==true)throw new InvalidArgumentException('Review and confirm the Mission assignment change.');
    $hash=hash('sha256',json_encode([$id,$input['expected_revision']??null,$input['assignments']??null],JSON_THROW_ON_ERROR));$pdo->beginTransaction();try{
        $m=cloud_mission_row_v1($pdo,$user,$id,true);$q=$pdo->prepare('SELECT request_hash FROM agent_cloud_mission_operations_v1 WHERE owner_user_id=? AND request_id=?');$q->execute([(int)$user['id'],$rid]);$old=$q->fetchColumn();if($old){if(!hash_equals($old,$hash))throw new RuntimeException('This operation ID belongs to a different review.',409);$pdo->commit();return cloud_mission_tools_v1($m);}
        $run=cloud_mission_run_v1($pdo,$user,$m,true);cloud_mission_authority_v1($pdo,$user,$m);if($run['status']!=='approval_pending'||(int)$m['revision']!==($input['expected_revision']??null))throw new RuntimeException('The Mission review changed. Refresh assignments.',409);
        $draft=$input['assignments']??[];if(($draft['max_parallel']??null)!==1)throw new InvalidArgumentException('Cloud Missions currently run one worker at a time.');$tasks=agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']);$entries=$draft['assignments']??[];if(!is_array($entries)||count($entries)!==count($tasks))throw new InvalidArgumentException('Review every Mission worker.');$seen=[];
        foreach($entries as &$entry){$tid=$entry['task_id']??'';if(isset($seen[$tid])||!in_array($tid,array_column($tasks,'action_key'),true))throw new InvalidArgumentException('Choose each current worker once.');$seen[$tid]=true;$tools=$entry['tools']??[];$calls=$entry['max_calls']??null;if(!is_array($tools)||array_diff($tools,['knowledge.search','brain.recall'])||!is_int($calls)||$calls<0||$calls>3||count($tools)>$calls||!empty($entry['actions'])||!empty($entry['max_actions'])||!empty($entry['browser_url'])||!in_array($entry['output']??'', ['analysis','sources','document'],true))throw new InvalidArgumentException('Cloud assignments support bounded Knowledge/Brain reads and draft output only.');if(in_array('knowledge.search',$tools,true)&&!personal_knowledge_available($user))throw new RuntimeException('Knowledge access unavailable.',403);if(in_array('brain.recall',$tools,true)&&!personal_capability_has_v242('agent_brain.access',$user))throw new RuntimeException('Brain access unavailable.',403);$entry=['task_id'=>$tid,'tools'=>array_values(array_unique($tools)),'max_calls'=>$calls,'actions'=>[],'max_actions'=>0,'browser_url'=>'','output'=>$entry['output']];}unset($entry);
        $oldConfig=json_decode($m['config_json'],true);$config=['thread_id'=>$oldConfig['thread_id']??0,'max_parallel'=>1,'assignments'=>$entries];$now=gmdate('Y-m-d H:i:s');$until=gmdate('Y-m-d H:i:s',time()+900);$pdo->prepare('UPDATE agent_cloud_missions_v1 SET config_json=?,revision=revision+1,reviewed_at=?,review_until=? WHERE mission_id=? AND owner_user_id=?')->execute([json_encode($config,JSON_THROW_ON_ERROR),$now,$until,$id,(int)$user['id']]);$pdo->prepare('INSERT INTO agent_cloud_mission_operations_v1(owner_user_id,request_id,mission_id,request_hash) VALUES(?,?,?,?)')->execute([(int)$user['id'],$rid,$id,$hash]);agent_workflow_event_v1400($pdo,(int)$user['id'],(int)$run['id'],'mission_assignments_reviewed','approval_pending','approval_pending','user','Cloud read capabilities and cumulative budgets reviewed.');$pdo->commit();return cloud_mission_tools_v1(cloud_mission_row_v1($pdo,$user,$id));
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function cloud_mission_execute_claim_v1(PDO $pdo,array $user,array $claim,?callable $model=null): array
{
    $run=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);if(!$run||($run['action_capability_key']??'')!=='missions.analyze')throw new RuntimeException('Cloud Mission lease is unavailable.',409);
    $m=cloud_mission_row_v1($pdo,$user,$run['source_key']);$oldScope=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
    try{
        cloud_mission_authority_v1($pdo,$user,$m,true);vp3_agent_memory_scope_set_current_v410((int)$user['id'],(int)$m['agent_id']);$config=json_decode($m['config_json'],true,16,JSON_THROW_ON_ERROR);$entry=null;foreach($config['assignments'] as $e)if($e['task_id']===($claim['action']['action_key']??'')){$entry=$e;break;}
        if(!$entry)throw new RuntimeException('This worker has no reviewed assignment.',409);
        $attempt=(int)($claim['action']['attempt_count']??1);$used=($attempt-1)*count($entry['tools']);if($used+count($entry['tools'])>$entry['max_calls'])throw new RuntimeException('The cumulative read budget is exhausted. Prepare a fresh Mission.',409);
        $context=[];$refs=[];
        $scheduled=function_exists('cloud_mission_schedule_link_v1')?cloud_mission_schedule_link_v1($pdo,$m['mission_id']):null;
        foreach($entry['tools'] as $tool){
            if($tool==='knowledge.search'){
                if(!personal_knowledge_available($user))throw new RuntimeException('Knowledge access changed.',403);
                foreach(search_knowledge($run['goal'],$user,4,$scheduled?['Recurring Cloud Mission · ']:[]) as $k){$id=(int)($k['id']??0);if($id<1&&preg_match('/knowledge:(\d+)/',(string)($k['source']??''),$match))$id=(int)$match[1];if($id<1)continue;$refs[]='knowledge:'.$id;$context[]=['source'=>'knowledge:'.$id,'title'=>$k['title']??'Knowledge','text'=>mb_substr((string)($k['content_text']??$k['text']??$k['excerpt']??$k['chunk_text']??''),0,5000)];}
            }elseif($tool==='brain.recall'){
                if(!personal_capability_has_v242('agent_brain.access',$user))throw new RuntimeException('Brain access changed.',403);
                foreach(agent_brain_context($user,$run['goal'],4,true) as $b){$bid=(int)($b['memory_id']??0);if($bid<1)continue;$refs[]='brain:'.$bid;$context[]=$b;}
            }else throw new RuntimeException('This read capability is not connected to Cloud Missions.',409);
        }
        $evidenceRows=array_map(fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$context);sort($evidenceRows,SORT_STRING);$evidenceHash=hash('sha256',json_encode($evidenceRows,JSON_THROW_ON_ERROR));
        if($scheduled){$priorRun=cloud_mission_schedule_prior_v1($pdo,$user,$m);if(!empty($priorRun['context']))$context[]=$priorRun['context'];foreach($priorRun['refs']??[] as $ref)$refs[]=$ref;}
        foreach(agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']) as $prior){if((int)$prior['sequence_no']>=(int)$claim['action']['sequence_no'])break;$r=json_decode((string)($prior['result_json']??''),true)?:[];cloud_mission_result_authority_v1($pdo,$user,$m,$r);if(!empty($r['mission_output']))$context[]=['source'=>'mission-worker:'.$prior['action_key'],'title'=>$prior['label'],'text'=>$r['mission_output']];foreach(array_filter(explode(',',(string)($r['source_refs']??''))) as $ref)$refs[]=$ref;}
        $refs=array_values(array_unique($refs));$sourceRefs=implode(',',$refs);if(strlen($sourceRefs)>500)throw new RuntimeException('Narrow the Mission source scope.',409);
        cloud_mission_result_authority_v1($pdo,$user,$m,['source_refs'=>$sourceRefs]);
        if(!agent_job_heartbeat_v1900($pdo,$user,(int)$run['id'],(int)$claim['action']['id'],$claim['lease_token'],20,'Analyzing approved Cloud evidence',240))throw new RuntimeException('Mission was paused or cancelled before inference.',409);
        $instruction="Mission objective:\n".$run['goal']."\nWorker: ".$run['action_label']."\nAssignment: ".$run['action_summary']."\nRequested output: ".$entry['output'];
        $result=$model?$model($instruction,$context,$user):ai_generate_chat_response($instruction,[],$context,$user,'main_ai',['surface'=>'cloud_mission']);
        if(empty($result['ok']))throw new RuntimeException('Cloud inference did not return a confirmed result. Review the provider or quota, then retry explicitly.',503);
        $answer=trim((string)($result['answer']??''));if($answer==='')throw new RuntimeException('Cloud inference returned no output.',503);$m=cloud_mission_row_v1($pdo,$user,$m['mission_id']);cloud_mission_authority_v1($pdo,$user,$m,true);cloud_mission_result_authority_v1($pdo,$user,$m,['source_refs'=>$sourceRefs]);
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,true,'Cloud Mission worker completed with model output.',['mission_output'=>mb_substr($answer,0,16000),'source_refs'=>$sourceRefs,'evidence_hash'=>$evidenceHash,'read_calls'=>$used+count($entry['tools']),'provider'=>(string)($result['provider']??'configured'),'effect_state'=>'analysis_complete'],'',false);
        if($scheduled)cloud_mission_schedule_sync_v1($pdo,$user,$m['mission_id']);
        return ['ok'=>true,'reason'=>'completed','receipt'=>$receipt];
    }catch(Throwable $e){
        $active=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);if(!$active)return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Cloud Mission stopped: '.($e instanceof RuntimeException?$e->getMessage():'execution unavailable'),[],(int)$e->getCode()===503?'cloud_inference_unconfirmed':'cloud_mission_authority_changed',false);return ['ok'=>false,'reason'=>'failed','receipt'=>$receipt];
    }finally{if($oldScope===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$oldScope;}
}
function cloud_mission_tick_v1(PDO $pdo,array $user,string $id,?callable $model=null): array
{
    $m=cloud_mission_row_v1($pdo,$user,$id);cloud_mission_authority_v1($pdo,$user,$m,true);agent_job_recover_expired_v1900($pdo,$user,25);$claim=agent_job_claim_run_v1900($pdo,$user,(int)$m['run_id'],'cloud',agent_worker_cloud_worker_id_v1910((int)$user['id']),240);if($claim)cloud_mission_execute_claim_v1($pdo,$user,$claim,$model);if(function_exists('cloud_mission_schedule_sync_v1'))cloud_mission_schedule_sync_v1($pdo,$user,$id);return cloud_mission_view_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$id));
}
function cloud_mission_notices_v1(PDO $pdo,array $user): array
{
    $q=$pdo->prepare("SELECT e.*,m.mission_id,rr.read_at FROM agent_workflow_events e INNER JOIN agent_cloud_missions_v1 m ON m.run_id=e.run_id AND m.owner_user_id=e.owner_user_id LEFT JOIN agent_cloud_mission_reads_v1 rr ON rr.event_id=e.id AND rr.owner_user_id=e.owner_user_id WHERE e.owner_user_id=? AND e.event_type IN ('completed','failed','cancelled','mission_prepared','mission_report_saved') ORDER BY e.id DESC LIMIT 30");$q->execute([(int)$user['id']]);$items=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$items[]=['id'=>'cloud:'.$r['id'],'mission_id'=>$r['mission_id'],'title'=>'Cloud Mission · '.$r['event_type'],'created_at'=>$r['created_at'],'read_at'=>$r['read_at']??''];return ['items'=>$items,'unread'=>count(array_filter($items,fn($r)=>$r['read_at']==='')),'count_is_lower_bound'=>count($items)===30];
}
function cloud_mission_service_v1(PDO $pdo,array $user,string $action,array $input): array
{
    $base=['ok'=>true,'contract'=>'vp3.agent-missions.cloud.v1','execution_target'=>'cloud'];
    if(in_array($action,['create','task.prepare','task.route'],true)){$view=cloud_mission_prepare_v1($pdo,$user,$input);return $base+['route'=>'specialists','mission'=>$view];}
    if(in_array($action,['list','progress.feed','notifications.list','notifications.read'],true)){
        if($action==='notifications.read'){$notice=$input['notice_id']??'';if(!is_string($notice)||!preg_match('/^cloud:([1-9][0-9]*)$/D',$notice,$match))throw new InvalidArgumentException('Cloud notification ID is invalid.');$q=$pdo->prepare('SELECT e.id FROM agent_workflow_events e INNER JOIN agent_cloud_missions_v1 m ON m.run_id=e.run_id AND m.owner_user_id=e.owner_user_id WHERE e.id=? AND e.owner_user_id=?');$q->execute([(int)$match[1],(int)$user['id']]);if(!$q->fetchColumn())throw new RuntimeException('Notification not found.',404);$pdo->prepare('INSERT IGNORE INTO agent_cloud_mission_reads_v1(owner_user_id,event_id,read_at) VALUES(?,?,?)')->execute([(int)$user['id'],(int)$match[1],gmdate('Y-m-d H:i:s')]);}
        $q=$pdo->prepare('SELECT * FROM agent_cloud_missions_v1 WHERE owner_user_id=? ORDER BY created_at DESC,mission_id DESC LIMIT 20');$q->execute([(int)$user['id']]);$items=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $m)$items[]=cloud_mission_view_v1($pdo,$user,$m);return $base+['missions'=>$items,'items'=>$items,'notifications'=>cloud_mission_notices_v1($pdo,$user)];
    }
    $m=cloud_mission_row_v1($pdo,$user,(string)($input['mission_id']??''));$run=cloud_mission_run_v1($pdo,$user,$m);$id=$m['mission_id'];
    if($action==='tools.get')return $base+['tools'=>cloud_mission_tools_v1($m)];
    if($action==='tools.configure')return $base+['tools'=>cloud_mission_configure_v1($pdo,$user,$input),'mission'=>cloud_mission_view_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$id))];
    if(in_array($action,['start','tools.start'],true)){
        if($run['status']==='approval_pending'){if(($input['confirmed']??false)!==true||(int)$m['revision']<1||(int)$m['revision']!==($input['expected_revision']??null))throw new RuntimeException('Review current assignments before starting.',409);cloud_mission_authority_v1($pdo,$user,$m,true);agent_workflow_approve_v1400($pdo,$user,(int)$run['id']);}
        elseif(!in_array($run['status'],['approved','executing','completed'],true))throw new RuntimeException('This Mission cannot start in its current state.',409);
    }elseif($action==='cloud.tick')return $base+['mission'=>cloud_mission_tick_v1($pdo,$user,$id)];
    elseif($action==='cancel'){if(!in_array($run['status'],['completed','cancelled'],true))agent_job_cancel_v1900($pdo,$user,(int)$run['id']);}
    elseif($action==='pause')agent_work_control_pause_v173($pdo,$user,(int)$run['id']);
    elseif($action==='resume'){if(($input['allow_reexecution']??false)!==true)throw new RuntimeException('Confirm any interrupted model reexecution.',409);cloud_mission_authority_v1($pdo,$user,$m,true);agent_work_control_resume_v173($pdo,$user,(int)$run['id']);}
    elseif($action==='retry'){cloud_mission_authority_v1($pdo,$user,$m,true);$failed=array_values(array_filter(agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']),fn($a)=>$a['status']==='failed'));if(!$failed||($input['task_id']??'')!==$failed[0]['action_key'])throw new RuntimeException('Choose the current failed worker.',409);if((int)$failed[0]['attempt_count']>=3)throw new RuntimeException('This worker exhausted its three-attempt budget. Prepare a fresh Mission.',409);if((int)$run['attempt_count']>=6)throw new RuntimeException('This Mission exhausted its worker attempt budget. Prepare a fresh Mission.',409);agent_job_retry_v1900($pdo,$user,(int)$run['id']);}
    elseif($action==='events')return $base+['events'=>agent_workflow_events_v1400($pdo,(int)$user['id'],(int)$run['id'])];
    elseif($action==='execution'){
        cloud_mission_authority_v1($pdo,$user,$m);
        foreach(agent_workflow_actions_v1400($pdo,(int)$user['id'],(int)$run['id']) as $a)cloud_mission_result_authority_v1($pdo,$user,$m,json_decode((string)($a['result_json']??''),true)?:[]);
        return $base+['execution'=>agent_job_public_run_v1900($pdo,$run,true)];
    }
    elseif(in_array($action,['decisions','actions.list'],true))return $base+[$action==='decisions'?'decisions':'actions'=>[]];
    elseif($action==='tools.status')return $base+['tools'=>cloud_mission_tools_v1($m),'mission'=>cloud_mission_view_v1($pdo,$user,$m)];
    elseif($action==='bind_provider'){if(($input['provider_key']??'auto')!=='auto')throw new InvalidArgumentException('Cloud Missions use the provider configured in AI settings.');}
    elseif($action!=='get')throw new RuntimeException('This operation requires the HomeServer Mission runtime. Cloud Missions currently provide analysis and reviewed Knowledge/Brain reads.',409);
    return $base+['mission'=>cloud_mission_view_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$id))];
}
function cloud_mission_dispatch_v1(PDO $pdo,array $user,string $action,array $input,?callable $home=null): ?array
{
    if(!cloud_mission_ready_v1($pdo)){if(($input['executor']??'auto')==='cloud')throw new RuntimeException('Run the current upgrade to enable the Cloud job engine.',503);return null;}cloud_mission_schema_v1($pdo);require_once __DIR__.'/cloud-mission-schedules-v1.php';cloud_mission_schedules_schema_v1($pdo);
    if($action==='cloud.schedule.tick')return cloud_mission_schedule_tick_v1($pdo,$user);
    if($action==='schedule.list'){
        $cloud=cloud_mission_schedule_list_v1($pdo,$user);
        try{$status=homeserver_https_v1300_status((int)$user['id']);if(!empty($status['connected'])){$remote=$home?$home($action,[]):homeserver_https_v1300_wait(homeserver_https_v1300_queue((int)$user['id'],'agent.missions.schedule.list',[]),24000);if(!empty($remote['ok'])){$cloud['schedules']=array_merge($cloud['schedules'],$remote['schedules']??[]);$cloud['scheduler_health']=$remote['scheduler_health']??null;}}}catch(Throwable $ignored){$cloud['homeserver_updates_unavailable']=true;}return $cloud;
    }
    if(in_array($action,['schedule.pause','schedule.resume','schedule.cancel'],true)&&!empty($input['schedule_id'])){
        $q=$pdo->prepare('SELECT owner_user_id FROM agent_cloud_mission_schedules_v1 WHERE schedule_id=?');$q->execute([(string)$input['schedule_id']]);$owner=$q->fetchColumn();if($owner!==false){if((int)$owner!==(int)$user['id'])throw new RuntimeException('Schedule not found.',404);return cloud_mission_schedule_mutate_v1($pdo,$user,$action,$input);}
    }
    if(in_array($action,['create','task.prepare','task.route'],true)){if(!in_array($input['executor']??'auto',['auto','cloud','homeserver'],true))throw new InvalidArgumentException('Choose Cloud or HomeServer execution.');if(($input['executor']??'auto')==='homeserver')return null;if($action==='task.route'&&!preg_match('/\b(?:start|prepare|run|create)\s+(?:(?:a|the)\s+)?(?:cloud\s+)?mission\b/iu',(string)($input['objective']??'')))return ['ok'=>true,'contract'=>'vp3.agent-missions.cloud.v1','route'=>'chat','reason'=>'Continue ordinary Cloud Chat. Choose Specialist plan to prepare a Mission.'];return cloud_mission_service_v1($pdo,$user,$action,$input);}
    if($action==='notifications.read'&&is_string($input['notice_id']??null)&&str_starts_with($input['notice_id'],'cloud:'))return cloud_mission_service_v1($pdo,$user,$action,$input);
    if(!empty($input['mission_id'])){$q=$pdo->prepare('SELECT owner_user_id FROM agent_cloud_missions_v1 WHERE mission_id=?');$q->execute([(string)$input['mission_id']]);$owner=$q->fetchColumn();if($owner!==false){if((int)$owner!==(int)$user['id'])throw new RuntimeException('Mission not found.',404);if($action==='schedule.create')return cloud_mission_schedule_mutate_v1($pdo,$user,$action,$input);return cloud_mission_service_v1($pdo,$user,$action,$input);}}
    if(!in_array($action,['list','progress.feed','notifications.list'],true))return null;
    $cloud=cloud_mission_service_v1($pdo,$user,$action,$input);$cloud['runtimes']=['cloud'=>true,'homeserver'=>false];
    try{$status=homeserver_https_v1300_status((int)$user['id']);if(!empty($status['connected'])){$payload=$action==='list'?['limit'=>8]:[];$remote=$home?$home($action,$payload):homeserver_https_v1300_wait(homeserver_https_v1300_queue((int)$user['id'],'agent.missions.'.$action,$payload),24000);if(is_array($remote)&&!empty($remote['ok'])){foreach(['missions','items'] as $k)if(is_array($remote[$k]??null))$cloud[$k]=array_merge($cloud[$k]??[],$remote[$k]);if(is_array($remote['notifications']['items']??null)){$cloud['notifications']['items']=array_merge($cloud['notifications']['items'],$remote['notifications']['items']);$cloud['notifications']['unread']+=(int)($remote['notifications']['unread']??0);}$cloud['runtimes']['homeserver']=true;}}}catch(Throwable $ignored){$cloud['homeserver_updates_unavailable']=true;}
    return $cloud;
}
