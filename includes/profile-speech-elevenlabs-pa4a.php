<?php
declare(strict_types=1);

/** Source-bound Profile speech. This module never resolves browser-supplied text. */
// Retention cleanup is deliberately independent of AI/bootstrap initialization.

const VP3_PROFILE_SPEECH_AUDIO_MAX_PA4A = 4194304;
const VP3_PROFILE_SPEECH_AUDIO_TTL_PA4A = 600000;
const VP3_PROFILE_SPEECH_METADATA_BUDGET_PA4A = 12000;

final class VP3ProfileSpeechProviderException extends RuntimeException
{
    public function __construct(public string $publicCode,public int $httpStatus=503)
    {
        parent::__construct(match($publicCode){
            'PROFILE_SPEECH_INVALID'=>'Choose a valid Profile speech operation.',
            'PROFILE_SPEECH_CONFIGURATION_CHANGED'=>'Speech settings changed. Refresh the current voice selection.',
            'PROFILE_SPEECH_CATALOG_UNAVAILABLE'=>'Visitor voices are unavailable. Ask an administrator to validate an accessible public voice in Profile speech settings.',
            'PROFILE_SPEECH_PUBLIC_VOICE_REQUIRED'=>'Choose an accessible, currently published public-library voice. Private voice clones cannot be visitor presets.',
            'PROFILE_SPEECH_VOICE_UNAVAILABLE'=>'This voice is no longer available for this speaker. Choose an available preset.',
            'PROFILE_SPEECH_AUDIO_EXPIRED'=>'This saved audio has expired. An explicit new generation may use additional provider quota.',
            'PROFILE_SPEECH_AUDIO_UNAVAILABLE'=>'The saved audio is unavailable. It was not generated again.',
            'PROFILE_SPEECH_STORAGE_UNAVAILABLE'=>'Private speech storage is unavailable.',
            'PROFILE_SPEECH_DISABLED'=>'Profile speech is turned off.',
            'PROFILE_SPEECH_MODEL_UNSUPPORTED'=>'The configured ElevenLabs model ID is invalid. Correct it in AI / API settings before using Profile speech.',
            'PROFILE_SPEECH_FORMAT_UNSUPPORTED'=>'The configured ElevenLabs output format is not supported by Profile speech. Use mp3_22050_32 or mp3_44100_128 in the server configuration.',
            default=>'Profile speech is unavailable. Check the existing ElevenLabs connection in AI / API settings.',
        },$httpStatus);
    }
}
function vp3_profile_speech_provider_error_pa4a(string $code='PROFILE_SPEECH_PROVIDER_UNAVAILABLE',int $status=503): never
{throw new VP3ProfileSpeechProviderException($code,$status);}
function vp3_profile_speech_provider_now_pa4a(): int{return (int)floor(microtime(true)*1000);}
/** One aggregate metadata allowance across pagination, presets and final voice checks. */
function vp3_profile_speech_metadata_remaining_pa4a(): int
{
    $now=(int)floor(hrtime(true)/1000000);
    if(!isset($GLOBALS['vp3ProfileSpeechMetadataDeadlinePa4a']))$GLOBALS['vp3ProfileSpeechMetadataDeadlinePa4a']=$now+VP3_PROFILE_SPEECH_METADATA_BUDGET_PA4A;
    return max(0,min(VP3_PROFILE_SPEECH_METADATA_BUDGET_PA4A,(int)$GLOBALS['vp3ProfileSpeechMetadataDeadlinePa4a']-$now));
}
function vp3_profile_speech_voice_id_pa4a(mixed $value): string
{if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$value))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_INVALID',422);return $value;}
function vp3_profile_speech_label_pa4a(mixed $value,string $default='Voice'): string
{if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return $default;return mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]/u',' ',$value)),0,120)?:$default;}
function vp3_profile_speech_style_pa4a(mixed $value): string
{$value=is_string($value)?strtolower(trim($value)):'';return in_array($value,['male','female','neutral'],true)?$value:'unclassified';}
function vp3_profile_speech_hash_pa4a(array $value): string
{return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}

