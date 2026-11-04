<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/profile-calls-pa3c.php';
require_once __DIR__.'/../includes/agent-catch-up-v1.php';
require_once __DIR__.'/../includes/agent-task-lists-v1.php';
require_once __DIR__.'/../includes/agent-reminders-v1.php';
require_once __DIR__.'/../includes/agent-scheduling-v430.php';
require_once __DIR__.'/../includes/user-calendar-v1300.php';
require_once __DIR__.'/../includes/knowledge-retrieval-v162.php';
require_once __DIR__.'/../includes/transcript-agent-v1.php';

function catchup_call_check(bool $condition,string $name): void
{
    if(!$condition)throw new RuntimeException('FAIL: '.$name);
    echo 'PASS '.$name.PHP_EOL;
}
function catchup_call_read(int $agent=0,int $owner=1): array
{
    $user=pa3_owner($owner);
    $principal=user_agent_principal_v236($user,$agent?['id'=>$agent,'owner_user_id'=>$owner,'display_name'=>'Fixture Agent']:null);
    return vp3_catch_up_profile_calls_v1(db(),$user,$principal,new DateTimeImmutable('@'.intdiv($GLOBALS['pa3Now'],1000)));
}
function catchup_call_task(int $id,int $session,array $overrides=[],?int $agent=null,int $owner=1): void
{
    $meta=$overrides+['source_kind'=>'transcription_intelligence','source_session_id'=>$session,
        'created_from_reviewed_item'=>true,'task_status'=>'open','item_id'=>'fixture-reviewed-action'];
    db()->prepare("INSERT INTO agent_memory_items (id,user_id,user_agent_id,is_active,memory_type,subject,metadata_json,last_seen_at)
        VALUES (?,?,?,1,'task',?,?,?)")->execute([$id,$owner,$agent,'Reviewed action '.$id,json_encode($meta,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')]);
}

pa3c_reset();
$offer=pa3c_offer('visitor',pa3c_context(2));
$before=vp3_profile_call_row_pa3c($pdo,$offer['call_id']);
catchup_call_check(catchup_call_read()===['ready',[]],'an offer still ringing stays on the dedicated call surface');
$GLOBALS['pa3Now']+=61000;
[$coverage,$items]=catchup_call_read();
catchup_call_check($coverage==='ready'&&array_column($items,'id')===['profile-call:'.$offer['call_id']],'one current unanswered incoming offer becomes a missed-call fact');
catchup_call_check($items[0]['url']==='/profile-agent.php?tab=inbox'&&!str_contains(json_encode($items),$before['credential_hash']),'missed-call destination omits participant and provider identifiers');
catchup_call_check(vp3_profile_call_row_pa3c($pdo,$offer['call_id'])===$before&&$GLOBALS['pa3cProviderCalls']===[],'catch-up reads do not expire offers, write state or contact the provider');
catchup_call_check(catchup_call_read(8)===['ready',[]]&&catchup_call_read(0,4)===['ready',[]],'other named Agents and owners cannot see this call');
catchup_call_check(count(catchup_call_read(7)[1])===1,'the selected Profile Agent may see its own missed call');
$pdo->exec('INSERT INTO user_blocks (blocker_user_id,blocked_user_id) VALUES (2,1)');
catchup_call_check(catchup_call_read()===['ready',[]],'a visitor block revokes missed-call projection');
$pdo->exec('DELETE FROM user_blocks');
$pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message,created_at) VALUES (?,'owner',1,'Private follow-up text',?)")
    ->execute([$offer['conversation_id'],vp3_profile_handoff_date_pa3()]);
catchup_call_check(catchup_call_read()===['ready',[]],'a subsequent owner reply closes missed-call attention');

pa3c_reset();
$offer=pa3c_offer();
$GLOBALS['pa3Now']+=61000;
pa3c_offer('owner',$offer['context']);
catchup_call_check(catchup_call_read()===['ready',[]],'a newer call on the same conversation supersedes the expired offer');
$GLOBALS['pa3Now']+=61000;
catchup_call_check(catchup_call_read()===['ready',[]],'expired outbound calls do not become incoming missed calls');

pa3c_reset();
$offer=pa3c_offer();
vp3_profile_call_owner_action_pa3c($pdo,pa3c_owner(),pa3c_owner_input($offer,'decline'));
$GLOBALS['pa3Now']+=61000;
catchup_call_check(catchup_call_read()===['ready',[]],'a declined call never becomes an unanswered incoming call');

