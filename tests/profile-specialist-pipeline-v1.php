<?php
declare(strict_types=1);

// Real Profile sources, current rules, scheduler, worker receipts and private
// Chat delivery. No public responder, network provider or browser is started.
define('VP3_COORDINATION_PIPELINE_FIXTURE', true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-evidence-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-registry-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-specialists-v1.php';

function get_tracks(): array { return []; }
// Physical provider configuration only: canonical call actions remain real,
// and this suite never dispatches a room operation or joins a call.
function vp3_profile_call_livekit_readiness_pa3c(): array
{
    return ['ready'=>true,'config_fingerprint'=>hash('sha256','profile-pipeline-provider')];
}
function vp3_profile_call_livekit_snapshot_pa3c(): array
{
    return ['encrypted'=>'fixture-provider-configuration','config_fingerprint'=>hash('sha256','profile-pipeline-provider')];
}

function profile_pipeline_reject(callable $operation, string $message, ?int $code=null): void
{
    try { $operation(); }
    catch (RuntimeException|InvalidArgumentException $error) {
        canvas_pipeline_check($code===null||$error->getCode()===$code,$message);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

function profile_pipeline_last(string $ref): array
{
    $runs=coordination_fixture_runs('coordination.review',$ref);
    return $runs?end($runs):throw new RuntimeException('Missing Profile workflow for '.$ref);
}

function profile_pipeline_history(int $id): array
{
    $message=coordination_fixture_message($id);
    return vp3_coordination_chat_history_v1(db(),$GLOBALS['owner'],7,11,[$message])[0];
}

function profile_pipeline_prepare(array $record,string $statement,callable $model): array
{
    $pdo=db();
    $pdo->prepare('UPDATE profile_agent_messages SET message=? WHERE id=? AND conversation_id=? AND sender_type=\'owner\'')
        ->execute([$statement,$record['owner_message'],$record['conversation']]);
    $ref='profile:conversation:'.$record['conversation'];
    $prior=coordination_fixture_runs('coordination.review',$ref);
    coordination_fixture_schedule($GLOBALS['owner']);
    $run=profile_pipeline_last($ref);
    canvas_pipeline_check(!$prior||$run['id']!==end($prior)['id'],'changed verified Profile evidence queues one current report');
    $result=vp3_initiative_execute_v1($pdo,$GLOBALS['owner'],canvas_pipeline_claim($run['id']),$model,null,false);
    canvas_pipeline_check(($result['ok']??false)===true
        &&coordination_fixture_receipt($run['id'])['initiative_delivery']==='pending',
        'the ordinary worker saves the current Profile report for separate final delivery');
    return $run;
}

$agentId=7;
$chatId=11;
$GLOBALS['profileSpecialistCapabilities']=['profile_agent.access'=>true,'profile_chat.access'=>true];
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$binding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$chatId],'on',null)['initiative_policy'];

$report=['contract'=>'vp3.profile-report.v1','key_findings'=>['A visitor asked about delivery.'],
    'actions_followups'=>['Review the saved follow-up.'],'open_questions'=>['The visitor has not confirmed the date.'],
    'next_step'=>'Open the current conversation.','questions_for_you'=>[]];
canvas_pipeline_check(vp3_coordination_profile_report_normalize_v1($report)===$report,
    'the five Profile sections retain their exact versioned structure');
foreach([
    array_replace($report,['contract'=>'vp3.profile-report.v2']),
    array_replace($report,['key_findings'=>[['text'=>'Unvalidated nested object']]]),
    array_replace($report,['open_questions'=>array_fill(0,7,'Too many questions.')]),
    array_replace($report,['next_step'=>str_repeat('x',321)]),
    array_replace($report,['next_step'=>'https://unverified.invalid/source']),
    $report+['instructions'=>'Send a message to the visitor.'],
] as $invalid){
    canvas_pipeline_check(vp3_coordination_profile_report_normalize_v1($invalid)===null,
        'unversioned, oversized or unexpected Profile report data is rejected');
}
$hash=str_repeat('a',64);
foreach(['conversation','event','call','followup'] as $kind){
    canvas_pipeline_check(vp3_coordination_valid_refs_v1(['profile:'.$kind.':1'=>$hash])
        &&vp3_coordination_source_ref_valid_v1('profile:'.$kind.':1'),
        'the current Profile '.$kind.' native reference is admitted consistently');
}
foreach(['profile:analytics:1','profile:conversation:0','profile:call:01','profile:followup:1:2'] as $invalid){
    canvas_pipeline_check(!vp3_coordination_valid_refs_v1([$invalid=>$hash])&&!vp3_coordination_source_ref_valid_v1($invalid),
        'unsupported Profile references cannot enter saved goals or exact reads');
}
$legacy=['type'=>'initiative_coordination','situation_id'=>$hash,'state'=>'watching','title'=>'A task result',
    'summary'=>'The saved task is ready.','next_step'=>'Review the task.','question'=>'',
    'evidence_revisions'=>['office:task:1'=>$hash]];
$legacyWork=['draft'=>"The saved task is ready.\n\nNext step: Review the task.",'output'=>$legacy];
canvas_pipeline_check(vp3_coordination_result_normalize_v1($legacyWork)===$legacyWork,
    'existing specialist reports retain their exact draft and result format');
$profileOutput=array_replace($legacy,['evidence_revisions'=>['profile:followup:1'=>$hash],'profile_report'=>$report]);
$profileWork=['draft'=>vp3_coordination_draft_v1($profileOutput),'output'=>$profileOutput];
canvas_pipeline_check(vp3_coordination_result_normalize_v1($profileWork)===$profileWork
    &&str_contains($profileWork['draft'],"Key findings\n")&&str_contains($profileWork['draft'],"Actions and follow-ups\n")
    &&str_contains($profileWork['draft'],"Open questions\n")&&str_contains($profileWork['draft'],"Next step\n")
    &&str_contains($profileWork['draft'],"Questions for you\n"),
    'one normalized Profile receipt persists all five review sections');
$invalid=$profileWork;$invalid['output']['profile_report']['questions_for_you']=['A new question?'];
canvas_pipeline_check(vp3_coordination_result_normalize_v1($invalid)===null,
    'a Report route cannot hide an owner question in the five-section draft');
$catalog=vp3_initiative_specialist_catalog_v1([]);
canvas_pipeline_check(($catalog['profile']['label']??null)==='Profile'
    &&$catalog['profile']['report_sources']===['profile']&&$catalog['profile']['ready_tools']===[],
    'Profile joins the specialist catalog without creating a new executable public tool');
$genericRef='specialist:profile:conversation:1';
$profileEvidence=[$genericRef=>['kind'=>'specialist_finding','specialist'=>'profile','private_summary_only'=>true,
    'profile_presentation_owned'=>true,'routing'=>'report']];
$privateJob=['tool'=>'work.prepare_private','primary'=>$genericRef,'refs'=>[$genericRef],'report'=>true];
canvas_pipeline_check(vp3_initiative_specialist_job_v1($privateJob,$profileEvidence)['report']===false
    &&vp3_initiative_proactive_specialist_v1(['tool'=>'work.prepare_private','primary'=>$genericRef]),
    'generic planning can retain Profile context privately without a competing automatic Chat post');
profile_pipeline_reject(fn()=>vp3_initiative_specialist_job_v1(
    array_replace($privateJob,['tool'=>'chat.check_in']),$profileEvidence),
    'generic check-in planning cannot bypass the Profile presentation owner');
$supporting=array_replace($privateJob,['primary'=>'task:123','refs'=>['task:123',$genericRef]]);
canvas_pipeline_check(vp3_initiative_specialist_job_v1($supporting,$profileEvidence)['report']===true
    &&!vp3_initiative_proactive_specialist_v1($supporting),
    'supporting Profile context does not suppress an unrelated private Tasks preparation report');
canvas_pipeline_check(vp3_coordination_profile_presentation_owned_v1(['primary'=>'profile:conversation:1'],
    ['profile:conversation:1'=>['specialist'=>'profile','profile_presentation_owned'=>true,'legacy_delivered'=>false]])
    &&!vp3_coordination_profile_presentation_owned_v1(['primary'=>'office:task:1'],
        ['office:task:1'=>['specialist'=>'tasks'],'profile:conversation:1'=>['specialist'=>'profile','profile_presentation_owned'=>true]]),
    'native delivery ownership is a primary-source hold and is independent of an actual delivery receipt');

require_once __DIR__.'/fixtures/profile-specialist-data-v1.php';
$settled=profile_specialist_fixture_settled_v1($pdo);
$ref='profile:conversation:'.$settled['conversation'];
$snapshot=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref]);
if(!isset($snapshot['items'][$ref]))throw new RuntimeException('Settled Profile source missing: '.json_encode(
    array_intersect_key($snapshot,['coverage'=>true,'failures'=>true]),JSON_THROW_ON_ERROR));
