<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-workspace-schema-v1.php';
require_once __DIR__.'/phone-tracking-v1.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/participant-updates-v1.php';

const VP3_PHONE_AGENT_KINDS_V1=['gmail_draft','gmail_attention','agent_result','image','canvas'];

function vp3_phone_agent_workspace_need_v1(bool $ok,int $code=422): void
{ if (!$ok) throw new RuntimeException('Phone Agent workspace access changed.', $code); }

function vp3_phone_agent_workspace_id_v1(mixed $value): string
{
    vp3_phone_agent_workspace_need_v1(is_string($value)&&preg_match('/^[a-f0-9]{32}$/D',$value)===1);
    return $value;
}

function vp3_phone_agent_workspace_user_v1(PDO $pdo,int $owner,bool $lock=false): array
{
    $row=vp3_phone_sql_v1($pdo,'SELECT * FROM users WHERE id=?'.($lock?vp3_phone_lock_v1($pdo):''),[$owner])->fetch(PDO::FETCH_ASSOC);
    if ($row&&function_exists('user_account_types_for_user_id')) $row['roles']=user_account_types_for_user_id($owner,(string)($row['role']??''));
    vp3_phone_agent_workspace_need_v1($row!==false&&(int)($row['is_active']??0)===1&&function_exists('has_permission')
        &&has_permission('account.access',$row)&&has_permission('chat.access',$row),403);
    return $row;
}

/** This is deliberately stronger than a paired holder or the device's display role. */
function vp3_phone_agent_workspace_owner_v1(PDO $pdo,?array $user,bool $lock=false): array
{
    $owner=(int)($user['id']??0);
    vp3_phone_agent_workspace_need_v1($owner>0&&(int)($_SESSION['user_id']??0)===$owner,401);
    vp3_phone_agent_workspace_need_v1(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null,403);
    $live=vp3_phone_agent_workspace_user_v1($pdo,$owner,$lock);
    $fingerprint=$_SESSION['auth_password_fingerprint']??null;
    vp3_phone_agent_workspace_need_v1(is_string($fingerprint)&&strlen($fingerprint)===64
        &&hash_equals(hash('sha256',$owner.':'.(string)($live['password_hash']??'')),$fingerprint),401);
    return $live;
}

function vp3_phone_agent_workspace_device_v1(PDO $pdo,int $owner,string $token,string $id,int $now,bool $lock=false): array
{
    vp3_phone_agent_workspace_need_v1(vp3_phone_secret_valid_v1($token),403);
    $row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$id])->fetch(PDO::FETCH_ASSOC);
    vp3_phone_agent_workspace_need_v1($row!==false&&$row['device_status']==='approved'&&(int)$row['expires_at']>$now
        &&hash_equals((string)$row['token_hash'],vp3_extension_hash_v2000($token)),403);
    return $row;
}

function vp3_phone_agent_workspace_agent_v1(PDO $pdo,int $owner,int $agent): array
{
    vp3_phone_agent_workspace_need_v1($agent>=0);
    if ($agent===0) return ['id'=>0,'name'=>function_exists('system_agent_name')?system_agent_name():'Main Agent'];
    $row=vp3_phone_sql_v1($pdo,'SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1',[$agent,$owner])->fetch(PDO::FETCH_ASSOC);
    vp3_phone_agent_workspace_need_v1($row!==false,403);
    return ['id'=>$agent,'name'=>mb_substr((string)$row['display_name'],0,190)];
}

function vp3_phone_agent_workspace_chat_v1(PDO $pdo,int $owner,int $agent,int $conversation): void
{
    vp3_phone_agent_workspace_agent_v1($pdo,$owner,$agent);
    vp3_phone_agent_workspace_need_v1($conversation>0
        &&vp3_agent_chat_conversation_v380($pdo,$conversation,$owner,$agent>0?['id'=>$agent]:null)!==null,403);
}

function vp3_phone_agent_workspace_enrollment_v1(PDO $pdo,int $owner,string $id,bool $lock=false): ?array
{
    return vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_enrollments_v1 WHERE owner_user_id=? AND device_id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$id])->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_phone_agent_workspace_key_v1(): string
{
    if (!is_string($_SESSION['phone_agent_workspace_key_v1']??null)
        ||preg_match('/^[a-f0-9]{64}$/D',$_SESSION['phone_agent_workspace_key_v1'])!==1) {
        $_SESSION['phone_agent_workspace_key_v1']=bin2hex(random_bytes(32));
    }
    return $_SESSION['phone_agent_workspace_key_v1'];
}

function vp3_phone_agent_workspace_binding_v1(array $enrollment,string $key): string
{
    return hash_hmac('sha256',json_encode(['phone-agent-workspace-v1',(int)$enrollment['owner_user_id'],
        $enrollment['device_id'],$enrollment['grant_id'],$enrollment['credential_hash'],$enrollment['owner_fingerprint'],
        (int)$enrollment['revision'],(int)$enrollment['selected_agent_id'],(int)$enrollment['conversation_id']],JSON_THROW_ON_ERROR),$key);
}