/** Strict SQL reads: a settings lookup failure cannot activate an environment fallback. */
function vp3_profile_speech_settings_pa4a(?PDO $pdo=null): array
{
    $pdo??=function_exists('db')?db():null;if(!$pdo)vp3_profile_speech_provider_error_pa4a();
    $keys=['ai_elevenlabs_api_key','ai_elevenlabs_voice_id','ai_elevenlabs_model_id','profile_speech_enabled_pa4a','profile_speech_public_presets_pa4a'];
    $q=$pdo->prepare('SELECT setting_key,setting_value FROM settings WHERE setting_key IN (?,?,?,?,?)');$q->execute($keys);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['setting_key']]=(string)$row['setting_value'];return $out;
}
function vp3_profile_speech_configuration_pa4a(): array
{
    if(!function_exists('ai_elevenlabs_credential'))require_once __DIR__.'/ai-settings.php';
    $settings=vp3_profile_speech_settings_pa4a();[$key,$state]=ai_elevenlabs_credential();
    $encrypted=trim($settings['ai_elevenlabs_api_key']??'');
    if($encrypted!==''){
        $expected=ai_encrypted_secret_state($encrypted);
        if($expected!=='ready'||$state!=='saved'||!is_string($key)||!hash_equals(ai_decrypt_secret($encrypted),$key))vp3_profile_speech_provider_error_pa4a();
    }elseif(!in_array($state,['environment','missing'],true))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CONFIGURATION_CHANGED',409);
    if(!is_string($key)||strlen($key)>1024||preg_match('/[^\x21-\x7e]/D',$key))vp3_profile_speech_provider_error_pa4a();
    $environmentModel=getenv('ELEVENLABS_MODEL_ID');
    $model=$environmentModel!==false&&trim($environmentModel)!==''?trim($environmentModel):trim($settings['ai_elevenlabs_model_id']??'');
    if($model==='')$model='eleven_flash_v2_5';
    // A trusted explicit model is sent unchanged; provider support is determined
    // by its response, never by silently selecting an older model here.
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D',$model))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_MODEL_UNSUPPORTED');
    $environmentFormat=getenv('ELEVENLABS_OUTPUT_FORMAT');$format=$environmentFormat===false?'':trim($environmentFormat);
    if($format==='')$format='mp3_22050_32';
    if(!in_array($format,['mp3_22050_32','mp3_44100_128'],true))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_FORMAT_UNSUPPORTED');
    $raw=$settings['profile_speech_public_presets_pa4a']??'';$presets=[];
    if($raw!==''){
        if(strlen($raw)>32768)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CATALOG_UNAVAILABLE');
        try{$presets=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}catch(Throwable){vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CATALOG_UNAVAILABLE');}
        if(!is_array($presets)||!array_is_list($presets)||count($presets)>8)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CATALOG_UNAVAILABLE');
    }
    $enabled=($settings['profile_speech_enabled_pa4a']??'1')==='1';$credential=hash('sha256',$key);
    $fingerprint=vp3_profile_speech_hash_pa4a([$credential,$model,$format,$enabled,$presets]);
    return ['api_key'=>$key,'credential_state'=>$state,'credential_fingerprint'=>$credential,'config_fingerprint'=>$fingerprint,'model_id'=>$model,'output_format'=>$format,'enabled'=>$enabled,'presets'=>$presets,'settings'=>$settings];
}
/** No secret is included in configuration projections. */
function vp3_profile_speech_config_pa4a(): array
{
    try{$config=vp3_profile_speech_configuration_pa4a();$reason=!$config['enabled']?'disabled':($config['api_key']===''?'credential_unavailable':(!function_exists('curl_init')?'transport_unavailable':'ready'));
        return ['ready'=>$reason==='ready','reason'=>$reason,'credential_state'=>$config['credential_state'],'config_fingerprint'=>$config['config_fingerprint'],'model_id'=>$config['model_id'],'output_format'=>$config['output_format'],'enabled'=>$config['enabled']];
    }catch(Throwable $error){$reason=$error instanceof VP3ProfileSpeechProviderException?match($error->publicCode){'PROFILE_SPEECH_MODEL_UNSUPPORTED'=>'model_unsupported','PROFILE_SPEECH_FORMAT_UNSUPPORTED'=>'format_unsupported',default=>'configuration_unavailable'}:'configuration_unavailable';return ['ready'=>false,'reason'=>$reason,'credential_state'=>'unavailable','config_fingerprint'=>'','model_id'=>'','output_format'=>'','enabled'=>false];}
}

