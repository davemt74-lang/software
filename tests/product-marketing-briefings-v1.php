<?php
declare(strict_types=1);

// Real source registry, current permission catalog, saved morning receipts and
// native scheduler. Product/provider transports and inference are never called.
define('VP3_COORDINATION_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once __DIR__.'/fixtures/product-marketing-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-schedule-v1.php';
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-evidence-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-registry-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$GLOBALS['profileSpecialistCapabilities']=['profile_agent.access'=>true,'profile_chat.access'=>true];
$fixture=product_marketing_followthrough_fixture_v1($pdo);
$owner=$fixture['scope']['user'];$principal=$fixture['scope']['principal'];
$now=new DateTimeImmutable('2026-11-17 08:15:00',new DateTimeZone('UTC'));
$binding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null)['initiative_policy'];
coordination_fixture_rule($pdo,$owner,'curiosity','open_question','watch');
$start=$checks;
require_once dirname(__DIR__).'/includes/agent-coordination-product-marketing-v1.php';
$ref=vp3_product_marketing_outcome_ref_v1($fixture['publications'][0],$fixture['ref'],$now);
$planRef=vp3_product_marketing_plan_ref_v1($fixture['product_ids'][0]);

function pmb_snapshot():array
{
    $out=[];foreach(['agent_product_launches_v1','agent_commerce_marketing_drafts_v1','agent_commerce_promotions_v1',
        'agent_promotion_publications_v1','agent_promotion_clicks_v1','agent_promotion_orders_v1','agent_commerce_checkout_attempts_v800',
        'profile_events','profile_agent_messages','profile_agent_handoff_turns_pa3','agent_commerce_orders_v800',
        'agent_commerce_payments_v800','agent_commerce_refunds_v800','agent_workflow_runs','agent_workflow_receipts',
        'chat_messages','ai_execution_ledger'] as $table)$out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
function pmb_source(array $owner,array $binding,array $refs,DateTimeImmutable $now):array
{
    return vp3_coordination_sources_v1(db(),$owner,$binding,$refs,$now);
}
function pmb_history(array $owner,array $row):array
{
    return vp3_morning_brief_history_v1(db(),$owner,7,11,$row,json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR));
}
function pmb_hidden(array $owner,array $row):bool
{
    return pmb_history($owner,$row)['message']==='The sources behind this morning brief have changed or are no longer available.';
}

$before=pmb_snapshot();$snapshot=pmb_source($owner,$binding,[$ref,$planRef],$now);$item=$snapshot['items'][$ref]??null;$plan=$snapshot['items'][$planRef]??null;
canvas_pipeline_check(is_array($item)&&is_array($plan)&&$snapshot['coverage']['marketing']==='ready',
    'exact product plan and publication-period sources share the real Marketing registry');
canvas_pipeline_check(in_array('marketing',vp3_initiative_specialist_catalog_v1([])['marketing']['report_sources'],true),
    'the existing Marketing specialist remains a report source without requiring Campaign setup');
canvas_pipeline_check(pmb_snapshot()===$before,'source reads cannot publish, recover, create another plan, open checkout, call inference or add a Chat card');
foreach([$ref,$planRef] as $sourceRef)canvas_pipeline_check(vp3_coordination_valid_refs_v1([$sourceRef=>$snapshot['items'][$sourceRef]['revision']])
    &&vp3_coordination_source_ref_valid_v1($sourceRef)
    &&vp3_initiative_goal_specialist_v1(['tool'=>'work.prepare_private','primary'=>'specialist:'.$sourceRef])==='marketing',
    'canonical product evidence keeps one source identity through receipts and specialist attribution');
foreach(['marketing:product-plan:01',str_replace(':v1:1:',':v1:2:',$ref),$ref.':extra'] as $bad)
    canvas_pipeline_check(!vp3_coordination_valid_refs_v1([$bad=>$item['revision']])&&!vp3_coordination_source_ref_valid_v1($bad),
        'malformed product source references cannot enter goals or exact reads');
canvas_pipeline_check($item['presentation_owner']==='initiative_promotion_outcome_v1'&&$item['legacy_delivered']===true
    &&$plan['presentation_owner']==='initiative_product_launch_v1'
    &&vp3_coordination_native_presentation_owned_v1(['primary'=>$ref],[$ref=>$item]),
    'native launch and outcome presentation remain authoritative and use actual saved delivery evidence');