/** Shared native viewer/Gmail scope; pairing alone never grants private access. */
function vp3_phone_agent_workspace_scope_v1(PDO $pdo,?array $user,string $token,string $deviceId,bool $lock=false,?int $now=null): array
{
    $now??=time();$deviceId=vp3_phone_agent_workspace_id_v1($deviceId);
    $paired=vp3_phone_agent_device_scope_v1($pdo,$token,$deviceId,$lock,$now);if($paired!==null)return $paired;
    vp3_phone_agent_workspace_need_v1(vp3_phone_agent_workspace_ready_v1($pdo),503);
    $live=vp3_phone_agent_workspace_owner_v1($pdo,$user,$lock);$owner=(int)$live['id'];
    $device=vp3_phone_agent_workspace_device_v1($pdo,$owner,$token,$deviceId,$now,$lock);
    $enrollment=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$deviceId,$lock);
    vp3_phone_agent_workspace_need_v1($enrollment!==null&&(int)$enrollment['enabled']===1
        &&hash_equals($enrollment['credential_hash'],$device['token_hash'])
        &&hash_equals($enrollment['owner_fingerprint'],(string)$_SESSION['auth_password_fingerprint']),403);
    $agent=vp3_phone_agent_workspace_agent_v1($pdo,$owner,(int)$enrollment['selected_agent_id']);
    vp3_phone_agent_workspace_chat_v1($pdo,$owner,$agent['id'],(int)$enrollment['conversation_id']);
    return ['user'=>$live,'device'=>$device,'enrollment'=>$enrollment,'agent'=>$agent,'now'=>$now,
        'scope_revision'=>vp3_phone_agent_workspace_binding_v1($enrollment,vp3_phone_agent_workspace_key_v1())];
}

/** Recheck a trusted server scope after another helper has run, without storing the raw holder credential. */
function vp3_phone_agent_workspace_scope_current_v1(PDO $pdo,array $scope,bool $lock=false): void
{
    $now=max((int)$scope['now'],time());
    $paired=!empty($scope['paired_access']);
    $live=$paired?vp3_phone_agent_workspace_user_v1($pdo,(int)$scope['user']['id'],$lock):vp3_phone_agent_workspace_owner_v1($pdo,$scope['user'],$lock);$owner=(int)$live['id'];
    $d=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$scope['device']['id']])->fetch(PDO::FETCH_ASSOC);
    $e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$scope['device']['id'],$lock);
    vp3_phone_agent_workspace_need_v1($d!==false&&$d['device_status']==='approved'&&(int)$d['expires_at']>$now
        &&hash_equals($scope['device']['token_hash'],$d['token_hash'])&&$e!==null&&(int)$e['enabled']===1
        &&hash_equals($e['credential_hash'],$d['token_hash'])
        &&hash_equals($e['owner_fingerprint'],$paired?hash('sha256',$owner.':'.(string)($live['password_hash']??'')):(string)($_SESSION['auth_password_fingerprint']??''))
        &&(!$paired||vp3_phone_agent_device_marker_v1($pdo,$e)),403);
    vp3_phone_agent_workspace_need_v1(hash_equals($scope['scope_revision'],vp3_phone_agent_workspace_binding_v1($e,vp3_phone_agent_workspace_key_v1())),409);
    vp3_phone_agent_workspace_chat_v1($pdo,$owner,(int)$e['selected_agent_id'],(int)$e['conversation_id']);
}

function vp3_phone_agent_workspace_adapter_v1(): void
{
    if (!function_exists('vp3_phone_agent_source_resolve_v1')) require_once __DIR__.'/phone-agent-workspace-sources-v1.php';
}

function vp3_phone_agent_workspace_source_v1(PDO $pdo,array $user,int $agent,string $kind,string $id,int $now): ?array
{
    vp3_phone_agent_workspace_need_v1(in_array($kind,VP3_PHONE_AGENT_KINDS_V1,true)&&$id!==''&&strlen($id)<=190,422);
    vp3_phone_agent_workspace_adapter_v1();
    $source=vp3_phone_agent_source_resolve_v1($pdo,$user,$agent,$kind,$id,$now);
    if ($source===null) return null;
    vp3_phone_agent_workspace_need_v1(($source['source_kind']??null)===$kind&&($source['source_id']??null)===$id
        &&is_string($source['source_revision']??null)&&preg_match('/^[a-f0-9]{64}$/D',$source['source_revision'])===1
        &&is_int($source['conversation_id']??null)&&is_string($source['title']??null)&&mb_strlen($source['title'])<=240
        &&is_string($source['summary']??null)&&mb_strlen($source['summary'])<=1200
        &&in_array($source['state']??null,['ready','needs_review','waiting','completed','unavailable'],true)
        &&is_bool($source['needs_action']??null)&&in_array($source['priority']??null,['normal','important','urgent'],true)
        &&is_int($source['occurred_epoch']??null)&&$source['occurred_epoch']>0&&$source['occurred_epoch']<=$now
        &&is_int($source['updated_epoch']??null)&&$source['updated_epoch']>0&&$source['updated_epoch']<=$now
        &&is_array($source['target']??null)
        &&(!array_key_exists('notification_eligible',$source)||is_bool($source['notification_eligible'])),503);
    vp3_phone_agent_workspace_chat_v1($pdo,(int)$user['id'],$agent,$source['conversation_id']);
    $target=$source['target'];
    $targets=['gmail_draft'=>'gmail_draft','gmail_attention'=>'gmail_attention','agent_result'=>'chat_message','image'=>'image','canvas'=>'canvas'];
    vp3_phone_agent_workspace_need_v1(($target['kind']??null)===$targets[$kind]
        &&($target['agent_id']??null)===$agent&&($target['conversation_id']??null)===$source['conversation_id'],503);
    $ids=['gmail_draft'=>'draft_id','gmail_attention'=>'item_ref','agent_result'=>'message_id','image'=>'generation_id','canvas'=>'canvas_id'];
    $allowed=['kind','agent_id','conversation_id',$ids[$kind]];
    if ($kind==='canvas') $allowed[]='revision';
    if ($kind==='gmail_attention') $allowed=[...$allowed,'generation','origin','conversation_role'];
    vp3_phone_agent_workspace_need_v1(!array_diff(array_keys($target),$allowed)
        &&(string)($target[$ids[$kind]]??'')===$id
        &&($kind!=='canvas'||(is_int($target['revision']??null)&&$target['revision']>0))
        &&($kind!=='gmail_attention'||(preg_match('/^[a-f0-9]{64}$/D',$id)===1
            &&is_int($target['generation']??null)&&$target['generation']>0
            &&($target['origin']??null)==='gmail_mailbox'&&($target['conversation_role']??null)==='discussion_target')),503);
    return $source;
}