/** One fixed-origin request, no redirects or automatic retries. */
function vp3_profile_speech_http_pa4a(string $method,string $path,array $config,?array $payload=null,?callable $transport=null): array
{
    $valid=$method==='GET'?preg_match('#^/v[12]/voices(?:/[A-Za-z0-9_-]{8,128})?(?:\?[^\r\n]*)?$#D',$path):($method==='POST'&&preg_match('#^/v1/text-to-speech/[A-Za-z0-9_-]{8,128}/stream\?output_format=mp3_(?:22050_32|44100_128)$#D',$path));
    if(!$valid)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_INVALID',422);
    $url='https://api.elevenlabs.io'.$path;$audio=$method==='POST';$limit=$audio?VP3_PROFILE_SPEECH_AUDIO_MAX_PA4A:2097152;$timeout=$audio?30000:min(8000,vp3_profile_speech_metadata_remaining_pa4a());
    if($timeout<1)return ['status'=>0,'headers'=>[],'body'=>'','complete'=>false];
    $headers=['xi-api-key: '.$config['api_key'],'Accept: '.($audio?'audio/mpeg':'application/json')];$body=$payload===null?'':json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if($audio)$headers[]='Content-Type: application/json';
    if($transport){
        try{$result=$transport($method,$url,$headers,$body,$timeout,$limit);}catch(Throwable){return ['status'=>0,'headers'=>[],'body'=>'','complete'=>false];}
        if(!is_array($result)||!is_int($result['status']??null)||!is_array($result['headers']??null)||!is_string($result['body']??null)||!is_bool($result['complete']??null)||strlen($result['body'])>$limit)return ['status'=>0,'headers'=>[],'body'=>'','complete'=>false];
        return $result;
    }
    if(!function_exists('curl_init'))return ['status'=>0,'headers'=>[],'body'=>'','complete'=>false];
    $received=[];$raw='';$overflow=false;$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$received):int{
            if(preg_match('/^HTTP\/\S+\s+\d{3}/',$line))$received=[];
            elseif(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$name=strtolower(trim($name));if(in_array($name,['content-type','character-cost','request-id','x-trace-id'],true))$received[$name]=trim($value);}
            return strlen($line);
        },CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw,&$overflow,$limit):int{if(strlen($raw)+strlen($chunk)>$limit){$overflow=true;return 0;}$raw.=$chunk;return strlen($chunk);}]);
    if($audio)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $complete=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);
    return ['status'=>$status,'headers'=>$received,'body'=>$raw,'complete'=>$complete===true&&$error===0&&!$overflow];
}
function vp3_profile_speech_json_response_pa4a(array $response): array
{
    if(($response['status']??0)!==200||($response['complete']??false)!==true||!is_string($response['body']??null))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CATALOG_UNAVAILABLE');
    try{$value=json_decode($response['body'],false,32,JSON_THROW_ON_ERROR);}catch(Throwable){vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CATALOG_UNAVAILABLE');}
    if(!$value instanceof stdClass)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CATALOG_UNAVAILABLE');
    return json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);
}
function vp3_profile_speech_public_voice_pa4a(array $voice,string $id): array
{
    $sharing=$voice['sharing']??null;$disabled=is_array($sharing)?($sharing['disable_at_unix']??null):null;
    if(($voice['voice_id']??null)!==$id||($voice['is_owner']??null)!==false||($voice['is_legacy']??false)!==false||!is_array($sharing)
        ||($sharing['status']??null)!=='enabled'||($sharing['enabled_in_library']??null)!==true||($sharing['review_status']??null)!=='allowed'
        ||!is_string($sharing['public_owner_id']??null)||!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$sharing['public_owner_id'])
        ||!is_string($sharing['original_voice_id']??null)||!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$sharing['original_voice_id'])
        ||($disabled!==null&&(!is_int($disabled)||$disabled<=time()))
        ||(isset($voice['safety_control'])&&$voice['safety_control']!=='NONE'))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_PUBLIC_VOICE_REQUIRED',422);
    return ['voice_id'=>$id,'name'=>vp3_profile_speech_label_pa4a($voice['name']??null),'style'=>vp3_profile_speech_style_pa4a($voice['labels']['gender']??null),'provenance'=>'admin_public','public_owner_id'=>$sharing['public_owner_id'],'original_voice_id'=>$sharing['original_voice_id'],'origin_hash'=>vp3_profile_speech_hash_pa4a(['admin_public',$id,$sharing['public_owner_id'],$sharing['original_voice_id']])];
}
function vp3_profile_speech_preset_key_pa4a(array $voice): string
{return vp3_profile_speech_hash_pa4a(['profile-visitor-preset-v1',$voice['voice_id'],$voice['origin_hash']]);}

