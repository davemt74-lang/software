<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/profile-workspace-calls-v1.php';

$checks=0;
function pwc_check(bool $ok,string $label): void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function pwc_reject(callable $work,int $code,string $label): void
{
    try{$work();}catch(RuntimeException|InvalidArgumentException $error){pwc_check((int)$error->getCode()===$code,$label.' ['.$error->getCode().']');return;}
    throw new RuntimeException('FAIL accepted '.$label);
}
function pwc_list(array $selection=[],int $agent=8,int $chat=12): array
{
    return pa4b_readonly(fn()=>vp3_profile_workspace_calls_v1(db(),pfs_scope($agent,$chat),$selection));
}
function pwc_activity(int $call,int $after=0,int $agent=8,int $chat=12): array
{
    return pa4b_readonly(fn()=>vp3_profile_workspace_call_activity_v1(db(),pfs_scope($agent,$chat),$call,$after));
}

pwc_reset();$before=pwc_snapshot();
pwc_check(pwc_list()===['calls'=>[],'coverage'=>'ready','has_more'=>false,'selected'=>null],'a configured empty Calls tab is a verified empty read');
pwc_check($before===pwc_snapshot(),'opening an empty Calls tab creates no calls, delivery, work or provider request');
$offer=pa3c_offer('owner',pa3c_context(2));$id=(int)$offer['call_id'];$cid=(int)$offer['conversation_id'];$before=pwc_snapshot();
$list=pwc_list(['conversation_id'=>$cid,'call_id'=>$id]);$card=$list['selected'];
pwc_check(count($list['calls'])===1&&$card===$list['calls'][0]&&$card['call_id']===$id&&$card['public_conversation_id']===$cid,
    'the exact selected call and bounded current list agree on distinct numeric public and private targets');
pwc_check($card['state']==='offered'&&$card['chat_message_id']===0&&!$card['activity_available'],'a named private Agent reads the separate public responder without creating a native card');
$json=json_encode($list,JSON_THROW_ON_ERROR);
foreach(['provider_snapshot','participant_token','room_name','credential_hash','authority_json','binding_hash','delivery_id'] as $secret)
    pwc_check(!str_contains($json,$secret),'safe Calls data excludes '.$secret);
pwc_check($before===pwc_snapshot(),'list and exact selection never deliver, acknowledge, dispatch, accept, join or call a provider');
pwc_reject(fn()=>pwc_list(['call_id'=>$id],7,12),403,'another named Agent cannot borrow this private Chat');
pwc_reject(fn()=>vp3_profile_workspace_calls_v1(db(),['user'=>pa3_owner(4),'agent_id'=>9,'conversation_id'=>13],['call_id'=>$id]),404,'a foreign owner cannot select this call');
pwc_reject(fn()=>pwc_list(['call_id'=>999999]),404,'a missing exact call never falls back to another current call');
pwc_reject(fn()=>pwc_list(['call_id'=>(string)$id]),0,'noncanonical call ID types are rejected');
db()->exec('UPDATE user_agents SET is_active=0 WHERE id=8');pwc_reject(fn()=>pwc_list(),403,'retiring the selected private Agent revokes Calls access');
db()->exec('UPDATE user_agents SET is_active=1 WHERE id=8');
db()->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'");pwc_reject(fn()=>pwc_list(),403,'current Profile Chat permission is required for Calls');

pwc_reset();$offer=pa3c_offer('visitor',pa3c_context(2));$id=(int)$offer['call_id'];pfs_advance(61000);vp3_profile_call_work_pa3c(db(),$id,'pa3c_provider');
pwc_check(pwc_list()['calls']===[]&&pwc_list(['call_id'=>$id])['selected']['state']==='expired','undelivered completed offers are not mislabeled as a full current call history, while exact selection remains exact');
$row=vp3_profile_call_row_pa3c(db(),$id);
$delivery=vp3_profile_call_owner_delivery_pa3c(db(),pa3c_owner(),['call_id'=>$id,'revision'=>(int)$row['revision'],'chat_conversation_id'=>12,'chat_agent_id'=>8]);
pwc_check(pwc_list()['calls'][0]['chat_message_id']===$delivery['chat_message_id'],'an already saved current-Chat card retains its exact native message identity');
db()->prepare("UPDATE chat_messages SET role='user' WHERE id=?")->execute([$delivery['chat_message_id']]);
pwc_reject(fn()=>pwc_list(['call_id'=>$id]),409,'a changed native message cannot be presented as an original call card');
$limited=pwc_list();pwc_check($limited['calls']===[]&&$limited['coverage']==='limited','discovery reports a source verification gap without exposing the changed card');

