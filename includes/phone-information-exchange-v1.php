<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';
require_once __DIR__.'/phone-information-files-v1.php';

/** PS5A: bounded, explicit text exchange. No device sensor, file or model access. */
const VP3_PHONE_INFO_TTL_V1=86400;
const VP3_PHONE_INFO_RETENTION_V1=2592000;
function vp3_phone_information_install_v1(PDO $pdo,string $options):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install information exchange outside transactions.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_information_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,profile_id CHAR(32) NOT NULL,
        exchange_id CHAR(32) NOT NULL,profile_revision BIGINT NOT NULL,version BIGINT NOT NULL,
        kind VARCHAR(16) NOT NULL,state VARCHAR(16) NOT NULL,message TEXT NOT NULL,response TEXT NOT NULL,
        created_epoch BIGINT NOT NULL,updated_epoch BIGINT NOT NULL,expires_epoch BIGINT NOT NULL,
        announced SMALLINT NOT NULL DEFAULT 0,
        PRIMARY KEY(owner_user_id,device_id,exchange_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_communication_profiles_v1(owner_user_id,device_id) ON DELETE CASCADE
    )".$options);
    if(!vp3_phone_information_ready_v1($pdo))throw new RuntimeException('Information exchange upgrade incomplete.');
}
function vp3_phone_information_ready_v1(PDO $pdo):bool
{
    try{
        $pdo->query('SELECT owner_user_id,device_id,profile_id,exchange_id,profile_revision,version,kind,state,message,response,created_epoch,updated_epoch,expires_epoch,announced FROM vp3_phone_information_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}
function vp3_phone_information_need_v1(bool $ok,int $code=422):void
{
    if(!$ok)throw new RuntimeException('Information exchange unavailable or changed.',$code);
}
function vp3_phone_information_id_v1(mixed $id):string
{
    vp3_phone_information_need_v1(is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)===1);
    return $id;
}
function vp3_phone_information_text_v1(mixed $text):string
{
    vp3_phone_information_need_v1(is_string($text)&&strlen($text)<=1000&&preg_match('//u',$text)===1);
    $text=trim($text);
    vp3_phone_information_need_v1($text!==''&&preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)!==1);
    return $text;
}
function vp3_phone_information_allowed_v1(array $p):bool
{
    return (int)($p['revision']??0)>0&&($p['profile_id']??'')!==''
        &&(int)($p['owner_requests']??0)===1&&(int)($p['holder_requests']??0)===1;
}
/** Under the same account/device lock as communication preference changes. */
function vp3_phone_information_fence_v1(PDO $pdo,array $before,array $after,int $now):void
{
    if(!vp3_phone_information_ready_v1($pdo)||(int)$before['revision']===0)return;
    $scope=[(int)$before['owner_user_id'],$before['device_id'],$before['profile_id']];
    // All file grants are revision-bound, including previously completed transfers.
    vp3_phone_file_fence_v1($pdo,$before);
    // Every changed revision invalidates unfinished work, even if consent is
    // subsequently restored. Keep only metadata on canceled work.
    vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_information_v1 SET state='canceled',version=version+1,message='',response='',updated_epoch=?,announced=1
        WHERE owner_user_id=? AND device_id=? AND profile_id=? AND state IN ('pending','accepted')",[$now,...$scope]);
    // Withdrawal of information consent also erases previous shared content.
    if(!vp3_phone_information_allowed_v1($after))
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_information_v1 SET message='',response='',announced=1 WHERE owner_user_id=? AND device_id=? AND profile_id=?",$scope);
}
function vp3_phone_information_pending_count_v1(PDO $pdo,array $d,array $p,int $now):?int
{
    if(!vp3_phone_information_ready_v1($pdo))return null;
    if(!vp3_phone_information_allowed_v1($p))return 0;
    $q=vp3_phone_sql_v1($pdo,"SELECT COUNT(*) FROM vp3_phone_information_v1 WHERE owner_user_id=? AND device_id=? AND profile_id=?
        AND profile_revision=? AND state IN ('pending','accepted') AND expires_epoch>?",
        [(int)$d['owner_user_id'],$d['id'],$p['profile_id'],(int)$p['revision'],$now]);
    return (int)$q->fetchColumn();
}
function vp3_phone_information_state_v1(array $r,array $p,int $now):string
{
    if(in_array($r['state'],['pending','accepted'],true)){
        if((int)$r['profile_revision']!==(int)$p['revision'])return 'canceled';
        if((int)$r['expires_epoch']<=$now)return 'expired';
    }
    return $r['state'];
}
function vp3_phone_information_view_v1(array $r,array $p,int $now):array
{
    $state=vp3_phone_information_state_v1($r,$p,$now);
    $hidden=in_array($state,['expired','canceled'],true);
    return ['exchange_id'=>$r['exchange_id'],'version'=>(int)$r['version'],'kind'=>$r['kind'],'state'=>$state,
        'message'=>$hidden?'':$r['message'],'response'=>$hidden?'':$r['response'],'created_epoch'=>(int)$r['created_epoch'],
        'updated_epoch'=>(int)$r['updated_epoch'],'expires_epoch'=>(int)$r['expires_epoch']];
}
function vp3_phone_information_list_v1(PDO $pdo,array $d,array $p,int $now):array
{
    $rows=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_information_v1 WHERE owner_user_id=? AND device_id=? AND profile_id=?
        AND created_epoch>? ORDER BY created_epoch DESC,exchange_id DESC LIMIT 20',
        [(int)$d['owner_user_id'],$d['id'],$p['profile_id'],$now-VP3_PHONE_INFO_RETENTION_V1])->fetchAll(PDO::FETCH_ASSOC);
    $available=vp3_phone_file_available_v1($pdo);
    $companion=[];
    if(function_exists('table_exists')&&table_exists('phone_call_shares_v1')&&$rows){
        $ids=array_column($rows,'exchange_id');$marks=implode(',',array_fill(0,count($ids),'?'));
        $shared=vp3_phone_sql_v1($pdo,"SELECT exchange_id FROM phone_call_shares_v1 WHERE owner_user_id=? AND device_id=? AND exchange_id IN ($marks)",[(int)$d['owner_user_id'],$d['id'],...$ids]);
        $companion=array_fill_keys($shared->fetchAll(PDO::FETCH_COLUMN),true);
    }
    $items=[];
    foreach($rows as $r){
        $view=vp3_phone_information_view_v1($r,$p,$now);
        if(isset($companion[$r['exchange_id']]))$view['call_companion']=true;
        $meta=$available?vp3_phone_file_metadata_v1($pdo,vp3_phone_file_scope_v1($r)):null;
        $view['attachment']=vp3_phone_file_view_v1($r,$p,$now,$meta);
        $items[]=$view;
    }
    return ['profile_id'=>$p['profile_id'],'profile_revision'=>(int)$p['revision'],'files_ready'=>$available,'items'=>$items];
}
/** Exact current authenticated pairing and both request/update permissions.
 * Generation guards the foreground UI only, not the lifetime of an exchange.
 */
