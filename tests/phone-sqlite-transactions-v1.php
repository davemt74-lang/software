<?php
declare(strict_types=1);

require_once __DIR__.'/phone-push-delivery-fixture-v1.php';
require_once dirname(__DIR__).'/includes/phone-push-worker-v1.php';

/** Reproduce PHP8.1/8.3 PDO's reporting of raw BEGIN on any test runtime.
 * Only reporting changes: SQLite still executes every real BEGIN/write/lock/
 * COMMIT/ROLLBACK. Native beginTransaction remains reported and caller-owned.
 */
final class PhoneLegacySQLiteTransactionPDO extends PDO
{
    private bool $nativeOwner=false;
    public bool $failCommit=false;
    public function inTransaction():bool{return $this->nativeOwner&&parent::inTransaction();}
    public function beginTransaction():bool
    {$ok=parent::beginTransaction();$this->nativeOwner=$ok;return $ok;}
    public function commit():bool
    {$ok=parent::commit();if($ok)$this->nativeOwner=false;return $ok;}
    public function rollBack():bool
    {$ok=parent::rollBack();if($ok)$this->nativeOwner=false;return $ok;}
    public function exec(string $statement):int|false
    {
        if($this->failCommit&&strtoupper(trim($statement))==='COMMIT'){
            $this->failCommit=false;throw new RuntimeException('fixture commit failed',597);
        }
        return parent::exec($statement);
    }
}

function phone_tx_logic_reject(callable $fn,string $label):void
{
    try{$fn();}catch(LogicException){ppd_check(true,$label);return;}
    throw new RuntimeException('Expected transaction guard: '.$label);
}

