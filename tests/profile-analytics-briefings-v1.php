<?php
declare(strict_types=1);

// Exercise the actual source registry, saved briefing proofs, current authority,
// catch-up adapter and native scheduler without starting any external provider.
define('VP3_COORDINATION_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once __DIR__.'/fixtures/profile-analytics-period-data-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-schedule-v1.php';
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-registry-v1.php';
require_once dirname(__DIR__).'/includes/agent-profile-analytics-summary-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$GLOBALS['profileSpecialistCapabilities']=['profile_agent.access'=>true,'profile_chat.access'=>true];
$fixture=profile_analytics_period_fixture_v1($pdo);
$owner=$fixture['scope']['user'];$principal=$fixture['scope']['principal'];
$ref=$fixture['ref'];$now=new DateTimeImmutable('2026-11-17 08:15:00',new DateTimeZone('UTC'));
$binding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null)['initiative_policy'];
coordination_fixture_rule($pdo,$owner,'curiosity','open_question','watch');
$start=$checks;

function pab_snapshot():array
{
    $out=[];foreach(['profile_events','profile_agent_messages','profile_agent_handoff_turns_pa3','profile_agent_handoff_operations_pa3',
        'profile_calls_pa3c','agent_commerce_orders_v800','agent_commerce_payments_v800','agent_commerce_refunds_v800',
        'agent_workflow_runs','agent_workflow_receipts','chat_messages','ai_execution_ledger'] as $table)
        $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
function pab_source(array $owner,array $binding,string $ref,DateTimeImmutable $now):array
{
    return vp3_coordination_sources_v1(db(),$owner,$binding,[$ref],$now);
}
function pab_history(array $owner,array $row):array
{
    return vp3_morning_brief_history_v1(db(),$owner,7,11,$row,json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR));
}
function pab_hidden(array $owner,array $row):bool
{
    return pab_history($owner,$row)['message']==='The sources behind this morning brief have changed or are no longer available.';
}

$before=pab_snapshot();$snapshot=pab_source($owner,$binding,$ref,$now);$item=$snapshot['items'][$ref]??null;
canvas_pipeline_check(is_array($item)&&$snapshot['coverage']['profile']==='ready','the real exact source registry returns the verified completed Profile period');
canvas_pipeline_check(pab_snapshot()===$before,'reading a period source changes no event, call, payment, Chat, receipt or inference row');
canvas_pipeline_check(vp3_coordination_valid_refs_v1([$ref=>$item['revision']])&&vp3_coordination_source_ref_valid_v1($ref)
    &&vp3_initiative_goal_specialist_v1(['tool'=>'work.prepare_private','primary'=>'specialist:'.$ref])==='profile',
    'the fixed period has one recognized source identity in references, receipts and specialist attribution');
canvas_pipeline_check($item['presentation_owner']==='profile_briefing_v1'&&$item['profile_presentation_owned']===true
    &&$item['legacy_delivered']===false&&vp3_coordination_profile_presentation_owned_v1(['primary'=>$ref],[$ref=>$item]),
    'the ordinary briefing owns period presentation without a fabricated delivery receipt or duplicate specialist interruption');
$projection=vp3_profile_specialist_report_v1($snapshot);
canvas_pipeline_check(count($projection['findings'])===1&&$projection['questions_for_you']===[]&&$projection['actions_followups']===[],
    'the shared five-section Profile report exposes period facts without inventing an owner obligation');

// Original timezone and completed cutoff stay part of the saved identity.
$later=$now->modify('+10 days');
$pdo->exec("UPDATE users SET timezone='America/Phoenix' WHERE id=1");
$same=pab_source($owner,$binding,$ref,$later)['items'][$ref]??null;
canvas_pipeline_check($same===$item,'a later read and a changed owner timezone preserve the original completed-period source and revision');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
canvas_pipeline_check((pab_source($owner,$binding,$ref,$now)['items'][$ref]??null)===$item,
    'private owner analytics remain readable when the public Profile and responder are off');
$discovery=vp3_coordination_sources_v1($pdo,$owner,$binding,null,$now,specialistOnly:'profile');
canvas_pipeline_check(isset($discovery['items'][$ref]),'ordinary Profile discovery can include verified historical periods while public visibility is off');
$pdo->exec('UPDATE user_profiles SET is_public=1,profile_agent_enabled=1 WHERE user_id=1');

