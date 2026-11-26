<?php
declare(strict_types=1);

/**
 * Read-only Operator worker projection. The caller MUST already have verified
 * private owner/Phone scope. Never pass request-selected owner IDs directly.
 * A registration receipt describes reporting, not current call availability,
 * carrier connectivity, recipient consent or authority to execute.
 */
function vp3_operator_worker_status_8a4(PDO $pdo,int $owner,int $now,?bool $enabled=null): array
{
    $empty=['state'=>'unavailable','last_verified_epoch'=>null,'checked_at'=>$now,
        'fresh_until'=>0,'execution_enabled'=>false,'live_certified'=>false];
    if($owner<1||$now<1||$now>4102444710)return $empty;
    if($enabled===null){
        $settings=$GLOBALS['config']['operator_worker_registration']??[];
        $enabled=is_array($settings)&&($settings['enabled']??false)===true;
        if($enabled&&(!is_string($settings['master_key_hex']??null)
            ||preg_match('/\A[0-9a-f]{64}\z/D',$settings['master_key_hex'])!==1
            ||count(array_unique(str_split($settings['master_key_hex'],2)))<2))return $empty;
    }
    if(!$enabled)return array_replace($empty,['state'=>'disabled']);
    try{
        // One statement reads a consistent grant/registration/pairing snapshot.
        // Do not select ciphertext, receipt payloads, key fingerprints or any
        // other owner's rows. No registration, claim, DDL or side effects.
        $q=$pdo->prepare("SELECT u.is_active AS owner_active,
            g.credential_id AS grant_id,g.state AS grant_state,g.granted_at,
            g.pairing_device,g.pairing_hash,g.worker_device,
            s.device_id AS session_device,s.session_token_hash AS session_hash,s.status AS session_state,
            c.device_id AS connection_device,c.status AS connection_state,
            r.credential_id AS registry_id,r.device_id AS registry_device,r.worker_id,r.route,
            r.state AS registry_state,r.generation,r.last_seen,r.lease_until,
            v.credential_id AS revoked_id
            FROM users u
            LEFT JOIN operator_paired_workers_8a4 g ON g.owner_id=u.id
            LEFT JOIN homeserver_https_sessions s ON s.user_id=u.id
            LEFT JOIN homeserver_connections c ON c.user_id=u.id
            LEFT JOIN operator_worker_registry_8a4 r ON r.credential_id=g.credential_id AND r.owner_id=u.id
            LEFT JOIN operator_worker_revoked_8a4 v ON v.credential_id=g.credential_id
            WHERE u.id=? LIMIT 1");
        $q->execute([$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['owner_active']!==1)return $empty;
        if($row['grant_id']===null)return array_replace($empty,['state'=>'not_granted']);
        if($row['grant_state']==='revoked'||$row['revoked_id']!==null)return array_replace($empty,['state'=>'revoked']);
        if($row['grant_state']!=='active'||(int)$row['granted_at']<1||(int)$row['granted_at']>$now)return $empty;
        if($row['session_state']!=='active'||$row['connection_state']!=='paired'
            ||!is_string($row['pairing_device'])||preg_match('/\Ahs-[0-9a-f]{24}\z/D',$row['pairing_device'])!==1
            ||$row['session_device']!==$row['pairing_device']||$row['connection_device']!==$row['pairing_device']
            ||!is_string($row['pairing_hash'])||preg_match('/\A[0-9a-f]{64}\z/D',$row['pairing_hash'])!==1
            ||!is_string($row['session_hash'])||!hash_equals($row['pairing_hash'],$row['session_hash']))
            return array_replace($empty,['state'=>'pairing_unavailable']);
        if($row['registry_id']===null)return array_replace($empty,['state'=>'awaiting_registration']);
        $last=(int)$row['last_seen'];$lease=(int)$row['lease_until'];
        if($row['registry_device']!==$row['worker_device']||$row['worker_id']!=='operator-homeserver'
            ||$row['route']!=='homeserver'||(int)$row['generation']<1
            ||$last<(int)$row['granted_at']||$last>$now)return $empty;
        if($row['registry_state']==='retired'&&$lease===0)
            return array_replace($empty,['state'=>'retired','last_verified_epoch'=>$last]);
        if($row['registry_state']!=='reporting'||$lease!==$last+90)return $empty;
        // A 90-second registration lease is NOT proof of recent connectivity.
        // Use the tighter 25-second heartbeat freshness window for the UI.
        $fresh=min($lease,$last+25);
        return array_replace($empty,['state'=>$fresh>$now?'reporting':'stale',
            'last_verified_epoch'=>$last,'fresh_until'=>$fresh>$now?$fresh:0]);
    }catch(Throwable){
        // Optional migration, DB outage or inconsistent source must not break
        // existing manual Phone or reveal internal errors.
        return $empty;
    }
}

function vp3_operator_worker_detail_8a4(array $worker):string
{
    $detail=match($worker['state']??'unavailable'){
        'disabled'=>'Operator worker reporting is disabled.',
        'not_granted'=>'Operator worker has not been granted.',
        'revoked'=>'Operator worker credential is revoked.',
        'pairing_unavailable'=>'Operator worker pairing is not authorized.',
        'awaiting_registration'=>'Awaiting a signed HomeServer worker report.',
        'reporting'=>'HomeServer simulation-only report verified at '.gmdate('H:i:s \U\T\C',(int)$worker['last_verified_epoch']).'.',
        'stale'=>'HomeServer worker report is stale.',
        'retired'=>'HomeServer worker retirement was reported.',
        default=>'Operator worker status could not be verified.',
    };
    return $detail.' Autonomous calls remain off.';
}