function vp3_profile_speech_private_root_pa4a(bool $create=false): string
{
    $private=STONEFELLOW_ROOT.'/private';$root=$private.'/profile-speech-pa4a';
    if($create){
        if(is_link($private)||is_link($root))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_STORAGE_UNAVAILABLE');
        if(!is_dir($root)&&!@mkdir($root,0700,true)&&!is_dir($root))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_STORAGE_UNAVAILABLE');
        if(realpath($root)!==realpath($private).DIRECTORY_SEPARATOR.'profile-speech-pa4a')vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_STORAGE_UNAVAILABLE');
        @chmod($root,0700);
        if(!is_file($root.'/.htaccess')&&file_put_contents($root.'/.htaccess',"Require all denied\nDeny from all\n")===false)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_STORAGE_UNAVAILABLE');
        if(!is_file($root.'/index.php')&&file_put_contents($root.'/index.php',"<?php http_response_code(404); exit;\n")===false)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_STORAGE_UNAVAILABLE');
    }
    return $root;
}
function vp3_profile_speech_catalog_pa4a(?callable $transport=null): array
{
    if(!function_exists('agent_voice_default_catalog'))require_once __DIR__.'/agent-voice-preferences.php';
    $config=vp3_profile_speech_configuration_pa4a();$empty=['ready'=>false,'reason'=>'voice_unavailable','config_fingerprint'=>$config['config_fingerprint'],'catalog_revision'=>'','voices'=>[]];
    if(!$config['enabled'])return array_replace($empty,['reason'=>'disabled']);if($config['api_key']==='')return array_replace($empty,['reason'=>'credential_unavailable']);
    $cache=vp3_profile_speech_private_root_pa4a().'/catalog-'.$config['config_fingerprint'].'.json';
    if(!$transport&&!is_link($cache)&&is_file($cache)&&filesize($cache)<=524288){
        $saved=json_decode((string)file_get_contents($cache),true);
        if(is_array($saved)&&($saved['config_fingerprint']??null)===$config['config_fingerprint']&&is_int($saved['expires_at_ms']??null)&&$saved['expires_at_ms']>vp3_profile_speech_provider_now_pa4a()&&is_array($saved['catalog']['voices']??null)&&count($saved['catalog']['voices'])<=1008)return $saved['catalog'];
    }
    $voices=[];$defaults=[];$lookupFailed=false;
    try{$defaults=agent_voice_default_catalog(static function(string $url,array $headers,int $timeout)use($config,$transport):array{
        $prefix='https://api.elevenlabs.io';if(!str_starts_with($url,$prefix.'/v2/voices?'))vp3_profile_speech_provider_error_pa4a();
        $r=vp3_profile_speech_http_pa4a('GET',substr($url,strlen($prefix)),$config,null,$transport);return ['status'=>$r['complete']?$r['status']:0,'body'=>$r['body']];
    });}catch(Throwable $e){$lookupFailed=true;}
    foreach($defaults as $voice){
        $row=['voice_id'=>$voice['voice_id'],'name'=>vp3_profile_speech_label_pa4a($voice['name']),'style'=>vp3_profile_speech_style_pa4a($voice['gender']??null),'provenance'=>'provider_default','public_owner_id'=>'','original_voice_id'=>'','origin_hash'=>vp3_profile_speech_hash_pa4a(['provider_default',$voice['voice_id']])];$row['preset_key']=vp3_profile_speech_preset_key_pa4a($row);$voices[$row['voice_id']]=$row;
    }
    foreach($config['presets'] as $saved){
        if(!is_array($saved)||($saved['credential_fingerprint']??null)!==$config['credential_fingerprint'])continue;
        try{$id=vp3_profile_speech_voice_id_pa4a($saved['voice_id']??null);$voice=vp3_profile_speech_public_voice_pa4a(vp3_profile_speech_json_response_pa4a(vp3_profile_speech_http_pa4a('GET','/v1/voices/'.rawurlencode($id),$config,null,$transport)),$id);
            if(($saved['origin_hash']??null)!==$voice['origin_hash'])continue;$voice['name']=vp3_profile_speech_label_pa4a($saved['label']??$voice['name']);$voice['preset_key']=vp3_profile_speech_preset_key_pa4a($voice);$voices[$id]=$voice;
        }catch(VP3ProfileSpeechProviderException $error){if($error->publicCode==='PROFILE_SPEECH_CATALOG_UNAVAILABLE')$lookupFailed=true;continue;}
    }
    if(vp3_profile_speech_config_pa4a()['config_fingerprint']!==$config['config_fingerprint'])vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CONFIGURATION_CHANGED',409);
    $voices=array_values($voices);usort($voices,static fn($a,$b)=>strcmp($a['preset_key'],$b['preset_key']));
    $out=['ready'=>(bool)$voices,'reason'=>$voices?'ready':($lookupFailed?'catalog_unavailable':'voice_unavailable'),'config_fingerprint'=>$config['config_fingerprint'],'catalog_revision'=>vp3_profile_speech_hash_pa4a($voices),'voices'=>$voices];
    if(!$transport){$root=vp3_profile_speech_private_root_pa4a(true);$tmp=$root.'/catalog-'.bin2hex(random_bytes(16)).'.tmp';$raw=json_encode(['config_fingerprint'=>$config['config_fingerprint'],'expires_at_ms'=>vp3_profile_speech_provider_now_pa4a()+($voices?300000:30000),'catalog'=>$out],JSON_THROW_ON_ERROR);if(strlen($raw)<=524288&&file_put_contents($tmp,$raw,LOCK_EX)!==false){@chmod($tmp,0600);if(!@rename($tmp,$cache))@unlink($tmp);}}
    return $out;
}

