<?php
declare(strict_types=1);

// Actual registry, native plan publisher, scheduler, saved morning proof and
// current role records. Physical model/provider calls remain fixture-only.
define('VP3_COORDINATION_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-coordination-pipeline-v1.php';
require_once __DIR__.'/fixtures/profile-commerce-followthrough-data-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-product-marketing-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-evidence-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-registry-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-schedule-v1.php';
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
require_once dirname(__DIR__).'/includes/initiative-product-launch-v1.php';
function get_tracks():array{return [];}
function pmp_state():array
{
    $out=[];
    foreach(['agent_product_launches_v1','agent_commerce_products_v800','agent_commerce_marketing_drafts_v1','agent_commerce_promotions_v1',
        'agent_promotion_publications_v1','agent_promotion_clicks_v1','agent_promotion_orders_v1',
        'profile_agent_conversations','profile_agent_messages','profile_agent_handoff_turns_pa3','profile_agent_handoff_operations_pa3',
        'agent_commerce_orders_v800','agent_commerce_checkout_attempts_v800','agent_commerce_payments_v800','agent_commerce_refunds_v800',
        'agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts','chat_messages','ai_execution_ledger'] as $table){
        $rows=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        $encoded=array_map(static fn(array $row):string=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($encoded,SORT_STRING);
        $out[$table]=hash('sha256',implode("\n",$encoded));
    }
    return $out;
}
function pmp_briefs():array
{
    return array_values(array_filter(db()->query("SELECT * FROM chat_messages WHERE role='assistant' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row):bool=>(json_decode((string)$row['context_json'],true)['source']??null)===VP3_MORNING_BRIEF_SOURCE_V1));
}
function pmp_history(array $owner,array $row):array{return vp3_coordination_chat_history_v1(db(),$owner,7,11,[$row])[0];}
function pmp_source(array $owner,array $binding,array $refs,DateTimeImmutable $now):array{return vp3_coordination_sources_v1(db(),$owner,$binding,$refs,$now);}

$start=$checks;
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$GLOBALS['profileSpecialistCapabilities']=['profile_agent.access'=>true,'profile_chat.access'=>true];
$fixture=profile_commerce_followthrough_fixture_v1($pdo);
$owner=$fixture['scope']['user'];$principal=$fixture['scope']['principal'];$ref=$fixture['ref'];$alias='profile:'.substr($ref,10);
$now=(new DateTimeImmutable('@'.($fixture['cutoff']+9*3600)))->setTimezone(new DateTimeZone('UTC'));
$binding=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null)['initiative_policy'];
coordination_fixture_rule($pdo,$owner,'curiosity','open_question','watch');
$GLOBALS['pipelineRuntimePlan']=['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];

// A description-only listing exercises the real native review checkpoint
// without a fake image path or a model-generated product.
profile_analytics_period_fixture_table_v1($pdo,'agent_commerce_product_bindings_v800',
    ['product_id'=>'BIGINT PRIMARY KEY','binding_type'=>"VARCHAR(40) NOT NULL DEFAULT ''",'binding_id'=>'BIGINT NULL']);
profile_analytics_period_fixture_table_v1($pdo,'agent_commerce_products_v800',
    ['metadata_json'=>'TEXT NULL','is_active'=>'INTEGER NOT NULL DEFAULT 1','description'=>'TEXT NULL',
        'product_type'=>"VARCHAR(32) NOT NULL DEFAULT 'physical'",'fulfillment_type'=>"VARCHAR(32) NOT NULL DEFAULT 'manual'",
        'payment_mode'=>"VARCHAR(24) NOT NULL DEFAULT 'one_time'",'price_cents'=>'BIGINT NOT NULL DEFAULT 2000',
        'deposit_cents'=>'BIGINT NOT NULL DEFAULT 0','currency'=>"VARCHAR(8) NOT NULL DEFAULT 'usd'",'updated_at'=>'DATETIME NULL']);
$private=$pdo->query('SELECT * FROM agent_commerce_products_v800 WHERE id='.(int)$fixture['ids']['product'])->fetch(PDO::FETCH_ASSOC);
$private['id']=993101;$private['product_key']='profile:private-followthrough-plan';$private['title']='Private T-shirt listing';$private['is_active']=0;
$private['metadata_json']=json_encode(['listing_draft'=>'product_text_v1','agent_id'=>7,'conversation_id'=>11,'source_title'=>'Description','visibility'=>'hidden'],JSON_THROW_ON_ERROR);
profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_products_v800',$private);
profile_analytics_period_fixture_row_v1($pdo,'agent_product_launches_v1',['product_id'=>993101,'owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>11,
    'draft_key'=>null,'creative_id'=>null,'last_stage'=>'listing_review','last_notice_key'=>str_repeat('f',64),'next_check_at'=>'2020-01-01 00:00:00'],'product_id');
$planRef='marketing:product-plan:993101';
$before=pmp_state();$snapshot=pmp_source($owner,$binding,[$planRef,$ref,$alias],$now);
canvas_pipeline_check(isset($snapshot['items'][$planRef],$snapshot['items'][$ref],$snapshot['items'][$alias]),
    'the actual registry resolves exact private plan and shared Profile/Marketing period sources');
canvas_pipeline_check($snapshot['coverage']['marketing']==='ready'&&$snapshot['coverage']['profile']==='ready',
    'complete canonical fixtures retain ready source coverage without requiring the Campaigns plugin');
$plan=$snapshot['items'][$planRef];$period=$snapshot['items'][$ref];$profile=$snapshot['items'][$alias];
canvas_pipeline_check($plan['status']==='listing_review'&&$plan['presentation_owner']==='agent_product_launch'
    &&$plan['legacy_delivered']===false&&$plan['question']===''&&str_contains($plan['url'],'marketing_plan=993101'),
    'a saved review checkpoint keeps native approval ownership and bookkeeping is not a delivery receipt');
canvas_pipeline_check($period['revision']===$profile['revision']&&$period['canonical_ref']===$profile['canonical_ref']
    &&$period['url']===$profile['url']&&str_contains($period['title'],'Original T-shirt promotion'),
    'shared reports use the exact older publication title and one proof rather than the current creative');
foreach([$planRef,$ref,$alias] as $source)canvas_pipeline_check(vp3_coordination_source_ref_valid_v1($source)
    &&vp3_coordination_valid_refs_v1([$source=>$snapshot['items'][$source]['revision']]),
    'canonical product references fit actual source, saved-work and receipt validation');
canvas_pipeline_check(pmp_state()===$before&&$GLOBALS['pipelineInference']===[],
    'reading the registry writes no plan, image, publication, click, order, payment, workflow, Chat or inference row');

$settings=vp3_coordination_rules_v1($pdo,$owner,7);
$assessment=vp3_morning_brief_assess_v1($snapshot['items'],$snapshot['coverage'],$settings,[]);
canvas_pipeline_check(array_keys($assessment['selected'])===[$ref]&&count($assessment['links'])===1,
    'one canonical Marketing period enters the brief while its Profile alias and watched private plan do not duplicate it');
$watch=$settings;$watch['rules']=vp3_coordination_rule_patch_v1($settings['rules'],['marketing'=>['promotion_period'=>'watch']]);
canvas_pipeline_check(vp3_morning_brief_assess_v1($snapshot['items'],$snapshot['coverage'],$watch,[])===null,
    'Marketing Watch suppresses both canonical and Profile-alias briefing routes');
$profileOnly=vp3_morning_brief_assess_v1([$alias=>$profile],['profile'=>'ready'],$settings,[]);
canvas_pipeline_check(array_keys($profileOnly['selected'])===[$alias]&&$profileOnly['links'][0]['url']===$period['url'],
    'a Profile-only brief retains its exact authorized alias when no canonical duplicate is present');
coordination_fixture_rule($pdo,$owner,'marketing','product_plan','report');
$coverage=[];$evidence=vp3_initiative_specialist_evidence_v1($pdo,$owner,$binding,$now,
    ['specialist:'.$planRef,'specialist:'.$ref,'specialist:'.$alias],$coverage,6);
foreach([$planRef,$ref,$alias] as $source){
    $key='specialist:'.$source;$job=['tool'=>'work.prepare_private','primary'=>$key,'refs'=>[$key],'report'=>true];
    canvas_pipeline_check(isset($evidence[$key])&&vp3_initiative_specialist_job_v1($job,$evidence)['report']===false
        &&vp3_initiative_proactive_specialist_v1($job),
        'generic preparation keeps the owned plan/period private rather than creating another automatic presenter');
    canvas_pipeline_check(vp3_initiative_goal_specialist_v1($job)===(str_starts_with($source,'profile:')?'profile':'marketing'),
        'actual assignment attribution retains the owning specialist');
    canvas_pipeline_check(vp3_coordination_native_presentation_owned_v1(['primary'=>$source],$snapshot['items']),
        'the scheduler and final delivery share the verified native presentation hold');
}
$phase=null;
agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,&$phase):void{
    vp3_initiative_owner_lock_v1($pdo,$owner);
    vp3_coordination_schedule_v1($pdo,$owner,$binding,true,$phase,false,false);
});
$checkpoint=vp3_coordination_scan_checkpoint_v1($owner,$binding);
canvas_pipeline_check(coordination_fixture_runs('coordination.review',$planRef)===[]
    &&!array_filter(coordination_fixture_runs('coordination.review'),static fn(array $run):bool=>
        str_starts_with($run['goal']['primary']??'','marketing:ft:'))
    &&in_array('native_presentation',array_column($checkpoint['specialists']['marketing']['dispositions']??[],'reason'),true),
    'the real coordinator records native ownership and cannot queue duplicate plan or promotion reports');
coordination_fixture_rule($pdo,$owner,'marketing','product_plan','watch');

$posted=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$snapshot,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$snapshot,$now);
});
canvas_pipeline_check($posted&&count(pmp_briefs())===1,'the existing morning publisher saves one ordinary Chat briefing for the shared promotion evidence');
$row=pmp_briefs()[0];
canvas_pipeline_check(pmp_history($owner,$row)===$row,'saved Chat history revalidates the exact original promotion publication and completed period');
$again=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$binding,$snapshot,$now):bool{
    vp3_initiative_owner_lock_v1($pdo,$owner);return vp3_morning_brief_v1($pdo,$owner,$binding,$snapshot,$now->modify('+1 hour'));
});
canvas_pipeline_check(!$again&&count(pmp_briefs())===1&&$GLOBALS['pipelineInference']===[],
    'a repeated read neither duplicates the briefing nor invokes inference');
$pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused' WHERE id=?")->execute([$fixture['ids']['current_creative']]);
$pdo->exec("UPDATE users SET timezone='America/Phoenix' WHERE id=1");
$later=pmp_source($owner,$binding,[$ref,$alias],$now->modify('+5 days'));
canvas_pipeline_check($later['items'][$ref]['revision']===$period['revision']&&pmp_history($owner,$row)===$row,
    'pausing a newer creative, changing timezone and moving the clock do not rewrite the original saved period');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
coordination_fixture_rule($pdo,$owner,'marketing','promotion_period','watch');
canvas_pipeline_check(pmp_history($owner,$row)['message']!==$row['message'],
    'a current Marketing Watch rule withdraws saved briefing prose and links');
coordination_fixture_rule($pdo,$owner,'marketing','promotion_period','report');
canvas_pipeline_check(pmp_history($owner,$row)===$row,'restoring the rule restores the original unchanged source proof without another post');

$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
$privateOnly=pmp_source($owner,$binding,[$planRef,$ref],$now);
canvas_pipeline_check(isset($privateOnly['items'][$planRef])&&!isset($privateOnly['items'][$ref])
    &&pmp_history($owner,$row)['message']!==$row['message'],
    'revoked public Profile reporting access withholds outcomes while current private plan facts remain available');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','profile_chat.access')");