pwc_reset();$offer=pa3c_offer('owner',pa3c_context(2));$id=(int)$offer['call_id'];$changed=false;
db()->afterSql=static function(string $sql,PA3PDO $pdo)use($id,&$changed):void{
    if(!$changed&&str_starts_with($sql,'SELECT * FROM profile_calls_pa3c WHERE id=')){$changed=true;$pdo->afterSql=null;$pdo->prepare("UPDATE profile_calls_pa3c SET state='cancelled',revision=revision+1 WHERE id=?")->execute([$id]);}
};
pwc_reject(fn()=>vp3_profile_workspace_call_read_v1(db(),pfs_scope(8,12),$id),409,'an exact call revision changing during projection is withheld');
pwc_check($changed,'the concurrent call change regression exercised its real read boundary');

pwc_reset();$record=pwc_activity_seed();$id=$record['call'];$before=pwc_snapshot();$view=pwc_activity($id);
pwc_check($view['call_id']===$id&&$view['activity']['call_id']===$record['public_id']&&$view['activity']['state']==='ended','activity preserves numeric selection and separate canonical public call identity');
pwc_check($view['activity']['segments'][0]['text']==='EXACT_CALL_TRANSCRIPT'&&$view['activity']['research'][0]['summary']==='EXACT_CALL_RESEARCH',
    'authorized activity reuses the exact stored transcript and research');
pwc_check(pwc_activity($id,84003)['activity']['segments']===[],'the exact transcript cursor does not replay earlier segments');
pwc_check($before===pwc_snapshot(),'reading saved activity does not create assistance, start transcription or change call data');
pwc_reject(fn()=>vp3_profile_workspace_call_activity_v1(db(),pfs_scope(8,12),$id,0,999999),404,'activity cannot substitute a call from another selected public conversation');
pwc_reject(fn()=>vp3_profile_workspace_call_action_v1(db(),pfs_scope(8,12),$id,'set_assistance',['enabled'=>true,'assistance_revision'=>1],999999),404,
    'an action checks the exact selected public conversation before side effects');
pwc_check($before===pwc_snapshot(),'a mismatched public action target leaves canonical storage unchanged');

foreach(['discarded','wrong_call','malformed_provenance','wrong_private_agent','wrong_owner','revoked_recording_rule'] as $mutation){
    pwc_reset();$record=pwc_activity_seed();$session=$record['session'];
    match($mutation){
        'discarded'=>db()->prepare("UPDATE artist_transcript_sessions_v172 SET discarded_at='2026-01-01' WHERE id=?")->execute([$session]),
        'wrong_call'=>db()->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=? WHERE id=?')->execute([json_encode(['source'=>'profile_call_pa3c','profile_call_id'=>str_repeat('a',32)]),$session]),
        'malformed_provenance'=>db()->prepare("UPDATE artist_transcript_sessions_v172 SET metadata_json='{' WHERE id=?")->execute([$session]),
        'wrong_private_agent'=>db()->prepare('UPDATE artist_transcript_sessions_v172 SET conversation_id=11 WHERE id=?')->execute([$session]),
        'wrong_owner'=>db()->prepare('UPDATE artist_transcript_sessions_v172 SET created_by_user_id=4 WHERE id=?')->execute([$session]),
        'revoked_recording_rule'=>db()->prepare("INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode) VALUES(8,'recording',?,'deny')")->execute([(string)$session]),
    };
    pwc_reject(fn()=>pwc_activity($record['call']),404,'current recording authority or exact provenance is required: '.$mutation);
}

