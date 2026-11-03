<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-speech-provider-pa4a.php';
$checks=0;
function sp_check(bool $ok,string $message): void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS '.$message."\n";}
function sp_reject(callable $call,string $label,?string $code=null): void{try{$call();}catch(VP3ProfileSpeechProviderException $e){sp_check(($code===null||$code===$e->publicCode)&&!str_contains($e->getMessage(),'SECRET_'),$label);return;}throw new RuntimeException('Expected safe rejection: '.$label);}
function sp_posts(): int{return count(array_filter($GLOBALS['spCalls'],static fn($r)=>$r['method']==='POST'));}

$config=vp3_profile_speech_config_pa4a();sp_check($config['ready']&&$config['credential_state']==='saved'&&!str_contains(json_encode($config),'SECRET_'),'saved encrypted credential wins without entering public configuration');
sp_setting('ai_elevenlabs_model_id','eleven_v3');$configured=vp3_profile_speech_config_pa4a();sp_check($configured['ready']&&$configured['model_id']==='eleven_v3','a trusted newer model ID is preserved rather than replaced with an older model');
$request=sp_request();$GLOBALS['spCalls']=[];$configuredResult=vp3_profile_speech_synthesize_pa4a($request,'sp_http');$configuredPost=array_values(array_filter($GLOBALS['spCalls'],fn($r)=>$r['method']==='POST'))[0];sp_check($configuredResult['status']==='ready'&&json_decode($configuredPost['body'],true)['model_id']==='eleven_v3','synthesis sends the explicit model ID unchanged');
putenv('ELEVENLABS_MODEL_ID=eleven_explicit_environment');sp_check(vp3_profile_speech_config_pa4a()['model_id']==='eleven_explicit_environment','an explicit environment model is preserved as configured');
putenv('ELEVENLABS_MODEL_ID=   ');sp_check(vp3_profile_speech_config_pa4a()['model_id']==='eleven_v3','blank environment model retains the saved explicit model');
sp_setting('ai_elevenlabs_model_id','  ');putenv('ELEVENLABS_OUTPUT_FORMAT=  ');$blank=vp3_profile_speech_config_pa4a();sp_check($blank['model_id']==='eleven_flash_v2_5'&&$blank['output_format']==='mp3_22050_32','only absent or blank model and format use their defaults');
sp_setting('ai_elevenlabs_model_id','bad/model SECRET_PRIVATE');$GLOBALS['spCalls']=[];$invalid=vp3_profile_speech_admin_state_pa4a();sp_check(!$invalid['ready']&&$invalid['reason']==='model_unsupported'&&$invalid['settings_revision']===''&&!str_contains(json_encode($invalid),'SECRET_')&&$GLOBALS['spCalls']===[],'invalid explicit model produces a safe unavailable Admin status with no provider call');
sp_reset();putenv('ELEVENLABS_OUTPUT_FORMAT=pcm_44100');$invalid=vp3_profile_speech_admin_state_pa4a();sp_check(!$invalid['ready']&&$invalid['reason']==='format_unsupported'&&$invalid['output_format']==='','explicit non-MP3 output is unavailable instead of silently replaced');
putenv('ELEVENLABS_OUTPUT_FORMAT=0');sp_reject(fn()=>vp3_profile_speech_configuration_pa4a(),'a nonblank false-like format is rejected rather than treated as absent','PROFILE_SPEECH_FORMAT_UNSUPPORTED');
sp_reset();
$catalog=vp3_profile_speech_catalog_pa4a('sp_http');sp_check($catalog['ready']&&count($catalog['voices'])===4,'current provider default catalogue is dynamic and complete');
$unknown=array_values(array_filter($catalog['voices'],fn($r)=>$r['voice_id']==='DefaultUnknownVoice'))[0];sp_check($unknown['style']==='unclassified'&&strlen($unknown['preset_key'])===64,'missing provider category is not inferred neutral or a visitor identity');
$first=$GLOBALS['spCalls'][0];sp_check(str_contains($first['url'],'voice_type=default')&&str_contains($first['url'],'include_total_count=false')&&$first['timeout']===8000,'default lookup preserves bounded provider filter and token pagination contract');
$owner=vp3_profile_speech_owner_voice_pa4a($pdo,['id'=>1],11,12);sp_check($owner['narrator']['voice_id']==='OwnerSystemVoice001'&&in_array('NarratorVoice000001',$owner['excluded_voice_ids'],true)&&in_array('OwnerSystemVoice001',$owner['excluded_voice_ids'],true),'owner system and global narrator voices are excluded from visitor assignment');
$before=(int)$pdo->query('SELECT COUNT(*) FROM studio_participants')->fetchColumn();$pdo->exec('UPDATE user_agents SET voice_enabled=1 WHERE id=11');$owner=vp3_profile_speech_owner_voice_pa4a($pdo,['id'=>1],11,12);
sp_check($owner['narrator']['voice_id']==='OwnerCloneVoice001'&&$owner['narrator']['provenance']==='owner_clone'&&!in_array('ForeignCloneVoice01',$owner['excluded_voice_ids'],true),'only the exact owned self clone can be the owner narrator');
sp_check((int)$pdo->query('SELECT COUNT(*) FROM studio_participants')->fetchColumn()===$before,'voice exclusion never creates a Studio identity');
foreach(['cloning consent'=>'UPDATE studio_participants SET cloning_consent=0 WHERE id=101','clone enable'=>'UPDATE studio_participant_voices SET clone_enabled=0 WHERE id=1','verification'=>'UPDATE studio_participant_voices SET clone_verified=0 WHERE id=1','revocation'=>"UPDATE studio_participant_voices SET revoked_at='2026-11-03 01:00:00' WHERE id=1"] as $label=>$sql){sp_reset();$pdo->exec('UPDATE user_agents SET voice_enabled=1 WHERE id=11');$pdo->exec($sql);sp_check(vp3_profile_speech_owner_voice_pa4a($pdo,['id'=>1],11,12)['narrator']===null,'unavailable owner clone does not fall back after '.$label);}
sp_reset();sp_reject(fn()=>vp3_profile_speech_owner_voice_pa4a($pdo,['id'=>1],21,12),'foreign Agent cannot select a narrator','PROFILE_SPEECH_VOICE_UNAVAILABLE');

