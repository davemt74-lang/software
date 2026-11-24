<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

require_once __DIR__.'/phone-tracking-v1.php';
require_once __DIR__.'/ai-settings.php';
require_once __DIR__.'/phone-push-vapid-cache-v1.php';

/** PS5C.4: private, per-device outbox. Installation and transport are explicit.
 * No source text, endpoint, browser secret or VAPID private key is copied here.
 * A source validator is REQUIRED at enqueue, claim, final send and open. It must
 * read the canonical exact-device source under the existing owner/device locks;
 * being an owner's paired phone is not permission to read that owner's Chat.
 */
const VP3_PHONE_PUSH_DELIVERY_LEASE_V1 = 60;
const VP3_PHONE_PUSH_DELIVERY_MAX_ATTEMPTS_V1 = 5;
const VP3_PHONE_PUSH_DELIVERY_WORKER_FRESH_V1 = 180;

function vp3_phone_push_delivery_install_v1(PDO $pdo,string $options=''):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install notification delivery outside transactions.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&$options==='')$options=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_push_delivery_config_v1 (
        id SMALLINT PRIMARY KEY,enabled SMALLINT NOT NULL DEFAULT 0,revision BIGINT NOT NULL DEFAULT 1,
        subject VARCHAR(254) NOT NULL DEFAULT '',updated_epoch BIGINT NOT NULL DEFAULT 0,
        worker_epoch BIGINT NOT NULL DEFAULT 0,worker_runtime VARCHAR(32) NOT NULL DEFAULT '',
        worker_transport_ready SMALLINT NOT NULL DEFAULT 0,worker_code VARCHAR(64) NOT NULL DEFAULT 'never_seen',
        capacity_skipped_count BIGINT NOT NULL DEFAULT 0,last_skipped_epoch BIGINT NOT NULL DEFAULT 0
    )".$options);
    foreach(['capacity_skipped_count','last_skipped_epoch'] as $column){
        if(schema_metadata_column_exists($pdo,'vp3_phone_push_delivery_config_v1',$column))continue;
        try{$pdo->exec('ALTER TABLE vp3_phone_push_delivery_config_v1 ADD COLUMN '.$column.' BIGINT NOT NULL DEFAULT 0');}
        catch(PDOException $e){
            $native=(int)($e->errorInfo[1]??0);
            $duplicate=$native===1060||$native===1&&str_contains(strtolower($e->getMessage()),'duplicate column name:');
            if(!$duplicate||!schema_metadata_column_exists($pdo,'vp3_phone_push_delivery_config_v1',$column))throw $e;
        }
    }
    $insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
    $pdo->exec($insert.' INTO vp3_phone_push_delivery_config_v1(id) VALUES(1)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_push_outbox_v1 (
        event_ref CHAR(32) NOT NULL PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,
        source_type VARCHAR(16) NOT NULL,source_id VARCHAR(96) NOT NULL,source_revision BIGINT NOT NULL,
        source_key CHAR(64) NOT NULL,credential_hash CHAR(64) NOT NULL,subscription_revision BIGINT NOT NULL,
        key_id CHAR(64) NOT NULL,payload_hash CHAR(64) NOT NULL,config_revision BIGINT NOT NULL,
        receipt_hash CHAR(64) NOT NULL,state VARCHAR(16) NOT NULL,attempts SMALLINT NOT NULL DEFAULT 0,
        available_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,created_epoch BIGINT NOT NULL,
        updated_epoch BIGINT NOT NULL,lease_token CHAR(32) NULL,lease_expires_epoch BIGINT NOT NULL DEFAULT 0,
        send_started_epoch BIGINT NOT NULL DEFAULT 0,provider_state VARCHAR(16) NULL,
        provider_http_status SMALLINT NOT NULL DEFAULT 0,provider_accepted_epoch BIGINT NOT NULL DEFAULT 0,
        client_observed_epoch BIGINT NOT NULL DEFAULT 0,opened_epoch BIGINT NOT NULL DEFAULT 0,
        last_code VARCHAR(64) NOT NULL DEFAULT '',
        UNIQUE(owner_user_id,device_id,source_key),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$options);
    foreach(['vp3_phone_push_dispatch_v1'=>'state,available_epoch,lease_expires_epoch,expires_epoch',
        'vp3_phone_push_device_events_v1'=>'owner_user_id,device_id,created_epoch'] as $name=>$columns){
        if(!$mysql){$pdo->exec('CREATE INDEX IF NOT EXISTS '.$name.' ON vp3_phone_push_outbox_v1('.$columns.')');continue;}
        $exists=static function()use($pdo,$name):bool{
            $q=$pdo->prepare('SHOW INDEX FROM vp3_phone_push_outbox_v1 WHERE Key_name=?');$q->execute([$name]);
            $found=(bool)$q->fetch();$q->closeCursor();return $found;
        };
        if(!$exists())try{$pdo->exec('CREATE INDEX '.$name.' ON vp3_phone_push_outbox_v1('.$columns.')');}
        catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1061||!$exists())throw $e;}
    }
    vp3_phone_push_vapid_cache_install_v1($pdo,$options);
}

function vp3_phone_push_delivery_ready_v1(PDO $pdo):bool
{
    try{
        $pdo->query('SELECT id,enabled,revision,subject,updated_epoch,worker_epoch,worker_runtime,worker_transport_ready,worker_code,capacity_skipped_count,last_skipped_epoch FROM vp3_phone_push_delivery_config_v1 WHERE 1=0');
        $pdo->query('SELECT event_ref,owner_user_id,device_id,source_type,source_id,source_revision,source_key,credential_hash,subscription_revision,key_id,payload_hash,config_revision,receipt_hash,state,attempts,available_epoch,expires_epoch,created_epoch,updated_epoch,lease_token,lease_expires_epoch,send_started_epoch,provider_state,provider_http_status,provider_accepted_epoch,client_observed_epoch,opened_epoch,last_code FROM vp3_phone_push_outbox_v1 WHERE 1=0');
        return vp3_phone_push_ready_v1($pdo)&&vp3_phone_push_vapid_cache_ready_v1($pdo);
    }catch(PDOException){return false;}
}

