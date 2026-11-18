<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/product-marketing-followthrough-v1.php';
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{return vp3_profile_analytics_table_v1(db(),$table);}
function url(string $path):string{return ($GLOBALS['p6_base']??'').$path;}
function has_permission(string $key,array $user):bool
{
    if(empty($user['is_active']))return false;$q=db()->prepare('SELECT 1 FROM role_permissions WHERE role=? AND permission_key=?');$q->execute([$user['role']??'fan',$key]);return(bool)$q->fetchColumn();
}
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-coordination-product-marketing-v1.php';
final class ProductMarketingPDO extends PDO
{
    public bool $guard=false;public string $failure='';public mixed $afterCommitHook=null;
    private function inspect(string $sql):void
    {
        if($this->guard&&!preg_match('/\A\s*(SELECT|SHOW|PRAGMA|SET\s+TRANSACTION)\b/i',$sql))throw new LogicException('Read attempted mutation: '.$sql);
        if($this->failure!==''&&str_contains($sql,$this->failure))throw new PDOException('fixture outage');
    }
    public function prepare(string $sql,array $options=[]):PDOStatement|false{$this->inspect($sql);return parent::prepare($sql,$options);}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$this->inspect($sql);return $fetchMode===null?parent::query($sql):parent::query($sql,$fetchMode,...$args);}
    public function exec(string $sql):int|false{$this->inspect($sql);return parent::exec($sql);}
    public function commit():bool{$ok=parent::commit();if($f=$this->afterCommitHook){$this->afterCommitHook=null;$g=$this->guard;$this->guard=false;try{$f($this);}finally{$this->guard=$g;}}return $ok;}
}
$dsn='sqlite::memory:';$user=$password=null;
if($input=getenv('VP3_TEST_MYSQL_DSN')){
    $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=getenv('VP3_TEST_MYSQL_PASS')?:'root';$admin=new PDO($input,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_product_marketing_'.bin2hex(random_bytes(6));$admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
    $dsn=preg_match('/dbname=[^;]+/',$input)?preg_replace('/dbname=[^;]+/','dbname='.$database,$input):rtrim($input,';').';dbname='.$database;
}
$pdo=new ProductMarketingPDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET SESSION time_zone='+05:30'");}
$f=product_marketing_followthrough_fixture_v1($pdo);$scope=$f['scope'];$now=new DateTimeImmutable('2026-11-18T12:00:00Z');$checks=0;
function p6_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function p6_read(?string $publication=null,?array $scope=null,?DateTimeImmutable $now=null):array
{
    $pdo=$GLOBALS['pdo'];$pdo->guard=true;
    try{return vp3_product_marketing_outcome_v1($pdo,$scope??$GLOBALS['scope'],$publication??$GLOBALS['f']['publications'][0],['ref'=>$GLOBALS['f']['ref']],$now??$GLOBALS['now']);}
    finally{$pdo->guard=false;}
}
function p6_metrics(array $r):array{return array_column($r['metrics'],null,'key');}
function p6_change(callable $change,callable $check):void{$p=$GLOBALS['pdo'];$p->beginTransaction();try{$change($p);$check();}finally{if($p->inTransaction())$p->rollBack();}}
function p6_reject(callable $read,int $code,string $label):void{try{$read();}catch(Throwable $error){p6_check($error->getCode()===$code,$label);return;}throw new RuntimeException('FAIL '.$label);}
function p6_review(?array $refs=null):array{$p=$GLOBALS['pdo'];$p->guard=true;try{return vp3_product_marketing_review_v1($p,$GLOBALS['scope'],$GLOBALS['now'],$refs);}finally{$p->guard=false;}}
$r=p6_read();$m=p6_metrics($r);
p6_check($r['coverage']==='ready'&&count($m)===6,'real canonical publication yields six fully verified fixed metrics');
foreach(['accepted_promotion_clicks'=>[2,1],'native_promotion_conversations'=>[1,0],'checkout_started_orders'=>[0,1],'verified_paid_orders'=>[1,0],'verified_refunded_orders'=>[1,0],'promotion_associated_exchanges'=>[0,1]] as $key=>$expected){
    foreach(['current','previous'] as $i=>$side)p6_check($m[$key][$side]['value']===$expected[$i]&&$m[$key][$side]['coverage']==='complete',$key.' '.$side.' uses canonical evidence');
}
p6_check($r['money'][0]['currency']==='USD'&&$r['money'][0]['current']['value_minor']==='2000'&&$r['money'][0]['previous']['value_minor']==='1000','exact USD payment strings remain within publication subset');
p6_check($r['money'][1]['current']['value_minor']==='500','later refund remains a separate exact amount');
$b=p6_read($f['publications'][1]);p6_check(count($b['money'])===2&&$b['money'][0]['currency']==='EUR'&&p6_metrics($b)['verified_paid_orders']['current']['value']===1,'another publication cannot inherit USD payments or refunds');
p6_check($r['legacy_delivered']&&$r['actions'][3]['message_id']===$f['native_message_id'],'native presentation requires exact saved private marker');
p6_check(str_contains($r['actions'][0]['href'],'marketing_publication='.$f['publications'][0])&&str_contains($r['actions'][0]['href'],'marketing_period='.rawurlencode($f['ref'])),'primary destination preserves exact publication and fixed period');
p6_check(!str_contains($r['actions'][0]['href'],'message_id='),'native message stays a related action');
foreach([$r['ref'],'marketing:product-plan:992101'] as $ref)p6_check(vp3_product_marketing_ref_parse_v1($ref,$now)!==null,'pure canonical source parser accepts '.$ref);
foreach(['marketing:product-plan:01',str_replace('VVRD','VVRD=',$r['ref']),strtoupper($r['ref']),'marketing:product-outcome:'.$f['publications'][0].':v1:2:1794873600:e:VVRD'] as $ref)p6_check(vp3_product_marketing_ref_parse_v1($ref,$now)===null,'pure parser rejects noncanonical source');
$review=p6_review([$r['ref'],'marketing:product-plan:992101']);
p6_check(count($review['plans'])===1&&count($review['publications'])===1,'full review returns plan and fixed outcome DTOs');
p6_check($review['sources'][$r['ref']]['revision']===$r['revision']&&$review['sources'][$r['ref']]['trigger']==='product_outcome','source and full outcome share exact revision and rule trigger');
p6_check($review['plans'][0]['stage']==='listing_review','existing private draft lifecycle remains canonical');
p6_change(fn($p)=>$p->exec('UPDATE agent_commerce_products_v800 SET price_cents=3100 WHERE id=992101'),function()use($review){
    p6_check(p6_review(['marketing:product-plan:992101'])['plans'][0]['revision']!==$review['plans'][0]['revision'],'saved plan revision includes current frozen review inputs');});
$associations=vp3_product_marketing_conversation_associations_v1($pdo,$scope,991002,$now);
p6_check(count($associations['items'])===1&&$associations['items'][0]['visitor_message_id']===991201,'literal exchange is linked only through native accepted reply receipt');
$a=$associations['items'][0];$related=p6_review([$a['source']['ref']]);
p6_check($related['sources'][$a['source']['ref']]['revision']===$a['source']['revision'],'Profile association carries the actual Marketing source revision');
$later=vp3_product_marketing_conversation_associations_v1($pdo,$scope,991002,new DateTimeImmutable('2026-11-28T12:00:00Z'));
p6_check($later===$associations,'unchanged saved exchange does not renew its related period ten days later');
p6_change(fn($p)=>$p->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1"),function()use($associations){
    p6_check(vp3_product_marketing_conversation_associations_v1($GLOBALS['pdo'],$GLOBALS['scope'],991002,$GLOBALS['now'])===$associations,'historical exchange uses declared immutable UTC fallback across timezone changes');});