function vp3_phone_agent_workspace_store_v1(PDO $pdo,int $owner,int $agent,array $source,int $now): array
{
    $sourceKey=hash('sha256',json_encode([$source['source_kind'],$source['source_id'],$source['conversation_id']],JSON_THROW_ON_ERROR));
    $row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_items_v1 WHERE owner_user_id=? AND agent_id=? AND source_key=?'
        .vp3_phone_lock_v1($pdo),[$owner,$agent,$sourceKey])->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $ref=bin2hex(random_bytes(16));
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_agent_items_v1(item_ref,owner_user_id,agent_id,conversation_id,source_kind,source_id,source_key,source_revision,created_epoch,updated_epoch)
            VALUES(?,?,?,?,?,?,?,?,?,?)',[$ref,$owner,$agent,$source['conversation_id'],$source['source_kind'],$source['source_id'],$sourceKey,$source['source_revision'],$now,$now]);
        return vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_items_v1 WHERE item_ref=?',[$ref])->fetch(PDO::FETCH_ASSOC);
    }
    if (!hash_equals($row['source_revision'],$source['source_revision'])) {
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_items_v1 SET source_revision=?,revision=revision+1,updated_epoch=? WHERE item_ref=?',
            [$source['source_revision'],$now,$row['item_ref']]);
        $row['source_revision']=$source['source_revision'];$row['revision']=(int)$row['revision']+1;$row['updated_epoch']=$now;
    }
    return $row;
}

/** Server-only ingestion: independently re-resolves every candidate; stores references, not messages. */
function vp3_phone_agent_workspace_ingest_v1(PDO $pdo,int $owner,int $agent,array $candidates,int $now): array
{
    vp3_phone_agent_workspace_need_v1(count($candidates)<=100,422);
    vp3_phone_agent_workspace_need_v1(vp3_phone_agent_workspace_ready_v1($pdo),503);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$owner,$agent,$candidates,$now):array {
        $user=vp3_phone_agent_workspace_user_v1($pdo,$owner,true);vp3_phone_agent_workspace_agent_v1($pdo,$owner,$agent);$out=[];$sources=[];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) throw new RuntimeException('Invalid canonical source.',422);
            $source=vp3_phone_agent_workspace_source_v1($pdo,$user,$agent,(string)($candidate['source_kind']??''),(string)($candidate['source_id']??''),$now);
            if ($source!==null) $sources[]=$source;
        }
        // Newest canonical activity receives the newest stable page sequence.
        usort($sources,static fn(array $a,array $b):int=>$a['updated_epoch']<=>$b['updated_epoch']
            ?:strcmp($a['source_kind'].':'.$a['source_id'],$b['source_kind'].':'.$b['source_id']));
        foreach ($sources as $source) {
            $row=vp3_phone_agent_workspace_store_v1($pdo,$owner,$agent,$source,$now);
            $out[]=['item_ref'=>$row['item_ref'],'revision'=>(int)$row['revision']];
        }
        return $out;
    });
}

function vp3_phone_agent_workspace_receipt_v1(PDO $pdo,array $scope,array $row,bool $lock=false): array
{
    $e=$scope['enrollment'];
    $receipt=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_receipts_v1 WHERE owner_user_id=? AND device_id=? AND grant_id=? AND item_ref=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[(int)$e['owner_user_id'],$e['device_id'],$e['grant_id'],$row['item_ref']])->fetch(PDO::FETCH_ASSOC)?:['revision'=>0];
    if (($receipt['source_revision']??null)!==$row['source_revision']) {
        foreach (['seen_epoch','read_epoch','acknowledged_epoch','snoozed_until'] as $key) $receipt[$key]=null;
        $receipt['wake_revision']=0;$receipt['wake_requested_epoch']=0;
    }
    return $receipt;
}

function vp3_phone_agent_workspace_iso_v1(mixed $epoch): ?string
{ return $epoch===null?null:gmdate('Y-m-d\TH:i:s\Z',(int)$epoch); }

function vp3_phone_agent_workspace_public_v1(array $row,array $source,array $receipt): array
{
    $version=(int)$row['revision']+(int)$receipt['revision'];
    return ['item_ref'=>$row['item_ref'],'revision'=>$version,'state_version'=>$version,'source_kind'=>$row['source_kind'],
        'source_revision'=>$source['source_revision'],'agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id'],
        'title'=>$source['title'],'summary'=>$source['summary'],'state'=>$source['state'],'needs_action'=>$source['needs_action'],
        'priority'=>$source['priority'],'occurred_at'=>vp3_phone_agent_workspace_iso_v1($source['occurred_epoch']),
        'updated_at'=>vp3_phone_agent_workspace_iso_v1($source['updated_epoch']),'target'=>$source['target'],
        'receipt'=>['seen_at'=>vp3_phone_agent_workspace_iso_v1($receipt['seen_epoch']??null),
            'read_at'=>vp3_phone_agent_workspace_iso_v1($receipt['read_epoch']??null),
            'acknowledged_at'=>vp3_phone_agent_workspace_iso_v1($receipt['acknowledged_epoch']??null),
            'snoozed_until'=>vp3_phone_agent_workspace_iso_v1($receipt['snoozed_until']??null)]];
}

