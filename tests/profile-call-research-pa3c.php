<?php
declare(strict_types=1);

define('PA3_HANDOFF_HELPERS_ONLY',true);
function vp3_profile_call_now_pa3c(): int { return $GLOBALS['pa3Now']; }
function ai_active_provider(): string { return 'openai'; }
function ai_provider_ready(string $provider): bool { return $provider==='openai'; }
function ai_provider_api_key(string $provider): string { return 'test-only'; }
function ai_provider_model(string $provider): string { return 'test-model'; }
function ai_curl_json(string $endpoint,array $headers,array $payload,int $timeout): array
{
    $GLOBALS['researchRequests'][]=$payload;
    if ($hook=$GLOBALS['researchHook']??null) $hook();
    $annotations=!empty($GLOBALS['researchNoSources'])?[]:[
        ['type'=>'url_citation','url'=>'https://example.org/record','title'=>'Public record'],
        ['type'=>'url_citation','url'=>'javascript:alert(1)','title'=>'Invalid source'],
    ];
    return ['ok'=>true,'data'=>['output'=>[['content'=>[['type'=>'output_text','text'=>'Verified public finding.','annotations'=>$annotations]]]]]];
}

require __DIR__.'/profile-handoff-pa3.php';
require_once dirname(__DIR__).'/includes/agent-compute-v020.php';
require_once dirname(__DIR__).'/includes/agent-compute-v023.php';
require_once dirname(__DIR__).'/includes/profile-call-activity-pa3c.php';

putenv('VP3_PROFILE_CALL_AGENT_ENABLED=1');
putenv('VP3_LIVEKIT_URL=ws://127.0.0.1:1');
putenv('VP3_LIVEKIT_API_KEY=test-media');
putenv('VP3_LIVEKIT_API_SECRET=test-media-secret');
putenv('VP3_LIVEKIT_AGENT_NAME=test-profile-worker');
putenv('VP3_MEETING_WORKER_SECRET='.str_repeat('x',32));

pa3_reset();
chat_settings_ensure_schema_v237();
vp3_profile_call_ensure_schema_pa3c($pdo);
vp3_profile_call_activity_ensure_schema_pa3c($pdo);
$pdo->exec('CREATE TABLE agent_compute_preferences (user_id INTEGER PRIMARY KEY,preference VARCHAR(24))');
$pdo->exec('CREATE TABLE agent_compute_overrides (user_id INTEGER,agent_id INTEGER,preference VARCHAR(24),PRIMARY KEY(user_id,agent_id))');
$pdo->exec('CREATE TABLE homeserver_connections (user_id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE artist_transcript_sessions_v172 (id INTEGER PRIMARY KEY,client_session_key VARCHAR(64),knowledge_id INTEGER,agent_memory_id INTEGER,project_note_id INTEGER)');
$pdo->exec('CREATE TABLE artist_transcript_segments_v172 (id INTEGER PRIMARY KEY,client_segment_key VARCHAR(64),segment_type VARCHAR(20))');
$pdo->exec("INSERT INTO role_permissions VALUES ('member','artist_listening.access')");
$pdo->exec("INSERT INTO team_user_presence (user_id,presence_mode,social_chat_enabled,agent_assisted_phone_chat_enabled) VALUES (1,'online',1,1)");

pa3_check(vp3_profile_call_owner_assistance_allowed_pa3c($pdo,1,7),'an opted-in unpaired owner with transcription access may use Cloud call assistance');
$pdo->exec('INSERT INTO homeserver_connections VALUES (1)');
pa3_check(!vp3_profile_call_owner_assistance_allowed_pa3c($pdo,1,7),'paired Automatic fails closed without a guest-cloud processing choice');
$pdo->exec("INSERT INTO agent_compute_preferences VALUES (1,'vp3_cloud')");
pa3_check(vp3_profile_call_owner_assistance_allowed_pa3c($pdo,1,7),'explicit Cloud permits assistance without a HomeServer network request');
$pdo->exec("INSERT INTO agent_compute_overrides VALUES (1,7,'homeserver_only')");
pa3_check(!vp3_profile_call_owner_assistance_allowed_pa3c($pdo,1,7),'the selected Profile Agent private compute override wins');
$pdo->exec('DELETE FROM agent_compute_overrides');

