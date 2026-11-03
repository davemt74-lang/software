<?php
declare(strict_types=1);

/** Real native authority, public messages, marker deliveries and canonical inbox.
 * The inherited fixture substitutes only external speech/media/inference I/O.
 * VP3_TEST_MYSQL_DSN/USER/PASS selects an isolated real MySQL database.
 */
require_once __DIR__.'/profile-visitor-speech-pa4a.php';
require_once dirname(__DIR__,2).'/includes/profile-attention-pa4b.php';
require_once dirname(__DIR__,2).'/includes/profile-event-projection-pa4b.php';
require_once dirname(__DIR__,2).'/includes/agent-event-infrastructure-v1920.php';
require_once dirname(__DIR__,2).'/includes/cognitive-domain-integration-v2380.php';

function pp_reset(): void
{
    $p=db();if($p->inTransaction())$p->rollBack();$p->afterSql=null;$p->failSql='';
    foreach(['profile_event_projections_pa4b','profile_attention_operations_pa4b','profile_attention_deliveries_pa4b','agent_chat_archive','agent_event_inbox'] as $table)if(table_exists($table))$p->exec('DELETE FROM '.$table);
    ps_reset();
    if(!column_exists('notifications','read_at'))$p->exec('ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL');
    $p->exec("INSERT INTO role_permissions(role,permission_key) VALUES('member','agent_brain.access')");
    vp3_profile_attention_ensure_schema_pa4b($p);vp3_profile_projection_ensure_schema_pa4b($p);agent_event_ensure_schema_v1920($p);
    $auto=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'LONGTEXT':'TEXT';
    $p->exec("CREATE TABLE IF NOT EXISTS agent_chat_archive(id $auto,user_id INTEGER NOT NULL,user_agent_id INTEGER NULL,conversation_id BIGINT NULL,source_message_id BIGINT NOT NULL,role VARCHAR(20) NOT NULL,input_mode VARCHAR(20) NOT NULL DEFAULT 'text',message_text $text NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(user_id,source_message_id))");
}
function pp_message(string $text='PUBLIC_VISITOR_STATEMENT',int $visitor=2,string $mode='auto'): array
{
    $m=ps_message($text,$mode,ps_context($visitor));$turn=vp3_profile_handoff_row_pa3(db(),(int)$m['response']['handoff']['turn_id']);
    $event=vp3_profile_projection_message_event_pa4b(db(),1,(int)$turn['visitor_message_id']);
    return $m+['turn'=>$turn,'event_id'=>$event,'source'=>vp3_profile_projection_source_pa4b(db(),1,$event)];
}
function pp_delivery(array $turn,array $extra=[]): array
{
    return vp3_profile_handoff_delivery_pa3(db(),ps_owner(),array_replace(['turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],'tab_id'=>str_repeat('a',32),'chat_conversation_id'=>11,'chat_agent_id'=>7],$extra));
}
function pp_obligation(int $event,string $kind='event_inbox',int $message=0): array
{
    $q=db()->prepare('SELECT * FROM profile_event_projections_pa4b WHERE profile_event_id=? AND destination_kind=?'.($message>0?' AND chat_message_id=?':'').' ORDER BY id');$q->execute($message>0?[$event,$kind,$message]:[$event,$kind]);return $q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Projection intent missing.');
}
function pp_reply(array $turn,string $message='OWNER_PUBLIC_REPLY',array $extra=[]): array
{
    return vp3_profile_handoff_owner_action_pa3(db(),ps_owner(),array_replace(['operation'=>'reply','turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],'conversation_id'=>(int)$turn['conversation_id'],'chat_conversation_id'=>11,'chat_agent_id'=>7,'request_id'=>bin2hex(random_bytes(16)),'message'=>$message],$extra));
}
function pp_no_external_snapshot(): array
{
    return ['model'=>count($GLOBALS['pa3ModelCalls']),'speech'=>count($GLOBALS['psProviderCalls']),'media'=>count($GLOBALS['pa3cProviderCalls']),'public_messages'=>pa3_count('profile_agent_messages'),'jobs'=>pa3_count('agent_workflow_runs'),'receipts'=>pa3_count('agent_workflow_receipts'),'memory'=>pa3_count('agent_memory_items')];
}
