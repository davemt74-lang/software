<?php
declare(strict_types=1);

// Native scoped worker, receipts, canonical Chat, reply state machine and composer.
define('VP3_COORDINATION_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-casual-checkin-v1.php';
require_once dirname(__DIR__).'/includes/agent-casual-checkin-reply-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-composer-v1.php';
$start=$checks;
$config=['connections'=>['gmail'=>['client_id'=>'invitation-pipeline.apps.googleusercontent.com','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://invitation-pipeline.example.test/connection-oauth.php']]];
$pdo->exec('ALTER TABLE chat_conversations MODIFY id BIGINT UNSIGNED NOT NULL');
if(!column_exists('chat_conversations','title'))$pdo->exec("ALTER TABLE chat_conversations ADD COLUMN title VARCHAR(190) DEFAULT ''");
agent_connections_install_v1($pdo);agent_connection_email_draft_schema_v1($pdo);agent_connection_email_followup_install_v1($pdo);
$pdo->prepare("INSERT INTO agent_connections_v1(id,owner_user_id,provider,status,read_enabled,send_enabled,authorization_key,client_fingerprint,account_email,access_token_enc,refresh_token_enc)
    VALUES(71,1,'gmail','connected',1,1,?,?,'owner@example.test','','')")->execute([str_repeat('a',64),agent_connections_client_fingerprint_v1()]);
$key='native-invitation-pipeline';$noIO=static fn()=>throw new RuntimeException('Invitation unexpectedly used a provider/model.');
$drafts=[];
foreach(['Workshop schedule','Supplier confirmation','Travel arrangements'] as $index=>$subject){
    $draft=agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,['action'=>'compose_create','request_id'=>str_pad((string)($index+1),32,'a')],$key,$noIO);
    // Seed the native saved snapshot; composer editing itself has separate native acceptance.
    $payload=['subject'=>$subject,'body'=>'Private draft for '.$subject,'composer'=>['version'=>1,'saved_at'=>time()]];
    $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET recipient=?,message_enc=? WHERE id=?')
        ->execute(['recipient@example.test',ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),$draft['id']]);
    $drafts[]=$draft;
}
$enabled=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null);$binding=$enabled['initiative_policy'];
vp3_casual_checkin_save_v1($pdo,1,true);coordination_fixture_presence('quiet');
$scope=vp3_agent_activity_scope_v1($pdo,$owner,7,11);
$items=vp3_casual_checkin_sources_v1($pdo,$scope,$binding,['casual:email'],new DateTimeImmutable('now',new DateTimeZone('UTC')))['items'];
$source=$items['casual:email']??null;
canvas_pipeline_check($source!==null&&count($source['email_draft_targets'])===3,'the actual specialist adapter exposes three native manual drafts');
$targets=$source['email_draft_targets'];
$id=vp3_coordination_scan_id_v1($owner,$binding,'casual:email:'.$source['revision']);
$goal=['binding'=>$binding,'tool'=>'coordination.review','coordination_id'=>$id,'primary'=>'casual:email','sources'=>['casual:email'=>$source['revision']],
    'focus'=>'Offer the exact reviewable drafts.','report'=>true,'coordination_coverage'=>[],'coordination_priority'=>'low',
    'coordination_route'=>['specialist'=>'casual','trigger'=>'email','action'=>'ask'],
    'coordination_routes'=>['casual:email'=>['specialist'=>'casual','trigger'=>'email','action'=>'ask']],'coordination_saved'=>true];
$before=canvas_pipeline_count('chat_messages');
canvas_pipeline_check(vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$goal,hash('sha256','native-invitation-pipeline')),'one grounded invitation enters the existing canonical worker queue');
$rows=canvas_pipeline_runs('initiative.prepare');$run=(int)end($rows)['id'];
$worked=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($run),$noIO,null,true);
canvas_pipeline_check(!empty($worked['ok']),'native invitation preparation completes without a model or provider call');
$receipt=canvas_pipeline_result($run);
canvas_pipeline_check(($receipt['initiative_delivery']??null)==='delivered','invitation is committed and delivered with the canonical receipt');
$messageId=(int)$receipt['initiative_message_id'];$post=coordination_fixture_message($messageId);$context=json_decode($post['context_json'],true);
canvas_pipeline_check(canvas_pipeline_count('chat_messages')===$before+1&&str_contains($post['message'],'1. Travel arrangements')
    &&str_contains($post['message'],'Which of these drafts'),'one canonical Chat post contains the grounded draft list');
