<?php
declare(strict_types=1);
require_once __DIR__.'/studio-participants.php';

/** Explicit owner-saved profile notes use the existing Brain store, not recordings. */
function vp3_participant_note_scope(array $user,callable $callback): mixed
{
    $previous=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
    vp3_agent_memory_scope_set_current_v410((int)$user['id'],0);
    try{return $callback();}
    finally{if($previous===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$previous;}
}

function vp3_participant_conversation_details(PDO $pdo,array $user,int $id): array
{
    $p=studio_participants_profile($pdo,$user,$id);
    if(empty($p['is_active']))throw new RuntimeException('Participant unavailable.');
    $memory=vp3_participant_note_scope($user,fn()=>agent_brain_v122_memory($user,'participant_note','participant:'.$id));
    return ['id'=>(string)$id,'name'=>(string)$p['display_name'],'note'=>mb_substr((string)($memory['metadata']['conversation_note']??''),0,1000)];
}

function vp3_participant_save_details(PDO $pdo,array $user,array $input): array
{
    $id=filter_var($input['participant_id']??0,FILTER_VALIDATE_INT);
    if($id===false||$id<1)throw new RuntimeException('Choose a participant.');
    $name=studio_participants_clean_name((string)($input['display_name']??''));
    $note=trim((string)($input['conversation_note']??''));
    if(mb_strlen($note)>1000)throw new RuntimeException('Conversation notes are limited to 1000 characters.');
    $p=studio_participants_profile($pdo,$user,$id);
    if(empty($p['is_active']))throw new RuntimeException('Participant unavailable.');
    $pdo->beginTransaction();
    try{
        $pdo->prepare('UPDATE studio_participants SET display_name=? WHERE id=? AND owner_user_id=?')->execute([$name,$id,(int)$user['id']]);
        $saved=vp3_participant_note_scope($user,function()use($user,$id,$note){
            return agent_brain_v122_upsert_system_memory($user,'participant_note','participant:'.$id,
                $note===''?'Participant conversation note cleared.':'Owner-saved participant conversation note: '.$note,
                ['participant_id'=>$id,'conversation_note'=>$note,'scope'=>'participant-profile','privacy_scope'=>'private','authentication_authority'=>false],0.95);
        });
        if($saved<1)throw new RuntimeException('Participant memory storage is unavailable. No details were changed.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['id'=>(string)$id,'name'=>$name,'note'=>$note];
}