function vp3_phone_push_delivery_need_v1(bool $ok,int $code=422):void
{if(!$ok)throw new RuntimeException('Phone notification unavailable.',$code);}

function vp3_phone_push_delivery_config_v1(PDO $pdo,bool $lock=false):?array
{
    $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_delivery_config_v1 WHERE id=1'.($lock?vp3_phone_lock_v1($pdo):''),[]);
    $r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}

function vp3_phone_push_delivery_subject_v1(mixed $subject):bool
{
    if(!is_string($subject)||strlen($subject)>254||preg_match('/[^\x21-\x7e]/',$subject))return false;
    if(str_starts_with($subject,'mailto:'))return filter_var(substr($subject,7),FILTER_VALIDATE_EMAIL)!==false;
    $p=parse_url($subject);
    return is_array($p)&&($p['scheme']??'')==='https'&&is_string($p['host']??null)&&str_contains($p['host'],'.')
        &&!array_intersect(array_keys($p),['user','pass','port','query','fragment'])
        &&filter_var($p['host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)!==false
        &&filter_var($p['host'],FILTER_VALIDATE_IP)===false&&!str_ends_with(strtolower($p['host']),'.localhost');
}

/** Admin web PHP need not be the same version as the scheduled transport PHP. */
function vp3_phone_push_delivery_configure_v1(PDO $pdo,array $admin,array $input,int $now,?string $testKey=null):array
{
    vp3_phone_push_delivery_need_v1(function_exists('has_permission')&&has_permission('users.manage',$admin)
        &&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),403);
    vp3_phone_push_delivery_need_v1(vp3_phone_push_delivery_ready_v1($pdo),503);
    vp3_phone_push_delivery_need_v1(!array_diff(array_keys($input),['enabled','subject','revision'])
        &&is_bool($input['enabled']??null)&&is_int($input['revision']??null)&&is_string($input['subject']??null)
        &&($input['subject']===''&&!$input['enabled']||vp3_phone_push_delivery_subject_v1($input['subject'])));
    vp3_phone_transaction_v1($pdo,static function()use($pdo,$input,$now,$testKey):array{
        // Enrollment already locks this row before delivery configuration.
        $push=vp3_phone_push_config_v1($pdo,true);$c=vp3_phone_push_delivery_config_v1($pdo,true);
        vp3_phone_push_delivery_need_v1($c!==null,503);
        vp3_phone_push_delivery_need_v1((int)$c['revision']===$input['revision']&&(int)$c['revision']<9007199254740990,409);
        if($input['enabled'])vp3_phone_push_delivery_need_v1(vp3_phone_push_config_valid_v1($push,vp3_phone_push_master_v1($testKey)),503);
        if((bool)$c['enabled']===$input['enabled']&&$c['subject']===$input['subject'])return [];
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_delivery_config_v1 SET enabled=?,subject=?,revision=revision+1,updated_epoch=? WHERE id=1',
            [(int)$input['enabled'],$input['subject'],$now]);
        // Re-enabling never revives notifications from an earlier grant/config.
        vp3_phone_push_delivery_cancel_where_v1($pdo,'1=1',[],$now,'configuration_changed');
        return [];
    });
    return vp3_phone_push_delivery_status_v1($pdo,$now,$testKey);
}

/** Called only by the worker after a real runtime/dependency preflight, never by a web poll. */
function vp3_phone_push_delivery_heartbeat_v1(PDO $pdo,int $now,array $health):void
{
    vp3_phone_push_delivery_need_v1(vp3_phone_push_delivery_ready_v1($pdo),503);
    vp3_phone_push_delivery_need_v1(!array_diff(array_keys($health),['runtime','transport_ready','code'])
        &&is_string($health['runtime']??null)&&preg_match('/^[A-Za-z0-9 ._+()-]{1,32}$/D',$health['runtime'])===1
        &&is_bool($health['transport_ready']??null)&&is_string($health['code']??null)
        &&preg_match('/^[a-z0-9_]{1,64}$/D',$health['code'])===1);
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_delivery_config_v1 SET worker_epoch=?,worker_runtime=?,worker_transport_ready=?,worker_code=? WHERE id=1 AND worker_epoch<=?',
        [$now,$health['runtime'],(int)$health['transport_ready'],$health['code'],$now]);
}

/** Optional Chat/request delivery may reach capacity without rejecting its source.
 * Record only the aggregate reason, atomically with that source; no private text,
 * source identity or endpoint is logged. Explicit test-button limits still fail.
 */
function vp3_phone_push_delivery_capacity_skipped_v1(PDO $pdo,int $now):void
{
    if(!vp3_phone_transaction_active_v1($pdo))throw new LogicException('Queue-capacity accounting requires the source transaction.');
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_delivery_config_v1 SET capacity_skipped_count=CASE WHEN capacity_skipped_count<9007199254740990 THEN capacity_skipped_count+1 ELSE capacity_skipped_count END,last_skipped_epoch=CASE WHEN last_skipped_epoch<? THEN ? ELSE last_skipped_epoch END WHERE id=1',[$now,$now]);
}