/** Exact native source resolution shared by read-only hosts and explicit receipt mutations. */
function vp3_phone_agent_workspace_item_v1(PDO $pdo,array $scope,string $itemRef,?int $expectedRevision=null,bool $withDetail=false): array
{
    if (!vp3_phone_transaction_active_v1($pdo)) return vp3_phone_transaction_v1($pdo,
        static fn():array=>vp3_phone_agent_workspace_item_v1($pdo,$scope,$itemRef,$expectedRevision,$withDetail));
    vp3_phone_agent_workspace_scope_current_v1($pdo,$scope,true);
    $ref=vp3_phone_agent_workspace_id_v1($itemRef);$owner=(int)$scope['user']['id'];$agent=$scope['agent']['id'];
    $row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_items_v1 WHERE owner_user_id=? AND agent_id=? AND item_ref=?',[$owner,$agent,$ref])->fetch(PDO::FETCH_ASSOC);
    vp3_phone_agent_workspace_need_v1($row!==false,404);
    $source=vp3_phone_agent_workspace_source_v1($pdo,$scope['user'],$agent,$row['source_kind'],$row['source_id'],$scope['now']);
    vp3_phone_agent_workspace_need_v1($source!==null&&$source['conversation_id']===(int)$row['conversation_id'],404);
    $row=vp3_phone_agent_workspace_store_v1($pdo,$owner,$agent,$source,$scope['now']);
    $receipt=vp3_phone_agent_workspace_receipt_v1($pdo,$scope,$row);
    $item=vp3_phone_agent_workspace_public_v1($row,$source,$receipt);
    vp3_phone_agent_workspace_need_v1($expectedRevision===null||$expectedRevision===$item['revision'],409);
    $result=['item'=>$item,'row'=>$row,'source'=>$source,'receipt'=>$receipt];
    if ($withDetail) {
        $result['detail']=vp3_phone_agent_source_detail_v1($pdo,$scope['user'],$agent,$row['source_kind'],$row['source_id'],$scope['now'],$source['source_revision']);
        $last=vp3_phone_agent_workspace_source_v1($pdo,$scope['user'],$agent,$row['source_kind'],$row['source_id'],$scope['now']);
        vp3_phone_agent_workspace_need_v1($last!==null&&hash_equals($source['source_revision'],$last['source_revision']),409);
    }
    vp3_phone_agent_workspace_scope_current_v1($pdo,$scope,true);
    return $result;
}

function vp3_phone_agent_workspace_envelope_v1(array $scope): array
{
    return ['device_id'=>$scope['device']['id'],'enrollment_revision'=>(int)$scope['enrollment']['revision'],
        'scope_revision'=>$scope['scope_revision']];
}

function vp3_phone_agent_workspace_status_v1(PDO $pdo,?array $user,string $token,string $id,int $now): array
{
    if (!vp3_phone_transaction_active_v1($pdo)) return vp3_phone_transaction_v1($pdo,
        static fn():array=>vp3_phone_agent_workspace_status_v1($pdo,$user,$token,$id,$now));
    try{$paired=vp3_phone_agent_device_scope_v1($pdo,$token,$id,true,$now);if($paired!==null)return vp3_phone_agent_device_status_v1($pdo,$paired);}
    catch(RuntimeException $error){if($error instanceof PDOException)throw $error;}
    $base=['state'=>'sign_in_required','enrolled'=>false,'owner_eligible'=>false,'enrollable'=>false,'revision'=>0,
        'enrollment_revision'=>0,'scope_revision'=>null,'device_id'=>$id,'agent'=>null,'conversation_id'=>null,
        'preferences'=>['notifications_enabled'=>false],'counts'=>null,'agents'=>[]];
    // Never expose owner names, Agent choices, enrollment state or a CSRF token to a paired holder.
    try {
        $live=vp3_phone_agent_workspace_owner_v1($pdo,$user,true);
        vp3_phone_agent_workspace_device_v1($pdo,(int)$live['id'],$token,$id,$now,true);
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException||!in_array($error->getCode(),[401,403],true)) throw $error;
        if(vp3_phone_secret_valid_v1($token)){
            $d=vp3_phone_sql_v1($pdo,"SELECT id FROM vp3_phone_devices_v1 WHERE id=? AND token_hash=? AND device_status='approved' AND expires_at>?",[$id,vp3_extension_hash_v2000($token),$now])->fetchColumn();
            if($d)$base['state']='approval_required';
        }
        return $base;
    }
    if (!vp3_phone_agent_workspace_ready_v1($pdo)) return array_replace($base,['state'=>'unavailable']);
    $owner=(int)$live['id'];$e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$id);
    $agents=[vp3_phone_agent_workspace_agent_v1($pdo,$owner,0)];
    foreach(vp3_phone_sql_v1($pdo,'SELECT id,display_name FROM user_agents WHERE owner_user_id=? AND is_active=1 ORDER BY display_name,id LIMIT 100',[$owner])->fetchAll(PDO::FETCH_ASSOC) as $a)
        $agents[]=['id'=>(int)$a['id'],'name'=>mb_substr((string)$a['display_name'],0,190)];
    $csrf=$_SESSION['csrf_token']??null;
    if (!is_string($csrf)||$csrf==='') { $csrf=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$csrf; }
    $base=array_replace($base,['state'=>'not_enrolled','owner_eligible'=>true,'enrollable'=>true,'revision'=>(int)($e['revision']??0),
        'enrollment_revision'=>(int)($e['revision']??0),'agents'=>$agents,'agent'=>$agents[0],'csrf_token'=>$csrf]);
    try { $scope=vp3_phone_agent_workspace_scope_v1($pdo,$live,$token,$id,false,$now); }
    catch (RuntimeException $error) {
        if ($error instanceof PDOException||!in_array($error->getCode(),[401,403],true)) throw $error;
        return $base;
    }
    return array_replace($base,vp3_phone_agent_workspace_envelope_v1($scope),['state'=>'ready','enrolled'=>true,
        'agent'=>$scope['agent'],'conversation_id'=>(int)$scope['enrollment']['conversation_id'],
        'preferences'=>['notifications_enabled'=>(bool)$scope['enrollment']['notifications_enabled']]]);
}

