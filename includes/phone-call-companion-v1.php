<?php
declare(strict_types=1);

/** Call companion uses the existing approved handset and information exchange. */
function vp3_phone_companion_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT connected_phone_id FROM crm_contacts WHERE 1=0');
        $pdo->query('SELECT id,owner_user_id,device_name,device_status,generation,expires_at FROM vp3_phone_devices_v1 WHERE 1=0');
        $pdo->query('SELECT owner_user_id,call_id,contact_id,device_id,exchange_id FROM phone_call_shares_v1 WHERE 1=0');
        return true;
    }catch(PDOException){return false;}
}
function vp3_phone_companion_devices_v1(PDO $pdo,int $owner): array
{
    if(!vp3_phone_companion_ready_v1($pdo)||!vp3_phone_voicemail_owner_private_v1())return [];
    $q=$pdo->prepare("SELECT id,device_name FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND device_status='approved' AND expires_at>? ORDER BY device_name,id LIMIT 100");
    $q->execute([$owner,time()]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function vp3_phone_companion_assign_v1(PDO $pdo,array $scope,array $input): array
{
    require_once __DIR__.'/phone-contact-management-v1.php';
    $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);
    $id=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $device=$input['device_id']??null;
    if(!is_string($device)||($device!==''&&!preg_match('/^[a-f0-9]{32}$/D',$device)))vp3_phone_workspace_error_v1('phone_contact_invalid',422,'Choose an approved phone.');
    if(!vp3_phone_companion_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_upgrade_required',503,'Upgrade the Phone workspace first.');
    return section12_owner_transaction($pdo,$scope['owner_user_id'],function()use($pdo,$scope,$input,$id,$device){
        $row=vp3_phone_contact_row_v1($pdo,$scope,$id);
        if(!is_string($input['expected_revision']??null)||!hash_equals(section12_revision($row),$input['expected_revision']))
            vp3_phone_workspace_error_v1('phone_contact_changed',409,'Reload this contact before changing its connected phone.');
        if($device!==''){
            $q=$pdo->prepare("SELECT id FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=? AND device_status='approved' AND expires_at>?".vp3_phone_workspace_lock_v1($pdo));
            $q->execute([$scope['owner_user_id'],$device,time()]);
            if(!$q->fetchColumn())vp3_phone_workspace_error_v1('phone_contact_changed',409,'That connected phone is no longer available.');
        }
        $pdo->prepare('UPDATE crm_contacts SET connected_phone_id=?,updated_at=? WHERE owner_user_id=? AND id=?')->execute([$device?:null,gmdate('Y-m-d H:i:s'),$scope['owner_user_id'],$id]);
        return ['assigned'=>true];
    });
}
function vp3_phone_companion_share_v1(PDO $pdo,array $scope,array $input): array
{
    require_once __DIR__.'/phone-tracking-v1.php';
    require_once __DIR__.'/phone-contact-management-v1.php';
    $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);
    $public=vp3_phone_information_id_v1($input['call_id']??null);
    $exchange=vp3_phone_information_id_v1($input['exchange_id']??null);
    $device=vp3_phone_information_id_v1($input['device_id']??null);
    $contactId=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $text=vp3_phone_information_text_v1($input['message']??null);
    if(!vp3_phone_companion_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_upgrade_required',503,'Upgrade the Phone workspace first.');
    return vp3_phone_transaction_v1($pdo,function()use($pdo,$scope,$public,$exchange,$device,$contactId,$text){
        $owner=$scope['owner_user_id'];vp3_phone_account_v1($pdo,$owner,true);
        $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE owner_user_id=? AND public_id=? AND transport_kind='browser'".vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,$public]);$call=$q->fetch(PDO::FETCH_ASSOC);
        if(!$call||$scope['conversation_id']<1||(int)$call['conversation_id']!==$scope['conversation_id']||(int)$call['chat_agent_id']!==$scope['agent_id']||$call['state']!=='answered'||!vp3_phone_browser_live_call_v1($pdo,$call))
            vp3_phone_workspace_error_v1('phone_call_changed',409,'The call is no longer active in this Chat.');
        $known=vp3_phone_caller_contacts_lookup_v1($pdo,$owner,[$call['peer_e164']]);
        $contact=vp3_phone_contact_row_v1($pdo,$scope,$contactId);
        if((int)($known[$call['peer_e164']]['id']??0)!==$contactId||($contact['connected_phone_id']??'')!==$device)
            vp3_phone_workspace_error_v1('phone_contact_changed',409,'The contact or linked phone changed.');
        $d=vp3_phone_row_v1($pdo,$owner,$device);
        $profile=vp3_phone_communication_row_v1($pdo,$d,true);
        $args=['device_id'=>$device,'profile_id'=>$profile['profile_id'],'generation'=>(int)$d['generation'],'profile_revision'=>(int)$profile['revision'],'kind'=>'update','message'=>$text,'exchange_id'=>$exchange];
        $q=$pdo->prepare('SELECT call_id,contact_id,device_id FROM phone_call_shares_v1 WHERE owner_user_id=? AND exchange_id=?');
        $q->execute([$owner,$exchange]);$old=$q->fetch(PDO::FETCH_ASSOC);
        if($old&&($old['call_id']!==$public||(int)$old['contact_id']!==$contactId||$old['device_id']!==$device))
            vp3_phone_workspace_error_v1('phone_call_changed',409,'This share belongs to another call.');
        vp3_phone_information_action_v1($pdo,$d,'information_create',$args,time(),false);
        if(!$old)$pdo->prepare('INSERT INTO phone_call_shares_v1(owner_user_id,call_id,contact_id,device_id,exchange_id) VALUES(?,?,?,?,?)')->execute([$owner,$public,$contactId,$device,$exchange]);
        return ['shared'=>true,'exchange_id'=>$exchange];
    });
}

/** Only project exchanges shared on this call into its original private Chat. */
function vp3_phone_companion_cards_v1(PDO $pdo,array $scope,array $calls): array
{
    if(!$scope['conversation_id']||!vp3_phone_voicemail_owner_private_v1()||!vp3_phone_companion_ready_v1($pdo)||
        !table_exists('vp3_phone_communication_profiles_v1')||!table_exists('vp3_phone_information_v1'))return [];
    $contacts=[];
    foreach($calls as $call)if(($call['source_kind']??'')==='browser'&&$call['conversation_id']===$scope['conversation_id']&&$call['contact_id']>0)$contacts[$call['contact_id']]=true;
    if(!$contacts)return [];
    $ids=array_keys($contacts);$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT c.id,d.id AS device_id,d.device_name,p.profile_id,p.revision FROM crm_contacts c
        JOIN vp3_phone_devices_v1 d ON d.id=c.connected_phone_id AND d.owner_user_id=c.owner_user_id
        JOIN vp3_phone_communication_profiles_v1 p ON p.device_id=d.id AND p.owner_user_id=d.owner_user_id
        WHERE c.owner_user_id=? AND c.id IN ($marks) AND c.status<>'archived' AND d.device_status='approved' AND d.expires_at>? AND p.owner_requests=1 AND p.holder_requests=1");
    $q->execute([$scope['owner_user_id'],...$ids,time()]);$devices=$q->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);$cards=[];
    foreach($calls as $call){
        $d=$devices[$call['contact_id']]??null;
        if(!$d||$call['conversation_id']!==$scope['conversation_id']||$call['source_kind']!=='browser'||!empty($call['connected_phone'])||isset($call['transport_kind']))continue;
        $cards[$call['public_id']]=['contact_id'=>$call['contact_id'],'device_id'=>$d['device_id'],'name'=>$d['device_name'],'items'=>[]];
    }
    if(!$cards)return [];
    $keys=array_keys($cards);$marks=implode(',',array_fill(0,count($keys),'?'));
    $q=$pdo->prepare("SELECT s.call_id,s.device_id,s.contact_id,i.message,i.state,i.created_epoch FROM phone_call_shares_v1 s
        JOIN vp3_phone_information_v1 i ON i.owner_user_id=s.owner_user_id AND i.device_id=s.device_id AND i.exchange_id=s.exchange_id
        JOIN vp3_phone_communication_profiles_v1 p ON p.owner_user_id=i.owner_user_id AND p.device_id=i.device_id AND p.profile_id=i.profile_id AND p.revision=i.profile_revision
        WHERE s.owner_user_id=? AND s.call_id IN ($marks) AND i.state IN ('pending','acknowledged') AND i.expires_epoch>? ORDER BY i.created_epoch,i.exchange_id");
    $q->execute([$scope['owner_user_id'],...$keys,time()]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $item){
        $id=$item['call_id'];
        if($cards[$id]['device_id']===$item['device_id']&&$cards[$id]['contact_id']===(int)$item['contact_id'])
            $cards[$id]['items'][]=['message'=>$item['message'],'state'=>$item['state']];
    }
    return $cards;
}