function vp3_phone_push_delivery_status_v1(PDO $pdo,int $now,?string $testKey=null):array
{
    $worker=['state'=>'never_seen','last_observed_epoch'=>0,'runtime'=>'','transport_ready'=>false,'code'=>'never_seen'];
    $counts=['capacity_skipped_count'=>0,'last_skipped_epoch'=>0];
    if(!vp3_phone_push_delivery_ready_v1($pdo))return ['state'=>'upgrade_required','enabled'=>false,'ready'=>false,'revision'=>0,'subject'=>'','worker'=>$worker]+$counts;
    $c=vp3_phone_push_delivery_config_v1($pdo);$push=vp3_phone_push_config_v1($pdo);
    if(!$c)return ['state'=>'upgrade_required','enabled'=>false,'ready'=>false,'revision'=>0,'subject'=>'','worker'=>$worker]+$counts;
    $fresh=(int)$c['worker_epoch']>0&&(int)$c['worker_epoch']<=$now+5&&(int)$c['worker_epoch']>$now-VP3_PHONE_PUSH_DELIVERY_WORKER_FRESH_V1;
    $worker=['state'=>(int)$c['worker_epoch']===0?'never_seen':($fresh?'observed':'stale'),
        'last_observed_epoch'=>(int)$c['worker_epoch'],'runtime'=>(string)$c['worker_runtime'],
        'transport_ready'=>(bool)$c['worker_transport_ready'],'code'=>(string)$c['worker_code']];
    $keys=vp3_phone_push_config_valid_v1($push,vp3_phone_push_master_v1($testKey));
    $state=!(bool)$c['enabled']?'off':(!$keys?'credential_unavailable':
        (!$fresh?'worker_unavailable':(!$worker['transport_ready']?'transport_unavailable':'ready')));
    return ['state'=>$state,'enabled'=>(bool)$c['enabled'],'ready'=>$state==='ready','revision'=>(int)$c['revision'],
        'subject'=>$c['subject'],'worker'=>$worker,'capacity_skipped_count'=>(int)$c['capacity_skipped_count'],'last_skipped_epoch'=>(int)$c['last_skipped_epoch']];
}

/** Safe holder receipt, never a bearer credential or a private source projection. */
function vp3_phone_push_delivery_public_v1(array $e):array
{
    return ['event_ref'=>$e['event_ref'],'device_id'=>$e['device_id'],'subscription_revision'=>(int)$e['subscription_revision'],
        'state'=>in_array($e['state'],['claimed','sending'],true)?'queued':$e['state'],'attempts'=>(int)$e['attempts'],
        'created_epoch'=>(int)$e['created_epoch'],'expires_epoch'=>(int)$e['expires_epoch'],
        'provider_accepted_epoch'=>(int)$e['provider_accepted_epoch'],'client_observed_epoch'=>(int)$e['client_observed_epoch'],
        'opened_epoch'=>(int)$e['opened_epoch'],'retry_at'=>$e['state']==='retry'?(int)$e['available_epoch']:0,'last_code'=>$e['last_code']];
}

function vp3_phone_push_delivery_view_v1(PDO $pdo,array $d,int $now,?string $testKey=null):array
{
    $s=vp3_phone_push_delivery_status_v1($pdo,$now,$testKey);
    $out=['enabled'=>$s['enabled'],'ready'=>false,'worker'=>$s['worker'],'last_test'=>null];
    if(!vp3_phone_push_delivery_ready_v1($pdo))return $out;
    $subscription=vp3_phone_push_row_v1($pdo,$d);$push=vp3_phone_push_config_v1($pdo);
    $valid=$d['device_status']==='approved'&&(int)$d['expires_at']>$now&&$subscription&&$subscription['state']==='registered'
        &&is_string($subscription['credential_hash'])&&hash_equals($d['token_hash'],$subscription['credential_hash'])
        &&($subscription['expires_epoch']===null||(int)$subscription['expires_epoch']>$now)
        &&$push&&$subscription['key_id']===hash('sha256',$push['public_key']);
    $out['ready']=$s['ready']&&$valid;
    if(!$valid)return $out;
    $q=vp3_phone_sql_v1($pdo,"SELECT * FROM vp3_phone_push_outbox_v1 WHERE owner_user_id=? AND device_id=? AND source_type='test' AND credential_hash=? AND subscription_revision=? AND config_revision=? ORDER BY created_epoch DESC,event_ref DESC LIMIT 1",
        [(int)$d['owner_user_id'],$d['id'],$d['token_hash'],(int)$subscription['revision'],$s['revision']]);
    $e=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if($e)$out['last_test']=vp3_phone_push_delivery_public_v1($e);
    return $out;
}

function vp3_phone_push_delivery_source_v1(array $source,int $now):array
{
    vp3_phone_push_delivery_need_v1(!array_diff(array_keys($source),['type','id','revision','expires_epoch'])
        &&in_array($source['type']??null,['test','private_chat','agent_request'],true)
        &&is_string($source['id']??null)&&preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$source['id'])===1
        &&is_int($source['revision']??null)&&$source['revision']>0&&$source['revision']<=9007199254740990
        &&is_int($source['expires_epoch']??null)&&$source['expires_epoch']>$now&&$source['expires_epoch']<=$now+86400);
    return ['type'=>$source['type'],'id'=>$source['id'],'revision'=>$source['revision'],'expires_epoch'=>$source['expires_epoch']];
}

function vp3_phone_push_delivery_event_source_v1(array $e):array
{return ['type'=>$e['source_type'],'id'=>$e['source_id'],'revision'=>(int)$e['source_revision'],'expires_epoch'=>(int)$e['expires_epoch']];}

/** Acquires the established account→device→subscription→configuration lock order.
 * A caller must already own a transaction. Returning a reason fails closed.
 */