pa3_presence();
$ctx=pa3_context();
$message=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'PRIVATE_CALL_TRANSCRIPT',0,[
    'async'=>true,'client_request_id'=>str_repeat('d',32),'chat_mode'=>'live',
]);
$turn=pa3_turn($message);
$cid=(int)$turn['conversation_id'];
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($turn,'claim'));
$ring=vp3_profile_call_action_pa3c($pdo,1,$cid,'owner','offer',['request_id'=>str_repeat('a',32),'disclosed'=>true]);
$id=$ring['call']['id'];
vp3_profile_call_action_pa3c($pdo,1,$cid,'visitor','accept',['call_id'=>$id,'revision'=>1,'disclosed'=>true],$ctx);
foreach (['owner','visitor'] as $side) {
    $clientId=substr(hash('sha256',$side),0,32);
    vp3_profile_call_action_pa3c($pdo,1,$cid,$side,'token',['call_id'=>$id,'revision'=>2,'client_id'=>$clientId],$side==='visitor'?$ctx:null);
    vp3_profile_call_action_pa3c($pdo,1,$cid,$side,'heartbeat',['call_id'=>$id,'client_id'=>$clientId],$side==='visitor'?$ctx:null);
}
$owner=pa3_owner();
$current=static fn()=>vp3_profile_call_activity_owner_pa3c($pdo,1,$id);
$GLOBALS['researchRequests']=[];
$GLOBALS['researchHook']=static function()use($pdo,$current): void {
    pa3_check(vp3_profile_call_activity_view_pa3c($pdo,$current(),0)['state']==='researching','a durable running request lights research during the provider request');
};
$publicQuestion='When did the public museum open?';
$first=vp3_profile_call_research_pa3c($pdo,$owner,$id,str_repeat('a',20),$publicQuestion);
pa3_check($first['research_status']==='complete','cited public research completes on the exact active call');
$prompt=json_encode($GLOBALS['researchRequests'],JSON_THROW_ON_ERROR);
pa3_check(str_contains($prompt,$publicQuestion)&&!str_contains($prompt,'PRIVATE_CALL_TRANSCRIPT')&&!str_contains($prompt,'PRIVATE_CHAT_SECRET')&&!str_contains($prompt,$id),'only the explicit question reaches public research');
$stored=vp3_profile_call_activity_view_pa3c($pdo,$current(),0);
pa3_check(count($stored['research'])===1&&$stored['research'][0]['sources']===[['url'=>'https://example.org/record','title'=>'Public record']],'the owner drawer reads the exact persisted provider citation');
$duplicate=vp3_profile_call_research_pa3c($pdo,$owner,$id,str_repeat('a',20),$publicQuestion);
pa3_check($duplicate===$first&&count($GLOBALS['researchRequests'])===1,'same request and question replay without rerunning public research');
try {
    vp3_profile_call_research_pa3c($pdo,$owner,$id,str_repeat('a',20),'Other question');
    throw new LogicException('Research request reused');
} catch (RuntimeException $error) {
    pa3_check($error->getCode()===409,'same request ID cannot be rebound');
}

$GLOBALS['pa3Now']+=16000;
$GLOBALS['researchHook']=static function()use($pdo,$id): void {
    vp3_profile_call_assistance_toggle_pa3c($pdo,1,$id,false,1);
    vp3_profile_call_assistance_toggle_pa3c($pdo,1,$id,true,2);
};
$late=vp3_profile_call_research_pa3c($pdo,$owner,$id,str_repeat('b',20),'Find the opening date');
$saved=vp3_profile_call_activity_view_pa3c($pdo,$current(),0);
pa3_check($late['research_status']==='failed'&&$saved['research'][1]['summary']===''&&$saved['research'][1]['sources']===[], 'pause and resume fence the late provider response');
pa3_check($current()['status']==='live'&&$saved['assistance_enabled'],'pausing does not end the human call and allows a fresh resume');
pa3_check(chat_settings_agent_assisted_phone_chat_enabled_v237($pdo,1),'the per-call switch preserves future-call settings');
try {
    vp3_profile_call_activity_owner_pa3c($pdo,4,$id);
    throw new LogicException('Foreign owner read call');
} catch (RuntimeException $error) {
    pa3_check($error->getCode()===404,'another owner cannot read research from this call');
}
echo 'PROFILE_CALL_RESEARCH_PA3C=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
