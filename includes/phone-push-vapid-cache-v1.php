<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

require_once __DIR__.'/phone-push-delivery-v1.php';

/** A local, pre-network failure. The worker can safely defer without claiming
 * provider acceptance or an ambiguous network outcome. No secrets enter errors.
 */
final class PhonePushVapidCacheUnavailableV1 extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly int $retry_after=0)
    {parent::__construct('Phone notification authorization is unavailable.',503);}
}

function vp3_phone_push_vapid_cache_install_v1(PDO $pdo,string $options=''):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install notification authorization cache outside transactions.');
    if($options===''&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$options=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    // No FK: an additive delivery migration can be absent during a rolling
    // upgrade. Every cache read still validates both live configuration rows.
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_push_vapid_cache_v1 (
        cache_key CHAR(64) NOT NULL PRIMARY KEY,origin VARCHAR(254) NOT NULL,key_id CHAR(64) NOT NULL,
        subject_hash CHAR(64) NOT NULL,config_revision BIGINT NOT NULL,header_cipher TEXT NOT NULL,
        issued_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL
    )".$options);
}

function vp3_phone_push_vapid_cache_ready_v1(PDO $pdo):bool
{
    try{
        $pdo->query('SELECT cache_key,origin,key_id,subject_hash,config_revision,header_cipher,issued_epoch,expires_epoch,updated_epoch FROM vp3_phone_push_vapid_cache_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}

function vp3_phone_push_vapid_cache_origin_v1(mixed $origin):bool
{
    if(!is_string($origin)||strlen($origin)>254)return false;
    $p=parse_url($origin);
    if(!is_array($p)||array_diff(array_keys($p),['scheme','host'])||($p['scheme']??'')!=='https'
        ||!is_string($p['host']??null)||$origin!=='https://'.$p['host'])return false;
    // Reuse the enrollment allowlist; these are only validation probe paths,
    // never endpoints to fetch or values included in an authorization token.
    return vp3_phone_push_endpoint_v1($origin.'/fcm/send/cache-scope')!==null
        ||vp3_phone_push_endpoint_v1($origin.'/wpush/cache-scope')!==null;
}

function vp3_phone_push_vapid_cache_header_v1(mixed $header,string $publicKey):bool
{
    if(!is_array($header)||array_diff(array_keys($header),['authorization','issued_epoch','expires_epoch'])
        ||!is_string($header['authorization']??null)||strlen($header['authorization'])>2048
        ||!is_int($header['issued_epoch']??null)||!is_int($header['expires_epoch']??null))return false;
    // Signature/claims verification belongs to the maintained transport library.
    // This storage layer only accepts its narrow aes128gcm Authorization shape.
    return preg_match('/^vapid t=[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+, k='.preg_quote($publicKey,'/').'$/D',$header['authorization'])===1;
}

/** Only the worker calls this after transport preflight. The factory is a local
 * vendor-library signer: factory(vapid-three-fields, origin, now) returns
 * {authorization,issued_epoch,expires_epoch}. It MUST NOT perform network I/O.
 * Serialize this short crypto operation to prevent two hosts minting different
 * tokens for one origin in the same hour. HTTP begins only after this commits.
 */
function vp3_phone_push_vapid_cache_get_v1(PDO $pdo,#[\SensitiveParameter] array $vapid,string $origin,int $now,callable $factory,#[\SensitiveParameter] ?string $testKey=null):array
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Resolve notification authorization outside a source transaction.');
    if(!vp3_phone_push_vapid_cache_ready_v1($pdo)||!vp3_phone_push_delivery_ready_v1($pdo))
        throw new PhonePushVapidCacheUnavailableV1('vapid_cache_upgrade_required');
    if(array_diff(array_keys($vapid),['public_key','private_key','subject','config_revision'])
        ||!vp3_phone_push_point_v1($vapid['public_key']??null)||vp3_phone_push_decode_v1($vapid['private_key']??null,32)===null
        ||!vp3_phone_push_delivery_subject_v1($vapid['subject']??null)||!is_int($vapid['config_revision']??null)
        ||$vapid['config_revision']<1||!vp3_phone_push_vapid_cache_origin_v1($origin)||$now<1)
        throw new PhonePushVapidCacheUnavailableV1('vapid_cache_invalid_scope');
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$vapid,$origin,$now,$factory,$testKey):array{
        // Same global order as queue preparation/configuration, with no owner or
        // outbox locks taken afterwards. No local token bypasses a disabled site.
        $push=vp3_phone_push_config_v1($pdo,true);$config=vp3_phone_push_delivery_config_v1($pdo,true);
        $master=vp3_phone_push_master_v1($testKey);
        if(!$push||!$config||!(bool)$config['enabled']||(int)$config['revision']!==$vapid['config_revision']
            ||$push['public_key']!==$vapid['public_key']||$config['subject']!==$vapid['subject'])
            throw new PhonePushVapidCacheUnavailableV1('vapid_cache_configuration_changed');
        if(!vp3_phone_push_config_valid_v1($push,$master))throw new PhonePushVapidCacheUnavailableV1('vapid_cache_key_unavailable');
        try{$protected=json_decode(ai_decrypt_secret_with_key($push['private_cipher'],$master),true,8,JSON_THROW_ON_ERROR);}
        catch(Throwable){throw new PhonePushVapidCacheUnavailableV1('vapid_cache_key_unavailable');}
        if(!is_array($protected)||!is_string($protected['private_key']??null)||!hash_equals($protected['private_key'],$vapid['private_key']))
            throw new PhonePushVapidCacheUnavailableV1('vapid_cache_key_unavailable');
        $keyId=hash('sha256',$vapid['public_key']);$subjectHash=hash('sha256',$vapid['subject']);
        $cacheKey=hash('sha256',$origin."\0".$keyId);
        // A site has at most128 active origin/key entries. Expired tokens cannot
        // suppress refresh and can be removed without affecting hourly reuse.
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_push_vapid_cache_v1 WHERE expires_epoch<=? AND issued_epoch<=?',[$now,$now-3600]);
        $q=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_vapid_cache_v1 WHERE cache_key=?'.vp3_phone_lock_v1($pdo),[$cacheKey]);
        $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$header=null;
        if($row){
            $defer=max(60,min(3600,(int)$row['issued_epoch']+3600-$now));
            try{
                $saved=json_decode(ai_decrypt_secret_with_key($row['header_cipher'],$master),true,12,JSON_THROW_ON_ERROR);
                if(!is_array($saved)||($saved['origin']??null)!==$origin||($saved['key_id']??null)!==$keyId
                    ||($saved['subject_hash']??null)!==$row['subject_hash']||($saved['config_revision']??null)!==(int)$row['config_revision']
                    ||($saved['issued_epoch']??null)!==(int)$row['issued_epoch']||($saved['expires_epoch']??null)!==(int)$row['expires_epoch']
                    ||$row['origin']!==$origin||$row['key_id']!==$keyId)
                    throw new RuntimeException('Invalid cached binding.');
                $candidate=['authorization'=>$saved['authorization']??null,'issued_epoch'=>$saved['issued_epoch'],'expires_epoch'=>$saved['expires_epoch']];
                if(!vp3_phone_push_vapid_cache_header_v1($candidate,$vapid['public_key'])||$candidate['issued_epoch']<1
                    ||$candidate['expires_epoch']<=$candidate['issued_epoch']+3900||$candidate['expires_epoch']>$candidate['issued_epoch']+86400)
                    throw new RuntimeException('Invalid cached lifetime.');
            }catch(Throwable){throw new PhonePushVapidCacheUnavailableV1('vapid_cache_unavailable',$defer);}
            if($row['subject_hash']===$subjectHash&&$candidate['issued_epoch']<=$now&&$candidate['expires_epoch']>$now+300){
                $header=$candidate;
                if((int)$row['config_revision']===$vapid['config_revision'])return $header;
                // Disable/re-enable with identical signing identity does not
                // mint a new JWT. Rebind the SAME token to current authority.
            }elseif((int)$row['issued_epoch']+3600>$now){
                throw new PhonePushVapidCacheUnavailableV1('vapid_refresh_deferred',$defer);
            }
        }else{
            if((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_vapid_cache_v1')->fetchColumn()>=128)
                throw new PhonePushVapidCacheUnavailableV1('vapid_cache_capacity',60);
        }
        if($header===null){
            try{
                $header=$factory(['public_key'=>$vapid['public_key'],'private_key'=>$vapid['private_key'],'subject'=>$vapid['subject']],$origin,$now);
            }catch(Throwable){throw new PhonePushVapidCacheUnavailableV1('vapid_signing_unavailable');}
            if(!vp3_phone_push_vapid_cache_header_v1($header,$vapid['public_key'])||$header['issued_epoch']!==$now
                ||$header['expires_epoch']<=$now+3900||$header['expires_epoch']>$now+86400)
                throw new PhonePushVapidCacheUnavailableV1('vapid_signing_unavailable');
        }
        $bound=['origin'=>$origin,'key_id'=>$keyId,'subject_hash'=>$subjectHash,'config_revision'=>$vapid['config_revision']]+$header;
        $cipher=ai_encrypt_secret_with_key(json_encode($bound,JSON_THROW_ON_ERROR),$master);
        if($row){
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_vapid_cache_v1 SET subject_hash=?,config_revision=?,header_cipher=?,issued_epoch=?,expires_epoch=?,updated_epoch=? WHERE cache_key=?',
                [$subjectHash,$vapid['config_revision'],$cipher,$header['issued_epoch'],$header['expires_epoch'],$now,$cacheKey]);
        }else{
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_push_vapid_cache_v1(cache_key,origin,key_id,subject_hash,config_revision,header_cipher,issued_epoch,expires_epoch,updated_epoch) VALUES(?,?,?,?,?,?,?,?,?)',
                [$cacheKey,$origin,$keyId,$subjectHash,$vapid['config_revision'],$cipher,$header['issued_epoch'],$header['expires_epoch'],$now]);
        }
        return $header;
    });
}