$scope=$fixture['scope'];$scope['binding']=$binding;
$disabled=$binding;$disabled['enabled']=false;
$discovered=vp3_coordination_sources_v1($pdo,$owner,$disabled,null,$now,specialistOnly:'marketing');
canvas_pipeline_check(isset($discovered['items'][$planRef])&&count(array_filter(array_keys($discovered['items']),
    static fn(string $key):bool=>str_starts_with($key,'marketing:product-outcome:')))===2,
    'existing products remain discoverable independently of Campaign setup and automatic-sweep enablement');
$later=$now->modify('+10 days');$pdo->exec("UPDATE users SET timezone='America/Phoenix' WHERE id=1");
canvas_pipeline_check((pmb_source($owner,$binding,[$ref],$later)['items'][$ref]??null)===$item,
    'reopening an exact publication source preserves its completed cutoff and timezone');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
canvas_pipeline_check((pmb_source($owner,$binding,[$ref,$planRef],$now)['items']??null)===$snapshot['items'],
    'private product reports preserve exact saved work when public Profile visibility is off');
$pdo->exec('UPDATE user_profiles SET is_public=1,profile_agent_enabled=1 WHERE user_id=1');

$coverage=[];$generic='specialist:'.$ref;
$evidence=vp3_initiative_specialist_evidence_v1($pdo,$owner,$binding,$now,[$generic],$coverage);
canvas_pipeline_check(isset($evidence[$generic])&&$evidence[$generic]['private_summary_only']===true
    &&$evidence[$generic]['product_marketing_presentation_owned']===true&&$evidence[$generic]['public_evidence_untrusted']===true,
    'shared planning consumes revision-bound private promotion evidence without trusting visitor instructions');
$job=['tool'=>'work.prepare_private','primary'=>$generic,'refs'=>[$generic],'report'=>true];
canvas_pipeline_check(vp3_initiative_specialist_job_v1($job,$evidence)['report']===false
    &&vp3_initiative_proactive_specialist_v1($job),'review preparation keeps native presentation ownership and Marketing attribution');
foreach(['chat.check_in','marketing.publish','commerce.checkout'] as $tool){
    try{vp3_initiative_specialist_job_v1(array_replace($job,['tool'=>$tool]),$evidence);$rejected=false;}
    catch(Vp3InitiativePlanValidationV1){$rejected=true;}
    canvas_pipeline_check($rejected,'promotion findings cannot authorize a sending or state-changing planner tool');
}
coordination_fixture_rule($pdo,$owner,'marketing','product_outcome','watch');$coverage=[];
$watch=vp3_initiative_specialist_evidence_v1($pdo,$owner,$binding,$now,[$generic],$coverage);
canvas_pipeline_check(isset($watch[$generic])&&$watch[$generic]['routing']==='watch',
    'watch-only product findings remain private review context without becoming a new announcement');
coordination_fixture_rule($pdo,$owner,'marketing','product_outcome','report');

[$catchCoverage,$catchItems]=vp3_catch_up_product_marketing_v1($pdo,$owner,7,11,$now);
$defaultRef=vp3_product_marketing_outcome_ref_v1($fixture['publications'][0],vp3_product_marketing_period_v1($pdo,$scope,[],$now)['ref'],$now);
$default=pmb_source($owner,$binding,[$defaultRef],$now)['items'][$defaultRef];
$found=array_values(array_filter($catchItems,static fn(array $row):bool=>$row['id']===$defaultRef));
canvas_pipeline_check($catchCoverage==='ready'&&count($found)===1&&$found[0]['_source_revision']===$default['revision']&&$found[0]['url']===$default['url'],
    'explicit named-Agent catch-up reuses the same exact product-period revision and original Chat destination');
$plain=vp3_catch_up_candidates_v1($pdo,$owner,'commerce',7,false,$now);
$explicit=vp3_catch_up_candidates_v1($pdo,$owner,'commerce',7,false,$now,false,11);
canvas_pipeline_check(!isset($plain['coverage']['product_marketing'])&&($explicit['coverage']['product_marketing']??null)==='ready',
    'background catch-up cannot invent a Chat while the explicit original Chat can read product work');
$spoken=vp3_catch_up_chat_result_v1(['items'=>$found,'coverage'=>['product_marketing'=>'ready'],'as_of'=>$now->format(DATE_ATOM)]);
canvas_pipeline_check(!str_contains(json_encode($spoken,JSON_THROW_ON_ERROR),'needs your attention'),
    'saved product periods are presented as information rather than fabricated unresolved obligations');

