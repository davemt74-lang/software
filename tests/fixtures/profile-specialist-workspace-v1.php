<?php
declare(strict_types=1);

/** Persistent SQL fixture; reset is explicit, never performed by an API read. */
require_once __DIR__.'/profile-specialist-sources-v1.php';
require_once dirname(__DIR__,2).'/includes/profile-specialist-workspace-v1.php';
require_once dirname(__DIR__,2).'/includes/profile-owner-drafts-v1.php';

function pws_reset(): array
{
    pfs_reset();$pdo=db();
    if(table_exists('profile_owner_drafts_v1'))$pdo->exec('DELETE FROM profile_owner_drafts_v1');
    // Production private Agent resolution reads the complete configured row.
    foreach(['instructions'=>"TEXT",'agent_role'=>"VARCHAR(100) NOT NULL DEFAULT ''",'engine_key'=>"VARCHAR(100) NOT NULL DEFAULT ''"] as $column=>$definition)
        profile_specialist_fixture_column_v1($pdo,'user_agents',$column,$definition);
    $first=pa3_admit('Could you help me choose a T-shirt size?',0,str_repeat('1',32),0,'b');
    // SQLite admission deliberately uses its wall clock rather than the injected
    // handoff clock. MySQL admission uses the frozen fixture/database clock. Age
    // only this fixture message against the clock the service will actually read.
    $admissionClock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ?gmdate('Y-m-d H:i:s',time()-5)
        :pfs_now()->modify('-5 seconds')->format('Y-m-d H:i:s');
    $pdo->prepare("UPDATE profile_agent_messages SET created_at=? WHERE conversation_id=? AND sender_type='visitor'")
        ->execute([$admissionClock,(int)$first['conversation_id']]);
    pfs_advance(3000);
    $second=pa3_admit('Does the second product come in blue?',0,str_repeat('2',32),0,'c');
    $legacy=profile_specialist_fixture_settled_v1($pdo,1,7,83000);
    if(function_exists('vp3_profile_owner_drafts_ensure_schema_v1'))vp3_profile_owner_drafts_ensure_schema_v1($pdo);
    pa3_login(1);$GLOBALS['pwsProviderCalls']=0;$GLOBALS['pwsProviderHook']=null;
    return ['primary'=>(int)$first['conversation_id'],'secondary'=>(int)$second['conversation_id'],'legacy'=>(int)$legacy['conversation'],
        'primary_turn'=>(int)vp3_profile_workspace_turn_v1($pdo,1,(int)$first['conversation_id'])['id'],
        'secondary_turn'=>(int)vp3_profile_workspace_turn_v1($pdo,1,(int)$second['conversation_id'])['id']];
}
function pws_scope(int $agent=7,int $chat=11): array
{pa3_login(1);return vp3_profile_workspace_scope_v1(db(),pa3_owner(),$agent,$chat);}
function pws_request(string $action,array $extra=[],int $agent=7,int $chat=11,?callable $guard=null,?callable $model=null): array
{
    pa3_login(1);$user=pa3_owner();$method=in_array($action,['conversation_action','draft_prepare','call_set_assistance','call_research'],true)?'POST':'GET';
    return vp3_profile_workspace_request_v1(db(),$user,$method,['action'=>$action,'chat_agent_id'=>$agent,'chat_conversation_id'=>$chat]+$extra,
        vp3_catch_up_page_binding_v1($user),$guard,$model);
}
function pws_claim(int $conversation): array
{
    $view=pws_request('conversation',['public_conversation_id'=>$conversation])['data'];$turn=$view['current_turn'];
    return pws_request('conversation_action',['public_conversation_id'=>$conversation,'turn_id'=>$turn['turn_id'],'revision'=>$turn['revision'],
        'operation'=>'claim','request_id'=>bin2hex(random_bytes(16))])['data'];
}