function vp3_profile_speech_table_pa4a(PDO $pdo,string $name): bool
{
    $q=$pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?":'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$name]);return(bool)$q->fetchColumn();
}
/** Exact existing owner/self binding only. No Studio profile creation or provider call. */
function vp3_profile_speech_owner_voice_pa4a(PDO $pdo,array $user,int $chatAgentId,int $profileAgentId): array
{
    $uid=(int)($user['id']??0);if($uid<1)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',403);
    $q=$pdo->prepare('SELECT id,is_active FROM users WHERE id=?');$q->execute([$uid]);$live=$q->fetch(PDO::FETCH_ASSOC);if(!$live||!in_array($live['is_active'],[1,'1',true],true))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',403);
    $settings=vp3_profile_speech_settings_pa4a($pdo);
    // This is the existing owner narrator fallback, never a visitor preset.
    $global=trim((string)(getenv('ELEVENLABS_VOICE_ID')?:($settings['ai_elevenlabs_voice_id']??'JBFqnCBsd6RMkjVDRZzb')));
    $voice=$global;$name='Agent voice';$excluded=[];
    if(preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$global))$excluded[]=$global;
    if(vp3_profile_speech_table_pa4a($pdo,'user_agent_voice_preferences')){
        $q=$pdo->prepare('SELECT system_voice_id,system_voice_name FROM user_agent_voice_preferences WHERE user_id=?');$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
        if($rows&&is_string($rows[0]['system_voice_id'])&&$rows[0]['system_voice_id']!==''){$voice=vp3_profile_speech_voice_id_pa4a($rows[0]['system_voice_id']);$excluded[]=$voice;$name=vp3_profile_speech_label_pa4a($rows[0]['system_voice_name'],'Agent voice');}
    }
    $cloneRequested=false;$cloneNeeded=false;
    foreach(array_values(array_unique([$chatAgentId,$profileAgentId])) as $aid){
        if($aid<1)continue;$q=$pdo->prepare('SELECT owner_user_id,is_active,voice_enabled FROM user_agents WHERE id=?');$q->execute([$aid]);$agent=$q->fetch(PDO::FETCH_ASSOC);
        if(!$agent||(int)$agent['owner_user_id']!==$uid||!in_array($agent['is_active'],[1,'1',true],true))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',403);
        if(in_array($agent['voice_enabled'],[1,'1',true],true)){$cloneNeeded=true;if($aid===$chatAgentId)$cloneRequested=true;}
    }
    $clone=null;
    if($cloneNeeded&&vp3_profile_speech_table_pa4a($pdo,'studio_participants')&&vp3_profile_speech_table_pa4a($pdo,'studio_participant_voices')){
        $q=$pdo->prepare("SELECT p.id participant_id,p.cloning_consent,v.clone_provider_voice_id,v.clone_enabled,v.clone_verified,v.consent_snapshot_at,v.revoked_at FROM studio_participants p INNER JOIN studio_participant_voices v ON v.participant_id=p.id AND v.owner_user_id=p.owner_user_id AND v.provider='elevenlabs' WHERE p.owner_user_id=? AND p.linked_user_id=? AND p.relationship_scope='self' LIMIT 2");$q->execute([$uid,$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',503);
        if($rows){$row=$rows[0];$id=is_string($row['clone_provider_voice_id'])?$row['clone_provider_voice_id']:'';
            if(preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$id)){
                $excluded[]=$id;
                if(in_array($row['cloning_consent'],[1,'1',true],true)&&in_array($row['clone_enabled'],[1,'1',true],true)&&in_array($row['clone_verified'],[1,'1',true],true)&&$row['revoked_at']===null&&is_string($row['consent_snapshot_at'])&&$row['consent_snapshot_at']!=='')$clone=['voice_id'=>$id,'name'=>'Owner Agent voice','provenance'=>'owner_clone','origin_hash'=>vp3_profile_speech_hash_pa4a(['owner_clone',$uid,(int)$row['participant_id'],$id,$row['consent_snapshot_at']])];
            }
        }
    }
    if($cloneRequested)$narrator=$clone;
    else $narrator=preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$voice)?['voice_id'=>$voice,'name'=>$name,'provenance'=>'owner_default','origin_hash'=>vp3_profile_speech_hash_pa4a(['owner_default',$uid,$chatAgentId,$voice])]:null;
    return ['ready'=>$narrator!==null,'reason'=>$narrator?'ready':'owner_voice_unavailable','narrator'=>$narrator,'excluded_voice_ids'=>array_values(array_unique($excluded))];
}

/** Validate one selected current voice; catalogue metadata never grants a private clone. */
function vp3_profile_speech_selected_voice_pa4a(array $request,array $config,?callable $transport): void
{
    $id=vp3_profile_speech_voice_id_pa4a($request['voice_id']??null);$provenance=$request['provenance']??null;$origin=$request['origin_hash']??null;
    if(!is_string($origin)||!preg_match('/^[a-f0-9]{64}$/D',$origin)||!in_array($provenance,['provider_default','admin_public','owner_default','owner_clone'],true))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_INVALID',422);
    if(str_starts_with($provenance,'owner_')){
        $attempt=$request['attempt']??[];$owner=(int)($request['owner_user_id']??$attempt['owner_user_id']??0);$chat=(int)($request['chat_agent_id']??$attempt['chat_agent_id']??0);$profile=(int)($request['profile_agent_id']??$attempt['profile_agent_id']??0);
        $pdo=function_exists('db')?db():null;if(!$pdo||$owner<1)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',403);
        $current=vp3_profile_speech_owner_voice_pa4a($pdo,['id'=>$owner],$chat,$profile)['narrator'];
        if(!$current||$current['voice_id']!==$id||$current['provenance']!==$provenance||!hash_equals($current['origin_hash'],$origin))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);
    }
    $metadata=vp3_profile_speech_json_response_pa4a(vp3_profile_speech_http_pa4a('GET','/v1/voices/'.rawurlencode($id),$config,null,$transport));
    if(($metadata['voice_id']??null)!==$id||($metadata['is_legacy']??false)!==false||(isset($metadata['safety_control'])&&$metadata['safety_control']!=='NONE'))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);
    if($provenance==='owner_clone'){
        if(($metadata['is_owner']??null)!==true||!in_array($metadata['category']??null,['cloned','professional'],true))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);return;
    }
    $catalog=vp3_profile_speech_catalog_pa4a($transport);$selected=null;
    foreach($catalog['voices'] as $voice)if($voice['voice_id']===$id){$selected=$voice;break;}
    if(!$selected)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);
    if($provenance!=='owner_default'&&($selected['provenance']!==$provenance||!hash_equals($selected['origin_hash'],$origin)))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);
    if($selected['provenance']==='admin_public'){
        $proof=vp3_profile_speech_public_voice_pa4a($metadata,$id);if(!hash_equals($selected['origin_hash'],$proof['origin_hash']))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);
    }elseif(($metadata['is_owner']??false)===true||in_array($metadata['category']??null,['cloned','generated'],true))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);
    if(vp3_profile_speech_style_pa4a($metadata['labels']['gender']??null)!==$selected['style'])vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_VOICE_UNAVAILABLE',409);
}