function vp3_phone_push_delivery_context_v1(PDO $pdo,int $owner,string $device,int $now,?string $testKey=null):array
{
    if(!vp3_phone_transaction_active_v1($pdo))throw new LogicException('Notification authority requires a transaction.');
    $account=vp3_phone_authorized_owner_v1($pdo,$owner,true);
    $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?'.vp3_phone_lock_v1($pdo),[$owner,$device]);
    $d=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    $s=$d?vp3_phone_push_row_v1($pdo,$d,true):null;
    $push=vp3_phone_push_config_v1($pdo,true);$c=vp3_phone_push_delivery_config_v1($pdo,true);
    $key=vp3_phone_push_master_v1($testKey);
    $reason=$account===null?'account_unavailable':(!$d||$d['device_status']!=='approved'||(int)$d['expires_at']<=$now?'device_unavailable':
        (!$s||$s['state']!=='registered'||!is_string($s['credential_hash'])||!hash_equals($d['token_hash'],$s['credential_hash'])
        ||$s['expires_epoch']!==null&&(int)$s['expires_epoch']<=$now?'subscription_unavailable':
        (!$push||!$c||!(bool)$c['enabled']?'delivery_disabled':
        (!vp3_phone_push_delivery_subject_v1($c['subject'])||!vp3_phone_push_config_valid_v1($push,$key)?'credential_unavailable':
        ($s['key_id']!==hash('sha256',$push['public_key'])||!is_string($s['payload_hash'])||!is_string($s['subscription_cipher'])?'subscription_unavailable':'')))));
    return ['device'=>$d?:null,'subscription'=>$s,'push'=>$push,'config'=>$c,'key'=>$key,'reason'=>$reason];
}

function vp3_phone_push_delivery_bound_v1(array $e,array $ctx):bool
{
    return $ctx['reason']===''&&hash_equals($e['credential_hash'],$ctx['device']['token_hash'])
        &&(int)$e['subscription_revision']===(int)$ctx['subscription']['revision']
        &&hash_equals($e['key_id'],$ctx['subscription']['key_id'])&&hash_equals($e['payload_hash'],$ctx['subscription']['payload_hash'])
        &&(int)$e['config_revision']===(int)$ctx['config']['revision'];
}

/** Event-only display capability. It cannot authenticate a holder or retrieve a source. */
function vp3_phone_push_delivery_receipt_token_v1(array $e,string $key):string
{
    return vp3_phone_push_b64_v1(hash_hmac('sha256',"vp3-phone-push-display-v1\0".json_encode([
        $e['event_ref'],(int)$e['owner_user_id'],$e['device_id'],$e['credential_hash'],(int)$e['subscription_revision'],
        (int)$e['config_revision'],(int)$e['expires_epoch']],JSON_THROW_ON_ERROR),$key,true));
}

/** Caller supplies the current authorized holder/owner device and a canonical source validator.
 * It may join the source's existing transaction: source and outbox commit together.
 */
function vp3_phone_push_delivery_enqueue_v1(PDO $pdo,array $d,array $source,int $now,callable $sourceCurrent,?string $testKey=null):array
{
    vp3_phone_push_delivery_need_v1(vp3_phone_push_delivery_ready_v1($pdo),503);
    $source=vp3_phone_push_delivery_source_v1($source,$now);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$d,$source,$now,$sourceCurrent,$testKey):array{
        $ctx=vp3_phone_push_delivery_context_v1($pdo,(int)$d['owner_user_id'],$d['id'],$now,$testKey);
        vp3_phone_push_delivery_need_v1($ctx['reason']==='',503);
        vp3_phone_push_delivery_need_v1(isset($d['token_hash'])&&hash_equals($ctx['device']['token_hash'],$d['token_hash']),403);
        $sourceKey=hash('sha256',json_encode([$source['type'],$source['id'],$source['revision']],JSON_THROW_ON_ERROR));
        $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_outbox_v1 WHERE owner_user_id=? AND device_id=? AND source_key=?'.vp3_phone_lock_v1($pdo),
            [(int)$d['owner_user_id'],$d['id'],$sourceKey]);
        $old=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($old){
            vp3_phone_push_delivery_need_v1(hash_equals($old['credential_hash'],$d['token_hash']),409);
            return vp3_phone_push_delivery_public_v1($old);
        }
        vp3_phone_push_delivery_need_v1($sourceCurrent($pdo,$ctx['device'],$source,$now)===true,403);
        if($source['type']==='test'){
            $rows=vp3_phone_sql_v1($pdo,"SELECT device_id,created_epoch FROM vp3_phone_push_outbox_v1 WHERE owner_user_id=? AND source_type='test' AND created_epoch>?",
                [(int)$d['owner_user_id'],$now-3600])->fetchAll(PDO::FETCH_ASSOC);
            $deviceTests=0;$recent=false;
            foreach($rows as $r)if($r['device_id']===$d['id']){$deviceTests++;if((int)$r['created_epoch']>$now-30)$recent=true;}
            vp3_phone_push_delivery_need_v1(!$recent&&$deviceTests<10&&count($rows)<30,429);
        }
        $count=vp3_phone_sql_v1($pdo,"SELECT COUNT(*) FROM vp3_phone_push_outbox_v1 WHERE owner_user_id=? AND device_id=? AND state IN ('queued','retry','claimed','sending') AND expires_epoch>?",
            [(int)$d['owner_user_id'],$d['id'],$now])->fetchColumn();
        vp3_phone_push_delivery_need_v1((int)$count<64,429);
        $e=['event_ref'=>bin2hex(random_bytes(16)),'owner_user_id'=>(int)$d['owner_user_id'],'device_id'=>$d['id'],
            'source_type'=>$source['type'],'source_id'=>$source['id'],'source_revision'=>$source['revision'],'source_key'=>$sourceKey,
            'credential_hash'=>$ctx['device']['token_hash'],'subscription_revision'=>(int)$ctx['subscription']['revision'],
            'key_id'=>$ctx['subscription']['key_id'],'payload_hash'=>$ctx['subscription']['payload_hash'],'config_revision'=>(int)$ctx['config']['revision'],
            'state'=>'queued','attempts'=>0,'available_epoch'=>$now,'expires_epoch'=>$source['expires_epoch'],'created_epoch'=>$now,'updated_epoch'=>$now,
            'provider_accepted_epoch'=>0,'client_observed_epoch'=>0,'opened_epoch'=>0,'last_code'=>''];
        $e['receipt_hash']=hash('sha256',vp3_phone_push_delivery_receipt_token_v1($e,$ctx['key']));
        $columns=array_keys($e);
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_push_outbox_v1('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')',array_values($e));
        return vp3_phone_push_delivery_public_v1($e);
    });
}

