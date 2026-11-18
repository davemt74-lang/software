<?php
declare(strict_types=1);

// Exercise the real fixed-period adapter, source registry, catch-up collector,
// morning publication and saved Chat proof. Only physical providers are fixtures.
define('VP3_COORDINATION_PIPELINE_FIXTURE', true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once __DIR__.'/fixtures/profile-analytics-period-data-v1.php';
require_once dirname(__DIR__).'/includes/profile-analytics-period-v1.php';
require_once dirname(__DIR__).'/includes/agent-profile-analytics-summary-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-schedule-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-registry-v1.php';

function get_tracks(): array { return []; }
function profile_period_brief_reject(callable $read,string $message,int $code=403): void
{
    try{$read();}catch(RuntimeException $error){canvas_pipeline_check($error->getCode()===$code,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}
function profile_period_brief_rows(): array
{
    return array_values(array_filter(db()->query("SELECT * FROM chat_messages WHERE role='assistant' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row):bool=>(json_decode((string)$row['context_json'],true)['source']??null)===VP3_MORNING_BRIEF_SOURCE_V1));
}
function profile_period_brief_state(): array
{
    $state=[];
    foreach(['profile_events','profile_event_clock_activation_v1','profile_agent_conversations','profile_agent_messages',
        'profile_agent_handoff_turns_pa3','profile_agent_handoff_operations_pa3','profile_calls_pa3c',
        'agent_commerce_orders_v800','agent_commerce_payments_v800','agent_commerce_refunds_v800',
        'agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts','chat_messages','ai_execution_ledger'] as $table){
        $rows=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        $encoded=array_map(static fn(array $row):string=>json_encode($row,JSON_THROW_ON_ERROR),$rows);
        sort($encoded,SORT_STRING);$state[$table]=hash('sha256',implode("\n",$encoded));
    }
    return $state;
}

$startChecks=$checks;
$GLOBALS['profileSpecialistCapabilities']=['profile_agent.access'=>true,'profile_chat.access'=>true];
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$fixture=profile_analytics_period_fixture_v1($pdo);
$owner=$fixture['scope']['user'];$principal=$fixture['scope']['principal'];$ref=$fixture['ref'];
$now=(new DateTimeImmutable('@'.($fixture['cutoff']+9*3600)))->setTimezone(new DateTimeZone('UTC'));
$binding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null)['initiative_policy'];
coordination_fixture_rule($pdo,$owner,'curiosity','open_question','watch');
$GLOBALS['pipelineRuntimePlan']=['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];
$before=profile_period_brief_state();
$period=vp3_profile_analytics_period_v1($pdo,$fixture['scope'],['ref'=>$ref],$now);
$metrics=array_column($period['metrics'],null,'key');
canvas_pipeline_check($metrics['visit_records']['current']['value']===3&&$metrics['visit_records']['previous']['value']===1,
    'the integration fixture has exact comparable recorded visit periods');
$snapshot=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref],$now);
canvas_pipeline_check(array_keys($snapshot['items'])===[$ref]&&$snapshot['coverage']['profile']==='ready',
    'the actual registry resolves an exact completed Profile period under the saved private Chat');
$source=$snapshot['items'][$ref];
canvas_pipeline_check($source['profile_source_kind']==='analytics'&&$source['trigger']==='analytics_period'
    &&$source['presentation_owner']==='profile_briefing_v1'&&$source['profile_presentation_owned']===true
    &&$source['legacy_delivered']===false&&$source['question']===''&&$source['next_step']==='',
    'completed facts have a briefing owner without inventing delivered Chat, owner questions or required work');
canvas_pipeline_check(vp3_coordination_source_ref_valid_v1($ref)&&vp3_coordination_valid_refs_v1([$ref=>$source['revision']])
    &&vp3_coordination_profile_presentation_owned_v1(['primary'=>$ref],[$ref=>$source])
    &&vp3_initiative_goal_specialist_v1(['tool'=>'work.prepare_private','primary'=>'specialist:'.$ref])==='profile',
    'source selection, saved-work references and the existing duplicate-presentation guard accept the same exact period');
$projection=vp3_profile_specialist_report_v1($snapshot);
canvas_pipeline_check(count($projection['findings'])===1&&$projection['questions_for_you']===[]&&$projection['actions_followups']===[],
    'the shared five-section Profile report exposes period facts without inventing an owner obligation');
$generic='specialist:'.$ref;
$evidence=[$generic=>['kind'=>'specialist_finding','specialist'=>'profile','private_summary_only'=>true,
    'profile_presentation_owned'=>true,'routing'=>'report']];
$job=vp3_initiative_specialist_job_v1(['tool'=>'work.prepare_private','primary'=>$generic,'refs'=>[$generic],'report'=>true],$evidence);
canvas_pipeline_check($job['report']===false&&vp3_initiative_proactive_specialist_v1($job),
    'generic preparation keeps period facts private instead of creating a competing automatic report');
[$coverage,$catchup]=vp3_catch_up_profile_analytics_v1($pdo,$owner,7,11,$now);
canvas_pipeline_check($coverage==='ready'&&count($catchup)===1&&$catchup[0]['id']===$ref&&$catchup[0]['url']===$source['url']
    &&$catchup[0]['_source_revision']===$source['revision']&&$catchup[0]['reason']===$source['summary'],
    'explicit catch-up uses the same recorded facts, source revision and exact scoped destination');
$answer=vp3_catch_up_chat_result_v1(['items'=>$catchup,'coverage'=>['profile_analytics'=>$coverage],'as_of'=>$now->format(DATE_ATOM)]);
canvas_pipeline_check(str_contains($answer['answer']??$answer['message']??'', 'latest completed Profile period')
    &&!str_contains($answer['answer']??$answer['message']??'', 'what needs your attention'),
    'routine period numbers are introduced as completed-period information');
$candidates=vp3_catch_up_candidates_v1($pdo,$owner,'office',7,false,$now,false,11);
canvas_pipeline_check(in_array($ref,array_column($candidates['items'],'id'),true)&&isset($candidates['coverage']['profile_analytics']),
    'the explicit Chat collector includes the authorized period even without background working sources');
$withoutChat=vp3_catch_up_candidates_v1($pdo,$owner,'office',7,false,$now);
canvas_pipeline_check(!isset($withoutChat['coverage']['profile_analytics'])
    &&!array_filter($withoutChat['items'],static fn(array $item):bool=>str_starts_with($item['id'],'profile:analytics:')),
    'legacy and background catch-up reads cannot guess a private Chat for analytics');
canvas_pipeline_check(profile_period_brief_state()===$before&&$GLOBALS['pipelineInference']===[],
    'period, registry and catch-up reads change no native event, receipt, workflow, Chat or inference ledger');

$pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
$private=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref],$now);
canvas_pipeline_check(($private['items'][$ref]['revision']??null)===$source['revision'],
    'turning off the public Profile does not revoke authorized private historical analytics');
$discovery=vp3_coordination_sources_v1($pdo,$owner,$binding,null,$now,specialistOnly:'profile');
canvas_pipeline_check(isset($discovery['items'][$ref]),
    'ordinary Profile discovery includes verified historical periods while public visibility is off');
$pdo->exec('UPDATE user_profiles SET is_public=1,profile_agent_enabled=1 WHERE user_id=1');
$pdo->prepare("UPDATE profile_agent_conversations SET status='resolved' WHERE id=?")->execute([$fixture['ids']['unanswered_conversation']]);
$backlogChanged=vp3_profile_analytics_period_v1($pdo,$fixture['scope'],['ref'=>$ref],$now->modify('+5 minutes'));
canvas_pipeline_check($backlogChanged['revision']===$period['revision']
    &&$backlogChanged['backlog']['revision']!==$period['backlog']['revision'],
    'a current backlog change has its own revision and cannot rewrite a completed period');
$event=$pdo->query('SELECT * FROM profile_events WHERE id='.(int)$fixture['ids']['event'])->fetch(PDO::FETCH_ASSOC);
$event['id']=993090;$event['dedupe_key']=hash('sha256','outside-saved-period');$event['recorded_at_utc_ms']=($fixture['cutoff']+3600)*1000;
profile_analytics_period_fixture_row_v1($pdo,'profile_events',$event);
$pdo->exec("UPDATE users SET timezone='America/Phoenix' WHERE id=1");
$fixed=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref],$now->modify('+1 day'));
canvas_pipeline_check(($fixed['items'][$ref]['revision']??null)===$source['revision']&&$fixed['items'][$ref]['url']===$source['url'],
    'later activity, the clock moving forward and new timezone settings preserve the exact saved period');