$state=vp3_profile_speech_admin_state_pa4a();$saved=vp3_profile_speech_admin_save_pa4a($pdo,['enabled'=>true,'settings_revision'=>$state['settings_revision'],'presets'=>[['voice_id'=>'PublicVoiceGood001','label'=>'Approved visitor']]],'sp_http');
sp_check(count($saved['presets'])===1&&$saved['presets'][0]['style']==='female'&&$saved['presets'][0]['credentials_current'],'Admin explicit preset saves exact current public-origin proof');
$GLOBALS['spDefaults']=[];$catalog=vp3_profile_speech_catalog_pa4a('sp_http');sp_check(count($catalog['voices'])===1&&$catalog['voices'][0]['provenance']==='admin_public','new accounts with no old defaults can use only an explicitly approved public preset');
sp_check(!str_contains(json_encode($catalog),'whitelisted_emails')&&!str_contains(json_encode($catalog),'SECRET_'),'catalogue projection omits provider diagnostics, credentials and private sharing fields');
$GLOBALS['spHook']=fn($r)=>str_contains($r['url'],'/v1/voices/')?sp_response(sp_voice('PublicVoiceGood001',['sharing'=>['status'=>'enabled','enabled_in_library'=>true,'review_status'=>'allowed','public_owner_id'=>'ChangedPublicOwner01','original_voice_id'=>'OriginalPublic001']])):null;
sp_check(!vp3_profile_speech_catalog_pa4a('sp_http')['ready'],'a changed public origin invalidates an approved voice without replacement guessing');