function vp3_phone_agent_workspace_primary_chat_v1(PDO $pdo,array $user,int $agent): int
{
    $row=vp3_phone_sql_v1($pdo,'SELECT id FROM chat_conversations WHERE user_id=? AND '.($agent>0?'user_agent_id=?':'user_agent_id IS NULL')
        .' ORDER BY id DESC LIMIT 1',$agent>0?[(int)$user['id'],$agent]:[(int)$user['id']])->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['id'];
    $chat=vp3_agent_chat_create_conversation_v380($pdo,$user,$agent>0?['id'=>$agent]:null,'Phone Agent');
    return (int)$chat['id'];
}

function vp3_phone_agent_workspace_cursor_v1(array $scope,string $filter,int $limit,int $after,int $now): string
{
    $key=hash('sha256',vp3_phone_agent_workspace_key_v1(),true);$iv=random_bytes(12);$tag='';
    $plain=json_encode(['scope'=>$scope['scope_revision'],'filter'=>$filter,'limit'=>$limit,'after'=>$after,'expires'=>$now+900],JSON_THROW_ON_ERROR);
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'phone-agent-workspace-cursor-v1');
    vp3_phone_agent_workspace_need_v1(is_string($cipher),503);
    return 'paw1.'.rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'=');
}

function vp3_phone_agent_workspace_cursor_open_v1(array $scope,string $filter,int $limit,string $cursor,int $now): int
{
    vp3_phone_agent_workspace_need_v1(strlen($cursor)<=2048&&preg_match('/^paw1\.[A-Za-z0-9_-]+$/D',$cursor)===1,409);
    $raw=base64_decode(strtr(substr($cursor,5),'-_','+/'),true);
    vp3_phone_agent_workspace_need_v1(is_string($raw)&&strlen($raw)>28,409);
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',vp3_phone_agent_workspace_key_v1(),true),OPENSSL_RAW_DATA,
        substr($raw,0,12),substr($raw,12,16),'phone-agent-workspace-cursor-v1');
    $v=is_string($plain)?json_decode($plain,true,6):null;
    vp3_phone_agent_workspace_need_v1(is_array($v)&&array_keys($v)===['scope','filter','limit','after','expires']
        &&$v['scope']===$scope['scope_revision']&&$v['filter']===$filter&&$v['limit']===$limit
        &&is_int($v['after'])&&$v['after']>0&&is_int($v['expires'])&&$v['expires']>$now&&$v['expires']<=$now+900,409);
    return $v['after'];
}

function vp3_phone_agent_workspace_matches_v1(array $item,string $filter,int $now): bool
{
    $r=$item['receipt'];$snoozed=$r['snoozed_until']!==null&&strtotime($r['snoozed_until'])>$now;
    $history=$r['acknowledged_at']!==null||in_array($item['state'],['completed','unavailable'],true);
    return match($filter){'all'=>true,'history'=>$history,'needs_you'=>$item['needs_action']&&!$history&&!$snoozed,
        'for_you'=>!$item['needs_action']&&!$history&&!$snoozed,default=>false};
}

