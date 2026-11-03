<?php
declare(strict_types=1);
require __DIR__.'/fixtures/commerce-recovery-pa2.php';

// Two real MySQL processes exercise webhook and financial unique-key races.
if (($argv[1]??'')==='worker') {
    $request=json_decode((string)file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    $pdo=commerce_fixture_connect_pa2($request['config']);
    $GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2([$request['fixture']]);
    echo "READY\n";flush();fgets(STDIN);
    try {
        agent_commerce_process_webhook_v800($pdo,'stripe',$request['event']['payload'],$request['event']['headers']);
        echo json_encode(['ok'=>true]);
    } catch (Throwable $error) {echo json_encode(['ok'=>false,'code'=>$error->getCode(),'error'=>$error->getMessage()]);}
    exit;
}

$fixtureConfig=commerce_fixture_config_pa2();$pdo=commerce_fixture_connect_pa2($fixtureConfig);$checks=0;
register_shutdown_function(static fn()=>commerce_fixture_cleanup_pa2($fixtureConfig));
function verify_pa2(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label."\n";
}
function rejects_pa2(callable $callback,string $label): Throwable
{
    try {$callback();} catch (Throwable $error) {verify_pa2(true,$label);return $error;}
    throw new RuntimeException($label.' unexpectedly succeeded');
}
function payments_pa2(array $f): array {return commerce_fixture_rows_pa2(db(),'agent_commerce_payments_v800','order_id='.(int)$f['id']);}
function jobs_pa2(array $f): array {return commerce_fixture_rows_pa2(db(),'agent_commerce_fulfillment_jobs_pa2','order_id='.(int)$f['id']." AND event_type<>'project_paid'");}
function proof_pa2(array $f): array
{
    $GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2([$f]);
    return agent_commerce_read_attempt_pa2(db(),agent_commerce_order_v800(db(),$f['id']),agent_commerce_checkout_attempt_v800(db(),$f['attempt_id']));
}
function pay_pa2(array $f,?array $proof=null): array
{
    $proof??=proof_pa2($f);
    return agent_commerce_mark_paid_v800(db(),$f['id'],$f['provider'],$f['session'],$f['payment'],(int)($f['amount']??5000),(string)($f['currency']??'usd'),$proof);
}
function deliver_pa2(array $event): void {agent_commerce_process_webhook_v800(db(),'stripe',$event['payload'],$event['headers']);}

commerce_fixture_schema_pa2($pdo);
verify_pa2(agent_commerce_schema_ready_v800($pdo)&&agent_commerce_recovery_schema_ready_pa2($pdo),'canonical install creates all recovery storage');
commerce_fixture_schema_pa2($pdo);
verify_pa2(agent_commerce_recovery_schema_ready_pa2($pdo),'canonical schema upgrade is idempotent');
$pdo->beginTransaction();rejects_pa2(fn()=>agent_commerce_recovery_ensure_schema_pa2($pdo),'schema upgrade rejects an active financial transaction');$pdo->rollBack();

// Existing installs receive immutable snapshots only when old rows prove them.
$legacyStripe=commerce_fixture_order_pa2($pdo);
$legacySquare=commerce_fixture_order_pa2($pdo,['provider_snapshot'=>'square'],['external_payment_id'=>'legacy_square_order']);
$ambiguousSquare=commerce_fixture_order_pa2($pdo,['provider_snapshot'=>'square'],['external_payment_id'=>'legacy_square_payment','status'=>'completed']);
$pdo->exec('DROP TABLE agent_commerce_fulfillment_jobs_pa2');
foreach ([
    'agent_commerce_checkout_attempts_v800'=>['provider_order_id','provider_account_id','provider_location_id','creation_lease_token','creation_lease_expires_at','creation_attempt_count','creation_request_json','last_error_class'],
    'agent_commerce_webhook_events_v800'=>['recovery_json','lease_token','lease_expires_at','attempt_count','next_attempt_at','last_error_class'],
] as $table=>$columns) foreach ($columns as $column) $pdo->exec('ALTER TABLE '.$table.' DROP COLUMN '.$column);
verify_pa2(!agent_commerce_schema_ready_v800($pdo),'old Commerce storage cannot be declared payment-ready');
$pdo->failSql='SET provider_order_id=';
rejects_pa2(fn()=>agent_commerce_recovery_ensure_schema_pa2($pdo),'interrupted legacy backfill remains an unsuccessful upgrade');$pdo->failSql='';
verify_pa2(!agent_commerce_recovery_schema_ready_pa2($pdo),'partial migration cannot enable payment processing');
agent_commerce_recovery_ensure_schema_pa2($pdo);
$migratedStripe=agent_commerce_checkout_attempt_v800($pdo,$legacyStripe['attempt_id']);
$migratedSquare=agent_commerce_checkout_attempt_v800($pdo,$legacySquare['attempt_id']);
$unprovable=agent_commerce_checkout_attempt_v800($pdo,$ambiguousSquare['attempt_id']);
verify_pa2(agent_commerce_schema_ready_v800($pdo)&&$migratedStripe['provider_order_id']===$legacyStripe['session']&&$migratedStripe['provider_account_id']===$legacyStripe['account'],'retry completes the upgrade and retains provable Stripe lineage');
verify_pa2($migratedSquare['provider_order_id']==='legacy_square_order'&&$migratedSquare['external_payment_id']===''&&$migratedSquare['provider_location_id']!=='','legacy open Square order identity is separated from a future payment ID');
verify_pa2($unprovable['provider_order_id']===''&&$unprovable['external_payment_id']==='legacy_square_payment','ambiguous completed legacy Square identity is never invented');
$pdo->failSql='SET provider_order_id=';agent_commerce_recovery_ensure_schema_pa2($pdo);$pdo->failSql='';
verify_pa2(true,'completed upgrade does not rescan or rewrite historical attempts');

// Signature admission happens before any event/receipt can be saved.
$valid=commerce_fixture_order_pa2($pdo);$event=commerce_fixture_stripe_pa2($valid);
$before=count(commerce_fixture_rows_pa2($pdo,'agent_commerce_webhook_events_v800'));
foreach (['bad','t=1,v1='.str_repeat('0',64),''] as $signature) {
    rejects_pa2(fn()=>agent_commerce_process_webhook_v800($pdo,'stripe',$event['payload'],['stripe-signature'=>$signature]),'invalid or stale Stripe signature is rejected');
}
rejects_pa2(fn()=>agent_commerce_process_webhook_v800($pdo,'stripe','{invalid}',[]),'malformed JSON is rejected');
verify_pa2(count(commerce_fixture_rows_pa2($pdo,'agent_commerce_webhook_events_v800'))===$before&&payments_pa2($valid)===[],'rejected requests create no event claim or financial receipt');

// Failure is retryable rather than permanently consumed by the first insert.
$GLOBALS['pa2_http']=static function(){throw new RuntimeException('SECRET temporary provider diagnostic');};
rejects_pa2(fn()=>deliver_pa2($event),'temporary provider failure is unsuccessful');
$rows=commerce_fixture_rows_pa2($pdo,'agent_commerce_webhook_events_v800');$failed=end($rows);
verify_pa2($failed['status']==='failed'&&$failed['last_error_class']!==''&&!str_contains((string)$failed['error_message'],'SECRET'),'failed event retains a fixed, safe recovery reason');
verify_pa2(payments_pa2($valid)===[]&&jobs_pa2($valid)===[],'provider failure creates neither payment nor fulfillment');
$GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2([$valid]);deliver_pa2($event);
verify_pa2(count(payments_pa2($valid))===1&&(int)agent_commerce_order_v800($pdo,$valid['id'])['amount_paid_cents']===5000,'same authenticated event succeeds after a transient failure');
verify_pa2(count(jobs_pa2($valid))===1&&jobs_pa2($valid)[0]['status']==='manual','manual fulfillment remains seller work, with a durable obligation');
$calls=count($GLOBALS['pa2_http_calls']);deliver_pa2($event);
verify_pa2(count(payments_pa2($valid))===1&&count($GLOBALS['pa2_http_calls'])===$calls,'processed event replay has one receipt and performs no provider call');
deliver_pa2(commerce_fixture_stripe_pa2($valid,[],'evt_duplicate_receipt','checkout.session.async_payment_succeeded'));
verify_pa2(count(payments_pa2($valid))===1&&count(jobs_pa2($valid))===1,'different authenticated event for the same exact payment is harmless');
$conflicting=$event['event'];$conflicting['account']='another_account';$raw=json_encode($conflicting);$ts=time();
rejects_pa2(fn()=>agent_commerce_process_webhook_v800($pdo,'stripe',$raw,['stripe-signature'=>'t='.$ts.',v1='.hash_hmac('sha256',$ts.'.'.$raw,'fixture-stripe-webhook')]),'event ID cannot be reused for another merchant account');

// A genuine event arriving before the checkout response is stored remains recoverable.
$early=commerce_fixture_order_pa2($pdo);$earlyEvent=commerce_fixture_stripe_pa2($early);
$pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET external_session_id=?,provider_order_id='',status='creating' WHERE id=?")->execute(['pending:early',$early['attempt_id']]);
$GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2([$early]);deliver_pa2($earlyEvent);
$row=commerce_fixture_rows_pa2($pdo,'agent_commerce_webhook_events_v800',"external_event_id='".$earlyEvent['event']['id']."'")[0];
verify_pa2($row['status']==='pending'&&$row['last_error_class']==='checkout_not_recorded'&&payments_pa2($early)===[],'early paid event is durable pending evidence, not a consumed success');
$pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET external_session_id=?,provider_order_id=?,status='open' WHERE id=?")->execute([$early['session'],$early['session'],$early['attempt_id']]);
$pdo->prepare('UPDATE agent_commerce_webhook_events_v800 SET next_attempt_at=? WHERE id=?')->execute(['2000-01-01 00:00:00',$row['id']]);
$recovery=agent_commerce_recovery_housekeeping_pa2($pdo);
verify_pa2(count(payments_pa2($early))===1&&$recovery['webhooks_processed']>=1,'housekeeping recovers early delivery without another provider webhook');

// Competing workers and an expired claimant cannot finish each other's receipt.
$fenced=commerce_fixture_order_pa2($pdo);$fencedEvent=commerce_fixture_stripe_pa2($fenced);
$target=agent_commerce_provider_event_target_pa2('stripe',$fencedEvent['event']);$hash=hash('sha256',$fencedEvent['payload']);
$claim=agent_commerce_webhook_claim_pa2($pdo,'stripe',$target['event_id'],$target['event_type'],$hash,$target);
$busy=agent_commerce_webhook_claim_pa2($pdo,'stripe',$target['event_id'],$target['event_type'],$hash,$target);
verify_pa2($claim['state']==='claimed'&&$busy['state']==='busy','a live webhook lease admits only one worker');
$pdo->prepare('UPDATE agent_commerce_webhook_events_v800 SET lease_expires_at=? WHERE id=?')->execute(['2000-01-01 00:00:00',$claim['row']['id']]);
$successor=agent_commerce_webhook_claim_pa2($pdo,'stripe',$target['event_id'],$target['event_type'],$hash,$target);
verify_pa2($successor['state']==='claimed'&&$successor['token']!==$claim['token'],'abandoned webhook is reclaimed with a fresh token');
verify_pa2(!agent_commerce_webhook_finish_pa2($pdo,$claim,'processed'),'stale worker cannot finish its successor’s claim');
$GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2([$fenced]);
rejects_pa2(fn()=>agent_commerce_webhook_process_claim_pa2($pdo,$claim),'stale worker cannot save a payment after provider verification');
verify_pa2(payments_pa2($fenced)===[],'stale completion creates no money or obligation');
agent_commerce_webhook_process_claim_pa2($pdo,$successor);
verify_pa2(count(payments_pa2($fenced))===1,'current lease completes exactly one payment');

// Stored payment lineage is checked before considering a duplicate successful.
$foreign=commerce_fixture_order_pa2($pdo);$foreignProof=proof_pa2($foreign);
rejects_pa2(fn()=>agent_commerce_mark_paid_v800($pdo,$foreign['id'],'stripe',$foreign['session'],$foreign['payment'],5000,'usd'),'bare payment arguments without authenticated evidence cannot settle an order');
rejects_pa2(fn()=>agent_commerce_mark_paid_v800($pdo,$foreign['id'],'stripe',$foreign['session'],$valid['payment'],5000,'usd',$foreignProof),'a recorded provider payment cannot settle a different local order');
verify_pa2(payments_pa2($foreign)===[]&&jobs_pa2($foreign)===[],'duplicate identity mismatch leaves the requested order unpaid');
foreach ([[4999,'usd','stripe'],[5001,'usd','stripe'],[5000,'eur','stripe'],[5000,'usd','square']] as [$amount,$currency,$provider]) {
    rejects_pa2(fn()=>agent_commerce_mark_paid_v800($pdo,$foreign['id'],$provider,$foreign['session'],$foreign['payment'],$amount,$currency,$foreignProof),'exact original amount, currency and provider are required');
}
verify_pa2(payments_pa2($foreign)===[],'invalid evidence cannot partially record a payment');

// Deposit replays use original attempt terms even after the remaining balance changes.
$deposit=commerce_fixture_order_pa2($pdo,['payment_mode'=>'deposit','amount_due_cents'=>2000]);$deposit['amount']=2000;
$first=pay_pa2($deposit);$firstDue=(int)$first['amount_due_cents'];
verify_pa2($first['payment_status']==='partially_paid'&&(int)$first['amount_paid_cents']===2000,'verified deposit records only the amount received');
$pdo->prepare('UPDATE agent_commerce_orders_v800 SET amount_due_cents=3000 WHERE id=?')->execute([$deposit['id']]);
pay_pa2($deposit);
verify_pa2(count(payments_pa2($deposit))===1&&(int)agent_commerce_order_v800($pdo,$deposit['id'])['amount_due_cents']===3000,'deposit replay cannot consume or reset a later balance');

// Payment and fulfillment obligation commit or roll back as one unit.
$atomic=commerce_fixture_order_pa2($pdo,[],[],['fulfillment_type'=>'appointment']);$atomicProof=proof_pa2($atomic);
$pdo->failSql='INTO agent_commerce_fulfillment_jobs_pa2';
rejects_pa2(fn()=>pay_pa2($atomic,$atomicProof),'failure to save the fulfillment obligation fails the payment transition');$pdo->failSql='';
verify_pa2(payments_pa2($atomic)===[]&&jobs_pa2($atomic)===[]&&(int)agent_commerce_order_v800($pdo,$atomic['id'])['amount_paid_cents']===0,'owning transaction rolls back financial and order state on obligation failure');
$pdo->beginTransaction();pay_pa2($atomic,$atomicProof);
verify_pa2($pdo->inTransaction()&&count(payments_pa2($atomic))===1&&count(jobs_pa2($atomic))===1,'caller-owned transaction contains both payment and obligation before commit');
rejects_pa2(fn()=>agent_commerce_fulfillment_run_pa2($pdo),'fulfillment cannot execute inside the caller’s uncommitted transaction');$pdo->rollBack();
verify_pa2(payments_pa2($atomic)===[]&&jobs_pa2($atomic)===[],'caller rollback removes both records');
$pdo->beginTransaction();$pdo->failSql='INTO agent_commerce_fulfillment_jobs_pa2';
rejects_pa2(fn()=>pay_pa2($atomic,$atomicProof),'obligation failure inside a caller transaction is unsuccessful');$pdo->failSql='';
if ($pdo->inTransaction()) $pdo->commit();
verify_pa2(payments_pa2($atomic)===[]&&jobs_pa2($atomic)===[],'catching a nested payment failure cannot commit an orphaned receipt');
pay_pa2($atomic,$atomicProof);
verify_pa2(count(payments_pa2($atomic))===1&&jobs_pa2($atomic)[0]['status']==='pending','committed payment survives a process exit with durable pending fulfillment');

// Fulfillment runs real transactions; a mid-adapter failure rolls its domain write back.
$pdo->exec('CREATE TABLE fixture_fulfillment_transitions (id INTEGER PRIMARY KEY, hits INTEGER NOT NULL)');
$dispatch=static function(PDO $db,array $order,array $item,string $event) use ($atomic): void {
    if ((int)$order['id']!==$atomic['id']) throw new RuntimeException('Unexpected fulfillment target.');
    $db->prepare('INSERT INTO fixture_fulfillment_transitions(id,hits) VALUES(?,1)')->execute([(int)$item['id']]);
    $db->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='confirmed' WHERE id=?")->execute([(int)$item['id']]);
    if ($GLOBALS['pa2_dispatch_fail']??false) throw new RuntimeException('SECRET simulated interruption after a domain write');
};
$GLOBALS['pa2_dispatch_fail']=true;$run=agent_commerce_fulfillment_run_pa2($pdo,100,$dispatch);
verify_pa2($run['retry']===1&&jobs_pa2($atomic)[0]['status']==='retry','adapter interruption retains a durable retry with bounded backoff');
verify_pa2((int)$pdo->query('SELECT COUNT(*) FROM fixture_fulfillment_transitions')->fetchColumn()===0&&count(payments_pa2($atomic))===1,'failed fulfillment rolls back domain work while keeping verified money');
verify_pa2(!str_contains(json_encode(jobs_pa2($atomic)),'SECRET'),'fulfillment diagnostics do not persist private exception messages');
$job=jobs_pa2($atomic)[0];$pdo->prepare('UPDATE agent_commerce_fulfillment_jobs_pa2 SET next_attempt_at=? WHERE id=?')->execute(['2000-01-01 00:00:00',$job['id']]);
$GLOBALS['pa2_dispatch_fail']=false;$run=agent_commerce_fulfillment_run_pa2($pdo,100,$dispatch);
verify_pa2($run['completed']===1&&jobs_pa2($atomic)[0]['status']==='completed','retry commits the domain change and job completion together');
agent_commerce_fulfillment_run_pa2($pdo,100,$dispatch);
verify_pa2((int)$pdo->query('SELECT COUNT(*) FROM fixture_fulfillment_transitions')->fetchColumn()===1,'repeated housekeeping cannot dispatch an already completed fulfillment');

// Late provider success is a financial fact, not permission to recreate an expired booking.
foreach (['expired','cancelled'] as $oldState) {
    $late=commerce_fixture_order_pa2($pdo,['payment_status'=>$oldState,'order_status'=>$oldState,'hold_expires_at'=>null,'cancelled_at'=>'2020-01-01 00:00:00'],['status'=>$oldState],['fulfillment_type'=>'appointment','fulfillment_status'=>'cancelled']);
    $paid=pay_pa2($late);
    verify_pa2($paid['payment_status']==='paid'&&$paid['order_status']==='payment_review'&&(int)$paid['amount_paid_cents']===5000,'late '.$oldState.' payment records money and requires review');
    verify_pa2(jobs_pa2($late)[0]['status']==='review_required','late '.$oldState.' payment never schedules automatic booking fulfillment');
    pay_pa2($late);verify_pa2(count(payments_pa2($late))===1&&count(jobs_pa2($late))===1,'late '.$oldState.' duplicate stays a single receipt and review');
}
$elapsed=commerce_fixture_order_pa2($pdo,['hold_expires_at'=>'2000-01-01 00:00:00'],[],['fulfillment_type'=>'appointment']);
$elapsedPaid=pay_pa2($elapsed);
verify_pa2($elapsedPaid['payment_status']==='paid'&&$elapsedPaid['order_status']==='payment_review'&&jobs_pa2($elapsed)[0]['status']==='review_required','elapsed hold requires review even before an expiry worker has run');

if ($fixtureConfig['driver']==='mysql') {
    // Both workers are started before either is allowed past its barrier.
    foreach ([true,false] as $sameEvent) {
        $race=commerce_fixture_order_pa2($pdo);$firstEvent=commerce_fixture_stripe_pa2($race);$secondEvent=$sameEvent?$firstEvent:commerce_fixture_stripe_pa2($race);
        $workers=[];
        try {
            foreach ([$firstEvent,$secondEvent] as $workerEvent) {
                $file=tempnam(sys_get_temp_dir(),'vp3-pa2-race-');
                file_put_contents($file,json_encode(['config'=>$fixtureConfig,'fixture'=>$race,'event'=>$workerEvent],JSON_THROW_ON_ERROR));
                $proc=proc_open([PHP_BINARY,__FILE__,'worker',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if (!is_resource($proc)) throw new RuntimeException('Cannot start concurrency fixture.');
                stream_set_timeout($pipes[1],20);$workers[]=compact('proc','pipes','file');
                verify_pa2(trim((string)fgets($pipes[1]))==='READY','independent MySQL worker reaches barrier');
            }
            foreach ($workers as $worker) {fwrite($worker['pipes'][0],"GO\n");fclose($worker['pipes'][0]);}
            foreach ($workers as $worker) {
                $output=stream_get_contents($worker['pipes'][1]);$errors=stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);$exit=proc_close($worker['proc']);
                verify_pa2($exit===0&&$errors===''&&is_array(json_decode($output,true)),'concurrent webhook worker exits cleanly');
            }
            $GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2([$race]);deliver_pa2($firstEvent);deliver_pa2($secondEvent);
            verify_pa2(count(payments_pa2($race))===1&&count(jobs_pa2($race))===1,($sameEvent?'same':'different').' event race stores exactly one receipt and obligation');
        } finally {
            foreach ($workers as $worker) {if (is_resource($worker['proc'])) proc_terminate($worker['proc']);@unlink($worker['file']);}
        }
    }
}
echo 'COMMERCE_RECOVERY_PA2=PASS driver='.$fixtureConfig['driver'].' checks='.$checks."\n";