/** Complete layer-III frames, not a MIME claim or a damaged ID3 prefix. */
function vp3_profile_speech_mp3_pa4a(string $audio): bool
{
    $size=strlen($audio);if($size<48||$size>VP3_PROFILE_SPEECH_AUDIO_MAX_PA4A)return false;$offset=0;$frames=0;
    if(substr($audio,0,3)==='ID3'){
        if($size<10||!in_array(ord($audio[3]),[2,3,4],true))return false;$tag=0;
        for($i=6;$i<10;$i++){if(ord($audio[$i])>127)return false;$tag=($tag<<7)|ord($audio[$i]);}
        $offset=10+$tag+((ord($audio[3])===4&&(ord($audio[5])&16))?10:0);if($offset>$size-8)return false;
    }
    $rates=[0,32,40,48,56,64,80,96,112,128,160,192,224,256,320];$small=[0,8,16,24,32,40,48,56,64,80,96,112,128,144,160];$samples=[44100,48000,32000];
    while($offset<$size){
        if($size-$offset===128&&substr($audio,$offset,3)==='TAG'){$offset=$size;break;}
        if($size-$offset<4||ord($audio[$offset])!==255||(ord($audio[$offset+1])&224)!==224)return false;
        $b1=ord($audio[$offset+1]);$b2=ord($audio[$offset+2]);$version=($b1>>3)&3;$layer=($b1>>1)&3;$rateIndex=$b2>>4;$sampleIndex=($b2>>2)&3;
        if($version===1||$layer!==1||$rateIndex===0||$rateIndex===15||$sampleIndex===3)return false;
        $sample=(int)($samples[$sampleIndex]/($version===3?1:($version===2?2:4)));$rate=($version===3?$rates:$small)[$rateIndex];
        $length=(int)floor(($version===3?144:72)*$rate*1000/$sample)+(($b2>>1)&1);if($length<24||$offset+$length>$size)return false;
        $offset+=$length;if(++$frames>50000)return false;
    }
    return $offset===$size&&$frames>=2;
}
function vp3_profile_speech_usage_pa4a(array $headers): array
{
    $lower=[];foreach($headers as $key=>$value)if(is_string($key)&&is_string($value))$lower[strtolower($key)]=trim($value);
    $cost=$lower['character-cost']??null;if(!is_string($cost)||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D',$cost))$cost=null;
    $id=static function(mixed $value):?string{return is_string($value)&&preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$value)?$value:null;};
    return ['provider_character_cost'=>$cost,'provider_request_id'=>$id($lower['request-id']??null),'provider_trace_id'=>$id($lower['x-trace-id']??null)];
}
/** Core must persist sending before calling this function. Never retry inside it. */
function vp3_profile_speech_synthesize_pa4a(array $request,?callable $transport=null,?callable $beforeSend=null): array
{
    $text=$request['text']??null;$id=$request['attempt_id']??null;
    if(!is_int($id)||$id<1||!is_string($text)||!mb_check_encoding($text,'UTF-8')||trim($text)===''||mb_strlen($text)>2000||str_contains($text,"\0")||($request['input_characters']??null)!==mb_strlen($text))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_INVALID',422);
    $result=['status'=>'blocked','reason'=>'provider_unavailable','provider_attempted'=>false,'attempt_id'=>$id,'config_fingerprint'=>(string)($request['config_fingerprint']??''),'input_characters'=>mb_strlen($text),'provider_character_cost'=>null,'provider_request_id'=>null,'provider_trace_id'=>null,'audio_key'=>null,'audio_bytes'=>0,'audio_sha256'=>null,'audio_expires_at_ms'=>null,'content_type'=>'audio/mpeg'];
    try{
        $config=vp3_profile_speech_configuration_pa4a();
        if(!$config['enabled']||$config['api_key']==='')return $result;
        if(($request['config_fingerprint']??null)!==$config['config_fingerprint']||($request['model_id']??null)!==$config['model_id']||($request['output_format']??null)!==$config['output_format'])return array_replace($result,['reason'=>'configuration_changed']);
        vp3_profile_speech_selected_voice_pa4a($request,$config,$transport);
        if(vp3_profile_speech_config_pa4a()['config_fingerprint']!==$config['config_fingerprint'])return array_replace($result,['reason'=>'configuration_changed']);
        vp3_profile_speech_private_root_pa4a(true);
        if($beforeSend)$beforeSend();
        if(vp3_profile_speech_config_pa4a()['config_fingerprint']!==$config['config_fingerprint'])return array_replace($result,['reason'=>'configuration_changed']);
    }catch(Throwable){return array_replace($result,['reason'=>'voice_unavailable']);}
    $result['provider_attempted']=true;$result['status']='outcome_unverified';$result['reason']='provider_outcome_unverified';
    $response=vp3_profile_speech_http_pa4a('POST','/v1/text-to-speech/'.rawurlencode($request['voice_id']).'/stream?output_format='.$config['output_format'],$config,['text'=>$text,'model_id'=>$config['model_id']],$transport);
    $result=array_replace($result,vp3_profile_speech_usage_pa4a($response['headers']));
    if($response['complete']&&$response['status']>=400&&$response['status']<500)return array_replace($result,['status'=>'rejected','reason'=>$response['status']===429?'provider_rate_limited':(in_array($response['status'],[401,403],true)?'provider_access_denied':'provider_request_rejected')]);
    $type='';foreach($response['headers'] as $key=>$value)if(is_string($key)&&strtolower($key)==='content-type'&&is_string($value))$type=strtolower(trim(explode(';',$value)[0]));
    if(!$response['complete']||$response['status']!==200||$type!=='audio/mpeg'||!vp3_profile_speech_mp3_pa4a($response['body']))return $result;
    $audioKey=bin2hex(random_bytes(32));$root=vp3_profile_speech_private_root_pa4a(true);$tmp=$root.'/audio-'.$audioKey.'.tmp';$path=$root.'/audio-'.$audioKey.'.mp3';$audio=$response['body'];
    try{if(file_put_contents($tmp,$audio,LOCK_EX)!==strlen($audio)||!@chmod($tmp,0600)||!@rename($tmp,$path))throw new RuntimeException('audio storage');}
    catch(Throwable){@unlink($tmp);return array_replace($result,['reason'=>'audio_storage_unverified']);}
    return array_replace($result,['status'=>'ready','reason'=>'ready','audio_key'=>$audioKey,'audio_bytes'=>strlen($audio),'audio_sha256'=>hash('sha256',$audio),'audio_expires_at_ms'=>vp3_profile_speech_provider_now_pa4a()+VP3_PROFILE_SPEECH_AUDIO_TTL_PA4A]);
}

