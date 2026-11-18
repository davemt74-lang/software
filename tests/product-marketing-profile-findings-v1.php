<?php
declare(strict_types=1);

// Actual accepted public turn, worker context and completed reply receipt.
// Only the inherited physical inference/provider transport is simulated.
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-product-profile-'.bin2hex(random_bytes(8)));
require_once __DIR__.'/fixtures/profile-promotion-capture-v1.php';
require_once __DIR__.'/fixtures/profile-specialist-sources-v1.php';
require_once __DIR__.'/fixtures/profile-commerce-followthrough-data-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-product-marketing-v1.php';
require_once dirname(__DIR__).'/includes/profile-commerce-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-chat-v1.php';
$native=pcap_fixture_init_v1('pfs_reset');$pdo=db();$fixture=profile_commerce_followthrough_fixture_v1($pdo);$checks=0;
function pmf_check(bool $condition,string $label):void
{if(!$condition)throw new RuntimeException('FAIL '.$label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function pmf_now(int $stamp):void{$GLOBALS['pa3Now']=$stamp;pfs_clock();}
function pmf_read(int $cid):array
{return vp3_coordination_profile_sources_v1(db(),pfs_scope(8,12),pfs_binding(8,12),['conversation:'.$cid],pfs_now());}
function pmf_row(string $table,int $id):array
{$q=db()->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row;}
function pmf_state():array
{
    $out=pa4b_snapshot()+pp_no_external_snapshot();
    foreach(['agent_product_launches_v1','agent_commerce_promotions_v1','agent_promotion_publications_v1','agent_promotion_clicks_v1',
        'agent_commerce_checkout_attempts_v800','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts'] as $table)
        $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
function pmf_remove(string $path):void
{if(!is_dir($path))return;foreach(scandir($path) as $file){if($file==='.'||$file==='..')continue;$entry=$path.'/'.$file;if(is_dir($entry))pmf_remove($entry);else unlink($entry);}rmdir($path);}
register_shutdown_function(static fn()=>pmf_remove(STONEFELLOW_ROOT));

$parents=$pdo->prepare('SELECT p.id FROM agent_commerce_products_v800 p
    JOIN agent_commerce_provider_connections_v800 c ON c.id=p.fixed_connection_id AND c.owner_user_id=p.owner_user_id
    JOIN agent_commerce_marketing_drafts_v1 d ON d.product_id=p.id AND d.owner_user_id=p.owner_user_id
    JOIN agent_commerce_promotions_v1 m ON m.product_id=p.id AND m.draft_key=d.request_key AND m.owner_user_id=p.owner_user_id
    JOIN agent_promotion_publications_v1 u ON u.promotion_id=m.id AND u.product_id=p.id AND u.owner_user_id=p.owner_user_id
    WHERE p.id=? AND p.owner_user_id=1 AND c.id=? AND d.request_key=? AND m.id=? AND u.publication_id=?');
$parents->execute([$native['product'],$native['seller'],$native['draft_key'],$native['promotion_id'],$native['publication_id']]);
pmf_check((int)$parents->fetchColumn()===$native['product'],'the Profile reset precedes canonical Commerce seeding and retains every exact native product parent');
$parents->closeCursor();

$art=file_get_contents(STONEFELLOW_ROOT.'/private/user-media/1/original.png');
$draft=['kind'=>'product_marketing_draft','draft_id'=>$native['draft_key'],'product_id'=>$native['product'],'title'=>'Field guide promotion',
    'description'=>'Reviewed guide details.','campaign_idea'=>'Review the guide.','asset_id'=>51,'asset_sha256'=>hash('sha256',$art),'asset_source'=>'saved_photo'];
$pdo->prepare('UPDATE agent_commerce_marketing_drafts_v1 SET draft_json=? WHERE product_id=? AND request_key=?')
    ->execute([json_encode($draft,JSON_THROW_ON_ERROR),$native['product'],$native['draft_key']]);
$pdo->prepare('UPDATE user_media_assets SET metadata_json=? WHERE id=52')->execute([json_encode(['creative_id'=>$native['promotion_id']],JSON_THROW_ON_ERROR)]);
$pdo->prepare('UPDATE agent_promotion_publications_v1 SET created_at=? WHERE publication_id=?')
    ->execute([gmdate('Y-m-d H:i:s',$fixture['cutoff']-3*86400),$native['publication_id']]);
profile_analytics_period_fixture_row_v1($pdo,'agent_product_launches_v1',['product_id'=>$native['product'],'owner_user_id'=>1,'agent_id'=>8,'conversation_id'=>12,
    'draft_key'=>$native['draft_key'],'creative_id'=>$native['promotion_id'],'last_stage'=>'published'],'product_id');

pmf_now(($fixture['cutoff']-86400+45000)*1000);
$message=pp_message('What is the price of the field guide product?',2,'agent');$turn=$message['turn'];
if(in_array($turn['state'],['awaiting_delivery','awaiting_owner'],true)){
    vp3_profile_handoff_owner_action_pa3($pdo,ps_owner(),['operation'=>'delegate','turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],
        'conversation_id'=>(int)$turn['conversation_id'],'chat_conversation_id'=>11,'chat_agent_id'=>7,'request_id'=>bin2hex(random_bytes(16))]);
}
pmf_now($GLOBALS['pa3Now']+9000);vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);$turn=vp3_profile_handoff_row_pa3($pdo,(int)$turn['id']);
$cid=(int)$turn['conversation_id'];$mid=(int)$turn['answer_message_id'];$answer=pmf_row('profile_agent_messages',$mid);
$context=json_decode($answer['context_json'],true,64,JSON_THROW_ON_ERROR);
pmf_check($turn['state']==='replied'&&($context['product_promotion_key']??null)===$native['publication_id'],
    'the real accepted-turn worker saves the exact promotion key without a test-added message marker');
pmf_now(($fixture['cutoff']+9*3600)*1000);$before=pmf_state();$ref='profile:conversation:'.$cid;
$snapshot=pmf_read($cid);$item=$snapshot['items'][$ref]??null;
pmf_check(is_array($item)&&count($item['related_product_marketing']??[])===1,'the native Profile finding contains one exact saved promotion relation');
$link=$item['related_product_marketing'][0];
$canonical=vp3_coordination_product_marketing_sources_v1($pdo,pfs_scope(8,12),[substr($link['ref'],10)],pfs_now())['items'][$link['ref']];
pmf_check($link['revision']===$canonical['revision']&&$link['url']===$canonical['url']&&$link['publication_id']===$native['publication_id']
    &&$link['public_conversation_id']===$cid&&$link['turn_id']===(int)$turn['id']&&$link['answer_message_id']===$mid,
    'Profile and Marketing share one canonical publication-period source and exact public reply receipt identity');
pmf_check(str_starts_with($link['ref'],'marketing:ft:v1:')&&str_contains($link['url'],'agent=8&conversation_id=12&marketing_followthrough='),
    'the relation links to the original private planning Agent and Chat, independently of the public responder');
$report=vp3_profile_specialist_report_v1($snapshot);
pmf_check(($report['findings'][0]['related_product_marketing'][0]??null)===$link
    &&str_contains(implode(' ',$report['key_findings']),'What is the price of the field guide product?')
    &&str_contains(implode(' ',$report['key_findings']),'saved Agent reply showed the promotion'),
    'the pure five-section report keeps the literal saved question and validated link without inferring intent');
pmf_check($before===pmf_state(),'related reads neither acknowledge, dispatch, create plans, publish nor duplicate native Chat cards');
$ownership=[$item['presentation_owner'],$item['profile_presentation_owned'],$item['legacy_delivered']];
pfs_advance(10*86400000);$pdo->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1");
pmf_check((pmf_read($cid)['items'][$ref]??null)===$item,'the completed saved-reply day stays fixed across later checks and timezone changes');
$pdo->exec("UPDATE users SET timezone='UTC' WHERE id=1");
$other=vp3_profile_commerce_conversation_associations_v1($pdo,pfs_scope(7,11),$cid,pfs_now());
pmf_check($other['items']===[],'the same owner cannot silently retarget a saved relation to another private Chat');

$pdo->beginTransaction();
try{
    $pdo->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE run_id=? AND action_id=? AND status='completed'")->execute([$turn['run_id'],$turn['action_id']]);
    $missing=pmf_read($cid)['items'][$ref]??null;
    pmf_check(is_array($missing)&&$missing['related_product_marketing']===[]&&$missing['related_product_marketing_coverage']==='limited'
        &&$missing['revision']!==$item['revision'],'missing exact reply proof removes the relation and revises the native finding without hiding its independently verified conversation');
    pmf_check(!str_contains(implode(' ',$missing['profile_report']['key_findings']),'saved Agent reply showed the promotion')
        &&[$missing['presentation_owner'],$missing['profile_presentation_owned'],$missing['legacy_delivered']]===$ownership,
        'withholding an association removes its stale prose and preserves native presentation ownership');
}finally{$pdo->rollBack();}
// A saved promotion key cannot predate its canonical tracking identity.
$pdo->beginTransaction();try{
    $pdo->prepare('UPDATE agent_promotion_publications_v1 SET created_at=? WHERE publication_id=?')
        ->execute([gmdate('Y-m-d H:i:s',intdiv($link['linked_at_utc_ms'],1000)+1),$native['publication_id']]);
    $late=pmf_read($cid)['items'][$ref]??null;
    pmf_check(is_array($late)&&$late['related_product_marketing']===[]&&$late['related_product_marketing_coverage']==='limited',
        'publication tracking recorded after the saved reply cannot prove an earlier promotion association');
}finally{$pdo->rollBack();}
foreach([
    'wrong public thread'=>static fn(array $link):array=>array_replace($link,['public_conversation_id'=>12]),
    'external destination'=>static fn(array $link):array=>array_replace($link,['url'=>'https://example.invalid/collect']),
    'wrong original period'=>static fn(array $link):array=>array_replace($link,['ref'=>$GLOBALS['fixture']['ref']]),
] as $label=>$change){
    $bad=$snapshot;$bad['items'][$ref]['related_product_marketing'][0]=$change($link);$safe=vp3_profile_specialist_report_v1($bad);
    pmf_check(count($safe['findings'])===1&&$safe['findings'][0]['related_product_marketing']===[],'pure projection withholds '.$label.' while retaining the native finding');
}

// The same saved exchange, viewed before its UTC day closes, points only to a
// proved current plan. No future period or complete empty outcome is invented.
pmf_now(($fixture['cutoff']-3600)*1000);$current=vp3_profile_commerce_conversation_associations_v1($pdo,pfs_scope(8,12),$cid,pfs_now());
pmf_check(count($current['items'])===1&&$current['items'][0]['ref']==='marketing:product-plan:'.$native['product']
    &&str_contains($current['items'][0]['url'],'agent=8&conversation_id=12&marketing_plan='),
    'an open saved-reply day uses the exact current plan instead of a future or empty completed period');
$pdo->beginTransaction();try{
    $pdo->prepare('DELETE FROM agent_product_launches_v1 WHERE product_id=?')->execute([$native['product']]);
    $unavailable=vp3_profile_commerce_conversation_associations_v1($pdo,pfs_scope(8,12),$cid,pfs_now());
    pmf_check($unavailable['items']===[]&&$unavailable['coverage']==='limited','an open day without a proved current plan has no fabricated review destination');
}finally{$pdo->rollBack();}
pmf_now(($fixture['cutoff']+9*3600)*1000);
$pdo->beginTransaction();try{
    $q=$pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json,created_at) VALUES(?,'agent',NULL,'Unverified source',?,?)");
    for($i=0;$i<26;$i++)$q->execute([$cid,json_encode(['product_promotion_key'=>$native['publication_id']],JSON_THROW_ON_ERROR),$answer['created_at']]);
    $bounded=vp3_profile_commerce_conversation_associations_v1($pdo,pfs_scope(8,12),$cid,pfs_now());
    pmf_check($bounded['items']===[]&&$bounded['coverage']==='limited','bounded source discovery neither scans beyond 24 candidates nor substitutes an older unselected association');
}finally{$pdo->rollBack();}
pmf_check(vp3_catch_up_chat_request_v1('Catch me up on product marketing')['scope']==='commerce',
    'an explicit Product Marketing catch-up reaches the established Commerce read route');
$catchup=vp3_catch_up_product_marketing_v1($pdo,ps_owner(),8,12,pfs_now());
$periods=array_values(array_filter($catchup[1],static fn(array $row):bool=>str_starts_with($row['id'],'marketing:ft:v1:'.$GLOBALS['native']['publication_id'].':')));
$currentSource=$periods?vp3_coordination_product_marketing_sources_v1($pdo,pfs_scope(8,12),[substr($periods[0]['id'],10)],pfs_now())['items'][$periods[0]['id']]:null;
pmf_check(in_array($catchup[0],['ready','limited'],true)&&count($periods)===1&&$currentSource!==null
    &&$periods[0]['_source_revision']===$currentSource['revision']&&$periods[0]['url']===$currentSource['url'],
    'explicit named-Agent catch-up uses the actual canonical current-period source and revision without a second collector');
$response=vp3_catch_up_chat_result_v1(['items'=>$catchup[1],'coverage'=>['product_marketing'=>$catchup[0]]]);
pmf_check(str_contains($response['answer'],'product marketing findings')&&$response['actions']===[]&&count($response['sources'])===count($catchup[1]),
    'the Chat catch-up gives reviewed source links and no implied mutation or urgent native interruption');
$event=profile_commerce_followthrough_fixture_event_v1($pdo,$fixture);
$syntheticRef='profile:conversation:'.$fixture['ids']['public_conversation'];
$nativeSeed=vp3_coordination_profile_sources_v1($pdo,pfs_scope(7,11),pfs_binding(7,11),['conversation:'.$fixture['ids']['public_conversation']],pfs_now());
pmf_check($event!==null&&($nativeSeed['items'][$syntheticRef]['related_product_marketing'][0]['publication_id']??null)===$fixture['publication_id'],
    'the reusable UI seed uses the canonical message event writer and passes native source admission');
echo 'PRODUCT_MARKETING_PROFILE_FINDINGS_V1=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; skips=0)'.PHP_EOL;
