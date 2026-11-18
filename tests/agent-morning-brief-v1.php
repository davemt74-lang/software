<?php
declare(strict_types=1);

// Native source grants, saved Chat policy, transactions, proof, history and voice
// revalidation. The mailbox's sealed provider analysis is fixture data.
define('VP3_COORDINATION_PIPELINE_FIXTURE', true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-schedule-v1.php';
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-voice-v1.php';
if ($mysql) $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$briefStart = $checks;
$owner['timezone'] = 'America/Phoenix';
$agent = 7;
$chat = 11;
$binding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$chat],'on',null)['initiative_policy'];
coordination_fixture_rule($pdo,$owner,'curiosity','open_question','watch');
coordination_fixture_gmail_setup($pdo,$owner,$mysql);
$source = coordination_fixture_gmail_observe($pdo,$owner);
$snapshot = vp3_coordination_sources_v1($pdo,$owner,$binding,[$source]);
$morning = new DateTimeImmutable('2027-02-03 08:00:00',new DateTimeZone('America/Phoenix'));
$GLOBALS['pipelineRuntimePlan'] = ['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];

function morning_brief_rows(): array
{
    return array_values(array_filter(db()->query("SELECT * FROM chat_messages WHERE role='assistant' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row):bool => (json_decode((string)$row['context_json'],true)['source']??null)===VP3_MORNING_BRIEF_SOURCE_V1));
}
function morning_brief_post(array $user,array $binding,array $snapshot,DateTimeImmutable $at,bool $priority=false):bool
{
    return agent_commerce_atomic_pa2(db(),static function()use($user,$binding,$snapshot,$at,$priority):bool{
        vp3_initiative_owner_lock_v1(db(),$user);
        return vp3_morning_brief_v1(db(),$user,$binding,$snapshot,$at,$priority);
    });
}
function morning_brief_hidden(array $row,array $user,int $agent,int $chat):bool
{
    $checked=vp3_coordination_chat_history_v1(db(),$user,$agent,$chat,[$row])[0];
    return $checked['message']==='The sources behind this morning brief have changed or are no longer available.'
        &&json_decode($checked['context_json'],true)===['source'=>VP3_MORNING_BRIEF_SOURCE_V1,'skip_brain_archive'=>true];
}
function morning_brief_proof_count():int
{
    return (int)db()->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='initiative_sweep' AND subject LIKE 'morning-brief:%'")->fetchColumn();
}

canvas_pipeline_check(isset($snapshot['items'][$source])&&$snapshot['coverage']['gmail']==='ready',
    'the brief receives a current native Gmail finding under the saved owner Chat grant');
$settings=vp3_coordination_rules_v1($pdo,$owner,$agent);
$assessment=vp3_morning_brief_assess_v1($snapshot['items'],$snapshot['coverage'],$settings,[]);
canvas_pipeline_check(array_keys($assessment['selected'])===[$source]
    &&str_contains($assessment['message'],$snapshot['items'][$source]['summary'])
    &&!str_contains($assessment['message'],$snapshot['items'][$source]['question'])
    &&$assessment['links'][0]['source']===$source,
    'a brief summarizes the verified finding and its source without issuing a duplicate owner question');
$watch=$settings;
$watch['rules']=vp3_coordination_rule_patch_v1($settings['rules'],['gmail'=>['needs_attention'=>'watch']]);
$watched=vp3_morning_brief_assess_v1($snapshot['items'],$snapshot['coverage'],$watch,[]);
canvas_pipeline_check($watched['selected']===[]&&!str_contains($watched['message'],$snapshot['items'][$source]['summary'])
    &&str_contains($watched['message'],'no verified findings marked for Chat'),
    'a watch-only finding stays private even when the mailbox was reviewed');
$partial=vp3_morning_brief_assess_v1([],['gmail'=>'limited'],$settings,[]);
canvas_pipeline_check(str_contains($partial['message'],'coverage is partial')
    &&!str_contains($partial['message'],'had no verified findings'),
    'partial inbox coverage cannot claim that the full mailbox has no findings');
canvas_pipeline_check(vp3_morning_brief_assess_v1([],['gmail'=>'unavailable'],$settings,[])===null,
    'unavailable source coverage cannot fabricate a completed mailbox review');
$unproven=vp3_morning_brief_assess_v1([],[], $settings,[['id'=>'tasks','state'=>'queued','run_id'=>0]]);
canvas_pipeline_check($unproven===null,'a specialist label without a real assignment cannot claim queued work');
$duplicate=$snapshot['items'][$source];
$duplicate['ref']='gmail:'.str_repeat('f',64);
$deduped=vp3_morning_brief_assess_v1($snapshot['items']+[$duplicate['ref']=>$duplicate],$snapshot['coverage'],$settings,[]);
canvas_pipeline_check(count($deduped['selected'])===1&&$deduped['grouped']===1,
    'identical saved findings contribute one brief line and one selected source');
$many=[];
for($n=0;$n<9;$n++){
    $item=$snapshot['items'][$source];
    $item['ref']='gmail:'.hash('sha256','brief-item-'.$n);
    $item['title']='Distinct finding '.$n;
    $many[$item['ref']]=$item;
}
$bounded=vp3_morning_brief_assess_v1($many,$snapshot['coverage'],$settings,[]);
canvas_pipeline_check(count($bounded['selected'])===6&&count($bounded['links'])===6,
    'a large verified snapshot produces a bounded six-source brief');
$unsafe=$snapshot['items'][$source];
$unsafe['url']='https://untrusted.example.test/private';
$safeLinks=vp3_morning_brief_assess_v1([$source=>$unsafe],$snapshot['coverage'],$settings,[]);
canvas_pipeline_check($safeLinks['links']===[],'source links cannot introduce external destinations into the brief');

// Completed-period facts belong in the existing brief, including when six
// priority lines have already been selected. Preserve their exact source proof.
$periodRef=vp3_profile_analytics_period_ref_v1(1,1731801600,'UTC','utc_fallback');
$period=array_replace($snapshot['items'][$source],[
    'ref'=>$periodRef,'specialist'=>'profile','profile_source_kind'=>'analytics','trigger'=>'analytics_period',
    'title'=>'Profile analytics: completed fixture period','summary'=>'8 visit records; 3 accepted visitor turns.',
    'next_step'=>'','question'=>'','url'=>vp3_profile_analytics_period_href_v1(7,11,$periodRef),
    'presentation_owner'=>'profile_briefing_v1','profile_presentation_owned'=>true,'legacy_delivered'=>false,
]);
$periodOnly=vp3_morning_brief_assess_v1([$periodRef=>$period],['profile'=>'ready'],$settings,[]);
canvas_pipeline_check(array_keys($periodOnly['selected'])===[$periodRef]
    &&str_contains($periodOnly['message'],$period['summary'])&&$periodOnly['links'][0]['url']===$period['url'],
    'a completed Profile period enters the ordinary morning brief with its fixed scoped destination');
$profileNative=array_replace($period,['ref'=>'profile:conversation:99001','profile_source_kind'=>'conversation',
    'trigger'=>'reviewed_followup','title'=>'Conversation requires review','summary'=>'A visitor is waiting for the owner.',
    'url'=>'/vp3/profile-agent.php?conversation_id=99001']);
$full=[$profileNative['ref']=>$profileNative]+$many+[$periodRef=>$period];
$withPeriod=vp3_morning_brief_assess_v1($full,['profile'=>'ready','gmail'=>'ready'],$settings,[]);
canvas_pipeline_check(count($withPeriod['selected'])===7&&isset($withPeriod['selected'][$periodRef])
    &&substr_count($withPeriod['message'],'• ')===6&&substr_count($withPeriod['message'],$period['summary'])===1
    &&count(array_filter($withPeriod['links'],static fn(array $link):bool=>$link['source']===$periodRef))===1,
    'six primary lines retain one completed-period proof and link folded into the existing Profile line');
$profileWatch=$settings;
$profileWatch['rules']=vp3_coordination_rule_patch_v1($settings['rules'],['profile'=>['analytics_period'=>'watch']]);
$watchPeriod=vp3_morning_brief_assess_v1($full,['profile'=>'ready','gmail'=>'ready'],$profileWatch,[]);
canvas_pipeline_check(!isset($watchPeriod['selected'][$periodRef])&&!str_contains($watchPeriod['message'],$period['summary']),
    'watch-only analytics never leaks into another Profile line in the morning brief');
$withPrimary=vp3_morning_brief_assess_v1([$periodRef=>$period,$profileNative['ref']=>$profileNative],
    ['profile'=>'limited'],$settings,[]);
canvas_pipeline_check(substr_count($withPrimary['message'],$period['summary'])===1
    &&count(array_filter($withPrimary['links'],static fn(array $link):bool=>$link['source']===$periodRef))===1
    &&str_contains($withPrimary['message'],'Profile (partial)'),
    'a primary period is not appended twice and retains the source coverage qualification');
canvas_pipeline_check(vp3_morning_brief_assess_v1([$periodRef=>$period],['profile'=>'unavailable'],$settings,[])===null,
    'an unavailable Profile period cannot fabricate a completed morning review');

$crowded=[$profileNative['ref']=>$profileNative];
for($n=1;$n<=5;$n++){
    $mail=array_replace($snapshot['items'][$source],['ref'=>'gmail:'.hash('sha256','period-budget-mail-'.$n),
        'title'=>'Review request '.$n,'summary'=>'A distinct saved reschedule request '.$n,
        'material'=>['key'=>hash('sha256','period-budget-'.$n),'revision'=>hash('sha256','period-budget-proof-'.$n),
            'observed_at'=>1731801600,'intent'=>'reschedule','topic'=>'fixture review '.$n]]);
    $left='planning:calendar:event:'.(9000+$n*2);$right='planning:calendar:event:'.(9001+$n*2);
    $slot=['specialist'=>'planning','status'=>'ready','start_at'=>'2024-11-16T10:00:00Z','end_at'=>'2024-11-16T11:00:00Z',
        'timezone'=>'UTC','next_step'=>'','question'=>'','summary'=>'A verified overlap.','url'=>'/vp3/calendar.php',
        'revision'=>hash('sha256','period-slot-'.$n),'priority'=>'normal'];
    $crowded[$mail['ref']]=$mail;
    $crowded[$left]=$slot+['ref'=>$left,'title'=>'Fixture review '.$n,'trigger'=>'conflict','related_ref'=>$right];
    $crowded[$right]=$slot+['ref'=>$right,'title'=>'Different topic '.$n,'trigger'=>'context_only'];
}
$crowded[$periodRef]=$period;
$capSettings=$settings;$capSettings['rules']=vp3_coordination_rule_patch_v1($settings['rules'],['curiosity'=>['open_question'=>'ask']]);
$curiosity=['ref'=>'curiosity:'.str_repeat('a',32).':'.str_repeat('b',32),'specialist'=>'curiosity','trigger'=>'open_question',
    'title'=>'A saved question','summary'=>'Review the original owner question.','revision'=>str_repeat('c',64),
    'url'=>'/vp3/chat.php?agent=7&conversation_id=11&message_id=22'];
$budgeted=vp3_morning_brief_assess_v1($crowded,['gmail'=>'ready','planning'=>'ready','profile'=>'ready','curiosity'=>'ready'],$capSettings,[],$curiosity);
canvas_pipeline_check(count($budgeted['selected'])===12&&isset($budgeted['selected'][$periodRef],$budgeted['selected'][$curiosity['ref']])
    &&count($budgeted['links'])===12&&substr_count($budgeted['message'],'Related Planning & Scheduling item')===4,
    'period and curiosity proof slots are reserved before optional planning links reach the twelve-source history limit');

// Both period summaries must keep their exact evidence when Marketing, Profile,
// mail/planning relations and a saved Curiosity question share one brief.
$marketingRef=vp3_profile_commerce_followthrough_ref_v1(str_repeat('d',32),$periodRef);
$marketingPeriod=array_replace($period,['ref'=>$marketingRef,'canonical_ref'=>$marketingRef,
    'canonical_revision'=>$period['revision'],'specialist'=>'marketing','marketing_source_kind'=>'followthrough',
    'trigger'=>'promotion_period','title'=>'Original promotion: completed period','summary'=>'2 recorded clicks; 1 verified paid order.',
    'url'=>vp3_profile_commerce_followthrough_href_v1(7,11,$marketingRef),
    'presentation_owner'=>'product_marketing_briefing_v1','marketing_presentation_owned'=>true]);
$marketingPlan=array_replace($marketingPeriod,['ref'=>'marketing:product-plan:992101','marketing_source_kind'=>'plan',
    'trigger'=>'product_plan','title'=>'Review T-shirt listing','summary'=>'The existing product plan is ready to review.',
    'presentation_owner'=>'agent_product_launch']);
unset($marketingPlan['canonical_ref'],$marketingPlan['canonical_revision']);
$marketingAlias=array_replace($marketingPeriod,['ref'=>'profile:'.substr($marketingRef,10),'specialist'=>'profile',
    'profile_source_kind'=>'commerce','trigger'=>'commerce_followthrough','profile_canonical_ref'=>$marketingRef]);
$withMarketing=[$marketingPlan['ref']=>$marketingPlan]+$crowded+[$marketingRef=>$marketingPeriod,$marketingAlias['ref']=>$marketingAlias];
$marketingSettings=$capSettings;
$marketingSettings['rules']=vp3_coordination_rule_patch_v1($capSettings['rules'],['marketing'=>['product_plan'=>'report','promotion_period'=>'report']]);
$marketingCoverage=['gmail'=>'ready','planning'=>'ready','profile'=>'ready','marketing'=>'ready','curiosity'=>'ready'];
$marketingBudget=vp3_morning_brief_assess_v1($withMarketing,$marketingCoverage,$marketingSettings,[],$curiosity);
canvas_pipeline_check(count($marketingBudget['selected'])===12&&count($marketingBudget['links'])===12
    &&isset($marketingBudget['selected'][$periodRef],$marketingBudget['selected'][$marketingRef],$marketingBudget['selected'][$curiosity['ref']])
    &&substr_count($marketingBudget['message'],'Related Planning & Scheduling item')===3,
    'both fixed periods and Curiosity retain proof slots before optional planning links fill the shared twelve-source bound');
canvas_pipeline_check(!isset($marketingBudget['selected'][$marketingAlias['ref']])
    &&substr_count($marketingBudget['message'],$marketingPeriod['summary'])===1
    &&count(array_filter($marketingBudget['links'],static fn(array $link):bool=>$link['source']===$marketingRef))===1,
    'shared Profile and Marketing evidence contributes one period summary and one canonical original-Chat link');
$marketingWatch=$marketingSettings;
$marketingWatch['rules']=vp3_coordination_rule_patch_v1($marketingSettings['rules'],['marketing'=>['promotion_period'=>'watch']]);
$watchMarketingBudget=vp3_morning_brief_assess_v1($withMarketing,$marketingCoverage,$marketingWatch,[],$curiosity);
canvas_pipeline_check(!isset($watchMarketingBudget['selected'][$marketingRef],$watchMarketingBudget['selected'][$marketingAlias['ref']])
    &&!str_contains($watchMarketingBudget['message'],$marketingPeriod['summary'])
    &&isset($watchMarketingBudget['selected'][$periodRef],$watchMarketingBudget['selected'][$marketingPlan['ref']]),
    'Marketing Watch suppresses both promotion copies while retaining the independently reportable plan and Profile period');

$nativeBefore=$pdo->query('SELECT * FROM agent_email_running_report_v1 ORDER BY thread_key')->fetchAll(PDO::FETCH_ASSOC);
$runsBefore=canvas_pipeline_count('agent_workflow_runs');
$ledgerBefore=canvas_pipeline_count('ai_execution_ledger');
canvas_pipeline_check(!vp3_morning_brief_v1($pdo,$owner,$binding,$snapshot,$morning)&&morning_brief_rows()===[],
    'publication requires the coordinator transaction rather than a read-only call');
canvas_pipeline_check(!morning_brief_post($owner,$binding,$snapshot,$morning->modify('-1 second'))&&morning_brief_proof_count()===0,
    'the owner-local 08:00 boundary suppresses early publication without consuming the day');
canvas_pipeline_check(vp3_morning_brief_local_v1($pdo,$owner,new DateTimeImmutable('2027-02-03T06:30:00Z'))->format('Y-m-d H:i')==='2027-02-02 23:30',
    'the local day follows the owner timezone across UTC midnight');
canvas_pipeline_check(!morning_brief_post($owner,$binding,$snapshot,$morning->setTime(9,59,59),true),
    'the priority slot has its own owner-local 10:00 boundary');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>5];
try{
    canvas_pipeline_check(!morning_brief_post($owner,$binding,$snapshot,$morning),
        'participant access cannot publish an owner morning brief');
}finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}
$foreign=$owner;$foreign['id']=2;
canvas_pipeline_check(!morning_brief_post($foreign,$binding,$snapshot,$morning)&&morning_brief_proof_count()===0,
    'a different owner cannot use another account Chat binding or consume its daily marker');
$stale=$binding;$stale['revision']=str_repeat('0',32);
canvas_pipeline_check(!morning_brief_post($owner,$stale,$snapshot,$morning),
    'a stale saved Chat policy cannot publish a brief');
canvas_pipeline_check(morning_brief_post($owner,$binding,$snapshot,$morning)&&count(morning_brief_rows())===1,
    'a saved owner finding publishes once at the local morning boundary despite unavailable inference');
$row=morning_brief_rows()[0];
$post=json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR);
$proofQuery=$pdo->prepare('SELECT user_id,user_agent_id,metadata_json FROM agent_memory_items WHERE id=?');
$proofQuery->execute([$post['morning_brief']['assessment_id']]);$proofRow=$proofQuery->fetch(PDO::FETCH_ASSOC);
$proof=json_decode($proofRow['metadata_json'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check((int)$proofRow['user_id']===1&&(int)$proofRow['user_agent_id']===7
    &&$proof['local_day']==='2027-02-03'&&$proof['timezone']==='America/Phoenix'
    &&$proof['conversation_id']===11&&$proof['binding_revision']===$binding['revision']
    &&$proof['selected'][$source]['revision']===$snapshot['items'][$source]['revision']
    &&$proof['payload_hash']===vp3_morning_brief_payload_hash_v1($row['message'],$post['sources']),
    'publication commits exact owner, Agent, local day, source revisions and payload proof with the Chat message');
canvas_pipeline_check($pdo->query('SELECT * FROM agent_email_running_report_v1 ORDER BY thread_key')->fetchAll(PDO::FETCH_ASSOC)===$nativeBefore
    &&canvas_pipeline_count('agent_workflow_runs')===$runsBefore&&canvas_pipeline_count('ai_execution_ledger')===$ledgerBefore
    &&$GLOBALS['pipelineInference']===[],
    'the brief changes no source finding, workflow job or inference ledger');
canvas_pipeline_check(!morning_brief_post($owner,$binding,$snapshot,$morning->modify('+2 hours'))&&count(morning_brief_rows())===1,
    'repeated coordinator calls cannot publish a second brief for the same Agent and local day');
canvas_pipeline_check(vp3_coordination_chat_history_v1($pdo,$owner,$agent,$chat,[$row])===[$row],
    'the ordinary Chat and model-history filter preserves a current verified brief');
$voiceContext=['scope'=>['user'=>$owner,'agent_id'=>$agent,'conversation_id'=>$chat,'principal'=>$principal],'policy'=>$binding];
canvas_pipeline_check(vp3_initiative_voice_notice_record_v1($pdo,$voiceContext,(int)$row['id'],(int)$row['id'],true)['text']===$row['message'],
    'fresh optional voice reads the same proof-backed Chat message');
canvas_pipeline_check(morning_brief_hidden($row,$foreign,9,13)&&morning_brief_hidden($row,$owner,8,12),
    'another account or Agent cannot reuse the saved brief proof');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>5];
try{canvas_pipeline_check(morning_brief_hidden($row,$owner,$agent,$chat),'participant history cannot expose an owner brief');}
finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}
$tampered=$row;$tampered['message'].=' Unverified addition.';
canvas_pipeline_check(morning_brief_hidden($tampered,$owner,$agent,$chat),'altered brief text fails its saved payload proof');
$tampered=$row;$changedPost=$post;$changedPost['sources'][0]['url']='/unverified';
$tampered['context_json']=json_encode($changedPost,JSON_THROW_ON_ERROR);
canvas_pipeline_check(morning_brief_hidden($tampered,$owner,$agent,$chat),'altered source links fail the same saved payload proof');
$tampered=$row;$changedPost=$post;$changedPost['morning_brief']['assessment_id']=0;
$tampered['context_json']=json_encode($changedPost,JSON_THROW_ON_ERROR);
canvas_pipeline_check(morning_brief_hidden($tampered,$owner,$agent,$chat),'a missing assessment proof cannot expose summary text or links');
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','watch');
canvas_pipeline_check(morning_brief_hidden($row,$owner,$agent,$chat),'changing the source rule to watch withdraws it from brief history');
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','ask');
canvas_pipeline_check(!morning_brief_hidden($row,$owner,$agent,$chat),'restoring permission reveals an otherwise unchanged verified brief');
coordination_fixture_gmail_observe($pdo,$owner,2,'The vendor confirmed a different delivery date.');
canvas_pipeline_check(morning_brief_hidden($row,$owner,$agent,$chat),'a changed native finding withdraws the old brief from Chat and model history');
$voiceRejected=false;
try{vp3_initiative_voice_notice_record_v1($pdo,$voiceContext,(int)$row['id'],(int)$row['id'],true);}
catch(Vp3InitiativeVoiceRefusalV1 $error){$voiceRejected=$error->reason==='post_changed';}
canvas_pipeline_check($voiceRejected,'changed evidence also prevents a fresh voice rendering of the old brief');

$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(14,1,7,'A second owner Chat')");
$nextBinding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>14],'on',null)['initiative_policy'];
$current=vp3_coordination_sources_v1($pdo,$owner,$nextBinding,[$source]);
canvas_pipeline_check(!morning_brief_post($owner,$nextBinding,$current,$morning->modify('+3 hours'))&&count(morning_brief_rows())===1,
    'switching Chats cannot replay the same Agent daily brief');