canvas_pipeline_check(pmp_history($owner,$row)===$row,'restored current Profile authority permits the unchanged original briefing');
$pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
canvas_pipeline_check(pmp_source($owner,$binding,[$ref],$now)['items'][$ref]['revision']===$period['revision'],
    'public Profile visibility does not revoke authorized private historical promotion reporting');
$pdo->exec('UPDATE user_profiles SET is_public=1,profile_agent_enabled=1 WHERE user_id=1');

// Exercise the real existing native plan publisher independently of source reads.
$pdo->exec("UPDATE agent_product_launches_v1 SET last_notice_key='' WHERE product_id=993101");
canvas_pipeline_check(initiative_product_launch_deliver_v1($pdo,993101),'the existing native publisher can deliver the exact private listing review once');
$delivered=pmp_source($owner,$binding,[$planRef],$now)['items'][$planRef];
canvas_pipeline_check($delivered['legacy_delivered']===true&&$delivered['revision']===$plan['revision'],
    'only the actual canonical assistant message proves native plan delivery and does not change its semantic revision');
$pdo->exec("DELETE FROM chat_messages WHERE context_json LIKE '%agent_product_launch%'");
canvas_pipeline_check(pmp_source($owner,$binding,[$planRef],$now)['items'][$planRef]['legacy_delivered']===false,
    'removing the actual native message cannot leave bookkeeping masquerading as delivered Chat');