function vp3_phone_agent_workspace_feed_v1(PDO $pdo,array $scope,array $input): array
{
    $filter=$input['filter']??'all';$limit=$input['limit']??10;$now=$scope['now'];
    vp3_phone_agent_workspace_need_v1(in_array($filter,['all','needs_you','for_you','history'],true)&&is_int($limit)&&$limit>=1&&$limit<=20);
    $after=null;
    if (isset($input['cursor'])) {
        vp3_phone_agent_workspace_need_v1(is_string($input['cursor']));
        $after=vp3_phone_agent_workspace_cursor_open_v1($scope,$filter,$limit,$input['cursor'],$now);
    }
    vp3_phone_agent_workspace_adapter_v1();
    $discovery=vp3_phone_agent_sources_discover_v1($pdo,$scope['user'],$scope['agent']['id'],$now,40);
    vp3_phone_agent_workspace_need_v1(is_array($discovery['items']??null)&&count($discovery['items'])<=100,503);
    vp3_phone_agent_workspace_ingest_v1($pdo,(int)$scope['user']['id'],$scope['agent']['id'],$discovery['items'],$now);
    $rows=vp3_phone_sql_v1($pdo,'SELECT item_ref,sequence_id FROM vp3_phone_agent_items_v1 WHERE owner_user_id=? AND agent_id=?'
        .($after!==null?' AND sequence_id<?':'').' ORDER BY sequence_id DESC LIMIT 101',
        $after!==null?[(int)$scope['user']['id'],$scope['agent']['id'],$after]:[(int)$scope['user']['id'],$scope['agent']['id']])->fetchAll(PDO::FETCH_ASSOC);
    // Explicit Canvas deliveries stay visible even when their canonical item is
    // older than the first feed page. Keep the ordinary sequence cursor intact.
    $pinned=[];$pinRefs=[];
    if($filter!=='history'&&vp3_phone_agent_device_grants_ready_v1($pdo)){
        $sent=vp3_phone_sql_v1($pdo,'SELECT item_ref,MAX(created_epoch) AS sent_epoch FROM vp3_phone_canvas_deliveries_v1
            WHERE owner_user_id=? AND device_id=? AND grant_id=? AND created_epoch>?
            GROUP BY item_ref ORDER BY sent_epoch DESC,item_ref LIMIT 5',
            [(int)$scope['user']['id'],$scope['device']['id'],$scope['enrollment']['grant_id'],$now-86400])->fetchAll(PDO::FETCH_ASSOC);
        foreach($sent as $delivery){
            try{$item=vp3_phone_agent_workspace_item_v1($pdo,$scope,$delivery['item_ref'])['item'];}
            catch(RuntimeException $error){if(!$error instanceof PDOException&&in_array($error->getCode(),[403,404],true))continue;throw $error;}
            if($item['receipt']['read_at']!==null||$item['receipt']['acknowledged_at']!==null
                ||($item['receipt']['snoozed_until']!==null&&strtotime($item['receipt']['snoozed_until'])>$now)
                ||!vp3_phone_agent_workspace_matches_v1($item,$filter,$now))continue;
            if(count($pinned)>=$limit)break;
            $pinned[]=['sequence'=>0,'item'=>$item];$pinRefs[$item['item_ref']]=true;
        }
    }
    $items=$after===null?$pinned:[];$next=null;$lastScanned=null;$lastAccepted=null;
    foreach(array_slice($rows,0,100) as $row) {
        $lastScanned=(int)$row['sequence_id'];
        if(isset($pinRefs[$row['item_ref']]))continue;
        try { $resolved=vp3_phone_agent_workspace_item_v1($pdo,$scope,$row['item_ref']); }
        catch(RuntimeException $e){if (!$e instanceof PDOException&&in_array($e->getCode(),[403,404],true))continue;throw $e;}
        if (!vp3_phone_agent_workspace_matches_v1($resolved['item'],$filter,$now)) continue;
        if (count($items)===$limit) { $next=$lastAccepted??((int)$row['sequence_id']+1);break; }
        $items[]=['sequence'=>(int)$row['sequence_id'],'item'=>$resolved['item']];
        $lastAccepted=(int)$row['sequence_id'];
    }
    if ($next===null&&count($rows)>100) $next=$lastScanned;
    $counts=['unread'=>0,'needs_action'=>0,'complete'=>true];
    $countRows=vp3_phone_sql_v1($pdo,'SELECT item_ref FROM vp3_phone_agent_items_v1 WHERE owner_user_id=? AND agent_id=? ORDER BY sequence_id DESC LIMIT 201',
        [(int)$scope['user']['id'],$scope['agent']['id']])->fetchAll(PDO::FETCH_ASSOC);
    $counts['complete']=count($countRows)<=200&&empty($discovery['has_more']);
    foreach (($discovery['coverage']??[]) as $coverage) if (!in_array($coverage,['ready','not_supported'],true)) $counts['complete']=false;
    foreach(array_slice($countRows,0,200) as $row) {
        try{$item=vp3_phone_agent_workspace_item_v1($pdo,$scope,$row['item_ref'])['item'];}
        catch(RuntimeException $e){if(!$e instanceof PDOException&&in_array($e->getCode(),[403,404],true))continue;throw $e;}
        if ($item['receipt']['read_at']===null) $counts['unread']++;
        if (vp3_phone_agent_workspace_matches_v1($item,'needs_you',$now)) $counts['needs_action']++;
    }
    return ['items'=>array_column($items,'item'),'counts'=>$counts,'has_more'=>$next!==null,
        'next_cursor'=>$next===null?null:vp3_phone_agent_workspace_cursor_v1($scope,$filter,$limit,$next,$now),
        'coverage'=>$discovery['coverage']??[]];
}

function vp3_phone_agent_workspace_mutate_v1(PDO $pdo,array $scope,array $input): array
{
    $operation=$input['operation']??null;
    vp3_phone_agent_workspace_need_v1(in_array($operation,['seen','read','ack','snooze','unsnooze'],true)
        &&is_int($input['expected_revision']??null)&&$input['expected_revision']>0);
    $value=null;$now=$scope['now'];
    if ($operation==='snooze') {
        $raw=$input['snooze_until']??null;
        vp3_phone_agent_workspace_need_v1(is_string($raw)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$raw)===1);
        $value=strtotime($raw);
        vp3_phone_agent_workspace_need_v1($value!==false&&gmdate('Y-m-d\TH:i:s\Z',$value)===$raw&&$value>$now&&$value<=$now+30*86400);
    } else vp3_phone_agent_workspace_need_v1(!array_key_exists('snooze_until',$input));
    $current=vp3_phone_agent_workspace_item_v1($pdo,$scope,vp3_phone_agent_workspace_id_v1($input['item_ref']??null),$input['expected_revision']);
    $row=$current['row'];$receipt=$current['receipt'];$e=$scope['enrollment'];
    $column=['seen'=>'seen_epoch','read'=>'read_epoch','ack'=>'acknowledged_epoch','snooze'=>'snoozed_until','unsnooze'=>'snoozed_until'][$operation];
    $new=in_array($operation,['snooze','unsnooze'],true)?$value:($receipt[$column]??$now);
    if (($receipt[$column]??null)===$new) return ['item'=>$current['item']];
    $receipt[$column]=$new;
    if(in_array($operation,['snooze','unsnooze'],true)){
        vp3_phone_agent_workspace_need_v1((int)($receipt['wake_revision']??0)<9007199254740990,409);
        $receipt['wake_revision']=(int)($receipt['wake_revision']??0)+1;$receipt['wake_requested_epoch']=$now;
    }
    $params=[(int)$e['owner_user_id'],$e['device_id'],$e['grant_id'],$row['item_ref'],$row['source_revision'],(int)$receipt['revision']+1,
        $receipt['seen_epoch']??null,$receipt['read_epoch']??null,$receipt['acknowledged_epoch']??null,$receipt['snoozed_until']??null,$now,(int)($receipt['wake_revision']??0),(int)($receipt['wake_requested_epoch']??0)];
    $exists=vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_agent_receipts_v1 WHERE owner_user_id=? AND device_id=? AND grant_id=? AND item_ref=?',array_slice($params,0,4))->fetchColumn();
    if ($exists) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_receipts_v1 SET source_revision=?,revision=?,seen_epoch=?,read_epoch=?,acknowledged_epoch=?,snoozed_until=?,updated_epoch=?,wake_revision=?,wake_requested_epoch=? WHERE owner_user_id=? AND device_id=? AND grant_id=? AND item_ref=?',
        [...array_slice($params,4),...array_slice($params,0,4)]);
    else vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_agent_receipts_v1(owner_user_id,device_id,grant_id,item_ref,source_revision,revision,seen_epoch,read_epoch,acknowledged_epoch,snoozed_until,updated_epoch,wake_revision,wake_requested_epoch) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',$params);
    return ['item'=>vp3_phone_agent_workspace_item_v1($pdo,$scope,$row['item_ref'])['item']];
}