canvas_pipeline_check(morning_brief_hidden($row,$owner,$agent,14),'the original proof cannot be transplanted into a sibling Chat');
canvas_pipeline_check(morning_brief_post($owner,$nextBinding,$current,$morning->modify('+1 day'))&&count(morning_brief_rows())===2,
    'the next local day can publish the current evidence into the newly saved Chat');
$nextRow=morning_brief_rows()[1];
canvas_pipeline_check((int)$nextRow['conversation_id']===14
    &&str_contains($nextRow['message'],'different delivery date')&&!morning_brief_hidden($nextRow,$owner,$agent,14),
    'the next-day message has current source content and independently valid Chat proof');
canvas_pipeline_check(morning_brief_post($owner,$nextBinding,$current,$morning->modify('+2 days')->setTime(10,0),true),
    'the priority slot can publish a verified non-casual source after its local boundary');

$beforeRollback=count(morning_brief_rows());$proofBeforeRollback=morning_brief_proof_count();
$pdo->exec($mysql
    ? "CREATE TRIGGER morning_brief_insert_failure BEFORE INSERT ON chat_messages FOR EACH ROW BEGIN IF NEW.message LIKE 'Morning brief%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture brief insert failure'; END IF; END"
    : "CREATE TRIGGER morning_brief_insert_failure BEFORE INSERT ON chat_messages WHEN NEW.message LIKE 'Morning brief%' BEGIN SELECT RAISE(ABORT,'fixture brief insert failure'); END");