[$coverage,$catchup]=vp3_catch_up_profile_analytics_v1($pdo,$owner,7,11,$now);
canvas_pipeline_check($coverage==='ready'&&count($catchup)===1&&$catchup[0]['id']===$ref&&$catchup[0]['url']===$item['url']
    &&$catchup[0]['_source_revision']===$item['revision'],'explicit catch-up uses the same current period proof and original private Chat destination');
$plain=vp3_catch_up_candidates_v1($pdo,$owner,'office',7,false,$now);
$explicit=vp3_catch_up_candidates_v1($pdo,$owner,'office',7,false,$now,false,11);
canvas_pipeline_check(!isset($plain['coverage']['profile_analytics'])&&isset($explicit['coverage']['profile_analytics'])
    &&count(array_filter($explicit['items'],static fn(array $row):bool=>$row['id']===$ref))===1,
    'background catch-up has no implicit Chat while an explicit authorized Chat adds the period once');
$spoken=vp3_catch_up_chat_result_v1(['items'=>[$explicit['items'][array_search($ref,array_column($explicit['items'],'id'),true)]],
    'coverage'=>['profile_analytics'=>'ready'],'as_of'=>$now->format(DATE_ATOM)]);
canvas_pipeline_check(!str_contains(json_encode($spoken,JSON_THROW_ON_ERROR),'needs your attention'),
    'informational period facts are not announced as an unresolved priority');
canvas_pipeline_check(pab_snapshot()===$before,'period discovery, report and catch-up remain read-only across all native source tables');

$settings=vp3_coordination_rules_v1($pdo,$owner,7);
$assessment=vp3_morning_brief_assess_v1($snapshot['items'],$snapshot['coverage'],$settings,[]);
canvas_pipeline_check(isset($assessment['selected'][$ref])&&$assessment['selected'][$ref]['revision']===$item['revision']
    &&$assessment['links'][0]['url']===$item['url'],'the actual morning assessment persists the fixed ref, revision and exact source link');
$posted=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$snapshot,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$snapshot,$now);
});
canvas_pipeline_check($posted,'the existing owner-locked morning publication writes a real period briefing');
$rows=$pdo->query("SELECT * FROM chat_messages WHERE role='assistant' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$row=current(array_filter($rows,static fn(array $row):bool=>(json_decode($row['context_json'],true)['source']??null)===VP3_MORNING_BRIEF_SOURCE_V1));
canvas_pipeline_check(is_array($row)&&pab_history($owner,$row)===$row,'saved Chat history revalidates the original period through the real registry');
$postedAgain=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$snapshot,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$snapshot,$now);
});
canvas_pipeline_check(!$postedAgain,'repeated scans do not deliver another briefing for the same Agent and local day');
$pdo->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1");
canvas_pipeline_check(pab_history($owner,$row)===$row,'saved briefing history stays valid after timezone preferences change');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$pdo->exec("UPDATE profile_agent_conversations SET status='resolved' WHERE id=".(int)$fixture['ids']['unanswered_conversation']);
canvas_pipeline_check(pab_history($owner,$row)===$row,'resolving current backlog does not rewrite a completed-period briefing');
coordination_fixture_rule($pdo,$owner,'profile','analytics_period','watch');
canvas_pipeline_check(pab_hidden($owner,$row),'changing the current analytics rule to watch withdraws saved briefing prose and links');
coordination_fixture_rule($pdo,$owner,'profile','analytics_period','report');
canvas_pipeline_check(pab_history($owner,$row)===$row,'restoring a reporting rule restores an unchanged verified briefing');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
canvas_pipeline_check(pab_hidden($owner,$row),'revoking the original private Agent withholds the saved Profile briefing');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
canvas_pipeline_check(pab_hidden($owner,$row),'a live permission revocation defeats inherited cached transport permissions');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");
canvas_pipeline_check(pab_history($owner,$row)===$row,'the restored canonical role grant permits the original verified briefing');
$pdo->exec('UPDATE profile_events SET recorded_at_utc_ms=recorded_at_utc_ms+1 WHERE id=993001');
canvas_pipeline_check(pab_hidden($owner,$row),'a canonical source-time correction invalidates the old proof even when aggregate totals match');
echo 'PROFILE_ANALYTICS_BRIEFINGS_V1=PASS ('.($checks-$start).' checks, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