foreach([
    'private clone'=>['is_owner'=>true],
    'missing owner classification'=>['is_owner'=>null],
    'retired voice'=>['is_legacy'=>true],
    'sharing removed'=>['sharing'=>null],
    'private sharing'=>['sharing'=>['status'=>'enabled','enabled_in_library'=>false,'review_status'=>'allowed','public_owner_id'=>'PublicOwnerOrigin01','original_voice_id'=>'OriginalPublic001']],
    'moderation pending'=>['sharing'=>['status'=>'enabled','enabled_in_library'=>true,'review_status'=>'pending','public_owner_id'=>'PublicOwnerOrigin01','original_voice_id'=>'OriginalPublic001']],
    'elapsed notice'=>['sharing'=>['status'=>'enabled','enabled_in_library'=>true,'review_status'=>'allowed','public_owner_id'=>'PublicOwnerOrigin01','original_voice_id'=>'OriginalPublic001','disable_at_unix'=>time()-1]],
    'string public flag'=>['sharing'=>['status'=>'enabled','enabled_in_library'=>'true','review_status'=>'allowed','public_owner_id'=>'PublicOwnerOrigin01','original_voice_id'=>'OriginalPublic001']],
    'banned provider voice'=>['safety_control'=>'BAN'],
] as $label=>$change)sp_reject(fn()=>vp3_profile_speech_public_voice_pa4a(sp_voice('PublicVoiceGood001',$change),'PublicVoiceGood001'),'public preset rejects '.$label,'PROFILE_SPEECH_PUBLIC_VOICE_REQUIRED');
sp_reset();$before=vp3_profile_speech_admin_state_pa4a();$GLOBALS['spHook']=function($r){if(str_contains($r['url'],'/v1/voices/'))sp_setting('ai_elevenlabs_model_id','eleven_turbo_v2_5');return null;};
sp_reject(fn()=>vp3_profile_speech_admin_save_pa4a($pdo,['enabled'=>true,'settings_revision'=>$before['settings_revision'],'presets'=>[['voice_id'=>'PublicVoiceGood001','label'=>'Visitor']]],'sp_http'),'configuration race cannot approve a voice against another setting revision','PROFILE_SPEECH_CONFIGURATION_CHANGED');
sp_check(setting('profile_speech_public_presets_pa4a','')==='','failed Admin proof leaves existing preset storage untouched');
sp_reset();$before=vp3_profile_speech_admin_state_pa4a();sp_reject(fn()=>vp3_profile_speech_admin_save_pa4a($pdo,['enabled'=>true,'settings_revision'=>$before['settings_revision'],'presets'=>array_fill(0,9,['voice_id'=>'PublicVoiceGood001'])],'sp_http'),'approved public preset count is bounded','PROFILE_SPEECH_INVALID');

sp_reset();$request=sp_request();$GLOBALS['spCalls']=[];$result=vp3_profile_speech_synthesize_pa4a($request,'sp_http');
sp_check($result['status']==='ready'&&$result['provider_attempted']&&sp_posts()===1,'one exact source produces one bounded upstream speech POST');
$post=array_values(array_filter($GLOBALS['spCalls'],fn($r)=>$r['method']==='POST'))[0];$payload=json_decode($post['body'],true);
sp_check($payload===['text'=>'A saved public message.','model_id'=>'eleven_flash_v2_5']&&!isset($payload['previous_text'],$payload['next_text']),'synthesis sends only the resolved public source and selected model');
sp_check($post['timeout']===30000&&$post['limit']===4194304&&!str_contains($post['url'],'SECRET_'),'fixed HTTPS operation and complete audio size are bounded');
sp_check($result['provider_character_cost']==='12.5'&&$result['provider_request_id']==='request-fixture-001'&&$result['input_characters']===23,'reported provider usage remains distinct from local input character count');
sp_check(!str_contains(json_encode($result),STONEFELLOW_ROOT)&&!str_contains(json_encode($result),'SECRET_'),'receipt contains no private path or provider credential');
sp_check(vp3_profile_speech_audio_read_pa4a($result)===sp_mp3(),'private cached audio has verified exact bytes and hash');$calls=count($GLOBALS['spCalls']);vp3_profile_speech_audio_read_pa4a($result);sp_check(count($GLOBALS['spCalls'])===$calls,'cached replay never invokes synthesis');
$expired=$result;$expired['audio_expires_at_ms']=vp3_profile_speech_provider_now_pa4a()-1;sp_reject(fn()=>vp3_profile_speech_audio_read_pa4a($expired),'expired cached audio requires an explicit new attempt','PROFILE_SPEECH_AUDIO_EXPIRED');
$bad=$result;$bad['audio_key']='../../SECRET';sp_reject(fn()=>vp3_profile_speech_audio_read_pa4a($bad),'audio cache does not accept a client path','PROFILE_SPEECH_AUDIO_UNAVAILABLE');
$bad=$result;$bad['audio_sha256']=str_repeat('0',64);sp_reject(fn()=>vp3_profile_speech_audio_read_pa4a($bad),'changed audio content cannot be replayed','PROFILE_SPEECH_AUDIO_UNAVAILABLE');