/** Strict private endpoint. Every action binds approved pairing and the current session CSRF. */
function vp3_phone_agent_workspace_request_v1(PDO $pdo,?array $user,string $token,array $input,?int $now=null): array
{
    $now??=time();$action=$input['action']??null;$id=vp3_phone_agent_workspace_id_v1($input['device_id']??null);
    $common=['action','device_id','csrf_token','enrollment_revision','scope_revision'];
    $fields=['status'=>[],'enroll'=>['confirmed','agent_id','expected_revision'],'select_agent'=>['agent_id','expected_revision'],
        'preferences'=>['notifications_enabled','expected_revision'],'revoke'=>['expected_revision'],
        'feed'=>['filter','limit','cursor'],'detail'=>['item_ref','expected_revision'],'discuss'=>['item_ref','expected_revision'],
        'mutate'=>['item_ref','expected_revision','operation','snooze_until']];
    vp3_phone_agent_workspace_need_v1(is_string($action)&&isset($fields[$action])&&!array_diff(array_keys($input),[...$common,...$fields[$action]]));
    if ($action==='status') return vp3_phone_agent_workspace_status_v1($pdo,$user,$token,$id,$now);
    if($action==='enroll')vp3_phone_agent_workspace_owner_v1($pdo,$user);
    else vp3_phone_agent_workspace_scope_v1($pdo,$user,$token,$id,false,$now);
    vp3_phone_agent_workspace_need_v1(is_string($input['csrf_token']??null)&&is_string($_SESSION['csrf_token']??null)
        &&$_SESSION['csrf_token']!==''&&hash_equals($_SESSION['csrf_token'],$input['csrf_token']),419);
    vp3_phone_agent_workspace_need_v1(vp3_phone_agent_workspace_ready_v1($pdo),503);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$user,$token,$input,$action,$id,$now):array {
        $paired=$action==='enroll'?null:vp3_phone_agent_device_scope_v1($pdo,$token,$id,true,$now);
        $live=$paired['user']??vp3_phone_agent_workspace_owner_v1($pdo,$user,true);$owner=(int)$live['id'];
        if($paired!==null)vp3_phone_agent_workspace_need_v1($action!=='select_agent',403);
        $device=vp3_phone_agent_workspace_device_v1($pdo,$owner,$token,$id,$now,true);
        $e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$id,true);
        if ($action==='enroll') {
            $agent=$input['agent_id']??0;
            vp3_phone_agent_workspace_need_v1(($input['confirmed']??null)===true&&is_int($agent)&&$agent>=0&&is_int($input['expected_revision']??null));
            vp3_phone_agent_workspace_need_v1($input['expected_revision']===(int)($e['revision']??0),409);
            vp3_phone_agent_workspace_agent_v1($pdo,$owner,$agent);
            $cid=vp3_phone_agent_workspace_primary_chat_v1($pdo,$live,$agent);$grant=bin2hex(random_bytes(16));
            if ($e) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_enrollments_v1 SET grant_id=?,credential_hash=?,owner_fingerprint=?,enabled=1,revision=revision+1,selected_agent_id=?,conversation_id=?,notifications_enabled=0,notifications_since_epoch=0,updated_epoch=? WHERE owner_user_id=? AND device_id=?',
                [$grant,$device['token_hash'],$_SESSION['auth_password_fingerprint'],$agent,$cid,$now,$owner,$id]);
            else vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_agent_enrollments_v1(owner_user_id,device_id,grant_id,credential_hash,owner_fingerprint,enabled,selected_agent_id,conversation_id,created_epoch,updated_epoch) VALUES(?,?,?,?,?,1,?,?,?,?)',
                [$owner,$id,$grant,$device['token_hash'],$_SESSION['auth_password_fingerprint'],$agent,$cid,$now,$now]);
            return vp3_phone_agent_workspace_status_v1($pdo,$live,$token,$id,$now);
        }
        $scope=vp3_phone_agent_workspace_scope_v1($pdo,$live,$token,$id,true,$now);
        vp3_phone_agent_workspace_need_v1(is_int($input['enrollment_revision']??null)&&$input['enrollment_revision']===(int)$scope['enrollment']['revision']
            &&is_string($input['scope_revision']??null)&&hash_equals($scope['scope_revision'],$input['scope_revision']),409);
        if (in_array($action,['select_agent','preferences','revoke'],true)) {
            vp3_phone_agent_workspace_need_v1(is_int($input['expected_revision']??null)&&$input['expected_revision']===(int)$e['revision'],409);
            if ($action==='select_agent') {
                vp3_phone_agent_workspace_need_v1(is_int($input['agent_id']??null)&&$input['agent_id']>=0);
                $agent=$input['agent_id'];vp3_phone_agent_workspace_agent_v1($pdo,$owner,$agent);
                $cid=vp3_phone_agent_workspace_primary_chat_v1($pdo,$live,$agent);
                vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_enrollments_v1 SET selected_agent_id=?,conversation_id=?,notifications_since_epoch=?,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND device_id=?',[$agent,$cid,!empty($e['notifications_enabled'])?$now:0,$now,$owner,$id]);
            } elseif ($action==='preferences') {
                vp3_phone_agent_workspace_need_v1(is_bool($input['notifications_enabled']??null));
                $since=$input['notifications_enabled']?(!empty($e['notifications_enabled'])?(int)$e['notifications_since_epoch']:$now):0;
                vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_enrollments_v1 SET notifications_enabled=?,notifications_since_epoch=?,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND device_id=?',[(int)$input['notifications_enabled'],$since,$now,$owner,$id]);
            } else vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0,notifications_enabled=0,notifications_since_epoch=0,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND device_id=?',[$now,$owner,$id]);
            return vp3_phone_agent_workspace_status_v1($pdo,$live,$token,$id,$now);
        }
        if ($action==='feed') $result=vp3_phone_agent_workspace_feed_v1($pdo,$scope,$input);
        elseif ($action==='mutate') $result=vp3_phone_agent_workspace_mutate_v1($pdo,$scope,$input);
        else {
            $expected=$input['expected_revision']??null;
            vp3_phone_agent_workspace_need_v1($expected===null||(is_int($expected)&&$expected>0));
            $current=vp3_phone_agent_workspace_item_v1($pdo,$scope,vp3_phone_agent_workspace_id_v1($input['item_ref']??null),$expected,$action==='detail');
            if ($action==='detail') $result=['item'=>$current['item'],'detail'=>$current['detail']];
            else {
                $target=['kind'=>'chat','agent_id'=>$scope['agent']['id'],'conversation_id'=>$current['item']['conversation_id']];
                if (($current['source']['target']['kind']??null)==='chat_message') $target=$current['source']['target'];
                $result=['item'=>$current['item'],'target'=>$target];
            }
        }
        $last=vp3_phone_agent_workspace_scope_v1($pdo,$live,$token,$id,true,$now);
        vp3_phone_agent_workspace_need_v1(hash_equals($scope['scope_revision'],$last['scope_revision']),409);
        return vp3_phone_agent_workspace_envelope_v1($last)+$result;
    });
}