$wrong=$binding;$wrong['conversation_id']=12;
try{$other=pmp_source($owner,$wrong,[$ref,$planRef],$now);$withheld=$other['items']===[];}
catch(RuntimeException $error){$withheld=$error->getCode()===403;}
canvas_pipeline_check($withheld,'coincident or sibling private Chat IDs never substitute for the original publication scope');
$pdo->prepare('UPDATE agent_promotion_clicks_v1 SET created_at=? WHERE click_id=?')->execute([
    gmdate('Y-m-d H:i:s',$fixture['cutoff']-86400+36031),$fixture['ids']['native_click']]);
$corrected=pmp_source($owner,$binding,[$ref],$now);
canvas_pipeline_check(($corrected['items'][$ref]['revision']??null)!==$period['revision']&&pmp_history($owner,$row)['message']!==$row['message'],
    'a canonical click-time correction invalidates saved history even when its aggregate count is unchanged');

// Complete the canonical public-target schema for native creative transitions.
// These are isolated setup rows; none is installed by a reporting read.
foreach([
    'users'=>['avatar_path'=>"VARCHAR(255) NOT NULL DEFAULT ''"],
    'agent_commerce_team_provider_v800'=>['id'=>'BIGINT PRIMARY KEY'],
    'agent_commerce_audit_v800'=>['id'=>'BIGINT PRIMARY KEY'],
    'agent_commerce_orders_v800'=>['fulfillment_ref_type'=>"VARCHAR(32) NOT NULL DEFAULT ''"],
    'agent_commerce_order_items_v800'=>['fulfillment_ref_type'=>"VARCHAR(32) NOT NULL DEFAULT ''"],
    'agent_commerce_checkout_attempts_v800'=>['creation_lease_token'=>"CHAR(64) NOT NULL DEFAULT ''",'creation_lease_expires_at'=>'DATETIME NULL',
        'creation_attempt_count'=>'INTEGER NOT NULL DEFAULT 0','last_error_class'=>"VARCHAR(80) NOT NULL DEFAULT ''"],
    'agent_commerce_webhook_events_v800'=>['id'=>'BIGINT PRIMARY KEY','recovery_json'=>'TEXT NULL','lease_token'=>"CHAR(64) NOT NULL DEFAULT ''",
        'lease_expires_at'=>'DATETIME NULL','attempt_count'=>'INTEGER NOT NULL DEFAULT 0','next_attempt_at'=>'DATETIME NULL','last_error_class'=>"VARCHAR(80) NOT NULL DEFAULT ''"],
    'agent_commerce_fulfillment_jobs_pa2'=>['id'=>'BIGINT PRIMARY KEY','job_key'=>'CHAR(64)','order_id'=>'BIGINT','order_item_id'=>'BIGINT',
        'payment_id'=>'BIGINT','event_type'=>'VARCHAR(32)','status'=>'VARCHAR(24)','attempt_count'=>'INTEGER','next_attempt_at'=>'DATETIME',
        'lease_token'=>'CHAR(64)','lease_expires_at'=>'DATETIME','last_error_class'=>'VARCHAR(80)','completed_at'=>'DATETIME'],
] as $table=>$columns)profile_analytics_period_fixture_table_v1($pdo,$table,$columns);