pa3c_reset();
vp3_profile_call_activity_ensure_schema_pa3c($pdo);
$pdo->exec("INSERT INTO role_permissions VALUES ('member','artist_listening.access')");
$pdo->exec('ALTER TABLE agent_memory_items ADD COLUMN user_agent_id INTEGER NULL');
$pdo->exec('ALTER TABLE agent_memory_items ADD COLUMN memory_scope_version INTEGER NOT NULL DEFAULT 410');
$pdo->exec('CREATE TABLE agent_chat_archive (user_agent_id INTEGER,created_at DATETIME)');
foreach([
    ['agent_memory_items','idx_agent_memory_agent_type_v410','memory_type'],
    ['agent_memory_items','idx_agent_memory_agent_occurrence_v410','last_seen_at'],
    ['agent_chat_archive','idx_agent_archive_agent_created_v410','created_at'],
] as [$table,$index,$column]){
    $pdo->exec('CREATE INDEX '.$index.' ON '.$table.' (user_agent_id,'.$column.')');
}
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    $pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec("CREATE TABLE information_schema.statistics AS
        SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name FROM main.sqlite_master WHERE type='index'");
}
$pdo->exec('CREATE TABLE artist_transcript_sessions_v172 (
    id INTEGER PRIMARY KEY, created_by_user_id INTEGER, status VARCHAR(20),
    conversation_id INTEGER, discarded_at DATETIME NULL, metadata_json TEXT)');
$offer=pa3c_offer();
$call=vp3_profile_call_row_pa3c($pdo,$offer['call_id']);
$pdo->prepare("UPDATE profile_calls_pa3c SET state='ended',accepted_at_ms=?,ended_at_ms=?,assisted=1 WHERE id=?")
    ->execute([$GLOBALS['pa3Now'],$GLOBALS['pa3Now'],$call['id']]);
$pdo->prepare('INSERT INTO profile_call_assistance_pa3c (call_id,transcript_session_id) VALUES (?,?)')->execute([$call['public_id'],25]);
$pdo->prepare("INSERT INTO artist_transcript_sessions_v172 VALUES (25,1,'draft',NULL,NULL,?)")
    ->execute([json_encode(['source'=>'profile_call_pa3c','profile_call_id'=>$call['public_id']],JSON_THROW_ON_ERROR)]);
catchup_call_task(401,25);
catchup_call_task(402,25,['created_from_reviewed_item'=>false]);
catchup_call_task(403,25,['task_status'=>'completed']);
catchup_call_task(404,25,['assigned_user_id'=>2]);
catchup_call_task(405,99);
catchup_call_task(406,25,[],null,4);
catchup_call_task(407,25,[],8);
[$coverage,$items]=catchup_call_read();
catchup_call_check($coverage==='ready'&&array_column($items,'id')===['task:401'],'only the reviewed open exact linked call task is surfaced and uses its canonical task ID');
catchup_call_check($items[0]['url']==='/artist-listening.php?session=25'&&$items[0]['source_label']==='Profile call follow-up','call follow-up points to its authorized transcript');
catchup_call_check(catchup_call_read(8)===['ready',[]],'an Agent-scoped task cannot substitute another Profile Agent’s call as evidence');
$pdo->exec('UPDATE artist_transcript_sessions_v172 SET conversation_id=12 WHERE id=25');
catchup_call_check(catchup_call_read()===['ready',[]],'system Agent cannot use a transcript attached to a named Agent');
$pdo->exec("UPDATE artist_transcript_sessions_v172 SET conversation_id=NULL,discarded_at='2026-01-01 00:00:00' WHERE id=25");
catchup_call_check(catchup_call_read()===['ready',[]],'a discarded transcript no longer supplies Profile call evidence');
$pdo->exec("UPDATE artist_transcript_sessions_v172 SET discarded_at=NULL,metadata_json='{}' WHERE id=25");
catchup_call_check(catchup_call_read()===['ready',[]],'an assistance reference without matching transcript provenance is not sufficient');
$pdo->exec('DROP TABLE profile_call_assistance_pa3c');
catchup_call_check(catchup_call_read()===['limited',[]],'missing assisted-call evidence is reported as partial coverage');

echo "AGENT_CATCH_UP_PROFILE_CALLS_V1=PASS\n";