$rolledBack=false;
try{morning_brief_post($owner,$nextBinding,$current,$morning->modify('+3 days'));}
catch(PDOException $error){$rolledBack=str_contains($error->getMessage(),'fixture brief insert failure');}
finally{$pdo->exec('DROP TRIGGER morning_brief_insert_failure');}
canvas_pipeline_check($rolledBack&&count(morning_brief_rows())===$beforeRollback&&morning_brief_proof_count()===$proofBeforeRollback,
    'a failed Chat insert rolls back the daily proof so an invisible brief cannot consume the day');
canvas_pipeline_check(morning_brief_post($owner,$nextBinding,$current,$morning->modify('+3 days'))
    &&count(morning_brief_rows())===$beforeRollback+1,
    'the same local day can retry successfully after the atomic publication failure');
vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>14],'off',null);
canvas_pipeline_check(!morning_brief_post($owner,$nextBinding,$current,$morning->modify('+4 days')),
    'an owner opt-out prevents publication even with a previously enabled binding and saved snapshot');
$renewed=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>14],'on',null)['initiative_policy'];
canvas_pipeline_check($renewed['revision']!==$nextBinding['revision']
    &&!morning_brief_post($owner,$nextBinding,$current,$morning->modify('+4 days')),
    'reenabling Initiative does not authorize an earlier binding revision');
