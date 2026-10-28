<?php
declare(strict_types=1);
require_once __DIR__.'/participant-conversation-details.php';

/** Only explicit conversational text crosses the existing paired transport. */
function vp3_participant_sync_key(mixed $key): string
{
    if(!is_string($key)||!preg_match('/^[a-z0-9-]{12,32}$/D',$key))throw new InvalidArgumentException('Invalid participant reference.');
    return $key;
}

function vp3_participant_sync_row(PDO $pdo,array $user,?array $p,string $key): array
{
    $deleted=!$p||empty($p['is_active']);
    $note=$deleted?'':vp3_participant_conversation_details($pdo,$user,(int)$p['id'])['note'];
    $row=['key'=>$key,'id'=>$p?(string)$p['id']:'','name'=>$deleted?'':(string)$p['display_name'],'note'=>$note,'deleted'=>$deleted];
    $row['revision']=hash('sha256',json_encode([$key,$row['name'],$note,$deleted],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return $row;
}

function vp3_participant_sync_find(PDO $pdo,int $uid,string $key): ?array
{
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM studio_participants WHERE owner_user_id=? AND profile_key=?'.$suffix);
    $q->execute([$uid,$key]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_participant_delete_details(PDO $pdo,array $user,int $id): void
{
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM studio_participants WHERE id=? AND owner_user_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$id,(int)$user['id']]);$p=$q->fetch(PDO::FETCH_ASSOC);
        if(!$p)throw new RuntimeException('Participant unavailable.');
        if($p['relationship_scope']==='self')throw new RuntimeException('Your account participant cannot be removed.');
        if(!empty($p['is_active']))vp3_participant_save_details($pdo,$user,['participant_id'=>$id,'display_name'=>$p['display_name'],'conversation_note'=>'']);
        $pdo->prepare('UPDATE studio_participants SET is_active=0 WHERE id=? AND owner_user_id=?')->execute([$id,(int)$user['id']]);
        if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Caller owns the live account/session transaction. Content revisions prevent lost updates. */
function vp3_participant_sync_exchange(PDO $pdo,int $uid,array $body): array
{
    if(!has_permission('chat.access',['id'=>$uid]))throw new RuntimeException('Participant Chat access required.',403);
    if(!studio_participants_schema_ready())throw new RuntimeException('Participant storage needs the existing database upgrade.',409);
    $changes=$body['changes']??[];$keys=$body['keys']??[];$after=$body['after']??0;
    if(!is_array($changes)||!array_is_list($changes)||count($changes)>25||!is_array($keys)||!array_is_list($keys)||count($keys)>100||!is_int($after)||$after<0)
        throw new InvalidArgumentException('Invalid participant sync page.');
    $user=['id'=>$uid];$results=[];$seen=[];
    foreach($changes as $change){
        if(!is_array($change)||array_diff(array_keys($change),['key','expected_revision','name','note','deleted','change_id']))throw new InvalidArgumentException('Only participant names and conversation notes may sync.');
        $key=vp3_participant_sync_key($change['key']??null);
        $expected=$change['expected_revision']??null;$name=$change['name']??null;$note=$change['note']??null;$deleted=$change['deleted']??null;$cid=$change['change_id']??null;
        if(isset($seen[$key])||!is_string($expected)||($expected!==''&&!preg_match('/^[a-f0-9]{64}$/D',$expected))||!is_string($name)||mb_strlen($name)>120||!is_string($note)||mb_strlen($note)>1000||!is_bool($deleted)||!is_string($cid)||!preg_match('/^[a-f0-9]{32}$/D',$cid))throw new InvalidArgumentException('Invalid participant change.');
        $seen[$key]=true;
        if(!$deleted)$name=studio_participants_clean_name($name);
        $note=trim($note);$p=vp3_participant_sync_find($pdo,$uid,$key);$current=vp3_participant_sync_row($pdo,$user,$p,$key);
        if(!$p&&$deleted){
            // Remember even a deletion that arrives before an earlier creation.
            $pdo->prepare("INSERT INTO studio_participants(owner_user_id,profile_key,display_name,relationship_scope,is_active) VALUES(?,?,'Removed participant','guest',0)")->execute([$uid,$key]);
            $p=vp3_participant_sync_find($pdo,$uid,$key);$current=vp3_participant_sync_row($pdo,$user,$p,$key);
        }
        $same=$current['deleted']===$deleted&&($deleted||($current['name']===$name&&$current['note']===$note));
        $status='applied';
        if(!$same){
            if(($p&&($expected===''||!hash_equals($current['revision'],$expected)))||(!$p&&$expected!==''))$status='conflict';
            elseif($deleted&&$p&&$p['relationship_scope']==='self')$status='protected';
            elseif($current['deleted']&&$p)$status='conflict'; // Tombstones never resurrect on retry.
            else{
                if(!$p&&!$deleted){
                    $q=$pdo->prepare("INSERT INTO studio_participants(owner_user_id,profile_key,display_name,relationship_scope) VALUES(?,?,?,'guest')");
                    $q->execute([$uid,$key,$name]);$p=studio_participants_profile($pdo,$user,(int)$pdo->lastInsertId());
                }
                if($deleted){if($p)vp3_participant_delete_details($pdo,$user,(int)$p['id']);}
                else vp3_participant_save_details($pdo,$user,['participant_id'=>$p['id'],'display_name'=>$name,'conversation_note'=>$note]);
                $current=vp3_participant_sync_row($pdo,$user,vp3_participant_sync_find($pdo,$uid,$key),$key);
            }
        }
        $results[]=['change_id'=>$cid,'status'=>$status,'participant'=>$current];
    }
    $q=$pdo->prepare('SELECT * FROM studio_participants WHERE owner_user_id=? AND id>? ORDER BY id LIMIT 100');$q->execute([$uid,$after]);
    $records=$q->fetchAll(PDO::FETCH_ASSOC);$rows=[];
    foreach($records as $p)$rows[$p['profile_key']]=vp3_participant_sync_row($pdo,$user,vp3_participant_sync_find($pdo,$uid,$p['profile_key']),$p['profile_key']);
    foreach($keys as $key){$key=vp3_participant_sync_key($key);$rows[$key]=vp3_participant_sync_row($pdo,$user,vp3_participant_sync_find($pdo,$uid,$key),$key);}
    return ['participants'=>array_values($rows),'results'=>$results,'next_after'=>count($records)===100?(int)end($records)['id']:0];
}
