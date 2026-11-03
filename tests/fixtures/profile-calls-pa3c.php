<?php
declare(strict_types=1);

/** Real native identity, PA3 authority, SQL and canonical jobs; only media provider/config are simulated. */
require_once __DIR__.'/profile-handoff-pa3.php';

function vp3_profile_call_livekit_readiness_pa3c(): array
{
    return ['ready'=>$GLOBALS['pa3cReady']??true,'reason'=>($GLOBALS['pa3cReady']??true)?'':'disabled','configured'=>true,'enabled'=>$GLOBALS['pa3cReady']??true,'human_only_acknowledged'=>true,'api_verified_at_ms'=>vp3_profile_handoff_now_pa3(),'config_fingerprint'=>$GLOBALS['pa3cFingerprint']??hash('sha256','original-provider')];
}
function vp3_profile_call_livekit_snapshot_pa3c(): array
{
    $fingerprint=vp3_profile_call_livekit_readiness_pa3c()['config_fingerprint'];return ['encrypted'=>'encrypted-fixture-provider:'.$fingerprint,'config_fingerprint'=>$fingerprint];
}
require_once dirname(__DIR__,2).'/includes/profile-calls-pa3c.php';

function pa3c_reset(): void
{
    $p=db();if($p->inTransaction())$p->rollBack();$p->afterSql=null;$p->failSql='';
    foreach(['profile_call_operations_pa3c','profile_call_deliveries_pa3c','profile_calls_pa3c','profile_call_slots_pa3c'] as $table)if(table_exists($table))$p->exec('DELETE FROM '.$table);
    if(table_exists('user_blocks'))$p->exec('DELETE FROM user_blocks');
    pa3_reset();vp3_profile_call_ensure_schema_pa3c($p);
    // Canonical setup/upgrade owns this social schema in production; no call request initializes it.
    $p->exec('CREATE TABLE IF NOT EXISTS user_blocks(blocker_user_id INTEGER NOT NULL,blocked_user_id INTEGER NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(blocker_user_id,blocked_user_id))');
    $GLOBALS['pa3cReady']=true;$GLOBALS['pa3cFingerprint']=hash('sha256','original-provider');$GLOBALS['pa3cProviderCalls']=[];$GLOBALS['pa3cProviderHook']=null;$GLOBALS['pa3cProviderMode']='';$GLOBALS['pa3cRooms']=[];
    vp3_profile_handoff_presence_pa3($p,pa3_owner(),['tab_id'=>str_repeat('a',32),'chat_conversation_id'=>11,'chat_agent_id'=>7,'visible'=>true,'presence_sequence'=>1]);
}
function pa3c_context(int $visitor=0,string $browser='b'): array{return pa3_context($visitor,$browser);}
function pa3c_owner(): array{return pa3_owner();}
function pa3c_start(?array $context=null): array
{
    $context??=pa3c_context();$started=vp3_profile_agent_public_start_v1(db(),$context);return ['context'=>$context,'conversation_id'=>(int)$started['conversation']['conversation_id']];
}
function pa3c_offer(string $role='visitor',?array $ctx=null,string $mode='audio'): array
{
    $started=pa3c_start($ctx);$input=['operation'=>'offer','request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>$started['conversation_id'],'mode'=>$mode];
    $result=$role==='visitor'?vp3_profile_call_visitor_action_pa3c(db(),$started['context'],$input):vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),$input+['chat_conversation_id'=>11,'chat_agent_id'=>7]);
    return $result+['context'=>$started['context']];
}
function pa3c_owner_input(array $call,string $operation,array $extra=[]): array
{
    $call=$call['call']??$call;
    $delivery=vp3_profile_call_owner_delivery_pa3c(db(),pa3c_owner(),['call_id'=>(int)$call['call_id'],'revision'=>(int)$call['revision'],'chat_conversation_id'=>11,'chat_agent_id'=>7]);
    return $extra+['operation'=>$operation,'request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>(int)$call['conversation_id'],'call_id'=>(int)$call['call_id'],'revision'=>(int)$call['revision'],'chat_conversation_id'=>11,'chat_agent_id'=>7,'delivery_id'=>$delivery['delivery_id']];
}
function pa3c_visitor_input(array $call,string $operation,array $extra=[]): array
{
    $call=$call['call']??$call;return $extra+['operation'=>$operation,'request_id'=>bin2hex(random_bytes(16)),'conversation_id'=>(int)$call['conversation_id'],'call_id'=>(int)$call['call_id'],'revision'=>(int)$call['revision']];
}
function pa3c_provider(string $operation,array $binding,array $options=[]): array
{
    if(db()->inTransaction())throw new RuntimeException('Provider I/O occurred inside a database transaction.');
    $GLOBALS['pa3cProviderCalls'][]=compact('operation','binding','options');if($hook=$GLOBALS['pa3cProviderHook'])$hook($operation,$binding,$options);
    $now=vp3_profile_handoff_now_pa3();$room=$binding['room_name'];$mode=$GLOBALS['pa3cProviderMode'];
    if($operation==='create'){
        $GLOBALS['pa3cRooms'][$room]=['sid'=>'RM_'.substr($binding['public_id'],0,20),'owner'=>false,'visitor'=>false];
        if($mode==='lost_create')throw new RuntimeException('SECRET provider transport error');
    }
    if($operation==='cleanup'){
        if($mode==='cleanup_error')throw new RuntimeException('SECRET provider cleanup error');
        unset($GLOBALS['pa3cRooms'][$room]);return ['status'=>'closed','owner_revoked'=>true,'visitor_revoked'=>true,'room_absent'=>true,'revoked_before_sec'=>max((int)$binding['revoke_before_sec'],intdiv($now,1000)+5),'owner_revoked_before_sec'=>intdiv($now,1000)+5,'visitor_revoked_before_sec'=>intdiv($now,1000)+5,'observed_at_ms'=>$now];
    }
    if($operation==='token'){
        if($mode==='token_error')throw new RuntimeException('SECRET provider token error');
        return ['server_url'=>'wss://fixture.livekit.cloud','participant_token'=>'SECRET_PARTICIPANT_TOKEN','participant_identity'=>$binding[$options['role'].'_identity'],'peer_identity'=>$binding[($options['role']==='owner'?'visitor':'owner').'_identity'],'expires_at_ms'=>$options['expires_at_ms'],'issued_at_ms'=>$options['issued_at_ms'],'room_sid'=>$binding['room_sid'],'config_fingerprint'=>$binding['provider_config_fingerprint']];
    }
    if(!isset($GLOBALS['pa3cRooms'][$room]))return ['status'=>'absent','room_sid'=>'','room_name'=>$room,'config_fingerprint'=>$binding['provider_config_fingerprint'],'owner_connected'=>false,'visitor_connected'=>false,'participants'=>[],'recording'=>false,'observed_at_ms'=>$now];
    $saved=$GLOBALS['pa3cRooms'][$room];$participants=[];foreach(['owner','visitor'] as $role)if($saved[$role])$participants[]=['identity'=>$binding[$role.'_identity'],'sid'=>'PA_'.$role,'state'=>'active','microphone'=>true,'camera'=>$binding['mode']==='video'];
    return ['status'=>'ready','room_sid'=>$saved['sid'],'room_name'=>$room,'config_fingerprint'=>$binding['provider_config_fingerprint'],'owner_connected'=>$saved['owner'],'visitor_connected'=>$saved['visitor'],'participants'=>$participants,'recording'=>false,'observed_at_ms'=>$now];
}