/** Internal SQL predicate is never caller input. Preserve an in-flight lease so a
 * late, already-started provider result can be recorded without reviving it.
 */
function vp3_phone_push_delivery_cancel_where_v1(PDO $pdo,string $where,array $params,int $now,string $reason):void
{
    $code=in_array($reason,['permission_changed','configuration_changed','subscription_changed','source_unavailable','delivery_disabled'],true)?$reason:'permission_changed';
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_push_outbox_v1 SET lease_token=CASE WHEN state='sending' THEN lease_token ELSE NULL END,
        lease_expires_epoch=CASE WHEN state='sending' THEN lease_expires_epoch ELSE 0 END,state='cancelled',last_code=?,updated_epoch=?
        WHERE ($where) AND state IN ('queued','retry','claimed','sending')",[$code,$now,...$params]);
}

function vp3_phone_push_delivery_cancel_device_v1(PDO $pdo,array $d,int $now,string $reason='permission_changed'):void
{
    if(!vp3_phone_push_delivery_ready_v1($pdo))return;
    vp3_phone_push_delivery_cancel_where_v1($pdo,'owner_user_id=? AND device_id=?',[(int)$d['owner_user_id'],$d['id']],$now,$reason);
}

function vp3_phone_push_delivery_event_v1(PDO $pdo,string $ref,bool $lock=false):?array
{
    $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_outbox_v1 WHERE event_ref=?'.($lock?vp3_phone_lock_v1($pdo):''),[$ref]);
    $e=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $e?:null;
}

function vp3_phone_push_delivery_terminal_v1(PDO $pdo,array $e,string $state,string $code,int $now):array
{
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_outbox_v1 SET state=?,last_code=?,lease_token=NULL,lease_expires_epoch=0,updated_epoch=? WHERE event_ref=?',
        [$state,$code,$now,$e['event_ref']]);
    return array_replace($e,['state'=>$state,'last_code'=>$code,'lease_token'=>null,'lease_expires_epoch'=>0,'updated_epoch'=>$now]);
}

/** A candidate read does not take the outbox lock before the owner lock.
 * Parallel workers compete under current locks; a loser gets no claim.
 */
function vp3_phone_push_delivery_claim_v1(PDO $pdo,int $now,callable $sourceCurrent,?string $testKey=null):?array
{
    vp3_phone_push_delivery_need_v1(!vp3_phone_transaction_active_v1($pdo),409);
    vp3_phone_push_delivery_need_v1(vp3_phone_push_delivery_ready_v1($pdo),503);
    $q=vp3_phone_sql_v1($pdo,"SELECT event_ref,owner_user_id,device_id FROM vp3_phone_push_outbox_v1
        WHERE (state IN ('queued','retry') AND (available_epoch<=? OR expires_epoch<=?))
        OR (state IN ('claimed','sending') AND lease_expires_epoch<=?) ORDER BY available_epoch,created_epoch,event_ref LIMIT 1",[$now,$now,$now]);
    $hint=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$hint)return null;
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$hint,$now,$sourceCurrent,$testKey):array{
        $ctx=vp3_phone_push_delivery_context_v1($pdo,(int)$hint['owner_user_id'],$hint['device_id'],$now,$testKey);
        $e=vp3_phone_push_delivery_event_v1($pdo,$hint['event_ref'],true);
        if(!$e)return ['state'=>'skipped'];
        if(in_array($e['state'],['claimed','sending'],true)&&(int)$e['lease_expires_epoch']>$now)return ['state'=>'skipped'];
        if(!in_array($e['state'],['queued','retry','claimed','sending'],true))return ['state'=>'skipped'];
        if(!vp3_phone_push_delivery_bound_v1($e,$ctx))return vp3_phone_push_delivery_terminal_v1($pdo,$e,'cancelled','permission_changed',$now);
        // A crash after dispatch may have reached the provider. Never send it again.
        if($e['state']==='sending')return vp3_phone_push_delivery_terminal_v1($pdo,$e,'uncertain','dispatch_outcome_unknown',$now);
        if((int)$e['expires_epoch']<=$now)return vp3_phone_push_delivery_terminal_v1($pdo,$e,'expired','event_expired',$now);
        if((int)$e['client_observed_epoch']>0||(int)$e['opened_epoch']>0)
            return vp3_phone_push_delivery_terminal_v1($pdo,$e,'uncertain','client_observed',$now);
        if((int)$e['attempts']>=VP3_PHONE_PUSH_DELIVERY_MAX_ATTEMPTS_V1)return vp3_phone_push_delivery_terminal_v1($pdo,$e,'failed','attempt_limit',$now);
        if((int)$e['available_epoch']>$now)return ['state'=>'skipped'];
        if($sourceCurrent($pdo,$ctx['device'],vp3_phone_push_delivery_event_source_v1($e),$now)!==true)
            return vp3_phone_push_delivery_terminal_v1($pdo,$e,'cancelled','source_unavailable',$now);
        $lease=bin2hex(random_bytes(16));$until=$now+VP3_PHONE_PUSH_DELIVERY_LEASE_V1;
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_push_outbox_v1 SET state='claimed',lease_token=?,lease_expires_epoch=?,updated_epoch=? WHERE event_ref=?",[$lease,$until,$now,$e['event_ref']]);
        return array_replace($e,['state'=>'claimed','lease_token'=>$lease,'lease_expires_epoch'=>$until,'updated_epoch'=>$now]);
    });
}

