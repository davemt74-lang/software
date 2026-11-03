<?php
declare(strict_types=1);
require __DIR__.'/fixtures/commerce-recovery-pa2.php';
$fixtureConfig=commerce_fixture_config_pa2();$pdo=commerce_fixture_connect_pa2($fixtureConfig);$checks=0;
register_shutdown_function(static fn()=>commerce_fixture_cleanup_pa2($fixtureConfig));
commerce_fixture_schema_pa2($pdo);
$pdo->exec('CREATE TABLE fixture_commerce_projection (id INTEGER PRIMARY KEY, projected INTEGER NOT NULL)');
$projectionCalls=0;$failProjection=false;
// This domain boundary deliberately fails after its idempotent local write.
// Financial receipt, durable job, claim and recovery remain production code.
function profile_conversion_commerce_order_v179(PDO $pdo,array $order): void
{
    if($pdo->inTransaction())throw new RuntimeException('Projection executed before payment commit.');
    $GLOBALS['projectionCalls']++;
    $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO fixture_commerce_projection(id,projected) VALUES(?,1)')->execute([(int)$order['id']]);
    if($GLOBALS['failProjection'])throw new RuntimeException('SECRET projection interruption');
}
function projection_check_pa2(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label."\n";}
function projection_pay_pa2(array $f): void
{
    $proof=agent_commerce_read_attempt_pa2(db(),agent_commerce_order_v800(db(),$f['id']),agent_commerce_checkout_attempt_v800(db(),$f['attempt_id']),commerce_fixture_stripe_transport_pa2([$f]));
    agent_commerce_mark_paid_v800(db(),$f['id'],'stripe',$f['session'],$f['payment'],5000,'usd',$proof);
}
function projection_job_pa2(array $f): array {return commerce_fixture_rows_pa2(db(),'agent_commerce_fulfillment_jobs_pa2','order_id='.$f['id']." AND event_type='project_paid'")[0];}
function projection_due_pa2(array $f): void {db()->prepare("UPDATE agent_commerce_fulfillment_jobs_pa2 SET next_attempt_at='2000-01-01 00:00:00' WHERE order_id=?")->execute([$f['id']]);}

$f=commerce_fixture_order_pa2($pdo);$failProjection=true;projection_pay_pa2($f);
projection_check_pa2(agent_commerce_order_v800($pdo,$f['id'])['payment_status']==='paid'&&projection_job_pa2($f)['status']==='retry','post-commit projection failure retains verified payment and a durable retry');
projection_check_pa2(!str_contains(json_encode(projection_job_pa2($f)),'SECRET'),'projection failure stores a fixed recovery reason');
$failProjection=false;projection_due_pa2($f);agent_commerce_recovery_housekeeping_pa2($pdo);
projection_check_pa2(projection_job_pa2($f)['status']==='completed'&&(int)$pdo->query('SELECT COUNT(*) FROM fixture_commerce_projection WHERE id='.$f['id'])->fetchColumn()===1,'retry repairs an interrupted idempotent domain projection');
$calls=$projectionCalls;projection_pay_pa2($f);agent_commerce_recovery_housekeeping_pa2($pdo);
projection_check_pa2($projectionCalls===$calls,'duplicate receipt and repeated housekeeping do not re-run a completed projection');

$outer=commerce_fixture_order_pa2($pdo);$calls=$projectionCalls;$pdo->beginTransaction();projection_pay_pa2($outer);
projection_check_pa2($projectionCalls===$calls&&projection_job_pa2($outer)['status']==='pending','outer transaction records the projection obligation without publishing before commit');
$pdo->commit();agent_commerce_recovery_housekeeping_pa2($pdo);
projection_check_pa2(projection_job_pa2($outer)['status']==='completed'&&$projectionCalls===$calls+1,'housekeeping repairs a committed payment even when the caller never returns');

$appointment=commerce_fixture_order_pa2($pdo,[],[],['fulfillment_type'=>'appointment']);$calls=$projectionCalls;projection_pay_pa2($appointment);
projection_check_pa2($projectionCalls===$calls&&projection_job_pa2($appointment)['status']!=='completed','booking conversion waits for actual automatic fulfillment');
projection_due_pa2($appointment);
agent_commerce_fulfillment_run_pa2($pdo,100,static function(PDO $pdo,array $order,array $item,string $event):void {
    $pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='confirmed' WHERE id=?")->execute([(int)$item['id']]);
});
projection_check_pa2(projection_job_pa2($appointment)['status']==='completed'&&$projectionCalls===$calls+1,'booking conversion follows committed fulfillment rather than payment alone');

$review=commerce_fixture_order_pa2($pdo,['payment_status'=>'expired','order_status'=>'expired','hold_expires_at'=>null],['status'=>'expired']);$calls=$projectionCalls;projection_pay_pa2($review);agent_commerce_recovery_housekeeping_pa2($pdo);
projection_check_pa2(projection_job_pa2($review)['status']==='review_required'&&$projectionCalls===$calls,'late payment under review cannot publish a successful conversion');
echo 'COMMERCE_PROJECTION_RECOVERY_PA2=PASS driver='.$fixtureConfig['driver'].' checks='.$checks."\n";
