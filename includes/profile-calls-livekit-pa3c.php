<?php
declare(strict_types=1);

/** Direct human Profile calls. No meeting, Agent dispatch, recording or SIP operations. */
require_once __DIR__.'/video-meetings-v1800.php';
require_once __DIR__.'/ai-settings.php';

final class VP3ProfileCallProviderException extends RuntimeException
{
    public function __construct(public string $publicCode,public int $httpStatus,public bool $retryable)
    {
        parent::__construct(match($publicCode){
            'CALL_PROVIDER_CONFIGURATION_CHANGED'=>'Calling settings changed. This call must close.',
            'CALL_PROVIDER_ROOM_UNSAFE'=>'This call could not be kept private. The room is closing.',
            'CALL_PROVIDER_NOT_CONFIGURED'=>'Direct calling is not ready on this profile.',
            'CALL_PROVIDER_CREDENTIALS_UNAVAILABLE'=>'Calling credentials are unavailable. Cleanup remains pending.',
            'CALL_PROVIDER_DEADLINE_EXPIRED'=>'This call operation expired. Refresh its current state.',
            'CALL_PROVIDER_REQUEST_INVALID'=>'Choose a valid call operation.',
            'CALL_PROVIDER_REVOCATION_UNVERIFIED'=>'Call closure is still being verified.',
            default=>'The calling service could not verify this operation. Please try again.',
        },$httpStatus);
    }
}
function vp3_profile_call_livekit_error_pa3c(string $code='CALL_PROVIDER_RESPONSE_INVALID',int $status=502,bool $retryable=false): never
{throw new VP3ProfileCallProviderException($code,$status,$retryable);}

