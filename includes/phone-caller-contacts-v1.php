<?php
declare(strict_types=1);

/** Shared owner-private caller identity. Reading never creates contacts or contacts schemas. */
function vp3_phone_caller_contacts_table_v1(PDO $pdo,string $table): bool
{
    if(!in_array($table,['crm_contacts','crm_contact_phones','crm_contact_emails'],true))return false;
    try{$pdo->query('SELECT 1 FROM '.$table.' WHERE 1=0');return true;}catch(PDOException){return false;}
}
function vp3_phone_caller_contacts_digits_v1(string $value): string
{
    $value=trim($value);
    if($value===''||strlen($value)>100||preg_match('/[^0-9+().\s-]/D',$value))return '';
    return preg_replace('/[^0-9]/','',$value)??'';
}
function vp3_phone_caller_contacts_e164_v1(string $value): string
{
    $value=trim($value);$digits=vp3_phone_caller_contacts_digits_v1($value);
    if(str_starts_with($value,'00'))$digits=substr($digits,2);
    elseif(!str_starts_with($value,'+')&&!(strlen($digits)===11&&$digits[0]==='1'))return '';
    return preg_match('/^[1-9][0-9]{7,14}$/D',$digits)?'+'.$digits:'';
}
function vp3_phone_caller_contacts_sql_digits_v1(string $column): string
{
    if(!in_array($column,['c.phone','p.phone_normalized'],true))throw new InvalidArgumentException('Invalid contact phone column.');
    $sql=$column;
    foreach([' ', '+', '-', '(', ')', '.'] as $separator)$sql="REPLACE($sql,'$separator','')";
    foreach([9,10,13] as $code)$sql="REPLACE($sql,CHAR($code),'')";
    return $sql;
}
/**
 * Match a bounded set of carrier E.164 peers to this owner's active Core CRM.
 * Ten-digit local numbers match only a known +1 peer, never a guessed dial target.
 * Primary and alternate phone channels share the same contact identity.
 * @return array<string,array{id:int,name:string,phone_e164:string}>
 */
