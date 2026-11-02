<?php
declare(strict_types=1);
require_once __DIR__.'/studio-participants.php';
require_once __DIR__.'/agent-task-lists-v1.php';
require_once __DIR__.'/agent-reminders-v1.php';

/** Recognition is an observation. Only the signed-in owner can configure resource grants. */
function vp3_participant_updates_schema_v1(PDO $pdo): void
{
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_participant_settings_v1(owner_user_id INT UNSIGNED PRIMARY KEY,enabled INT NOT NULL DEFAULT 0,self_participant_id BIGINT NOT NULL DEFAULT 0,offer_updates INT NOT NULL DEFAULT 0,revision BIGINT NOT NULL DEFAULT 1,FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_participant_grants_v1(owner_user_id INT UNSIGNED NOT NULL,participant_id BIGINT NOT NULL,agent_scope_id BIGINT NOT NULL,resource_kind VARCHAR(16) NOT NULL,resource_id BIGINT NOT NULL,access_mode VARCHAR(8) NOT NULL,PRIMARY KEY(owner_user_id,participant_id,agent_scope_id,resource_kind,resource_id),FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_participant_presence_v1(owner_user_id INT UNSIGNED NOT NULL,participant_id BIGINT NOT NULL,last_seen BIGINT NOT NULL DEFAULT 0,last_heard BIGINT NOT NULL DEFAULT 0,last_activity BIGINT NOT NULL DEFAULT 0,visit_id VARCHAR(32) NOT NULL,offer_token VARCHAR(32) NOT NULL DEFAULT '',lease_until BIGINT NOT NULL DEFAULT 0,offered_at BIGINT NOT NULL DEFAULT 0,PRIMARY KEY(owner_user_id,participant_id),FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_participant_observers_v1(owner_user_id INT UNSIGNED NOT NULL,session_id VARCHAR(36) NOT NULL,sequence_no BIGINT NOT NULL,updated_at BIGINT NOT NULL,PRIMARY KEY(owner_user_id,session_id),FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)$tail");
}
function vp3_participant_updates_ready_v1(PDO $pdo): bool
{
    foreach(['agent_participant_settings_v1','agent_participant_grants_v1','agent_participant_presence_v1','agent_participant_observers_v1'] as $t)if(!table_exists($t))return false;
    return true;
}
function vp3_participant_settings_v1(PDO $pdo,array $u): array
{
    if(!vp3_participant_updates_ready_v1($pdo))return ['enabled'=>false,'self_participant_id'=>0,'offer_updates'=>false,'revision'=>0];
    $q=$pdo->prepare('SELECT * FROM agent_participant_settings_v1 WHERE owner_user_id=?');$q->execute([(int)$u['id']]);$r=$q->fetch(PDO::FETCH_ASSOC)?:[];
    return ['enabled'=>!empty($r['enabled']),'self_participant_id'=>(int)($r['self_participant_id']??0),'offer_updates'=>!empty($r['offer_updates']),'revision'=>(int)($r['revision']??0)];
}
function vp3_participant_owned_v1(PDO $pdo,array $u,int $id,bool $recognition=false): array
{
    try{$r=studio_participants_profile($pdo,$u,$id);}catch(Throwable $e){throw new RuntimeException('Participant is unavailable.',403);}
    if(empty($r['is_active'])||($recognition&&empty($r['recognition_consent'])))throw new RuntimeException('Participant is unavailable or recognition consent was removed.',403);
    return $r;
}
function vp3_participant_scope_v1(): ?array {return $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']??null;}
function vp3_participant_scope_check_v1(PDO $pdo,array $u,array $p): ?array
{
    $s=vp3_participant_scope_v1();if($s===null)return null;
    $settings=vp3_participant_settings_v1($pdo,$u);
    if((int)$s['owner']!==(int)$u['id']||(int)$s['agent']!==vp3_task_list_agent_v1($p)||!$settings['enabled']||(int)$s['revision']!==$settings['revision'])throw new RuntimeException('Participant permissions changed. Please ask again.',403);
    if($pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$q=$pdo->prepare('SELECT id FROM studio_participants WHERE id=? AND owner_user_id=? FOR UPDATE');$q->execute([(int)$s['participant'],(int)$u['id']]);}
    vp3_participant_owned_v1($pdo,$u,(int)$s['participant'],!empty($s['recognition']));return $s;
}
function vp3_participant_resource_v1(PDO $pdo,array $u,array $p,string $kind,int $id,bool $edit=false): bool
{
    $s=vp3_participant_scope_check_v1($pdo,$u,$p);if($s===null)return true;
    if($id<1)return false;
    $q=$pdo->prepare('SELECT access_mode FROM agent_participant_grants_v1 WHERE owner_user_id=? AND participant_id=? AND agent_scope_id=? AND resource_kind=? AND resource_id=?');$q->execute([(int)$u['id'],(int)$s['participant'],vp3_task_list_agent_v1($p),$kind,$id]);$access=$q->fetchColumn();
    if($access==='edit'||(!$edit&&$access==='read'))return true;
    if($kind==='task'){
        $q=$pdo->prepare("SELECT g.access_mode FROM agent_task_list_items_v1 i JOIN agent_task_lists_v1 l ON l.id=i.list_id JOIN agent_participant_grants_v1 g ON g.resource_kind='task_list' AND g.resource_id=l.id AND g.owner_user_id=l.owner_user_id AND g.agent_scope_id=l.agent_scope_id WHERE i.task_id=? AND l.owner_user_id=? AND l.agent_scope_id=? AND g.participant_id=?");$q->execute([$id,(int)$u['id'],vp3_task_list_agent_v1($p),(int)$s['participant']]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $mode)if($mode==='edit'||(!$edit&&$mode==='read'))return true;
    }
    return false;
}
function vp3_participant_require_resource_v1(PDO $pdo,array $u,array $p,string $kind,int $id,bool $edit=false): void
{
    if(!vp3_participant_resource_v1($pdo,$u,$p,$kind,$id,$edit))throw new RuntimeException('This record has not been shared with this participant for that action.',403);
}
function vp3_participant_with_scope_v1(PDO $pdo,array $u,array $p,int $id,bool $recognition,callable $fn): mixed
{
    $settings=vp3_participant_settings_v1($pdo,$u);vp3_participant_owned_v1($pdo,$u,$id,$recognition);
    if(!$settings['enabled'])throw new RuntimeException('Participant updates are disabled.',403);
    $old=vp3_participant_scope_v1();
    // Self access is an explicit owner setting, never inferred from a name or another account's link.
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=$id===$settings['self_participant_id']?null:['owner'=>(int)$u['id'],'agent'=>vp3_task_list_agent_v1($p),'participant'=>$id,'recognition'=>$recognition,'revision'=>$settings['revision'],'user'=>$u,'principal'=>$p];
    try{return $fn();}finally{if($old===null)unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);else $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=$old;}
}
/** Bounded canonical reads; no scheduler ticks, occurrence leases or delivery acknowledgments. */
function vp3_participant_resources_v1(PDO $pdo,array $u,array $p): array
{
    vp3_task_list_authorize_v1($pdo,$u,$p,0);$aid=vp3_task_list_agent_v1($p);$uid=(int)$u['id'];$lists=[];$tasks=[];$reminders=[];
    if(vp3_task_lists_ready_v1($pdo)){
        $q=$pdo->prepare('SELECT id,name FROM agent_task_lists_v1 WHERE owner_user_id=? AND agent_scope_id=? ORDER BY name,id LIMIT 101');$q->execute([$uid,$aid]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)if(vp3_participant_resource_v1($pdo,$u,$p,'task_list',(int)$r['id']))$lists[]=['id'=>(int)$r['id'],'title'=>$r['name'],'edit'=>vp3_participant_resource_v1($pdo,$u,$p,'task_list',(int)$r['id'],true),'items'=>vp3_task_list_note_v1($pdo,$u,$p,(int)$r['id'])['items']];
        $q=$pdo->prepare("SELECT id,subject,metadata_json FROM agent_memory_items WHERE user_id=? AND ".($aid?'user_agent_id=?':'user_agent_id IS NULL')." AND is_active=1 AND memory_type IN ('task','commitment') ORDER BY id DESC LIMIT 100");$q->execute($aid?[$uid,$aid]:[$uid]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)if(vp3_task_list_task_allowed_v1($pdo,$u,$p,(int)$r['id'])){$meta=json_decode((string)$r['metadata_json'],true);$tasks[]=['id'=>(int)$r['id'],'title'=>$r['subject'],'status'=>$meta['task_status']??'open','edit'=>vp3_participant_resource_v1($pdo,$u,$p,'task',(int)$r['id'],true)];}
    }
    if(reminder_ready_v1($pdo))foreach(reminder_rows_v1($pdo,$uid) as $r)if(vp3_participant_resource_v1($pdo,$u,$p,'reminder',(int)$r['id']))$reminders[]=['id'=>(int)$r['id'],'title'=>$r['title'],'status'=>$r['status'],'next_at'=>$r['next_at'],'timezone'=>$r['timezone'],'revision'=>(int)$r['revision'],'edit'=>vp3_participant_resource_v1($pdo,$u,$p,'reminder',(int)$r['id'],true)];
    return ['lists'=>array_slice($lists,0,100),'tasks'=>$tasks,'reminders'=>$reminders,'bounded'=>true];
}
function vp3_participant_configure_v1(PDO $pdo,array $u,array $p,array $input): array
{
    foreach(['enabled','offer_updates'] as $k)if(!is_bool($input[$k]??null))throw new InvalidArgumentException('Choose participant update settings.');
    $self=$input['self_participant_id']??null;$recipient=$input['participant_id']??null;$grants=$input['grants']??null;$expected=$input['revision']??null;
    if(!is_int($self)||$self<0||!is_int($recipient)||$recipient<0||!is_int($expected)||!is_array($grants)||count($grants)>200)throw new InvalidArgumentException('Invalid participant settings.');
    $pdo->beginTransaction();try{
        // Same owner lock is used by canonical task edits and grant revocation.
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([(int)$u['id']]);
        vp3_task_list_authorize_v1($pdo,$u,$p,0);$current=vp3_participant_settings_v1($pdo,$u);if($expected!==$current['revision'])throw new RuntimeException('Settings changed in another window. Reload and try again.',409);
        if($self)vp3_participant_owned_v1($pdo,$u,$self);if($recipient)vp3_participant_owned_v1($pdo,$u,$recipient);
        if(!$recipient&&$grants)throw new InvalidArgumentException('Choose whom to share with.');
        $catalog=vp3_participant_resources_v1($pdo,$u,$p);$available=[];
        foreach(['lists'=>'task_list','tasks'=>'task','reminders'=>'reminder'] as $key=>$kind)foreach($catalog[$key] as $r)$available[$kind.':'.$r['id']]=true;
        $clean=[];foreach($grants as $g){if(!is_array($g)||array_diff(array_keys($g),['kind','id','access'])||!is_int($g['id']??null)||!in_array($g['access']??'', ['read','edit'],true)||!isset($available[($g['kind']??'').':'.($g['id']??0)]))throw new RuntimeException('A shared record is no longer available in this Agent.',403);$clean[($g['kind']).':'.$g['id']]=$g;}
        if(!$current['revision'])$pdo->prepare('INSERT INTO agent_participant_settings_v1(owner_user_id) VALUES(?)')->execute([(int)$u['id']]);
        $pdo->prepare('UPDATE agent_participant_settings_v1 SET enabled=?,self_participant_id=?,offer_updates=?,revision=revision+1 WHERE owner_user_id=?')->execute([(int)$input['enabled'],$self,(int)$input['offer_updates'],(int)$u['id']]);
        if($recipient){$pdo->prepare('DELETE FROM agent_participant_grants_v1 WHERE owner_user_id=? AND participant_id=? AND agent_scope_id=?')->execute([(int)$u['id'],$recipient,vp3_task_list_agent_v1($p)]);foreach($clean as $g)$pdo->prepare('INSERT INTO agent_participant_grants_v1(owner_user_id,participant_id,agent_scope_id,resource_kind,resource_id,access_mode) VALUES(?,?,?,?,?,?)')->execute([(int)$u['id'],$recipient,vp3_task_list_agent_v1($p),$g['kind'],$g['id'],$g['access']]);}
        $pdo->commit();return vp3_participant_settings_v1($pdo,$u);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Call after event-inbox deduplication. Server time only; no raw audio, images or biometric features. */
function vp3_participant_observed_v1(PDO $pdo,array $u,int $id,string $kind,?int $now=null): void
{
    if(!vp3_participant_updates_ready_v1($pdo)||!vp3_participant_settings_v1($pdo,$u)['enabled'])return;
    if(!in_array($kind,['seen','heard'],true))throw new InvalidArgumentException('Invalid observation.');
    vp3_participant_owned_v1($pdo,$u,$id,true);$now??=time();$uid=(int)$u['id'];
    $ownTransaction=!$pdo->inTransaction();if($ownTransaction)$pdo->beginTransaction();
    try{
    $lock=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$lock->execute([$uid]);
    $q=$pdo->prepare('SELECT * FROM agent_participant_presence_v1 WHERE owner_user_id=? AND participant_id=?');$q->execute([$uid,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r){$pdo->prepare("INSERT INTO agent_participant_presence_v1(owner_user_id,participant_id,visit_id) VALUES(?,?,?)")->execute([$uid,$id,bin2hex(random_bytes(16))]);$r=['last_activity'=>0];}
    $new=$now-(int)$r['last_activity']>600;$field=$kind==='seen'?'last_seen':'last_heard';
    $sql="UPDATE agent_participant_presence_v1 SET $field=?,last_activity=?".($new?",visit_id=?,offered_at=0,offer_token='',lease_until=0":'').' WHERE owner_user_id=? AND participant_id=?';
    $pdo->prepare($sql)->execute($new?[$now,$now,bin2hex(random_bytes(16)),$uid,$id]:[$now,$now,$uid,$id]);
    if($ownTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Strictly ordered camera heartbeats; camera absence does not erase last-heard evidence. */
function vp3_participant_heartbeat_v1(PDO $pdo,array $u,array $p,array $input,?int $now=null): void
{
    $session=$input['session_id']??'';$seq=$input['sequence']??0;$ids=$input['participants']??null;
    if(!is_string($session)||!preg_match('/^[a-f0-9-]{36}$/D',$session)||!is_int($seq)||$seq<1||!is_array($ids)||count($ids)>4)throw new InvalidArgumentException('Invalid presence heartbeat.');
    foreach($ids as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid participant.');
    $now??=time();$pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([(int)$u['id']]);vp3_task_list_authorize_v1($pdo,$u,$p,0);
        $q=$pdo->prepare('SELECT sequence_no FROM agent_participant_observers_v1 WHERE owner_user_id=? AND session_id=?');$q->execute([(int)$u['id'],$session]);$old=$q->fetchColumn();
        if($old!==false&&(int)$old>=$seq){$pdo->commit();return;}
        if($old===false)$pdo->prepare('INSERT INTO agent_participant_observers_v1(owner_user_id,session_id,sequence_no,updated_at) VALUES(?,?,?,?)')->execute([(int)$u['id'],$session,$seq,$now]);
        else $pdo->prepare('UPDATE agent_participant_observers_v1 SET sequence_no=?,updated_at=? WHERE owner_user_id=? AND session_id=?')->execute([$seq,$now,(int)$u['id'],$session]);
        foreach(array_unique($ids) as $id)vp3_participant_observed_v1($pdo,$u,$id,'seen',$now);
        $pdo->prepare('DELETE FROM agent_participant_observers_v1 WHERE owner_user_id=? AND updated_at<?')->execute([(int)$u['id'],$now-86400]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function vp3_participant_offer_v1(PDO $pdo,array $u,int $id,?array $ack=null,?int $now=null): ?array
{
    $s=vp3_participant_settings_v1($pdo,$u);if(!$s['enabled']||!$s['offer_updates'])return null;
    $person=vp3_participant_owned_v1($pdo,$u,$id,true);$now??=time();$uid=(int)$u['id'];
    if($ack!==null){
        if(!is_string($ack['token']??null)||!is_string($ack['visit_id']??null))throw new InvalidArgumentException('Invalid update receipt.');
        $pdo->prepare('UPDATE agent_participant_presence_v1 SET offered_at=?,lease_until=0 WHERE owner_user_id=? AND participant_id=? AND visit_id=? AND offer_token=? AND lease_until>=? AND offered_at=0')->execute([$now,$uid,$id,$ack['visit_id'],$ack['token'],$now]);return null;
    }
    $token=bin2hex(random_bytes(16));$q=$pdo->prepare('UPDATE agent_participant_presence_v1 SET offer_token=?,lease_until=? WHERE owner_user_id=? AND participant_id=? AND last_activity>=? AND offered_at=0 AND lease_until<?');$q->execute([$token,$now+45,$uid,$id,$now-90,$now]);if(!$q->rowCount())return null;
    $q=$pdo->prepare('SELECT visit_id FROM agent_participant_presence_v1 WHERE owner_user_id=? AND participant_id=? AND offer_token=?');$q->execute([$uid,$id,$token]);$visit=$q->fetchColumn();
    return $visit?['participant_id'=>$id,'name'=>$person['display_name'],'text'=>'I can show your shared task, list and reminder updates.','token'=>$token,'visit_id'=>$visit]:null;
}

function vp3_participant_presence_rows_v1(PDO $pdo,array $u): array
{
    $q=$pdo->prepare('SELECT r.participant_id,r.last_seen,r.last_heard,r.last_activity FROM agent_participant_presence_v1 r JOIN studio_participants s ON s.id=r.participant_id AND s.owner_user_id=r.owner_user_id WHERE r.owner_user_id=? AND s.is_active=1 AND s.recognition_consent=1 ORDER BY r.last_activity DESC LIMIT 100');$q->execute([(int)$u['id']]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