/** Called only with a core-authorized attempt. A cache miss never synthesizes. */
function vp3_profile_speech_audio_read_pa4a(array $attempt): string
{
    $expiry=$attempt['audio_expires_at_ms']??null;$key=$attempt['audio_key']??null;
    if(!is_numeric($expiry)||(int)$expiry<=vp3_profile_speech_provider_now_pa4a()||$key===null||!empty($attempt['audio_removed_at_ms']))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_AUDIO_EXPIRED',409);
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_string($attempt['audio_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$attempt['audio_sha256'])||!is_numeric($attempt['audio_bytes']??null)||(int)$attempt['audio_bytes']<48||(int)$attempt['audio_bytes']>VP3_PROFILE_SPEECH_AUDIO_MAX_PA4A)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_AUDIO_UNAVAILABLE',409);
    $root=vp3_profile_speech_private_root_pa4a();$path=$root.'/audio-'.$key.'.mp3';
    if(is_link($root)||is_link($path)||realpath($root)!==realpath(STONEFELLOW_ROOT.'/private').DIRECTORY_SEPARATOR.'profile-speech-pa4a'||!is_file($path)||filesize($path)!==(int)$attempt['audio_bytes'])vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_AUDIO_UNAVAILABLE',409);
    $audio=file_get_contents($path,false,null,0,VP3_PROFILE_SPEECH_AUDIO_MAX_PA4A+1);
    if(!is_string($audio)||strlen($audio)!==(int)$attempt['audio_bytes']||!hash_equals($attempt['audio_sha256'],hash('sha256',$audio))||!vp3_profile_speech_mp3_pa4a($audio))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_AUDIO_UNAVAILABLE',409);return $audio;
}
/** Bounded private retention cleanup; no provider request or attempt reset. */
function vp3_profile_speech_audio_cleanup_pa4a(PDO $pdo,int $limit=20): array
{
    $limit=max(1,min(100,$limit));$now=vp3_profile_speech_provider_now_pa4a();$removed=0;$orphans=0;$root=vp3_profile_speech_private_root_pa4a();
    if(is_link($root))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_STORAGE_UNAVAILABLE');
    if(vp3_profile_speech_table_pa4a($pdo,'profile_speech_attempts_pa4a')){
        $q=$pdo->prepare('SELECT id,audio_key,audio_expires_at_ms FROM profile_speech_attempts_pa4a WHERE audio_removed_at_ms IS NULL AND audio_expires_at_ms IS NOT NULL AND audio_expires_at_ms<=? ORDER BY audio_expires_at_ms,id LIMIT '.$limit);$q->execute([$now]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $key=$row['audio_key'];if($key!==null&&(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)))continue;$path=$key===null?'':$root.'/audio-'.$key.'.mp3';
            if($path!==''&&is_link($path))continue;if($path!==''&&is_file($path)&&!@unlink($path))continue;
            $update=$pdo->prepare('UPDATE profile_speech_attempts_pa4a SET audio_key=NULL,audio_removed_at_ms=? WHERE id=? AND audio_removed_at_ms IS NULL AND audio_expires_at_ms<=?');$update->execute([$now,(int)$row['id'],$now]);$removed+=$update->rowCount();
        }
    }
    // Incomplete writes and artifacts whose DB commit was lost cannot live forever.
    if(is_dir($root)){$examined=0;$cutoff=time()-1200;foreach(new DirectoryIterator($root) as $file){
        if(++$examined>$limit*10)break;if($file->isDot()||$file->isLink()||!$file->isFile()||$file->getMTime()>$cutoff)continue;
        if(!preg_match('/^(?:audio-[a-f0-9]{64}\.(?:mp3|tmp)|catalog-[a-f0-9]{32,64}\.(?:json|tmp))$/D',$file->getFilename()))continue;
        if(@unlink($file->getPathname()))$orphans++;if($orphans>=$limit)break;
    }}
    return ['removed'=>$removed,'orphan_files_removed'=>$orphans];
}

