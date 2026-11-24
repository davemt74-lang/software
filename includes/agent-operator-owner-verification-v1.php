<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-owner-verify-provider-v1.php';

/**
 * Owner-number possession verification. A committed reservation precedes
 * any provider effect. Exact owner, number revision, operation and service
 * are checked again after I/O. No login, phone call or recording permission.
 */
final class VP3OwnerPhoneVerificationV1
{
    private const TTL=600;
    private const SEND_GAP=660;
    private const MAX_CHECKS=5;
    private const VERIFIED_TTL=7776000; // 90 days
    private PDO $db;
    private VP3OwnerPhoneVerifyProviderV1 $provider;
    private Closure $clock;

    public function __construct(PDO $pdo,VP3OwnerPhoneVerifyProviderV1 $provider,?Closure $clock=null)
    {
        $this->db=$pdo;$this->provider=$provider;$this->clock=$clock??static fn():int=>time();
    }
    public static function ready(PDO $pdo):bool
    {
        try{
            $pdo->query('SELECT id,lock_value FROM phone_operator_owner_verify_mutex_v1 WHERE 1=0');
            $pdo->query('SELECT owner_user_id,phone_revision,scope_hash,destination_hash,challenge_id,operation_id,verification_sid,state,created_epoch,expires_epoch,attempts,verified_epoch FROM phone_operator_owner_verifications_v1 WHERE 1=0');
            $pdo->query('SELECT id,owner_user_id,destination_hash,created_epoch FROM phone_operator_owner_verify_sends_v1 WHERE 1=0');
            return (int)$pdo->query('SELECT COUNT(*) FROM phone_operator_owner_verify_mutex_v1 WHERE id=1')->fetchColumn()===1;
        }catch(PDOException){return false;}
    }
    public static function install(PDO $pdo):void
    {
        if($pdo->inTransaction())throw new RuntimeException('Install verification outside a transaction.');
        $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if(!$mysql&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')throw new RuntimeException('Unsupported database.');
        $owner=$mysql?'INT UNSIGNED':'INTEGER';$suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin':'';
        $pdo->exec('CREATE TABLE IF NOT EXISTS phone_operator_owner_verify_mutex_v1(id INTEGER PRIMARY KEY,lock_value INTEGER NOT NULL)'.$suffix);
        $pdo->exec(($mysql?'INSERT IGNORE':'INSERT OR IGNORE').' INTO phone_operator_owner_verify_mutex_v1(id,lock_value) VALUES(1,0)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_owner_verifications_v1(
            owner_user_id $owner PRIMARY KEY,phone_revision BIGINT NOT NULL,scope_hash CHAR(64) NOT NULL,
            destination_hash CHAR(64) NOT NULL,challenge_id CHAR(32) NOT NULL,operation_id CHAR(32) NOT NULL,
            verification_sid VARCHAR(34) NOT NULL,state VARCHAR(24) NOT NULL,created_epoch BIGINT NOT NULL,
            expires_epoch BIGINT NOT NULL,attempts INTEGER NOT NULL,verified_epoch BIGINT NOT NULL,
            FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        )".$suffix);
        // Short retention rate evidence must survive deletion/recreation of an
        // account until the send budget window expires. No raw phone or code.
        $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_owner_verify_sends_v1(
            id CHAR(32) PRIMARY KEY,owner_user_id $owner NOT NULL,destination_hash CHAR(64) NOT NULL,created_epoch BIGINT NOT NULL
        )".$suffix);
        if(!self::ready($pdo))throw new RuntimeException('Verification schema unavailable.');
    }
    private static function require(bool $ok,string $message='Phone verification is unavailable.'):void
    {if(!$ok)throw new DomainException($message);}
    private function now():int
    {
        $t=($this->clock)();self::require(is_int($t)&&$t>0&&$t<4102444800-self::VERIFIED_TTL);return $t;
    }
    private function sql(string $q,array $args=[]):PDOStatement
    {$s=$this->db->prepare($q);$s->execute($args);return $s;}
    private function row(int $owner):?array
    {$r=$this->sql('SELECT * FROM phone_operator_owner_verifications_v1 WHERE owner_user_id=?',[$owner])->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;}
    private function tx(callable $fn):mixed
    {
        self::require(!$this->db->inTransaction()&&self::ready($this->db));
        $this->db->beginTransaction();
        try{
            self::require($this->db->exec('UPDATE phone_operator_owner_verify_mutex_v1 SET lock_value=1-lock_value WHERE id=1')===1);
            $out=$fn();$this->db->commit();return $out;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function revision(mixed $value):int
    {
        self::require(is_string($value)&&preg_match('/\A[1-9][0-9]{0,14}\z/D',$value)===1,'Refresh Account settings before verification.');
        return (int)$value;
    }
    private function bind(array $user,int $revision,bool $lock):array
    {
        $p=VP3OperatorOwnerPhoneV1::verificationBinding($this->db,$user,$lock);
        self::require($p['phone_revision']===$revision&&$p['phone_e164']!=='','Your saved phone number changed. Refresh Account settings.');
        return $p+['scope_hash'=>$this->provider->scope(),'destination_hash'=>$this->provider->destination($p['phone_e164'])];
    }
    private static function matches(?array $r,array $p):bool
    {
        return $r!==null&&(int)$r['phone_revision']===$p['phone_revision']
            &&hash_equals($r['scope_hash'],$p['scope_hash'])&&hash_equals($r['destination_hash'],$p['destination_hash']);
    }
    private function currentState(?array $r,array $p,int $now):string
    {
        if(!self::matches($r,$p))return 'unverified';
        if($r['state']==='verified'){
            return (int)$r['verified_epoch']>0&&(int)$r['verified_epoch']<=$now
                &&(int)$r['verified_epoch']+self::VERIFIED_TTL>$now?'verified':'expired';
        }
        if((int)$r['created_epoch']>$now||(int)$r['expires_epoch']<=$now)return 'expired';
        return in_array($r['state'],['sending','pending','checking','unresolved','exhausted'],true)?$r['state']:'unresolved';
    }
    public function status(array $user,mixed $revision):array
    {
        $rev=self::revision($revision);$p=$this->bind($user,$rev,false);
        $now=$this->now();$r=$this->row($p['owner']);$state=$this->currentState($r,$p,$now);
        return ['phone_revision'=>$rev,'state'=>$state,'verified'=>$state==='verified',
            'verified_epoch'=>$state==='verified'?(int)$r['verified_epoch']:0,
            'expires_epoch'=>$state==='pending'?(int)$r['expires_epoch']:0,
            'can_check'=>$state==='pending'&&(int)$r['attempts']<self::MAX_CHECKS,
            'can_send'=>in_array($state,['unverified','expired'],true),'execution_enabled'=>false];
    }
    /** Explicit request only. Start budget is committed even if send outcome is unknown. */
    public function start(array $user,mixed $revision):array
    {
        $rev=self::revision($revision);
        $reservation=$this->tx(function()use($user,$rev){
            $now=$this->now();$p=$this->bind($user,$rev,true);
            $old=$this->row($p['owner']);
            self::require(in_array($this->currentState($old,$p,$now),['unverified','expired'],true),
                'A verification is already active or verified. Wait for it to expire before requesting a new code.');
            // Clock rollback must not reset the limiter. One durable global
            // mutex serializes account, destination and installation budgets.
            self::require(!(int)$this->sql('SELECT COUNT(*) FROM phone_operator_owner_verify_sends_v1 WHERE created_epoch>?',[$now])->fetchColumn());
            $this->sql('DELETE FROM phone_operator_owner_verify_sends_v1 WHERE created_epoch<=?',[$now-86400]);
            $counts=$this->sql('SELECT COUNT(*) AS total,
                SUM(CASE WHEN owner_user_id=? THEN 1 ELSE 0 END) AS owner_count,
                SUM(CASE WHEN destination_hash=? THEN 1 ELSE 0 END) AS destination_count,
                SUM(CASE WHEN created_epoch>? THEN 1 ELSE 0 END) AS minute_count,
                SUM(CASE WHEN created_epoch>? AND (owner_user_id=? OR destination_hash=?) THEN 1 ELSE 0 END) AS recent
                FROM phone_operator_owner_verify_sends_v1',
                [$p['owner'],$p['destination_hash'],$now-60,$now-self::SEND_GAP,$p['owner'],$p['destination_hash']])->fetch(PDO::FETCH_ASSOC);
            self::require((int)$counts['total']<50&&(int)$counts['owner_count']<3&&(int)$counts['destination_count']<3
                &&(int)$counts['minute_count']<5&&(int)$counts['recent']===0,
                'Verification send limit reached. Try again later.');
            $id=bin2hex(random_bytes(16));$op=bin2hex(random_bytes(16));
            $this->sql('INSERT INTO phone_operator_owner_verify_sends_v1(id,owner_user_id,destination_hash,created_epoch) VALUES(?,?,?,?)',[$id,$p['owner'],$p['destination_hash'],$now]);
            // Preserve the rate record even when a different number revision
            // replaces the single current challenge.
            $this->sql('DELETE FROM phone_operator_owner_verifications_v1 WHERE owner_user_id=?',[$p['owner']]);
            $this->sql("INSERT INTO phone_operator_owner_verifications_v1(owner_user_id,phone_revision,scope_hash,destination_hash,challenge_id,operation_id,verification_sid,state,created_epoch,expires_epoch,attempts,verified_epoch)
                VALUES(?,?,?,?,?,?,'','sending',?,?,0,0)",[$p['owner'],$rev,$p['scope_hash'],$p['destination_hash'],$id,$op,$now,$now+self::TTL]);
            return $p+['challenge_id'=>$id,'operation_id'=>$op,'created_epoch'=>$now];
        });
        try{
            $r=$this->provider->start($reservation['phone_e164'],$reservation['created_epoch'],$this->now());
        }catch(Throwable){$r=null;}
        return $this->finish($user,$rev,$reservation,'sending',$r);
    }
    /** Reserve each code check before external I/O. No code is persisted. */
    public function check(array $user,mixed $revision,#[\SensitiveParameter] mixed $code):array
    {
        $rev=self::revision($revision);
        self::require(is_string($code)&&preg_match('/\A[0-9]{4,10}\z/D',$code)===1,'Enter the SMS verification code.');
        $reservation=$this->tx(function()use($user,$rev){
            $p=$this->bind($user,$rev,true);$now=$this->now();$r=$this->row($p['owner']);
            self::require($this->currentState($r,$p,$now)==='pending'&&(int)$r['attempts']<self::MAX_CHECKS
                &&preg_match('/\AVE[a-fA-F0-9]{32}\z/D',$r['verification_sid'])===1,'There is no current code to check.');
            $op=bin2hex(random_bytes(16));
            $this->sql("UPDATE phone_operator_owner_verifications_v1 SET state='checking',operation_id=?,attempts=attempts+1 WHERE owner_user_id=?",[$op,$p['owner']]);
            return $p+['challenge_id'=>$r['challenge_id'],'operation_id'=>$op,'verification_sid'=>$r['verification_sid']];
        });
        try{$r=$this->provider->check($reservation['phone_e164'],$reservation['verification_sid'],$code);}
        catch(Throwable){$r=null;}
        return $this->finish($user,$rev,$reservation,'checking',$r);
    }
    private function finish(array $user,int $rev,array $res,string $expected,?array $receipt):array
    {
        $this->tx(function()use($user,$rev,$res,$expected,$receipt){
            $p=$this->bind($user,$rev,true);$now=$this->now();$row=$this->row($p['owner']);
            self::require(self::matches($row,$p)&&$p['owner']===$res['owner']
                &&hash_equals($row['challenge_id'],$res['challenge_id'])&&hash_equals($row['operation_id'],$res['operation_id'])
                &&$row['state']===$expected&&$now>=(int)$row['created_epoch'],'Verification changed. Refresh Account settings.');
            $state='unresolved';$sid=$row['verification_sid'];$verified=0;
            if($now>=(int)$row['expires_epoch'])$state='unresolved';
            elseif($receipt!==null&&$expected==='sending'&&$receipt['status']==='pending'){
                $state='pending';$sid=$receipt['sid'];
            }elseif($receipt!==null&&$expected==='checking'&&$receipt['sid']===$sid){
                if($receipt['status']==='approved'){$state='verified';$verified=$now;}
                elseif($receipt['status']==='pending'&&(int)$row['attempts']<self::MAX_CHECKS)$state='pending';
                elseif(in_array($receipt['status'],['pending','max_attempts_reached'],true))$state='exhausted';
            }
            // A missing/ambiguous response, including Verify's post-approval
            // 404, cannot be promoted to success or automatically retried.
            $this->sql('UPDATE phone_operator_owner_verifications_v1 SET state=?,verification_sid=?,verified_epoch=? WHERE owner_user_id=?',
                [$state,$sid,$verified,$p['owner']]);
        });
        return $this->status($user,(string)$rev);
    }
}
