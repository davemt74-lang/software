<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-provider-transport-pa2.php';

/** Recovery storage belongs to the existing Commerce ledger. Loading it performs no DDL. */
function agent_commerce_sqlite_pa2(PDO $pdo): bool{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';}
function agent_commerce_lock_pa2(PDO $pdo): string{return agent_commerce_sqlite_pa2($pdo)?'':' FOR UPDATE';}
function agent_commerce_insert_ignore_pa2(PDO $pdo): string{return agent_commerce_sqlite_pa2($pdo)?'INSERT OR IGNORE':'INSERT IGNORE';}
function agent_commerce_time_pa2(): string{return gmdate('Y-m-d H:i:s');}
function agent_commerce_atomic_pa2(PDO $pdo,callable $work): mixed
{
    $owns=!$pdo->inTransaction();$savepoint='commerce_pa2_'.bin2hex(random_bytes(6));
    if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{$out=$work();if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);return $out;}
    catch(Throwable $e){
        if($pdo->inTransaction()){
            if($owns)$pdo->rollBack();else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $e;
    }
}
function agent_commerce_date_pa2(mixed $value): int
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))return 0;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $date&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():0;
}
function agent_commerce_recovery_column_pa2(PDO $pdo,string $table,string $column): bool
{
    try{$pdo->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');return true;}catch(PDOException){return false;}
}
function agent_commerce_recovery_schema_ready_pa2(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT provider_order_id,provider_account_id,provider_location_id,creation_lease_token,creation_lease_expires_at,creation_attempt_count,creation_request_json,last_error_class FROM agent_commerce_checkout_attempts_v800 WHERE 1=0');
        $pdo->query('SELECT recovery_json,lease_token,lease_expires_at,attempt_count,next_attempt_at,last_error_class FROM agent_commerce_webhook_events_v800 WHERE 1=0');
        $pdo->query('SELECT job_key,order_id,order_item_id,payment_id,event_type,status,attempt_count,next_attempt_at,lease_token,lease_expires_at,last_error_class,completed_at FROM agent_commerce_fulfillment_jobs_pa2 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}
function agent_commerce_recovery_ensure_schema_pa2(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Commerce recovery upgrade cannot run inside a transaction.');
    if(agent_commerce_recovery_schema_ready_pa2($pdo))return;
    $columns=[
        'agent_commerce_checkout_attempts_v800'=>[
            'provider_order_id'=>"VARCHAR(190) NOT NULL DEFAULT ''",'provider_account_id'=>"VARCHAR(190) NOT NULL DEFAULT ''",'provider_location_id'=>"VARCHAR(190) NOT NULL DEFAULT ''",
            'creation_lease_token'=>"CHAR(64) NOT NULL DEFAULT ''",'creation_lease_expires_at'=>'DATETIME NULL','creation_attempt_count'=>'INT NOT NULL DEFAULT 0',
            'creation_request_json'=>'LONGTEXT NULL','last_error_class'=>"VARCHAR(80) NOT NULL DEFAULT ''"
        ],
        'agent_commerce_webhook_events_v800'=>[
            'recovery_json'=>'LONGTEXT NULL','lease_token'=>"CHAR(64) NOT NULL DEFAULT ''",'lease_expires_at'=>'DATETIME NULL',
            'attempt_count'=>'INT NOT NULL DEFAULT 0','next_attempt_at'=>'DATETIME NULL','last_error_class'=>"VARCHAR(80) NOT NULL DEFAULT ''"
        ]
    ];
    foreach($columns as $table=>$definitions)foreach($definitions as $column=>$definition){
        if(!agent_commerce_recovery_column_pa2($pdo,$table,$column))$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
    }
    $id=agent_commerce_sqlite_pa2($pdo)?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail=agent_commerce_sqlite_pa2($pdo)?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $jobSchema="CREATE TABLE IF NOT EXISTS agent_commerce_fulfillment_jobs_pa2 (
        id $id,job_key CHAR(64) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,order_item_id BIGINT UNSIGNED NOT NULL,payment_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'pending',attempt_count INT NOT NULL DEFAULT 0,next_attempt_at DATETIME NULL,
        lease_token CHAR(64) NOT NULL DEFAULT '',lease_expires_at DATETIME NULL,last_error_class VARCHAR(80) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,completed_at DATETIME NULL,
        FOREIGN KEY(order_id) REFERENCES agent_commerce_orders_v800(id) ON DELETE CASCADE,
        FOREIGN KEY(order_item_id) REFERENCES agent_commerce_order_items_v800(id) ON DELETE CASCADE,
        FOREIGN KEY(payment_id) REFERENCES agent_commerce_payments_v800(id) ON DELETE RESTRICT
    )$tail";
    // Preserve only lineage that the old row can establish. A completed legacy
    // Square row lost its Order ID and therefore cannot manufacture that proof.
    $after=0;
    do{
        $s=$pdo->prepare('SELECT a.*,o.connection_id route_connection,o.provider_snapshot route_provider,o.external_account_snapshot route_account,o.owner_user_id order_owner,o.workspace_owner_user_id order_workspace,c.owner_user_id connection_owner,c.external_account_id connection_account,c.provider connection_provider,c.capabilities_json FROM agent_commerce_checkout_attempts_v800 a JOIN agent_commerce_orders_v800 o ON o.id=a.order_id JOIN agent_commerce_provider_connections_v800 c ON c.id=a.connection_id WHERE a.id>? ORDER BY a.id LIMIT 500');
        $s->execute([$after]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as $a){
            $after=(int)$a['id'];$owner=(int)($a['order_workspace']??0)?:((int)$a['order_owner']);
            if($owner!==(int)$a['connection_owner']||(int)$a['connection_id']!==(int)$a['route_connection']
                ||(string)$a['provider']!==(string)$a['route_provider']||(string)$a['provider']!==(string)$a['connection_provider']
                ||(string)$a['route_account']===''||!hash_equals((string)$a['route_account'],(string)$a['connection_account']))continue;
            $account=(string)($a['provider_account_id']??'');$location=(string)($a['provider_location_id']??'');$providerOrder=(string)($a['provider_order_id']??'');
            if($account==='')$account=(string)$a['route_account'];
            if($providerOrder===''&&in_array((string)$a['provider'],['stripe','paypal'],true)&&!str_starts_with((string)$a['external_session_id'],'pending:'))$providerOrder=(string)$a['external_session_id'];
            if((string)$a['provider']==='square'){
                if($providerOrder===''&&in_array((string)$a['status'],['open','expired','cancelled'],true))$providerOrder=(string)$a['external_payment_id'];
                $caps=json_decode((string)$a['capabilities_json'],true);
                if($location===''&&is_array($caps)&&is_string($caps['location_id']??null))$location=$caps['location_id'];
            }
            $pdo->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET provider_order_id=?,provider_account_id=?,provider_location_id=? WHERE id=?')->execute([$providerOrder,$account,$location,(int)$a['id']]);
            if((string)$a['provider']==='square'&&$providerOrder!==''&&in_array((string)$a['status'],['open','expired','cancelled'],true)&&hash_equals($providerOrder,(string)$a['external_payment_id'])){
                $pdo->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET external_payment_id='' WHERE id=?")->execute([(int)$a['id']]);
            }
        }
    }while(count($rows)===500);
    // This final table makes readiness true only after legacy backfill finished.
    $pdo->exec($jobSchema);
}

function agent_commerce_recovery_json_pa2(mixed $json,int $max=16384): array
{
    if(!is_string($json)||strlen($json)>$max)throw new RuntimeException('Commerce recovery evidence is unavailable.',409);
    try{$value=json_decode($json,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('Commerce recovery evidence is unavailable.',409);}
    if(!is_array($value)||!str_starts_with(ltrim($json),'{')||($value!==[]&&array_is_list($value)))throw new RuntimeException('Commerce recovery evidence is unavailable.',409);
    return $value;
}
function agent_commerce_recovery_target_pa2(array $target): array
{
    $keys=['status','provider','event_id','event_type','provider_account_id','provider_location_id','external_session_id','provider_order_id','expected_payment_id'];
    if(array_diff(array_keys($target),$keys)||!in_array($target['status']??'', ['target','ignored'],true))throw new RuntimeException('Commerce webhook identity is invalid.',409);
    $out=[];
    foreach($keys as $key){
        $value=$target[$key]??'';
        if(!is_string($value)||strlen($value)>190||preg_match('/[\x00-\x20\x7f]/',$value))throw new RuntimeException('Commerce webhook identity is invalid.',409);
        $out[$key]=$value;
    }
    if($out['event_id']===''||$out['event_type']===''||strlen($out['event_type'])>120)throw new RuntimeException('Commerce webhook identity is invalid.',409);
    return $out;
}

/** A claim is fenced by a random token; a prior worker cannot complete its successor. */
function agent_commerce_webhook_claim_pa2(PDO $pdo,string $provider,string $eventId,string $eventType,string $payloadHash,array $target): array
{
    if(!in_array($provider,['stripe','square','paypal'],true)||!preg_match('/^[a-f0-9]{64}$/D',$payloadHash))throw new RuntimeException('Commerce webhook identity is invalid.',409);
    $target=agent_commerce_recovery_target_pa2($target+['provider'=>$provider]);
    if($target['provider']!==$provider||$target['event_id']!==$eventId||$target['event_type']!==$eventType)throw new RuntimeException('Commerce webhook identity is invalid.',409);
    $encoded=json_encode($target,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$now=agent_commerce_time_pa2();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo)." INTO agent_commerce_webhook_events_v800 (provider,external_event_id,event_type,payload_sha256,status,recovery_json,created_at,updated_at) VALUES (?,?,?,?, 'pending',?,?,?)")
            ->execute([$provider,$eventId,$eventType,$payloadHash,$encoded,$now,$now]);
        $s=$pdo->prepare('SELECT * FROM agent_commerce_webhook_events_v800 WHERE provider=? AND external_event_id=? LIMIT 1'.agent_commerce_lock_pa2($pdo));$s->execute([$provider,$eventId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row||!hash_equals((string)$row['provider'],$provider)||!hash_equals((string)$row['external_event_id'],$eventId)||!hash_equals((string)$row['event_type'],$eventType))throw new RuntimeException('Commerce webhook identity conflicts with its receipt.',409);
        if((string)($row['recovery_json']??'')!==''){
            $prior=agent_commerce_recovery_target_pa2(agent_commerce_recovery_json_pa2($row['recovery_json']));
            if($prior!==$target)throw new RuntimeException('Commerce webhook identity conflicts with its receipt.',409);
        }elseif(!hash_equals((string)$row['payload_sha256'],$payloadHash))throw new RuntimeException('Commerce webhook identity conflicts with its receipt.',409);
        if((string)$row['status']==='processed'){if($owns)$pdo->commit();return ['state'=>'processed','row'=>$row];}
        if((string)$row['status']==='processing'&&agent_commerce_date_pa2($row['lease_expires_at']??null)>time()){if($owns)$pdo->commit();return ['state'=>'busy','row'=>$row];}
        $token=bin2hex(random_bytes(32));$until=gmdate('Y-m-d H:i:s',time()+180);
        $pdo->prepare("UPDATE agent_commerce_webhook_events_v800 SET status='processing',recovery_json=?,lease_token=?,lease_expires_at=?,attempt_count=attempt_count+1,next_attempt_at=NULL,last_error_class='',error_message='',processed_at=NULL,updated_at=? WHERE id=?")
            ->execute([$encoded,$token,$until,$now,(int)$row['id']]);
        $row['recovery_json']=$encoded;$row['lease_token']=$token;$row['lease_expires_at']=$until;$row['attempt_count']=(int)($row['attempt_count']??0)+1;$row['status']='processing';
        if($owns)$pdo->commit();return ['state'=>'claimed','row'=>$row,'target'=>$target,'token'=>$token];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function agent_commerce_webhook_finish_pa2(PDO $pdo,array $claim,string $status,string $reason=''): bool
{
    if(!in_array($status,['processed','pending','failed','review_required'],true)||!preg_match('/^[a-z0-9_]{0,80}$/D',$reason))throw new InvalidArgumentException('Invalid Commerce recovery result.');
    $now=agent_commerce_time_pa2();$count=(int)($claim['row']['attempt_count']??1);$next=$status==='processed'||$status==='review_required'?null:gmdate('Y-m-d H:i:s',time()+min(3600,10*(2**min(8,max(0,$count-1)))));
    $s=$pdo->prepare("UPDATE agent_commerce_webhook_events_v800 SET status=?,last_error_class=?,error_message='',processed_at=?,next_attempt_at=?,lease_token='',lease_expires_at=NULL,updated_at=? WHERE id=? AND status='processing' AND lease_token=? AND lease_expires_at>=?");
    $s->execute([$status,$reason,$status==='processed'?$now:null,$next,$now,(int)($claim['row']['id']??0),(string)($claim['token']??''),$now]);return $s->rowCount()===1;
}
function agent_commerce_webhook_attempt_pa2(PDO $pdo,array $target): ?array
{
    $column=$target['external_session_id']!==''?'external_session_id':'provider_order_id';$value=$target[$column]??'';
    if($value==='')throw new RuntimeException('Commerce payment target is unavailable.',409);
    $s=$pdo->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE provider=? AND '.$column.'=? ORDER BY id LIMIT 3');$s->execute([$target['provider'],$value]);$matches=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[] as $a){
        if(!hash_equals((string)$a['provider'],(string)$target['provider'])||!hash_equals((string)$a[$column],$value))continue;
        foreach(['provider_account_id','provider_location_id','provider_order_id','external_session_id'] as $key)if(($target[$key]??'')!==''&&!hash_equals((string)($a[$key]??''),(string)$target[$key]))throw new RuntimeException('Commerce webhook does not match the checkout lineage.',409);
        $matches[]=$a;
    }
    if(count($matches)>1)throw new RuntimeException('Commerce payment target is ambiguous.',409);
    return $matches[0]??null;
}
function agent_commerce_read_attempt_pa2(PDO $pdo,array $order,array $attempt,?callable $http=null): array
{
    require_once __DIR__.'/agent-commerce-provider-evidence-pa2.php';
    $connection=agent_commerce_attempt_connection_pa2($pdo,$order,$attempt);$request=agent_commerce_provider_transport_pa2($pdo,$connection,$http);
    return agent_commerce_provider_read_pa2((string)$attempt['provider'],$attempt,$order,$connection,static fn(string $path):array=>$request('GET',$path));
}
function agent_commerce_webhook_process_claim_pa2(PDO $pdo,array $claim,?callable $http=null): array
{
    if(($claim['state']??'')==='processed'){
        $json=$claim['row']['recovery_json']??null;
        if(is_string($json)&&$json!=='')try{$target=agent_commerce_recovery_target_pa2(agent_commerce_recovery_json_pa2($json));if($target['status']==='target'){$a=agent_commerce_webhook_attempt_pa2($pdo,$target);if($a)agent_commerce_projection_repair_pa2($pdo,(int)$a['order_id']);}}catch(Throwable){}
        return ['status'=>'processed'];
    }
    if(($claim['state']??'')!=='claimed')throw new RuntimeException('Commerce webhook is already processing.',503);
    $target=$claim['target'];
    try{
        if($target['status']==='ignored'){
            if(!agent_commerce_webhook_finish_pa2($pdo,$claim,'processed'))throw new RuntimeException('Commerce webhook lease changed.',503);
            return ['status'=>'processed'];
        }
        $attempt=agent_commerce_webhook_attempt_pa2($pdo,$target);
        if(!$attempt){
            if(!agent_commerce_webhook_finish_pa2($pdo,$claim,'pending','checkout_not_recorded'))throw new RuntimeException('Commerce webhook lease changed.',503);
            return ['status'=>'pending'];
        }
        $order=agent_commerce_order_v800($pdo,(int)$attempt['order_id']);if(!$order)throw new RuntimeException('Commerce order is unavailable.',409);
        if($target['expected_payment_id']!=='')$attempt['expected_payment_id']=$target['expected_payment_id'];
        $proof=agent_commerce_read_attempt_pa2($pdo,$order,$attempt,$http);
        if(($proof['status']??'')!=='verified'){
            if(!agent_commerce_webhook_finish_pa2($pdo,$claim,'pending','provider_payment_pending'))throw new RuntimeException('Commerce webhook lease changed.',503);
            return ['status'=>'pending'];
        }
        // Receipt and fenced event completion share a transaction. A lost lease
        // cannot commit payment under another worker's receipt.
        $pdo->beginTransaction();
        try{
            $s=$pdo->prepare('SELECT status,lease_token,lease_expires_at FROM agent_commerce_webhook_events_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));$s->execute([(int)$claim['row']['id']]);$live=$s->fetch(PDO::FETCH_ASSOC);
            if(!$live||(string)$live['status']!=='processing'||!hash_equals((string)$live['lease_token'],(string)$claim['token'])||agent_commerce_date_pa2($live['lease_expires_at'])<time())throw new RuntimeException('Commerce webhook lease changed.',503);
            agent_commerce_mark_paid_v800($pdo,(int)$order['id'],(string)$proof['provider'],(string)$proof['external_session_id'],(string)$proof['external_payment_id'],(int)$proof['amount_cents'],(string)$proof['currency'],$proof);
            if(!agent_commerce_webhook_finish_pa2($pdo,$claim,'processed'))throw new RuntimeException('Commerce webhook lease changed.',503);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        agent_commerce_projection_repair_pa2($pdo,(int)$order['id']);
        return ['status'=>'processed'];
    }catch(Throwable $e){agent_commerce_webhook_finish_pa2($pdo,$claim,'failed','payment_verification_failed');throw $e;}
}

/** Must be called within the financial/expiry transaction. */
function agent_commerce_fulfillment_verify_job_pa2(PDO $pdo,string $key,int $orderId,int $itemId,?int $paymentId,string $event): void
{
    $q=$pdo->prepare('SELECT job_key,order_id,order_item_id,payment_id,event_type FROM agent_commerce_fulfillment_jobs_pa2 WHERE job_key=?');$q->execute([$key]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!hash_equals((string)$row['job_key'],$key)||(int)$row['order_id']!==$orderId||(int)$row['order_item_id']!==$itemId
        ||(($row['payment_id']===null?null:(int)$row['payment_id'])!==$paymentId)||(string)$row['event_type']!==$event){
        throw new RuntimeException('Commerce fulfillment obligation could not be verified.',409);
    }
}
function agent_commerce_fulfillment_enqueue_pa2(PDO $pdo,array $order,string $event,?int $paymentId=null,bool $review=false): void
{
    if(!$pdo->inTransaction()||!in_array($event,['paid','paid_in_full','payment_updated','expired'],true))throw new RuntimeException('Commerce fulfillment must be recorded with its transition.');
    $now=agent_commerce_time_pa2();
    $items=agent_commerce_order_items_v800($pdo,(int)$order['id']);
    if(!$items)throw new RuntimeException('Commerce order has no fulfillment lineage.',409);
    foreach($items as $item){
        $type=(string)($item['fulfillment_type']??'');$key=hash('sha256',implode('|',[(int)$order['id'],(int)$item['id'],$event,$paymentId??0]));
        $status=$review?'review_required':($type==='appointment'?'pending':'manual');
        $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO agent_commerce_fulfillment_jobs_pa2 (job_key,order_id,order_item_id,payment_id,event_type,status,attempt_count,next_attempt_at,created_at,updated_at,last_error_class) VALUES (?,?,?,?,?,?,0,?,?,?,?)')
            ->execute([$key,(int)$order['id'],(int)$item['id'],$paymentId,$event,$status,$status==='pending'?$now:null,$now,$now,$review?'late_payment':'']);
        agent_commerce_fulfillment_verify_job_pa2($pdo,$key,(int)$order['id'],(int)$item['id'],$paymentId,$event);
    }
    if($paymentId!==null){
        $first=$items[0];$key=hash('sha256',implode('|',[(int)$order['id'],(int)$first['id'],'project_paid',$paymentId]));$status=$review?'review_required':'pending';
        $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo)." INTO agent_commerce_fulfillment_jobs_pa2 (job_key,order_id,order_item_id,payment_id,event_type,status,attempt_count,next_attempt_at,created_at,updated_at,last_error_class) VALUES (?,?,?,?,'project_paid',?,0,?,?,?,?)")
            ->execute([$key,(int)$order['id'],(int)$first['id'],$paymentId,$status,$review?null:$now,$now,$now,$review?'payment_review':'']);
        agent_commerce_fulfillment_verify_job_pa2($pdo,$key,(int)$order['id'],(int)$first['id'],$paymentId,'project_paid');
    }
}
function agent_commerce_fulfillment_finish_pa2(PDO $pdo,array $job,string $status,string $reason=''): bool
{
    if(!in_array($status,['completed','retry','review_required','manual'],true)||!preg_match('/^[a-z0-9_]{0,80}$/D',$reason))throw new InvalidArgumentException('Invalid Commerce fulfillment result.');
    $now=agent_commerce_time_pa2();$next=$status==='retry'?gmdate('Y-m-d H:i:s',time()+min(3600,30*(2**min(6,max(0,(int)$job['attempt_count']-1))))):null;
    $s=$pdo->prepare("UPDATE agent_commerce_fulfillment_jobs_pa2 SET status=?,last_error_class=?,next_attempt_at=?,completed_at=?,lease_token='',lease_expires_at=NULL,updated_at=? WHERE id=? AND status='processing' AND lease_token=? AND lease_expires_at>=?");
    $s->execute([$status,$reason,$next,$status==='completed'?$now:null,$now,(int)$job['id'],(string)$job['lease_token'],$now]);return $s->rowCount()===1;
}
function agent_commerce_projection_repair_pa2(PDO $pdo,int $orderId): void
{
    if($pdo->inTransaction())return;
    try{agent_commerce_fulfillment_run_pa2($pdo,20,null,$orderId,true);}catch(Throwable $e){error_log('Commerce projection recovery pending ['.get_class($e).'].');}
}
function agent_commerce_fulfillment_run_pa2(PDO $pdo,int $limit=100,?callable $dispatch=null,int $onlyOrderId=0,bool $projectionOnly=false): array
{
    if($pdo->inTransaction())throw new RuntimeException('Commerce fulfillment cannot run before commit.');
    $limit=max(1,min(500,$limit));$now=agent_commerce_time_pa2();$stats=['completed'=>0,'retry'=>0,'review_required'=>0];
    $scope=($onlyOrderId>0?' AND order_id='.$onlyOrderId:'').($projectionOnly?" AND event_type='project_paid'":'');
    $s=$pdo->prepare("SELECT id FROM agent_commerce_fulfillment_jobs_pa2 WHERE ((status IN ('pending','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=?)) OR (status='processing' AND lease_expires_at<=?))".$scope." ORDER BY id LIMIT ".$limit);$s->execute([$now,$now]);
    foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[] as $pick){
        $job=null;
        try{
            $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM agent_commerce_fulfillment_jobs_pa2 WHERE id=?'.agent_commerce_lock_pa2($pdo));$q->execute([(int)$pick['id']]);$job=$q->fetch(PDO::FETCH_ASSOC);
            if(!$job||!in_array((string)$job['status'],['pending','retry','processing'],true)
                ||((string)$job['status']==='processing'&&agent_commerce_date_pa2($job['lease_expires_at'])>time())
                ||agent_commerce_date_pa2($job['next_attempt_at'])>time()){$pdo->rollBack();continue;}
            $token=bin2hex(random_bytes(32));$until=gmdate('Y-m-d H:i:s',time()+180);$job['lease_token']=$token;$job['attempt_count']=(int)$job['attempt_count']+1;
            $pdo->prepare("UPDATE agent_commerce_fulfillment_jobs_pa2 SET status='processing',attempt_count=attempt_count+1,lease_token=?,lease_expires_at=?,updated_at=? WHERE id=?")->execute([$token,$until,$now,(int)$job['id']]);$pdo->commit();
            if((string)$job['event_type']==='project_paid'){
                $order=agent_commerce_order_v800($pdo,(int)$job['order_id'])?:throw new RuntimeException('Commerce projection order unavailable.',409);
                $dependencies=$pdo->prepare("SELECT status FROM agent_commerce_fulfillment_jobs_pa2 WHERE order_id=? AND payment_id=? AND event_type<>'project_paid'");$dependencies->execute([(int)$job['order_id'],(int)$job['payment_id']]);$states=$dependencies->fetchAll(PDO::FETCH_COLUMN);
                if(!$states||in_array('review_required',$states,true))throw new RuntimeException('Commerce projection requires fulfillment review.',409);
                foreach($states as $state)if(!in_array($state,['completed','manual'],true))throw new RuntimeException('Commerce projection awaits fulfillment.',425);
                agent_commerce_project_paid_pa2($pdo,$order);
                if(!agent_commerce_fulfillment_finish_pa2($pdo,$job,'completed'))throw new RuntimeException('Commerce projection lease changed.',503);
                $stats['completed']++;continue;
            }
            // Domain state and the completion receipt commit together. Network
            // delivery remains in Scheduling's own durable delivery queue.
            $pdo->beginTransaction();$o=$pdo->prepare('SELECT * FROM agent_commerce_orders_v800 WHERE id=?'.agent_commerce_lock_pa2($pdo));$o->execute([(int)$job['order_id']]);$order=$o->fetch(PDO::FETCH_ASSOC);
            $i=$pdo->prepare('SELECT * FROM agent_commerce_order_items_v800 WHERE id=? AND order_id=?'.agent_commerce_lock_pa2($pdo));$i->execute([(int)$job['order_item_id'],(int)$job['order_id']]);$item=$i->fetch(PDO::FETCH_ASSOC);
            if(!$order||!$item)throw new RuntimeException('Commerce fulfillment record unavailable.',409);
            $event=(string)$job['event_type'];
            if($event==='expired'&&!in_array((string)$order['payment_status'],['expired','cancelled'],true)&&(string)$order['order_status']!=='payment_review'){
                if(!agent_commerce_fulfillment_finish_pa2($pdo,$job,'completed','expiry_superseded'))throw new RuntimeException('Commerce fulfillment lease changed.',503);
            }else{
                if($event!=='expired'&&((string)$order['order_status']==='payment_review'||!in_array((string)$order['payment_status'],['paid','partially_paid'],true)))throw new RuntimeException('Commerce fulfillment requires review.',409);
                if($dispatch)$dispatch($pdo,$order,$item,$event);
                else{
                    $type=(string)$item['fulfillment_type'];$fn='agent_commerce_fulfillment_'.$type.'_'.$event.'_v800';
                    if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$type)||!function_exists($fn)){
                        if($event==='paid_in_full'||$event==='payment_updated'){
                            // An appointment was already activated by its deposit.
                            $fn='agent_commerce_fulfillment_'.$type.'_paid_v800';
                        }
                    }
                    if(!function_exists($fn))throw new RuntimeException('Commerce fulfillment adapter unavailable.',503);
                    $fn($pdo,$order,$item);
                }
                $check=$pdo->prepare('SELECT fulfillment_status FROM agent_commerce_order_items_v800 WHERE id=? AND order_id=?');$check->execute([(int)$item['id'],(int)$order['id']]);$state=(string)$check->fetchColumn();
                if(!in_array($state,$event==='expired'?['cancelled']:['confirmed','fulfilled'],true))throw new RuntimeException('Commerce fulfillment result was not established.',409);
                if(!agent_commerce_fulfillment_finish_pa2($pdo,$job,'completed'))throw new RuntimeException('Commerce fulfillment lease changed.',503);
            }
            $pdo->commit();$stats['completed']++;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();if(!$job||empty($job['lease_token']))continue;
            $terminal=(int)$e->getCode()===409||((int)$job['attempt_count']>=8&&(int)$e->getCode()!==425);$state=$terminal?'review_required':'retry';$reason=$terminal?'fulfillment_review_required':((int)$e->getCode()===425?'awaiting_fulfillment':'fulfillment_retry');
            if(agent_commerce_fulfillment_finish_pa2($pdo,$job,$state,$reason)){
                $stats[$state]++;
                if($terminal&&(string)$job['event_type']!=='project_paid')$pdo->prepare("UPDATE agent_commerce_order_items_v800 SET fulfillment_status='review_required',updated_at=? WHERE id=? AND fulfillment_status NOT IN ('fulfilled','confirmed','cancelled')")->execute([agent_commerce_time_pa2(),(int)$job['order_item_id']]);
            }
        }
    }
    return $stats;
}
function agent_commerce_recovery_housekeeping_pa2(PDO $pdo,int $limit=100,?callable $http=null): array
{
    if(!agent_commerce_recovery_schema_ready_pa2($pdo))throw new RuntimeException('Commerce recovery schema is unavailable.',503);
    $limit=max(1,min(500,$limit));$now=agent_commerce_time_pa2();$stats=['webhooks_processed'=>0,'webhooks_pending'=>0,'webhooks_failed'=>0];
    $s=$pdo->prepare("SELECT * FROM agent_commerce_webhook_events_v800 WHERE recovery_json IS NOT NULL AND ((status IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=?)) OR (status='processing' AND lease_expires_at<=?)) ORDER BY id LIMIT ".$limit);$s->execute([$now,$now]);
    foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        try{
            $target=agent_commerce_recovery_target_pa2(agent_commerce_recovery_json_pa2($row['recovery_json']));
            $claim=agent_commerce_webhook_claim_pa2($pdo,(string)$row['provider'],(string)$row['external_event_id'],(string)$row['event_type'],(string)$row['payload_sha256'],$target);
            if($claim['state']==='busy')continue;
            $result=agent_commerce_webhook_process_claim_pa2($pdo,$claim,$http);$stats[$result['status']==='processed'?'webhooks_processed':'webhooks_pending']++;
        }catch(Throwable){$stats['webhooks_failed']++;}
    }
    $stats['fulfillment']=agent_commerce_fulfillment_run_pa2($pdo,$limit);return $stats;
}