$source=$snapshot['items'][$ref];
canvas_pipeline_check($source['trigger']==='activity'&&$source['status']==='updated'
    &&$source['profile_presentation_owned']===false&&$source['legacy_delivered']===false,
    'a verified latest reply is informational evidence with no inferred resolution, active native presenter or claimed delivery');

$coverage=[];
$context=vp3_initiative_specialist_evidence_v1($pdo,$owner,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),
    ['specialist:'.$ref],$coverage);
canvas_pipeline_check(isset($context['specialist:'.$ref])&&$context['specialist:'.$ref]['private_summary_only']===true
    &&$context['specialist:'.$ref]['specialist']==='profile'&&$context['specialist:'.$ref]['public_evidence_untrusted']===true
    &&in_array($coverage['specialist_profile'],['ready','limited'],true),
    'current Profile facts stay available as exact private, untrusted evidence even under the default watch rule');
$initial=vp3_coordination_rule_settings_v1($pdo,$owner,7,11);
$watch=[];
foreach($initial['rules'] as $family=>$rules)$watch[$family]=array_fill_keys(array_keys($rules),'watch');
vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$initial['revision'],$watch);
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
$day=vp3_morning_brief_local_v1($pdo,$owner,new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');
vp3_initiative_in_scope_v1($owner,7,static fn()=>agent_brain_v122_upsert_system_memory(
    $owner,'initiative_sweep','morning-brief:'.$day,'Earlier daily brief fixture assessment',['local_day'=>$day]));
coordination_fixture_schedule($owner);
canvas_pipeline_check(coordination_fixture_runs('coordination.review',$ref)===[],
    'default watch does not interrupt Chat about an informational visitor reply');

coordination_fixture_rule($pdo,$owner,'profile','activity','report');
$sourceRows=$pdo->query('SELECT * FROM profile_agent_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$eventRows=$pdo->query('SELECT * FROM profile_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$initialChatCount=canvas_pipeline_count('chat_messages','conversation_id=11');
coordination_fixture_schedule($owner);
$first=profile_pipeline_last($ref);
$route=['specialist'=>'profile','trigger'=>'activity','action'=>'report'];
canvas_pipeline_check($first['goal']['coordination_route']===$route&&$first['goal']['coordination_saved']===true
    &&$first['goal']['sources']===[$ref=>$source['revision']]
    &&vp3_initiative_goal_specialist_v1($first['goal'])==='profile',
    'the owner-selected Report rule schedules one standalone Profile review with the exact current source');
$inferences=0;
$model=static function()use(&$inferences):never{++$inferences;throw new LogicException('Saved Profile reports must not invoke a provider.');};
$firstResult=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($first['id']),$model,null,false);
$firstReceipt=coordination_fixture_receipt($first['id']);
canvas_pipeline_check(($firstResult['ok']??false)===true&&$inferences===0
    &&$firstReceipt['initiative_delivery']==='pending'
    &&$firstReceipt['initiative_tool_result']['output']['profile_report']===$source['profile_report']
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$initialChatCount,
    'the real worker persists a verified five-section Profile receipt without inference or premature delivery');
$tampered=$firstReceipt['initiative_tool_result'];
$tampered['output']['profile_report']['key_findings'][0]='An unsupported claim about the visitor.';
$tampered['draft']=vp3_coordination_draft_v1($tampered['output']);
profile_pipeline_reject(fn()=>vp3_coordination_result_evidence_v1($pdo,$owner,$binding,$first['goal'],$tampered),
    'a structurally valid report cannot substitute unsupported findings for the exact saved Profile source',409);
$missing=$firstReceipt['initiative_tool_result'];unset($missing['output']['profile_report']);
$missing['draft']=vp3_coordination_draft_v1($missing['output']);
profile_pipeline_reject(fn()=>vp3_coordination_saved_revisions_v1($first['goal'],$missing),
    'an automatic Profile report cannot silently fall back to an unversioned flat result',409);
canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$owner,7),
    'the existing final delivery pass posts the verified Profile report to its bound private Chat');