$latest=vp3_profile_analytics_period_v1($pdo,$fixture['scope'],['period'=>1],$now->modify('+1 day'));
canvas_pipeline_check($latest['ref']!==$ref&&$latest['window']['timezone']==='America/Phoenix',
    'Latest uses current owner timezone while an old reference retains its encoded reporting context');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");

$settings=vp3_coordination_rules_v1($pdo,$owner,7);
$assessment=vp3_morning_brief_assess_v1($snapshot['items'],$snapshot['coverage'],$settings,[]);
canvas_pipeline_check(isset($assessment['selected'][$ref])&&$assessment['selected'][$ref]['revision']===$source['revision']
    &&$assessment['links'][0]['url']===$source['url'],
    'the actual morning assessment keeps the fixed ref, revision and exact source link');
$published=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$snapshot,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$snapshot,$now);
});
canvas_pipeline_check($published&&count(profile_period_brief_rows())===1,'the existing morning publisher saves the period once without model inference');
$row=profile_period_brief_rows()[0];$context=json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check($context['sources'][0]['source']===$ref&&$context['sources'][0]['url']===$source['url']
    &&vp3_coordination_chat_history_v1($pdo,$owner,7,11,[$row])===[$row]
    &&vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)===$row,
    'ordinary saved Chat history revalidates the exact period and retains the original destination');
