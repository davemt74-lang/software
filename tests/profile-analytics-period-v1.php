<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/profile-specialist-sources-v1.php';
require_once __DIR__.'/fixtures/profile-analytics-period-data-v1.php';
require_once dirname(__DIR__).'/includes/profile-analytics-period-v1.php';
pfs_reset();$fixture=profile_analytics_period_fixture_v1(db());$checks=0;
$now=new DateTimeImmutable('2026-11-18 12:00:00',new DateTimeZone('UTC'));
function pap_ok(bool $condition,string $label):void
{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function pap_read(?array $selection=null,?DateTimeImmutable $now=null):array
{
    return vp3_profile_analytics_period_v1(db(),$GLOBALS['fixture']['scope'],$selection??['ref'=>$GLOBALS['fixture']['ref']],$now??$GLOBALS['now']);
}
function pap_metric(array $snapshot,string $key,string $side='current'):array
{
    foreach($snapshot['metrics'] as $metric)if($metric['key']===$key)return $metric[$side];
    throw new RuntimeException('Missing metric '.$key);
}
function pap_money(array $snapshot,string $key,string $currency,string $side='current'):array
{
    foreach($snapshot['money'] as $money)if($money['key']===$key&&$money['currency']===$currency)return $money[$side];
    throw new RuntimeException('Missing money measure '.$key.' '.$currency);
}
function pap_case(callable $run):void
{
    $pdo=db();if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();try{$run($pdo);}finally{$pdo->beforeSql=null;$pdo->afterSql=null;$pdo->failSql='';if($pdo->inTransaction())$pdo->rollBack();}
}
function pap_denied(callable $run,int $code,string $label):void
{
    try{$run();}catch(RuntimeException $error){pap_ok($error->getCode()===$code&&!str_contains($error->getMessage(),'SECRET'),$label.' ('.$error->getCode().')');return;}
    throw new RuntimeException('FAIL '.$label);
}
function pap_json_update(PDO $pdo,string $table,string $column,int $id,callable $update):void
{
    $q=$pdo->prepare('SELECT '.$column.' FROM '.$table.' WHERE id=?');$q->execute([$id]);$value=json_decode((string)$q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);$q->closeCursor();
    $value=$update($value);$pdo->prepare('UPDATE '.$table.' SET '.$column.'=? WHERE id=?')->execute([json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
}

$readStatements=0;db()->beforeSql=static function(string $sql)use(&$readStatements):void{
    if(!preg_match('/\A\s*(?:SELECT|SHOW|PRAGMA|SET TRANSACTION)\b/i',$sql))throw new RuntimeException('Unexpected analytics write: '.substr($sql,0,80));
    $readStatements++;
};
$baseline=pap_read();db()->beforeSql=null;
pap_ok($readStatements>30&&$baseline['coverage']==='ready'&&!db()->inTransaction(),'the complete baseline is query-only and closes only its owned read transactions');
pap_ok(count($baseline['metrics'])===17&&array_keys($baseline['groups'])===['events','conversations','calls','commerce'],'the response always carries the closed seventeen-measure catalog and four groups');
$expected=['visit_records'=>[3,1],'recognized_browser_contacts'=>[1,1],'accepted_visitor_turns'=>[2,1],
    'active_conversations'=>[2,1],'new_conversations'=>[2,1],'owner_takeovers'=>[1,0],'owner_reply_messages'=>[1,0],
    'agent_reply_messages'=>[0,1],'calls_offered'=>[2,1],'calls_accepted'=>[1,1],'calls_finished'=>[2,1],
    'median_call_accept_seconds'=>[12,20],'recorded_product_conversions'=>[1,1],'recorded_booking_conversions'=>[1,0],
    'verified_paid_orders'=>[2,0],'verified_refunded_orders'=>[1,0],'median_first_reply_seconds'=>[15,30]];
foreach($expected as $key=>[$current,$previous]){
    $a=pap_metric($baseline,$key);$b=pap_metric($baseline,$key,'previous');
    pap_ok($a['coverage']==='complete'&&$b['coverage']==='complete'&&$a['value']===$current&&$b['value']===$previous,'exact current and previous evidence: '.$key);
}
pap_ok($baseline['first_reply_cohorts']['current']===['eligible'=>2,'replied'=>1,'unanswered'=>1,'unverifiable'=>0,'coverage'=>'complete','reason'=>'complete'],'new-conversation reply cohorts retain their unanswered denominator');
pap_ok($baseline['call_breakdowns']['current']['accepted']===['direct'=>0,'assisted'=>1]
    &&$baseline['call_breakdowns']['previous']['accepted']===['direct'=>1,'assisted'=>0],'direct and assisted classification is based on accepted calls');
pap_ok(pap_money($baseline,'verified_payments','USD')['value_minor']==='2000'
    &&pap_money($baseline,'verified_payments','USD','previous')['value_minor']==='1000'
    &&pap_money($baseline,'verified_payments','EUR')['value_minor']==='1500'
    &&pap_money($baseline,'verified_refunds','USD')['value_minor']==='500','split payments, distinct currencies, and refunds keep exact decimal-string amounts');
pap_ok(count(array_filter($baseline['money'],static fn(array $m):bool=>$m['scale']===2&&$m['amount_unit']==='commerce_cent'&&$m['scale_source']==='vp3.commerce.v800'))===count($baseline['money']),'money explicitly declares Commerce scale two without inferring a currency exponent');
pap_ok(!preg_match('/fixture-(?:owner|visitor)-password|analytics-native-session|Exact historical visitor statement/',json_encode($baseline,JSON_THROW_ON_ERROR)),'analytics exposes aggregates without private credentials or visitor message bodies');
pap_ok($baseline['backlog']['metrics'][0]['value']===1&&$baseline['backlog']['as_of_utc_ms']===$now->getTimestamp()*1000,'current unresolved inventory has a separate present-time observation');
$later=pap_read(null,$now->modify('+2 hours'));
pap_ok($later['revision']===$baseline['revision']&&$later['observed_at_utc_ms']!==$baseline['observed_at_utc_ms'],'checking a fixed period later does not change its source revision');

pap_case(static function(PDO $pdo)use($baseline):void{
    $pdo->exec("UPDATE profile_agent_conversations SET status='resolved' WHERE id=991003");$r=pap_read();
    pap_ok($r['revision']===$baseline['revision']&&$r['backlog']['revision']!==$baseline['backlog']['revision']&&$r['backlog']['metrics'][0]['value']===0,'today\'s backlog change never reconstructs yesterday\'s unresolved count');
    pap_ok($pdo->inTransaction(),'period reads preserve a caller-owned transaction');
});
pap_case(static function(PDO $pdo)use($baseline):void{
    $pdo->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
    pap_ok(pap_read()['revision']===$baseline['revision'],'turning the public Profile off preserves authorized private historical analytics');
});
pap_case(static function(PDO $pdo)use($baseline):void{
    $pdo->exec("UPDATE users SET timezone='Asia/Kathmandu' WHERE id=1");$old=pap_read();$latest=pap_read(['period'=>7]);
    pap_ok($old['revision']===$baseline['revision']&&$old['window']['timezone']==='UTC'&&$latest['window']['timezone']==='Asia/Kathmandu','an old reference retains its original zone while a new period uses the current configured zone');
    $pdo->exec("UPDATE users SET timezone='bad/timezone' WHERE id=1");
    pap_denied(fn()=>pap_read(['period'=>1]),503,'invalid configured timezone is unavailable rather than a silent UTC fallback');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec("UPDATE users SET timezone='' WHERE id=1");$r=pap_read(['period'=>1]);
    pap_ok($r['window']['timezone']==='UTC'&&$r['window']['timezone_source']==='utc_fallback','a missing owner timezone and schedule explicitly resolves to UTC fallback');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec("UPDATE users SET timezone='America/Havana' WHERE id=1");
    $r=pap_read(['period'=>1],new DateTimeImmutable('2026-03-08 12:00:00',new DateTimeZone('UTC')));
    pap_ok($r['window']['to_local']==='2026-03-08T01:00:00-04:00','latest-period resolution accepts a timezone whose local midnight was skipped');
});

pap_case(static function(PDO $pdo)use($baseline):void{
    $cutoff=$GLOBALS['fixture']['cutoff'];$future=($cutoff+3600)*1000;$request=str_repeat('e',32);
    $pdo->prepare('INSERT INTO profile_agent_handoff_operations_pa3(owner_user_id,request_id,turn_id,operation,request_hash,message_id,created_at_ms) VALUES(?,?,?,?,?,?,?)')
        ->execute([1,$request,995003,'reply',hash('sha256',$request),991302,$future]);
    profile_analytics_period_fixture_row_v1($pdo,'profile_agent_messages',['id'=>991302,'conversation_id'=>991003,'sender_type'=>'owner','sender_user_id'=>1,'message'=>'Later exact reply',
        'context_json'=>json_encode(['profile_handoff'=>['contract'=>'vp3.profile-handoff.v1','turn_id'=>995003,'owner_request_id'=>$request]],JSON_THROW_ON_ERROR),'created_at'=>gmdate('Y-m-d H:i:s',intdiv($future,1000))]);
    $q=$pdo->query('SELECT * FROM profile_events WHERE id=993001');$event=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $event['id']=993099;$event['dedupe_key']=hash('sha256','future-event');$event['recorded_at_utc_ms']=$future;
    profile_analytics_period_fixture_row_v1($pdo,'profile_events',$event);
    $q=$pdo->query('SELECT * FROM profile_calls_pa3c WHERE id=994001');$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $call['id']=994099;$call['public_id']=substr(hash('sha256','future-call'),0,32);$call['room_name']='vp3-profile-'.$call['public_id'];$call['owner_identity']='owner-'.$call['public_id'];$call['visitor_identity']='visitor-'.$call['public_id'];
    $call['created_at_ms']=$future;$call['accepted_at_ms']=$future+12000;$call['ended_at_ms']=$future+60000;$call['binding_hash']=vp3_profile_call_binding_hash_pa3c($call);
    profile_analytics_period_fixture_row_v1($pdo,'profile_calls_pa3c',$call);
    $q=$pdo->query('SELECT * FROM agent_commerce_refunds_v800 WHERE id=992601');$refund=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $refund['id']=992699;$refund['external_refund_id']='future_refund';$refund['completed_at']=gmdate('Y-m-d H:i:s',intdiv($future,1000));
    profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_refunds_v800',$refund);
    $r=pap_read();pap_ok($r['revision']===$baseline['revision'],'later exact replies, timed visits, calls, and refunds do not rewrite a completed period');
    pap_ok($r['first_reply_cohorts']['current']['unanswered']===1&&pap_metric($r,'verified_paid_orders')['value']===2,'late answers and refunds do not erase the old unanswered cohort or payment-completion facts');
});

pap_case(static function(PDO $pdo):void{
    $pdo->prepare('UPDATE profile_event_clock_activation_v1 SET activated_at_utc_ms=? WHERE id=1')->execute([($GLOBALS['fixture']['cutoff']-3600)*1000]);$r=pap_read();$p=pap_metric($r,'visit_records');
    pap_ok($r['coverage']==='limited'&&$p['coverage']==='partial'&&$p['value']===null&&$p['observed_value']===3&&$p['reason']==='before_clock_activation','a pre-activation window reports its observed subset rather than a false complete total');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE profile_event_clock_activation_v1 SET token=NULL,activated_at_utc_ms=NULL WHERE id=1');$r=pap_read();
    pap_ok($r['coverage']==='limited'&&$r['groups']['events']['coverage']==='unconfigured'&&pap_metric($r,'visit_records')['value']===null&&pap_metric($r,'calls_accepted')['value']===1,'an unconfigured event clock does not hide independent usable call and reply measures');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE profile_events SET recorded_at_utc_ms=NULL,clock_legacy_token_v1=NULL WHERE id=993001');$r=pap_read();$p=pap_metric($r,'visit_records');
    pap_ok($p['coverage']==='partial'&&$p['observed_value']===2&&$p['reason']==='clock_gap','an unclocked unmarked row is a gap regardless of its identifier or legacy DATETIME');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec("UPDATE profile_events SET clock_legacy_token_v1='ffffffffffffffffffffffffffffffff' WHERE id=993001");$r=pap_read();
    pap_ok(pap_metric($r,'visit_records')['coverage']==='partial'&&pap_metric($r,'visit_records')['observed_value']===2,'a foreign or clock-plus-marker provenance cannot be counted as a valid timed record');
});
pap_case(static function(PDO $pdo):void{
    $token=$pdo->query('SELECT token FROM profile_event_clock_activation_v1 WHERE id=1')->fetchColumn();
    $pdo->prepare('UPDATE profile_events SET recorded_at_utc_ms=NULL,clock_legacy_token_v1=? WHERE id=993001')->execute([$token]);
    $p=pap_metric(pap_read(),'visit_records');
    pap_ok($p['coverage']==='complete'&&$p['value']===2,'an explicitly marked untimed legacy row is excluded without inventing a gap after activation');
});
pap_case(static function(PDO $pdo):void{
    $q=$pdo->query('SELECT * FROM profile_events WHERE id=993001');$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $insert=$pdo->prepare('INSERT INTO profile_events(id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,event_type,metadata_json,created_at,priority,dedupe_key,recorded_at_utc_ms,clock_legacy_token_v1) VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL)');
    for($i=0;$i<1201;$i++)$insert->execute([1000000+$i,1,$row['profile_agent_id'],$row['profile_session_id'],$row['visitor_user_id'],'profile_view',$row['metadata_json'],'2001-01-01 00:00:00',1,hash('sha256','uncapped-period-event-'.$i),$row['recorded_at_utc_ms']]);
    $r=pap_read();pap_ok(pap_metric($r,'visit_records')['value']===1204&&pap_metric($r,'visit_records')['coverage']==='complete'
        &&pap_metric($r,'recognized_browser_contacts')['value']===1,'all 1201 additional timed records contribute to an uncapped total while repeated browser contacts remain distinct');
});
pap_case(static function(PDO $pdo)use($baseline):void{
    $pdo->exec('UPDATE profile_visit_sessions SET visitor_user_id=98002,identity_disclosed=1 WHERE id=990002');
    pap_ok(pap_read()['revision']===$baseline['revision'],'reusing a guest browser with a recognized CRM identity does not invent historical recognized visits');
});
pap_case(static function(PDO $pdo):void{
    pap_json_update($pdo,'profile_events','metadata_json',993002,static function(array $m):array{$m['source_authority']['credential_hash']=hash('sha256','second-verified-browser');return $m;});
    pap_ok(pap_metric(pap_read(),'recognized_browser_contacts')['value']===2,'distinct proved native browser credentials remain distinct from repeated records');
});
foreach([
    ["UPDATE user_profiles SET share_visit_identity=0 WHERE user_id=98002",'withdrawn current visit disclosure'],
    ["UPDATE users SET password_hash='rotated-credential' WHERE id=98002",'changed visitor authentication'],
    ["INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(1,98002)",'a current visitor block'],
    ["UPDATE profile_events SET metadata_json='{}' WHERE id=993001",'missing native event principal proof'],
] as [$change,$label])pap_case(static function(PDO $pdo)use($change,$label):void{
    $pdo->exec($change);$r=pap_read();$p=pap_metric($r,'recognized_browser_contacts');
    pap_ok($p['coverage']==='partial'&&$p['value']===null&&pap_metric($r,'visit_records')['value']===3,$label.' limits identity evidence without falsifying saved visit-record totals');
});

pap_case(static function(PDO $pdo):void{
    $pdo->exec("UPDATE profile_visit_sessions SET session_key='replaced-native-session' WHERE id=990001");$r=pap_read();
    pap_ok(pap_metric($r,'accepted_visitor_turns')['coverage']==='partial'&&pap_metric($r,'calls_offered')['coverage']==='partial','changed exact native session proof invalidates saved turn and call lineage');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec("DELETE FROM profile_agent_handoff_operations_pa3 WHERE turn_id=995001 AND operation='reply'");$r=pap_read();
    pap_ok(pap_metric($r,'owner_reply_messages')['coverage']==='partial'&&$r['first_reply_cohorts']['current']['unverifiable']===1&&$r['first_reply_cohorts']['current']['unanswered']===1,'a reply without its exact receipt becomes unverifiable, independently of a genuinely unanswered conversation');
});
pap_case(static function(PDO $pdo):void{
    pap_json_update($pdo,'agent_workflow_receipts','result_json',998001,static function(array $r):array{$r['answer_message_id']=991102;return $r;});$r=pap_read();
    pap_ok(pap_metric($r,'agent_reply_messages','previous')['coverage']==='partial'&&$r['first_reply_cohorts']['previous']['unverifiable']===1,'an Agent receipt cannot be rebound to another conversation\'s answer');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE profile_calls_pa3c SET accepted_at_ms=created_at_ms-1 WHERE id=994001');$r=pap_read();
    pap_ok(pap_metric($r,'median_call_accept_seconds')['coverage']==='partial'&&pap_metric($r,'median_call_accept_seconds')['value']===null,'an acceptance preceding its offer never becomes a negative delay or talk-time claim');
});

foreach([
    ["UPDATE agent_commerce_checkout_attempts_v800 SET amount_cents=1999 WHERE id=993402",'checkout attempt amount mismatch'],
    ["UPDATE agent_commerce_checkout_attempts_v800 SET creation_request_json='{}' WHERE id=993402",'missing frozen provider request'],
    ["UPDATE agent_commerce_payments_v800 SET received_at=NULL WHERE id=992402",'missing canonical payment UTC clock'],
    ["UPDATE agent_commerce_provider_connections_v800 SET owner_user_id=2 WHERE id=992001",'changed merchant ownership'],
    ["UPDATE agent_commerce_order_items_v800 SET metadata_json='{}' WHERE order_id=992201",'mutable order metadata without matching frozen item attribution'],
] as [$change,$label])pap_case(static function(PDO $pdo)use($change,$label):void{
    $pdo->exec($change);$r=pap_read();$p=pap_metric($r,'verified_paid_orders');
    pap_ok($p['coverage']==='partial'&&$p['value']===null&&pap_metric($r,'recorded_product_conversions')['value']===1,$label.' limits verified payments while retaining independently recorded conversions');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec('DELETE FROM agent_commerce_payments_v800 WHERE order_id=992201');$r=pap_read();
    pap_ok(pap_metric($r,'verified_paid_orders')['coverage']==='partial','a mutable paid aggregate without canonical receipt rows cannot certify paid orders');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE agent_commerce_refunds_v800 SET amount_cents=2001 WHERE id=992601');$r=pap_read();
    pap_ok(pap_metric($r,'verified_refunded_orders')['coverage']==='partial'&&pap_metric($r,'verified_paid_orders')['value']===2,'an over-refund fails its exact payment proof without erasing verified historical payment completion');
});
pap_case(static function(PDO $pdo):void{
    pap_json_update($pdo,'agent_commerce_orders_v800','metadata_json',992201,static fn(array $m):array=>['profile_conversion_source'=>'public_profile_booking']);$r=pap_read();
    pap_ok(pap_metric($r,'verified_paid_orders')['coverage']==='partial'&&pap_metric($r,'recorded_booking_conversions')['value']===1,'legacy mutable booking enrichment is not invented as frozen Profile payment origin');
});
pap_ok(vp3_profile_analytics_add_v1('9007199254740993000','2000')==='9007199254740995000'
    &&vp3_profile_analytics_sub_v1('1000','9007199254740993000')==='-9007199254740992000','Commerce integer arithmetic remains exact beyond JavaScript numeric precision');

foreach(['profile_calls_pa3c'=>'calls','agent_commerce_payments_v800'=>'commerce'] as $table=>$group){
    db()->failSql='FROM '.$table;try{$r=pap_read();}finally{db()->failSql='';}
    $key=$group==='calls'?'calls_offered':'verified_paid_orders';
    pap_ok($r['coverage']==='limited'&&$r['groups'][$group]['coverage']===($group==='commerce'?'limited':'unavailable')
        &&pap_metric($r,$key)['coverage']==='unavailable'&&pap_metric($r,$key)['value']===null
        &&count($r['metrics'])===17&&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'SECRET'),'storage failure is explicit unavailable data with the complete response shape: '.$group);
}
pap_case(static function(PDO $pdo):void{
    $pdo->beforeSql=static function(string $sql):void{
        if(str_starts_with($sql,'SELECT * FROM profile_agent_conversations WHERE owner_user_id=')
            ||str_starts_with($sql,'SELECT * FROM agent_commerce_orders_v800 WHERE owner_user_id=')
            ||str_contains($sql,'metadata_json,recorded_at_utc_ms,clock_legacy_token_v1 FROM profile_events'))
            throw new PDOException('SECRET unavailable metric-family fixture');
    };
    $r=pap_read();$pdo->beforeSql=null;
    pap_ok($r['coverage']==='unavailable'&&count(array_filter($r['groups'],static fn(array $g):bool=>$g['coverage']==='unavailable'))===4
        &&$r['backlog']['coverage']==='ready'&&$r['backlog']['metrics'][0]['value']===1,
        'a usable current backlog cannot make four unavailable fixed-period families appear usable');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'");
    pap_denied(fn()=>pap_read(),403,'current revoked Profile grant denies the whole period instead of leaking other usable groups');
    pap_ok($pdo->inTransaction(),'a denied period never ends the caller transaction');
});
pap_case(static function(PDO $pdo):void{
    $pdo->exec('UPDATE chat_conversations SET user_agent_id=NULL WHERE id=11');
    pap_denied(fn()=>pap_read(),403,'exact private Chat and named Agent binding is required for historical analytics');
});
pap_denied(fn()=>pap_read(['ref'=>$fixture['ref'],'period'=>1]),422,'fixed reference requests cannot silently fall back to a newly resolved period');

echo 'PROFILE_ANALYTICS_PERIOD_V1=PASS ('.$checks.' checks; driver='.db()->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