$associations=vp3_product_marketing_conversation_associations_v1($pdo,$scope,991002,$now);
canvas_pipeline_check(count($associations['items'])===1&&$associations['items'][0]['public_conversation_id']===991002,
    'a literal accepted visitor exchange links to its exact saved promotion rather than a private Chat ID match');
$association=$associations['items'][0];$related=vp3_profile_specialist_marketing_link_v1($association['source']+[
    'product_id'=>$association['product_id'],'publication_id'=>$association['publication_id']]);
$relatedSource=pmb_source($owner,$binding,[$association['source']['ref']],$now)['items'][$association['source']['ref']];
canvas_pipeline_check($related!==null&&$related['revision']===$relatedSource['revision']&&$related['url']===$relatedSource['url'],
    'the related Profile link uses the actual Marketing source revision and exact fixed publication destination');
$pdo->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1");
canvas_pipeline_check(vp3_product_marketing_conversation_associations_v1($pdo,$scope,991002,$later)===$associations,
    'an unchanged saved exchange cannot renew a Profile finding when time or timezone preferences change');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$wrong=vp3_product_marketing_conversation_associations_v1($pdo,$scope,11,$now);
canvas_pipeline_check($wrong['items']===[]&&$wrong['coverage']==='unavailable',
    'private and public conversation identifiers never substitute for one another');
canvas_pipeline_check(pmb_snapshot()===$before,'report, association and catch-up reads leave source records, provider sessions and native delivery state unchanged');

$settings=vp3_coordination_rules_v1($pdo,$owner,7);$one=['items'=>[$ref=>$item],'coverage'=>['marketing'=>'ready']];
$assessment=vp3_morning_brief_assess_v1($one['items'],$one['coverage'],$settings,[]);
canvas_pipeline_check(isset($assessment['selected'][$ref])&&$assessment['selected'][$ref]['revision']===$item['revision']
    &&$assessment['links'][0]['url']===$item['url'],'the ordinary morning assessment preserves the fixed publication proof and review link');
$post=static fn():bool=>agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$one,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$one,$now);
});
canvas_pipeline_check($post(),'the owner-locked existing morning publisher can include an exact product period');
$rows=$pdo->query("SELECT * FROM chat_messages WHERE role='assistant' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$row=current(array_filter($rows,static fn(array $row):bool=>(json_decode($row['context_json'],true)['source']??null)===VP3_MORNING_BRIEF_SOURCE_V1));
canvas_pipeline_check(is_array($row)&&pmb_history($owner,$row)===$row,'saved morning Chat history revalidates its exact promotion source');
canvas_pipeline_check(!$post(),'a repeated scan cannot duplicate the same Agent/day morning card');
coordination_fixture_rule($pdo,$owner,'marketing','product_outcome','watch');
canvas_pipeline_check(pmb_hidden($owner,$row),'watch-only routing withdraws old automatic promotion briefing prose and links');
coordination_fixture_rule($pdo,$owner,'marketing','product_outcome','report');
canvas_pipeline_check(pmb_history($owner,$row)===$row,'restoring the reporting route restores unchanged verified product evidence');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
canvas_pipeline_check(pmb_hidden($owner,$row),'revoking the original private Agent withholds old promotion briefing content');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
canvas_pipeline_check(pmb_hidden($owner,$row),'revoking Profile excerpt authority invalidates a saved briefing that relied on those exchanges');
$withoutProfile=pmb_source($owner,$binding,[$planRef],$now);
canvas_pipeline_check(isset($withoutProfile['items'][$planRef]),'ordinary private product-plan authority remains independent of Profile excerpt grants');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");
canvas_pipeline_check(pmb_history($owner,$row)===$row,'restoring canonical grants restores the original fixed source');
$pdo->prepare('UPDATE agent_promotion_clicks_v1 SET created_at=? WHERE click_id=?')->execute(['2026-11-16 12:00:01',$fixture['click_ids'][1]]);
canvas_pipeline_check(pmb_hidden($owner,$row),'an exact click-evidence correction invalidates saved briefing proof even if the total is unchanged');

foreach([997601,997602] as $asset)unlink(STONEFELLOW_ROOT.'/private/user-media/1/phase6-'.$asset.'.png');
rmdir(STONEFELLOW_ROOT.'/private/user-media/1');rmdir(STONEFELLOW_ROOT.'/private/user-media');
echo 'PRODUCT_MARKETING_BRIEFINGS_V1=PASS ('.($checks-$start).' checks, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
