<?php
declare(strict_types=1);

// Keep Profile session, conversation, ownership, Phone routing and SQL real.
// Only configured credentials and the closed package catalog are fixture data.
if(!defined('PA3_HANDOFF_HELPERS_ONLY'))define('PA3_HANDOFF_HELPERS_ONLY',true);
require_once dirname(__DIR__).'/profile-handoff-pa3.php';
require_once dirname(__DIR__,2).'/includes/profile-browser-phone-v1.php';
require_once dirname(__DIR__,2).'/includes/phone-browser-endpoint-v1.php';
require_once dirname(__DIR__,2).'/includes/phone-voice-conversation-v140.php';
require_once dirname(__DIR__,2).'/includes/phone-voice-stream-v150.php';
require_once dirname(__DIR__,2).'/includes/profile-calls-pa3c.php';

function save_setting(string $key,string $value): void{$GLOBALS['pa3Settings'][$key]=$value;}
function ai_encrypt_secret(string $value): string{return 'fixture-encrypted:'.base64_encode($value);}
function ai_decrypt_secret(string $value): string
{
    if(!str_starts_with($value,'fixture-encrypted:'))throw new RuntimeException('Unreadable fixture secret.');
    return base64_decode(substr($value,18),true)?:'';
}
function ai_encrypted_secret_state(string $value): string
{try{return ai_decrypt_secret($value)!==''?'ready':'empty';}catch(Throwable){return 'invalid';}}
function subscription_effective_entitlement_v340(array $user,string $key): array
{
    return ['enabled'=>!empty($user['is_active'])&&!in_array($key,$GLOBALS['profilePhoneDeniedEntitlements']??[],true),
        'limit'=>$key==='phone.numbers'?2:0,'unlimited'=>false];
}
function subscription_has_entitlement(?array $user,string $key): bool{return $user!==null&&subscription_effective_entitlement_v340($user,$key)['enabled'];}