$delivered=coordination_fixture_receipt($first['id']);
$message=coordination_fixture_message((int)$delivered['initiative_message_id']);
$savedContext=json_decode($message['context_json'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check($delivered['initiative_delivery']==='delivered'&&(int)$message['conversation_id']===11
    &&$message['role']==='assistant'&&$message['user_id']===null
    &&$savedContext['initiative_output']===$delivered['initiative_tool_result']['output']
    &&$savedContext['initiative_work']['owner_user_id']===1&&$savedContext['initiative_work']['agent_id']===7
    &&$message['message']===$delivered['initiative_draft']
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$initialChatCount+1,
    'Chat stores the same five-section draft and exact owner, named Agent, private thread and execution proof');
canvas_pipeline_check(profile_pipeline_history((int)$message['id'])['message']===$message['message'],
    'ordinary Chat reload verifies and retains the same current Profile report');
vp3_initiative_deliver_pending_v1($pdo,$owner,7);
coordination_fixture_schedule($owner);
canvas_pipeline_check(canvas_pipeline_count('chat_messages','conversation_id=11')===$initialChatCount+1
    &&count(coordination_fixture_runs('coordination.review',$ref))===1&&$inferences===0,
    'repeated delivery and ordinary rescans neither replay the report nor rerun inference');
canvas_pipeline_check($pdo->query('SELECT * FROM profile_agent_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$sourceRows
    &&$pdo->query('SELECT * FROM profile_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$eventRows
    &&canvas_pipeline_count('profile_agent_handoff_deliveries_pa3')===0&&canvas_pipeline_count('profile_call_deliveries_pa3c')===0,
    'report discovery and delivery do not send a public reply, announce a visitor, claim a turn or deliver a native call');

$chatCount=$initialChatCount+1;
$changed=profile_pipeline_prepare($settled,'The current public hours were checked again.',$model);
$pdo->prepare('UPDATE profile_agent_messages SET message=? WHERE id=?')->execute(['The public hours changed after preparation.',$settled['owner_message']]);
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    &&coordination_fixture_receipt($changed['id'])['initiative_delivery']==='withheld'
    &&coordination_fixture_receipt($changed['id'])['initiative_delivery_reason']==='source_changed'
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
    'a source change after the real receipt withholds the stale Profile report before Chat');
canvas_pipeline_check(profile_pipeline_history((int)$message['id'])['message']!==$message['message'],
    'reload does not expose a prior Profile statement after its exact source changes');

$ruleChange=profile_pipeline_prepare($settled,'The current reply has a revised schedule.',$model);
coordination_fixture_rule($pdo,$owner,'profile','activity','watch');
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    &&coordination_fixture_receipt($ruleChange['id'])['initiative_delivery']==='withheld'
    &&coordination_fixture_receipt($ruleChange['id'])['initiative_delivery_reason']==='rule_changed'
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
    'an owner rule change after preparation withdraws the pending Profile Chat report');
coordination_fixture_rule($pdo,$owner,'profile','activity','report');

$outage=profile_pipeline_prepare($settled,'The current Profile reply is waiting for source verification.',$model);
$pdo->exec('ALTER TABLE profile_attention_operations_pa4b RENAME COLUMN request_hash TO fixture_request_hash');
try{
    try{
        vp3_coordination_read_v1($pdo,$owner,$binding,$outage['goal']['sources']);
        throw new LogicException('Expected unavailable Profile source proof.');
    }catch(Vp3InitiativeSourceProofUnavailableV1 $error){
        canvas_pipeline_check(Vp3InitiativeSourceProofUnavailableV1::diagnostic($error->family,$error->coverage)
            ===['source_family'=>'profile','source_coverage'=>'unavailable'],
            'exact Profile verification failure retains its own safe source diagnostic');
    }
    canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,7)
        &&coordination_fixture_receipt($outage['id'])['initiative_delivery']==='pending'
        &&coordination_fixture_receipt($outage['id'])['initiative_delivery_reason']==='result_unavailable'
        &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
        'a temporary Profile verification outage holds the real saved receipt without exposing stale evidence');
}finally{$pdo->exec('ALTER TABLE profile_attention_operations_pa4b RENAME COLUMN fixture_request_hash TO request_hash');}
$pdo->prepare("UPDATE agent_workflow_actions SET updated_at='2000-01-01 00:00:00' WHERE run_id=?")
    ->execute([$outage['id']]);
canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    &&coordination_fixture_receipt($outage['id'])['initiative_delivery']==='delivered'
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===++$chatCount&&$inferences===0,
    'restored exact source verification delivers the original prepared report once without another provider attempt');

$revoked=profile_pipeline_prepare($settled,'A current reply awaits the owner report check.',$model);
$GLOBALS['profileSpecialistCapabilities']['profile_agent.access']=false;
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    &&coordination_fixture_receipt($revoked['id'])['initiative_delivery']==='withheld'
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
    'revoked current Profile capability prevents delivery of an already prepared report');
$GLOBALS['profileSpecialistCapabilities']['profile_agent.access']=true;
profile_pipeline_reject(fn()=>vp3_coordination_sources_v1($pdo,$owner,
    ['owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>12],[$ref]),
    'a selected Agent cannot read Profile evidence through another Agent private Chat',403);
$foreign=vp3_coordination_sources_v1($pdo,['id'=>2,'is_active'=>1],
    ['owner_user_id'=>2,'agent_id'=>9,'conversation_id'=>13],[$ref]);
canvas_pipeline_check($foreign['items']===[],'another owner cannot read this public visitor source through an owned private Chat');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['kind'=>'participant','owner_user_id'=>1,'participant_user_id'=>1,'conversation_id'=>11];
try{profile_pipeline_reject(fn()=>vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref]),
    'participant context cannot acquire private Profile report authority',403);}
finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}

$paused=profile_pipeline_prepare($settled,'The current Profile statement was saved before Pause.',$model);
vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'off',null);
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
    'pausing the exact private Chat binding stops its pending Profile report from entering Chat');
$binding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null)['initiative_policy'];
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    &&coordination_fixture_receipt($paused['id'])['initiative_delivery']==='withheld'
    &&coordination_fixture_receipt($paused['id'])['initiative_delivery_reason']==='policy_changed'
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
    'a new policy revision cannot revive the pending report from the earlier private Chat binding');

$native=profile_pipeline_prepare($settled,'This informational reply is current before a new call offer.',$model);
$beforeNative=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref])['items'][$ref];
$offered=vp3_profile_call_owner_action_pa3c($pdo,$owner,['operation'=>'offer','request_id'=>bin2hex(random_bytes(16)),
    'conversation_id'=>$settled['conversation'],'mode'=>'audio','chat_conversation_id'=>11,'chat_agent_id'=>7]);
$duringNative=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref])['items'][$ref];
canvas_pipeline_check($duringNative['profile_presentation_owned']===true&&$duringNative['legacy_delivered']===false
    &&$duringNative['revision']!==$beforeNative['revision']&&$duringNative['profile_report']===$beforeNative['profile_report']
    &&canvas_pipeline_count('profile_call_deliveries_pa3c')===0,
    'a real current call offer changes presentation responsibility without inventing delivery or changing the saved reply');
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    &&coordination_fixture_receipt($native['id'])['initiative_delivery']==='withheld'
    &&coordination_fixture_receipt($native['id'])['initiative_delivery_reason']==='source_changed'
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
    'final dispatch rechecks presentation responsibility after preparation and withdraws its older Profile snapshot');
// Exercise the final boundary independently of discovery: even an admitted
// current-source assignment cannot compete with an existing native presenter.
$nativeGoal=$native['goal'];$nativeGoal['sources']=[$ref=>$duringNative['revision']];
canvas_pipeline_check(vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$nativeGoal,
    hash('sha256','profile-native-final-boundary|'.$duringNative['revision'])),'the native-hold fixture enters the existing governed queue');
$nativeCurrent=profile_pipeline_last($ref);
$nativeResult=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($nativeCurrent['id']),$model);
$nativeReceipt=coordination_fixture_receipt($nativeCurrent['id']);
canvas_pipeline_check(($nativeResult['ok']??false)===true&&$nativeReceipt['initiative_delivery']==='private'
    &&$nativeReceipt['initiative_delivery_reason']==='native_presentation'
    &&canvas_pipeline_count('chat_messages','conversation_id=11')===$chatCount,
    'the real worker final gate keeps even a current native-owned Profile report private without claiming prior delivery');
$beforeRuns=count(coordination_fixture_runs('coordination.review'));
coordination_fixture_schedule($owner);
$checkpoint=vp3_coordination_scan_checkpoint_v1($owner,$binding);
canvas_pipeline_check(count(coordination_fixture_runs('coordination.review'))===$beforeRuns
    &&in_array('native_presentation',array_column($checkpoint['specialists']['profile']['dispositions']??[],'reason'),true)
    &&($checkpoint['specialists']['profile']['legacy_handled']??-1)===0,
    'the ordinary scheduler holds current native obligations with an honest presentation reason and no fake receipt');
$row=vp3_top_of_mind_profile_status_v1($pdo,$owner,$principal,7,11,$binding,time(),true);
canvas_pipeline_check(($row['id']??null)==='profile'&&in_array($row['source_coverage']??null,['ready','limited'],true)
    &&$row['state']==='checked'&&$inferences===0,
    'Top of Mind exposes current Profile coverage without claiming another worker or starting inference');
echo 'PROFILE_SPECIALIST_PIPELINE=PASS ('.$checks.' assertions, '.($mysql?'mysql':'sqlite').")\n";
