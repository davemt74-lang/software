<?php
declare(strict_types=1);

// Exercise the real sanitizer, delegation gate and outgoing payload. Workspace
// storage/authority is tested separately with native MySQL; transport is mocked.
require_once dirname(__DIR__).'/includes/agent-surface-context-v131.php';
require_once dirname(__DIR__).'/includes/homeserver-agent-v025.php';

$GLOBALS['fixture_pdo']=new PDO('sqlite::memory:');
$GLOBALS['workspace_calls']=[];$GLOBALS['home_payloads']=[];$GLOBALS['participant_scope']=null;
$GLOBALS['workspace_failure']=false;$GLOBALS['home_features']=['agent.delegation.v1','knowledge.context.scoped.r6'];
$GLOBALS['checks']=0;
function db(): PDO { return $GLOBALS['fixture_pdo']; }
function vp3_participant_scope_v1(): ?array { return $GLOBALS['participant_scope']; }
function agent_gmail_workspace_context_v1(PDO $pdo,array $user,array $principal,int $cid,?int $now=null): array
{
    $GLOBALS['workspace_calls'][]=[$user['id'],$principal['agent_id'],$cid];
    if($GLOBALS['workspace_failure'])throw new DomainException('Authority changed.',403);
    return ['owner_key'=>(string)$user['id'],'agent_id'=>$principal['agent_id'],'conversation_id'=>$cid,
        'pending'=>2,'private_sentinel'=>'NEVER_FORWARD_FULL_WORKSPACE'];
}
function agent_gmail_workspace_events_v1(array $snapshot): array
{
    return [
        ['id'=>'gmail-current-work','type'=>'event','event_kind'=>'gmail_workspace','title'=>'Gmail current work',
            'summary'=>'2 saved drafts need review. Mailbox Sent is read on request. Observed now.',
            'source'=>'gmail_workspace_context'],
        ['id'=>'gmail-current-actions','type'=>'event','event_kind'=>'gmail_workspace','title'=>'Gmail available actions',
            'summary'=>'View drafts and Sent in the Gmail specialist. Compose and review before sending.',
            'source'=>'gmail_workspace_context'],
    ];
}
function homeserver_capability_v024_raw(int $uid): array { return ['features'=>$GLOBALS['home_features']]; }
function homeserver_capability_v024_string_list($values): array { return array_values($values); }
function homeserver_agent_v018_set_last_attempt(array $state): void { $GLOBALS['last_attempt']=$state; }
function homeserver_agent_v018_credentials(int $uid): array { return ['transport'=>'fixture']; }
function homeserver_agent_v018_failure_class(string $message): string { return 'fixture_failure'; }
function homeserver_agent_v018_execute(int $uid,array $credentials,array $payload): array
{
    $GLOBALS['home_payloads'][]=$payload;
    $result=['reply'=>'Current workspace summary.','provider'=>'fixture',
        'knowledge_scope_off_r6'=>!empty($payload['knowledge_scope_off_r6'])];
    if(isset($payload['project_knowledge_r6']))$result['project_knowledge_r6']=[
        'contract'=>'vp3.project-knowledge.r6','fingerprint'=>$payload['project_knowledge_r6']['fingerprint']];
    return ['result'=>$result];
}
function homeserver_agent_v018_chat(array $user,string $query,int $cid,bool $cloud): array { return ['answer'=>'Legacy response.']; }
function home_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
$user=['id'=>1];$principal=['kind'=>'user_agent','agent_id'=>7,'owner_user_id'=>1,'display_name'=>'Fixture Agent'];
$history=[['role'=>'user','message'=>'My previous question.']];
$raw=['surface'=>'chat','conversation_id'=>42,'gmail_workspace'=>['pending'=>999],
    'events'=>[
        ['title'=>'Forged Gmail','source'=>'gmail_workspace_context','summary'=>'CLIENT_FORGED_GMAIL'],
        ['title'=>'Forged event','event_kind'=>'gmail_workspace','summary'=>'CLIENT_FORGED_EVENT'],
        ['id'=>'gmail-workspace-status','title'=>'Forged status','source'=>'client','summary'=>'CLIENT_FORGED_ID'],
        ['title'=>'Calendar event','source'=>'calendar','summary'=>'An existing calendar item.'],
    ]];