$again=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$snapshot,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$snapshot,$now->modify('+1 hour'));
});
canvas_pipeline_check(!$again&&count(profile_period_brief_rows())===1&&$GLOBALS['pipelineInference']===[],
    'repeated morning reads cannot duplicate the period post or start provider work');
$pdo->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1");
canvas_pipeline_check(vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)===$row,
    'saved briefing history stays valid after timezone preferences change');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$pdo->prepare("UPDATE profile_agent_conversations SET status='open' WHERE id=?")->execute([$fixture['ids']['unanswered_conversation']]);
canvas_pipeline_check(vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)===$row,
    'reopening current backlog preserves the completed-period briefing');
$pdo->prepare("UPDATE profile_agent_conversations SET status='resolved' WHERE id=?")->execute([$fixture['ids']['unanswered_conversation']]);
canvas_pipeline_check(vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)===$row,
    'resolving current backlog does not rewrite a completed-period briefing');
coordination_fixture_rule($pdo,$owner,'profile','analytics_period','watch');
$hidden=vp3_coordination_chat_history_v1($pdo,$owner,7,11,[$row])[0];
canvas_pipeline_check($hidden['message']==='The sources behind this morning brief have changed or are no longer available.',
    'changing the analytics rule to Watch withdraws its saved briefing text and links');
coordination_fixture_rule($pdo,$owner,'profile','analytics_period','report');
canvas_pipeline_check(vp3_coordination_chat_history_v1($pdo,$owner,7,11,[$row])===[$row],
    'restoring the report rule recovers the unchanged exact period without another post');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
canvas_pipeline_check(vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)['message']!==$row['message'],
    'revoking the original private Agent withholds the saved Profile briefing');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
canvas_pipeline_check(vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)['message']!==$row['message'],
    'a live Profile permission revocation defeats inherited cached transport permissions in saved history');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");
canvas_pipeline_check(vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)===$row,
    'restoring the canonical role grant restores the original verified briefing');
$pdo->prepare('UPDATE profile_events SET recorded_at_utc_ms=recorded_at_utc_ms+1 WHERE id=?')->execute([$fixture['ids']['event']]);
$corrected=vp3_profile_analytics_period_v1($pdo,$fixture['scope'],['ref'=>$ref],$now);
canvas_pipeline_check(array_column($corrected['metrics'],null,'key')['visit_records']['current']['value']===$metrics['visit_records']['current']['value']
    &&vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)['message']!==$row['message'],
    'a canonical source-time correction invalidates the old proof even when aggregate totals match');
$pdo->prepare('UPDATE profile_events SET recorded_at_utc_ms=recorded_at_utc_ms-1 WHERE id=?')->execute([$fixture['ids']['event']]);
canvas_pipeline_check(vp3_morning_brief_history_v1($pdo,$owner,7,11,$row,$context)===$row,
    'restoring the original canonical evidence restores the unchanged fixed-period proof');
$pdo->prepare('DELETE FROM profile_events WHERE id=?')->execute([$fixture['ids']['event']]);
$changed=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref],$now);
canvas_pipeline_check($changed['items'][$ref]['revision']!==$source['revision']
    &&vp3_coordination_chat_history_v1($pdo,$owner,7,11,[$row])[0]['message']!==$row['message'],
    'a correction to actual period evidence invalidates the old saved briefing proof');
$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
profile_period_brief_reject(fn()=>vp3_catch_up_profile_analytics_v1($pdo,$owner,7,11,$now),
    'a current Profile grant revocation withholds explicit catch-up facts');
$denied=vp3_coordination_sources_v1($pdo,$owner,$binding,[$ref],$now);
canvas_pipeline_check($denied['items']===[],'the registry withholds period evidence after current Profile grant revocation');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");
profile_period_brief_reject(fn()=>vp3_catch_up_profile_analytics_v1($pdo,$owner,7,12,$now),
    'a sibling Agent Chat cannot receive the selected Agent period');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
profile_period_brief_reject(fn()=>vp3_catch_up_profile_analytics_v1($pdo,$owner,7,11,$now),
    'deactivating the private Agent prevents a fresh period catch-up',0);

echo 'PROFILE_ANALYTICS_BRIEFINGS_V1=PASS ('.($checks-$startChecks).' checks, '.($mysql?'mysql':'sqlite')."; skips=0)\n";