foreach([
    'timeout'=>['status'=>0,'headers'=>[],'body'=>'','complete'=>false],
    'truncated stream'=>['status'=>200,'headers'=>['content-type'=>'audio/mpeg','character-cost'=>'23'],'body'=>substr(sp_mp3(),0,-1),'complete'=>false],
    'damaged MP3'=>['status'=>200,'headers'=>['content-type'=>'audio/mpeg'],'body'=>'ID3'.str_repeat('x',120),'complete'=>true],
    'JSON masquerading as audio'=>['status'=>200,'headers'=>['content-type'=>'audio/mpeg'],'body'=>'{"error":"SECRET_DIAGNOSTIC"}','complete'=>true],
    'redirect'=>['status'=>302,'headers'=>[],'body'=>'','complete'=>true],
    'provider failure'=>['status'=>503,'headers'=>[],'body'=>'SECRET_DIAGNOSTIC','complete'=>true],
] as $label=>$response){sp_reset();$request=sp_request();$GLOBALS['spCalls']=[];$GLOBALS['spHook']=fn($r)=>$r['method']==='POST'?$response:null;$out=vp3_profile_speech_synthesize_pa4a($request,'sp_http');sp_check($out['status']==='outcome_unverified'&&$out['provider_attempted']&&$out['audio_key']===null&&sp_posts()===1,'uncertain '.$label.' is not retried or called completed');sp_check(!str_contains(json_encode($out),'SECRET_'),'uncertain '.$label.' returns fixed safe diagnostics');}
foreach([401,403,422,429] as $status){sp_reset();$request=sp_request();$GLOBALS['spHook']=fn($r)=>$r['method']==='POST'?['status'=>$status,'headers'=>[],'body'=>'SECRET_DIAGNOSTIC','complete'=>true]:null;$out=vp3_profile_speech_synthesize_pa4a($request,'sp_http');sp_check($out['status']==='rejected'&&$out['provider_character_cost']===null&&$out['audio_key']===null,'explicit provider rejection '.$status.' has no invented usage or output');}
sp_reset();$request=sp_request();$GLOBALS['spHook']=fn($r)=>$r['method']==='POST'?['status'=>200,'headers'=>['content-type'=>'audio/mpeg','character-cost'=>'SECRET_INVALID_USAGE','request-id'=>"bad\r\nid"],'body'=>sp_mp3(),'complete'=>true]:null;$out=vp3_profile_speech_synthesize_pa4a($request,'sp_http');sp_check($out['status']==='ready'&&$out['provider_character_cost']===null&&$out['provider_request_id']===null,'missing or invalid usage stays unreported while validated audio remains usable');
sp_reset();$request=sp_request();$GLOBALS['spCalls']=[];$out=vp3_profile_speech_synthesize_pa4a($request,'sp_http',fn()=>throw new RuntimeException('Current source revoked'));sp_check($out['status']==='blocked'&&!$out['provider_attempted']&&sp_posts()===0,'late authority gate runs after metadata and before paid synthesis');
sp_reset();$request=sp_request();sp_setting('ai_elevenlabs_api_key',ai_encrypt_secret('SECRET_ROTATED_ELEVENLABS_KEY'));$GLOBALS['spCalls']=[];$out=vp3_profile_speech_synthesize_pa4a($request,'sp_http');sp_check($out['status']==='blocked'&&!$out['provider_attempted']&&$GLOBALS['spCalls']===[],'rotated credential invalidates the saved attempt before provider access');
sp_reset();$request=sp_request();$GLOBALS['spHook']=fn($r)=>str_contains($r['url'],'/v1/voices/DefaultMaleVoice001')?sp_response(['voice_id'=>'RedirectedVoice0001','is_legacy'=>false]):null;$out=vp3_profile_speech_synthesize_pa4a($request,'sp_http');sp_check($out['status']==='blocked'&&!$out['provider_attempted'],'provider voice alias cannot silently change the assigned speaker');

