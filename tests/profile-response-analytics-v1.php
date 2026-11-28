<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/profile-specialist-sources-v1.php';
require_once __DIR__.'/fixtures/profile-analytics-period-data-v1.php';
require_once __DIR__.'/fixtures/profile-response-analytics-data-v1.php';
require_once dirname(__DIR__).'/includes/profile-analytics-period-v1.php';
require_once dirname(__DIR__).'/includes/agent-profile-analytics-summary-v1.php';
pfs_reset();$fixture=profile_analytics_period_fixture_v1(db());$now=new DateTimeImmutable('2026-11-18 12:00:00',new DateTimeZone('UTC'));
$rows=profile_response_analytics_fixture_v1(db(),$fixture,$now);$checks=0;
function pra_ok(bool $ok,string $label): void{if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function pra_read(?DateTimeImmutable $at=null): array{return vp3_profile_analytics_period_v1(db(),$GLOBALS['fixture']['scope'],['ref'=>$GLOBALS['fixture']['ref']],$at??$GLOBALS['now']);}
function pra_metric(array $data,string $key,string $side='current'): array{foreach($data['response_activity']['metrics'] as $row)if($row['key']===$key)return $row[$side];throw new RuntimeException('Missing response metric.');}
function pra_case(callable $work): void{$p=db();$p->beginTransaction();try{$work($p);}finally{$p->beforeSql=$p->afterSql=null;$p->failSql='';if($p->inTransaction())$p->rollBack();}}
function pra_receipt(PDO $p,int $id,callable $edit): void{$q=$p->prepare('SELECT context_json FROM profile_agent_messages WHERE id=?');$q->execute([$id]);$v=json_decode($q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);$p->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([json_encode($edit($v),JSON_THROW_ON_ERROR),$id]);}

$queries=0;db()->beforeSql=static function(string $sql)use(&$queries):void{if(!preg_match('/\A\s*(SELECT|SHOW|PRAGMA|SET TRANSACTION)\b/i',$sql))throw new RuntimeException('Unexpected reporting write');$queries++;};
$baseline=pra_read();db()->beforeSql=null;
pra_ok($queries>30&&$baseline['response_activity']['coverage']==='complete','exact native request reporting is read-only with complete source coverage');
foreach(['owner_requests_submitted'=>7,'knowledge_gap_requests'=>4,'user_response_requests'=>1,'service_unavailable_requests'=>1,'quota_exhausted_requests'=>1,
    'unknown_reason_requests'=>0,'owner_requests_answered'=>3,'owner_response_messages'=>3,'median_owner_response_seconds'=>600,'p90_owner_response_seconds'=>86400] as $key=>$value)
    pra_ok(pra_metric($baseline,$key)['value']===$value,'recorded period measure: '.$key);
pra_ok(pra_metric($baseline,'owner_requests_submitted','previous')['value']===2&&pra_metric($baseline,'owner_requests_answered','previous')['value']===1,'earlier submissions and replies retain their own period');
$backlog=$baseline['backlog']['owner_requests'];
pra_ok($backlog['waiting_count']===5&&$backlog['delegated_count']===1&&$backlog['unknown_count']===0,'waiting and delegated current requests are distinct');
pra_ok($backlog['questions'][0]['count']===3&&$baseline['response_activity']['current']['questions'][0]['count']===2,'repeated gap wording is grouped without treating service or quota failures as knowledge gaps');
pra_ok($backlog['questions'][0]['suggestions'][0]['knowledge_id']===1289101&&!str_contains(json_encode($baseline,JSON_THROW_ON_ERROR),'FOREIGN_PRIVATE'),'only a currently permitted existing owner note is suggested');
pra_ok(!str_contains(json_encode($baseline['response_activity'],JSON_THROW_ON_ERROR),'suggestions')&&!str_contains(json_encode($baseline['response_activity'],JSON_THROW_ON_ERROR),'1289101'),'mutable note suggestions are absent from completed-period evidence');
pra_ok($backlog['oldest_wait_seconds']===($now->getTimestamp()*1000-$rows['gap_a']['created_at_ms'])/1000,'current wait age uses the saved submission clock');
pra_ok(pra_read($now->modify('+1 hour'))['revision']===$baseline['revision'],'time passing changes wait ages without rewriting the fixed period');
$source=vp3_profile_analytics_source_item_v1($baseline,7,11);
pra_ok(str_contains(json_encode($source['profile_report'],JSON_THROW_ON_ERROR),'Repeated knowledge-gap wording')&&str_contains($source['summary'],'submitted questions answered'),'existing specialist report and Top of Mind source carry the new proved findings');

pra_case(static function(PDO $p)use($baseline):void{
    $p->exec('UPDATE user_profiles SET is_public=0,profile_agent_enabled=0 WHERE user_id=1');
    pra_ok(pra_read()['revision']===$baseline['revision'],'private historical response reports survive public Profile disablement');
});
pra_case(static function(PDO $p)use($baseline):void{
    $p->exec("UPDATE user_data_policies SET profile_agent_allowed=0,audience_scope='private' WHERE owner_user_id=1 AND resource_type='knowledge'");$r=pra_read();
    pra_ok($r['revision']===$baseline['revision']&&$r['backlog']['owner_requests']['questions'][0]['suggestions'][0]['knowledge_id']===1289101,'owner note review follows existing private Chat permission without granting public Profile access');
});
pra_case(static function(PDO $p)use($baseline):void{
    $p->exec("UPDATE user_data_policies SET owner_agents_allowed=0 WHERE owner_user_id=1 AND resource_type='knowledge'");$r=pra_read();
    pra_ok($r['backlog']['owner_requests']['questions'][0]['suggestions']===[]&&$r['revision']===$baseline['revision'],'revoked owner-Agent Knowledge access removes suggestions only, preserving fixed facts');
});
pra_case(static function(PDO $p):void{
    $p->exec("INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode) VALUES(7,'knowledge','1289101','deny')");
    pra_ok(pra_read()['backlog']['owner_requests']['questions'][0]['suggestions']===[],'current exact note deny retains canonical precedence');
});
pra_case(static function(PDO $p):void{
    $p->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='personal_knowledge.access'");$r=pra_read();
    pra_ok($r['backlog']['owner_requests']['knowledge']['coverage']==='unavailable'&&!str_contains(json_encode($r['backlog']['owner_requests'],JSON_THROW_ON_ERROR),'1289101'),'personal Knowledge revocation withholds note metadata while retaining owner request reporting');
});
pra_case(static function(PDO $p):void{
    $p->exec('UPDATE knowledge_items SET created_by_user_id=4 WHERE id=1289101');
    pra_ok(pra_read()['backlog']['owner_requests']['questions'][0]['suggestions']===[],'changed note ownership cannot retain a suggestion');
});
pra_case(static function(PDO $p)use($rows,$baseline):void{
    $r=$rows['gap_a'];$p->prepare("UPDATE profile_agent_owner_requests_v1 SET status='resolved',revision=2,updated_at_ms=?,settled_at_ms=? WHERE id=?")
        ->execute([$GLOBALS['now']->getTimestamp()*1000,$GLOBALS['now']->getTimestamp()*1000,$r['id']]);$v=pra_read();
    pra_ok($v['revision']===$baseline['revision']&&$v['backlog']['owner_requests']['waiting_count']===4,'resolving a current question does not rewrite its completed submission period');
});
pra_case(static function(PDO $p)use($rows,$baseline):void{
    $p->prepare("UPDATE profile_agent_handoff_turns_pa3 SET state='replied' WHERE id=?")->execute([$rows['delegated']['turn_id']]);$v=pra_read();
    pra_ok($v['backlog']['owner_requests']['delegated_count']===0&&$v['revision']===$baseline['revision'],
        'a delegated turn already answered by the Agent is not a current unanswered backlog item');
});
pra_case(static function(PDO $p)use($rows):void{
    $p->prepare("UPDATE profile_agent_conversations SET status='resolved' WHERE id=?")->execute([$rows['delegated']['conversation_id']]);
    pra_ok(pra_read()['backlog']['owner_requests']['delegated_count']===0,'a resolved delegated conversation is no longer waiting for an Agent reply');
});
pra_case(static function(PDO $p)use($rows):void{
    $r=$rows['gap_a'];pra_receipt($p,$r['receipt_message_id'],static function(array $ctx):array{unset($ctx['reason'],$ctx['submitted_at_ms']);return $ctx;});
    pra_ok(pra_metric(pra_read(),'knowledge_gap_requests')['value']===4,'an unreopened legacy request retains its provable original reason');
});
pra_case(static function(PDO $p)use($rows):void{
    $r=$rows['gap_a'];pra_receipt($p,$r['receipt_message_id'],static function(array $ctx):array{unset($ctx['reason'],$ctx['submitted_at_ms']);return $ctx;});
    $mid=$r['receipt_message_id']+5;$stamp=$GLOBALS['now']->getTimestamp()*1000-60000;
    profile_analytics_period_fixture_row_v1($p,'profile_agent_messages',['id'=>$mid,'conversation_id'=>$r['conversation_id'],'sender_type'=>'receipt','message'=>'Waiting again.','sender_user_id'=>null,
        'context_json'=>json_encode(['contract'=>VP3_PROFILE_RESPONSE_V1,'request_id'=>$r['id'],'visitor_message_id'=>$r['visitor_message_id'],'revision'=>3,'reason'=>'knowledge_gap','submitted_at_ms'=>$stamp],JSON_THROW_ON_ERROR)]);
    $p->prepare("UPDATE profile_agent_owner_requests_v1 SET revision=3,receipt_message_id=?,reason='knowledge_gap',updated_at_ms=? WHERE id=?")->execute([$mid,$stamp,$r['id']]);$v=pra_read();
    pra_ok(pra_metric($v,'unknown_reason_requests')['value']===1&&pra_metric($v,'knowledge_gap_requests')['observed_value']===3,'reopened legacy requests have an unknown original reason, never a fabricated historical gap');
    $current=array_values(array_filter($v['backlog']['owner_requests']['oldest'],static fn(array $row):bool=>$row['request_id']===$r['id']))[0];
    pra_ok($current['wait_seconds']===60&&$current['first_submitted_at_utc_ms']===$r['created_at_ms'],'reopening restarts current wait while retaining first submission provenance');
});
pra_case(static function(PDO $p)use($rows,$baseline):void{
    $r=$rows['gap_a'];$mid=$r['receipt_message_id']+5;$stamp=$GLOBALS['now']->getTimestamp()*1000-60000;
    profile_analytics_period_fixture_row_v1($p,'profile_agent_messages',['id'=>$mid,'conversation_id'=>$r['conversation_id'],'sender_type'=>'receipt','message'=>'Waiting again.','sender_user_id'=>null,
        'context_json'=>json_encode(['contract'=>VP3_PROFILE_RESPONSE_V1,'request_id'=>$r['id'],'visitor_message_id'=>$r['visitor_message_id'],'revision'=>3,'reason'=>'service_unavailable','submitted_at_ms'=>$stamp],JSON_THROW_ON_ERROR)]);
    $p->prepare("UPDATE profile_agent_owner_requests_v1 SET revision=3,receipt_message_id=?,reason='service_unavailable',updated_at_ms=? WHERE id=?")->execute([$mid,$stamp,$r['id']]);$v=pra_read();
    pra_ok($v['revision']===$baseline['revision']&&$v['backlog']['owner_requests']['questions'][0]['count']===2,'new receipt evidence preserves the historical gap when a re-submission has a different live reason');
});
pra_case(static function(PDO $p)use($rows,$fixture):void{
    // The later exact owner reply can settle several earlier waiting questions
    // in its conversation. Count questions and reply messages separately.
    $a=$rows['gap_a'];$b=$rows['answered'];
    $p->prepare('UPDATE profile_agent_owner_requests_v1 SET conversation_id=?,status=\'answered\',revision=2,reply_message_id=?,updated_at_ms=?,settled_at_ms=? WHERE id=?')
        ->execute([$b['conversation_id'],$b['reply_message_id'],$b['created_at_ms']+300000,$b['created_at_ms']+300000,$a['id']]);
    $p->prepare('UPDATE profile_agent_messages SET conversation_id=? WHERE id IN (?,?)')->execute([$b['conversation_id'],$a['visitor_message_id'],$a['receipt_message_id']]);
    $p->prepare('UPDATE profile_agent_handoff_turns_pa3 SET conversation_id=? WHERE id=?')->execute([$b['conversation_id'],$a['turn_id']]);$v=pra_read();
    pra_ok(pra_metric($v,'owner_requests_answered')['value']===4&&pra_metric($v,'owner_response_messages')['value']===3,'one exact owner reply answering several questions counts each request once and the reply once');
});
foreach(['owner_user_id'=>4,'profile_agent_id'=>999,'visitor_message_id'=>999,'receipt_message_id'=>999] as $field=>$value)pra_case(static function(PDO $p)use($field,$value,$rows):void{
    $p->prepare('UPDATE profile_agent_owner_requests_v1 SET '.$field.'=? WHERE id=?')->execute([$value,$rows['gap_a']['id']]);$v=pra_read();
    pra_ok($field==='owner_user_id'?pra_metric($v,'owner_requests_submitted')['value']===6:pra_metric($v,'owner_requests_submitted')['coverage']==='partial','foreign or broken request binding is excluded: '.$field);
});
pra_case(static function(PDO $p)use($rows):void{
    $p->prepare('UPDATE profile_agent_messages SET message=? WHERE id=?')->execute(['TAMPERED_UNACCEPTED_QUESTION',$rows['gap_a']['visitor_message_id']]);$v=pra_read();
    pra_ok($v['backlog']['owner_requests']['coverage']==='partial'&&!str_contains(json_encode($v,JSON_THROW_ON_ERROR),'TAMPERED_UNACCEPTED_QUESTION'),'changed visitor text cannot keep its accepted source proof or leak into excerpts');
});
pra_case(static function(PDO $p)use($rows):void{
    $p->prepare("UPDATE profile_agent_messages SET context_json='{}' WHERE id=?")->execute([$rows['answered']['reply_message_id']]);$v=pra_read();
    pra_ok(pra_metric($v,'owner_requests_answered')['coverage']==='partial'&&pra_metric($v,'owner_requests_answered')['observed_value']===2&&pra_metric($v,'median_owner_response_seconds')['value']===null,'a reply without its operation proof is unknown and cannot bias a complete response-time result');
});
pra_case(static function(PDO $p)use($rows):void{
    $p->prepare('UPDATE profile_agent_handoff_operations_pa3 SET created_at_ms=? WHERE message_id=?')->execute([$rows['answered']['created_at_ms']-1,$rows['answered']['reply_message_id']]);
    pra_ok(pra_metric(pra_read(),'median_owner_response_seconds')['coverage']==='partial','an impossible owner reply time never becomes a negative response delay');
});
pra_case(static function(PDO $p):void{
    $p->failSql='FROM profile_agent_owner_requests_v1 WHERE';$v=pra_read();$p->failSql='';
    pra_ok($v['response_activity']['coverage']==='unavailable'&&pra_metric($v,'owner_requests_submitted')['value']===null&&!str_contains(json_encode($v,JSON_THROW_ON_ERROR),'SECRET'),'request storage failure is unavailable, not a false zero or raw SQL leak');
});
pra_case(static function(PDO $p)use($baseline):void{
    $p->failSql='FROM knowledge_items WHERE';$v=pra_read();$p->failSql='';
    pra_ok($v['revision']===$baseline['revision']&&$v['backlog']['owner_requests']['knowledge']['coverage']==='unavailable','optional note lookup failure preserves verified response report facts');
});
// A policy changed after the aggregate transaction closes must not retain a
// stale private title in the final read. Use the current-authority SQL boundary.
$authorityReads=0;db()->beforeSql=static function(string $sql,PDO $p)use(&$authorityReads):void{
    if(!str_starts_with($sql,'SELECT * FROM users WHERE id=?'))return;
    if(++$authorityReads!==4)return;$p->beforeSql=null;
    $p->exec("UPDATE user_data_policies SET owner_agents_allowed=0 WHERE owner_user_id=1 AND resource_type='knowledge'");
};
$changed=pra_read();db()->beforeSql=null;
pra_ok($authorityReads===4&&$changed['backlog']['owner_requests']['questions'][0]['suggestions']===[],'a final current note-policy revocation removes suggestions from the delivered report');
db()->exec("UPDATE user_data_policies SET owner_agents_allowed=1 WHERE owner_user_id=1 AND resource_type='knowledge'");
pra_ok(pra_read()['revision']===$baseline['revision'],'restoring exact records restores the completed report revision');
// The real native admission owns its transaction. Run it after reversible
// historical cases rather than wrapping it in a fixture-only outer transaction.
$pdo->beginTransaction();vp3_profile_response_settings_save_v1($pdo,1,'user_responses',0);$pdo->commit();
$accepted=pa3_admit('A new canonical owner-response reporting question',0,bin2hex(random_bytes(16)),0,'f');
$q=$pdo->prepare('SELECT r.*,m.context_json FROM profile_agent_owner_requests_v1 r JOIN profile_agent_messages m ON m.id=r.receipt_message_id WHERE r.conversation_id=?');
$q->execute([(int)$accepted['conversation_id']]);$request=$q->fetch(PDO::FETCH_ASSOC);$ctx=json_decode($request['context_json'],true,64,JSON_THROW_ON_ERROR);
pra_ok($ctx['reason']==='user_responses'&&$ctx['submitted_at_ms']===(int)$request['created_at_ms']&&$ctx['request_id']===(int)$request['id'],
    'the real native submission writer persists immutable reason and application UTC submission evidence');
$at=(int)$request['created_at_ms'];$end=(int)(floor($at/86400000)+1)*86400;$ref=vp3_profile_analytics_period_encode_v1(1,$end,'UTC','e');
$r=vp3_profile_analytics_period_v1($pdo,$fixture['scope'],['ref'=>$ref],new DateTimeImmutable('@'.($end+86400)));
pra_ok(pra_metric($r,'user_response_requests')['value']===1&&$r['backlog']['owner_requests']['waiting_count']===6,
    'a real native owner request is counted without running an automated answer or requiring seeded request metadata');
echo 'PROFILE_RESPONSE_ANALYTICS_V1=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
