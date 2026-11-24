<?php
declare(strict_types=1);

/**
 * Phase 8A.4A: Cloud-side authority for the 8A.2 wire contract.
 * Internal, non-networked foundation only. No endpoint, dialer, real credential
 * provisioning, call policy or authorization to execute a telephone call.
 */
final class VP3OperatorWorkerAuthority8A4
{
    private const PROTOCOL = 'vp3.operator.worker.v1';
    private const CAPS = ['session.recover', 'session.simulate'];
    private const MAX = 9007199254740991;
    private const CLOCK_MAX = 4102444800;
    private array $credentials = [];
    private PDO $db;
    private int $reportMaxAge;

    private static function require(bool $condition): void
    {
        if (!$condition) throw new DomainException('Operator worker authority rejected the request.');
    }
    private static function integer(mixed $v, int $min=1, int $max=self::MAX): bool
    {
        return is_int($v) && $v >= $min && $v <= $max;
    }
    private static function hex(mixed $v, int $length=32): bool
    {
        return is_string($v) && strlen($v)===$length && preg_match('/\A[0-9a-f]+\z/D',$v)===1;
    }
    private static function worker(mixed $v): bool
    {
        return is_string($v) && preg_match('/\A[A-Za-z0-9_-]{3,64}\z/D',$v)===1;
    }
    private static function clock(int $now): void
    {
        self::require(self::integer($now,1,self::CLOCK_MAX-90));
    }
    private static function caps(mixed $v): bool
    {
        if (!is_array($v) || !array_is_list($v)) return false;
        return $v===[] || $v===['session.recover'] || $v===['session.simulate'] || $v===self::CAPS;
    }
    private static function canonical(array $payload): string
    {
        ksort($payload,SORT_STRING);
        return json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    private static function frame(string $raw): array
    {
        self::require(strlen($raw)>=2 && strlen($raw)<=4096);
        try {$v=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}
        catch (JsonException $e) {throw new DomainException('Invalid worker frame.');}
        self::require(is_array($v) && !array_is_list($v) && self::canonical($v)===$raw);
        return $v;
    }
    private static function sign(string $body,string $secret,string $direction): string
    {
        return hash_hmac('sha256',self::PROTOCOL.'/'.$direction."\0".$body,$secret);
    }

    /** Inventory comes only from trusted server-side test/deployment code, never a request.
     * A real pairing/provisioning adapter and secret storage are separate gates.
     */
    public function __construct(PDO $db, #[\SensitiveParameter] array $inventory, int $reportMaxAge=90)
    {
        self::require(PHP_INT_SIZE>=8 && $db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION);
        self::require(in_array($db->getAttribute(PDO::ATTR_DRIVER_NAME),['sqlite','mysql'],true));
        self::require(count($inventory)<=128 && $reportMaxAge>=1 && $reportMaxAge<=90);
        $this->reportMaxAge=$reportMaxAge;
        $keys=[];$identities=[];
        foreach ($inventory as $c) {
            self::require(is_array($c) && self::hex($c['credential_id']??null)
                && self::integer($c['owner_id']??null) && self::hex($c['device_id']??null)
                && self::worker($c['worker_id']??null) && is_string($c['secret']??null)
                && strlen($c['secret'])===32 && count(array_unique(str_split($c['secret'])))>1
                && self::caps($c['capabilities']??null)
                && in_array($c['route']??null,['homeserver','cloud'],true));
            $id=$c['credential_id'];$finger=hash('sha256',$c['secret']);
            $binding=$c['owner_id'].':'.$c['device_id'].':'.$c['worker_id'];
            self::require(!isset($this->credentials[$id])
                && !isset($keys[$finger]) && !isset($identities[$binding]));
            $keys[$finger]=true;$identities[$binding]=true;$this->credentials[$id]=$c;
        }
        $this->db=$db;
    }

    /** Explicit install only; caller must use a database controlled by VP3. */
    public static function install(PDO $db): void
    {
        self::require(!$db->inTransaction() && $db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION);
        $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        self::require(in_array($driver,['sqlite','mysql'],true));
        $suffix=$driver==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin':'';
        $db->exec('CREATE TABLE IF NOT EXISTS operator_worker_mutex_8a4 (id INTEGER PRIMARY KEY, lock_value INTEGER NOT NULL)'.$suffix);
        $db->exec(($driver==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO operator_worker_mutex_8a4(id,lock_value) VALUES(1,0)');
        $db->exec('CREATE TABLE IF NOT EXISTS operator_worker_registry_8a4 (
            credential_id CHAR(32) PRIMARY KEY, owner_id BIGINT NOT NULL, device_id CHAR(32) NOT NULL,
            worker_id VARCHAR(64) NOT NULL, route VARCHAR(16) NOT NULL, key_fingerprint CHAR(64) NOT NULL,
            boot_id CHAR(32) NOT NULL, generation BIGINT NOT NULL, seq BIGINT NOT NULL,
            last_seen BIGINT NOT NULL, lease_until BIGINT NOT NULL, state VARCHAR(16) NOT NULL,
            request_hash CHAR(64) NOT NULL, receipt VARCHAR(4096) NOT NULL,
            UNIQUE(key_fingerprint)
        )'.$suffix);
        $db->exec('CREATE TABLE IF NOT EXISTS operator_worker_boots_8a4 (
            credential_id CHAR(32) NOT NULL, boot_id CHAR(32) NOT NULL, PRIMARY KEY(credential_id,boot_id)
        )'.$suffix);
        $db->exec('CREATE TABLE IF NOT EXISTS operator_worker_revoked_8a4 (
            credential_id CHAR(32) PRIMARY KEY, owner_id BIGINT NOT NULL, revoked_at BIGINT NOT NULL
        )'.$suffix);
        $db->exec('CREATE TABLE IF NOT EXISTS operator_worker_claims_8a4 (
            owner_id BIGINT NOT NULL, occurrence_id CHAR(32) NOT NULL, claim_id CHAR(32) NOT NULL UNIQUE,
            credential_id CHAR(32) NOT NULL, boot_id CHAR(32) NOT NULL, generation BIGINT NOT NULL,
            created_at BIGINT NOT NULL, lease_until BIGINT NOT NULL, state VARCHAR(16) NOT NULL,
            PRIMARY KEY(owner_id,occurrence_id)
        )'.$suffix);
    }

    private function tx(callable $f): mixed
    {
        // One short serialization fence covers first registration, generation
        // changes, selection, revocation and exclusive simulated claims.
        self::require(!$this->db->inTransaction());
        $this->db->beginTransaction();
        try {
            self::require($this->db->exec('UPDATE operator_worker_mutex_8a4 SET lock_value=1-lock_value WHERE id=1')===1);
            $v=$f();
            $this->db->commit();
            return $v;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
    private function sql(string $q,array $p=[]): PDOStatement
    {
        $s=$this->db->prepare($q);$s->execute($p);return $s;
    }
    private function row(string $q,array $p=[]): ?array
    {
        $v=$this->sql($q,$p)->fetch(PDO::FETCH_ASSOC);return $v===false?null:$v;
    }
    private function registration(string $id): ?array
    {
        return $this->row('SELECT * FROM operator_worker_registry_8a4 WHERE credential_id=?',[$id]);
    }
    private function revoked(string $id): bool
    {
        return $this->row('SELECT credential_id FROM operator_worker_revoked_8a4 WHERE credential_id=?',[$id])!==null;
    }
    private static function binding(array $r,array $c): bool
    {
        return (int)$r['owner_id']===$c['owner_id'] && $r['device_id']===$c['device_id']
            && $r['worker_id']===$c['worker_id'] && $r['route']===$c['route']
            && hash_equals($r['key_fingerprint'],hash('sha256',$c['secret']));
    }

    public function receive(string $body,string $mac,int $now,?callable $authorize=null): array
    {
        self::clock($now);
        $p=self::frame($body);
        $fields=['action','boot_id','capabilities','credential_id','device_id','generation','nonce','owner_id','protocol','sent_at','sequence','worker_id'];
        self::require(array_keys($p)===$fields && self::hex($p['credential_id'])
            && isset($this->credentials[$p['credential_id']]) && self::hex($mac,64));
        $id=$p['credential_id'];$c=$this->credentials[$id];
        self::require(hash_equals(self::sign($body,$c['secret'],'request'),$mac));
        self::require($p['protocol']===self::PROTOCOL && in_array($p['action'],['register','heartbeat','retire'],true)
            && $p['owner_id']===$c['owner_id'] && $p['device_id']===$c['device_id'] && $p['worker_id']===$c['worker_id']
            && self::hex($p['boot_id']) && self::hex($p['nonce']) && self::integer($p['sequence'])
            && self::integer($p['generation'],0) && self::integer($p['sent_at'],1,self::CLOCK_MAX)
            && $p['sent_at']>=$now-30 && $p['sent_at']<=$now+5 && self::caps($p['capabilities'])
            && !array_diff($p['capabilities'],$c['capabilities']));
        $register=$p['action']==='register';$retire=$p['action']==='retire';
        self::require($register?($p['sequence']===1 && $p['generation']===0):($p['sequence']>=2 && $p['generation']>=1));
        self::require(!$retire || $p['capabilities']===[]);
        $digest=hash('sha256',$body);
        return $this->tx(function() use($p,$id,$c,$now,$digest,$register,$retire,$authorize) {
            // Internal production adapter rechecks paired authority under the same mutex.
            // No public request may supply this callback.
            if ($authorize!==null) $authorize();
            self::require(!$this->revoked($id));
            $r=$this->registration($id);
            if ($r!==null) {
                self::require(self::binding($r,$c) && $now>=(int)$r['last_seen']);
                if ($r['boot_id']===$p['boot_id'] && (int)$r['seq']===$p['sequence']) {
                    self::require(hash_equals($r['request_hash'],$digest)
                        && ($r['state']==='retired' || (int)$r['lease_until']>$now));
                    return ['body'=>$r['receipt'],'signature'=>self::sign($r['receipt'],$c['secret'],'response')];
                }
            }
            if ($register) {
                // Never reuse a secret across credential IDs, including after rotation.
                self::require($this->row('SELECT credential_id FROM operator_worker_registry_8a4 WHERE key_fingerprint=? AND credential_id<>?',
                    [hash('sha256',$c['secret']),$id])===null);
                self::require($r===null || $r['state']!=='reporting' || (int)$r['lease_until']<=$now);
                // A different credential may not inherit the same worker's active identity.
                // Rotation requires explicit revocation of the old credential, not merely a new key.
                $aliases=$this->sql('SELECT credential_id FROM operator_worker_registry_8a4 WHERE owner_id=? AND device_id=? AND worker_id=? AND credential_id<>?',
                    [$c['owner_id'],$c['device_id'],$c['worker_id'],$id])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($aliases as $alias) self::require($this->revoked($alias));
                self::require($this->row('SELECT boot_id FROM operator_worker_boots_8a4 WHERE credential_id=? AND boot_id=?',[$id,$p['boot_id']])===null);
                self::require((int)$this->sql('SELECT COUNT(*) FROM operator_worker_boots_8a4 WHERE credential_id=?',[$id])->fetchColumn()<4096);
                $generation=$r===null?1:(int)$r['generation']+1;
                self::require(self::integer($generation));
                $this->sql('INSERT INTO operator_worker_boots_8a4(credential_id,boot_id) VALUES(?,?)',[$id,$p['boot_id']]);
            } else {
                self::require($r!==null && $r['state']==='reporting' && (int)$r['lease_until']>$now
                    && $r['boot_id']===$p['boot_id'] && (int)$r['generation']===$p['generation']
                    && (int)$r['seq']+1===$p['sequence']);
                $generation=$p['generation'];
            }
            $lease=$retire?0:$now+90;
            $answer=$p;unset($answer['sent_at']);
            $answer['generation']=$generation;$answer['request_sha256']=$digest;$answer['received_at']=$now;
            $answer['lease_until']=$lease;$answer['status']=$register?'registered':($retire?'retired':'reporting');
            $answer['execution_enabled']=false;$answer['live_certified']=false;
            $receipt=self::canonical($answer);
            $values=[$p['boot_id'],$generation,$p['sequence'],$now,$lease,$retire?'retired':'reporting',$digest,$receipt];
            if ($r===null) {
                $this->sql('INSERT INTO operator_worker_registry_8a4(boot_id,generation,seq,last_seen,lease_until,state,request_hash,receipt,credential_id,owner_id,device_id,worker_id,route,key_fingerprint) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array_merge($values,[$id,$c['owner_id'],$c['device_id'],$c['worker_id'],$c['route'],hash('sha256',$c['secret'])]));
            } else {
                $this->sql('UPDATE operator_worker_registry_8a4 SET boot_id=?,generation=?,seq=?,last_seen=?,lease_until=?,state=?,request_hash=?,receipt=? WHERE credential_id=?',array_merge($values,[$id]));
            }
            $this->reconcile($c['owner_id'],$now);
            return ['body'=>$receipt,'signature'=>self::sign($receipt,$c['secret'],'response')];
        });
    }

    private function available(array $r,int $owner,int $now,string $cap): bool
    {
        $id=$r['credential_id'];$c=$this->credentials[$id]??null;
        if ($c===null || $c['owner_id']!==$owner || !self::binding($r,$c) || $this->revoked($id)
            || $r['state']!=='reporting' || (int)$r['last_seen']>$now || (int)$r['lease_until']<=$now
            || $now-(int)$r['last_seen'] >= $this->reportMaxAge
            || !in_array($cap,$c['capabilities'],true)) return false;
        $receipt=self::frame($r['receipt']);
        return in_array($cap,$receipt['capabilities'],true);
    }
    private function reconcile(int $owner,int $now): void
    {
        $claims=$this->sql("SELECT * FROM operator_worker_claims_8a4 WHERE owner_id=? AND state='held'",[$owner])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($claims as $cl) {
            self::require($now>=(int)$cl['created_at']);
            $r=$this->registration($cl['credential_id']);
            if ((int)$cl['lease_until']<=$now || $r===null || !$this->available($r,$owner,$now,'session.simulate')
                || $r['boot_id']!==$cl['boot_id'] || (int)$r['generation']!==(int)$cl['generation']) {
                // Do not retry a previously claimed occurrence after ambiguous loss.
                $this->sql("UPDATE operator_worker_claims_8a4 SET state='uncertain' WHERE owner_id=? AND occurrence_id=? AND state='held'",[$owner,$cl['occurrence_id']]);
            }
        }
    }
    private function selectInternal(int $owner,int $now,string $cap): ?array
    {
        $rows=$this->sql("SELECT * FROM operator_worker_registry_8a4 WHERE owner_id=? AND state='reporting' AND lease_until>? AND last_seen<=? ORDER BY CASE route WHEN 'homeserver' THEN 0 ELSE 1 END,last_seen DESC,credential_id ASC",
            [$owner,$now,$now])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!$this->available($r,$owner,$now,$cap)) continue;
            if ($this->row("SELECT claim_id FROM operator_worker_claims_8a4 WHERE owner_id=? AND credential_id=? AND state='held'",[$owner,$r['credential_id']])!==null) continue;
            return $r;
        }
        return null;
    }

    /** The optional guard is supplied only by trusted internal pairing authority.
     * A denied guard fences held synthetic work in that owner's ledger atomically.
     */
    private function simulationGuard(int $owner,?callable $guard):bool
    {
        if($guard===null||$guard()===true)return true;
        $this->sql("UPDATE operator_worker_claims_8a4 SET state='uncertain' WHERE owner_id=? AND state='held'",[$owner]);
        return false;
    }

    /** Advisory selection. No reservation, phone number, contact, or live grant. */
    public function select(int $owner,int $now,string $cap='session.simulate',?callable $guard=null): ?array
    {
        self::clock($now);self::require(self::integer($owner) && in_array($cap,self::CAPS,true));
        return $this->tx(function() use($owner,$now,$cap,$guard) {
            if(!$this->simulationGuard($owner,$guard))return null;
            $this->reconcile($owner,$now);
            $r=$this->selectInternal($owner,$now,$cap);
            return $r===null?null:['worker_id'=>$r['worker_id'],'route'=>$r['route'],'generation'=>(int)$r['generation'],
                'lease_until'=>min((int)$r['lease_until'],(int)$r['last_seen']+$this->reportMaxAge),'execution_enabled'=>false,'live_certified'=>false];
        });
    }

    /** Explicitly synthetic occurrence reservation. Never a carrier dispatch grant.
     * Trusted server code only. No public endpoint or recipient contact.
     */
    public function claimSimulation(int $owner,string $occurrence,int $now,?callable $guard=null): ?array
    {
        self::clock($now);self::require(self::integer($owner) && self::hex($occurrence));
        return $this->tx(function() use($owner,$occurrence,$now,$guard) {
            if(!$this->simulationGuard($owner,$guard))return null;
            $this->reconcile($owner,$now);
            $old=$this->row('SELECT state FROM operator_worker_claims_8a4 WHERE owner_id=? AND occurrence_id=?',[$owner,$occurrence]);
            if ($old!==null) return ['state'=>$old['state'],'duplicate'=>true,'execution_enabled'=>false];
            $r=$this->selectInternal($owner,$now,'session.simulate');
            if ($r===null) return null;
            $claim=bin2hex(random_bytes(16));$lease=min((int)$r['lease_until'],(int)$r['last_seen']+$this->reportMaxAge,$now+30);
            $this->sql("INSERT INTO operator_worker_claims_8a4(owner_id,occurrence_id,claim_id,credential_id,boot_id,generation,created_at,lease_until,state) VALUES(?,?,?,?,?,?,?,?,'held')",
                [$owner,$occurrence,$claim,$r['credential_id'],$r['boot_id'],(int)$r['generation'],$now,$lease]);
            return ['state'=>'held','claim_id'=>$claim,'worker_id'=>$r['worker_id'],'route'=>$r['route'],'lease_until'=>$lease,
                'duplicate'=>false,'execution_enabled'=>false];
        });
    }
    public function finishSimulation(int $owner,string $occurrence,string $claim,int $now,?callable $guard=null): bool
    {
        self::clock($now);self::require(self::integer($owner) && self::hex($occurrence) && self::hex($claim));
        return $this->tx(function() use($owner,$occurrence,$claim,$now,$guard) {
            if(!$this->simulationGuard($owner,$guard))return false;
            $this->reconcile($owner,$now);
            return $this->sql("UPDATE operator_worker_claims_8a4 SET state='finished' WHERE owner_id=? AND occurrence_id=? AND claim_id=? AND state='held'",
                [$owner,$occurrence,$claim])->rowCount()===1;
        });
    }
    /** Revocation is durable, including across reopened connections. */
    public function revoke(int $owner,string $id,int $now): void
    {
        self::clock($now);self::require(self::integer($owner) && self::hex($id));
        $this->tx(function() use($owner,$id,$now) {
            $r=$this->registration($id);$c=$this->credentials[$id]??null;
            self::require(($r!==null && (int)$r['owner_id']===$owner && (int)$r['last_seen']<=$now)
                || ($r===null && $c!==null && $c['owner_id']===$owner));
            if (!$this->revoked($id)) $this->sql('INSERT INTO operator_worker_revoked_8a4(credential_id,owner_id,revoked_at) VALUES(?,?,?)',[$id,$owner,$now]);
            $this->reconcile($owner,$now);
        });
    }
}
