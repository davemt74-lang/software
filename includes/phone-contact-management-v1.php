<?php
declare(strict_types=1);

/** Phone-friendly editing of the existing owner CRM. No calls or external messages. */
require_once __DIR__.'/phone-caller-contacts-v1.php';
require_once __DIR__.'/phone-caller-contacts-schema-v1.php';
require_once __DIR__.'/section12-data.php';

function vp3_phone_contact_private_v1(PDO $pdo,array $scope,array $input): array
{
    $scope=vp3_phone_workspace_scope_v1($pdo,$scope['user'],$input);
    if(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)
        vp3_phone_workspace_error_v1('phone_contact_private',403,'Open your private Phone workspace to manage contacts.');
    return $scope;
}
function vp3_phone_contact_text_v1(mixed $value,string $label,int $limit,bool $required=false,bool $multiline=false): string
{
    if(!is_string($value)||preg_match('//u',$value)!==1)
        vp3_phone_workspace_error_v1('phone_contact_invalid',422,'Enter valid '.$label.'.');
    $value=trim(str_replace(["\r\n","\r"],"\n",$value));
    if(($required&&$value==='')||mb_strlen($value,'UTF-8')>$limit||preg_match($multiline?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u':'/[\x00-\x1F\x7F]/u',$value))
        vp3_phone_workspace_error_v1('phone_contact_invalid',422,'Enter '.$label.' up to '.number_format($limit).' characters.');
    return $value;
}
function vp3_phone_contact_row_v1(PDO $pdo,array $scope,int $id): array
{
    $q=$pdo->prepare("SELECT * FROM crm_contacts WHERE id=? AND owner_user_id=? AND status<>'archived'");
    $q->execute([$id,$scope['owner_user_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$row)vp3_phone_workspace_error_v1('phone_contact_unavailable',404,'This saved contact is unavailable.');
    return $row;
}
function vp3_phone_contact_public_v1(array $row): array
{
    return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'phone_e164'=>vp3_phone_caller_contacts_e164_v1((string)$row['phone']),
        'relationship'=>(string)($row['relationship']??''),'notes'=>(string)($row['notes']??''),'revision'=>section12_revision($row)];
}
function vp3_phone_contact_detail_v1(PDO $pdo,array $scope,array $input): array
{
    if(array_diff(array_keys($input),['action','agent_id','conversation_id','contact_id']))
        vp3_phone_workspace_error_v1('phone_contact_invalid',422,'Choose a saved contact.');
    $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);
    $id=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $row=vp3_phone_contact_row_v1($pdo,$scope,$id);
    require_once __DIR__.'/phone-contact-schedules-v1.php';
    return ['contact'=>vp3_phone_contact_public_v1($row),'editable'=>vp3_phone_contact_details_schema_ready_v1($pdo),
        'schedules'=>vp3_phone_contact_schedule_book_v1($pdo,$scope,$id,time())];
}
/** Keep alternate channels; only the chosen canonical phone becomes primary. */
function vp3_phone_contact_channel_v1(PDO $pdo,int $id,string $phone,string $now): void
{
    if(!vp3_phone_caller_contacts_table_v1($pdo,'crm_contact_phones'))return;
    $pdo->prepare('UPDATE crm_contact_phones SET is_primary=0 WHERE contact_id=?')->execute([$id]);
    $q=$pdo->prepare('SELECT id FROM crm_contact_phones WHERE contact_id=? AND phone_normalized=? LIMIT 1');
    $q->execute([$id,substr($phone,1)]);$channel=(int)$q->fetchColumn();$q->closeCursor();
    if($channel)$pdo->prepare('UPDATE crm_contact_phones SET phone=?,is_primary=1,updated_at=? WHERE id=? AND contact_id=?')->execute([$phone,$now,$channel,$id]);
    else $pdo->prepare("INSERT INTO crm_contact_phones(contact_id,phone,phone_normalized,label,is_primary,created_at,updated_at) VALUES(?,?,?,'primary',1,?,?)")->execute([$id,$phone,substr($phone,1),$now,$now]);
}
function vp3_phone_contact_save_v1(PDO $pdo,array $scope,array $input): array
{
    if(array_diff(array_keys($input),['action','csrf_token','agent_id','conversation_id','contact_id','expected_revision','name','phone','relationship','notes']))
        vp3_phone_workspace_error_v1('phone_contact_invalid',422,'Choose supported contact fields.');
    $id=vp3_phone_workspace_id_v1($input['contact_id']??0,true);
    $name=vp3_phone_contact_text_v1($input['name']??null,'a contact name',120,true);
    $relationship=vp3_phone_contact_text_v1($input['relationship']??'','a relationship',80);
    $notes=vp3_phone_contact_text_v1($input['notes']??'','notes',2000,false,true);
    $raw=vp3_phone_contact_text_v1($input['phone']??null,'an international phone number',80,true);
    $phone=vp3_phone_caller_contacts_e164_v1($raw);
    if($phone==='')vp3_phone_workspace_error_v1('phone_contact_invalid',422,'Enter a phone number with its country code, starting with +.');
    $expected=$input['expected_revision']??'';
    if(!is_string($expected)||($id>0&&!preg_match('/^[a-f0-9]{64}$/D',$expected))||($id===0&&$expected!==''))
        vp3_phone_workspace_error_v1('phone_contact_changed',409,'Reload this contact before saving.');
    if(!vp3_phone_contact_details_schema_ready_v1($pdo))
        vp3_phone_workspace_error_v1('phone_contacts_upgrade_required',503,'Run the Cloud database upgrade to edit phone contact details.');
    return section12_owner_transaction($pdo,$scope['owner_user_id'],function()use($pdo,$scope,$input,$id,$name,$relationship,$notes,$phone,$expected):array{
        $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);$row=$id>0?vp3_phone_contact_row_v1($pdo,$scope,$id):null;
        if($row&&!hash_equals(section12_revision($row),$expected))vp3_phone_workspace_error_v1('phone_contact_changed',409,'This contact changed. Reload it before saving.');
        $known=vp3_phone_caller_contacts_lookup_v1($pdo,$scope['owner_user_id'],[$phone]);$match=$known[$phone]??null;
        if($match&&$id!==(int)$match['id']){
            $existing=vp3_phone_contact_row_v1($pdo,$scope,(int)$match['id']);
            if($id===0&&$existing['name']===$name&&vp3_phone_caller_contacts_e164_v1((string)$existing['phone'])===$phone
                &&(string)($existing['relationship']??'')===$relationship&&(string)($existing['notes']??'')===$notes)
                return ['contact'=>vp3_phone_contact_public_v1($existing),'created'=>false];
            vp3_phone_workspace_error_v1('phone_contact_exists',409,'This number already belongs to a saved contact. Open that contact to edit it.');
        }
        $now=gmdate('Y-m-d H:i:s');
        if($row){
            $pdo->prepare("UPDATE crm_contacts SET name=?,phone=?,relationship=?,notes=?,updated_at=? WHERE id=? AND owner_user_id=? AND status<>'archived'")
                ->execute([$name,$phone,$relationship,$notes,$now,$id,$scope['owner_user_id']]);
        }else{
            $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
            $public=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $pdo->prepare("INSERT INTO crm_contacts(public_id,owner_user_id,name,email,email_normalized,phone,company,source,status,lifecycle_stage,marketing_status,relationship,notes,created_at,updated_at) VALUES(?,?,?,'',NULL,?,'','phone_workspace','active','','unknown',?,?,?,?)")
                ->execute([$public,$scope['owner_user_id'],$name,$phone,$relationship,$notes,$now,$now]);$id=(int)$pdo->lastInsertId();
        }
        vp3_phone_contact_channel_v1($pdo,$id,$phone,$now);
        return ['contact'=>vp3_phone_contact_public_v1(vp3_phone_contact_row_v1($pdo,$scope,$id)),'created'=>$row===null];
    });
}
