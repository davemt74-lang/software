<?php
declare(strict_types=1);
require_once __DIR__.'/phone-workspace-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';

/** A separate authenticated document owns browser audio. Opening it is read-only. */
function vp3_phone_call_window_scope_v1(PDO $pdo,?array $user,array $input): array
{
    if(!$user)vp3_phone_workspace_error_v1('authentication_required',401,'Sign in in VP3, then reopen Phone.');
    $owner=vp3_phone_workspace_id_v1($input['owner']??null);
    if($owner!==(int)$user['id'])vp3_phone_workspace_error_v1('phone_account_changed',403,'Your account changed. Reopen Phone from VP3.');
    return vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$input['agent_id']??0,'conversation_id'=>$input['conversation_id']??0]);
}

function vp3_phone_call_binding_matches_v1(?array $user,string $binding): bool
{
    return $user!==null&&hash_equals(vp3_catch_up_page_binding_v1($user),$binding);
}
