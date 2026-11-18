<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/vp3-profile-followthrough-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
require_once __DIR__.'/fixtures/profile-promotion-capture-v1.php';
require_once __DIR__.'/fixtures/profile-commerce-followthrough-data-v1.php';
require_once dirname(__DIR__).'/includes/profile-commerce-followthrough-v1.php';
$nativeFixture=pcap_fixture_init_v1();$fixture=profile_commerce_followthrough_fixture_v1(db());$checks=0;
$now=new DateTimeImmutable('2026-11-18T12:00:00Z');
function pft_ok(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function pft_read(?array $selection=null,?array $scope=null,?DateTimeImmutable $now=null):array
{return vp3_profile_commerce_followthrough_v1(db(),$scope??$GLOBALS['fixture']['scope'],$selection??['ref'=>$GLOBALS['fixture']['ref']],$now??$GLOBALS['now']);}
function pft_metric(array $report,string $key,string $side='current'):array
{foreach($report['metrics'] as $row)if($row['key']===$key)return $row[$side];throw new RuntimeException('Missing measure '.$key);}
function pft_money(array $report,string $key,string $currency,string $side='current'):array
{foreach($report['money'] as $row)if($row['key']===$key&&$row['currency']===$currency)return $row[$side];throw new RuntimeException('Missing money '.$key);}
function pft_case(callable $run):void
{
    $pdo=db();if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();try{$run($pdo);}finally{$pdo->beforeSql=null;$pdo->afterSql=null;$pdo->failSql='';if($pdo->inTransaction())$pdo->rollBack();}
}
function pft_denied(callable $run,int $code,string $label):void
{try{$run();}catch(RuntimeException $error){pft_ok($error->getCode()===$code&&!str_contains($error->getMessage(),'SECRET'),$label);return;}throw new RuntimeException('FAIL '.$label);}
function pft_json(PDO $pdo,string $table,string $column,string|int $id,callable $change,string $key='id'):void
{
    $q=$pdo->prepare('SELECT '.$column.' FROM '.$table.' WHERE '.$key.'=?');$q->execute([$id]);$raw=$q->fetchColumn();$q->closeCursor();
    $value=$change(json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR));
    $pdo->prepare('UPDATE '.$table.' SET '.$column.'=? WHERE '.$key.'=?')->execute([json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$id]);
}
function pft_row(PDO $pdo,string $table,string|int $id,string $key='id'):array
{
    $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE '.$key.'=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    return $row?:throw new RuntimeException('Missing fixture row.');
}
function pft_clock(int $at):void
{
    $GLOBALS['pa3Now']=$at;$date=static fn():string=>gmdate('Y-m-d H:i:s',intdiv($GLOBALS['pa3Now'],1000));
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')db()->exec('SET timestamp='.intdiv($at,1000));
    else foreach(['NOW','UTC_TIMESTAMP','CURRENT_TIMESTAMP'] as $name)db()->sqliteCreateFunction($name,$date);
}
function pft_cleanup(string $path):void
{if(!is_dir($path))return;foreach(scandir($path) as $name){if($name==='.'||$name==='..')continue;$entry=$path.'/'.$name;if(is_dir($entry))pft_cleanup($entry);else unlink($entry);}rmdir($path);}
register_shutdown_function(static fn()=>pft_cleanup($GLOBALS['root']));

$queries=0;$models=count($GLOBALS['pa3ModelCalls']);$providers=count($GLOBALS['pa5ProviderRequests']);
db()->beforeSql=static function(string $sql)use(&$queries):void{if(!preg_match('/\A\s*(SELECT|SHOW|PRAGMA|SET TRANSACTION)\b/i',$sql))throw new RuntimeException('Unexpected write '.$sql);++$queries;};
$baseline=pft_read();db()->beforeSql=null;
pft_ok($baseline['coverage']==='ready'&&count($baseline['metrics'])===7&&count($baseline['groups'])===5,'verified publication baseline exposes all seven measures and five complete groups');
pft_ok($queries>30&&!db()->inTransaction()&&count($GLOBALS['pa3ModelCalls'])===$models&&count($GLOBALS['pa5ProviderRequests'])===$providers,'report reads perform only queries, end only owned snapshots and dispatch no inference or provider');
foreach(['recorded_cta_clicks'=>[2,1],'conversation_attributed_clicks'=>[1,0],'promotion_linked_exchanges'=>[1,0],
    'recorded_attributed_orders'=>[0,1],'verified_checkout_openings'=>[1,1],'verified_paid_orders'=>[1,0],'verified_refunded_orders'=>[1,0]] as $key=>[$a,$b])
    pft_ok(pft_metric($baseline,$key)['value']===$a&&pft_metric($baseline,$key,'previous')['value']===$b,'exact current and prior publication evidence: '.$key);