function vp3_phone_caller_contacts_lookup_v1(PDO $pdo,int $owner,array $phones): array
{
    if($owner<1)return [];$targets=[];$keys=[];
    foreach($phones as $phone){if(!is_string($phone)||!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone))continue;$targets[$phone]=true;if(count($targets)>=100)break;}
    foreach(array_keys($targets) as $phone){$digits=substr($phone,1);foreach([$digits,'00'.$digits] as $key)$keys[$key]=true;if(strlen($digits)===11&&$digits[0]==='1')$keys[substr($digits,1)]=true;}
    if(!$keys)return [];
    $values=array_keys($keys);$marks=implode(',',array_fill(0,count($values),'?'));
    $channels=vp3_phone_caller_contacts_table_v1($pdo,'crm_contact_phones');
    $select=$channels?',p.phone AS channel_phone':",'' AS channel_phone";
    $join=$channels?' LEFT JOIN crm_contact_phones p ON p.contact_id=c.id':'';
    $where=vp3_phone_caller_contacts_sql_digits_v1('c.phone')." IN ($marks)";
    $args=[$owner,...$values];
    if($channels){$where.=' OR '.vp3_phone_caller_contacts_sql_digits_v1('p.phone_normalized')." IN ($marks)";$args=[...$args,...$values];}
    try{$q=$pdo->prepare("SELECT c.id,c.name,c.phone$select FROM crm_contacts c$join WHERE c.owner_user_id=? AND c.status<>'archived' AND ($where) ORDER BY c.id");$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);}
    catch(PDOException){return [];}
    $out=[];
    foreach($rows as $row){
        foreach([(string)$row['phone'],(string)$row['channel_phone']] as $raw){
            $digits=vp3_phone_caller_contacts_digits_v1($raw);if($digits==='')continue;$canonical=vp3_phone_caller_contacts_e164_v1($raw);$matches=[];
            foreach(array_keys($targets) as $phone){$targetDigits=substr($phone,1);
                if(($canonical!==''&&$canonical===$phone)||($canonical===''&&($digits===$targetDigits||(strlen($targetDigits)===11&&$targetDigits[0]==='1'&&$digits===substr($targetDigits,1)))))$matches[]=$phone;
            }
            if(count($matches)!==1)continue;$phone=$matches[0];
            if(!isset($out[$phone]))$out[$phone]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'phone_e164'=>$phone];
        }
    }
    return $out;
}
/** The call reference supplies phone ownership; a browser may supply only the name. */
function vp3_phone_caller_contact_save_v1(PDO $pdo,array $scope,array $input): array
{
    require_once __DIR__.'/phone-caller-contacts-schema-v1.php';
    foreach(['owner_user_id','owner_id','phone','phone_e164','phone_number','number'] as $key)if(array_key_exists($key,$input))vp3_phone_workspace_error_v1('invalid_phone_contact',422,'Save the caller from its call record.');
    $name=$input['name']??null;$ref=$input['call_id']??null;
    if(!is_string($name)||!is_string($ref))vp3_phone_workspace_error_v1('invalid_phone_contact',422,'Enter a contact name and choose a call.');
    $name=trim($name);
    if($name===''||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1F\x7F]/u',$name)||mb_strlen($name,'UTF-8')>120)vp3_phone_workspace_error_v1('invalid_phone_contact',422,'Enter a contact name of 1 to 120 characters.');
    if(!preg_match('/^(?:[a-f0-9]{32}|legacy-[1-9][0-9]{0,14})$/D',$ref))vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call is unavailable.');
    if(!vp3_phone_caller_contacts_schema_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_contacts_upgrade_required',503,'Run the Cloud database upgrade to save phone contacts.');
    return vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$scope,$input,$name,$ref):array{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));$lock->execute([$scope['owner_user_id']]);if(!$lock->fetchColumn())vp3_phone_workspace_error_v1();
        $scope=vp3_phone_workspace_scope_v1($pdo,$scope['user'],$input);
        if(str_starts_with($ref,'legacy-')){
            $q=$pdo->prepare("SELECT caller_e164 AS peer_e164,agent_id FROM phone_voice_calls_v130 WHERE id=? AND owner_user_id=? AND provider='twilio'".vp3_phone_workspace_lock_v1($pdo));$q->execute([(int)substr($ref,7),$scope['owner_user_id']]);
        }else{$q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));$q->execute([$ref,$scope['owner_user_id']]);}
        $call=$q->fetch(PDO::FETCH_ASSOC);
        if(!$call||($scope['agent_id']>0&&(int)$call['agent_id']!==$scope['agent_id']))vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call is unavailable.');
        if((string)($call['paired_profile_id']??'')!=='')vp3_phone_workspace_error_v1('operator_connected_private',403,'Manage this connected phone in Phone Tracking.');
        $phone=(string)$call['peer_e164'];if(!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone))vp3_phone_workspace_error_v1('phone_contact_number_unavailable',422,'This caller has no phone number to save.');
        $known=vp3_phone_caller_contacts_lookup_v1($pdo,$scope['owner_user_id'],[$phone]);
        if(isset($known[$phone]))return ['contact'=>$known[$phone],'created'=>false];
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);$public=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
        $now=gmdate('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO crm_contacts(public_id,owner_user_id,name,email,email_normalized,phone,company,source,status,lifecycle_stage,marketing_status,created_at,updated_at) VALUES (?,?,?,'',NULL,?,'','phone_call','active','','unknown',?,?)")
            ->execute([$public,$scope['owner_user_id'],$name,$phone,$now,$now]);$id=(int)$pdo->lastInsertId();
        if(vp3_phone_caller_contacts_table_v1($pdo,'crm_contact_phones'))$pdo->prepare("INSERT INTO crm_contact_phones(contact_id,phone,phone_normalized,label,is_primary,created_at,updated_at) VALUES (?,?,?,'primary',1,?,?)")->execute([$id,$phone,substr($phone,1),$now,$now]);
        return ['contact'=>['id'=>$id,'name'=>$name,'phone_e164'=>$phone],'created'=>true];
    });
}
