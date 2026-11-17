<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/agent-connection-gmail-thread-analysis-v1.php';

function gmail_thread_analysis_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$user = ['id'=>71];
$mail = [];
for ($i=1; $i<=7; $i++) {
    $id = 'msg-'.$i;
    $mail[$id] = ['id'=>$id,'threadId'=>'example-thread','internalDate'=>(string)(1000*$i),
        'labelIds'=>[$i===2?'SENT':($i===7?'DRAFT':'INBOX')],
        'payload'=>['mimeType'=>'text/plain','headers'=>[['name'=>'Subject','value'=>'Venue question '.$i]],
            'body'=>['data'=>base64_encode(($i===2?'Owner reply':str_repeat('x',2100))."\n> earlier quoted material")]]];
}
$reads = [];
$http = static function (string $method, string $url) use ($mail,&$reads): array {
    $path = parse_url($url,PHP_URL_PATH);
    if (str_ends_with($path,'/threads/example-thread')) {
        return ['status'=>200,'json'=>['id'=>'example-thread','messages'=>array_values(array_reverse($mail))]];
    }
    $id = basename($path);
    $reads[] = $id;
    return ['status'=>200,'json'=>$mail[$id]];
};
$result = ['category'=>'opportunity','priority'=>'high','state'=>'open','summary'=>'A venue asked about hosting a session.',
    'importance_reason'=>'They want to confirm a date this week.','next_step'=>'Review the venue date.',
    'followup_question'=>'Which date can you offer?','evidence_ref'=>3];
$model = static function (PDO $db, array $owner, string $prompt, Closure $current) use ($pdo,$user,$result): string {
    $data = json_decode($prompt,true,flags:JSON_THROW_ON_ERROR);
    gmail_thread_analysis_check($db===$pdo && $owner===$user,'Preserve the owner and database scope.');
    gmail_thread_analysis_check($data['trigger_ref']===1 && $data['partial_thread']===true,'Mark trigger and partial coverage.');
    gmail_thread_analysis_check($data['owner_sender_attention']==='review','Pass an explicit remembered sender preference.');
    gmail_thread_analysis_check(array_column($data['messages'],'direction')===['received','sent_by_owner','received','received'],
        'Include the exact trigger, recent evidence, and an owner reply.');
    gmail_thread_analysis_check(strlen($data['messages'][3]['body_excerpt'])===2000,'Bound message text.');
    gmail_thread_analysis_check($data['messages'][1]['body_excerpt']==='Owner reply','Exclude quoted material from analyzed text.');
    return json_encode($result,JSON_THROW_ON_ERROR);
};
$analysis = agent_connection_gmail_thread_analysis_v1($pdo,$user,'fake-access-token','example-thread','msg-1',$http,static fn():bool=>true,$model,'review');
gmail_thread_analysis_check($reads===['msg-1','msg-2','msg-5','msg-6'],'Fetch selected messages in chronological order.');
gmail_thread_analysis_check($analysis['evidence_message_id']==='msg-5' && $analysis['subject']==='Venue question 1',
    'Bind the evidence to an exact read provider message and use the server-known subject.');
gmail_thread_analysis_check(!isset($analysis['evidence_ref'],$analysis['body_excerpt']), 'Persist neither prompt refs nor source excerpts.');
gmail_thread_analysis_check($analysis['handoff']==='none' && $analysis['handoff_question']==='',
    'Existing analysis results default to staying in the Gmail report.');
$needsAttention = agent_connection_gmail_thread_result_v1(json_encode(['handoff'=>'needs_attention']+$result,JSON_THROW_ON_ERROR),[3=>'msg-5']);
gmail_thread_analysis_check($needsAttention['handoff']==='needs_attention' && $needsAttention['handoff_question']==='',
    'An actionable finding can call for owner attention.');
$agentWork = ['handoff'=>'agent_work','handoff_question'=>'What sound requirements apply to public jukebox sessions?'];
$work = agent_connection_gmail_thread_result_v1(json_encode($agentWork+$result,JSON_THROW_ON_ERROR),[3=>'msg-5']);
gmail_thread_analysis_check($work['handoff']==='agent_work' && $work['handoff_question']===$agentWork['handoff_question'],
    'A public research question can be forwarded with its source-bound finding.');

$claimChecks = 0;
$reads = [];
try {
    agent_connection_gmail_thread_analysis_v1($pdo,$user,'fake-access-token','example-thread','msg-1',$http,
        static function() use (&$claimChecks):bool { return ++$claimChecks<3; },$model);
    throw new RuntimeException('Revoked source access must abort.');
} catch (RuntimeException $error) {
    gmail_thread_analysis_check($error->getCode()===403 && $reads===['msg-1'],'Stop before any further read or inference after revocation.');
}

foreach ([['evidence_ref'=>90],['summary'=>'See https://example.org'],['state'=>'resolved'],['priority'=>true],
    ['handoff'=>'send'],['handoff'=>'agent_work'],['handoff_question'=>'What now?'],
    ['handoff'=>'agent_work','handoff_question'=>'See https://example.org'],
    ['handoff'=>'needs_attention','next_step'=>'','followup_question'=>''],
    ['handoff'=>'needs_attention','state'=>'resolved','next_step'=>'','followup_question'=>'']] as $invalid) {
    try {
        agent_connection_gmail_thread_result_v1(json_encode($invalid+$result,JSON_THROW_ON_ERROR),[3=>'msg-5']);
        throw new RuntimeException('The invalid finding was accepted.');
    } catch (InvalidArgumentException) {
        // A model answer cannot invent its evidence or attach live content.
    }
}
echo "Gmail thread analysis contract passed\n";
