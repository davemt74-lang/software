<?php
declare(strict_types=1);
require_once __DIR__.'/initiative-action-chat-fixture.php';
require_once __DIR__.'/agent-initiative-followups-v1.php';
if(!vp3_initiative_followup_action_ready_v1(db()))reminder_schema_v1(db());
if(!table_exists('notifications'))db()->exec('CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,created_at TEXT)');
function ai_generate_chat_response($prompt,...$args):array{
 $protocol=(string)($args[4]['protocol']??'');$data=json_decode((string)$prompt,true);
 if($protocol==='vp3.initiative-reply.v1'&&isset($_SERVER['HTTP_X_FIXTURE_FOLLOWUP_CLARIFICATION'])){
  followthrough_fixture_log_v1('initiative_model',['protocol'=>$protocol,'prompt'=>$prompt]);followthrough_fixture_provider_gate_v1('intent');
  return['ok'=>true,'answer'=>json_encode(['intent'=>'discuss','focus'=>'Resolve the exact revisit time.','clarification'=>$_SERVER['HTTP_X_FIXTURE_FOLLOWUP_CLARIFICATION']],JSON_THROW_ON_ERROR)];
 }
 if($protocol==='vp3.initiative-draft.v1'&&($data['response_kind']??null)==='followthrough_reply'&&!empty($data['verified_followthrough']['followup_clarification'])){
  followthrough_fixture_log_v1('main_model',['context'=>$data]);return['ok'=>true,'answer'=>json_encode(['draft'=>$data['verified_followthrough']['followup_clarification']],JSON_THROW_ON_ERROR)];
 }
 return action_fixture_ai_generate_chat_response($prompt,...$args);
}
function followup_fixture_state():array{return array_replace(action_fixture_state_v1(),['reminders'=>db()->query('SELECT * FROM agent_reminders_v1 ORDER BY id')->fetchAll(),'occurrences'=>db()->query('SELECT * FROM agent_reminder_occurrences_v1 ORDER BY id')->fetchAll()]);}
function followup_fixture_control():never{
 $action=(string)($_GET['action']??'state');$pdo=db();
 if(!in_array($action,['seed','state','tick','run','cancel','condition_seed','condition_complete'],true))action_fixture_control_v1();
 header('Content-Type: application/json');
 try{
  if($action==='seed'){
   $pdo->exec('DELETE FROM agent_reminder_occurrences_v1;DELETE FROM agent_reminders_v1;DELETE FROM agent_reminder_turns_v1;DELETE FROM agent_reminder_context_v1');
   $pdo->exec("INSERT OR IGNORE INTO role_permissions(role,permission_key) VALUES('member','agent_brain.access');UPDATE user_agents SET is_active=1;UPDATE users SET is_active=1");followthrough_fixture_seed_v1();
  }elseif($action==='condition_seed'){
   require_once __DIR__.'/cloud-missions-v1.php';cloud_mission_schema_v1($pdo);
   $pdo->exec('DELETE FROM agent_cloud_missions_v1');
   $mission=cloud_mission_prepare_v1($pdo,['id'=>1,'is_active'=>1],['objective'=>'Review the launch assets','request_id'=>bin2hex(random_bytes(16)),'thread_id'=>11,'agent_id'=>7]);
   cloud_mission_configure_v1($pdo,['id'=>1,'is_active'=>1],['mission_id'=>$mission['id'],'request_id'=>cloud_mission_uuid_v1(bin2hex(random_bytes(16))),'confirmed'=>true,'expected_revision'=>0,'assignments'=>$mission['chat_task']['draft']]);
   echo json_encode(['ok'=>true,'condition_target'=>'mission:'.$mission['id']]+followup_fixture_state(),JSON_THROW_ON_ERROR);exit;
  }elseif($action==='condition_complete'){
   require_once __DIR__.'/cloud-missions-v1.php';$id=(string)$_GET['mission_id'];$user=['id'=>1,'is_active'=>1];$m=cloud_mission_row_v1($pdo,$user,$id);$view=cloud_mission_view_v1($pdo,$user,$m);
   cloud_mission_service_v1($pdo,$user,'start',['mission_id'=>$id,'confirmed'=>true,'expected_revision'=>1]);
   for($i=0;$i<2;$i++){$claim=followthrough_fixture_claim_v1((int)$m['run_id']);$r=cloud_mission_execute_claim_v1($pdo,$user,$claim,static fn()=>['ok'=>true,'answer'=>'Saved launch assessment.']);if(empty($r['ok']))throw new RuntimeException('Fixture Mission execution failed.');}
   $pdo->exec('UPDATE agent_reminder_occurrences_v1 SET lease_until=NULL');
  }elseif($action==='tick'){
   $id=(int)($_GET['id']??0);$q=$pdo->prepare('SELECT * FROM agent_reminders_v1 WHERE id=? AND owner_user_id=1');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Missing exact fixture reminder.');
   reminder_tick_v1($pdo,1,($r['next_at']===null?new DateTimeImmutable('now',new DateTimeZone('UTC')):(new DateTimeImmutable($r['next_at']))->modify('+1 second')));
  }elseif($action==='cancel'){
   reminder_execute_v1($pdo,1,'fixture-cancel','cancel-'.bin2hex(random_bytes(12)),'Owner cancels this exact revisit',['operation'=>'cancel','id'=>(int)$_GET['id']],reminder_rows_v1($pdo,1),11);
  }elseif($action==='run'){
   $id=(int)($_GET['id']??0);$q=$pdo->prepare("SELECT id FROM agent_workflow_runs WHERE capability_key='initiative.prepare' AND goal LIKE ? ORDER BY id DESC LIMIT 1");$q->execute(['%"reminder_id":'.$id.',%']);$run=(int)$q->fetchColumn();if(!$run)throw new RuntimeException('Missing actual scheduled workflow.');
   $pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE role='assistant'");
   $result=vp3_initiative_execute_v1($pdo,['id'=>1,'is_active'=>1],followthrough_fixture_claim_v1($run),static function(string $prompt):string{
    if(db()->inTransaction())throw new RuntimeException('Wake inference held SQL locks.');$p=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);followthrough_fixture_log_v1('followup_model',$p);
    return json_encode(['draft'=>'The current launch task is still open. We can review the launch assets next.'],JSON_THROW_ON_ERROR);
   });if(empty($result['ok']))throw new RuntimeException('Actual scheduled worker failed.');
  }
  echo json_encode(['ok'=>true]+followup_fixture_state(),JSON_THROW_ON_ERROR);
 }catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'at'=>basename($e->getFile()).':'.$e->getLine()]);}
 exit;
}