p6_check(vp3_product_marketing_conversation_associations_v1($pdo,$scope,11,$now)['coverage']==='unavailable','coincident private Chat id cannot become public conversation authority');
p6_change(function($p){$p->exec("UPDATE profile_agent_handoff_turns_pa3 SET created_at='2026-11-16 11:00:00' WHERE id=995002");$p->exec("UPDATE profile_agent_messages SET created_at='2026-11-16 11:00:00' WHERE id=991201");$p->exec("UPDATE profile_agent_messages SET created_at='2026-11-16 11:00:30' WHERE id=991202");},function(){
    $v=p6_read();p6_check(count($v['associations']['items'])===1&&$v['associations']['items'][0]['url']==='/profile-agent.php?tab=inbox&conversation=991002','current-window literal question exposes only its exact authorized PUBLIC inbox URL');
    p6_check(str_contains(vp3_product_marketing_source_v1($v,'outcome')['summary'],'Visitor message:')&&str_contains(vp3_product_marketing_source_v1($v,'outcome')['summary'],'saved reply showed this promotion'),'source briefing includes literal visitor attribution without inferred intent');
    $GLOBALS['pdo']->exec("UPDATE profile_agent_messages SET created_at='2026-11-18 00:00:00' WHERE id=991202");
    p6_check(p6_read()['associations']['items']===[],'reply saved after cutoff cannot appear in the fixed-period questions');
});
p6_change(fn($p)=>$p->exec("UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1"),function()use($r){p6_check(p6_read()['revision']===$r['revision'],'private historical outcomes survive public Profile disable');});
p6_change(fn($p)=>$p->exec("UPDATE agent_promotion_publications_v1 SET next_review_at='2026-11-18 00:00:00',last_review_fingerprint='scheduler-only'"),function()use($r){p6_check(p6_read()['revision']===$r['revision'],'scheduler bookkeeping does not revise fixed evidence');});
p6_check(p6_read(null,null,new DateTimeImmutable('2026-11-20T12:00:00Z'))['revision']===$r['revision'],'observation time does not revise a fixed source');
p6_change(fn($p)=>$p->exec("UPDATE users SET timezone='Pacific/Auckland' WHERE id=1"),function()use($r){p6_check(p6_read()['window']===$r['window']&&p6_read()['revision']===$r['revision'],'saved period retains original timezone after owner preference changes');});
p6_change(fn($p)=>$p->exec("UPDATE agent_commerce_promotions_v1 SET status='paused' WHERE id='".str_repeat('1',32)."'"),function(){p6_check(p6_read()['publication_status']==='paused'&&p6_metrics(p6_read())['verified_paid_orders']['current']['value']===1,'paused publication retains verified historical totals');});
p6_change(fn($p)=>$p->exec("UPDATE agent_commerce_promotions_v1 SET public_key='".str_repeat('9',32)."' WHERE id='".str_repeat('1',32)."'"),function()use($f){$v=p6_read();p6_check($v['publication_status']==='historical'&&$v['resume']['publication_id']===$f['publications'][0],'rotated publication selector keeps old immutable key');});
p6_change(fn($p)=>$p->exec("UPDATE agent_promotion_publications_v1 SET created_at='2026-11-16 00:00:00' WHERE publication_id='".str_repeat('a',32)."'"),function(){
    $a=vp3_product_marketing_conversation_associations_v1($GLOBALS['pdo'],$GLOBALS['scope'],991002,$GLOBALS['now']);
    p6_check($a['items']===[]&&$a['coverage']==='limited','a saved reply cannot show a publication that did not yet exist');
});
p6_change(fn($p)=>$p->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'"),function(){
    $v=p6_read();p6_check(p6_metrics($v)['promotion_associated_exchanges']['current']['coverage']==='unavailable'&&p6_metrics($v)['verified_paid_orders']['current']['value']===1,'Profile permission loss withholds excerpts while ordinary Commerce totals remain');
    p6_check(count(p6_review(['marketing:product-plan:992101'])['plans'])===1,'ordinary product plan is not hidden behind Profile permission');
});
foreach(['account.access','chat.access'] as $permission)p6_change(fn($p)=>$p->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='$permission'"),fn()=>p6_reject(fn()=>p6_read(),403,'current '.$permission.' revocation blocks publication'));
p6_change(fn($p)=>$p->exec('UPDATE chat_conversations SET user_agent_id=NULL WHERE id=11'),fn()=>p6_reject(fn()=>p6_read(),403,'original private Chat rebound fails current authority'));
p6_change(fn($p)=>$p->exec("UPDATE agent_product_launches_v1 SET conversation_id=99 WHERE product_id=992101"),fn()=>p6_reject(fn()=>p6_read(),409,'launch original Chat mismatch is withheld'));
p6_change(fn($p)=>$p->exec("UPDATE agent_commerce_promotions_v1 SET draft_key='".str_repeat('f',64)."' WHERE id='".str_repeat('1',32)."'"),fn()=>p6_reject(fn()=>p6_read(),409,'creative draft substitution cannot satisfy archived publication'));
p6_change(fn($p)=>$p->exec('DELETE FROM agent_promotion_orders_v1 WHERE order_id=992201'),function(){p6_check(p6_metrics(p6_read())['verified_paid_orders']['current']['coverage']==='partial','missing expected attribution binding is unknown, not zero');});
p6_change(fn($p)=>$p->exec("UPDATE agent_promotion_orders_v1 SET click_id='".str_repeat('4',32)."' WHERE order_id=992201"),function(){p6_check(p6_metrics(p6_read())['verified_paid_orders']['current']['coverage']==='partial','cross-publication order binding cannot claim a payment');});
p6_change(fn($p)=>$p->exec('DELETE FROM agent_commerce_payments_v800 WHERE id=992401'),function(){p6_check(p6_metrics(p6_read())['verified_paid_orders']['current']['value']===null,'missing prior payment prevents a verified first-completion count');});
p6_change(fn($p)=>$p->exec('UPDATE agent_commerce_orders_v800 SET amount_refunded_cents=1000 WHERE id=992201'),function(){p6_check(p6_read()['money'][1]['current']['coverage']==='partial','missing refund receipt prevents complete refund totals');});
p6_change(fn($p)=>$p->exec('DELETE FROM agent_commerce_payments_v800 WHERE id=992403'),function()use($r){p6_check(p6_read()['revision']===$r['revision'],'unrelated incomplete order does not poison publication subset');});
p6_change(fn($p)=>$p->exec('UPDATE agent_commerce_checkout_attempts_v800 SET opened_at_utc_ms=NULL WHERE id=993401'),function(){p6_check(p6_metrics(p6_read())['checkout_started_orders']['current']['coverage']==='partial'&&p6_metrics(p6_read())['checkout_started_orders']['current']['observed_value']===0,'undated earlier checkout cannot be replaced by a later known attempt');});
p6_change(fn($p)=>$p->exec("UPDATE agent_commerce_checkout_attempts_v800 SET creation_request_json='{}' WHERE id=993401"),function(){p6_check(p6_metrics(p6_read())['checkout_started_orders']['previous']['coverage']==='partial','checkout opening requires frozen provider request proof');});
p6_change(fn($p)=>$p->exec('UPDATE agent_promotion_clicks_v1 SET native_context_json_v1=NULL'),function(){
    $v=p6_read();$m=p6_metrics($v);p6_check($m['native_promotion_conversations']['current']['coverage']==='partial'&&$m['accepted_promotion_clicks']['current']['value']===2&&$m['verified_paid_orders']['current']['value']===1,'legacy click proof limits association without corrupting click or payment measures');
    p6_check(str_starts_with(vp3_product_marketing_source_v1($v,'outcome')['summary'],'Partial coverage.'),'partial availability survives compact planner and morning truncation');});
p6_change(fn($p)=>$p->exec("UPDATE users SET password_hash='changed-auth' WHERE id=98002"),function(){
    p6_check(p6_metrics(p6_read())['native_promotion_conversations']['current']['coverage']==='partial','revoked browser identity cannot establish native conversation click');
    $v=vp3_product_marketing_conversation_associations_v1($GLOBALS['pdo'],$GLOBALS['scope'],991002,$GLOBALS['now']);p6_check($v['items']===[]&&$v['coverage']==='limited','visitor authentication revocation withholds literal associated text');
});
p6_change(fn($p)=>$p->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,98002)'),function(){
    p6_check(vp3_product_marketing_conversation_associations_v1($GLOBALS['pdo'],$GLOBALS['scope'],991002,$GLOBALS['now'])['items']===[],'current visitor block withholds literal promotion-associated text');
});
p6_change(fn($p)=>$p->exec("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=998001"),function(){
    $v=p6_read();p6_check(p6_metrics($v)['promotion_associated_exchanges']['previous']['coverage']==='partial','saved promotion key without exact receipt is partial');
    $a=vp3_product_marketing_conversation_associations_v1($GLOBALS['pdo'],$GLOBALS['scope'],991002,$GLOBALS['now']);p6_check($a['items']===[]&&$a['coverage']==='limited','invalid receipt cannot disclose a product-question association');});