function vp3_profile_speech_admin_state_pa4a(): array
{
    $public=vp3_profile_speech_config_pa4a();if($public['config_fingerprint']==='')return $public+['presets'=>[],'settings_revision'=>''];
    $config=vp3_profile_speech_configuration_pa4a();$presets=[];
    foreach($config['presets'] as $row)if(is_array($row))$presets[]=['voice_id'=>is_string($row['voice_id']??null)?$row['voice_id']:'','label'=>vp3_profile_speech_label_pa4a($row['label']??null),'style'=>vp3_profile_speech_style_pa4a($row['style']??null),'verified_at_ms'=>(int)($row['verified_at_ms']??0),'credentials_current'=>($row['credential_fingerprint']??null)===$config['credential_fingerprint']];
    return vp3_profile_speech_config_pa4a()+['presets'=>$presets,'settings_revision'=>$config['config_fingerprint']];
}
/** Admin approval is explicit and tied to current public-origin proof. No imports. */
function vp3_profile_speech_admin_save_pa4a(PDO $pdo,array $input,?callable $transport=null,?callable $authorize=null): array
{
    $config=vp3_profile_speech_configuration_pa4a();$revision=$input['settings_revision']??null;
    if(!is_string($revision)||!hash_equals($config['config_fingerprint'],$revision))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CONFIGURATION_CHANGED',409);
    $entries=$input['presets']??[];if(!is_array($entries)||!array_is_list($entries)||count($entries)>8)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_INVALID',422);
    $saved=[];$seen=[];
    foreach($entries as $entry){
        if(!is_array($entry)||array_diff(array_keys($entry),['voice_id','label']))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_INVALID',422);
        if(($entry['voice_id']??'')==='')continue;$id=vp3_profile_speech_voice_id_pa4a($entry['voice_id']);if(isset($seen[$id]))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_INVALID',422);$seen[$id]=true;
        if($config['api_key']==='')vp3_profile_speech_provider_error_pa4a();
        $voice=vp3_profile_speech_public_voice_pa4a(vp3_profile_speech_json_response_pa4a(vp3_profile_speech_http_pa4a('GET','/v1/voices/'.rawurlencode($id),$config,null,$transport)),$id);
        $saved[]=['voice_id'=>$id,'label'=>vp3_profile_speech_label_pa4a($entry['label']??$voice['name']),'style'=>$voice['style'],'public_owner_id'=>$voice['public_owner_id'],'original_voice_id'=>$voice['original_voice_id'],'origin_hash'=>$voice['origin_hash'],'credential_fingerprint'=>$config['credential_fingerprint'],'verified_at_ms'=>vp3_profile_speech_provider_now_pa4a()];
    }
    if(vp3_profile_speech_config_pa4a()['config_fingerprint']!==$revision)vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_CONFIGURATION_CHANGED',409);
    if($authorize)$authorize();$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value':'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';
        $q=$pdo->prepare($sql);$q->execute(['profile_speech_public_presets_pa4a',json_encode($saved,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);$q->execute(['profile_speech_enabled_pa4a',in_array($input['enabled']??false,[true,1,'1'],true)?'1':'0']);if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_profile_speech_admin_state_pa4a();
}
function vp3_profile_speech_admin_disable_pa4a(PDO $pdo): void
{
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value':'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';$pdo->prepare($sql)->execute(['profile_speech_enabled_pa4a','0']);
}