/** Final fence, immediately before leaving the transaction for transport. */
function vp3_phone_push_delivery_prepare_v1(PDO $pdo,array $claim,int $now,callable $sourceCurrent,?string $testKey=null):?array
{
    vp3_phone_push_delivery_need_v1(!vp3_phone_transaction_active_v1($pdo),409);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$claim,$now,$sourceCurrent,$testKey):array{
        $ctx=vp3_phone_push_delivery_context_v1($pdo,(int)$claim['owner_user_id'],$claim['device_id'],$now,$testKey);
        $e=vp3_phone_push_delivery_event_v1($pdo,$claim['event_ref'],true);
        if(!$e||$e['state']!=='claimed'||!is_string($e['lease_token'])||!is_string($claim['lease_token']??null)
            ||!hash_equals($e['lease_token'],$claim['lease_token'])||(int)$e['lease_expires_epoch']<=$now)return [];
        if(!vp3_phone_push_delivery_bound_v1($e,$ctx)){vp3_phone_push_delivery_terminal_v1($pdo,$e,'cancelled','permission_changed',$now);return [];}
        if((int)$e['expires_epoch']<=$now){vp3_phone_push_delivery_terminal_v1($pdo,$e,'expired','event_expired',$now);return [];}
        if((int)$e['client_observed_epoch']>0||(int)$e['opened_epoch']>0){vp3_phone_push_delivery_terminal_v1($pdo,$e,'uncertain','client_observed',$now);return [];}
        if($sourceCurrent($pdo,$ctx['device'],vp3_phone_push_delivery_event_source_v1($e),$now)!==true){
            vp3_phone_push_delivery_terminal_v1($pdo,$e,'cancelled','source_unavailable',$now);return [];
        }
        try{
            $saved=json_decode(ai_decrypt_secret_with_key($ctx['subscription']['subscription_cipher'],$ctx['key']),true,16,JSON_THROW_ON_ERROR);
            vp3_phone_push_delivery_need_v1(is_array($saved)&&(int)($saved['owner_user_id']??0)===(int)$e['owner_user_id']
                &&($saved['device_id']??null)===$e['device_id']&&($saved['credential_hash']??null)===$e['credential_hash']
                &&($saved['revision']??null)===(int)$e['subscription_revision']&&($saved['key_id']??null)===$e['key_id']);
            $subscription=vp3_phone_push_parse_v1($saved['subscription']??null,$now);
            vp3_phone_push_delivery_need_v1(hash_equals($e['payload_hash'],hash('sha256',json_encode($subscription,JSON_THROW_ON_ERROR)))
                &&hash_equals($ctx['subscription']['endpoint_hash'],hash('sha256',$subscription['endpoint'])));
            $vapid=json_decode(ai_decrypt_secret_with_key($ctx['push']['private_cipher'],$ctx['key']),true,8,JSON_THROW_ON_ERROR);
            vp3_phone_push_delivery_need_v1(is_array($vapid)&&($vapid['public_key']??null)===$ctx['push']['public_key']
                &&vp3_phone_push_decode_v1($vapid['private_key']??null,32)!==null);
            $vapid['subject']=$ctx['config']['subject'];
            $vapid['config_revision']=(int)$ctx['config']['revision'];
            $token=vp3_phone_push_delivery_receipt_token_v1($e,$ctx['key']);
            vp3_phone_push_delivery_need_v1(hash_equals($e['receipt_hash'],hash('sha256',$token)));
        }catch(Throwable){vp3_phone_push_delivery_terminal_v1($pdo,$e,'failed','credential_unavailable',$now);return [];}
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_push_outbox_v1 SET state='sending',attempts=attempts+1,send_started_epoch=?,lease_expires_epoch=?,updated_epoch=? WHERE event_ref=?",[$now,$now+VP3_PHONE_PUSH_DELIVERY_LEASE_V1,$now,$e['event_ref']]);
        return ['claim'=>array_replace($e,['state'=>'sending','attempts'=>(int)$e['attempts']+1,'send_started_epoch'=>$now]),
            'subscription'=>$subscription,'vapid'=>$vapid,
            'payload'=>json_encode(['v'=>1,'event_ref'=>$e['event_ref'],'receipt_token'=>$token],JSON_THROW_ON_ERROR),
            'options'=>['ttl'=>min(3600,(int)$e['expires_epoch']-$now),'topic'=>$e['event_ref'],'urgency'=>'normal']];
    })?:null;
}

/** Transport exceptions or ambiguous replies must never become successful or retryable. */
function vp3_phone_push_delivery_result_v1(mixed $result):array
{
    $fallback=['state'=>'uncertain','code'=>'transport_outcome_unknown','http_status'=>0,'retry_after'=>0,'dispatched'=>true];
    if(!is_array($result)||!in_array($result['state']??null,['accepted','expired','retry','uncertain','failed'],true)
        ||!is_bool($result['dispatched']??null)||!is_int($result['http_status']??null)||!is_int($result['retry_after']??null)
        ||!is_string($result['code']??null)||preg_match('/^[a-z0-9_]{1,64}$/D',$result['code'])!==1)return $fallback;
    $r=['state'=>$result['state'],'code'=>$result['code'],'http_status'=>$result['http_status'],
        'retry_after'=>max(0,min(86400,$result['retry_after'])),'dispatched'=>$result['dispatched']];
    $http=$r['http_status'];
    if($http!==0&&($http<100||$http>599))return $fallback;
    if($r['state']==='accepted'&&(!$r['dispatched']||$http<200||$http>=300))return $fallback;
    if($r['state']==='expired'&&(!in_array($http,[404,410],true)||!$r['dispatched']))return $fallback;
    if($r['state']==='retry'&&$r['dispatched']&&!in_array($http,[429,503],true))return $fallback;
    $redirect=$http>=300&&$http<400&&$r['code']==='provider_redirect_blocked';
    if($r['state']==='failed'&&$r['dispatched']&&!$redirect&&($http<400||$http>=500||$http===408))return $fallback;
    return $r;
}