p6_change(fn($p)=>$p->exec("UPDATE profile_agent_messages SET message='edited after admission' WHERE id=991201"),function(){p6_check(p6_metrics(p6_read())['promotion_associated_exchanges']['previous']['coverage']==='partial','visitor statement must match frozen accepted hash');});
p6_change(fn($p)=>$p->exec("UPDATE agent_promotion_clicks_v1 SET created_at='2026-11-17 00:00:00' WHERE click_id='".str_repeat('3',32)."'"),function(){p6_check(p6_metrics(p6_read())['accepted_promotion_clicks']['current']['value']===1,'period endpoint excludes exact cutoff click');});
p6_change(fn($p)=>$p->exec("UPDATE chat_messages SET context_json='{}' WHERE id=997901"),function()use($r){$v=p6_read();p6_check(!$v['legacy_delivered']&&$v['revision']===$r['revision']&&count($v['actions'])===3,'forged native marker removes related action without revising fixed figures');});
$bytes=file_get_contents($f['source_file']);try{file_put_contents($f['source_file'],$bytes.'changed');p6_reject(fn()=>p6_read(),409,'source bytes changed without timestamp still revoke source');}finally{file_put_contents($f['source_file'],$bytes);}
$GLOBALS['p6_base']='/installed';$sub=p6_read();p6_check(str_starts_with($sub['actions'][0]['href'],'/installed/chat.php?'),'exact action respects subdirectory installation');$GLOBALS['p6_base']='';
$pdo->afterCommitHook=static fn($p)=>$p->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='chat.access'");
p6_reject(fn()=>p6_read(),403,'authority revoked after snapshot commit is withheld');
$pdo->prepare('INSERT INTO role_permissions(role,permission_key) VALUES(?,?)')->execute(['fan','chat.access']);
$pdo->afterCommitHook=static fn($p)=>$p->exec('UPDATE agent_product_launches_v1 SET creative_id=NULL WHERE product_id=992101');
p6_reject(fn()=>p6_review(['marketing:product-plan:992101']),409,'plan selection changed after snapshot cannot return its older creative');
$pdo->prepare('UPDATE agent_product_launches_v1 SET creative_id=? WHERE product_id=992101')->execute([str_repeat('1',32)]);
$pdo->afterCommitHook=static fn($p)=>$p->exec("UPDATE users SET password_hash='late-revocation' WHERE id=98002");
p6_reject(fn()=>vp3_product_marketing_conversation_associations_v1($pdo,$scope,991002,$now),409,'visitor revocation after snapshot withholds literal association');
$pdo->exec("UPDATE users SET password_hash='fixture-visitor-password' WHERE id=98002");
$savedRef=$f['ref'];$f['ref']=vp3_profile_analytics_period_ref_v1(7,$f['cutoff'],'UTC','e');
try{
    p6_check(count(p6_read()['associations']['items'])===1,'selected seven-day window includes the exact historical saved exchange');
    $pdo->afterCommitHook=static fn($p)=>$p->exec("UPDATE profile_agent_messages SET created_at='2026-11-19 12:00:00' WHERE id=991202");
    try{p6_reject(fn()=>p6_read(),409,'reply timestamp moved after snapshot cutoff cannot disclose the old excerpt');}
    finally{$pdo->exec("UPDATE profile_agent_messages SET created_at='2026-11-15 11:00:30' WHERE id=991202");}
    $pdo->afterCommitHook=static fn($p)=>$p->exec("UPDATE profile_agent_handoff_turns_pa3 SET created_at='2026-11-15 10:59:00' WHERE id=995002");
    try{p6_reject(fn()=>p6_read(),409,'accepted-turn timestamp mutation invalidates final exact exchange proof');}
    finally{$pdo->exec("UPDATE profile_agent_handoff_turns_pa3 SET created_at='2026-11-15 11:00:00' WHERE id=995002");}
}finally{$f['ref']=$savedRef;}
$pdo->failure='FROM agent_promotion_clicks_v1';$unavailable=p6_review([$r['ref']]);$pdo->failure='';p6_check($unavailable['coverage']==='unavailable'&&$unavailable['sources']===[],'storage outage is explicitly unavailable with no stale source');
p6_check(!$pdo->inTransaction(),'owned readers leave no open transaction');
$pdo->beginTransaction();$inside=p6_read();p6_check($pdo->inTransaction()&&$inside['revision']===$r['revision'],'reader preserves caller transaction and exact source');$pdo->rollBack();
p6_change(fn($p)=>$p->exec("UPDATE agent_commerce_products_v800 SET title='<b>Blue</b> https://hostile.invalid/word' WHERE id=992101"),function(){
    $v=p6_read();$source=vp3_product_marketing_source_v1($v,'outcome');p6_check(!str_contains($source['title'],'<')&&!str_contains($source['title'],'https://')&&str_contains($source['title'],'[link in source]'),'untrusted product labels cannot become HTML or planner URLs');});
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    $other=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->beginTransaction();$pdo->query('SELECT price_cents FROM agent_commerce_products_v800 WHERE id=992101')->fetchColumn();
    try{$other->exec('UPDATE agent_commerce_products_v800 SET price_cents=3100 WHERE id=992101');
        $stale=p6_review(['marketing:product-plan:992101']);
        p6_check($stale['plans']===[]&&$stale['coverage']==='limited'&&$pdo->inTransaction(),'native MySQL rejects stale caller snapshot after current product change');
    }finally{if($pdo->inTransaction())$pdo->rollBack();$other->exec('UPDATE agent_commerce_products_v800 SET price_cents=3000 WHERE id=992101');}
    $pdo->beginTransaction();$pdo->query('SELECT * FROM user_media_assets WHERE id=997601')->fetchAll();
    try{$other->exec('UPDATE user_media_assets SET user_id=98002 WHERE id=997601');
        p6_reject(fn()=>p6_read(),409,'native MySQL cannot resolve a transferred source image through an older caller snapshot');
    }finally{if($pdo->inTransaction())$pdo->rollBack();$other->exec('UPDATE user_media_assets SET user_id=1 WHERE id=997601');}
}
echo 'PRODUCT_MARKETING_FOLLOWTHROUGH_V1=PASS ('.$checks.' checks; driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; skips=0)'.PHP_EOL;
