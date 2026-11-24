<?php
declare(strict_types=1);

/** Owner-approved speech turns use metadata only. No script/audio is stored. */
const VP3_OPERATOR_VOICE_PREFIX_V1="This is the VP3 AI assistant, relaying the owner's approved response. ";
const VP3_OPERATOR_VOICE_MAX_SAMPLES_V1=320000;
function vp3_operator_voice_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT call_id,source_seq,owner_user_id,token_hash,state,created_epoch,expires_epoch,samples FROM phone_operator_voice_turns_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}
function vp3_operator_voice_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install voice turn storage outside a transaction.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_voice_turns_v1(
        call_id BIGINT NOT NULL,source_seq INTEGER NOT NULL,owner_user_id BIGINT NOT NULL,
        token_hash CHAR(64) NOT NULL,state VARCHAR(16) NOT NULL,created_epoch BIGINT NOT NULL,
        expires_epoch BIGINT NOT NULL,samples INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(call_id,source_seq)
    )".$suffix);
    if(!vp3_operator_voice_ready_v1($pdo))throw new RuntimeException('Voice turn metadata installation failed.');
}
function vp3_operator_voice_proof_v1(array $assist,string $public,int $seq,string $suggestion): string
{
    return hash_hmac('sha256',json_encode(['operator-voice-v1',$public,$seq,$suggestion],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$assist['receipt_secret']);
}
function vp3_operator_voice_row_v1(PDO $pdo,int $id,int $seq,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM phone_operator_voice_turns_v1 WHERE call_id=? AND source_seq=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$id,$seq]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
function vp3_operator_voice_busy_v1(PDO $pdo,int $id,int $now): bool
{
    if(!vp3_operator_voice_ready_v1($pdo))return false;
    $q=$pdo->prepare("SELECT 1 FROM phone_operator_voice_turns_v1 WHERE call_id=? AND state IN ('rendering','ready','playing') AND expires_epoch>? LIMIT 1");
    $q->execute([$id,$now]);$busy=(bool)$q->fetchColumn();$q->closeCursor();return $busy;
}
function vp3_operator_voice_authority_v1(PDO $pdo,array $scope,string $public,string $token,int $now): array
{
    $scope=vp3_operator_live_lock_owner_v1($pdo,$scope);
    $call=vp3_operator_live_call_v1($pdo,$scope,$public,$now,true);
    $id=(int)$call['id'];$session=vp3_operator_live_row_v1($pdo,$id,true);$assist=vp3_operator_live_assist_row_v1($pdo,$id,true);
    if(!vp3_operator_live_session_matches_v1($session,$call,$scope,$token,$now)||!$assist||!(bool)$assist['suggestions_enabled']
        ||(int)$session['pending_seq']!==0||(int)$assist['suggestion_pending']!==0)
        vp3_phone_workspace_error_v1('operator_voice_changed',409,'Live voice authority changed. Return to the human call.');
    return [$scope,$call,$session,$assist];
}
function vp3_operator_voice_script_v1(string $suggestion): string
{
    if($suggestion===''||strlen($suggestion)>720||preg_match('//u',$suggestion)!==1||mb_strlen($suggestion)>180
        ||preg_match('/[\p{Cc}\p{Cf}<>]/u',$suggestion))
        vp3_phone_workspace_error_v1('operator_voice_request',422,'Only short, plain-text reviewed suggestions can be spoken.');
    return VP3_OPERATOR_VOICE_PREFIX_V1.$suggestion;
}
function vp3_operator_voice_render_v1(string $script,string $key): string
{
    if($key===''||!function_exists('curl_init'))throw new RuntimeException('Speech service is unavailable.');
    // Fixed stock synthetic voice, not a clone, and no client-supplied provider/voice.
    $curl=curl_init('https://api.elevenlabs.io/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb?output_format=pcm_16000');
    if($curl===false)throw new RuntimeException('Speech service could not start.');
    $raw='';
    try{
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['xi-api-key: '.$key,'Content-Type: application/json','Accept: audio/pcm'],
            CURLOPT_POSTFIELDS=>json_encode(['text'=>$script,'model_id'=>'eleven_flash_v2_5'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{
                if(strlen($raw)+strlen($chunk)>VP3_OPERATOR_VOICE_MAX_SAMPLES_V1*2)return 0;
                $raw.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$mime=strtolower((string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE));
    }finally{curl_close($curl);}
    if($ok===false||$status!==200||!in_array(trim(explode(';',$mime)[0]),['audio/pcm','audio/raw','application/octet-stream'],true))
        throw new RuntimeException('A verified PCM voice result was not returned.');
    return $raw;
}
function vp3_operator_voice_prepare_v1(PDO $pdo,array $scope,string $public,string $token,int $seq,string $suggestion,string $proof,bool $consent,int $now,?callable $speech=null,?callable $clock=null): array
{
    if(!$consent)vp3_phone_workspace_error_v1('operator_voice_consent_required',422,'Confirm everyone agreed to an AI voice turn and review the exact script.');
    $script=vp3_operator_voice_script_v1($suggestion);
    if(!preg_match('/^[a-f0-9]{64}$/D',$proof))vp3_phone_workspace_error_v1('operator_voice_request',422,'Invalid reviewed suggestion.');
    $key=$speech===null?vp3_operator_live_speech_key_v1():'';
    if($speech===null&&$key==='')vp3_phone_workspace_error_v1('operator_voice_unavailable',503,'Configure the Cloud speech service first.');
    if(!vp3_operator_voice_ready_v1($pdo))vp3_phone_workspace_error_v1('operator_upgrade_required',503,'Run the Cloud voice turn upgrade.');
    $turn=bin2hex(random_bytes(32));$turnHash=hash('sha256',$turn);
    $id=vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$token,$seq,$suggestion,$proof,$now,$turnHash):int{
        [$fresh,$call,$session,$assist]=vp3_operator_voice_authority_v1($pdo,$scope,$public,$token,$now);
        $id=(int)$call['id'];
        if($seq!==(int)$assist['last_suggestion_seq']||(int)$assist['last_suggestion_epoch']<$now-45
            ||!hash_equals(vp3_operator_voice_proof_v1($assist,$public,$seq,$suggestion),$proof))
            vp3_phone_workspace_error_v1('operator_voice_evidence_changed',409,'The approved suggestion is not current.');
        if((int)$session['expires_epoch']<$now+15||vp3_operator_voice_busy_v1($pdo,$id,$now)||vp3_operator_voice_row_v1($pdo,$id,$seq,true))
            vp3_phone_workspace_error_v1('operator_voice_changed',409,'A voice turn is already reserved or the call permission is ending.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM phone_operator_voice_turns_v1 WHERE call_id=?');$q->execute([$id]);$count=(int)$q->fetchColumn();$q->closeCursor();
        $q=$pdo->prepare('SELECT COUNT(*) FROM phone_operator_voice_turns_v1 WHERE owner_user_id=? AND created_epoch>=?');$q->execute([$fresh['owner_user_id'],$now-3600]);$hour=(int)$q->fetchColumn();$q->closeCursor();
        if($count>=2||$hour>=8)vp3_phone_workspace_error_v1('operator_voice_budget',429,'The approved voice turn allowance has been reached.');
        $q=$pdo->prepare("INSERT INTO phone_operator_voice_turns_v1(call_id,source_seq,owner_user_id,token_hash,state,created_epoch,expires_epoch) VALUES(?,?,?,?,'rendering',?,?)");
        $q->execute([$id,$seq,$fresh['owner_user_id'],$turnHash,$now,min((int)$session['expires_epoch'],$now+20)]);
        return $id;
    });
    $read=static function(int $at)use($pdo,$scope,$public,$token,$id,$seq,$turnHash):array{
        [,,$session,$assist]=vp3_operator_voice_authority_v1($pdo,$scope,$public,$token,$at);
        $row=vp3_operator_voice_row_v1($pdo,$id,$seq,true);
        if(!$row||$row['state']!=='rendering'||(int)$row['expires_epoch']<=$at||!hash_equals($row['token_hash'],$turnHash)
            ||(int)$assist['last_suggestion_seq']!==$seq)
            vp3_phone_workspace_error_v1('operator_voice_changed',409,'Voice turn authorization changed.');
        return $session;
    };
    try{
        vp3_phone_workspace_atomic_v1($pdo,static fn()=>$read($clock?$clock():time()));
        $pcm=$speech?$speech($script):vp3_operator_voice_render_v1($script,$key);
        if(!is_string($pcm)||strlen($pcm)<3200||strlen($pcm)>VP3_OPERATOR_VOICE_MAX_SAMPLES_V1*2||strlen($pcm)%2!==0)
            throw new RuntimeException('Invalid voice audio.');
        $samples=intdiv(strlen($pcm),2);
        return vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$read,$clock,$id,$seq,$turn,$turnHash,$samples,$pcm,$public,$script):array{
            $at=$clock?$clock():time();$session=$read($at);
            $expires=min((int)$session['expires_epoch']-(int)ceil($samples/16000)-2,$at+15);
            if($expires<=$at)throw new RuntimeException('The call authorization will end before this voice turn.');
            $q=$pdo->prepare("UPDATE phone_operator_voice_turns_v1 SET state='ready',samples=?,expires_epoch=? WHERE call_id=? AND source_seq=? AND token_hash=? AND state='rendering'");
            $q->execute([$samples,$expires,$id,$seq,$turnHash]);if($q->rowCount()!==1)throw new RuntimeException('Voice turn changed.');
            return ['call_id'=>$public,'source_seq'=>$seq,'turn_token'=>$turn,'script'=>$script,'pcm'=>base64_encode($pcm),
                'samples'=>$samples,'sample_rate'=>16000,'expires_epoch'=>$expires,'state'=>'ready'];
        });
    }catch(Throwable){
        $pdo->prepare("UPDATE phone_operator_voice_turns_v1 SET state='closed' WHERE call_id=? AND source_seq=? AND token_hash=? AND state='rendering'")
            ->execute([$id,$seq,$turnHash]);
        vp3_phone_workspace_error_v1('operator_voice_interrupted',409,'Voice preparation stopped. Its synthesis request may have been charged.');
    }
}
function vp3_operator_voice_claim_v1(PDO $pdo,array $scope,string $public,string $token,int $seq,string $turn,int $now): array
{
    return vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$token,$seq,$turn,$now):array{
        [, $call,$session,$assist]=vp3_operator_voice_authority_v1($pdo,$scope,$public,$token,$now);
        $id=(int)$call['id'];$row=vp3_operator_voice_row_v1($pdo,$id,$seq,true);
        if(!$row||$row['state']!=='ready'||(int)$row['expires_epoch']<=$now||!hash_equals($row['token_hash'],hash('sha256',$turn))
            ||(int)$assist['last_suggestion_seq']!==$seq)
            vp3_phone_workspace_error_v1('operator_voice_changed',409,'The approved voice turn cannot be played.');
        $samples=(int)$row['samples'];$end=$now+(int)ceil($samples/16000)+3;
        if($samples<1600||$samples>VP3_OPERATOR_VOICE_MAX_SAMPLES_V1||$end>(int)$session['expires_epoch'])
            vp3_phone_workspace_error_v1('operator_voice_changed',409,'The voice turn would exceed the call authorization.');
        $q=$pdo->prepare("UPDATE phone_operator_voice_turns_v1 SET state='playing',expires_epoch=? WHERE call_id=? AND source_seq=? AND state='ready'");
        $q->execute([$end,$id,$seq]);if($q->rowCount()!==1)vp3_phone_workspace_error_v1('operator_voice_changed',409,'Voice turn already claimed.');
        return ['call_id'=>$public,'source_seq'=>$seq,'state'=>'playing','samples'=>$samples,'start_by_epoch'=>$now+2,'expires_epoch'=>$end];
    });
}
function vp3_operator_voice_finish_v1(PDO $pdo,array $scope,string $public,string $token,int $seq,string $turn,int $now): array
{
    return vp3_phone_workspace_atomic_v1($pdo,static function()use($pdo,$scope,$public,$token,$seq,$turn,$now):array{
        $scope=vp3_operator_live_lock_owner_v1($pdo,$scope);
        $call=vp3_operator_live_call_v1($pdo,$scope,$public,$now,true,false);
        $id=(int)$call['id'];$session=vp3_operator_live_row_v1($pdo,$id,true);$row=vp3_operator_voice_row_v1($pdo,$id,$seq,true);
        if(!$session||!$row||(int)$row['owner_user_id']!==$scope['owner_user_id']||!hash_equals($session['token_hash'],hash('sha256',$token))
            ||!hash_equals($row['token_hash'],hash('sha256',$turn)))
            vp3_phone_workspace_error_v1('operator_voice_changed',404,'Voice turn is unavailable.');
        $pdo->prepare("UPDATE phone_operator_voice_turns_v1 SET state='closed' WHERE call_id=? AND source_seq=?")->execute([$id,$seq]);
        // A browser receipt is not proof the carrier delivered all of the audio.
        return ['call_id'=>$public,'source_seq'=>$seq,'state'=>'closed'];
    });
}
function vp3_operator_voice_close_call_v1(PDO $pdo,int $callId): void
{
    if(vp3_operator_voice_ready_v1($pdo))$pdo->prepare("UPDATE phone_operator_voice_turns_v1 SET state='closed' WHERE call_id=? AND state<>'closed'")->execute([$callId]);
}