$follow=$context['initiative_work']['followthrough'];$explicit=['message_id'=>$messageId,'reply_ref'=>$follow['reply_ref'],'revision'=>$follow['revision']];
$target=vp3_casual_checkin_reply_target_v1($pdo,$owner,7,11,'Yes.',$explicit);
$answer=vp3_casual_checkin_reply_chat_v1($pdo,$owner,$principal,11,'Yes.',$explicit,$target,'voice',null);
canvas_pipeline_check($answer['initiative_followthrough']['state']==='open'&&count($answer['actions'])===3&&!isset($answer['media_playback']),
    'bare voice assent offers three review choices without choosing or sending a draft');
canvas_pipeline_check(array_column($answer['actions'],'id')===array_column($targets,'id'),'each review button targets an exact offered native draft');
$nextExplicit=['message_id'=>$messageId,'reply_ref'=>$answer['initiative_followthrough']['reply_ref'],'revision'=>$answer['initiative_followthrough']['revision']];
$target=vp3_casual_checkin_reply_target_v1($pdo,$owner,7,11,'second',$nextExplicit);
$selected=vp3_casual_checkin_reply_chat_v1($pdo,$owner,$principal,11,'second',$nextExplicit,$target,'text',null);
canvas_pipeline_check($selected['initiative_followthrough']['state']==='owner_resolved'&&count($selected['actions'])===1
    &&$selected['actions'][0]['id']===$targets[1]['id'],'ordinal reply selects the second offered draft and no other');
$opened=agent_connection_email_chat_action_v1($pdo,$owner,$principal,$targets[1]['conversation_id'],['action'=>'review','draft_id'=>$targets[1]['id']],$key,$noIO);
canvas_pipeline_check($opened['subject']===$source['invitation']['items'][1]['title']&&$opened['can_edit']&&isset($opened['review_token']),
    'the selected button opens the original native composer with a fresh exact review');
$replayTarget=vp3_casual_checkin_reply_target_v1($pdo,$owner,7,11,'second',$nextExplicit);
$replay=vp3_casual_checkin_reply_chat_v1($pdo,$owner,$principal,11,'second',$nextExplicit,$replayTarget,'text',null);
canvas_pipeline_check(!empty($replay['replayed'])&&$replay['assistant_message_id']===$selected['assistant_message_id'],
    'lost reply recovery reuses the same saved answer and creates no second action');
canvas_pipeline_check(canvas_pipeline_count('agent_connection_email_drafts_v1')===3&&canvas_pipeline_count('agent_connection_sends_v1')===0
    &&canvas_pipeline_count('agent_connection_email_followups_v1')===0,'invitation and review leave drafts unchanged and create no send or watch');
if(($snapshot=getenv('VP3_INVITATION_BROWSER_SNAPSHOT'))!==false&&$snapshot!==''){
    file_put_contents($snapshot,json_encode(['draft'=>$post['message'],'actions'=>$answer['actions'],'selected'=>$selected['actions'][0]],JSON_THROW_ON_ERROR));
}
$pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET recipient=? WHERE id=?')->execute(['changed@example.test',$targets[0]['id']]);
$stale=false;
try{
    $replayTarget=vp3_casual_checkin_reply_target_v1($pdo,$owner,7,11,'second',$nextExplicit);
    vp3_casual_checkin_reply_chat_v1($pdo,$owner,$principal,11,'second',$nextExplicit,$replayTarget,'text',null);
}catch(Vp3InitiativeSourcesChangedV1 $error){$stale=true;}
canvas_pipeline_check($stale,'replaying an answered invitation cannot return saved actions after an offered draft changes');
echo 'PASS specialist invitation canonical pipeline ('.($checks-$start).' checks)'.PHP_EOL;