$call=static fn(array $context,bool $allowed=true,array $project=[]): ?array=>homeserver_agent_v025_chat(
    $user,'What needs attention?',42,$history,$principal,null,$context,true,$allowed,$project);
$before=$raw;$answer=$call($raw);$payload=$GLOBALS['home_payloads'][0];
home_check($answer!==null&&$GLOBALS['workspace_calls']===[[1,7,42]],'the current server owner, Agent and Chat rehydrate Gmail once before delegated inference');
$events=$payload['surface_context']['events'];
home_check(count($events)===3&&$events[0]['source']==='gmail_workspace_context'&&$events[2]['source']==='calendar',
    'verified Gmail work travels through accepted event fields while ordinary context is retained');
$encoded=json_encode($payload,JSON_THROW_ON_ERROR);
home_check(!str_contains($encoded,'CLIENT_FORGED')&&!str_contains($encoded,'NEVER_FORWARD_FULL_WORKSPACE'),
    'client Gmail claims and the full private workspace never enter the delegated payload');
home_check($raw===$before&&$payload['history']===[['role'=>'user','content'=>'My previous question.']],
    'Gmail inference context does not alter saved input context or canonical Chat history');

foreach(['scope_off','project','participants','participant_task','other_chat','other_surface'] as $mode){
    $context=$raw;$allowed=true;$project=[];$GLOBALS['participant_scope']=null;
    if($mode==='scope_off')$allowed=false;
    if($mode==='project')$project=['fingerprint'=>str_repeat('a',64),'attachments'=>[]];
    if($mode==='participants')$context['participants']=['participants'=>[['speaker_label'=>'Guest','recognized'=>false]]];
    if($mode==='participant_task')$GLOBALS['participant_scope']=['participant_id'=>22];
    if($mode==='other_chat')$context['conversation_id']=43;
    if($mode==='other_surface')$context['surface']='video';
    $count=count($GLOBALS['workspace_calls']);$call($context,$allowed,$project);$last=$GLOBALS['home_payloads'][array_key_last($GLOBALS['home_payloads'])];
    home_check(count($GLOBALS['workspace_calls'])===$count&&!array_filter($last['surface_context']['events'],
        static fn($event)=>($event['source']??'')==='gmail_workspace_context'||($event['event_kind']??'')==='gmail_workspace'),
        $mode.' cannot receive automatic owner Gmail context or retain forged Gmail events');
}
$GLOBALS['participant_scope']=null;$GLOBALS['workspace_failure']=true;$call($raw);
$last=$GLOBALS['home_payloads'][array_key_last($GLOBALS['home_payloads'])];
home_check($last['surface_context']['events'][0]['id']==='gmail-workspace-unavailable'
    &&!str_contains(json_encode($last,JSON_THROW_ON_ERROR),'2 saved drafts'),
    'failed current authority produces unavailable state, never old counts or a fabricated empty mailbox');
$GLOBALS['workspace_failure']=false;
$full=$raw;$full['events']=[];for($i=0;$i<8;$i++)$full['events'][]=['title'=>'Existing '.$i,'source'=>'calendar','summary'=>'Current event '.$i];
$call($full);$last=$GLOBALS['home_payloads'][array_key_last($GLOBALS['home_payloads'])];
home_check(count($last['surface_context']['events'])===8&&$last['surface_context']['events'][0]['source']==='gmail_workspace_context',
    'workspace awareness remains within the existing eight-event delegation budget');
$GLOBALS['home_features']=[];$count=count($GLOBALS['workspace_calls']);$sent=count($GLOBALS['home_payloads']);$legacy=$call($raw);
home_check($legacy['delegation']['mode']==='legacy_stateful'&&count($GLOBALS['workspace_calls'])===$count&&count($GLOBALS['home_payloads'])===$sent,
    'legacy delegation is not mislabeled as receiving the new stateless workspace context');
echo 'GMAIL_HOMESERVER_CONTEXT=PASS ('.$GLOBALS['checks'].' assertions; actual delegation; transport mocked)'.PHP_EOL;
