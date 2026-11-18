<?php
declare(strict_types=1);

// Retained parallel-build entry point. Exercise the reconciled production
// collector and explicit policy differences, not a second report adapter.
$root=sys_get_temp_dir().'/vp3-product-followthrough-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
require_once __DIR__.'/fixtures/profile-promotion-capture-v1.php';
require_once __DIR__.'/fixtures/profile-commerce-followthrough-data-v1.php';
require_once dirname(__DIR__).'/includes/profile-commerce-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-product-marketing-summary-v1.php';
$native=pcap_fixture_init_v1();$fixture=profile_commerce_followthrough_fixture_v1(db());
$now=new DateTimeImmutable('2026-11-18T12:00:00Z');$checks=0;
function p6f_check(bool $ok,string $label):void
{if(!$ok)throw new RuntimeException('FAIL '.$label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function p6f_read(?array $selection=null,?array $scope=null,?DateTimeImmutable $now=null):array
{return vp3_profile_commerce_followthrough_v1(db(),$scope??$GLOBALS['fixture']['scope'],$selection??['ref'=>$GLOBALS['fixture']['ref']],$now??$GLOBALS['now']);}
function p6f_metric(array $report,string $key,string $side='current'):array
{foreach($report['metrics'] as $row)if($row['key']===$key)return $row[$side];throw new LogicException('Missing metric '.$key);}
function p6f_case(callable $run):void
{
    $p=db();if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $p->beginTransaction();try{$run($p);}finally{$p->beforeSql=null;$p->afterSql=null;$p->failSql='';if($p->inTransaction())$p->rollBack();}
}
function p6f_reject(callable $run,int $code,string $label):void
{try{$run();}catch(Throwable $error){p6f_check($error->getCode()===$code,$label.' ('.$error->getCode().')');return;}throw new RuntimeException('FAIL '.$label);}
function p6f_row(string $table,int|string $id,string $key='id'):array
{$q=db()->prepare('SELECT * FROM '.$table.' WHERE '.$key.'=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:throw new LogicException('Missing fixture row');}
function p6f_snapshot():array
{
    $out=[];
    foreach(['agent_product_launches_v1','agent_commerce_products_v800','agent_commerce_marketing_drafts_v1','agent_commerce_promotions_v1',
        'agent_promotion_publications_v1','agent_promotion_clicks_v1','agent_promotion_orders_v1','profile_agent_conversations',
        'profile_agent_messages','profile_agent_handoff_turns_pa3','agent_commerce_checkout_attempts_v800',
        'agent_commerce_orders_v800','agent_commerce_order_items_v800','agent_commerce_payments_v800','agent_commerce_refunds_v800',
        'agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts'] as $table){
        $rows=array_map(static fn(array $r):string=>json_encode($r,JSON_THROW_ON_ERROR),db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows,SORT_STRING);$out[$table]=hash('sha256',implode("\n",$rows));
    }
    return $out;
}
function p6f_cleanup(string $path):void
{if(!is_dir($path))return;foreach(scandir($path) as $name){if($name==='.'||$name==='..')continue;$entry=$path.'/'.$name;if(is_dir($entry))p6f_cleanup($entry);else unlink($entry);}rmdir($path);}
register_shutdown_function(static fn()=>p6f_cleanup($GLOBALS['root']));

$before=p6f_snapshot();$models=count($GLOBALS['pa3ModelCalls']);$providers=count($GLOBALS['pa5ProviderRequests']);
db()->beforeSql=static function(string $sql):void{if(!preg_match('/\A\s*(SELECT|SHOW|PRAGMA|SET TRANSACTION)\b/i',$sql))throw new LogicException('Report attempted write: '.$sql);};
try{$baseline=p6f_read();}finally{db()->beforeSql=null;}
p6f_check($baseline['coverage']==='ready'&&count($baseline['metrics'])===7,'one canonical report retains seven explicitly defined measures');
foreach(['recorded_cta_clicks'=>[2,1],'conversation_attributed_clicks'=>[1,0],'promotion_linked_exchanges'=>[1,0],
    'recorded_attributed_orders'=>[0,1],'verified_checkout_openings'=>[1,1],'verified_paid_orders'=>[1,0],'verified_refunded_orders'=>[1,0]] as $key=>[$a,$b])
    p6f_check(p6f_metric($baseline,$key)['value']===$a&&p6f_metric($baseline,$key,'previous')['value']===$b,$key.' has exact current and previous evidence');
p6f_check(p6f_snapshot()===$before&&count($GLOBALS['pa3ModelCalls'])===$models&&count($GLOBALS['pa5ProviderRequests'])===$providers,
    'reporting writes no product, publication, visitor, checkout, receipt or workflow and dispatches nothing');
$source=vp3_product_marketing_period_item_v1($baseline,7,11);
p6f_check($source!==null&&$source['question']===''&&$source['next_step']===''&&!$source['legacy_delivered'],
    'period facts create neither an owner obligation nor a fabricated native delivery receipt');
p6f_check(str_contains($source['url'],'marketing_followthrough='.rawurlencode($fixture['ref']))
    &&!str_contains($source['url'],'message_id='),'the primary link selects the exact original publication period');
p6f_check($baseline['money'][0]['currency']==='USD'&&$baseline['money'][0]['current']['value_minor']==='2000'
    &&$baseline['money'][1]['current']['value_minor']==='500','payments and refunds retain separate exact minor-unit amounts');
$other=p6f_read(['ref'=>vp3_profile_commerce_followthrough_ref_v1($fixture['ids']['current_publication'],$fixture['period_ref'])]);
p6f_check(p6f_metric($other,'verified_paid_orders')['value']===0&&$other['money']===[],
    'a newer publication cannot inherit the earlier publication payments');
p6f_check(p6f_metric($baseline,'verified_checkout_openings')['value']+p6f_metric($baseline,'verified_checkout_openings','previous')['value']===2
    &&p6f_metric($baseline,'recorded_attributed_orders','previous')['value']===1,
    'two committed checkout attempts remain two link-availability events even when attached to one order');

p6f_case(static function(PDO $p)use($baseline):void{
    $p->exec("UPDATE agent_commerce_products_v800 SET title='Renamed current catalog item' WHERE id=992101");
    $p->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused',public_key=? WHERE id=?")
        ->execute([str_repeat('9',32),$GLOBALS['fixture']['ids']['original_creative']]);
    $r=p6f_read();p6f_check($r['publication']['title']==='Original T-shirt promotion'&&$r['revision']===$baseline['revision'],
        'current title, paused creative and rotated public selector cannot rewrite an immutable historical period');
    p6f_check($r['publication']['publication_id']===$GLOBALS['fixture']['publication_id'],'the report still identifies the original publication');
});
p6f_case(static function(PDO $p)use($baseline):void{
    $p->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1");
    $p->exec("UPDATE agent_promotion_publications_v1 SET next_review_at='2026-12-01 00:00:00',last_review_fingerprint='scheduler-only'");
    $r=p6f_read(null,null,$GLOBALS['now']->modify('+10 days'));
    p6f_check($r['revision']===$baseline['revision']&&$r['window']===$baseline['window'],
        'current timezone, observation time and scheduler bookkeeping do not renew fixed evidence');
});
p6f_case(static function(PDO $p)use($baseline):void{
    $p->exec("UPDATE users SET password_hash='rotated-password',is_active=0 WHERE id=98002");
    $p->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,98002)');
    $p->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
    p6f_check(p6f_read()['revision']===$baseline['revision'],
        'current visitor authentication, block and public visibility changes do not rewrite accepted historical evidence');
});
p6f_case(static function(PDO $p)use($baseline):void{
    $row=p6f_row('profile_agent_messages',$GLOBALS['fixture']['ids']['answer_message']);
    $row['id']=999991;$row['created_at']=gmdate('Y-m-d H:i:s',$GLOBALS['fixture']['cutoff']+3600);
    $row['context_json']=json_encode(['product_promotion_key'=>$GLOBALS['fixture']['publication_id']],JSON_THROW_ON_ERROR);
    profile_analytics_period_fixture_row_v1($p,'profile_agent_messages',$row);
    p6f_check(p6f_read()['revision']===$baseline['revision'],
        'a known future reply without receipt cannot poison coverage for an earlier completed period');
});
p6f_case(static function(PDO $p):void{
    $p->prepare('UPDATE agent_commerce_payments_v800 SET received_at=? WHERE id=992402')
        ->execute([gmdate('Y-m-d H:i:s',$GLOBALS['fixture']['cutoff']-172800+35999)]);
    $r=p6f_read();p6f_check(p6f_metric($r,'verified_paid_orders')['coverage']==='partial'
        &&p6f_metric($r,'verified_refunded_orders')['coverage']==='partial',
        'payment before the verified order clock cannot prove completion or a later verified refund');
});
p6f_case(static function(PDO $p):void{
    $p->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
    p6f_reject(fn()=>p6f_read(),403,'the full report keeps its explicit Profile authority contract');
    $f=$GLOBALS['fixture'];$list=vp3_profile_commerce_publications_v1($p,$f['scope'],$f['ids']['product'],'',$GLOBALS['now']);
    p6f_check(count($list['publications'])===2,'private publication inventory remains available through independent account and Chat grants');
});
p6f_case(static function(PDO $p):void{
    $p->exec("DELETE FROM settings WHERE setting_key='personal_capabilities_seed_v242'");
    $f=$GLOBALS['fixture'];$list=vp3_profile_commerce_publications_v1($p,$f['scope'],$f['ids']['product'],'',$GLOBALS['now']);
    p6f_check(count($list['publications'])===2&&$p->inTransaction(),
        'private inventory does not require unrelated personal-default provenance or end a caller transaction');
    p6f_reject(fn()=>p6f_read(),503,'missing personal provenance cannot certify the protected Profile report');
    $p->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='chat.access'");
    p6f_reject(fn()=>vp3_profile_commerce_publications_v1($p,$f['scope'],$f['ids']['product']),403,
        'missing seed cannot bypass a current private Chat denial');
});
p6f_case(static function(PDO $p):void{
    profile_analytics_period_fixture_row_v1($p,'chat_conversations',['id'=>999991,'user_id'=>1,'user_agent_id'=>7,'title'=>'Another private Chat']);
    $scope=$GLOBALS['fixture']['scope'];$scope['conversation_id']=999991;
    p6f_reject(fn()=>p6f_read(null,$scope),404,'same Agent with another private Chat is not silently rewritten to the original Chat');
});
p6f_case(static function(PDO $p):void{
    $p->prepare('UPDATE agent_promotion_clicks_v1 SET native_context_json_v1=NULL WHERE click_id=?')
        ->execute([$GLOBALS['fixture']['ids']['native_click']]);
    $r=p6f_read();p6f_check(p6f_metric($r,'conversation_attributed_clicks')['coverage']==='partial'
        &&p6f_metric($r,'recorded_cta_clicks')['value']===2&&p6f_metric($r,'verified_paid_orders')['value']===1,
        'legacy native association is unknown without erasing separate click and payment evidence');
    p6f_check(str_starts_with(vp3_product_marketing_period_item_v1($r,7,11)['summary'],'Partial coverage.'),
        'partial evidence qualification survives the shared compact summary');
});
p6f_case(static function(PDO $p):void{
    $row=p6f_row('agent_promotion_clicks_v1',$GLOBALS['fixture']['ids']['native_click'],'click_id');
    $row['click_id']=substr(hash('sha256','another-accepted-click-same-thread'),0,32);
    $proof=json_decode($row['native_context_json_v1'],true,8,JSON_THROW_ON_ERROR);$proof['click_id']=$row['click_id'];
    $row['native_context_json_v1']=json_encode($proof,JSON_THROW_ON_ERROR);
    profile_analytics_period_fixture_row_v1($p,'agent_promotion_clicks_v1',$row,'click_id');
    p6f_check(p6f_metric(p6f_read(),'conversation_attributed_clicks')['value']===2,
        'two exact accepted clicks in one public conversation remain two click events rather than unique people or threads');
});
p6f_case(static function(PDO $p):void{
    $p->exec("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=998011");$r=p6f_read();
    p6f_check($r['exchanges']['items']===[]&&p6f_metric($r,'promotion_linked_exchanges')['coverage']==='partial',
        'a stored promotion key alone cannot disclose a visitor statement without its exact native receipt');
});
$GLOBALS['pa3AppBase']='/nested/app';
try{p6f_check(str_starts_with(vp3_product_marketing_period_item_v1(p6f_read(),7,11)['url'],'/nested/app/chat.php?'),
    'the shared exact source link respects a subdirectory installation');}finally{$GLOBALS['pa3AppBase']='';}
p6f_check(!db()->inTransaction()&&p6f_snapshot()===$before,'isolated source mutations restore every original record and owned readers leave no transaction');
echo 'PRODUCT_MARKETING_FOLLOWTHROUGH_V1=PASS ('.$checks.' checks; driver='.db()->getAttribute(PDO::ATTR_DRIVER_NAME).'; skips=0)'.PHP_EOL;
