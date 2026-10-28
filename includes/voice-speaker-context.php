<?php
declare(strict_types=1);
require_once __DIR__.'/studio-participants.php';
require_once __DIR__.'/participant-conversation-details.php';

function vp3_voice_speaker_context(PDO $pdo,array $user,array $input): ?array
{
    $manual=in_array($input['source']??'', ['manual_selection','owner_selected_participant'],true);
    if(!in_array($input['source']??'', ['local_voice_similarity','browser_reported_local_voice_similarity','manual_selection','owner_selected_participant'],true)||($input['authentication_authority']??false)!==false)return null;
    $confidence=filter_var($input['confidence']??null,FILTER_VALIDATE_FLOAT);
    $id=filter_var($input['participant_id']??null,FILTER_VALIDATE_INT);
    if((!$manual&&($confidence===false||!is_finite($confidence)||$confidence<0.90||$confidence>1))||$id===false||$id<1)return null;
    try{$p=studio_participants_profile($pdo,$user,$id);}catch(Throwable $e){return null;}
    if(empty($p['is_active'])||(!$manual&&empty($p['recognition_consent'])))return null;
    $note='';if(function_exists('agent_brain_v122_memory')){try{$note=vp3_participant_conversation_details($pdo,$user,$id)['note'];}catch(Throwable $e){}}
    return ['conversation_note'=>$note,'participant_id'=>$id,'display_name'=>(string)$p['display_name'],'source'=>$manual?'owner_selected_participant':'browser_reported_local_voice_similarity','confidence'=>$manual?null:$confidence,'authentication_authority'=>false,'identity_verified'=>false];
}