pft_ok(pft_money($baseline,'verified_payments','USD')['value_minor']==='2000'&&pft_money($baseline,'verified_payments','USD','previous')['value_minor']==='1000'
    &&pft_money($baseline,'verified_refunds','USD')['value_minor']==='500'&&count($baseline['money'])===2,'only exact selected publication receipts contribute; amounts remain decimal strings and separate refunds');
pft_ok($baseline['publication']['title']==='Original T-shirt promotion'&&$baseline['exact_creative']['marker']['creative_id']===$fixture['ids']['original_creative']
    &&$baseline['exact_creative']['marker']['draft_id']===$fixture['ids']['draft'],'the original creative and older exact draft survive a newer current plan');
$list=vp3_profile_commerce_publications_v1(db(),$fixture['scope'],$fixture['ids']['product'],'',$now);
pft_ok(count($list['publications'])===2&&$list['coverage']==='ready'&&!$list['has_more'],'publication choices stay in the exact product and original private Chat');
pft_ok($baseline['current_publication']===['availability'=>'ready','state'=>'paused']
    &&array_column(array_column($list['publications'],'current_publication'),'state')===['active','paused'],
    'report and picker expose separate current publication status without raw technical labels');
pft_case(static function(PDO $pdo)use($baseline):void{
    $f=$GLOBALS['fixture'];$pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='published' WHERE id=?")->execute([$f['ids']['original_creative']]);
    $pdo->exec("UPDATE agent_commerce_products_v800 SET title='Renamed current listing' WHERE id=992101");
    $r=pft_read();pft_ok($r['revision']===$baseline['revision']&&$r['current_publication']['state']==='active',
        'current activation and listing rename do not rewrite fixed publication facts');
    $pdo->prepare('UPDATE agent_commerce_promotions_v1 SET public_key=? WHERE id=?')->execute([str_repeat('b',32),$f['ids']['original_creative']]);
    $r=pft_read();pft_ok($r['revision']===$baseline['revision']&&$r['current_publication']['state']==='historical',
        'a replaced publication remains explicitly historical with unchanged period evidence');
});
pft_case(static function(PDO $pdo):void{
    $f=$GLOBALS['fixture'];$row=pft_row($pdo,'agent_promotion_publications_v1',$f['publication_id'],'publication_id');
    for($i=0;$i<25;$i++){$row['publication_id']=substr(hash('sha256','publication-page-'.$i),0,32);profile_analytics_period_fixture_row_v1($pdo,'agent_promotion_publications_v1',$row,'publication_id');}
    $a=vp3_profile_commerce_publications_v1($pdo,$f['scope'],$f['ids']['product'],'',$GLOBALS['now']);
    $b=vp3_profile_commerce_publications_v1($pdo,$f['scope'],$f['ids']['product'],$a['next_cursor'],$GLOBALS['now']);
    $ids=array_merge(array_column($a['publications'],'publication_id'),array_column($b['publications'],'publication_id'));
    pft_ok(count($a['publications'])===20&&$a['has_more']&&count($b['publications'])===7&&!$b['has_more']&&count(array_unique($ids))===27,'all exact publication choices remain reachable beyond the bounded first page without overlap');
    $page=json_decode(base64_decode(strtr($a['next_cursor'],'-_','+/')),true,16,JSON_THROW_ON_ERROR);$page['chat']=12;
    $wrong=rtrim(strtr(base64_encode(json_encode($page,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    pft_denied(fn()=>vp3_profile_commerce_publications_v1($pdo,$f['scope'],$f['ids']['product'],$wrong,$GLOBALS['now']),422,'publication pagination cannot move a cursor to another private Chat');
});
pft_ok($baseline['exchanges']['total']===1&&$baseline['exchanges']['items'][0]['question']==='Can I get this T-shirt in blue?'
    &&$baseline['exchanges']['items'][0]['public_conversation_id']===$fixture['ids']['public_conversation']
    &&$baseline['exchanges']['items'][0]['open_selection']['publicConversationId']!==11,'the exact saved visitor question retains public thread identity instead of the private Chat ID');
$encoded=json_encode($baseline,JSON_THROW_ON_ERROR);
pft_ok(!preg_match('/credential_hash|session_sha256|session_key|context_json|password|fixture_analytics_merchant|campaign_id/', $encoded),'safe report projection omits native credentials, raw context, merchant and Campaign data');
pft_ok(pft_read(null,null,$now->modify('+2 days'))['revision']===$baseline['revision'],'checking a fixed period later preserves its source revision');
pft_case(static function(PDO $pdo):void{
    $f=$GLOBALS['fixture'];$answer=pft_row($pdo,'profile_agent_messages',$f['ids']['answer_message']);
    $after=(new DateTimeImmutable($answer['created_at'],new DateTimeZone('UTC')))->modify('+1 minute')->format('Y-m-d H:i:s');
    $pdo->prepare('UPDATE agent_promotion_publications_v1 SET created_at=? WHERE publication_id=?')->execute([$after,$f['publication_id']]);
    $r=pft_read();
    pft_ok(pft_metric($r,'promotion_linked_exchanges')['coverage']==='partial'&&$r['exchanges']['items']===[]
        &&pft_metric($r,'recorded_cta_clicks')['coverage']==='partial'&&pft_metric($r,'recorded_cta_clicks')['value']===null,
        'replies and recorded clicks before their exact publication tracking identity are partial, not verified results');
});
pft_case(static function(PDO $pdo)use($baseline):void{
    $pdo->exec("UPDATE agent_product_launches_v1 SET last_stage='paused',creative_id=NULL WHERE product_id=992101");
    $r=pft_read();pft_ok($r['revision']===$baseline['revision']&&$r['current_plan']['revision']!==$baseline['current_plan']['revision'],'current plan state remains separate from fixed-period evidence');
    pft_ok($pdo->inTransaction(),'a successful report preserves the caller transaction');
});
pft_case(static function(PDO $pdo)use($baseline):void{
    $pdo->exec("UPDATE users SET timezone='Asia/Kathmandu' WHERE id=1");$r=pft_read();
    pft_ok($r['revision']===$baseline['revision']&&$r['window']['timezone']==='UTC'&&pft_read(['publication_id'=>$GLOBALS['fixture']['publication_id'],'period'=>7])['window']['timezone']==='Asia/Kathmandu','old promotion references keep their original timezone while new selections use current owner settings');
    $pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
    pft_ok(pft_read()['revision']===$baseline['revision'],'private publication outcomes survive public Profile and responder visibility changes');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
    pft_denied(fn()=>pft_read(),403,'full follow-through requires the current Profile grants');
    $f=$GLOBALS['fixture'];$list=vp3_profile_commerce_publications_v1($pdo,$f['scope'],$f['ids']['product'],'',$GLOBALS['now']);
    pft_ok(count($list['publications'])===2,'private publication choices remain available with account and Chat authority alone');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='chat.access'");$f=$GLOBALS['fixture'];
    pft_denied(fn()=>vp3_profile_commerce_publications_v1($pdo,$f['scope'],$f['ids']['product']),403,'publication choices never bypass current private Chat access');
});
foreach([['agent_id'=>8,'conversation_id'=>12],['agent_id'=>0,'conversation_id'=>11]] as $target){
    $scope=array_replace($fixture['scope'],$target);$scope['principal']=$target['agent_id']>0?['kind'=>'user_agent','agent_id'=>$target['agent_id'],'owner_user_id'=>1,'viewer_user_id'=>1]:['kind'=>'system','agent_id'=>0,'viewer_user_id'=>1];
    pft_denied(fn()=>pft_read(null,$scope),$target['agent_id']>0?404:403,'an exact publication cannot be retargeted to another private Agent or namespace');
}
foreach([['ref'=>$fixture['ref'],'period'=>1],['publication_id'=>$fixture['publication_id'],'period'=>0],['ref'=>str_replace('marketing:','profile:',$fixture['ref'])],['publication_id'=>str_repeat('1',31)]] as $bad)
    pft_denied(fn()=>pft_read($bad),422,'malformed or ambiguous exact publication selection is rejected');

pft_case(static function(PDO $pdo)use($baseline):void{
    $pdo->prepare('UPDATE agent_promotion_clicks_v1 SET native_context_json_v1=NULL WHERE click_id=?')->execute([$GLOBALS['fixture']['ids']['native_click']]);$r=pft_read();
    pft_ok(pft_metric($r,'recorded_cta_clicks')['value']===2&&pft_metric($r,'conversation_attributed_clicks')['coverage']==='partial'
        &&pft_metric($r,'conversation_attributed_clicks')['value']===null&&pft_metric($r,'conversation_attributed_clicks')['observed_value']===0,'legacy click association is unknown while canonical CTA event totals remain usable');
    pft_ok(pft_metric($r,'verified_paid_orders')['value']===1,'missing native click association does not erase independently frozen promotion order attribution');
});
foreach([
    ['UPDATE profile_visit_sessions SET session_key=? WHERE id=990011',[hash('sha256','changed-session')]],

] as [$sql,$params])pft_case(static function(PDO $pdo)use($sql,$params):void{
    $pdo->prepare($sql)->execute($params);$r=pft_read();
    pft_ok(pft_metric($r,'conversation_attributed_clicks')['coverage']==='partial'&&pft_metric($r,'recorded_cta_clicks')['value']===2,'changed native click proof cannot reassign the saved public conversation');
});
foreach(['public_conversation_id'=>11,'session_hash'=>str_repeat('0',64)] as $field=>$value)pft_case(static function(PDO $pdo)use($field,$value):void{
    pft_json($pdo,'agent_promotion_clicks_v1','native_context_json_v1',$GLOBALS['fixture']['ids']['native_click'],static fn(array $proof):array=>array_replace($proof,[$field=>$value]),'click_id');
    $r=pft_read();pft_ok(pft_metric($r,'conversation_attributed_clicks')['coverage']==='partial'&&pft_metric($r,'recorded_cta_clicks')['value']===2,'changed closed native click proof cannot reassign the saved public conversation: '.$field);
});
pft_case(static function(PDO $pdo):void{
    $pdo->prepare('UPDATE agent_promotion_clicks_v1 SET created_at=? WHERE click_id=?')->execute([gmdate('Y-m-d H:i:s',$GLOBALS['fixture']['cutoff']),$GLOBALS['fixture']['ids']['plain_click']]);
    pft_ok(pft_metric(pft_read(),'recorded_cta_clicks')['value']===1,'click reporting uses the exact exclusive upper UTC boundary');
});
pft_case(static function(PDO $pdo)use($baseline):void{
    $pdo->prepare('INSERT INTO agent_promotion_clicks_v1(click_id,publication_id,placement,created_at) VALUES(?,?,?,?)')
        ->execute([substr(hash('sha256','later-publication-click'),0,32),$GLOBALS['fixture']['publication_id'],'profile',gmdate('Y-m-d H:i:s',$GLOBALS['fixture']['cutoff']+3600)]);
    pft_ok(pft_read()['revision']===$baseline['revision'],'later valid click records do not rewrite a saved earlier reporting period');
});
pft_case(static function(PDO $pdo):void{
    $q=$pdo->prepare('INSERT INTO agent_promotion_clicks_v1(click_id,publication_id,placement,created_at) VALUES(?,?,?,?)');
    for($i=0;$i<1201;$i++)$q->execute([substr(hash('sha256','uncapped-publication-click-'.$i),0,32),$GLOBALS['fixture']['publication_id'],'profile',gmdate('Y-m-d H:i:s',$GLOBALS['fixture']['cutoff']-3600)]);
    pft_ok(pft_metric(pft_read(),'recorded_cta_clicks')['value']===1203,'all 1201 additional recorded clicks contribute beyond any bounded UI list');
});

pft_case(static function(PDO $pdo):void{
    $pdo->exec("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=998011");$r=pft_read();
    pft_ok(pft_metric($r,'promotion_linked_exchanges')['coverage']==='partial'&&$r['exchanges']['items']===[]&&$r['exchanges']['total']===null,'a promotion key without its exact completed native reply receipt cannot become a conversation finding');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec("UPDATE profile_agent_messages SET message='Changed visitor source' WHERE id=991111");$r=pft_read();
    pft_ok(pft_metric($r,'promotion_linked_exchanges')['coverage']==='partial'&&$r['exchanges']['items']===[],'changed visitor text fails the accepted statement hash instead of presenting a new quote');
});
pft_case(static function(PDO $pdo):void{
    pft_json($pdo,'agent_workflow_receipts','result_json',998011,static function(array $r):array{$r['conversation_id']='11';return $r;});
    pft_ok(pft_metric(pft_read(),'promotion_linked_exchanges')['coverage']==='partial','an actual receipt cannot substitute a private Chat numeric ID for the public thread');
});
pft_case(static function(PDO $pdo):void{
    $f=$GLOBALS['fixture'];$turn=pft_row($pdo,'profile_agent_handoff_turns_pa3',$f['ids']['turn']);
    $run=pft_row($pdo,'agent_workflow_runs',$turn['run_id']);$action=pft_row($pdo,'agent_workflow_actions',$turn['action_id']);$receipt=pft_row($pdo,'agent_workflow_receipts',$f['ids']['receipt']);
    $visitor=pft_row($pdo,'profile_agent_messages',$f['ids']['visitor_message']);$answer=pft_row($pdo,'profile_agent_messages',$f['ids']['answer_message']);
    for($i=0;$i<25;$i++){
        $tid=995100+$i;$rid=996100+$i;$aid=997100+$i;$vid=999100+$i*2;$mid=$vid+1;
        $created=gmdate('Y-m-d H:i:s',$f['cutoff']-3600+$i*2);$answered=gmdate('Y-m-d H:i:s',$f['cutoff']-3599+$i*2);
        $v=array_replace($visitor,['id'=>$vid,'created_at'=>$created]);$a=array_replace($answer,['id'=>$mid,'created_at'=>$answered]);
        $ctx=json_decode($a['context_json'],true,16,JSON_THROW_ON_ERROR);$ctx['profile_handoff']['turn_id']=$tid;$ctx['profile_handoff']['visitor_message_id']=$vid;$a['context_json']=json_encode($ctx,JSON_THROW_ON_ERROR);
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',$v);profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',$a);
        profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_runs',array_replace($run,['id'=>$rid,'source_key'=>'profile-turn:'.$tid,'source_hash'=>sha1('profile-turn:'.$tid),'dedupe_key'=>hash('sha256','profile-turn:'.$tid)]));
        profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_actions',array_replace($action,['id'=>$aid,'run_id'=>$rid,'action_key'=>'profile-turn:'.$tid]));
        profile_analytics_period_fixture_row_v1($pdo,'profile_agent_handoff_turns_pa3',array_replace($turn,['id'=>$tid,'visitor_message_id'=>$vid,'run_id'=>$rid,'action_id'=>$aid,'answer_message_id'=>$mid,'client_request_id'=>substr(hash('sha256','turn-'.$tid),0,32),'created_at'=>$created,'updated_at'=>$answered]));
        $result=['turn_id'=>(string)$tid,'conversation_id'=>(string)$turn['conversation_id'],'visitor_message_id'=>(string)$vid,'answer_message_id'=>(string)$mid];
        profile_analytics_period_fixture_row_v1($pdo,'agent_workflow_receipts',array_replace($receipt,['id'=>998100+$i,'run_id'=>$rid,'action_id'=>$aid,'receipt_key'=>hash('sha256','1|'.$rid.'|'.$aid.'|profile-turn:'.$tid.':reply'),'result_json'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>$answered]));
    }
    $r=pft_read();pft_ok(pft_metric($r,'promotion_linked_exchanges')['value']===26&&$r['exchanges']['total']===26&&count($r['exchanges']['items'])===20&&$r['exchanges']['has_more'],'exchange totals remain uncapped while the safe current-period snippet list stays bounded');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec('DELETE FROM agent_promotion_orders_v1 WHERE order_id=992201');$r=pft_read();
    pft_ok(pft_metric($r,'recorded_attributed_orders','previous')['coverage']==='partial'&&pft_metric($r,'verified_paid_orders')['coverage']==='partial','a missing promotion-order ledger binding is found through frozen metadata and never treated as a complete zero');
});
pft_case(static function(PDO $pdo):void{
    pft_json($pdo,'agent_commerce_order_items_v800','metadata_json',992201,static function(array $m):array{$m['product_promotion_click_v1']=str_repeat('0',32);return $m;},'order_id');
    pft_ok(pft_metric(pft_read(),'verified_paid_orders')['coverage']==='partial','order-level enrichment cannot replace mismatched frozen item attribution');
});
pft_case(static function(PDO $pdo):void{
    $late=gmdate('Y-m-d H:i:s',$GLOBALS['fixture']['cutoff']-86400+36000);
    foreach([['agent_commerce_orders_v800','id'],['agent_commerce_order_items_v800','order_id']] as [$table,$key])pft_json($pdo,$table,'metadata_json',992201,static function(array $m)use($late):array{$m['product_promotion_order_utc_v1']=$late;return $m;},$key);
    pft_ok(pft_metric(pft_read(),'recorded_attributed_orders')['coverage']==='partial','a frozen order outside the canonical 24-hour click window is not attributed');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec('DELETE FROM agent_commerce_payments_v800 WHERE id=992401');$r=pft_read();
    pft_ok(pft_metric($r,'verified_paid_orders')['coverage']==='partial'&&pft_money($r,'verified_payments','USD','previous')['value_minor']===null,'selected publication money retains whole-ledger completeness and missing prior receipt protection');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE agent_commerce_orders_v800 SET amount_refunded_cents=1000 WHERE id=992201');
    pft_ok(pft_metric(pft_read(),'verified_refunded_orders')['coverage']==='partial','a partly missing refund ledger stays explicitly partial after publication selection');
});
pft_case(static function(PDO $pdo):void{
    $early=gmdate('Y-m-d H:i:s',$GLOBALS['fixture']['cutoff']-172800+35999);
    $pdo->prepare('UPDATE agent_commerce_payments_v800 SET received_at=? WHERE id=992402')->execute([$early]);$r=pft_read();
    pft_ok(pft_metric($r,'verified_paid_orders')['coverage']==='partial'&&pft_metric($r,'verified_paid_orders')['value']===null
        &&pft_metric($r,'verified_refunded_orders')['coverage']==='partial'&&pft_money($r,'verified_payments','USD')['value_minor']===null,
        'a payment before the proven frozen promotion order cannot certify payment completion or its later refund');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE agent_commerce_checkout_attempts_v800 SET opened_at_utc_ms=NULL WHERE id=993402');$r=pft_read();
    pft_ok(pft_metric($r,'verified_checkout_openings')['coverage']==='partial'&&pft_metric($r,'verified_checkout_openings')['value']===null
        &&pft_metric($r,'verified_checkout_openings')['reason']==='checkout_clock_missing'&&pft_metric($r,'verified_paid_orders')['value']===1,'legacy opening clocks remain unknown without erasing verified payment facts');
});
pft_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE agent_commerce_checkout_attempts_v800 SET opened_at_utc_ms=1 WHERE id=993402');
    pft_ok(pft_metric(pft_read(),'verified_checkout_openings')['coverage']==='partial','malformed opening clocks never become zero or epoch-derived progress');
});
pft_case(static function(PDO $pdo):void{
    $history=vp3_profile_analytics_native_history_v1($pdo,1);$window=$GLOBALS['baseline']['window'];
    $none=vp3_profile_analytics_commerce_v1($pdo,1,$window,$history,[]);
    pft_ok($none['coverage']==='ready'&&$none['points']['verified_paid_orders']['current']['value']===0&&$none['money']===[],'an explicit empty exact-order selection never falls back to all owner receipts');
    $missing=vp3_profile_analytics_commerce_v1($pdo,1,$window,$history,[9999999]);
    pft_ok($missing['points']['verified_paid_orders']['current']['coverage']==='partial','an unavailable requested exact order remains missing proof, not a complete zero');
});

foreach([['agent_promotion_clicks_v1','recorded_cta_clicks'],['agent_workflow_receipts','promotion_linked_exchanges'],['agent_commerce_payments_v800','verified_paid_orders']] as [$table,$key]){
    db()->failSql='FROM '.$table;try{$r=pft_read();}finally{db()->failSql='';}
    pft_ok(pft_metric($r,$key)['coverage']==='unavailable'&&pft_metric($r,$key)['value']===null&&count($r['metrics'])===7
        &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SECRET'),'optional storage failure remains honest unavailable evidence: '.$table);
}
// Simulate a real pre-upgrade store. Only this isolated test's explicit
// installer restores columns; the reader must not migrate or invent history.
$savedClicks=db()->query('SELECT click_id,native_context_json_v1 FROM agent_promotion_clicks_v1')->fetchAll(PDO::FETCH_ASSOC);
try{
    db()->exec('ALTER TABLE agent_promotion_clicks_v1 DROP COLUMN native_context_json_v1');
    $r=pft_read();
    pft_ok(pft_metric($r,'recorded_cta_clicks')['value']===2&&pft_metric($r,'conversation_attributed_clicks')['coverage']==='unavailable'
        &&pft_metric($r,'conversation_attributed_clicks')['value']===null&&pft_metric($r,'verified_paid_orders')['value']===1,
        'missing capture columns leave association unknown while saved CTA and frozen payment facts remain usable');
    pft_ok(!profile_promotion_capture_click_columns_v1(db()),'a pre-upgrade association read never creates missing capture columns');
}finally{
    profile_promotion_capture_install_v1(db(),'clicks');$restore=db()->prepare('UPDATE agent_promotion_clicks_v1 SET native_context_json_v1=? WHERE click_id=?');
    foreach($savedClicks as $row)$restore->execute([$row['native_context_json_v1'],$row['click_id']]);
}
$savedOpenings=db()->query('SELECT id,opened_at_utc_ms FROM agent_commerce_checkout_attempts_v800')->fetchAll(PDO::FETCH_ASSOC);
try{
    db()->exec('ALTER TABLE agent_commerce_checkout_attempts_v800 DROP COLUMN opened_at_utc_ms');$r=pft_read();
    pft_ok($r['groups']['checkouts']['coverage']==='unconfigured'&&pft_metric($r,'verified_checkout_openings')['coverage']==='unavailable'
        &&pft_metric($r,'verified_checkout_openings')['value']===null&&pft_metric($r,'verified_paid_orders')['value']===1,
        'missing link-availability clock is explicitly unconfigured and never guessed from checkout or payment time');
    pft_ok(!profile_promotion_capture_checkout_clock_v1(db()),'a pre-upgrade checkout read never creates the missing recorded clock');
}finally{
    profile_promotion_capture_install_v1(db(),'checkout');$restore=db()->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET opened_at_utc_ms=? WHERE id=?');
    foreach($savedOpenings as $row)$restore->execute([$row['opened_at_utc_ms'],$row['id']]);
}
foreach([['agent_commerce_products_v800','owner_user_id',992101],['user_media_assets','user_id',996001]] as [$table,$column,$id])pft_case(static function(PDO $pdo)use($table,$column,$id):void{
    $pdo->exec('UPDATE '.$table.' SET '.$column.'=4 WHERE id='.$id);
    pft_denied(fn()=>pft_read(),$table==='user_media_assets'?409:404,'currently reassigned publication source cannot expose historical details: '.$table);
});
$ownerReadSql='SELECT * FROM users WHERE id=?'.(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' LOCK IN SHARE MODE':'');
$ownerReads=0;$lateSourceChanged=false;db()->beforeSql=static function(string $sql,PA3PDO $pdo)use(&$ownerReads,&$lateSourceChanged,$ownerReadSql):void{
    if($sql===$ownerReadSql&&++$ownerReads===3){$pdo->beforeSql=null;$pdo->exec('UPDATE user_media_assets SET user_id=4 WHERE id=996001');$lateSourceChanged=true;}
};
pft_denied(fn()=>pft_read(),409,'source ownership revoked after the aggregate snapshot is withheld by the final current publication fence');db()->beforeSql=null;
pft_ok($lateSourceChanged&&$ownerReads===3&&!$pdo->inTransaction()&&pft_read()['revision']===$baseline['revision'],'the late mutation ran, the denied owned snapshot rolled back and a fresh authorized read recovers');
$ownerReads=0;$lateOutputChanged=false;db()->beforeSql=static function(string $sql,PA3PDO $pdo)use(&$ownerReads,&$lateOutputChanged,$ownerReadSql):void{
    if($sql===$ownerReadSql&&++$ownerReads===3){$pdo->beforeSql=null;$pdo->exec('UPDATE user_media_assets SET user_id=4 WHERE id=996002');$lateOutputChanged=true;}
};
try{$r=pft_read();pft_ok($lateOutputChanged&&$ownerReads===3&&$r['revision']===$baseline['revision']&&$r['exact_creative']['availability']==='unavailable'&&$r['exact_creative']['marker']===null,
    'late output-image reassignment removes the review action without rewriting the fixed-period facts');}
finally{db()->beforeSql=null;db()->exec('UPDATE user_media_assets SET user_id=1 WHERE id=996002');}
foreach([
    'public thread owner'=>static fn(PDO $pdo)=>$pdo->exec('UPDATE profile_agent_conversations SET owner_user_id=4 WHERE id=991011'),
    'immutable native session'=>static fn(PDO $pdo)=>$pdo->exec("UPDATE profile_visit_sessions SET session_key='changed-current-session' WHERE id=990011"),
    'completed reply receipt'=>static fn(PDO $pdo)=>$pdo->exec("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=998011"),
] as $label=>$mutate){
    $ownerReads=0;$changed=false;db()->beforeSql=static function(string $sql,PA3PDO $pdo)use(&$ownerReads,&$changed,$ownerReadSql,$mutate):void{
        if($sql===$ownerReadSql&&++$ownerReads===3){$pdo->beforeSql=null;$mutate($pdo);$changed=true;}
    };
    pft_denied(fn()=>pft_read(),409,'final current exchange fence withholds changed '.$label);
    db()->beforeSql=null;
    pft_ok($changed&&$ownerReads===3&&!db()->inTransaction()&&pft_read()['revision']===$baseline['revision'],
        'the actual late '.$label.' mutation was rolled back without exposing the saved question');
}

// A genuine public admission writes the promotion key and canonical reply
// receipt from the worker bootstrap, without loading the API context bridge.
$native=$nativeFixture;$private=$fixture['scope'];$private['agent_id']=8;$private['conversation_id']=12;
$private['principal']=['kind'=>'user_agent','agent_id'=>8,'owner_user_id'=>1,'viewer_user_id'=>1];
$art=file_get_contents(STONEFELLOW_ROOT.'/private/user-media/1/original.png');
$draftData=['kind'=>'product_marketing_draft','draft_id'=>$native['draft_key'],'product_id'=>$native['product'],'title'=>'Field guide promotion',
    'asset_id'=>51,'asset_sha256'=>hash('sha256',$art)];
db()->prepare('UPDATE agent_commerce_marketing_drafts_v1 SET draft_json=? WHERE product_id=? AND request_key=?')->execute([json_encode($draftData,JSON_THROW_ON_ERROR),$native['product'],$native['draft_key']]);
db()->exec("UPDATE user_media_assets SET metadata_json='{".'"creative_id":"'.$native['promotion_id'].'"'."}' WHERE id=52");
db()->prepare('UPDATE agent_promotion_publications_v1 SET created_at=? WHERE publication_id=?')->execute([gmdate('Y-m-d H:i:s',$fixture['cutoff']-3*86400),$native['publication_id']]);
pft_clock(($fixture['cutoff']-86400+45000)*1000);
$admitted=pa3_admit('What is the price of the field guide product?',0,null,2,'e');$turnId=(int)$admitted['handoff']['turn_id'];
pft_clock($GLOBALS['pa3Now']+9000);vp3_profile_handoff_dispatch_pa3(db(),$turnId);$turn=vp3_profile_handoff_row_pa3(db(),$turnId);
$q=db()->prepare('SELECT * FROM profile_agent_messages WHERE id=?');$q->execute([$turn['answer_message_id']]);$answer=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
$context=json_decode((string)$answer['context_json'],true,64,JSON_THROW_ON_ERROR);
pft_ok($turn['state']==='replied'&&($context['product_promotion_key']??null)===$native['publication_id'],'the real public admission and worker save the exact promotion key on their canonical answer');
$realRef=vp3_profile_commerce_followthrough_ref_v1($native['publication_id'],$fixture['period_ref']);$real=pft_read(['ref'=>$realRef],$private);
pft_ok(pft_metric($real,'promotion_linked_exchanges')['value']===1&&$real['exchanges']['items'][0]['turn_id']===$turnId
    &&$real['exchanges']['items'][0]['question']==='What is the price of the field guide product?','actual saved promotion context and canonical string-ID receipt produce the exact safe exchange');
pft_ok($real['publication']['chat_agent_id']===8&&(int)$turn['profile_agent_id']===7,'public responder and private planning Agent remain independent identities');
pft_case(static function(PDO $pdo)use($private,$real,$realRef):void{
    $pdo->exec("UPDATE user_agents SET display_name='Retired public guide',is_active=0 WHERE id=7");$pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
    pft_ok(pft_read(['ref'=>$realRef],$private)['revision']===$real['revision'],'retiring the historical public Agent leaves current private planning evidence intact');
});
pft_case(static function(PDO $pdo)use($private,$realRef,$turn):void{
    $pdo->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE run_id=? AND action_id=? AND status='completed'")->execute([$turn['run_id'],$turn['action_id']]);
    pft_ok(pft_metric(pft_read(['ref'=>$realRef],$private),'promotion_linked_exchanges')['coverage']==='partial','tampering with the actual worker receipt withholds the associated exchange');
});

if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    $config=$GLOBALS['pa3Config'];$writer=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo=db();$pdo->beginTransaction();$pdo->query('SELECT * FROM user_media_assets WHERE id=996001')->fetchAll();$writer->exec('UPDATE user_media_assets SET user_id=4 WHERE id=996001');
    try{pft_denied(fn()=>pft_read(),409,'native current source locks reject a reassigned source hidden by an older repeatable-read snapshot');}
    finally{$pdo->rollBack();$writer->exec('UPDATE user_media_assets SET user_id=1 WHERE id=996001');}
    $pdo->beginTransaction();pft_read();$writer->exec('SET innodb_lock_wait_timeout=1');
    try{$writer->exec('UPDATE user_media_assets SET user_id=4 WHERE id=996001');throw new RuntimeException('Native source ownership lock was not held.');}
    catch(PDOException $error){pft_ok((int)($error->errorInfo[1]??0)===1205,'native report holds the exact source-owner row through its caller transaction');}
    finally{$pdo->rollBack();}
}
echo 'PROFILE_COMMERCE_FOLLOWTHROUGH_V1=PASS ('.$checks.' checks; driver='.db()->getAttribute(PDO::ATTR_DRIVER_NAME).'; skips=0)'.PHP_EOL;
