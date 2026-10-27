<?php
declare(strict_types=1);
require_once __DIR__.'/studio-participants.php';

function vp3_voice_speaker_context(PDO $pdo,array $user,array $input): ?array
{
    if(!in_array($input['source']??'', ['local_voice_similarity','browser_reported_local_voice_similarity'],true)||($input['authentication_authority']??false)!==false)return null;
    $confidence=filter_var($input['confidence']??null,FILTER_VALIDATE_FLOAT);
    $id=filter_var($input['participant_id']??null,FILTER_VALIDATE_INT);
    if($confidence===false||!is_finite($confidence)||$confidence<0.90||$confidence>1||$id===false||$id<1)return null;
    try{$p=studio_participants_profile($pdo,$user,$id);}catch(Throwable $e){return null;}
    if(empty($p['is_active'])||empty($p['recognition_consent']))return null;
    return ['participant_id'=>$id,'display_name'=>(string)$p['display_name'],'source'=>'browser_reported_local_voice_similarity','confidence'=>$confidence,'authentication_authority'=>false,'identity_verified'=>false];
}
