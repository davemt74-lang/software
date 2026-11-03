<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-calls-provider-pa3c.php';
$checks=0;
function pc_check(bool $value,string $label): void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";}
function pc_reject(callable $call,string $label,?string $code=null): void
{
    try{$call();}catch(VP3ProfileCallProviderException $e){pc_check(($code===null||$e->publicCode===$code)&&!str_contains($e->getMessage(),'SECRET_'),$label);return;}
    throw new RuntimeException('Expected fixed provider rejection: '.$label);
}
pc_check(!vp3_profile_call_livekit_readiness_pa3c()['ready'],'existing meeting credentials alone never enable Profile calls');
$meetingBefore=$config;
$state=vp3_profile_call_livekit_save_pa3c($pdo,pc_settings());
pc_check($state['configured']&&$state['human_only_acknowledged']&&!$state['ready']&&$state['reason']==='api_unverified','admin acknowledgement is separate from provider API verification');
pc_check(!str_contains((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='profile_calls_livekit_config_pa3c'")->fetchColumn(),'SECRET_PROFILE'),'dedicated configuration is encrypted in real settings storage');
$GLOBALS['pcRequests']=[];$state=vp3_profile_call_livekit_verify_pa3c($pdo,'pc_http');
pc_check($state['ready']&&$state['api_verified_at_ms']>0&&array_column($GLOBALS['pcRequests'],'method')===['ListRooms'],'readiness test performs only an authenticated metadata lookup');
pc_check($config===$meetingBefore,'Profile call settings preserve the existing meeting configuration');

$binding=pc_reset();$proof=vp3_profile_call_livekit_provider_pa3c('create',$binding,pc_options(),'pc_http');$binding['room_sid']=$proof['room_sid'];
pc_check($proof['status']==='ready'&&!$proof['owner_connected']&&!$proof['visitor_connected'],'room preparation confirms the exact empty room without claiming people are connected');
$created=$GLOBALS['pcRequests'][0];$sent=$created['payload'];$service=pc_claims($created['auth']);
pc_check($sent['max_participants']===2&&!isset($sent['agents'],$sent['egress'])&&!isset($sent['room_preset'])&&$sent['replay_enabled']===false,'room creation includes no Agent dispatch, preset, recording or replay configuration');
pc_check($service['video']===['roomCreate'=>true]&&$service['exp']<=intdiv(pc_options()['operation_deadline_ms'],1000)&&$created['timeout']<=8000,'room service grant and transport are bounded by the operation deadline');
$options=pc_options();$token=vp3_profile_call_livekit_provider_pa3c('token',$binding,$options,'pc_http');$claims=pc_claims($token['participant_token']);
pc_check($claims['sub']===$binding['owner_identity']&&$claims['video']['room']===$binding['room_name']&&$token['peer_identity']===$binding['visitor_identity'],'participant token binds the exact role, peer and call room');
pc_check($claims['video']['canPublishSources']===['microphone']&&!$claims['video']['canPublishData']&&!$claims['video']['canUpdateOwnMetadata']&&!isset($claims['video']['roomAdmin'],$claims['video']['roomRecord'],$claims['sip']),'voice grants allow microphone only and omit administrative, SIP and recording privileges');
pc_check($claims['exp']-$claims['iat']===60&&$claims['nbf']===$claims['iat']-5&&$claims['nbf']<$binding['revoke_before_sec'],'same-second reserved revocation cutoff strictly exceeds the issued token not-before value');
pc_check($token['issued_at_ms']===$options['issued_at_ms']&&$token['expires_at_ms']===$options['expires_at_ms'],'token honors the exact server-reserved lifetime');
$parts=explode('.',$token['participant_token']);pc_check(hash_equals(video_meeting_b64url_v1800(hash_hmac('sha256',$parts[0].'.'.$parts[1],pc_settings()['api_secret'],true)),$parts[2]),'token is signed by the saved Profile project key');
pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('token',$binding,pc_options(['camera'=>true]),'pc_http'),'audio call rejects camera escalation','CALL_PROVIDER_REQUEST_INVALID');
$video=$binding;$video['mode']='video';$videoToken=vp3_profile_call_livekit_provider_pa3c('token',$video,pc_options(['role'=>'visitor','camera'=>true]),'pc_http');
pc_check(pc_claims($videoToken['participant_token'])['video']['canPublishSources']===['microphone','camera'],'explicit video consent grants only camera and microphone');
foreach(['missing mic'=>['microphone'=>false],'numeric camera'=>['camera'=>1],'future issue'=>['issued_at_ms'=>(int)floor(microtime(true)*1000)+9000],'expired'=>['expires_at_ms'=>(int)floor(microtime(true)*1000)-1],'long token'=>['expires_at_ms'=>(int)floor(microtime(true)*1000)+600000],'bad intent'=>['join_intent_id'=>'foreign']] as $label=>$change)pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('token',$binding,pc_options($change),'pc_http'),'token rejects '.$label,'CALL_PROVIDER_REQUEST_INVALID');

$GLOBALS['pcParticipants']=[pc_participant($binding,'owner'),pc_participant($binding,'visitor')];$observed=vp3_profile_call_livekit_provider_pa3c('inspect',$binding,pc_options(),'pc_http');
pc_check($observed['owner_connected']&&$observed['visitor_connected']&&count($observed['participants'])===2,'actual source-shaped participants prove both exact human roles connected');
pc_check(!str_contains(json_encode($observed),'SECRET_')&&!str_contains(json_encode($observed),'permission'),'safe inspection excludes raw provider secrets and grant objects');
foreach([
    'foreign identity'=>fn($p)=>$p->identity='FOREIGN',
    'Agent'=>fn($p)=>$p->kind='AGENT',
    'hidden participant'=>fn($p)=>$p->permission->hidden=true,
    'recorder'=>fn($p)=>$p->permission->recorder=true,
    'screen share grant'=>fn($p)=>$p->permission->canPublishSources=['SCREEN_SHARE'],
    'camera on voice'=>fn($p)=>$p->tracks[0]->source='CAMERA',
    'data privilege'=>fn($p)=>$p->permission->canPublishData=true,
    'unrestricted source grant'=>function($p){unset($p->permission->canPublishSources);},
    'missing permission'=>function($p){unset($p->permission);},
    'wrong boolean'=>fn($p)=>$p->permission->canPublish='true',
] as $label=>$change){$p=pc_participant($binding);$change($p);$GLOBALS['pcParticipants']=[$p];pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('inspect',$binding,pc_options(),'pc_http'),'inspection rejects '.$label);}
$GLOBALS['pcParticipants']=[pc_participant($binding),pc_participant($binding),pc_participant($binding,'visitor')];pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('inspect',$binding,pc_options(),'pc_http'),'more than two participants fails closed');
$GLOBALS['pcParticipants']=[pc_participant($binding),pc_participant($binding)];pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('inspect',$binding,pc_options(),'pc_http'),'duplicate role identities fail closed');
$GLOBALS['pcParticipants']=[];
$validRoom=clone $GLOBALS['pcRoom'];
foreach(['wrong room'=>['name'=>'foreign-room'],'changed SID'=>['sid'=>'RM_foreign'],'recording'=>['activeRecording'=>true],'wrong capacity'=>['maxParticipants'=>3],'bad metadata'=>['metadata'=>'{}'],'false string'=>['activeRecording'=>'false']] as $label=>$changes){$GLOBALS['pcRoom']=clone $validRoom;foreach($changes as $key=>$value)$GLOBALS['pcRoom']->$key=$value;pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('inspect',$binding,pc_options(),'pc_http'),'room evidence rejects '.$label);}
$GLOBALS['pcRoom']=$validRoom;
foreach([
    'list root'=>['status'=>200,'body'=>'[]'],
    'malformed JSON'=>['status'=>200,'body'=>'{'],
    'oversized JSON'=>['status'=>200,'body'=>str_repeat('x',262145)],
    'provider error'=>['status'=>401,'body'=>'{"code":"unauthenticated","msg":"SECRET_PROVIDER_DIAGNOSTIC"}'],
    'redirect'=>['status'=>302,'body'=>'{}'],
    'null rooms'=>['status'=>200,'body'=>'{"rooms":null}'],
] as $label=>$response){$GLOBALS['pcHttpHook']=fn()=> $response;pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('inspect',$binding,pc_options(),'pc_http'),'malformed transport rejects '.$label);}
$GLOBALS['pcHttpHook']=null;
$requests=count($GLOBALS['pcRequests']);$bad=$binding;$bad['room_name']='foreign';pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('create',$bad,pc_options(),'pc_http'),'server binding rejects a substituted room before HTTP','CALL_PROVIDER_REQUEST_INVALID');
pc_check(count($GLOBALS['pcRequests'])===$requests,'invalid immutable room identity produces no provider request');
pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('create',$binding,pc_options(['operation_deadline_ms'=>(int)floor(microtime(true)*1000)-1]),'pc_http'),'expired operation cannot mint a fresh create grant','CALL_PROVIDER_DEADLINE_EXPIRED');

