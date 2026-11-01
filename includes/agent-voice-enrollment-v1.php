<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-receipts-v1.php';
/** Prepare a local setup card. This never enables a microphone or saves a profile. */
function vp3_voice_enrollment_prepare_v1(array $user,array $principal): array
{
    unset($GLOBALS['vp3_voice_enrollment_approved_v1']);
    $allowed=knowledge_retrieval_v162_owner_session($user,$principal)&&has_permission('chat.access',$user);
    if($allowed)$GLOBALS['vp3_voice_enrollment_approved_v1']=(int)$user['id'];
    return ['handled'=>true,'answer'=>$allowed?'Let’s learn your voice. Open the setup below to choose your participant profile, prepare the local voice model, and record three samples. You can listen, retry or cancel before saving.':'Voice profile setup is unavailable to this conversation.','actions'=>$allowed?[['type'=>'voice_enrollment','label'=>'Learn my voice']]:[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('voice.profiles.enroll','prepare',$allowed?'clarification_required':'blocked',$allowed?'setup_required':'permission_denied')];
}
