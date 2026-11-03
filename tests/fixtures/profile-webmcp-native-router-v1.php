<?php
declare(strict_types=1);
require_once __DIR__.'/profile-webmcp-confirmation-v192.php';
// Canonical domain fixtures stop at the real confirmation verifier and durable commit serializer.
// The native API, source context authority and outcome adapter remain production code.
function vp3_profile_webmcp_dispatch_v191(PDO $pdo,array $profile,?array $viewer,array $manifest,string $surface,string $tool,array $args,array $telemetry,?array $telemetryContext,float $startedAt,callable $respond,array $options=[]): never
{
    $pdo->prepare('INSERT INTO fixture_calls(tool) VALUES(?)')->execute([$tool]);
    if($tool==='vp3.profile.get')$respond(true,['profile'=>['label'=>'Fixture public Profile']]);
    $case=$GLOBALS['native_cases']['booking.create'];
    if($tool==='vp3.booking.prepare')vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$case['prepare']);
    if($tool==='vp3.booking.confirm'){
        $context=$case['transport']+['secret'=>'fixture-native-secret','origin_hash'=>''];
        vp3_profile_webmcp_scheduling_token_verify_v150((string)($args['confirmation_token']??''),$context,'booking.create',(array)($args['intent']??[]));
        $key=(string)($args['idempotency_key']??'');$idem=vp3_profile_webmcp_idempotency_hash_v150(1,'booking.create',$key);
        $row=vp3_profile_webmcp_action_row_v150($pdo,1,$case['action']['intent_id']);$result=$case['result'];
        if($row['state']==='committed'){if($row['idempotency_hash']!==$idem)throw new RuntimeException('Idempotency conflict.');$result['idempotent_replay']=true;}
        else vp3_profile_webmcp_action_commit_v150($pdo,$case['action']['id'],$idem,'booking',$case['resultId'],$result);
        $late=$_SERVER['HTTP_X_FIXTURE_LATE']??'';
        if($late==='agent')$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
        if($late==='conversation')$pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=1');
        if($late==='profile')$pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
        if($late==='malformed_receipt')$pdo->exec("UPDATE profile_webmcp_actions SET result_json='{}' WHERE id=1");
        vp3_profile_webmcp_action_respond_v192($respond,$tool,true,$result);
    }
    throw new RuntimeException('Unknown fixture operation.');
}
