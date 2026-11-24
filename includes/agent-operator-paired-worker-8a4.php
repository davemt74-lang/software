<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-worker-authority-8a4.php';

/**
 * Explicitly provisioned, simulation-only HomeServer registration.
 * All grants are tied to the exact live HTTPS pairing, not a browser cookie.
 * No recipient, carrier, model, execution or media authority is provided.
 */
final class VP3OperatorPairedWorker8A4
{
    private const WORKER='operator-homeserver';
    private const REPORT_MAX_AGE=25;
    private const CAPS=['session.recover','session.simulate'];
    private PDO $db;
    private string $key;

    public function __construct(PDO $db,#[\SensitiveParameter] string $master)
    {
        self::check(strlen($master)===32 && count(array_unique(str_split($master)))>1
            && in_array($db->getAttribute(PDO::ATTR_DRIVER_NAME),['sqlite','mysql'],true)
            && $db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION);
        $this->db=$db;$this->key=$master;
    }
    private static function check(bool $ok):void
    {
        if (!$ok) throw new DomainException('Operator paired worker is not authorized.');
    }
    private static function hex(mixed $s,int $n):bool
    {
        return is_string($s) && strlen($s)===$n && preg_match('/\A[0-9a-f]+\z/D',$s)===1;
    }
    private static function device(string $s):bool{return preg_match('/\Ahs-[0-9a-f]{24}\z/D',$s)===1;}
    private static function clock(int $t):void{self::check($t>=1 && $t<=4102444710);}
    public static function masterKey(array $settings):string
    {
        $hex=$settings['master_key_hex']??null;
        self::check(self::hex($hex,64));
        $key=hex2bin($hex);self::check(is_string($key) && count(array_unique(str_split($key)))>1);
        return $key;
    }
    public static function install(PDO $db):void
    {
        VP3OperatorWorkerAuthority8A4::install($db);
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin':'';
        $db->exec('CREATE TABLE IF NOT EXISTS operator_paired_workers_8a4 (
            owner_id BIGINT PRIMARY KEY, credential_id CHAR(32) NOT NULL UNIQUE,
            pairing_device VARCHAR(100) NOT NULL, pairing_hash CHAR(64) NOT NULL,
            worker_device CHAR(32) NOT NULL, encrypted_secret VARCHAR(160) NOT NULL,
            state VARCHAR(16) NOT NULL, granted_at BIGINT NOT NULL, revoked_at BIGINT NOT NULL
        )'.$suffix);
    }
    private function sql(string $q,array $p=[]):PDOStatement
    {
        $s=$this->db->prepare($q);$s->execute($p);return $s;
    }
    private function row(string $q,array $p=[],bool $lock=false):?array
    {
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $q.=' FOR UPDATE';
        $r=$this->sql($q,$p)->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;
    }
    private function tx(callable $fn):mixed
    {
        self::check(!$this->db->inTransaction());
        $this->db->beginTransaction();
        try {
            // The same short mutex as the wire registry; existing pairing code
            // serializes on users.id. Always lock mutex before account rows.
            self::check($this->db->exec('UPDATE operator_worker_mutex_8a4 SET lock_value=1-lock_value WHERE id=1')===1);
            $r=$fn();$this->db->commit();return $r;
        } catch(Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
    private function pairing(int $owner,string $device,?string $expectedHash=null):array
    {
        self::check($this->db->inTransaction() && $owner>0 && self::device($device));
        $u=$this->row('SELECT id,is_active FROM users WHERE id=?',[$owner],true);
        $s=$this->row('SELECT user_id,device_id,session_token_hash,status FROM homeserver_https_sessions WHERE user_id=?',[$owner],true);
        $c=$this->row('SELECT user_id,device_id,status FROM homeserver_connections WHERE user_id=?',[$owner],true);
        self::check($u!==null && (int)$u['is_active']===1 && $s!==null && $c!==null
            && $s['status']==='active' && $c['status']==='paired'
            && $s['device_id']===$device && $c['device_id']===$device
            && self::hex($s['session_token_hash'],64)
            && ($expectedHash===null || hash_equals($expectedHash,$s['session_token_hash'])));
        return $s;
    }
    private static function aad(array $g):string
    {
        return 'vp3.operator.paired.worker.v1'."\0".(string)$g['owner_id']."\0".$g['credential_id']."\0".
            $g['pairing_device']."\0".$g['pairing_hash']."\0".$g['worker_device'];
    }
    private function encrypt(array $g,string $secret):string
    {
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($secret,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,self::aad($g),16);
        self::check(is_string($cipher) && strlen($cipher)===32 && strlen($tag)===16);
        return base64_encode($iv.$tag.$cipher);
    }
    private function decrypt(array $g):string
    {
        $blob=base64_decode($g['encrypted_secret'],true);
        self::check(is_string($blob) && strlen($blob)===60);
        $secret=openssl_decrypt(substr($blob,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,
            substr($blob,0,12),substr($blob,12,16),self::aad($g));
        self::check(is_string($secret) && strlen($secret)===32);
        return $secret;
    }
    private function inventory(array $g):array
    {
        return ['credential_id'=>$g['credential_id'],'owner_id'=>(int)$g['owner_id'],
            'device_id'=>$g['worker_device'],'worker_id'=>self::WORKER,'secret'=>$this->decrypt($g),
            'capabilities'=>self::CAPS,'route'=>'homeserver'];
    }
    private function grantRow(int $owner,bool $lock=false):?array
    {
        return $this->row('SELECT * FROM operator_paired_workers_8a4 WHERE owner_id=?',[$owner],$lock);
    }

    /** Privileged explicit grant. No secret is returned to the operator/admin. */
    public function grant(int $owner,string $device,int $now):array
    {
        self::clock($now);
        return $this->tx(function()use($owner,$device,$now){
            $pair=$this->pairing($owner,$device);
            $old=$this->grantRow($owner,true);
            if ($old!==null && $old['state']==='active') {
                self::check($old['pairing_device']===$device && hash_equals($old['pairing_hash'],$pair['session_token_hash'])
                    && (int)$old['granted_at']<=$now);
                $this->decrypt($old); // Fail closed on an invalid encryption key or corrupted record.
                return ['credential_id'=>$old['credential_id'],'created'=>false,'execution_enabled'=>false];
            }
            self::check($old===null || ($old['state']==='revoked' && (int)$old['revoked_at']<=$now));
            $g=['owner_id'=>$owner,'credential_id'=>bin2hex(random_bytes(16)),
                'pairing_device'=>$device,'pairing_hash'=>$pair['session_token_hash'],
                'worker_device'=>substr(hash('sha256','vp3.operator.device.v1'."\0".$device),0,32)];
            $encrypted=$this->encrypt($g,random_bytes(32));
            $values=[$g['credential_id'],$device,$g['pairing_hash'],$g['worker_device'],$encrypted,$now,$owner];
            if($old===null) {
                $this->sql("INSERT INTO operator_paired_workers_8a4(credential_id,pairing_device,pairing_hash,worker_device,encrypted_secret,granted_at,owner_id,state,revoked_at) VALUES(?,?,?,?,?,?,?,'active',0)",$values);
            }else{
                $this->sql("UPDATE operator_paired_workers_8a4 SET credential_id=?,pairing_device=?,pairing_hash=?,worker_device=?,encrypted_secret=?,granted_at=?,state='active',revoked_at=0 WHERE owner_id=?",$values);
            }
            return ['credential_id'=>$g['credential_id'],'created'=>true,'execution_enabled'=>false];
        });
    }

    /** Retrieve only the preapproved credential over the exact paired device channel.
     * Repeated fetches return the same secret, allowing loss-safe acknowledgement.
     */
    public function fetchCredential(string $device,#[\SensitiveParameter] string $bearer,int $now):array
    {
        self::clock($now);self::check(self::device($device) && preg_match('/\A[A-Za-z0-9_-]{64}\z/D',$bearer)===1);
        $hash=hash('sha256',$bearer);
        $s=$this->row('SELECT user_id FROM homeserver_https_sessions WHERE device_id=? AND session_token_hash=?',[$device,$hash]);
        self::check($s!==null);
        $owner=(int)$s['user_id'];
        return $this->tx(function()use($owner,$device,$hash,$now){
            $this->pairing($owner,$device,$hash);
            $g=$this->grantRow($owner,true);
            self::check($g!==null && $g['state']==='active' && $g['pairing_device']===$device
                && hash_equals($g['pairing_hash'],$hash) && (int)$g['granted_at']<=$now
                && $this->row('SELECT credential_id FROM operator_worker_revoked_8a4 WHERE credential_id=?',[$g['credential_id']],true)===null);
            $c=$this->inventory($g);$c['secret_hex']=bin2hex($c['secret']);unset($c['secret'],$c['route']);
            $c['execution_enabled']=false;$c['live_certified']=false;
            return $c;
        });
    }

    /** Recheck the complete grant and exact active pairing under the registry mutex. */
    private function authorizeGrant(array $g,int $owner,int $now):void
    {
        $this->pairing($owner,$g['pairing_device'],$g['pairing_hash']);
        $current=$this->grantRow($owner,true);
        self::check($current!==null && $current['state']==='active' && (int)$current['granted_at']<=$now
            && (int)$current['granted_at']===(int)$g['granted_at']
            && $current['credential_id']===$g['credential_id']
            && hash_equals($current['pairing_hash'],$g['pairing_hash'])
            && $current['pairing_device']===$g['pairing_device'] && $current['worker_device']===$g['worker_device']
            && hash_equals($current['encrypted_secret'],$g['encrypted_secret'])
            && $this->row('SELECT credential_id FROM operator_worker_revoked_8a4 WHERE credential_id=?',[$g['credential_id']],true)===null);
    }

    /** Internal paired simulation entry point, NOT a live call or browser action.
     * The caller must already have verified the owner; no request may choose it.
     * Missing, corrupt or replaced grants cannot authorize new or held work.
     */
    private function simulationAuthority(int $owner,int $now):array
    {
        self::clock($now);self::check($owner>0);
        $g=$this->grantRow($owner);
        $inventory=[];
        if($g!==null&&$g['state']==='active'&&(int)$g['granted_at']<=$now){
            try{$inventory=[$this->inventory($g)];}
            catch(DomainException){$g=null;}
        }else{$g=null;}
        $guard=function()use($g,$owner,$now):bool{
            if($g===null)return false;
            try{$this->authorizeGrant($g,$owner,$now);return true;}
            catch(DomainException){return false;}
        };
        return [new VP3OperatorWorkerAuthority8A4($this->db,$inventory,self::REPORT_MAX_AGE),$guard];
    }

    public function selectSimulation(int $owner,int $now):?array
    {
        [$authority,$guard]=$this->simulationAuthority($owner,$now);
        return $authority->select($owner,$now,'session.simulate',$guard);
    }

    public function claimSimulation(int $owner,string $occurrence,int $now):?array
    {
        [$authority,$guard]=$this->simulationAuthority($owner,$now);
        $claim=$authority->claimSimulation($owner,$occurrence,$now,$guard);
        return $claim===null?null:$claim+['live_certified'=>false,'mode'=>'simulation'];
    }

    public function finishSimulation(int $owner,string $occurrence,string $claim,int $now):bool
    {
        [$authority,$guard]=$this->simulationAuthority($owner,$now);
        return $authority->finishSimulation($owner,$occurrence,$claim,$now,$guard);
    }

    /** The public wire handler trusts only the signed, current preprovisioned identity. */
    public function receive(string $body,string $mac,int $now):array
    {
        self::clock($now);self::check(strlen($body)>=2 && strlen($body)<=4096 && self::hex($mac,64));
        try {$p=json_decode($body,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new DomainException('Invalid worker frame.');}
        self::check(is_array($p) && self::hex($p['credential_id']??null,32));
        $g=$this->row('SELECT * FROM operator_paired_workers_8a4 WHERE credential_id=?',[$p['credential_id']]);
        self::check($g!==null && $g['state']==='active' && (int)$g['granted_at']<=$now);
        $c=$this->inventory($g);$owner=$c['owner_id'];
        $a=new VP3OperatorWorkerAuthority8A4($this->db,[$c],self::REPORT_MAX_AGE);
        return $a->receive($body,$mac,$now,function()use($g,$owner,$now){
            $this->authorizeGrant($g,$owner,$now);
        });
    }

    /** Revoke even after account deletion, session replacement or config disable.
     * Keep durable registry fences; erase encrypted worker secret.
     */
    public function revoke(int $owner,int $now):bool
    {
        self::clock($now);self::check($owner>0);
        return $this->tx(function()use($owner,$now){
            $g=$this->grantRow($owner,true);
            if ($g===null || $g['state']==='revoked') return false;
            self::check($g['state']==='active' && (int)$g['granted_at']<=$now);
            $id=$g['credential_id'];
            $old=$this->row('SELECT owner_id FROM operator_worker_revoked_8a4 WHERE credential_id=?',[$id],true);
            if($old===null)$this->sql('INSERT INTO operator_worker_revoked_8a4(credential_id,owner_id,revoked_at) VALUES(?,?,?)',[$id,$owner,$now]);
            else self::check((int)$old['owner_id']===$owner);
            $this->sql("UPDATE operator_paired_workers_8a4 SET encrypted_secret='',state='revoked',revoked_at=? WHERE owner_id=?",[$now,$owner]);
            $this->sql("UPDATE operator_worker_claims_8a4 SET state='uncertain' WHERE owner_id=? AND credential_id=? AND state='held'",[$owner,$id]);
            return true;
        });
    }
}