$GLOBALS['pcRequests']=[];$cleanup=vp3_profile_call_livekit_provider_pa3c('cleanup',$binding,pc_options(),'pc_http');
pc_check($cleanup['status']==='closed'&&$cleanup['owner_revoked']&&$cleanup['visitor_revoked']&&$cleanup['room_absent'],'closure requires both explicit revocations and independent room absence');
pc_check(array_column($GLOBALS['pcRequests'],'method')===['RemoveParticipant','RemoveParticipant','DeleteRoom','ListRooms'],'cleanup verifies token revocation before room deletion and final absence');
foreach(array_slice($GLOBALS['pcRequests'],0,2) as $request)pc_check(is_string($request['payload']['revoke_token_ts'])&&(int)$request['payload']['revoke_token_ts']>$claims['nbf']&&(int)$request['payload']['revoke_token_ts']>=time()+1,'explicit cutoff covers cached tokens without using the default revocation buffer');
pc_check($cleanup['revoked_before_sec']===min($cleanup['owner_revoked_before_sec'],$cleanup['visitor_revoked_before_sec']),'receipt reports the weakest confirmed per-role revocation cutoff');

foreach(['not_found','invalid_argument','permission_denied'] as $error){$GLOBALS['pcHttpHook']=fn($r)=>$r['method']==='RemoveParticipant'?pc_response((object)['code'=>$error,'msg'=>'SECRET_DIAGNOSTIC'],404):null;$GLOBALS['pcRequests']=[];pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('cleanup',$binding,pc_options(),'pc_http'),'missing or rejected revocation remains unverified: '.$error,'CALL_PROVIDER_REVOCATION_UNVERIFIED');pc_check(!in_array('DeleteRoom',array_column($GLOBALS['pcRequests'],'method'),true),'failed revocation cannot turn a missing room into completion');}
$GLOBALS['pcHttpHook']=fn($r)=>$r['method']==='DeleteRoom'?pc_response((object)['code'=>'not_found'],404):null;
pc_check(vp3_profile_call_livekit_provider_pa3c('cleanup',$binding,pc_options(),'pc_http')['status']==='closed','already absent room is acceptable only after both independent revocation acknowledgements');
$GLOBALS['pcHttpHook']=fn($r)=>$r['method']==='ListRooms'?pc_response((object)['rooms'=>[(object)['name'=>$binding['room_name']]]]):null;
pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('cleanup',$binding,pc_options(),'pc_http'),'room still present after delete remains pending','CALL_PROVIDER_REVOCATION_UNVERIFIED');
$GLOBALS['pcHttpHook']=null;$bad=$binding;$bad['revoke_before_sec']=time()+120;
pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('cleanup',$bad,pc_options(),'pc_http'),'clock outside the Cloud cutoff window is not a successful cleanup','CALL_PROVIDER_REVOCATION_UNVERIFIED');