function vp3_profile_call_livekit_settings_pa3c(): array
{
    $keys=['profile_calls_enabled_pa3c','profile_calls_source_pa3c','profile_calls_livekit_config_pa3c','profile_calls_human_only_ack_pa3c','profile_calls_api_verified_pa3c'];
    $pdo=function_exists('db')?db():null;if(!$pdo)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
    $q=$pdo->prepare('SELECT setting_key,setting_value FROM settings WHERE setting_key IN (?,?,?,?,?)');$q->execute($keys);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['setting_key']]=(string)$row['setting_value'];
    return $out;
}
function vp3_profile_call_livekit_origin_pa3c(mixed $value): string
{
    if(!is_string($value)||strlen($value)>250)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
    $parts=parse_url(trim($value));
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='wss'
        ||!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.livekit\.cloud$/D',strtolower((string)($parts['host']??'')))
        ||isset($parts['user'],$parts['pass'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])
        ||(isset($parts['port'])&&(int)$parts['port']!==443)||!in_array($parts['path']??'',['','/'],true)){
        vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
    }
    return 'wss://'.strtolower($parts['host']);
}
function vp3_profile_call_livekit_credentials_pa3c(array $value): array
{
    $url=vp3_profile_call_livekit_origin_pa3c($value['url']??null);$key=$value['api_key']??null;$secret=$value['api_secret']??null;
    foreach([$key,$secret] as $part)if(!is_string($part)||strlen($part)<8||strlen($part)>240||preg_match('/[^\x21-\x7e]/D',$part))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
    return ['contract'=>'vp3.profile-call.livekit.pa3c','provider'=>'livekit_cloud','url'=>$url,'api_key'=>$key,'api_secret'=>$secret];
}
function vp3_profile_call_livekit_fingerprint_pa3c(array $config): string
{return hash('sha256',json_encode([$config['provider'],$config['url'],$config['api_key'],$config['api_secret']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function vp3_profile_call_livekit_config_pa3c(?array $settings=null): array
{
    $settings??=vp3_profile_call_livekit_settings_pa3c();$source=$settings['profile_calls_source_pa3c']??'profile';
    if($source==='meetings')$raw=video_meeting_livekit_config_v1800();
    elseif($source==='profile'){
        $cipher=$settings['profile_calls_livekit_config_pa3c']??'';
        if($cipher===''||strlen($cipher)>8192)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
        $plain=ai_decrypt_secret($cipher);if($plain==='')vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CREDENTIALS_UNAVAILABLE',503,true);
        try{$raw=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CREDENTIALS_UNAVAILABLE',503,true);}
        if(!is_array($raw)||($raw['contract']??'')!=='vp3.profile-call.livekit.pa3c')vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CREDENTIALS_UNAVAILABLE',503,true);
    }else vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
    $config=vp3_profile_call_livekit_credentials_pa3c($raw);$config['source']=$source;$config['config_fingerprint']=vp3_profile_call_livekit_fingerprint_pa3c($config);
    return $config;
}
function vp3_profile_call_livekit_readiness_from_pa3c(array $settings,array $config): array
{
    $fingerprint=$config['config_fingerprint'];$enabled=($settings['profile_calls_enabled_pa3c']??'0')==='1';
    $ack=is_string($settings['profile_calls_human_only_ack_pa3c']??null)&&hash_equals($fingerprint,$settings['profile_calls_human_only_ack_pa3c']);
    $verified=json_decode($settings['profile_calls_api_verified_pa3c']??'',true);$tested=0;
    if(is_array($verified)&&is_string($verified['config_fingerprint']??null)&&hash_equals($fingerprint,$verified['config_fingerprint'])&&is_int($verified['verified_at_ms']??null)&&$verified['verified_at_ms']>0&&$verified['verified_at_ms']<=(int)floor(microtime(true)*1000)+60000)$tested=$verified['verified_at_ms'];
    $keyReady=ai_master_key_state()==='ready';
    $reason=!$enabled?'disabled':(!$ack?'human_only_unacknowledged':(!$tested?'api_unverified':(!$keyReady?'credential_unavailable':(!function_exists('curl_init')?'transport_unavailable':'ready'))));
    return ['ready'=>$reason==='ready','reason'=>$reason,'config_fingerprint'=>$fingerprint,'configured'=>true,'enabled'=>$enabled,'human_only_acknowledged'=>$ack,'api_verified_at_ms'=>$tested];
}
function vp3_profile_call_livekit_readiness_pa3c(): array
{
    $settings=[];
    try{$settings=vp3_profile_call_livekit_settings_pa3c();return vp3_profile_call_livekit_readiness_from_pa3c($settings,vp3_profile_call_livekit_config_pa3c($settings));}
    catch(Throwable $e){return ['ready'=>false,'reason'=>$e instanceof VP3ProfileCallProviderException&&$e->publicCode==='CALL_PROVIDER_CREDENTIALS_UNAVAILABLE'?'credential_unavailable':'configuration_missing','config_fingerprint'=>'','configured'=>false,'enabled'=>($settings['profile_calls_enabled_pa3c']??'0')==='1','human_only_acknowledged'=>false,'api_verified_at_ms'=>0];}
}
function vp3_profile_call_livekit_snapshot_pa3c(): array
{
    $settings=vp3_profile_call_livekit_settings_pa3c();$config=vp3_profile_call_livekit_config_pa3c($settings);
    if(!vp3_profile_call_livekit_readiness_from_pa3c($settings,$config)['ready'])vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
    return ['encrypted'=>ai_encrypt_secret(json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),'config_fingerprint'=>$config['config_fingerprint']];
}
function vp3_profile_call_livekit_bound_config_pa3c(array $binding,bool $cleanup=false): array
{
    $cipher=$binding['provider_snapshot']??null;$fingerprint=$binding['provider_config_fingerprint']??null;
    if(!is_string($cipher)||strlen($cipher)<30||strlen($cipher)>8192||!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
    $plain=ai_decrypt_secret($cipher);if($plain==='')vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CREDENTIALS_UNAVAILABLE',503,true);
    try{$saved=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CREDENTIALS_UNAVAILABLE',503,true);}
    if(!is_array($saved)||($saved['contract']??'')!=='vp3.profile-call.livekit.pa3c'||($saved['provider']??'')!=='livekit_cloud')vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CREDENTIALS_UNAVAILABLE',503,true);
    $config=vp3_profile_call_livekit_credentials_pa3c($saved);$config['config_fingerprint']=vp3_profile_call_livekit_fingerprint_pa3c($config);
    if(!hash_equals($fingerprint,$config['config_fingerprint']))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CONFIGURATION_CHANGED',409);
    if(!$cleanup){$settings=vp3_profile_call_livekit_settings_pa3c();$current=vp3_profile_call_livekit_config_pa3c($settings);
        if(!hash_equals($fingerprint,$current['config_fingerprint']))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CONFIGURATION_CHANGED',409);
        if(!vp3_profile_call_livekit_readiness_from_pa3c($settings,$current)['ready'])vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_NOT_CONFIGURED',503);
    }
    return $config;
}
function vp3_profile_call_livekit_binding_pa3c(array $binding): array
{
    $public=$binding['public_id']??null;
    if(!is_int($binding['call_id']??null)||$binding['call_id']<1||!is_string($public)||!preg_match('/^[a-f0-9]{32}$/D',$public)
        ||($binding['room_name']??null)!=='vp3-profile-'.$public||($binding['owner_identity']??null)!=='owner-'.$public||($binding['visitor_identity']??null)!=='visitor-'.$public
        ||!in_array($binding['mode']??null,['audio','video'],true)||!is_string($binding['room_sid']??null)||strlen($binding['room_sid'])>100||($binding['room_sid']!==''&&!preg_match('/^RM_[A-Za-z0-9_-]+$/D',$binding['room_sid'])))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
    return $binding;
}
function vp3_profile_call_livekit_jwt_pa3c(array $config,array $claims): string
{
    $header=video_meeting_b64url_v1800('{"alg":"HS256","typ":"JWT"}');$payload=video_meeting_b64url_v1800(json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $header.'.'.$payload.'.'.video_meeting_b64url_v1800(hash_hmac('sha256',$header.'.'.$payload,$config['api_secret'],true));
}
function vp3_profile_call_livekit_deadline_pa3c(array $options): int
{
    $now=(int)floor(microtime(true)*1000);$deadline=$options['operation_deadline_ms']??($now+60000);
    if(!is_int($deadline)||$deadline<=$now+1000||$deadline>$now+120000)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_DEADLINE_EXPIRED',409);
    return $deadline;
}
/** Fixed RoomService methods only; this helper never exposes raw provider messages. */
function vp3_profile_call_livekit_http_pa3c(array $config,string $method,array $payload,int $deadline,?callable $http=null): object
{
    $now=time();$remaining=$deadline-(int)floor(microtime(true)*1000);if($remaining<1000)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_DEADLINE_EXPIRED',409);
    $grant=match($method){'CreateRoom','DeleteRoom'=>['roomCreate'=>true],'ListRooms'=>['roomList'=>true],'ListParticipants','RemoveParticipant'=>['roomAdmin'=>true,'room'=>$payload['room']??''],default=>throw new LogicException('Unsupported Profile call provider operation.')};
    $claims=['iss'=>$config['api_key'],'sub'=>'vp3-profile-call-service','nbf'=>$now-5,'exp'=>min($now+60,intdiv($deadline,1000)),'video'=>$grant];
    $token=vp3_profile_call_livekit_jwt_pa3c($config,$claims);$url='https://'.substr($config['url'],6).'/twirp/livekit.RoomService/'.$method;
    $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($body)>8192)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
    $headers=['Authorization: Bearer '.$token,'Content-Type: application/json'];
    if($http){$response=$http($url,$headers,$body,min(8000,$remaining));}
    else{
        if(!function_exists('curl_init'))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_UNAVAILABLE',503,true);
        $ch=curl_init($url);$raw='';$oversized=false;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining),CURLOPT_TIMEOUT_MS=>min(8000,$remaining),CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw,&$oversized):int{if(strlen($raw)+strlen($chunk)>262144){$oversized=true;return 0;}$raw.=$chunk;return strlen($chunk);}]);
        curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);
        if($oversized)vp3_profile_call_livekit_error_pa3c();if($error!==0)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_UNAVAILABLE',503,true);
        $response=['status'=>$status,'body'=>$raw];
    }
    if(!is_array($response)||!is_int($response['status']??null)||!is_string($response['body']??null)||strlen($response['body'])>262144)vp3_profile_call_livekit_error_pa3c();
    try{$json=json_decode($response['body'],false,32,JSON_THROW_ON_ERROR);}catch(Throwable){vp3_profile_call_livekit_error_pa3c();}
    if(!is_object($json))vp3_profile_call_livekit_error_pa3c();
    if($response['status']<200||$response['status']>=300){
        if($method==='DeleteRoom'&&$response['status']===404&&($json->code??null)==='not_found')return (object)['__provider_room_absent'=>true];
        if($method==='RemoveParticipant')vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REVOCATION_UNVERIFIED',502,true);
        if(in_array($response['status'],[401,403,408,429],true)||$response['status']>=500)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_UNAVAILABLE',503,true);
        vp3_profile_call_livekit_error_pa3c();
    }
    if(isset($json->code)||isset($json->error))vp3_profile_call_livekit_error_pa3c();
    return $json;
}
function vp3_profile_call_livekit_field_pa3c(object $row,string $snake,string $camel,mixed $default=null): mixed
{
    if(property_exists($row,$snake)&&property_exists($row,$camel)&&$row->$snake!==$row->$camel)vp3_profile_call_livekit_error_pa3c();
    return property_exists($row,$snake)?$row->$snake:(property_exists($row,$camel)?$row->$camel:$default);
}
function vp3_profile_call_livekit_bool_pa3c(mixed $value): bool
{if(!is_bool($value))vp3_profile_call_livekit_error_pa3c();return $value;}
function vp3_profile_call_livekit_room_pa3c(object $room,array $binding): array
{
    $sid=$room->sid??null;$name=$room->name??null;$metadata=$room->metadata??null;
    if(!is_string($sid)||!preg_match('/^RM_[A-Za-z0-9_-]{1,90}$/D',$sid)||$name!==$binding['room_name']||($binding['room_sid']!==''&&!hash_equals($binding['room_sid'],$sid))
        ||vp3_profile_call_livekit_field_pa3c($room,'max_participants','maxParticipants')!==2||!is_string($metadata)||strlen($metadata)>512)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
    try{$meta=json_decode($metadata,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);}
    if(!is_array($meta)||$meta!==['contract'=>'vp3.profile-call.pa3c','call'=>$binding['public_id']])vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
    if(vp3_profile_call_livekit_bool_pa3c(vp3_profile_call_livekit_field_pa3c($room,'active_recording','activeRecording',false)))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
    return ['room_sid'=>$sid,'room_name'=>$name];
}
function vp3_profile_call_livekit_participants_pa3c(object $response,array $binding): array
{
    // Protobuf JSON omits an empty repeated field; supplied wrong types still fail.
    $rows=property_exists($response,'participants')?$response->participants:[];if(!is_array($rows)||count($rows)>2)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
    $seen=[];$out=[];
    foreach($rows as $row){
        if(!is_object($row))vp3_profile_call_livekit_error_pa3c();$identity=$row->identity??null;$sid=$row->sid??null;
        if(!in_array($identity,[$binding['owner_identity'],$binding['visitor_identity']],true)||isset($seen[$identity])||!is_string($sid)||!preg_match('/^PA_[A-Za-z0-9_-]{1,90}$/D',$sid))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
        $kind=$row->kind??'STANDARD';if(!in_array($kind,['STANDARD',0],true))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
        $permission=$row->permission??null;if(!is_object($permission))vp3_profile_call_livekit_error_pa3c();
        foreach([['hidden','hidden'],['recorder','recorder'],['can_publish_data','canPublishData'],['can_update_metadata','canUpdateMetadata']] as [$snake,$camel])if(vp3_profile_call_livekit_bool_pa3c(vp3_profile_call_livekit_field_pa3c($permission,$snake,$camel,false)))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
        $sources=vp3_profile_call_livekit_field_pa3c($permission,'can_publish_sources','canPublishSources',[]);
        if(!is_array($sources)||!$sources||count($sources)>2)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
        foreach($sources as $source)if(!in_array($source,$binding['mode']==='audio'?['MICROPHONE',2]:['MICROPHONE','CAMERA',1,2],true))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
        $state=match($row->state??'JOINING'){'JOINING',0=>'joining','JOINED',1=>'joined','ACTIVE',2=>'active',default=>''};if($state==='')vp3_profile_call_livekit_error_pa3c();
        $tracks=$row->tracks??[];if(!is_array($tracks)||count($tracks)>2)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);$mic=false;$camera=false;
        foreach($tracks as $track){if(!is_object($track))vp3_profile_call_livekit_error_pa3c();$source=$track->source??null;$muted=vp3_profile_call_livekit_bool_pa3c($track->muted??false);
            if(in_array($source,['MICROPHONE',2],true))$mic=!$muted;elseif($binding['mode']==='video'&&in_array($source,['CAMERA',1],true))$camera=!$muted;else vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
        }
        $seen[$identity]=true;$out[]=['identity'=>$identity,'sid'=>$sid,'state'=>$state,'microphone'=>$mic,'camera'=>$camera];
    }
    return $out;
}
function vp3_profile_call_livekit_inspect_pa3c(array $config,array $binding,int $deadline,?callable $http=null): array
{
    $response=vp3_profile_call_livekit_http_pa3c($config,'ListRooms',['names'=>[$binding['room_name']]],$deadline,$http);$rooms=$response->rooms??[];
    if(!is_array($rooms)||count($rooms)>1)vp3_profile_call_livekit_error_pa3c();
    $out=['status'=>'absent','room_sid'=>'','room_name'=>$binding['room_name'],'config_fingerprint'=>$config['config_fingerprint'],'owner_connected'=>false,'visitor_connected'=>false,'participants'=>[],'recording'=>false,'observed_at_ms'=>(int)floor(microtime(true)*1000)];
    if(!$rooms)return $out;if(!is_object($rooms[0]))vp3_profile_call_livekit_error_pa3c();$room=vp3_profile_call_livekit_room_pa3c($rooms[0],$binding);
    $participants=vp3_profile_call_livekit_participants_pa3c(vp3_profile_call_livekit_http_pa3c($config,'ListParticipants',['room'=>$binding['room_name']],$deadline,$http),$binding);
    $out=array_merge($out,$room,['status'=>'ready','participants'=>$participants,'observed_at_ms'=>(int)floor(microtime(true)*1000)]);
    foreach($participants as $p)if(in_array($p['state'],['joined','active'],true))$out[$p['identity']===$binding['owner_identity']?'owner_connected':'visitor_connected']=true;
    return $out;
}
function vp3_profile_call_livekit_provider_pa3c(string $operation,array $binding,array $options=[],?callable $http=null): array
{
    if(!in_array($operation,['create','inspect','cleanup','token'],true))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
    $binding=vp3_profile_call_livekit_binding_pa3c($binding);$config=vp3_profile_call_livekit_bound_config_pa3c($binding,$operation==='cleanup');$deadline=vp3_profile_call_livekit_deadline_pa3c($options);
    if($operation==='cleanup'){
        $reserved=$binding['revoke_before_sec']??null;if(!is_int($reserved)||$reserved<1||$reserved>time()+55)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REVOCATION_UNVERIFIED',502,true);
        $cutoffs=[];
        foreach(['owner','visitor'] as $role){$cutoff=max($reserved,time()+5);$reply=vp3_profile_call_livekit_http_pa3c($config,'RemoveParticipant',['room'=>$binding['room_name'],'identity'=>$binding[$role.'_identity'],'revoke_token_ts'=>(string)$cutoff],$deadline,$http);
            if(count(get_object_vars($reply))!==0)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REVOCATION_UNVERIFIED',502,true);$cutoffs[$role]=$cutoff;
        }
        $reply=vp3_profile_call_livekit_http_pa3c($config,'DeleteRoom',['room'=>$binding['room_name']],$deadline,$http);if(count(get_object_vars($reply))!==0&&!isset($reply->__provider_room_absent))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REVOCATION_UNVERIFIED',502,true);
        $response=vp3_profile_call_livekit_http_pa3c($config,'ListRooms',['names'=>[$binding['room_name']]],$deadline,$http);$rooms=$response->rooms??[];
        if(!is_array($rooms)||count($rooms)!==0)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REVOCATION_UNVERIFIED',502,true);
        return ['status'=>'closed','owner_revoked'=>true,'visitor_revoked'=>true,'room_absent'=>true,'owner_revoked_before_sec'=>$cutoffs['owner'],'visitor_revoked_before_sec'=>$cutoffs['visitor'],'revoked_before_sec'=>min($cutoffs),'observed_at_ms'=>(int)floor(microtime(true)*1000)];
    }
    if($operation==='create'){
        $request=['name'=>$binding['room_name'],'empty_timeout'=>120,'departure_timeout'=>10,'max_participants'=>2,'metadata'=>json_encode(['contract'=>'vp3.profile-call.pa3c','call'=>$binding['public_id']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'replay_enabled'=>false];
        // No agents/egress/preset. Empty agents is NOT a dispatch-disable switch.
        $room=vp3_profile_call_livekit_room_pa3c(vp3_profile_call_livekit_http_pa3c($config,'CreateRoom',$request,$deadline,$http),$binding);$binding['room_sid']=$room['room_sid'];
    }
    $proof=vp3_profile_call_livekit_inspect_pa3c($config,$binding,$deadline,$http);
    if($operation!=='token')return $proof;
    if($proof['status']!=='ready')vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_ROOM_UNSAFE',409);
    $role=$options['role']??null;$intent=$options['join_intent_id']??null;$issued=$options['issued_at_ms']??null;$expires=$options['expires_at_ms']??null;$camera=$options['camera']??false;
    $now=(int)floor(microtime(true)*1000);
    if(!in_array($role,['owner','visitor'],true)||!is_string($intent)||!preg_match('/^[a-f0-9]{32}$/D',$intent)||($options['microphone']??null)!==true||!is_bool($camera)||($camera&&$binding['mode']!=='video')
        ||!is_int($issued)||!is_int($expires)||$issued>$now+1000||$issued<$now-60000||$expires<=$now||$expires-$issued>60000||$expires<=$issued)vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
    $identity=$binding[$role.'_identity'];$peer=$binding[$role==='owner'?'visitor_identity':'owner_identity'];$nbf=intdiv($issued,1000)-5;
    $claims=['iss'=>$config['api_key'],'sub'=>$identity,'nbf'=>$nbf,'iat'=>intdiv($issued,1000),'exp'=>intdiv($expires,1000),'video'=>['room'=>$binding['room_name'],'roomJoin'=>true,'canPublish'=>true,'canPublishSources'=>$camera?['microphone','camera']:['microphone'],'canSubscribe'=>true,'canPublishData'=>false,'canUpdateOwnMetadata'=>false,'hidden'=>false],'metadata'=>json_encode(['contract'=>'vp3.profile-call.pa3c','call'=>$binding['public_id'],'join'=>$intent],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
    return ['server_url'=>$config['url'],'participant_token'=>vp3_profile_call_livekit_jwt_pa3c($config,$claims),'participant_identity'=>$identity,'peer_identity'=>$peer,'expires_at_ms'=>$expires,'issued_at_ms'=>$issued,'not_before_sec'=>$nbf,'room_sid'=>$proof['room_sid'],'config_fingerprint'=>$config['config_fingerprint']];
}

function vp3_profile_call_livekit_store_pa3c(PDO $pdo,string $key,string $value): void
{
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value':'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';
    $pdo->prepare($sql)->execute([$key,$value]);
}
/** Admin-only callers authorize and check CSRF before entering this settings service. */
function vp3_profile_call_livekit_save_pa3c(PDO $pdo,array $input): array
{
    $old=vp3_profile_call_livekit_settings_pa3c();$source=$input['source']??'profile';if(!in_array($source,['profile','meetings'],true))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
    $enabled=($input['enabled']??'')==='1';$ack=($input['human_only_acknowledged']??'')==='1';$prior=null;try{$prior=vp3_profile_call_livekit_config_pa3c($old);}catch(Throwable){}
    if($source==='meetings')$config=vp3_profile_call_livekit_credentials_pa3c(video_meeting_livekit_config_v1800());
    else{
        foreach(['url','api_key','api_secret'] as $key)if(isset($input[$key])&&!is_string($input[$key]))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
        $raw=['url'=>trim($input['url']??''),'api_key'=>trim($input['api_key']??''),'api_secret'=>trim($input['api_secret']??'')];
        if($prior&&($old['profile_calls_source_pa3c']??'profile')==='profile'){
            if($raw['url']==='')$raw['url']=$prior['url'];
            if($raw['api_key']==='')$raw['api_key']=$prior['api_key'];
            if($raw['api_secret']===''){
                if(vp3_profile_call_livekit_origin_pa3c($raw['url'])!==$prior['url']||$raw['api_key']!==$prior['api_key'])vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_REQUEST_INVALID',422);
                $raw['api_secret']=$prior['api_secret'];
            }
        }
        $config=vp3_profile_call_livekit_credentials_pa3c($raw);
    }
    $fingerprint=vp3_profile_call_livekit_fingerprint_pa3c($config);
    // Replacing a missing master key must never silently strand old call cleanup snapshots.
    if(ai_master_key_state()!=='ready'&&($old['profile_calls_livekit_config_pa3c']??'')!=='')vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CREDENTIALS_UNAVAILABLE',503,true);
    $cipher=$source==='profile'?ai_encrypt_secret(json_encode($config,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)):($old['profile_calls_livekit_config_pa3c']??'');
    if($source==='meetings'&&ai_master_key_state()!=='ready')ai_encrypt_secret('profile-call-key-initialization');
    $changed=!$prior||!hash_equals($prior['config_fingerprint'],$fingerprint)||($old['profile_calls_source_pa3c']??'profile')!==$source;
    // Queue original-provider cleanup before changing or disabling its configuration.
    if(($changed||!$enabled||!$ack)&&function_exists('vp3_profile_call_configuration_changed_pa3c'))vp3_profile_call_configuration_changed_pa3c($pdo);
    $pdo->beginTransaction();try{
        vp3_profile_call_livekit_store_pa3c($pdo,'profile_calls_source_pa3c',$source);vp3_profile_call_livekit_store_pa3c($pdo,'profile_calls_enabled_pa3c',$enabled?'1':'0');
        vp3_profile_call_livekit_store_pa3c($pdo,'profile_calls_human_only_ack_pa3c',$ack?$fingerprint:'');
        if($source==='profile')vp3_profile_call_livekit_store_pa3c($pdo,'profile_calls_livekit_config_pa3c',$cipher);
        if($changed)vp3_profile_call_livekit_store_pa3c($pdo,'profile_calls_api_verified_pa3c','');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_profile_call_livekit_readiness_pa3c();
}
function vp3_profile_call_livekit_verify_pa3c(PDO $pdo,?callable $http=null): array
{
    $config=vp3_profile_call_livekit_config_pa3c();$nonce='vp3-profile-readiness-'.bin2hex(random_bytes(16));$deadline=(int)floor(microtime(true)*1000)+10000;
    $reply=vp3_profile_call_livekit_http_pa3c($config,'ListRooms',['names'=>[$nonce]],$deadline,$http);$rooms=$reply->rooms??[];
    if(!is_array($rooms)||$rooms!==[])vp3_profile_call_livekit_error_pa3c();
    $current=vp3_profile_call_livekit_config_pa3c();if(!hash_equals($current['config_fingerprint'],$config['config_fingerprint']))vp3_profile_call_livekit_error_pa3c('CALL_PROVIDER_CONFIGURATION_CHANGED',409);
    vp3_profile_call_livekit_store_pa3c($pdo,'profile_calls_api_verified_pa3c',json_encode(['config_fingerprint'=>$config['config_fingerprint'],'verified_at_ms'=>(int)floor(microtime(true)*1000)],JSON_THROW_ON_ERROR));
    return vp3_profile_call_livekit_readiness_pa3c();
}