sp_reset();$request=sp_request();$out=vp3_profile_speech_synthesize_pa4a($request,'sp_http');$path=vp3_profile_speech_private_root_pa4a().'/audio-'.$out['audio_key'].'.mp3';$q=$pdo->prepare('INSERT INTO profile_speech_attempts_pa4a(id,state,provider_state,audio_key,audio_bytes,audio_sha256,audio_expires_at_ms,audio_removed_at_ms) VALUES(?,?,?,?,?,?,?,NULL)');$q->execute([1,'ready','ready',$out['audio_key'],$out['audio_bytes'],$out['audio_sha256'],vp3_profile_speech_provider_now_pa4a()-1]);$q->execute([2,'sending','outcome_unverified',null,0,null,null]);
$result=vp3_profile_speech_audio_cleanup_pa4a($pdo,1);$row=$pdo->query('SELECT * FROM profile_speech_attempts_pa4a WHERE id=1')->fetch();sp_check($result['removed']===1&&!is_file($path)&&$row['audio_key']===null&&$row['state']==='ready'&&$row['provider_state']==='ready','retention cleanup removes only expired audio without rewriting generation history');
sp_check($pdo->query('SELECT state FROM profile_speech_attempts_pa4a WHERE id=2')->fetchColumn()==='sending','retention cannot turn an uncertain sending attempt into a new request');
sp_reject(fn()=>vp3_profile_speech_audio_read_pa4a($row),'cleaned receipt remains expired instead of regenerating','PROFILE_SPEECH_AUDIO_EXPIRED');
foreach([['POST','/v1/voices/DefaultMaleVoice001'],['GET','https://evil.example/'],['POST','/v1/text-to-speech/DefaultMaleVoice001/stream?output_format=wav'],['GET','/v1/voices/../../settings']] as [$method,$path])sp_reject(fn()=>vp3_profile_speech_http_pa4a($method,$path,vp3_profile_speech_configuration_pa4a(),null,'sp_http'),'transport rejects unsupported method/path '.$method.' '.$path,'PROFILE_SPEECH_INVALID');
sp_reset();$GLOBALS['vp3ProfileSpeechMetadataDeadlinePa4a']=(int)floor(hrtime(true)/1000000)+100;$config=vp3_profile_speech_configuration_pa4a();vp3_profile_speech_http_pa4a('GET','/v1/voices/DefaultMaleVoice001',$config,null,'sp_http');sp_check($GLOBALS['spCalls'][0]['timeout']>0&&$GLOBALS['spCalls'][0]['timeout']<=100,'each metadata GET is limited by the remaining aggregate request budget');
$GLOBALS['vp3ProfileSpeechMetadataDeadlinePa4a']=(int)floor(hrtime(true)/1000000)-1;$before=count($GLOBALS['spCalls']);$r=vp3_profile_speech_http_pa4a('GET','/v1/voices/DefaultMaleVoice001',$config,null,'sp_http');sp_check($r['status']===0&&!$r['complete']&&count($GLOBALS['spCalls'])===$before,'an exhausted metadata budget performs no further provider request');
$catalog=vp3_profile_speech_catalog_pa4a('sp_http');sp_check(!$catalog['ready']&&$catalog['reason']==='catalog_unavailable'&&count($GLOBALS['spCalls'])===$before,'metadata outage returns bounded unavailable status without stale voice substitution');
echo 'PROFILE_SPEECH_PROVIDER_PA4A=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