pwc_reset();$record=pwc_activity_seed();$changed=false;
db()->afterSql=static function(string $sql,PA3PDO $pdo)use(&$changed):void{
    if(!$changed&&str_starts_with($sql,'SELECT id,speaker_label,transcript_text,started_ms')){$changed=true;$pdo->afterSql=null;$pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=12');}
};
pwc_reject(fn()=>vp3_profile_workspace_call_activity_v1(db(),pfs_scope(8,12),$record['call']),403,'a private Chat authority change after transcript projection withholds the entire response');
pwc_check($changed,'the late private-scope regression reached the actual transcript read');

pwc_reset();$record=pwc_activity_seed();$changed=false;
db()->afterSql=static function(string $sql,PA3PDO $pdo)use($record,&$changed):void{
    if(!$changed&&str_starts_with($sql,'SELECT id,speaker_label,transcript_text,started_ms')){$changed=true;$pdo->afterSql=null;$pdo->prepare('UPDATE profile_call_assistance_pa3c SET transcript_session_id=0 WHERE call_id=?')->execute([$record['public_id']]);}
};
pwc_reject(fn()=>vp3_profile_workspace_call_activity_v1(db(),pfs_scope(8,12),$record['call']),409,'a concurrent transcript pointer change cannot return the earlier recording');

pwc_reset();$live=pwc_live_assisted();$scope=pfs_scope(8,12);$before=pwc_snapshot();$view=pwc_activity($live['call']);
pwc_check($view['activity']['assistance_enabled']&&$view['activity']['assistance_toggle_available'],'a real accepted assisted call exposes its existing assistance controls');
pwc_check($before===pwc_snapshot(),'reading a live assisted call does not initialize assistance or dispatch a worker');
$action=vp3_profile_workspace_call_action_v1(db(),$scope,$live['call'],'set_assistance',['enabled'=>false,'assistance_revision'=>1],$live['conversation']);
pwc_check($action['operation']==='set_assistance'&&$action['result']['assistance_enabled']===false&&$action['result']['assistance_revision']===2,
    'an explicit scoped assistance change reuses the canonical current-call mutation');
$repeat=vp3_profile_workspace_call_action_v1(db(),$scope,$live['call'],'set_assistance',['enabled'=>false,'assistance_revision'=>1],$live['conversation']);
pwc_check($repeat===$action,'retrying a confirmed assistance state does not apply another transition');
pwc_reject(fn()=>vp3_profile_workspace_call_action_v1(db(),$scope,$live['call'],'set_assistance',['enabled'=>true,'assistance_revision'=>1]),409,'a stale assistance revision cannot reverse a newer change');
require_once dirname(__DIR__).'/includes/profile-specialist-workspace-v1.php';
pa3_login(1);$owner=pa3_owner();$binding=vp3_catch_up_page_binding_v1($owner);
$envelope=vp3_profile_workspace_request_v1(db(),$owner,'POST',['action'=>'call_set_assistance','chat_agent_id'=>8,'chat_conversation_id'=>12,
    'csrf_token'=>'fixture-token','public_conversation_id'=>$live['conversation'],'call_id'=>$live['call'],'enabled'=>true,'assistance_revision'=>2],$binding);
pwc_check($envelope['action']==='call_set_assistance'&&$envelope['scope']['chat_agent_id']===8
    &&$envelope['data']['call_action']['call_id']===$live['call']&&$envelope['data']['call_action']['result']['assistance_enabled']===true,
    'the real workspace dispatcher extracts operation fields from the complete scoped action envelope');
pwc_reject(fn()=>vp3_profile_workspace_request_v1(db(),$owner,'POST',['action'=>'call_research','chat_agent_id'=>8,'chat_conversation_id'=>12,
    'csrf_token'=>'fixture-token','public_conversation_id'=>$live['conversation'],'call_id'=>$live['call'],'query'=>'Public opening hours','request_id'=>str_repeat('r',32)],$binding),503,
    'the real research dispatcher reaches canonical provider readiness instead of rejecting common request fields');
$before=pwc_snapshot();
pwc_reject(fn()=>vp3_profile_workspace_call_action_v1(db(),$scope,$live['call'],'research',['request_id'=>'short','query'=>'Public hours']),0,'malformed research identity is rejected before provider work');
pwc_reject(fn()=>vp3_profile_workspace_call_action_v1(db(),$scope,$live['call'],'join',[]),0,'the activity boundary exposes no join or other media operation');
pwc_check($before===pwc_snapshot(),'rejected activity operations cannot start media or alter assistance');
db()->exec("DELETE FROM role_permissions WHERE permission_key='profile_agent.access'");
pwc_reject(fn()=>vp3_profile_workspace_call_action_v1(db(),$scope,$live['call'],'set_assistance',['enabled'=>true,'assistance_revision'=>2]),403,'revoked current private Profile authority prevents explicit mutations');

echo 'PROFILE_WORKSPACE_CALLS_V1=PASS ('.$checks.' checks; driver='.db()->getAttribute(PDO::ATTR_DRIVER_NAME)."; skips=0)\n";
