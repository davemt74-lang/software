<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';
require_once __DIR__.'/schema-metadata.php';
require_once __DIR__.'/phone-information-exchange-v1.php';
require_once __DIR__.'/phone-remote-dialer-v1.php';

/** Communication preferences for one existing QR pairing. No execution grant. */
function vp3_phone_communication_install_v1(PDO $pdo,string $options):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install communication profiles outside transactions.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_communication_profiles_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,profile_id CHAR(32) NOT NULL UNIQUE,
        revision BIGINT NOT NULL,phone_revision BIGINT NOT NULL DEFAULT 0,phone_e164 VARCHAR(20) NOT NULL DEFAULT '',
        owner_calls SMALLINT NOT NULL DEFAULT 0,owner_requests SMALLINT NOT NULL DEFAULT 0,
        holder_calls SMALLINT NOT NULL DEFAULT 0,holder_proactive SMALLINT NOT NULL DEFAULT 0,
        agent_call_consent SMALLINT NOT NULL DEFAULT 0,holder_requests SMALLINT NOT NULL DEFAULT 0,holder_memory SMALLINT NOT NULL DEFAULT 0,
        timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',window_start CHAR(5) NOT NULL DEFAULT '09:00',
        window_end CHAR(5) NOT NULL DEFAULT '17:00',max_calls_per_day INTEGER NOT NULL DEFAULT 1,
        min_gap_minutes INTEGER NOT NULL DEFAULT 240,updated_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$options);
    if(!schema_metadata_column_exists($pdo,'vp3_phone_communication_profiles_v1','agent_call_consent')){
        try{$pdo->exec('ALTER TABLE vp3_phone_communication_profiles_v1 ADD COLUMN agent_call_consent SMALLINT NOT NULL DEFAULT 0');}
        catch(PDOException $error){if(!schema_metadata_column_exists($pdo,'vp3_phone_communication_profiles_v1','agent_call_consent'))throw $error;}
    }
    if(!vp3_phone_communication_ready_v1($pdo))throw new RuntimeException('Communication profile upgrade incomplete.');
}
function vp3_phone_communication_ready_v1(PDO $pdo):bool
{
    try{
        $pdo->query('SELECT owner_user_id,device_id,profile_id,revision,phone_revision,phone_e164,owner_calls,owner_requests,holder_calls,holder_proactive,agent_call_consent,holder_requests,holder_memory,timezone,window_start,window_end,max_calls_per_day,min_gap_minutes,updated_epoch FROM vp3_phone_communication_profiles_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}
function vp3_phone_communication_require_v1(bool $ok,int $code=422):void
{if(!$ok)throw new RuntimeException('Communication profile unavailable or changed.',$code);}
function vp3_phone_communication_active_v1(array $d,int $now):bool
{return $d['device_status']==='approved'&&(int)$d['expires_at']>$now;}
function vp3_phone_communication_row_v1(PDO $pdo,array $d,bool $lock=false):array
{
    $r=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_communication_profiles_v1 WHERE owner_user_id=? AND device_id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[(int)$d['owner_user_id'],$d['id']])->fetch(PDO::FETCH_ASSOC);
    return $r!==false?$r:[
        'owner_user_id'=>(int)$d['owner_user_id'],'device_id'=>$d['id'],'profile_id'=>'','revision'=>0,
        'phone_revision'=>0,'phone_e164'=>'','owner_calls'=>0,'owner_requests'=>0,'holder_calls'=>0,
        'holder_proactive'=>0,'agent_call_consent'=>0,'holder_requests'=>0,'holder_memory'=>0,'timezone'=>'UTC',
        'window_start'=>'09:00','window_end'=>'17:00','max_calls_per_day'=>1,'min_gap_minutes'=>240,'updated_epoch'=>0];
}
function vp3_phone_communication_view_v1(array $r,bool $holder):array
{
    $phone=(string)$r['phone_e164'];
    $out=['profile_id'=>(string)$r['profile_id'],'revision'=>(int)$r['revision'],'phone_revision'=>(int)$r['phone_revision'],
        'phone_saved'=>$phone!=='','owner_calls'=>(bool)$r['owner_calls'],'owner_requests'=>(bool)$r['owner_requests'],
        'holder_calls'=>(bool)$r['holder_calls'],'holder_proactive'=>(bool)$r['holder_proactive'],
        'holder_requests'=>(bool)$r['holder_requests'],'holder_memory'=>(bool)$r['holder_memory'],
        'timezone'=>(string)$r['timezone'],'window_start'=>(string)$r['window_start'],'window_end'=>(string)$r['window_end'],
        'max_calls_per_day'=>(int)$r['max_calls_per_day'],'min_gap_minutes'=>(int)$r['min_gap_minutes'],
        'call_permission_saved'=>$phone!==''&&(bool)$r['owner_calls']&&(bool)$r['holder_calls'],
        'proactive_permission_saved'=>$phone!==''&&(bool)$r['owner_calls']&&(bool)$r['holder_calls']&&(bool)$r['holder_proactive'],
        'request_permission_saved'=>(bool)$r['owner_requests']&&(bool)$r['holder_requests'],
        'holder_scheduled_agent'=>(bool)((int)($r['agent_call_consent']??0)&1),
        'holder_spontaneous_agent'=>(bool)((int)($r['agent_call_consent']??0)&2),
        'execution_enabled'=>false,'identity_verified'=>false];
    if($holder)$out['phone_e164']=$phone;
    else $out['phone_masked']=$phone===''?'':'Ending in '.substr($phone,-4);
    return $out;
}
function vp3_phone_communication_project_v1(PDO $pdo,array $d,int $now,bool $holder):?array
{
    if(!vp3_phone_communication_active_v1($d,$now)||!vp3_phone_communication_ready_v1($pdo))return null;
    $row=vp3_phone_communication_row_v1($pdo,$d);
    $view=vp3_phone_communication_view_v1($row,$holder);
    $view['information_pending']=vp3_phone_information_pending_count_v1($pdo,$d,$row,$now);
    return $view;
}
function vp3_phone_communication_delete_v1(PDO $pdo,array $d):void
{
    vp3_phone_dialer_clear_v1($pdo,$d);
    require_once __DIR__.'/phone-managed-callback-schema-v1.php';
    vp3_managed_callback_revoke_v1($pdo,(int)$d['owner_user_id'],$d['id'],time());
    if(vp3_phone_communication_ready_v1($pdo))vp3_phone_sql_v1($pdo,
        'DELETE FROM vp3_phone_communication_profiles_v1 WHERE owner_user_id=? AND device_id=?',
        [(int)$d['owner_user_id'],$d['id']]);
}
function vp3_phone_communication_bool_v1(array $input,string $key):int
{
    vp3_phone_communication_require_v1(is_bool($input[$key]??null));return $input[$key]?1:0;
}
function vp3_phone_communication_number_v1(mixed $raw):string
{
    vp3_phone_communication_require_v1(is_string($raw)&&strlen($raw)<=64);
    $raw=trim($raw);if($raw==='')return '';
    vp3_phone_communication_require_v1(preg_match('/\A\+[0-9 ().-]+\z/D',$raw)===1);
    $digits=preg_replace('/[^0-9]/','',$raw);
    vp3_phone_communication_require_v1(is_string($digits)&&preg_match('/\A[1-9][0-9]{7,14}\z/D',$digits)===1);
    return '+'.$digits;
}
/** Called only under the existing account-then-device transaction. */
function vp3_phone_communication_save_v1(PDO $pdo,array $d,array $input,int $now,bool $holder):array
{
    vp3_phone_communication_require_v1(vp3_phone_communication_ready_v1($pdo),503);
    vp3_phone_communication_require_v1(vp3_phone_communication_active_v1($d,$now),403);
    vp3_phone_generation_v1($d,$input);
    $r=vp3_phone_communication_row_v1($pdo,$d,true);
    vp3_phone_communication_require_v1(is_int($input['communication_revision']??null)
        &&$input['communication_revision']===(int)$r['revision']&&(int)$r['revision']<9007199254740990,409);
    $before=$r;
    if($holder){
        $phone=vp3_phone_communication_number_v1($input['phone_e164']??null);
        $calls=vp3_phone_communication_bool_v1($input,'holder_calls');
        $proactive=vp3_phone_communication_bool_v1($input,'holder_proactive');
        $requests=vp3_phone_communication_bool_v1($input,'holder_requests');
        $memory=vp3_phone_communication_bool_v1($input,'holder_memory');
        $zone=$input['timezone']??null;$start=$input['window_start']??null;$end=$input['window_end']??null;
        $max=$input['max_calls_per_day']??null;$gap=$input['min_gap_minutes']??null;
        vp3_phone_communication_require_v1(is_string($zone)&&strlen($zone)<=80
            &&($zone==='UTC'||in_array($zone,DateTimeZone::listIdentifiers(),true))
            &&is_string($start)&&is_string($end)&&preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$start)===1
            &&preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$end)===1&&strcmp($start,$end)<0
            &&is_int($max)&&$max>=1&&$max<=3&&is_int($gap)&&$gap>=120&&$gap<=10080);
        // Older clients omit these fields. Preserve affirmative grants only while their prerequisites still hold.
        $modes=(int)($r['agent_call_consent']??0);
        foreach(['holder_scheduled_agent'=>1,'holder_spontaneous_agent'=>2] as $field=>$bit){
            if(array_key_exists($field,$input)){
                $modes=vp3_phone_communication_bool_v1($input,$field)?($modes|$bit):($modes&~$bit);
            }
        }
        // Saving a new number never transfers calling consent from another number.
        if($phone!==$r['phone_e164']){
            $r['phone_e164']=$phone;$r['phone_revision']=(int)$r['phone_revision']+1;$calls=$proactive=0;
        }
        vp3_phone_communication_require_v1(!$calls||($phone!==''&&(bool)$r['owner_calls']));
        vp3_phone_communication_require_v1(!$proactive||$calls);
        vp3_phone_communication_require_v1(!$requests||(bool)$r['owner_requests']);
        $r['agent_call_consent']=$calls?($proactive?$modes:($modes&1)):0;
        $r=array_replace($r,['holder_calls'=>$calls,'holder_proactive'=>$proactive,'holder_requests'=>$requests,
            'holder_memory'=>$memory,'timezone'=>$zone,'window_start'=>$start,'window_end'=>$end,
            'max_calls_per_day'=>$max,'min_gap_minutes'=>$gap]);
    }else{
        $r['owner_calls']=vp3_phone_communication_bool_v1($input,'owner_calls');
        $r['owner_requests']=vp3_phone_communication_bool_v1($input,'owner_requests');
        // Re-enabling requires new holder consent.
        if(!$r['owner_calls']){$r['holder_calls']=0;$r['holder_proactive']=0;$r['agent_call_consent']=0;}
        if(!$r['owner_requests'])$r['holder_requests']=0;
    }
    $keys=['phone_revision','phone_e164','owner_calls','owner_requests','holder_calls','holder_proactive',
        'agent_call_consent','holder_requests','holder_memory','timezone','window_start','window_end','max_calls_per_day','min_gap_minutes'];
    $changed=false;
    foreach($keys as $key)if((string)$before[$key]!== (string)$r[$key]){$changed=true;break;}
    if(!$changed)return vp3_phone_communication_view_v1($before,$holder);
    $r['revision']=(int)$before['revision']+1;$r['updated_epoch']=$now;
    $fields=array_merge($keys,['revision','updated_epoch']);
    $values=array_map(static fn($key)=>$r[$key],$fields);
    if((int)$before['revision']===0){
        $r['profile_id']=bin2hex(random_bytes(16));
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,'.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields)+3,'?')).')',
            array_merge([(int)$d['owner_user_id'],$d['id'],$r['profile_id']],$values));
    }else{
        $q=vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_communication_profiles_v1 SET '.implode('=?,',$fields).'=? WHERE owner_user_id=? AND device_id=? AND revision=?',
            array_merge($values,[(int)$d['owner_user_id'],$d['id'],(int)$before['revision']]));
        vp3_phone_communication_require_v1($q->rowCount()===1,409);
    }
    vp3_phone_information_fence_v1($pdo,$before,$r,$now);
    require_once __DIR__.'/phone-managed-callback-schema-v1.php';
    vp3_managed_callback_revoke_v1($pdo,(int)$d['owner_user_id'],$d['id'],$now);
    return vp3_phone_communication_view_v1($r,$holder);
}
/** Private owner endpoint only. Never accept an owner identity from a request. */
function vp3_phone_communication_owner_v1(PDO $pdo,int $owner,array $input):array
{
    vp3_phone_communication_require_v1($owner>0&&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),403);
    $id=$input['device_id']??null;
    vp3_phone_communication_require_v1(is_string($id)&&preg_match('/\A[a-f0-9]{32}$/D',$id)===1);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$owner,$id,$input){
        vp3_phone_account_v1($pdo,$owner,true);$now=time();$d=vp3_phone_row_v1($pdo,$owner,$id);
        $profile=vp3_phone_communication_save_v1($pdo,$d,$input,$now,false);
        return ['device'=>vp3_phone_public_v1($d,$now)+['communication'=>$profile]];
    });
}
/** Only the private map endpoint may attach this masked view. */
function vp3_phone_communication_map_v1(PDO $pdo,int $owner,array $devices,int $now):array
{
    if((function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)
        ||!vp3_phone_communication_ready_v1($pdo))return $devices;
    $rows=vp3_phone_sql_v1($pdo,"SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND device_status='approved' AND expires_at>? ORDER BY created_at DESC,id LIMIT 32",[$owner,$now])->fetchAll(PDO::FETCH_ASSOC);
    $byId=[];foreach($rows as $d)$byId[$d['id']]=$d;
    foreach($devices as &$device){
        $d=$byId[$device['id']]??null;
        if($d!==null){
            $device['communication']=vp3_phone_communication_project_v1($pdo,$d,$now,false);
            $device['dialer']=vp3_phone_dialer_view_v1($pdo,$d,$now,false);
            require_once __DIR__.'/phone-managed-ui-v1.php';
            $device['managed_callback']=vp3_managed_ui_projection_v1($pdo,$d,$now,false);
            $p=$device['communication'];
            if(is_array($p)&&$p['profile_id']!==''){
                require_once __DIR__.'/agent-operator-connected-call-v1.php';
                $device['communication']['recent_calls']=vp3_connected_phone_recent_v1($pdo,$owner,$d['id'],$p['profile_id']);
            }
        }
    }
    unset($device);return $devices;
}
