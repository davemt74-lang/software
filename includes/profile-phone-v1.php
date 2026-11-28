<?php
declare(strict_types=1);

/** Publish only an owner-approved, currently assigned Twilio line on a Profile. */
const VP3_PROFILE_PHONE_V1='vp3.profile-phone.v1';

function vp3_profile_phone_ready_v1(PDO $pdo): bool
{
    try{$pdo->query('SELECT owner_user_id,phone_number_id,agent_id,phone_e164,enabled,revision FROM profile_phone_settings_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}

function vp3_profile_phone_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Install Profile calling outside transactions.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_phone_settings_v1 (
        owner_user_id BIGINT PRIMARY KEY,phone_number_id BIGINT NOT NULL DEFAULT 0,
        agent_id BIGINT NOT NULL DEFAULT 0,phone_e164 VARCHAR(20) NOT NULL DEFAULT '',
        enabled SMALLINT NOT NULL DEFAULT 0,revision BIGINT NOT NULL DEFAULT 1
    )".$suffix);
}

function vp3_profile_phone_row_v1(PDO $pdo,int $owner): array
{
    $q=$pdo->prepare('SELECT * FROM profile_phone_settings_v1 WHERE owner_user_id=?');$q->execute([$owner]);
    return $q->fetch(PDO::FETCH_ASSOC)?:['owner_user_id'=>$owner,'phone_number_id'=>0,'agent_id'=>0,'phone_e164'=>'','enabled'=>0,'revision'=>0];
}

function vp3_profile_phone_numbers_v1(PDO $pdo,int $owner,bool $lock=false): array
{
    if(!vp3_phone_schema_ready_v100($pdo))return [];
    $plan=vp3_phone_package_v100($pdo,$owner);
    if(empty($plan['included'])||empty($plan['voice']))return [];
    $q=$pdo->prepare("SELECT n.id,n.agent_id,n.phone_e164,a.display_name AS agent_name
        FROM phone_cloud_numbers_v100 n JOIN user_agents a
          ON a.id=n.agent_id AND a.owner_user_id=n.owner_user_id AND a.is_active=1
        WHERE n.owner_user_id=? AND n.provider='twilio' AND n.voice_enabled=1 ORDER BY n.id LIMIT 100".($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$owner]);$numbers=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(!preg_match('/\A\+[1-9][0-9]{7,14}\z/D',(string)$row['phone_e164']))continue;
        $numbers[]=['id'=>(int)$row['id'],'agent_id'=>(int)$row['agent_id'],'number'=>(string)$row['phone_e164'],'agent_name'=>(string)$row['agent_name']];
    }
    return $numbers;
}

function vp3_profile_phone_match_v1(array $saved,array $numbers): ?array
{
    foreach($numbers as $number){
        if($number['id']===(int)$saved['phone_number_id']&&$number['agent_id']===(int)$saved['agent_id']
            &&hash_equals((string)$saved['phone_e164'],$number['number']))return $number;
    }
    return null;
}

function vp3_profile_phone_owner_v1(PDO $pdo,array $user): array
{
    $installed=vp3_profile_phone_ready_v1($pdo);$numbers=vp3_profile_phone_numbers_v1($pdo,(int)$user['id']);
    $saved=$installed?vp3_profile_phone_row_v1($pdo,(int)$user['id']):['phone_number_id'=>0,'agent_id'=>0,'phone_e164'=>'','enabled'=>0,'revision'=>0];
    $current=vp3_profile_phone_match_v1($saved,$numbers);
    return ['contract'=>VP3_PROFILE_PHONE_V1,'owner_user_id'=>(int)$user['id'],'ready'=>$installed,
        'enabled'=>(bool)$saved['enabled'],'active'=>$installed&&!empty($saved['enabled'])&&$current!==null,
        'number_id'=>$current['id']??0,'revision'=>(int)$saved['revision'],'numbers'=>$numbers,
        'reason'=>!$installed?'Run Database Upgrade to enable Profile calling.':(!$numbers?'Assign a Twilio number with incoming voice and a voice allowance in Phone settings.':(!empty($saved['enabled'])&&!$current?'Your approved phone assignment changed. Choose and save the current line again.':''))];
}

function vp3_profile_phone_save_v1(PDO $pdo,array $user,array $input): array
{
    if(!vp3_profile_phone_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade to enable Profile calling.',503);
    foreach(array_keys($input) as $key)if(!in_array($key,['enabled','number_id','revision'],true))throw new InvalidArgumentException('Choose valid Profile calling settings.',422);
    if(!is_bool($input['enabled']??null)||!is_int($input['number_id']??null)||$input['number_id']<0
       ||!is_int($input['revision']??null)||$input['revision']<0)throw new InvalidArgumentException('Choose valid Profile calling settings.',422);
    vp3_phone_workspace_atomic_v1($pdo,function()use($pdo,$user,$input){
        $owner=(int)$user['id'];$suffix=vp3_phone_workspace_lock_v1($pdo);
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$owner]);
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1'.$suffix);$q->execute([$owner]);
        if(!$q->fetchColumn())throw new RuntimeException('Your account changed. Reload Profile settings.',403);
        $saved=vp3_profile_phone_row_v1($pdo,$owner);
        if((int)$saved['revision']!==$input['revision'])throw new RuntimeException('Calling settings changed. Reload them before saving.',409);
        $number=null;
        if($input['enabled']){
            // Lock the selected assignment along with the owner. Never accept a
            // submitted destination, personal callback number, or foreign line.
            $q=$pdo->prepare('SELECT id FROM phone_cloud_numbers_v100 WHERE id=? AND owner_user_id=?'.$suffix);
            $q->execute([$input['number_id'],$owner]);if(!$q->fetchColumn())throw new RuntimeException('Choose one of your current Twilio phone lines.',409);
            foreach(vp3_profile_phone_numbers_v1($pdo,$owner,true) as $candidate)if($candidate['id']===$input['number_id'])$number=$candidate;
            if(!$number)throw new RuntimeException('Choose an assigned Twilio line with incoming voice enabled.',409);
        }
        $values=[$number['id']??0,$number['agent_id']??0,$number['number']??'',$input['enabled']?1:0,(int)$saved['revision']+1,$owner];
        if((int)$saved['revision']===0){
            $pdo->prepare('INSERT INTO profile_phone_settings_v1(phone_number_id,agent_id,phone_e164,enabled,revision,owner_user_id) VALUES(?,?,?,?,?,?)')->execute($values);
        }else{
            $write=$pdo->prepare('UPDATE profile_phone_settings_v1 SET phone_number_id=?,agent_id=?,phone_e164=?,enabled=?,revision=? WHERE owner_user_id=? AND revision=?');
            $write->execute([...$values,$input['revision']]);
            if($write->rowCount()!==1)throw new RuntimeException('The phone assignment changed. Reload calling settings before saving.',409);
        }
    });
    return vp3_profile_phone_owner_v1($pdo,$user);
}

/** Number transfers invalidate publication even when the same line later returns. */
function vp3_profile_phone_revoke_line_v1(PDO $pdo,int $numberId): void
{
    if(!vp3_profile_phone_ready_v1($pdo))return;
    $pdo->prepare('UPDATE profile_phone_settings_v1 SET enabled=0,revision=revision+1 WHERE phone_number_id=? AND enabled=1')->execute([$numberId]);
}

function vp3_profile_phone_public_v1(PDO $pdo,array $profile): array
{
    $out=['contract'=>VP3_PROFILE_PHONE_V1,'enabled'=>false,'number'=>'','href'=>''];
    $owner=(int)($profile['user_id']??0);
    if($owner<1||empty($profile['is_active'])||empty($profile['is_public'])||empty($profile['profile_agent_enabled'])||!vp3_profile_phone_ready_v1($pdo))return $out;
    $user=profile_user_row($pdo,$owner);$agent=profile_active_agent($pdo,$profile);
    if(!$user||empty($user['is_active'])||!$agent||(int)$agent['owner_user_id']!==$owner
       ||!vp3_observation_personal_permission_v1($pdo,$user,'profile_agent.access')
       ||!vp3_observation_personal_permission_v1($pdo,$user,'profile_chat.access'))return $out;
    $saved=vp3_profile_phone_row_v1($pdo,$owner);
    if(empty($saved['enabled']))return $out;
    $number=vp3_profile_phone_match_v1($saved,vp3_profile_phone_numbers_v1($pdo,$owner));
    if(!$number)return $out;
    return ['contract'=>VP3_PROFILE_PHONE_V1,'enabled'=>true,'number'=>$number['number'],'href'=>'tel:'.$number['number']];
}