function vp3_phone_push_delivery_finish_v1(PDO $pdo,array $claim,mixed $result,int $now,?string $testKey=null):?array
{
    vp3_phone_push_delivery_need_v1(!vp3_phone_transaction_active_v1($pdo),409);
    $r=vp3_phone_push_delivery_result_v1($result);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$claim,$r,$now,$testKey):array{
        $ctx=vp3_phone_push_delivery_context_v1($pdo,(int)$claim['owner_user_id'],$claim['device_id'],$now,$testKey);
        $e=vp3_phone_push_delivery_event_v1($pdo,$claim['event_ref'],true);
        if(!$e)return [];
        if(!in_array($e['state'],['sending','cancelled'],true)||(int)$e['send_started_epoch']===0
            ||!is_string($e['lease_token'])||!is_string($claim['lease_token']??null)||!hash_equals($e['lease_token'],$claim['lease_token']))
            return vp3_phone_push_delivery_public_v1($e);
        $bound=vp3_phone_push_delivery_bound_v1($e,$ctx);
        $state=$r['state'];$code=$r['code'];$available=(int)$e['available_epoch'];
        if($state==='retry'){
            $delays=[30,120,600,1800,3600];
            $available=$now+max($delays[min(4,max(0,(int)$e['attempts']-1))],$r['retry_after']);
            if((int)$e['attempts']>=VP3_PHONE_PUSH_DELIVERY_MAX_ATTEMPTS_V1){$state='failed';$code='attempt_limit';}
            elseif($available>=(int)$e['expires_epoch']){$state='expired';$code='event_expired';}
            if((int)$e['client_observed_epoch']>0||(int)$e['opened_epoch']>0){$state='uncertain';$code='client_observed';}
        }
        if($e['state']==='cancelled'||!$bound){$state='cancelled';$code='permission_changed';}
        if($bound&&$r['state']==='expired'){
            // Exact current subscription only. A late 410 cannot revoke its replacement.
            vp3_phone_push_revoke_v1($pdo,$ctx['device'],$now);
            vp3_phone_push_delivery_cancel_device_v1($pdo,$ctx['device'],$now,'subscription_changed');
            if($e['state']!=='cancelled')$state='expired';
        }
        $accepted=$r['state']==='accepted'&&!(int)$e['provider_accepted_epoch']?$now:(int)$e['provider_accepted_epoch'];
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_outbox_v1 SET state=?,available_epoch=?,last_code=?,provider_state=?,provider_http_status=?,provider_accepted_epoch=?,lease_token=NULL,lease_expires_epoch=0,updated_epoch=? WHERE event_ref=?',
            [$state,$available,$code,$r['state'],$r['http_status'],$accepted,$now,$e['event_ref']]);
        return vp3_phone_push_delivery_public_v1(array_replace($e,['state'=>$state,'available_epoch'=>$available,'last_code'=>$code,'provider_accepted_epoch'=>$accepted]));
    })?:null;
}

/** The CLI MUST preflight transport readiness before calling. No vendor is loaded
 * here, so installation/web APIs continue to work on the existing PHP minimum.
 * clock is solely for deterministic fixtures; transport receives four arguments.
 */
function vp3_phone_push_delivery_run_v1(PDO $pdo,int $limit,callable $sourceCurrent,callable $transport,int $now,?string $testKey=null,?callable $clock=null):array
{
    vp3_phone_push_delivery_need_v1(!vp3_phone_transaction_active_v1($pdo),409);
    vp3_phone_push_delivery_need_v1($limit>=1&&$limit<=25);
    $start=microtime(true);$clock??=static fn():int=>$now+(int)floor(microtime(true)-$start);
    $out=['processed'=>0,'attempted'=>0,'states'=>[]];
    for($i=0;$i<$limit;$i++){
        $claim=vp3_phone_push_delivery_claim_v1($pdo,$clock(),$sourceCurrent,$testKey);
        if($claim===null)break;
        $out['processed']++;
        if($claim['state']!=='claimed'){$out['states'][$claim['state']]=($out['states'][$claim['state']]??0)+1;continue;}
        $send=vp3_phone_push_delivery_prepare_v1($pdo,$claim,$clock(),$sourceCurrent,$testKey);
        if($send===null){$out['states']['fenced']=($out['states']['fenced']??0)+1;continue;}
        if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Notification transport cannot hold database locks.');
        $out['attempted']++;
        try{$result=$transport($send['subscription'],$send['vapid'],$send['payload'],$send['options']);}
        catch(Throwable){$result=null;}
        $finished=vp3_phone_push_delivery_finish_v1($pdo,$send['claim'],$result,$clock(),$testKey);
        $state=$finished['state']??'unavailable';$out['states'][$state]=($out['states'][$state]??0)+1;
        unset($send);
    }
    return $out;
}

/** Narrow, unauthenticated, event-only display acknowledgement. No read or open.
 * A client report is separate from push-service acceptance and from a user tap.
 */