// The observer injects an in-read correction, while the native cases below use
// an independent committed writer and an actual stale RR snapshot.
class ProductPlanProofPDO extends InitiativeCanvasPipelinePDO {public mixed $afterPlanRead=null;}
class ProductPlanProofStatement extends PDOStatement
{
    protected function __construct(private ProductPlanProofPDO $connection){}
    public function execute(?array $params=null):bool
    {
        $ok=parent::execute($params);
        if(is_callable($this->connection->afterPlanRead))($this->connection->afterPlanRead)($this->queryString,$params??[],$this->connection);
        return $ok;
    }
}
$originalPdo=$pdo;
$pdo=$GLOBALS['pdo']=new ProductPlanProofPDO($dsn,getenv('VP3_IMAGE_TEST_USER')?:null,getenv('VP3_IMAGE_TEST_PASSWORD')?:null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ProductPlanProofStatement::class,[$pdo]]);
if(!$mysql){
    foreach(['NOW','UTC_TIMESTAMP'] as $clock)$pdo->sqliteCreateFunction($clock,static fn()=>gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');$pdo->sqliteCreateFunction('IF',static fn($v,$a,$b)=>$v?$a:$b);
}
$directory=STONEFELLOW_ROOT.'/private/user-media/1';mkdir($directory,0700,true);
$png=base64_decode(image_fixture_response_v1('32x32')['data'][0]['b64_json'],true);
$sourcePath=$directory.'/plan-source.png';$outputPath=$directory.'/plan-output.png';
file_put_contents($sourcePath,$png);file_put_contents($outputPath,$png);
$creativeId=$fixture['ids']['current_creative'];$draftId=$fixture['ids']['current_draft'];$productId=$fixture['ids']['product'];
$currentRef='marketing:product-plan:'.$productId;$sourceRead=static fn():array=>pmp_source($owner,$binding,[$currentRef],$now);
$json=static fn(array $data):string=>json_encode($data,JSON_THROW_ON_ERROR);
try{
    $pdo->prepare("UPDATE user_media_assets SET file_path=?,file_size=? WHERE id=?")->execute(['private/user-media/1/plan-source.png',strlen($png),996003]);
    $pdo->prepare("UPDATE user_media_assets SET file_path=?,file_size=? WHERE id=?")->execute(['private/user-media/1/plan-output.png',strlen($png),996004]);
    $q=$pdo->prepare('SELECT draft_json FROM agent_commerce_marketing_drafts_v1 WHERE request_key=?');$q->execute([$draftId]);
    $draft=json_decode($q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);$q->closeCursor();$draft['asset_sha256']=hash('sha256',$png);
    $draftJson=$json($draft);$setDraft=$pdo->prepare('UPDATE agent_commerce_marketing_drafts_v1 SET draft_json=? WHERE request_key=?');$setDraft->execute([$draftJson,$draftId]);
    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET source_sha256=?,asset_sha256=?,status='designing',generation_id=NULL,public_product_url=? WHERE id=?")
        ->execute([hash('sha256',$png),hash('sha256',$png),'/analytics-owner/product/fixture-product',$creativeId]);
    $pdo->prepare('UPDATE agent_commerce_products_v800 SET is_active=1,metadata_json=? WHERE id=?')
        ->execute([$json(['agent_id'=>7,'conversation_id'=>11,'profile_visibility'=>'public','profile_slug'=>'fixture-product',
            'source_asset_id'=>996003,'source_content_sha256'=>hash('sha256',$png)]),$productId]);
    $baseline=$sourceRead()['items'][$currentRef];
    canvas_pipeline_check($baseline['status']==='creative_ready','the actual native view verifies a selected draft older than the recent list and its real source image');
    $setDraft->execute([$json(array_replace($draft,['title'=>'Corrected saved draft wording'])),$draftId]);
    $changed=$sourceRead()['items'][$currentRef];
    canvas_pipeline_check($changed['status']==='creative_ready'&&$changed['revision']!==$baseline['revision'],
        'a selected draft correction changes its source proof even when launch IDs and the rendered stage stay equal');
    $setDraft->execute([$draftJson,$draftId]);
    $pdo->exec("UPDATE user_media_assets SET title='Corrected source photo' WHERE id=996003");
    canvas_pipeline_check($sourceRead()['items'][$currentRef]['revision']!==$baseline['revision'],
        'the exact source image record participates in current plan proof');
    $pdo->exec("UPDATE user_media_assets SET title='current product photo' WHERE id=996003");
    $pdo->prepare("UPDATE agent_product_launches_v1 SET last_stage='creative_ready',last_notice_key=?,next_check_at=? WHERE product_id=?")
        ->execute([str_repeat('8',64),'2099-01-01 00:00:00',$productId]);
    $pdo->prepare('UPDATE agent_commerce_promotions_v1 SET updated_at=? WHERE id=?')->execute(['2099-01-01 00:00:00',$creativeId]);
    canvas_pipeline_check($sourceRead()['items'][$currentRef]['revision']===$baseline['revision'],
        'native launch notices, wake scheduling and an unchanged creative timestamp do not invent a new report');

    $correctedDuringRead=false;
    $pdo->afterPlanRead=static function(string $sql,array $args,ProductPlanProofPDO $db)use($creativeId,&$correctedDuringRead):void{
        if(str_starts_with($sql,'SELECT * FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND id=?')){
            $db->afterPlanRead=null;$correctedDuringRead=true;
            $db->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused' WHERE id=?")->execute([$creativeId]);
        }
    };
    $raced=$sourceRead();
    canvas_pipeline_check($correctedDuringRead&&!isset($raced['items'][$currentRef])&&$raced['coverage']['marketing']==='limited',
        'a changed creative after native view hydration is withheld rather than reported from its earlier stage');
    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='designing' WHERE id=?")->execute([$creativeId]);

    // A canonical completed image binding requires the current attempt receipt
    // and real saved bytes. These deterministic rows are accepted by the actual
    // native image proof; the reporting adapter does not invent its own result.
    require_once dirname(__DIR__).'/includes/initiative-marketing-creative-v1.php';
    vp3_image_schema_v1($pdo,true);
    $variant=initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId);$settings=$variant['settings'];$settings['level']=7;
    $pdo->prepare('UPDATE agent_commerce_promotions_v1 SET settings_json=?,generation_id=? WHERE id=?')
        ->execute([$json($settings),'11111111-2222-3333-4444-555555555555',$creativeId]);
    $variant=initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId);$imageBinding=initiative_marketing_creative_binding_v1($variant);
    $generationId=$variant['generation_id'];$runId=996021;$actionId=997021;$receiptId=998021;$generatedAssetId=996005;
    profile_analytics_period_fixture_row_v1($pdo,'user_media_assets',['id'=>$generatedAssetId,'user_id'=>1,'media_type'=>'photo','title'=>'Saved scene','mime_type'=>'image/png',
        'file_path'=>'private/user-media/1/plan-output.png','file_size'=>strlen($png),'source'=>'openai_image',
        'metadata_json'=>$json(['generation_id'=>$generationId,'marketing_variant_id'=>$creativeId,'sha256'=>hash('sha256',$png)])]);
    profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_runs',['id'=>$runId,'owner_user_id'=>1,'agent_id'=>7,'workflow_type'=>'image_generation',
        'origin'=>'chat','source_kind'=>'image_generation','source_key'=>$generationId,'source_hash'=>sha1($generationId),
        'dedupe_key'=>hash('sha256',$generationId),'title'=>'Exact saved scene','goal'=>'Create the reviewed scene','decision_summary'=>'Reviewed image',
        'status'=>'completed','execution_target'=>'cloud','capability_key'=>'images.generate','risk_level'=>'low']);
    $result=$json(['asset_id'=>(string)$generatedAssetId,'generation_id'=>$generationId,'effect_state'=>'image_saved']);
    profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_actions',['id'=>$actionId,'run_id'=>$runId,'owner_user_id'=>1,'sequence_no'=>1,
        'action_key'=>$generationId,'action_type'=>'execute','label'=>'Create reviewed scene','summary'=>'Exact saved scene','status'=>'completed',
        'execution_target'=>'cloud','capability_key'=>'images.generate','attempt_count'=>1,'result_json'=>$result]);
    profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_receipts',['id'=>$receiptId,'run_id'=>$runId,'action_id'=>$actionId,'owner_user_id'=>1,
        'receipt_key'=>agent_job_receipt_key_v1900(1,$runId,$actionId,'v1910-cloud-'.$runId.'-'.$actionId.'-1'),
        'executor'=>'cloud','worker_id'=>'fixture','attempt_no'=>1,'status'=>'completed','summary'=>'Exact saved scene','result_json'=>$result,'error_class'=>'']);
    profile_analytics_period_fixture_row_v1($pdo,'agent_image_generations_v1',['generation_id'=>$generationId,'owner_user_id'=>1,'agent_id'=>7,
        'conversation_id'=>11,'user_message_id'=>null,'request_hash'=>hash('sha256','plan-proof-image'),'run_id'=>$runId,
        'config_json'=>$json(['mode'=>'marketing_scene','marketing'=>$imageBinding]),'asset_id'=>$generatedAssetId,'created_at'=>'2026-11-18 12:00:00'],'generation_id');
    $completed=$sourceRead()['items'][$currentRef];
    canvas_pipeline_check($completed['status']==='creative_ready','a real exact completed run, current attempt receipt and saved PNG authorize the native ready stage');
    $pdo->prepare('UPDATE agent_workflow_runs SET lease_token=?,heartbeat_at=? WHERE id=?')->execute([str_repeat('a',64),'2099-01-01 00:00:00',$runId]);
    canvas_pipeline_check($sourceRead()['items'][$currentRef]['revision']===$completed['revision'],
        'image worker lease and heartbeat bookkeeping do not change the saved source revision');
    $pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$json(['asset_id'=>'999','effect_state'=>'image_saved']),$receiptId]);
    $invalid=$sourceRead()['items'][$currentRef];
    canvas_pipeline_check($invalid['status']==='creative_failed'&&$invalid['revision']!==$completed['revision'],
        'a corrected current attempt receipt invalidates a completed image without trusting run status alone');
    $pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$result,$receiptId]);
    $correctedReceipt=false;
    $pdo->afterPlanRead=static function(string $sql,array $args,ProductPlanProofPDO $db)use($receiptId,&$correctedReceipt):void{
        if(str_starts_with($sql,'SELECT a.id,a.attempt_count,a.result_json,r.receipt_key')){
            $db->afterPlanRead=null;$correctedReceipt=true;
            $db->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=?")->execute([$receiptId]);
        }
    };
    $raced=$sourceRead();
    canvas_pipeline_check($correctedReceipt&&!isset($raced['items'][$currentRef])&&$raced['coverage']['marketing']==='limited',
        'the final guard withholds a receipt corrected during native completed-image projection');
    $pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$result,$receiptId]);
    $readBefore=pmp_state();$stable=$sourceRead();
    canvas_pipeline_check($stable['items'][$currentRef]['revision']===$completed['revision']&&pmp_state()===$readBefore,
        'verified current plan reads neither advance image jobs nor write native notices or receipts');

    // Validate both canonical writer paths on SQLite as well as native MySQL;
    // only the independently committed stale-snapshot tests are driver-specific.
    $settings['level']=1;
    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET settings_json=?,generation_id=NULL,status='designing' WHERE id=?")
        ->execute([$json($settings),$creativeId]);
    $saved=initiative_work_marketing_promotion_save_v1($pdo,1,$fixture['scope'],initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId),996004);
    canvas_pipeline_check($saved['status']==='saved'&&$sourceRead()['items'][$currentRef]['status']==='publication_review',
        'the actual canonical creative save advances the verified source to publication review');
    $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='published' WHERE id=?")->execute([$creativeId]);
    canvas_pipeline_check($sourceRead()['items'][$currentRef]['status']==='published',
        'a canonical public card with real reviewed PNG bytes resolves the exact published plan');
    $paused=initiative_work_marketing_promotion_pause_v1($pdo,1,$creativeId,$fixture['scope']);
    canvas_pipeline_check($paused['status']==='paused'&&$sourceRead()['items'][$currentRef]['status']==='paused',
        'the actual canonical pause changes the plan source while retaining its exact launch IDs');

    if($mysql){
        $reader=$pdo;$writer=new ImageFixturePDO($dsn,getenv('VP3_IMAGE_TEST_USER')?:null,getenv('VP3_IMAGE_TEST_PASSWORD')?:null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$writer->exec('SET SESSION innodb_lock_wait_timeout=1');
        $settings['level']=7;
        $reader->prepare("UPDATE agent_commerce_promotions_v1 SET settings_json=?,generation_id=?,status='designing' WHERE id=?")
            ->execute([$json($settings),$generationId,$creativeId]);
        $staleRead=static function(callable $write,string $label)use($reader,$writer,$sourceRead,$currentRef):void{
            $reader->beginTransaction();
            try{
                $q=$reader->query('SELECT id FROM agent_commerce_products_v800 ORDER BY id LIMIT 1');$q->fetchAll();$q->closeCursor();
                $GLOBALS['pdo']=$writer;$write($writer);$GLOBALS['pdo']=$reader;
                $snapshot=$sourceRead();
                canvas_pipeline_check(!isset($snapshot['items'][$currentRef])&&$snapshot['coverage']['marketing']==='limited'
                    &&$reader->inTransaction(),$label.' withholds stale plan proof and preserves the caller RR transaction');
            }finally{$GLOBALS['pdo']=$reader;if($writer->inTransaction())$writer->rollBack();if($reader->inTransaction())$reader->rollBack();}
        };
        $staleRead(static function(PDO $db)use($draftId,$draft,$json):void{
            $db->prepare('UPDATE agent_commerce_marketing_drafts_v1 SET draft_json=? WHERE request_key=?')
                ->execute([$json(array_replace($draft,['title'=>'Concurrent exact draft correction'])),$draftId]);
        },'an independently committed selected-draft correction');
        $setDraft->execute([$draftJson,$draftId]);
        $staleRead(static fn(PDO $db)=>$db->exec("UPDATE user_media_assets SET title='Concurrent source correction' WHERE id=996003"),
            'an independently committed source-image correction');
        $reader->exec("UPDATE user_media_assets SET title='current product photo' WHERE id=996003");
        $staleRead(static function(PDO $db)use($generationId):void{
            $db->prepare('UPDATE agent_image_generations_v1 SET asset_id=996004 WHERE generation_id=?')->execute([$generationId]);
        },'an independently committed image-generation correction');
        $reader->prepare('UPDATE agent_image_generations_v1 SET asset_id=? WHERE generation_id=?')->execute([$generatedAssetId,$generationId]);
        $staleRead(static function(PDO $db)use($receiptId):void{
            $db->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=?")->execute([$receiptId]);
        },'an independently committed current-attempt receipt correction');
        $reader->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$result,$receiptId]);

        // Actual canonical save/pause take their usual owner/Chat locks, commit
        // independently, and leave the selected launch IDs unchanged.
        $settings['level']=1;
        $reader->prepare("UPDATE agent_commerce_promotions_v1 SET settings_json=?,generation_id=NULL,status='designing' WHERE id=?")
            ->execute([$json($settings),$creativeId]);
        canvas_pipeline_check($sourceRead()['items'][$currentRef]['status']==='creative_ready','native save starts from a verified exact ready creative');
        $staleRead(static function(PDO $db)use($creativeId,$fixture):void{
            $variant=initiative_work_marketing_promotion_row_v1($db,1,$creativeId);
            $saved=initiative_work_marketing_promotion_save_v1($db,1,$fixture['scope'],$variant,996004);
            canvas_pipeline_check($saved['status']==='saved'&&!$db->inTransaction(),'the independent canonical creative save actually commits');
        },'an independently committed canonical creative save');
        canvas_pipeline_check($sourceRead()['items'][$currentRef]['status']==='publication_review','a fresh report sees the saved creative after the caller ends its old snapshot');
        $reader->prepare("UPDATE agent_commerce_promotions_v1 SET status='published' WHERE id=?")->execute([$creativeId]);
        canvas_pipeline_check($sourceRead()['items'][$currentRef]['status']==='published','native pause starts from a currently resolved published card');
        $staleRead(static function(PDO $db)use($creativeId,$fixture):void{
            $paused=initiative_work_marketing_promotion_pause_v1($db,1,$creativeId,$fixture['scope']);
            canvas_pipeline_check($paused['status']==='paused'&&!$db->inTransaction(),'the independent canonical pause actually commits');
        },'an independently committed canonical creative pause');
        canvas_pipeline_check($sourceRead()['items'][$currentRef]['status']==='paused','a fresh report sees the canonical paused state');
        $reader->beginTransaction();
        try{
            canvas_pipeline_check($sourceRead()['items'][$currentRef]['status']==='paused'&&$reader->inTransaction(),
                'a current source read keeps the caller transaction and holds its selected dependencies');
            $blocked=false;
            try{$writer->prepare('UPDATE agent_commerce_marketing_drafts_v1 SET draft_json=? WHERE request_key=?')->execute([$draftJson,$draftId]);}
            catch(PDOException $error){$blocked=(int)($error->errorInfo[1]??0)===1205;}
            canvas_pipeline_check($blocked,'the exact current draft remains locked until its caller releases the report transaction');
        }finally{if($writer->inTransaction())$writer->rollBack();if($reader->inTransaction())$reader->rollBack();}
    }
}finally{
    $pdo->afterPlanRead=null;if($pdo->inTransaction())$pdo->rollBack();$pdo=$GLOBALS['pdo']=$originalPdo;
    unlink($sourcePath);unlink($outputPath);rmdir($directory);rmdir(dirname($directory));
}
echo 'PRODUCT_MARKETING_PIPELINE_V1=PASS ('.($checks-$start).' checks; '.($mysql?'mysql':'sqlite').'; skips=0)'.PHP_EOL;