function vp3_phone_information_scope_v1(PDO $pdo,array $d,array $input,int $now,bool $list):array
{
    vp3_phone_information_need_v1(vp3_phone_information_ready_v1($pdo),503);
    vp3_phone_information_need_v1(vp3_phone_communication_active_v1($d,$now),403);
    vp3_phone_information_need_v1(($input['device_id']??null)===$d['id'],403);
    $p=vp3_phone_communication_row_v1($pdo,$d,true);
    vp3_phone_information_need_v1(vp3_phone_information_allowed_v1($p),403);
    if(!$list){
        vp3_phone_generation_v1($d,$input);
        vp3_phone_information_need_v1(is_int($input['profile_revision']??null)
            &&$input['profile_revision']===(int)$p['revision']
            &&($input['profile_id']??null)===$p['profile_id'],409);
    }
    return $p;
}
/** No network, capture, upload, model request or automatic task execution. */
function vp3_phone_information_action_v1(PDO $pdo,array $d,string $action,array $input,int $now,bool $holder):array
{
    $list=$action==='information_list';
    // Never trust an unlisted action even if a direct internal caller bypasses HTTP.
    $allowed=$holder?['information_list','information_accept','information_decline','information_ack','information_reply','information_attach_file','information_download']:['information_list','information_create','information_cancel','information_create_file','information_download'];
    vp3_phone_information_need_v1(in_array($action,$allowed,true),403);
    $p=vp3_phone_information_scope_v1($pdo,$d,$input,$now,$list);
    if($list)return vp3_phone_information_list_v1($pdo,$d,$p,$now);
    $id=vp3_phone_information_id_v1($input['exchange_id']??null);
    $scope=[(int)$d['owner_user_id'],$d['id'],$id];
    $r=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_information_v1 WHERE owner_user_id=? AND device_id=? AND exchange_id=?'.vp3_phone_lock_v1($pdo),$scope)->fetch(PDO::FETCH_ASSOC);
    if(in_array($action,['information_create','information_create_file'],true)){
        vp3_phone_information_need_v1(!$holder,403);
        $file=null;
        $kind=$input['kind']??null;
        if($action==='information_create_file'){
            vp3_phone_information_need_v1($kind==='file_share'&&($input['share_confirmed']??null)===true);
            vp3_phone_information_need_v1(vp3_phone_file_available_v1($pdo),503);
            $file=vp3_phone_file_parse_v1($input['file_base64']??null,false);
        }else{
            vp3_phone_information_need_v1(in_array($kind,['update','question','availability','task','photo_request','file_request'],true));
            if(vp3_phone_file_request_kind_v1($kind))vp3_phone_information_need_v1(vp3_phone_file_available_v1($pdo),503);
        }
        $text=vp3_phone_information_text_v1($input['message']??null);
        if($r){
            vp3_phone_information_need_v1($r['profile_id']===$p['profile_id']&&(int)$r['profile_revision']===(int)$p['revision']
                &&$r['kind']===$kind&&hash_equals($r['message'],$text),409);
            if($file!==null){
                $old=vp3_phone_file_metadata_v1($pdo,$scope);
                vp3_phone_information_need_v1($old!==null&&hash_equals($old['digest'],$file['digest'])&&(int)$old['expires_epoch']>$now,409);
            }
            return vp3_phone_information_list_v1($pdo,$d,$p,$now);
        }
        // Account-serialized budget survives number/consent changes.
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_information_v1 WHERE owner_user_id=? AND created_epoch<=?',[$scope[0],$now-VP3_PHONE_INFO_RETENTION_V1]);
        $q=vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_information_v1 WHERE owner_user_id=? AND created_epoch>?',[$scope[0],$now-86400]);
        vp3_phone_information_need_v1((int)$q->fetchColumn()<32,429);
        $q=vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_information_v1 WHERE owner_user_id=? AND device_id=? AND created_epoch>?',[$scope[0],$d['id'],$now-86400]);
        vp3_phone_information_need_v1((int)$q->fetchColumn()<8,429);
        vp3_phone_information_need_v1(vp3_phone_information_pending_count_v1($pdo,$d,$p,$now)<4,429);
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_information_v1(owner_user_id,device_id,exchange_id,profile_id,profile_revision,version,kind,state,message,response,created_epoch,updated_epoch,expires_epoch,announced)
            VALUES(?,?,?,?,?,1,?,'pending',?,'',?,?,?,0)",[...$scope,$p['profile_id'],(int)$p['revision'],$kind,$text,$now,$now,$now+VP3_PHONE_INFO_TTL_V1]);
        if($file!==null)vp3_phone_file_store_v1($pdo,$scope,$file,$now);
        vp3_phone_push_event_enqueue_v1($pdo,$d,['type'=>'agent_request','id'=>$id,'revision'=>(int)$p['revision'],
            'expires_epoch'=>$now+VP3_PHONE_INFO_TTL_V1],$now);
        return vp3_phone_information_list_v1($pdo,$d,$p,$now);
    }
    vp3_phone_information_need_v1($r!==false&&$r['profile_id']===$p['profile_id'],404);
    if($action==='information_download'){
        vp3_phone_information_need_v1(($input['version']??null)===(int)$r['version'],409);
        return ['download'=>vp3_phone_file_download_v1($pdo,$r,$p,$now)];
    }
    vp3_phone_information_need_v1((int)$r['profile_revision']===(int)$p['revision']&&(int)$r['expires_epoch']>$now,409);
    $version=$input['version']??null;
    vp3_phone_information_need_v1(is_int($version)&&$version>0);
    $state=$r['state'];$response='';$file=null;
    switch($action){
        case 'information_cancel':
            vp3_phone_information_need_v1(!$holder,403);$next='canceled';$from=['pending','accepted'];break;
        case 'information_accept':
            vp3_phone_information_need_v1($holder&&!in_array($r['kind'],['update','file_share'],true),403);$next='accepted';$from=['pending'];break;
        case 'information_decline':
            vp3_phone_information_need_v1($holder,403);$next='declined';$from=['pending','accepted'];break;
        case 'information_ack':
            vp3_phone_information_need_v1($holder&&in_array($r['kind'],['update','file_share'],true),403);$next='acknowledged';$from=['pending'];break;
        case 'information_reply':
            vp3_phone_information_need_v1($holder&&in_array($r['kind'],['question','availability','task'],true),403);
            $response=vp3_phone_information_text_v1($input['response']??null);$next='completed';$from=['accepted'];break;
        case 'information_attach_file':
            vp3_phone_information_need_v1($holder&&vp3_phone_file_request_kind_v1($r['kind'])&&($input['share_confirmed']??null)===true,403);
            vp3_phone_information_need_v1(vp3_phone_file_available_v1($pdo),503);
            $file=vp3_phone_file_parse_v1($input['file_base64']??null,$r['kind']==='photo_request');
            $next='completed';$from=['accepted'];break;
        default:throw new RuntimeException('Unknown information action.',422);
    }
    // Lost acknowledgements can repeat precisely the prior transition only.
    if($state===$next&&(int)$r['version']===$version+1&&hash_equals((string)$r['response'],$response)){
        if($file!==null){$old=vp3_phone_file_metadata_v1($pdo,$scope);vp3_phone_information_need_v1($old!==null&&hash_equals($old['digest'],$file['digest'])&&(int)$old['expires_epoch']>$now,409);}
        return vp3_phone_information_list_v1($pdo,$d,$p,$now);
    }
    vp3_phone_information_need_v1((int)$r['version']===$version&&in_array($state,$from,true),409);
    $q=vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_information_v1 SET state=?,version=version+1,response=?,message=?,updated_epoch=?,announced=?
        WHERE owner_user_id=? AND device_id=? AND exchange_id=? AND version=?',
        [$next,$response,$next==='canceled'?'':$r['message'],$now,$holder?0:1,...$scope,$version]);
    vp3_phone_information_need_v1($q->rowCount()===1,409);
    if($file!==null)vp3_phone_file_store_v1($pdo,$scope,$file,$now);
    if(in_array($next,['canceled','declined'],true))vp3_phone_file_drop_v1($pdo,$scope);
    return vp3_phone_information_list_v1($pdo,$d,$p,$now);
}
function vp3_phone_information_owner_v1(PDO $pdo,int $owner,string $action,array $input):array
{
    vp3_phone_information_need_v1($owner>0&&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null),403);
    vp3_phone_information_need_v1(in_array($action,['information_list','information_create','information_cancel','information_create_file','information_download'],true),422);
    $device=vp3_phone_information_id_v1($input['device_id']??null);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$owner,$action,$input,$device){
        vp3_phone_account_v1($pdo,$owner,true);$d=vp3_phone_row_v1($pdo,$owner,$device);
        return ['information'=>vp3_phone_information_action_v1($pdo,$d,$action,$input,time(),false)];
    });
}
/** Only content-free receipts enter the existing private Chat announcement flow. */
function vp3_phone_information_announcements_v1(PDO $pdo,int $uid,int $aid,int $cid,bool $deliver):array
{
    if(!vp3_phone_information_ready_v1($pdo)||!vp3_phone_communication_ready_v1($pdo))return [];
    $now=time();
    $rows=vp3_phone_sql_v1($pdo,"SELECT i.device_id,i.exchange_id,i.state FROM vp3_phone_information_v1 i
        JOIN vp3_phone_communication_profiles_v1 p ON p.owner_user_id=i.owner_user_id AND p.device_id=i.device_id
            AND p.profile_id=i.profile_id AND p.revision=i.profile_revision AND p.owner_requests=1 AND p.holder_requests=1
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=i.owner_user_id AND d.id=i.device_id AND d.device_status='approved' AND d.expires_at>?
        JOIN vp3_paired_phone_threads_v1 t ON t.owner_user_id=i.owner_user_id AND t.device_id=i.device_id
        WHERE i.owner_user_id=? AND t.agent_id=? AND t.conversation_id=? AND i.announced=0
        AND i.state IN ('accepted','declined','acknowledged','completed') AND i.created_epoch>?
        ORDER BY i.updated_epoch,i.exchange_id LIMIT 10".($deliver?vp3_phone_lock_v1($pdo):''),
        [$now,$uid,$aid,$cid,$now-VP3_PHONE_INFO_RETENTION_V1])->fetchAll(PDO::FETCH_ASSOC);
    if(!$deliver)return $rows;
    $out=[];
    foreach($rows as $r){
        $label=match($r['state']){
            'accepted'=>'accepted an information request',
            'declined'=>'declined an information request',
            'acknowledged'=>'acknowledged an update',
            default=>'responded to an information request',
        };
        $saved=vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,'','A connected phone '.$label.'. Open its private information exchange in Phone Tracking.','information',null,null,$r['device_id']);
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_information_v1 SET announced=1 WHERE owner_user_id=? AND device_id=? AND exchange_id=?',[$uid,$r['device_id'],$r['exchange_id']]);
        $out[]=vp3_phone_chat_announcement_v1($saved);
    }
    return $out;
}