function vp3_phone_push_delivery_receipt_v1(PDO $pdo,array $input,int $now):array
{
    vp3_phone_push_delivery_need_v1(vp3_phone_push_delivery_ready_v1($pdo),503);
    vp3_phone_push_delivery_need_v1(!array_diff(array_keys($input),['event_ref','receipt_token','kind'])
        &&is_string($input['event_ref']??null)&&preg_match('/^[a-f0-9]{32}$/D',$input['event_ref'])===1
        &&vp3_phone_push_decode_v1($input['receipt_token']??null,32)!==null&&($input['kind']??null)==='displayed');
    $hint=vp3_phone_push_delivery_event_v1($pdo,$input['event_ref']);
    vp3_phone_push_delivery_need_v1($hint!==null&&hash_equals($hint['receipt_hash'],hash('sha256',$input['receipt_token'])),404);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$hint,$input,$now):array{
        // Receipts do not need decryption/master-key access; current durable grants suffice.
        $account=vp3_phone_authorized_owner_v1($pdo,(int)$hint['owner_user_id'],true);
        $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?'.vp3_phone_lock_v1($pdo),[(int)$hint['owner_user_id'],$hint['device_id']]);
        $d=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$s=$d?vp3_phone_push_row_v1($pdo,$d,true):null;
        $p=vp3_phone_push_config_v1($pdo,true);$c=vp3_phone_push_delivery_config_v1($pdo,true);
        $e=vp3_phone_push_delivery_event_v1($pdo,$hint['event_ref'],true);
        vp3_phone_push_delivery_need_v1($account!==null&&$d&&$s&&$p&&$c&&$e&&$d['device_status']==='approved'
            &&(int)$d['expires_at']>$now&&$s['state']==='registered'&&$s['credential_hash']===$d['token_hash']
            &&($s['expires_epoch']===null||(int)$s['expires_epoch']>$now)&&(int)$c['enabled']===1
            &&(int)$e['config_revision']===(int)$c['revision']&&$e['credential_hash']===$d['token_hash']
            &&(int)$e['subscription_revision']===(int)$s['revision']&&$e['key_id']===$s['key_id']&&$e['key_id']===hash('sha256',$p['public_key'])
            &&$e['payload_hash']===$s['payload_hash']&&(int)$e['expires_epoch']>$now&&(int)$e['send_started_epoch']>0
            &&in_array($e['state'],['claimed','sending','accepted','uncertain','retry','failed'],true)
            &&hash_equals($e['receipt_hash'],hash('sha256',$input['receipt_token'])),404);
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_outbox_v1 SET client_observed_epoch=CASE WHEN client_observed_epoch=0 THEN ? ELSE client_observed_epoch END,updated_epoch=? WHERE event_ref=?',[$now,$now,$e['event_ref']]);
        if(in_array($e['state'],['claimed','retry'],true))vp3_phone_push_delivery_terminal_v1($pdo,$e,'uncertain','client_observed',$now);
        return ['ok'=>true];
    });
}

/** The API authenticates the holder first. Recheck the supplied credential and
 * current source, then record the tap; the caller maps source to a fixed UI tab.
 */
function vp3_phone_push_delivery_resolve_v1(PDO $pdo,array $d,string $eventRef,int $now,callable $sourceCurrent,?string $testKey=null):array
{
    vp3_phone_push_delivery_need_v1(vp3_phone_push_delivery_ready_v1($pdo),503);
    vp3_phone_push_delivery_need_v1(preg_match('/^[a-f0-9]{32}$/D',$eventRef)===1);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$d,$eventRef,$now,$sourceCurrent,$testKey):array{
        $ctx=vp3_phone_push_delivery_context_v1($pdo,(int)$d['owner_user_id'],$d['id'],$now,$testKey);
        $e=vp3_phone_push_delivery_event_v1($pdo,$eventRef,true);
        vp3_phone_push_delivery_need_v1($e!==null&&(int)$e['owner_user_id']===(int)$d['owner_user_id']&&$e['device_id']===$d['id']
            &&vp3_phone_push_delivery_bound_v1($e,$ctx)&&isset($d['token_hash'])&&hash_equals($d['token_hash'],$ctx['device']['token_hash'])
            &&(int)$e['expires_epoch']>$now&&(int)$e['send_started_epoch']>0
            &&in_array($e['state'],['claimed','sending','accepted','uncertain','retry','failed'],true),404);
        $source=vp3_phone_push_delivery_event_source_v1($e);
        vp3_phone_push_delivery_need_v1($sourceCurrent($pdo,$ctx['device'],$source,$now)===true,404);
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_outbox_v1 SET opened_epoch=CASE WHEN opened_epoch=0 THEN ? ELSE opened_epoch END,updated_epoch=? WHERE event_ref=?',[$now,$now,$e['event_ref']]);
        if(in_array($e['state'],['claimed','retry'],true))$e=vp3_phone_push_delivery_terminal_v1($pdo,$e,'uncertain','client_observed',$now);
        if(!(int)$e['opened_epoch'])$e['opened_epoch']=$now;
        return vp3_phone_push_delivery_public_v1($e)+['source'=>$source];
    });
}

/** Bounded housekeeping; active/uncertain dispatches are retained for diagnosis. */
function vp3_phone_push_delivery_prune_v1(PDO $pdo,int $now,int $limit=100):int
{
    vp3_phone_push_delivery_need_v1($limit>=1&&$limit<=500);
    $refs=vp3_phone_sql_v1($pdo,"SELECT event_ref FROM vp3_phone_push_outbox_v1 WHERE state IN ('accepted','failed','expired','cancelled','uncertain') AND expires_epoch<? ORDER BY expires_epoch,event_ref LIMIT ".$limit,[$now-7*86400])->fetchAll(PDO::FETCH_COLUMN);
    $deleted=0;
    foreach($refs as $ref)$deleted+=vp3_phone_sql_v1($pdo,"DELETE FROM vp3_phone_push_outbox_v1 WHERE event_ref=? AND expires_epoch<? AND state IN ('accepted','failed','expired','cancelled','uncertain')",[$ref,$now-7*86400])->rowCount();
    return $deleted;
}
