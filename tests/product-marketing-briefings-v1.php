<?php
declare(strict_types=1);

// Retained parallel-build entry point: actual registry, explicit Chat catch-up,
// source routing and saved morning proof all consume the canonical collector.
define('VP3_COORDINATION_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once __DIR__.'/fixtures/profile-commerce-followthrough-data-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-product-marketing-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-schedule-v1.php';
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-evidence-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-registry-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-chat-v1.php';
function get_tracks():array{return [];}
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$GLOBALS['profileSpecialistCapabilities']=['profile_agent.access'=>true,'profile_chat.access'=>true];
$fixture=profile_commerce_followthrough_fixture_v1($pdo);$owner=$fixture['scope']['user'];$principal=$fixture['scope']['principal'];
$now=(new DateTimeImmutable('@'.($fixture['cutoff']+9*3600)))->setTimezone(new DateTimeZone('UTC'));
$binding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null)['initiative_policy'];
coordination_fixture_rule($pdo,$owner,'curiosity','open_question','watch');
$start=$checks;$ref=$fixture['ref'];$alias='profile:'.substr($ref,10);$planRef='marketing:product-plan:993101';
$GLOBALS['pipelineRuntimePlan']=['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];
profile_analytics_period_fixture_table_v1($pdo,'agent_commerce_product_bindings_v800',
    ['product_id'=>'BIGINT PRIMARY KEY','binding_type'=>"VARCHAR(40) NOT NULL DEFAULT ''",'binding_id'=>'BIGINT NULL']);
profile_analytics_period_fixture_table_v1($pdo,'agent_commerce_products_v800',
    ['metadata_json'=>'TEXT NULL','is_active'=>'INTEGER NOT NULL DEFAULT 1','description'=>'TEXT NULL',
        'product_type'=>"VARCHAR(32) NOT NULL DEFAULT 'physical'",'fulfillment_type'=>"VARCHAR(32) NOT NULL DEFAULT 'manual'",
        'payment_mode'=>"VARCHAR(24) NOT NULL DEFAULT 'one_time'",'price_cents'=>'BIGINT NOT NULL DEFAULT 2000',
        'deposit_cents'=>'BIGINT NOT NULL DEFAULT 0','currency'=>"VARCHAR(8) NOT NULL DEFAULT 'usd'",'updated_at'=>'DATETIME NULL']);
$private=$pdo->query('SELECT * FROM agent_commerce_products_v800 WHERE id='.(int)$fixture['ids']['product'])->fetch(PDO::FETCH_ASSOC);
$private['id']=993101;$private['product_key']='profile:private-briefing-plan';$private['title']='Private T-shirt listing';$private['is_active']=0;
$private['metadata_json']=json_encode(['listing_draft'=>'product_text_v1','agent_id'=>7,'conversation_id'=>11,'source_title'=>'Description','visibility'=>'hidden'],JSON_THROW_ON_ERROR);
profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_products_v800',$private);
profile_analytics_period_fixture_row_v1($pdo,'agent_product_launches_v1',['product_id'=>993101,'owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>11,
    'draft_key'=>null,'creative_id'=>null,'last_stage'=>'listing_review','last_notice_key'=>'','next_check_at'=>'2020-01-01 00:00:00'],'product_id');

function pmb_snapshot():array
{
    $out=[];
    foreach(['agent_product_launches_v1','agent_commerce_products_v800','agent_commerce_marketing_drafts_v1','agent_commerce_promotions_v1',
        'agent_promotion_publications_v1','agent_promotion_clicks_v1','agent_promotion_orders_v1','agent_commerce_checkout_attempts_v800',
        'profile_agent_conversations','profile_agent_messages','profile_agent_handoff_turns_pa3','agent_commerce_orders_v800',
        'agent_commerce_payments_v800','agent_commerce_refunds_v800','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts',
        'chat_messages','ai_execution_ledger'] as $table){
        $rows=array_map(static fn(array $r):string=>json_encode($r,JSON_THROW_ON_ERROR),db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows,SORT_STRING);$out[$table]=hash('sha256',implode("\n",$rows));
    }
    return $out;
}
function pmb_source(array $refs,?DateTimeImmutable $now=null):array
{return vp3_coordination_sources_v1(db(),$GLOBALS['owner'],$GLOBALS['binding'],$refs,$now??$GLOBALS['now']);}
function pmb_history(array $row):array
{return vp3_coordination_chat_history_v1(db(),$GLOBALS['owner'],7,11,[$row])[0];}

$before=pmb_snapshot();$snapshot=pmb_source([$ref,$alias,$planRef]);
$item=$snapshot['items'][$ref]??null;$plan=$snapshot['items'][$planRef]??null;
canvas_pipeline_check(is_array($item)&&is_array($plan)&&$snapshot['coverage']['marketing']==='ready',
    'the existing Marketing registry returns exact private plans and canonical completed periods');
canvas_pipeline_check(in_array('marketing',vp3_initiative_specialist_catalog_v1([])['marketing']['report_sources'],true),
    'the existing Marketing specialist remains available without Campaign setup');
foreach([$ref,$alias,$planRef] as $sourceRef)canvas_pipeline_check(vp3_coordination_valid_refs_v1([$sourceRef=>$snapshot['items'][$sourceRef]['revision']])
    &&vp3_coordination_source_ref_valid_v1($sourceRef),'exact canonical proof fits registry and saved receipt validation');
foreach(['marketing:product-plan:01',str_replace(':v1:'.$fixture['publication_id'].':1:',':v1:'.$fixture['publication_id'].':2:',$ref),$ref.':extra'] as $bad)
    canvas_pipeline_check(!vp3_coordination_source_ref_valid_v1($bad)&&!vp3_coordination_valid_refs_v1([$bad=>$item['revision']]),
        'noncanonical product sources cannot enter saved work');
canvas_pipeline_check($item['presentation_owner']==='product_marketing_briefing_v1'&&!$item['legacy_delivered']
    &&$plan['presentation_owner']==='agent_product_launch'&&!$plan['legacy_delivered'],
    'native plan and ordinary briefing presenters retain ownership without fabricated delivery');

$generic='specialist:'.$ref;$coverage=[];
$evidence=vp3_initiative_specialist_evidence_v1($pdo,$owner,$binding,$now,[$generic],$coverage);
canvas_pipeline_check(isset($evidence[$generic])&&$evidence[$generic]['private_summary_only']===true
    &&$evidence[$generic]['marketing_presentation_owned']===true&&$evidence[$generic]['public_evidence_untrusted']===true,
    'shared planning receives private revision-bound evidence without trusting visitor instructions');
$job=['tool'=>'work.prepare_private','primary'=>$generic,'refs'=>[$generic],'report'=>true];
canvas_pipeline_check(vp3_initiative_specialist_job_v1($job,$evidence)['report']===false
    &&vp3_initiative_proactive_specialist_v1($job),'private preparation cannot create a competing automatic presenter');
foreach(['chat.check_in','marketing.publish','commerce.checkout'] as $tool){
    try{vp3_initiative_specialist_job_v1(array_replace($job,['tool'=>$tool]),$evidence);$rejected=false;}
    catch(Vp3InitiativePlanValidationV1){$rejected=true;}
    canvas_pipeline_check($rejected,'observed marketing facts cannot authorize a sending or publishing planner tool');
}
coordination_fixture_rule($pdo,$owner,'marketing','promotion_period','watch');$coverage=[];
$watch=vp3_initiative_specialist_evidence_v1($pdo,$owner,$binding,$now,[$generic],$coverage);
canvas_pipeline_check(isset($watch[$generic])&&$watch[$generic]['routing']==='watch',
    'Watch keeps current marketing evidence available as private review context');
$ruleQuery=$pdo->prepare("SELECT id,metadata_json FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_policy' AND subject='top-of-mind-routing'");
$ruleQuery->execute();$ruleRow=$ruleQuery->fetch(PDO::FETCH_ASSOC);$ruleQuery->closeCursor();
if(!$ruleRow)throw new LogicException('Missing saved routing fixture');
$legacy=json_decode($ruleRow['metadata_json'],true,32,JSON_THROW_ON_ERROR);
$legacy['rules']['marketing']['product_outcome']=$legacy['rules']['marketing']['promotion_period'];
unset($legacy['rules']['marketing']['promotion_period']);$legacyJson=json_encode($legacy,JSON_THROW_ON_ERROR);
$pdo->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=?')->execute([$legacyJson,$ruleRow['id']]);
try{
    $legacySettings=vp3_coordination_rules_v1($pdo,$owner,7);
    canvas_pipeline_check($legacySettings['rules']['marketing']['promotion_period']==='watch'
        &&!isset($legacySettings['rules']['marketing']['product_outcome'])
        &&vp3_morning_brief_assess_v1([$ref=>$item,$alias=>$snapshot['items'][$alias]],['marketing'=>'ready','profile'=>'ready'],$legacySettings,[])===null,
        'a released product_outcome Watch preference still suppresses both canonical Marketing and Profile aliases');
    $q=$pdo->prepare('SELECT metadata_json FROM agent_memory_items WHERE id=?');$q->execute([$ruleRow['id']]);
    canvas_pipeline_check($q->fetchColumn()===$legacyJson,'reading a released routing preference never migrates or rewrites owner settings');$q->closeCursor();
    try{vp3_coordination_rule_patch_v1($legacySettings['rules'],['marketing'=>['product_outcome'=>'watch','promotion_period'=>'report']]);$conflict=false;}
    catch(InvalidArgumentException){$conflict=true;}
    canvas_pipeline_check($conflict,'conflicting old and current reporting choices cannot silently reopen delivery');
}finally{$pdo->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=?')->execute([$ruleRow['metadata_json'],$ruleRow['id']]);}
coordination_fixture_rule($pdo,$owner,'marketing','promotion_period','report');

[$catchCoverage,$catchItems]=vp3_catch_up_product_marketing_v1($pdo,$owner,7,11,$now);
$found=array_values(array_filter($catchItems,static fn(array $row):bool=>$row['id']===$ref));
canvas_pipeline_check(in_array($catchCoverage,['ready','limited'],true)&&count($found)===1
    &&$found[0]['_source_revision']===$item['revision']&&$found[0]['url']===$item['url'],
    'explicit original-Chat catch-up uses the same canonical period, revision and exact destination');
$foundPlans=array_values(array_filter($catchItems,static fn(array $row):bool=>$row['id']===$planRef));
canvas_pipeline_check(count($foundPlans)===1&&$foundPlans[0]['_source_revision']===$plan['revision'],
    'explicit catch-up includes the existing current plan without creating a replacement');
[$noChatCoverage,$noChatItems]=vp3_catch_up_product_marketing_v1($pdo,$owner,7,0,$now);
canvas_pipeline_check($noChatCoverage==='unconfigured'&&$noChatItems===[],
    'a background request cannot invent an original private Chat');
$plain=vp3_catch_up_candidates_v1($pdo,$owner,'commerce',7,false,$now);
$explicit=vp3_catch_up_candidates_v1($pdo,$owner,'commerce',7,false,$now,false,11);
canvas_pipeline_check(!isset($plain['coverage']['product_marketing'])
    &&in_array($explicit['coverage']['product_marketing']??null,['ready','limited'],true),
    'the actual catch-up router adds product evidence only for an explicit saved Chat');
$spoken=vp3_catch_up_chat_result_v1(['items'=>$found,'coverage'=>['product_marketing'=>'ready'],'as_of'=>$now->format(DATE_ATOM)]);
canvas_pipeline_check(!str_contains(json_encode($spoken,JSON_THROW_ON_ERROR),'needs your attention'),
    'completed promotion facts are not narrated as an invented unresolved obligation');
$pdo->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1");
canvas_pipeline_check(pmb_source([$ref],$now->modify('+10 days'))['items'][$ref]===$item,
    'exact saved evidence keeps the original period across later timezone and clock changes');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
canvas_pipeline_check(pmb_source([$ref])['items'][$ref]===$item,
    'private historical briefing proof remains available when the public Profile is hidden');
$pdo->exec('UPDATE user_profiles SET is_public=1,profile_agent_enabled=1 WHERE user_id=1');
canvas_pipeline_check(pmb_snapshot()===$before&&$GLOBALS['pipelineInference']===[],
    'registry, routing and catch-up reads leave every source, workflow, provider record and Chat row unchanged');

$settings=vp3_coordination_rules_v1($pdo,$owner,7);
$assessment=vp3_morning_brief_assess_v1([$ref=>$item,$alias=>$snapshot['items'][$alias]],['marketing'=>'ready','profile'=>'ready'],$settings,[]);
canvas_pipeline_check(array_keys($assessment['selected'])===[$ref]&&count($assessment['links'])===1,
    'Marketing and Profile aliases become one ordinary briefing proof and one exact link');
$one=['items'=>[$ref=>$item],'coverage'=>['marketing'=>'ready']];
$post=static fn():bool=>agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$one,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$one,$now);
});
canvas_pipeline_check($post(),'the existing owner-locked morning publisher saves one exact marketing period');
$rows=$pdo->query("SELECT * FROM chat_messages WHERE role='assistant' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$row=current(array_filter($rows,static fn(array $r):bool=>(json_decode((string)$r['context_json'],true)['source']??null)===VP3_MORNING_BRIEF_SOURCE_V1));
canvas_pipeline_check(is_array($row)&&pmb_history($row)===$row,'saved ordinary Chat history revalidates the canonical publication proof');
canvas_pipeline_check(!$post(),'repeated assessment cannot post the same morning period twice');
coordination_fixture_rule($pdo,$owner,'marketing','promotion_period','watch');
canvas_pipeline_check(pmb_history($row)['message']!==$row['message'],'current Watch routing withdraws old automatic marketing prose and links');
coordination_fixture_rule($pdo,$owner,'marketing','promotion_period','report');
canvas_pipeline_check(pmb_history($row)===$row,'restored report routing recovers unchanged proof without a new message');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
canvas_pipeline_check(pmb_history($row)['message']!==$row['message'],'private Agent revocation withdraws saved marketing briefing details');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
canvas_pipeline_check(pmb_history($row)['message']!==$row['message']&&isset(pmb_source([$planRef])['items'][$planRef]),
    'revoked Profile reporting authority withdraws outcomes while independent private plan authority remains');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");
canvas_pipeline_check(pmb_history($row)===$row,'restored current source grants recover the exact original briefing');
$pdo->prepare('UPDATE agent_promotion_clicks_v1 SET created_at=? WHERE click_id=?')->execute([
    gmdate('Y-m-d H:i:s',$fixture['cutoff']-86400+36031),$fixture['ids']['native_click']]);
canvas_pipeline_check(pmb_history($row)['message']!==$row['message'],
    'a click evidence correction invalidates saved text even when its displayed aggregate is unchanged');
echo 'PRODUCT_MARKETING_BRIEFINGS_V1=PASS ('.($checks-$start).' checks; driver='.($mysql?'mysql':'sqlite').'; skips=0)'.PHP_EOL;