$pdo->exec('DELETE FROM agent_connection_gmail_inbox_initial_v1 WHERE owner_user_id=1');
$otherPrincipal=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,8));
$otherBinding=vp3_initiative_chat_v1($pdo,$owner,$otherPrincipal,['conversation_id'=>12],'on',null)['initiative_policy'];
$otherSnapshot=vp3_coordination_sources_v1($pdo,$owner,$otherBinding,[$source]);
canvas_pipeline_check(isset($otherSnapshot['items'][$source])
    &&morning_brief_post($owner,$otherBinding,$otherSnapshot,$morning),
    'a separately authorized Agent keeps its own daily marker when the mailbox has no pinned destination');
$allBriefs=morning_brief_rows();$otherRow=end($allBriefs);
canvas_pipeline_check((int)$otherRow['conversation_id']===12&&!morning_brief_hidden($otherRow,$owner,8,12)
    &&morning_brief_hidden($otherRow,$owner,7,14),
    'the second Agent brief is readable only through its own saved Chat proof');
$pdo->exec('UPDATE agent_connection_gmail_inbox_v1 SET enabled=0 WHERE owner_user_id=1');
canvas_pipeline_check(morning_brief_hidden($nextRow,$owner,$agent,14),'revoking the mailbox grant removes its saved brief text and links');
$pdo->exec('UPDATE agent_connection_gmail_inbox_v1 SET enabled=1 WHERE owner_user_id=1');
$pdo->exec('DELETE FROM agent_email_running_report_v1 WHERE owner_user_id=1');
canvas_pipeline_check(morning_brief_hidden($nextRow,$owner,$agent,14),'deleting the source report cannot leave its private summary readable');

echo 'MORNING_BRIEF=PASS ('.($checks-$briefStart).' assertions, '.($mysql?'mysql':'sqlite').")\n";