/** Restricted background predicate: no session creation and no owner payload returned to holder APIs. */
function vp3_phone_agent_workspace_push_current_v1(PDO $pdo,array $device,array $source,int $now): bool
{
    if (($source['type']??null)!=='agent_item'||!is_string($source['id']??null)
        ||preg_match('/^[a-f0-9]{32}$/D',$source['id'])!==1||!is_int($source['revision']??null)||$source['revision']<1
        ||!is_int($source['expires_epoch']??null)||$source['expires_epoch']<=$now||!vp3_phone_agent_workspace_ready_v1($pdo)) return false;
    try {
        $owner=(int)$device['owner_user_id'];$user=vp3_phone_agent_workspace_user_v1($pdo,$owner);
        $live=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?',[$owner,$device['id']])->fetch(PDO::FETCH_ASSOC);
        $e=vp3_phone_agent_workspace_enrollment_v1($pdo,$owner,$device['id']);
        if (!$live||$live['device_status']!=='approved'||(int)$live['expires_at']<=$now||!hash_equals($live['token_hash'],$device['token_hash'])
            ||!$e||!(int)$e['enabled']||!(int)$e['notifications_enabled']||!hash_equals($e['credential_hash'],$live['token_hash'])
            ||!hash_equals($e['owner_fingerprint'],hash('sha256',$owner.':'.(string)($user['password_hash']??'')))) return false;
        if(isset($source['grant_id'])&&$source['grant_id']!==$e['grant_id'])return false;
        $agent=vp3_phone_agent_workspace_agent_v1($pdo,$owner,(int)$e['selected_agent_id']);
        vp3_phone_agent_workspace_chat_v1($pdo,$owner,$agent['id'],(int)$e['conversation_id']);
        $row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_items_v1 WHERE owner_user_id=? AND agent_id=? AND item_ref=?',[$owner,$agent['id'],$source['id']])->fetch(PDO::FETCH_ASSOC);
        if (!$row||(int)$row['revision']!==$source['revision']) return false;
        $native=vp3_phone_agent_workspace_source_v1($pdo,$user,$agent['id'],$row['source_kind'],$row['source_id'],$now);
        if (!$native||!hash_equals($row['source_revision'],$native['source_revision'])||$native['conversation_id']!==(int)$row['conversation_id']
            ||!in_array($native['state'],['ready','needs_review'],true)||($native['notification_eligible']??true)===false) return false;
        $receipt=vp3_phone_agent_workspace_receipt_v1($pdo,['enrollment'=>$e],$row);
        return (int)($source['wake_revision']??0)===(int)($receipt['wake_revision']??0)
            &&($receipt['read_epoch']??null)===null&&($receipt['acknowledged_epoch']??null)===null&&(int)($receipt['snoozed_until']??0)<=$now;
    } catch (RuntimeException|DomainException $error) {
        if ($error instanceof PDOException) throw $error;
        return false;
    }
}

require_once __DIR__.'/phone-agent-device-grants-v1.php';