$oldBinding=$binding;$oldSnapshot=$binding['provider_snapshot'];$oldFingerprint=$binding['provider_config_fingerprint'];
$rotated=pc_settings(['url'=>'wss://profile-second.livekit.cloud','api_key'=>'API_PROFILE_SECOND','api_secret'=>'SECRET_PROFILE_SECOND_987654321']);$newState=vp3_profile_call_livekit_save_pa3c($pdo,$rotated);
pc_check(!$newState['ready']&&$newState['reason']==='api_unverified'&&$GLOBALS['pcInvalidations']>=2,'project rotation queues old-call invalidation and requires a new API check');
vp3_profile_call_livekit_verify_pa3c($pdo,'pc_http');
foreach(['create','inspect','token'] as $operation)pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c($operation,$oldBinding,pc_options(),'pc_http'),'current operation rejects rotated configuration: '.$operation,'CALL_PROVIDER_CONFIGURATION_CHANGED');
$GLOBALS['pcRequests']=[];$closed=vp3_profile_call_livekit_provider_pa3c('cleanup',$oldBinding,pc_options(),'pc_http');
pc_check($closed['status']==='closed'&&count(array_filter($GLOBALS['pcRequests'],fn($r)=>str_starts_with($r['url'],'https://profile-first.livekit.cloud/')))===4,'original encrypted snapshot closes the original project after rotation');
pc_check(array_reduce($GLOBALS['pcRequests'],fn($ok,$r)=>$ok&&pc_claims($r['auth'])['iss']==='API_PROFILE_FIRST',true),'old-project cleanup never signs with the replacement project key');
vp3_profile_call_livekit_disable_pa3c($pdo);$GLOBALS['pcRequests']=[];pc_check(!vp3_profile_call_livekit_readiness_pa3c()['ready']&&vp3_profile_call_livekit_provider_pa3c('cleanup',$oldBinding,pc_options(),'pc_http')['status']==='closed','disable prevents new calling while retaining original cleanup authority');
pc_check($oldSnapshot===$oldBinding['provider_snapshot']&&$oldFingerprint===$oldBinding['provider_config_fingerprint'],'configuration changes never rewrite original private call evidence');
$bad=$oldBinding;$bad['provider_snapshot']=substr($oldSnapshot,0,-4).'AAAA';pc_reject(fn()=>vp3_profile_call_livekit_provider_pa3c('cleanup',$bad,pc_options(),'pc_http'),'damaged encrypted snapshot never falls back to current credentials','CALL_PROVIDER_CREDENTIALS_UNAVAILABLE');
foreach(['ws://fixture.livekit.cloud','wss://livekit.example.test','wss://fixture.livekit.cloud/path','wss://fixture.livekit.cloud?token=SECRET','wss://user@fixture.livekit.cloud','wss://fixture.livekit.cloud.evil.test'] as $url)pc_reject(fn()=>vp3_profile_call_livekit_origin_pa3c($url),'strict Cloud transport rejects unsafe origin');
echo 'PROFILE_CALLS_PROVIDER_PA3C=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
