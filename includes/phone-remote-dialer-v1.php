<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

/** Remote dialer handoff is not a telephone call, recording, or carrier API. */
const VP3_DIALER_TTL_V1=120;
function vp3_phone_dialer_install_v1(PDO $pdo,string $options):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install remote dialer outside a transaction.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_dialer_settings_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,
        revision BIGINT NOT NULL,enabled SMALLINT NOT NULL,auto_number VARCHAR(20) NOT NULL,
        PRIMARY KEY(owner_user_id,device_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_dialer_requests_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,request_id CHAR(32) NOT NULL,
        revision BIGINT NOT NULL,generation BIGINT NOT NULL,destination VARCHAR(20) NOT NULL,
        state VARCHAR(24) NOT NULL,auto_attempted SMALLINT NOT NULL DEFAULT 0,
        created_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,device_id,request_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$options);
    if(!vp3_phone_dialer_ready_v1($pdo))throw new RuntimeException('Remote dialer upgrade incomplete.');
}
function vp3_phone_dialer_ready_v1(PDO $pdo):bool
{
    try{
        $pdo->query('SELECT owner_user_id,device_id,revision,enabled,auto_number FROM vp3_phone_dialer_settings_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,device_id,request_id,revision,generation,destination,state,auto_attempted,created_epoch,expires_epoch FROM vp3_phone_dialer_requests_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}
function vp3_phone_dialer_need_v1(bool $ok,int $code=422):void
{if(!$ok)throw new RuntimeException('Remote dialer request unavailable or changed.',$code);}
function vp3_phone_dialer_number_v1(mixed $number):string
{
    vp3_phone_dialer_need_v1(is_string($number)&&preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$number)===1);
    return $number;
}
function vp3_phone_dialer_id_v1(mixed $id):string
{
    vp3_phone_dialer_need_v1(is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)===1);
    return $id;
}
function vp3_phone_dialer_settings_v1(PDO $pdo,array $d,bool $lock=false):array
{
    $row=vp3_phone_sql_v1($pdo,'SELECT revision,enabled,auto_number FROM vp3_phone_dialer_settings_v1 WHERE owner_user_id=? AND device_id=?'.($lock?vp3_phone_lock_v1($pdo):''),[(int)$d['owner_user_id'],$d['id']])->fetch(PDO::FETCH_ASSOC);
    return $row?:['revision'=>0,'enabled'=>0,'auto_number'=>''];
}
function vp3_phone_dialer_scope_v1(array $d):array
{return [(int)$d['owner_user_id'],$d['id']];}
function vp3_phone_dialer_clear_v1(PDO $pdo,array $d):void
{
    if(!vp3_phone_dialer_ready_v1($pdo))return;
    $scope=vp3_phone_dialer_scope_v1($d);
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_dialer_requests_v1 SET destination='',state='canceled' WHERE owner_user_id=? AND device_id=? AND state='pending'",$scope);
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_dialer_settings_v1 WHERE owner_user_id=? AND device_id=?',$scope);
}
function vp3_phone_dialer_prune_v1(PDO $pdo,array $d,int $now):void
{
    $scope=vp3_phone_dialer_scope_v1($d);
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_dialer_requests_v1 SET destination='',state='expired' WHERE owner_user_id=? AND device_id=? AND state='pending' AND expires_epoch<=?",[...$scope,$now]);
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_dialer_requests_v1 SET destination='',state='canceled' WHERE owner_user_id=? AND device_id=? AND state='pending' AND generation<>?",[...$scope,(int)$d['generation']]);
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_dialer_requests_v1 WHERE owner_user_id=? AND created_epoch<=?',[$scope[0],$now-86400]);
}
function vp3_phone_dialer_view_v1(PDO $pdo,array $d,int $now,bool $holder):?array
{
    if(!vp3_phone_communication_active_v1($d,$now)||!vp3_phone_dialer_ready_v1($pdo))return null;
    $s=vp3_phone_dialer_settings_v1($pdo,$d);
    $out=['revision'=>(int)$s['revision'],'enabled'=>(bool)$s['enabled'],'auto_enabled'=>$s['auto_number']!=='','server_epoch'=>$now,'request'=>null];
    if($holder)$out['auto_number']=$s['auto_number'];
    if(!$out['enabled'])return $out;
    $r=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_dialer_requests_v1 WHERE owner_user_id=? AND device_id=? AND revision=? AND generation=? AND created_epoch>? ORDER BY created_epoch DESC,request_id DESC LIMIT 1',[...vp3_phone_dialer_scope_v1($d),(int)$s['revision'],(int)$d['generation'],$now-86400])->fetch(PDO::FETCH_ASSOC);
    if(!$r)return $out;
    $state=$r['state']==='pending'&&(int)$r['expires_epoch']<=$now?'expired':$r['state'];
    $view=['id'=>$r['request_id'],'state'=>$state,'expires_epoch'=>(int)$r['expires_epoch'],'auto_attempted'=>(bool)$r['auto_attempted']];
    if($holder&&$state==='pending'){
        $view['destination']=$r['destination'];
        $view['auto_allowed']=$s['auto_number']!==''&&hash_equals($s['auto_number'],$r['destination']);
    }
    $out['request']=$view;
    return $out;
}
/** Caller holds the existing account and device locks. */
function vp3_phone_dialer_action_v1(PDO $pdo,array $d,string $action,array $input,int $now,bool $holder):array
{
    vp3_phone_dialer_need_v1(vp3_phone_dialer_ready_v1($pdo),503);
    vp3_phone_dialer_need_v1(vp3_phone_communication_active_v1($d,$now)&&($input['device_id']??null)===$d['id'],403);
    vp3_phone_generation_v1($d,$input);
    $s=vp3_phone_dialer_settings_v1($pdo,$d,true);
    vp3_phone_dialer_need_v1(($input['dialer_revision']??null)===(int)$s['revision'],409);
    $scope=vp3_phone_dialer_scope_v1($d);
    vp3_phone_dialer_prune_v1($pdo,$d,$now);
    if($action==='dialer_preferences'){
        vp3_phone_dialer_need_v1($holder,403);
        $enabled=$input['enabled']??null;$auto=$input['auto_number']??null;
        vp3_phone_dialer_need_v1(is_bool($enabled)&&is_string($auto));
        if($auto!=='')vp3_phone_dialer_number_v1($auto);
        vp3_phone_dialer_need_v1($enabled||$auto==='');
        if((bool)$s['enabled']===$enabled&&$s['auto_number']===$auto)return [];
        vp3_phone_dialer_need_v1((int)$s['revision']<9007199254740990,409);
        $rev=(int)$s['revision']+1;
        if((int)$s['revision']===0)vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_dialer_settings_v1(owner_user_id,device_id,revision,enabled,auto_number) VALUES(?,?,?,?,?)',[...$scope,$rev,(int)$enabled,$auto]);
        else vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_dialer_settings_v1 SET revision=?,enabled=?,auto_number=? WHERE owner_user_id=? AND device_id=?',[$rev,(int)$enabled,$auto,...$scope]);
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_dialer_requests_v1 SET destination='',state='canceled' WHERE owner_user_id=? AND device_id=? AND state='pending'",$scope);
        return [];
    }
    vp3_phone_dialer_need_v1(in_array($action,$holder?['dialer_claim','dialer_handled','dialer_decline']:['dialer_send','dialer_cancel'],true),403);
    vp3_phone_dialer_need_v1((bool)$s['enabled'],403);
    $id=vp3_phone_dialer_id_v1($input['dialer_request_id']??null);
    $r=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_dialer_requests_v1 WHERE owner_user_id=? AND device_id=? AND request_id=?'.vp3_phone_lock_v1($pdo),[...$scope,$id])->fetch(PDO::FETCH_ASSOC);
    if($action==='dialer_send'){
        $destination=vp3_phone_dialer_number_v1($input['destination']??null);
        vp3_phone_dialer_need_v1(($input['confirmed']??null)===true,403);
        if($r){
            vp3_phone_dialer_need_v1($r['state']==='pending'&&(int)$r['expires_epoch']>$now&&(int)$r['revision']===(int)$s['revision']&&(int)$r['generation']===(int)$d['generation']&&hash_equals($r['destination'],$destination),409);
            return [];
        }
        $count=vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_dialer_requests_v1 WHERE owner_user_id=? AND created_epoch>?',[$scope[0],$now-86400])->fetchColumn();
        vp3_phone_dialer_need_v1((int)$count<20,429);
        $count=vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_dialer_requests_v1 WHERE owner_user_id=? AND device_id=? AND created_epoch>?',[...$scope,$now-60])->fetchColumn();
        vp3_phone_dialer_need_v1((int)$count===0,429);
        $count=vp3_phone_sql_v1($pdo,"SELECT COUNT(*) FROM vp3_phone_dialer_requests_v1 WHERE owner_user_id=? AND device_id=? AND state='pending'", $scope)->fetchColumn();
        vp3_phone_dialer_need_v1((int)$count===0,409);
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_dialer_requests_v1(owner_user_id,device_id,request_id,revision,generation,destination,state,auto_attempted,created_epoch,expires_epoch) VALUES(?,?,?,?,?,?,'pending',0,?,?)",[...$scope,$id,(int)$s['revision'],(int)$d['generation'],$destination,$now,$now+VP3_DIALER_TTL_V1]);
        return [];
    }
    vp3_phone_dialer_need_v1(is_array($r)&&(int)$r['revision']===(int)$s['revision']&&(int)$r['generation']===(int)$d['generation'],409);
    if($action==='dialer_claim'){
        vp3_phone_dialer_need_v1($r['state']==='pending'&&(int)$r['expires_epoch']>$now&&!(bool)$r['auto_attempted']&&$s['auto_number']!==''&&hash_equals($s['auto_number'],$r['destination']),409);
        $q=vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_dialer_requests_v1 SET auto_attempted=1 WHERE owner_user_id=? AND device_id=? AND request_id=? AND auto_attempted=0",[...$scope,$id]);
        vp3_phone_dialer_need_v1($q->rowCount()===1,409);
        // A claim is permission to TRY the external link once, not evidence of a call.
        return ['dialer_claimed'=>$id];
    }
    $next=match($action){'dialer_handled'=>'handoff_requested','dialer_decline'=>'declined',default=>'canceled'};
    if($r['state']===$next)return [];
    vp3_phone_dialer_need_v1($r['state']==='pending'&&(int)$r['expires_epoch']>$now,409);
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_dialer_requests_v1 SET state=?,destination=? WHERE owner_user_id=? AND device_id=? AND request_id=?',[$next,'',...$scope,$id]);
    return [];
}
function vp3_phone_dialer_owner_v1(PDO $pdo,int $owner,string $action,array $input):array
{
    vp3_phone_dialer_need_v1($owner>0&&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),403);
    vp3_phone_dialer_need_v1(in_array($action,['dialer_send','dialer_cancel'],true),422);
    $id=vp3_phone_dialer_id_v1($input['device_id']??null);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$owner,$id,$action,$input){
        vp3_phone_account_v1($pdo,$owner,true);
        $d=vp3_phone_row_v1($pdo,$owner,$id);$now=time();
        vp3_phone_dialer_action_v1($pdo,$d,$action,$input,$now,false);
        return ['dialer'=>vp3_phone_dialer_view_v1($pdo,$d,$now,false)];
    });
}
