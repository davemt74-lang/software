<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/recommendations.php';
require dirname(__DIR__).'/includes/chat-engine.php';
require dirname(__DIR__).'/includes/agent-brain-context-v142.php';
require dirname(__DIR__).'/includes/ai-settings.php';
require dirname(__DIR__).'/includes/ai-stream-v121.php';
require dirname(__DIR__).'/includes/vp3-analytics-chat.php';

$checks=0;
function verify(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function db(): PDO {static $pdo;return $pdo??=new PDO('sqlite::memory:');}
function setting(string $key,mixed $fallback=''): mixed {return ['ai_provider'=>'openai','ai_openai_enabled'=>'0'][$key]??$fallback;}
function ai_v100_rate_limit(...$args): void {}
function agent_brain_default_soul(): array {return [];}
$allowed=true;$schemaReady=true;$reads=[];
function personal_capability_has_v242(string $key,array $user): bool {global $allowed;return $allowed;}
function vp3_radar_schema_ready(PDO $pdo): bool {global $schemaReady;return $schemaReady;}
function vp3_analytics_dashboard_state_v2(PDO $pdo,array $user,int $propertyId=0,int $days=30): array {
    global $reads;$reads[]=[(int)$user['id'],$days];
    return ['ready'=>true,'stats'=>['sessions'=>((int)$user['id']===7?12:104),'human_sessions'=>((int)$user['id']===7?9:100),'agent_sessions'=>((int)$user['id']===7?3:4),'page_views'=>30],'property_stats'=>[]];
}

$reported=[
    'Are you listening',
    'Home Server offline okay you can stop',
    'How many website visitors have I had',
    'State reconciliation require',
    'State reconciliation required last reconciled not yet last continuity changes zero created zero updated zero retired mirrors stop zero stop listening',
];
foreach($reported as $query){
    verify(!track_is_music_request($query),'Reported query must not request songs: '.$query);
    verify(track_detect_moods($query)===[],'Reported query must not accidentally request a mood: '.$query);
    verify(track_media_suggestions($query)===[],'Reported query must not generate a playlist: '.$query);
}
foreach(['Play some music','Recommend songs','Next track','Play a romantic playlist'] as $query)verify(track_is_music_request($query),'Explicit music request preserved: '.$query);
verify(track_detect_moods('created and updated')===[],'date inside updated is not romantic');
verify(track_detect_moods('romantic music')===['romantic'],'Explicit romantic request remains available');

$context=[
    ['source'=>'homeserver:reconciliation','text'=>'State: reconciliation required Last reconciled: not yet'],
    ['source'=>'homeserver:execution-routing','text'=>'HomeServer: offline'],
    ['source'=>'system-apps:canonical','text'=>'HomeServer: Connected Owned: 0'],
    ['source'=>'agent-brain:activity-history','text'=>'Old activity'],
    ['source'=>'agent:identity','text'=>'Longfellow'],
    ['source'=>'knowledge:12','text'=>'Authorized relevant material'],
];
$filtered=chat_response_context('Are you listening',$context);
verify(array_column($filtered,'source')===['agent:identity','knowledge:12'],'Ordinary questions receive no status or activity dump');
verify(chat_response_context('Are you listening',[['source'=>'database:track:1','title'=>'Songbird','text'=>'A recording']])===[],'Attention checks do not receive unrelated catalog records');
verify(count(chat_response_context('Tell me about Songbird',[['source'=>'database:track:1','title'=>'Songbird','text'=>'A recording']]))===1,'Named-song questions retain the authorized song');
verify(count(chat_response_context('Why is my HomeServer offline?',$context))===5,'Explicit status question retains diagnostics but not unrelated history');
verify(in_array('agent-brain:activity-history',array_column(chat_response_context('Show my activity history',$context),'source'),true),'Explicit history question retains history');
verify(array_column(chat_context_public_items($context),'source')===['knowledge:12'],'Internal diagnostics are not public citations');
verify(chat_local_answer('Are you listening',$context)==='Yes, your message reached me.','Attention check acknowledges receipt without claiming live hearing');

$failure=['ok'=>false,'provider'=>'openai','error'=>'Not fully configured: secret-credential-must-not-appear'];
foreach(['How many website visitors have I had','What should I do next?','Why is my HomeServer offline?'] as $query){
    $answer=chat_local_answer($query,$context,$failure);
    verify(!str_contains($answer,'reconciliation required')&&!str_contains($answer,'HomeServer:')&&!str_contains($answer,'Old activity'),'Provider failure cannot repeat unrelated records');
    verify(str_contains($answer,'AI service')&&!str_contains($answer,'secret-credential'),'Safe configuration guidance without credential disclosure');
}
verify(str_contains(chat_local_answer('Hello',$context,['provider'=>'local']),'search-only mode'),'Local retrieval mode is explicit');
verify(str_contains(chat_local_answer('Hello',$context,['quota_exhausted'=>true]),'allowance or access check failed'),'Allowance failure is explicit without invented counts');
verify(str_contains(chat_local_answer('Hello',$context,['error'=>'timeout']),'could not get an AI answer'),'Transport failure cannot masquerade as a generated answer');
verify(chat_remote_answer('How many visitors?',[],$context,['id'=>7])===null,'Configured provider failure still returns no AI answer');
verify(str_contains(chat_local_answer('How many visitors?',$context),'disabled on the server'),'Nonstream provider outcome identifies the disabled server provider');
$stream=ai_v121_stream_chat_response('How many visitors?',[],$context,['id'=>7],static function(): void {throw new RuntimeException('Disabled provider cannot stream');});
verify(empty($stream['ok'])&&str_contains(chat_local_answer('How many visitors?',$context,$stream),'disabled on the server'),'Streaming provider outcome reaches the same specific fallback');
$history=ai_history_messages([
    ['role'=>'assistant','message'=>"Here’s the useful information I found:\n\n• HomeServer: offline"],
    ['role'=>'user','message'=>'Why did you say HomeServer: offline?'],
    ['role'=>'assistant','message'=>'The server cannot read its saved AI credential. The site administrator needs to restore credential access in AI / API Settings.'],
    ['role'=>'assistant','message'=>"No, I don't have access to the LLM right now. The server can't read its saved AI credential, so I can only send you this fixed message."],
    ['role'=>'assistant','message'=>'A real relevant answer.'],
]);
verify(count($history)===2&&$history[0]['role']==='user','Known broken assistant fallback does not poison new turns; user messages remain');
$quoted="The server can't read its saved AI credential";
$history=ai_history_messages([['role'=>'user','message'=>$quoted],['role'=>'assistant','message'=>'We discussed an earlier connection incident.']]);
verify(count($history)===2&&$history[0]['content']===$quoted,'User quotes and ordinary historical discussion remain intact');
verify(str_contains(ai_system_prompt([]),'You are generating this reply through an AI provider for this request.'),'Generated replies are grounded in the current invocation rather than historical outage notices');

foreach(['How many website visitors have I had','How many visitors today?','Number of visits this week','Website visitors','My website traffic'] as $query)verify(vp3_analytics_chat_intent($query),'Visitor question routes to existing analytics: '.$query);
verify(!vp3_analytics_chat_intent('How many AI agent visits?'),'Agent Radar retains its own route');
$result=vp3_analytics_chat_tool('How many website visitors have I had',['id'=>7]);
verify($result['handled']&&str_contains($result['answer'],'9 human visits')&&str_contains($result['answer'],'last 30 days'),'Visitor answer uses recorded human sessions and an explicit period');
verify(str_contains($result['answer'],'not unique people'),'Sessions are not mislabeled as unique people');
verify($reads===[[7,30]],'Analytics read remains bound to the signed-in owner');
$allowed=false;$before=count($reads);$denied=vp3_analytics_chat_tool('How many visitors today?',['id'=>8]);
verify(!$denied['handled']&&count($reads)===$before,'Denied analytics cannot read another owner’s counts');
$allowed=true;$schemaReady=false;$unavailable=vp3_analytics_chat_tool('How many visitors?',['id'=>7]);
verify($unavailable['handled']&&str_contains($unavailable['answer'],'cannot verify')&&!str_contains($unavailable['answer'],'0 visits'),'Missing tracking is not reported as zero visitors');
// Load the actual router and result authorization functions with only their
// module bootstrap removed; their dependencies are the fixtures above.
$router=file_get_contents(dirname(__DIR__).'/includes/agent-tool-authorization-v400.php');
eval(preg_replace('/^require_once[^\n]*\n/m','',substr($router,5)));
$schemaReady=true;$routed=vp3_agent_tool_execute_query_v400('How many website visitors have I had',['id'=>7]);
verify($routed['handled']&&str_contains($routed['answer'],'9 human visits'),'Canonical Chat and voice router actually reaches Analytics');
verify(($routed['authorization']['principal_user_id']??0)===7,'Canonical Analytics result passes existing principal authorization');
echo "CHAT_RESPONSE_RELEVANCE=PASS ($checks assertions)\n";