function profile_phone_fixture_settings_v1(): void
{
    $GLOBALS['pa3Settings']=($GLOBALS['pa3Settings']??[])+['phone_twilio_account_sid'=>'AC'.str_repeat('a',32),
        'phone_twilio_auth_token'=>ai_encrypt_secret('profile-phone-carrier-fixture'),
        'phone_voice_webhook_url_v130'=>'https://cloud.example/api/phone-voice-inbound-v130.php',
        'phone_browser_api_key_sid_v1'=>'SK'.str_repeat('b',32),
        'phone_browser_api_key_secret_v1'=>ai_encrypt_secret('profile-phone-api-fixture-123456'),
        'phone_browser_twiml_app_sid_v1'=>'AP'.str_repeat('c',32)];
}
function profile_phone_fixture_reset_v1(): void
{
    pa3_reset();$p=db();$GLOBALS['profilePhoneDeniedEntitlements']=[];profile_phone_fixture_settings_v1();
    foreach(['profile_browser_phone_cancellations_v1','profile_browser_phone_calls_v1','profile_phone_settings_v1',
        'phone_browser_deliveries_v1','phone_browser_presence_v1','phone_browser_calls_v1','phone_browser_numbers_v1',
        'phone_voice_recordings_v130','phone_voice_calls_v130','phone_sms_threads_v110','phone_cloud_numbers_v100',
        'phone_voice_turns_v140','phone_voice_modes_v140','phone_voice_stream_routes_v150','phone_voice_stream_claims_v150',
        'profile_call_slots_pa3c','profile_calls_pa3c','profile_call_operations_pa3c','profile_call_deliveries_pa3c','user_blocks'] as $table)if(table_exists($table))$p->exec('DELETE FROM '.$table);
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $auto=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    foreach([
        'phone_cloud_numbers_v100'=>"id INTEGER PRIMARY KEY,provider VARCHAR(24),provider_sid VARCHAR(40),phone_e164 VARCHAR(20),owner_user_id INTEGER,agent_id INTEGER,status VARCHAR(24),voice_enabled INTEGER,sms_enabled INTEGER DEFAULT 0",
        'phone_cloud_requests_v100'=>"id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,phone_e164 VARCHAR(20),status VARCHAR(24)",
        'phone_sms_threads_v110'=>"id INTEGER PRIMARY KEY,phone_number_id INTEGER,owner_user_id INTEGER,agent_id INTEGER,contact_e164 VARCHAR(20),UNIQUE(phone_number_id,owner_user_id,agent_id,contact_e164)",
        'phone_sms_assignment_migrations_v111'=>'id INTEGER PRIMARY KEY',
        'phone_voice_calls_v130'=>"id $auto,provider VARCHAR(24),provider_call_sid VARCHAR(40),owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,caller_e164 VARCHAR(20),called_e164 VARCHAR(20),state VARCHAR(24) DEFAULT 'voicemail_offered',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_call_sid)",
        'phone_voice_recordings_v130'=>"id $auto,call_id INTEGER,owner_user_id INTEGER,provider VARCHAR(24),provider_recording_sid VARCHAR(40),recording_status VARCHAR(24),duration_seconds INTEGER,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_recording_sid)",
        'phone_voice_modes_v140'=>"phone_number_id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,mode VARCHAR(24),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'phone_voice_turns_v140'=>"id $auto,call_sid VARCHAR(40),step_number INTEGER,owner_user_id INTEGER,agent_id INTEGER,speech_hash CHAR(64),speech_text TEXT,response_text TEXT,state VARCHAR(16) DEFAULT 'processing',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(call_sid,step_number)",
        'phone_voice_stream_routes_v150'=>'phone_number_id INTEGER PRIMARY KEY,owner_user_id INTEGER,cloud_agent_id INTEGER,homeserver_agent_id INTEGER',
        'phone_voice_stream_claims_v150'=>'parent_call_sid VARCHAR(40) PRIMARY KEY,child_call_sid VARCHAR(40) UNIQUE,created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
        'user_blocks'=>'blocker_user_id INTEGER,blocked_user_id INTEGER,PRIMARY KEY(blocker_user_id,blocked_user_id)',
    ] as $table=>$columns)$p->exec('CREATE TABLE IF NOT EXISTS '.$table.'('.$columns.')');
    $p->exec('DELETE FROM phone_sms_assignment_migrations_v111');$p->exec('INSERT INTO phone_sms_assignment_migrations_v111 VALUES(1)');
    if(!column_exists('chat_conversations','created_at'))$p->exec('ALTER TABLE chat_conversations ADD COLUMN created_at DATETIME');
    $p->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(14,1,NULL,'Owner Phone Chat')");
    $p->exec("INSERT INTO phone_cloud_numbers_v100(id,provider,provider_sid,phone_e164,owner_user_id,agent_id,status,voice_enabled) VALUES
        (61,'twilio','PNaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','+16025550101',1,8,'ready',1),
        (62,'twilio','PNbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','+16025550102',4,9,'ready',1)");
    vp3_phone_workspace_ensure_schema_v1($p);vp3_profile_phone_install_v1($p);vp3_profile_browser_phone_install_v1($p);vp3_profile_call_ensure_schema_pa3c($p);
    vp3_phone_browser_number_policy_set_v1($p,61,true,false);
    vp3_profile_phone_save_v1($p,pa3_owner(),['enabled'=>true,'number_id'=>61,'revision'=>0]);
    pa3_context();
}
function profile_phone_fixture_input_v1(string $seed='request',int $cid=0): array
{return ['client_request_id'=>md5($seed),'conversation_id'=>$cid];}
function profile_phone_fixture_voice_form_v1(array $prepared,string $seed='parent'): array
{
    $call=vp3_profile_browser_phone_row_v1(db(),$prepared['call']['id']);
    return ['AccountSid'=>(string)setting('phone_twilio_account_sid'),'CallSid'=>'CA'.md5($seed),
        'From'=>'client:'.$call['identity'],'profile_call_intent'=>$prepared['connect_params']['profile_call_intent']];
}
function profile_phone_fixture_signature_v1(string $kind,array $form,string $public=''): string
{return vp3_phone_sms_twilio_signature_v110(vp3_phone_browser_callback_v1($kind,$public!==''?['call'=>$public]:[]),$form,'profile-phone-carrier-fixture');}
function profile_phone_fixture_voice_v1(array $prepared,string $seed='parent'): array
{
    $form=profile_phone_fixture_voice_form_v1($prepared,$seed);
    return [vp3_phone_browser_voice_v1(db(),$form,profile_phone_fixture_signature_v1('voice',$form)),$form];
}
function profile_phone_fixture_parent_v1(string $sid,string $state='completed',string $duration='12'): array
{
    $form=['AccountSid'=>(string)setting('phone_twilio_account_sid'),'CallSid'=>$sid,'CallStatus'=>$state,'CallDuration'=>$duration];
    return vp3_profile_browser_phone_parent_status_v1(db(),$form,profile_phone_fixture_signature_v1('status',$form));
}
function profile_phone_fixture_child_v1(array $phone,string $state='answered',string $duration='0',bool $complete=false): array
{
    $child='CA'.md5($phone['public_id'].'-child');
    $form=['AccountSid'=>(string)setting('phone_twilio_account_sid'),'CallSid'=>$complete?$phone['provider_call_sid']:$child];
    $form+=$complete?['DialCallSid'=>$child,'DialCallStatus'=>$state,'DialCallDuration'=>$duration]
        :['ParentCallSid'=>$phone['provider_call_sid'],'CallStatus'=>$state,'CallDuration'=>$duration];
    return vp3_phone_browser_status_v1(db(),$form,profile_phone_fixture_signature_v1($complete?'complete':'status',$form,$phone['public_id']),$phone['public_id'],$complete);
}
function profile_phone_fixture_denied_v1(callable $work,string $label,?string $code=null): void
{
    try{$work();}catch(VP3ProfileBrowserPhoneException|VP3ProfileAgentPublicException|VP3ProfileHandoffException|VP3PhoneWorkspaceException $e){
        $actual=$e->publicCode??$e->errorCode??'';pa3_check($code===null||$actual===$code,$label.' ('.$actual.')');return;
    }
    throw new RuntimeException($label.' unexpectedly succeeded.');
}