$path=tempnam(sys_get_temp_dir(),'vp3_phone_tx_');
try{
    $pdo=new PhoneLegacySQLiteTransactionPDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $GLOBALS['ppd_db']=$pdo;$pdo->exec('PRAGMA foreign_keys=ON');
    $other=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('PRAGMA busy_timeout=0');
    $pdo->exec('CREATE TABLE phone_tx_fixture(id INT PRIMARY KEY)');
    ppd_check(!vp3_phone_transaction_active_v1($pdo)&&!vp3_phone_transaction_active_v1($other),'new connections have independent inactive transaction state');
    vp3_phone_transaction_v1($pdo,static function()use($pdo,$other):array{
        ppd_check(!$pdo->inTransaction()&&vp3_phone_transaction_active_v1($pdo),'raw BEGIN is tracked even when legacy PDO reports false');
        ppd_check(!vp3_phone_transaction_active_v1($other),'manual ownership is isolated to its PDO connection');
        try{$other->exec('INSERT INTO phone_tx_fixture VALUES(99)');throw new RuntimeException('Competing writer was admitted.');}
        catch(PDOException $e){ppd_check(str_contains(strtolower($e->getMessage()),'locked'),'BEGIN IMMEDIATE still excludes a competing writer');}
        $pdo->exec('INSERT INTO phone_tx_fixture VALUES(1)');
        $out=vp3_phone_transaction_v1($pdo,static function()use($pdo):array{$pdo->exec('INSERT INTO phone_tx_fixture VALUES(2)');return ['joined'=>true];});
        ppd_check($out['joined']&&vp3_phone_transaction_active_v1($pdo),'nested helper joins without committing the owning transaction');
        ppd_check((int)$other->query('SELECT COUNT(*) FROM phone_tx_fixture')->fetchColumn()===0,'neither nested nor outer write is visible before outer commit');
        return [];
    });
    ppd_check(!vp3_phone_transaction_active_v1($pdo)&&(int)$other->query('SELECT COUNT(*) FROM phone_tx_fixture')->fetchColumn()===2,'outer commit clears tracking and publishes both writes');
    $failure=new RuntimeException('fixture source failure',598);
    try{
        vp3_phone_transaction_v1($pdo,static function()use($pdo,$failure):array{
            $pdo->exec('INSERT INTO phone_tx_fixture VALUES(3)');
            return vp3_phone_transaction_v1($pdo,static function()use($pdo,$failure):array{$pdo->exec('INSERT INTO phone_tx_fixture VALUES(4)');throw $failure;});
        });
    }catch(RuntimeException $e){ppd_check($e===$failure,'nested failure preserves the original exception object');}
    ppd_check(!vp3_phone_transaction_active_v1($pdo)&&(int)$other->query('SELECT COUNT(*) FROM phone_tx_fixture')->fetchColumn()===2,'outer rollback clears tracking and discards all nested writes');
    $pdo->failCommit=true;
    ppd_reject(597,fn()=>vp3_phone_transaction_v1($pdo,static function()use($pdo):array{$pdo->exec('INSERT INTO phone_tx_fixture VALUES(5)');return [];}),'commit failure is preserved after a confirmed rollback');
    ppd_check(!vp3_phone_transaction_active_v1($pdo)&&(int)$other->query('SELECT COUNT(*) FROM phone_tx_fixture')->fetchColumn()===2,'failed commit leaves no write or stale manual ownership');
    $pdo->beginTransaction();
    vp3_phone_transaction_v1($pdo,static function()use($pdo):array{$pdo->exec('INSERT INTO phone_tx_fixture VALUES(6)');return [];});
    ppd_check($pdo->inTransaction()&&vp3_phone_transaction_active_v1($pdo),'native caller transaction remains active and caller-owned');
    $pdo->rollBack();
    ppd_check(!vp3_phone_transaction_active_v1($pdo)&&(int)$other->query('SELECT COUNT(*) FROM phone_tx_fixture')->fetchColumn()===2,'native caller can roll back a joined Phone operation');

    // Real source/outbox helpers now execute through the legacy-reporting PDO.
    ppd_schema($pdo);vp3_phone_push_delivery_install_v1($pdo);
    $key=random_bytes(32);$now=time();$admin=['role'=>'admin'];
    vp3_phone_push_configure_v1($pdo,$admin,true,$now,$key);
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:fixture@example.test','revision'=>1],$now,$key);
    $device=ppd_make($pdo,1,'Legacy SQLite phone');ppd_subscribe($pdo,$device,$now,$key);
    $sourceId=bin2hex(random_bytes(16));$outboxRef=null;$networkCalls=0;$factoryCalls=0;
    try{
        vp3_phone_transaction_v1($pdo,static function()use($pdo,$device,$sourceId,$key,$now,&$outboxRef,&$networkCalls,&$factoryCalls):array{
            vp3_phone_account_v1($pdo,1,true);$d=vp3_phone_row_v1($pdo,1,$device['device']['id']);
            $source=ppd_source($pdo,$device,$now,'private_chat',$sourceId);
            $event=vp3_phone_push_delivery_enqueue_v1($pdo,$d,$source,$now,'ppd_source_current',$key);$outboxRef=$event['event_ref'];
            ppd_check(vp3_phone_transaction_active_v1($pdo)&&$event['state']==='queued','real nested push enqueue retains the source transaction under legacy PDO');
            vp3_phone_push_delivery_capacity_skipped_v1($pdo,$now);
            phone_tx_logic_reject(fn()=>vp3_phone_push_delivery_install_v1($pdo),'delivery migration refuses a tracked manual transaction');
            phone_tx_logic_reject(fn()=>vp3_phone_push_vapid_cache_get_v1($pdo,[],'https://web.push.apple.com',$now,
                static function()use(&$factoryCalls):array{$factoryCalls++;return [];},$key),'VAPID cache refuses source locks before invoking its signer');
            ppd_reject(409,fn()=>vp3_phone_push_delivery_run_v1($pdo,1,'ppd_source_current',
                static function()use(&$networkCalls):array{$networkCalls++;return ppd_accepted();},$now,$key),'queue worker refuses a manually active source transaction');
            phone_tx_logic_reject(fn()=>vp3_phone_push_worker_send_v1($pdo,[],[],'{}',[],
                static function()use(&$networkCalls):array{$networkCalls++;return ppd_accepted();}),'production transport refuses tracked source locks before preflight or exchange');
            throw new RuntimeException('fixture source rolled back',599);
        });
    }catch(RuntimeException $e){ppd_check($e->getCode()===599,'source failure propagates after exercising transaction fences');}
    ppd_check(!vp3_phone_transaction_active_v1($pdo)&&$networkCalls===0&&$factoryCalls===0,'rollback leaves no active registry and no signer or network side effect');
    ppd_check(!(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM push_fixture_sources WHERE source_id=?',[$sourceId])->fetchColumn()
        &&!(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_push_outbox_v1 WHERE event_ref=?',[$outboxRef])->fetchColumn()
        &&(int)vp3_phone_push_delivery_config_v1($pdo)['capacity_skipped_count']===0,'source, outbox and aggregate telemetry roll back atomically');
    $source=ppd_source($pdo,$device,$now);$event=ppd_enqueue($pdo,$device,$source,$now,$key);
    $result=ppd_run($pdo,$now,$key,static function()use($pdo,&$networkCalls):array{
        ppd_check(!vp3_phone_transaction_active_v1($pdo),'subsequent provider call occurs only after source and queue locks are released');
        $networkCalls++;return ppd_accepted();
    });
    ppd_check($result['attempted']===1&&$networkCalls===1&&vp3_phone_push_delivery_event_v1($pdo,$event['event_ref'])['state']==='accepted',
        'connection remains usable after rollback for one successful notification attempt');
    echo 'PASS Phone SQLite transaction compatibility: '.$GLOBALS['ppd_checks'].' checks; PHP '.PHP_VERSION."\n";
}finally{unset($GLOBALS['ppd_db']);$pdo=null;$other=null;if($path!==false)@unlink($path);}
