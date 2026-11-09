<?php
declare(strict_types=1);

// Real HTTP requests execute unchanged production page/API copies. Only the
// unrelated application bootstrap, chrome and SQL dialect are fixture adapters.
$root=sys_get_temp_dir().'/vp3-recovery-page-'.bin2hex(random_bytes(6));
foreach(['admin','api','includes','private','sessions'] as $dir)mkdir($root.'/'.$dir,0700,true);
define('STONEFELLOW_ROOT',$root);
$repo=dirname(__DIR__);
require $repo.'/includes/ai-settings.php';
$pdo=new PDO('sqlite:'.$root.'/fixture.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE subscription_packages(id INTEGER PRIMARY KEY,name TEXT NOT NULL);
 CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT NOT NULL);
 CREATE TABLE user_llm_credentials(user_id INT,provider TEXT,model TEXT,encrypted_key TEXT,PRIMARY KEY(user_id,provider));
 CREATE TABLE user_llm_preferences(user_id INT PRIMARY KEY,route TEXT,provider TEXT);
 INSERT INTO subscription_packages VALUES (9,'Fixture Package');
 INSERT INTO users VALUES (10,'Fixture Member'),(11,'Other Member');");
$keys=['ai_anthropic_api_key'=>'fixture-claude-reconnected','ai_elevenlabs_api_key'=>'fixture-eleven-reconnected'];
$old=random_bytes(32);
$insert=$pdo->prepare('INSERT INTO settings VALUES(?,?)');
foreach($keys as $name=>$key)$insert->execute([$name,ai_encrypt_secret_with_key($key,$old)]);
foreach(['system_agent_name'=>'Original Agent','ai_active_provider'=>'anthropic','ai_anthropic_enabled'=>'1','ai_anthropic_model'=>'claude-haiku-4-5','ai_openai_model'=>'gpt-5.4-mini','ai_elevenlabs_voice_id'=>'original-voice','ai_elevenlabs_model_id'=>'eleven_flash_v2_5'] as $name=>$value)$insert->execute([$name,$value]);
$before=$pdo->query('SELECT * FROM settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC);
foreach ([
 'admin/ai.php', 'admin/ai-service-rules.php', 'admin/_ai-service-controls-v1.php', 'api/admin-elevenlabs-v102.php',
 'includes/ai-credential-recovery.php', 'includes/agent-image-provider-v1.php', 'includes/ai-service-policy-v1.php',
 'includes/ai-service-model-test-v1.php', 'includes/ai-runtime-v100.php', 'includes/ai-service-usage-v1.php',
 'includes/ai-settings.php', 'includes/user-llm-v1.php', 'includes/ai-service-own-key-v1.php',
 'api/user-llm-v1.php', 'settings-ai-providers.php',
] as $file) copy($repo.'/'.$file,$root.'/'.$file);
$bootstrap= <<<'PHP'
<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT',dirname(__DIR__));
ini_set('session.save_path',STONEFELLOW_ROOT.'/sessions');session_start();
$config=['site'=>['base_path'=>'']];
class FixturePDO extends PDO {
 public function prepare(string $query,array $options=[]): PDOStatement|false {
  $query=str_replace('ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)','ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value',$query);
  return parent::prepare($query,$options);
 }
}
function db():PDO {static $pdo;return $pdo??=new FixturePDO('sqlite:'.STONEFELLOW_ROOT.'/fixture.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function current_user():array{
 return ['id'=>match($_SERVER['HTTP_X_TEST_ROLE']??'admin'){'member'=>10,'other_member'=>11,default=>7}];
}
function has_permission(string $permission,?array $user=null):bool{
 $role=$_SERVER['HTTP_X_TEST_ROLE']??'admin';
 return $role==='admin'||(in_array($role,['member','other_member'],true)&&$permission==='account.access')||($role==='ai_only'&&$permission==='ai.manage')||($role==='users_only'&&$permission==='users.manage');
}
function require_permission(string $permission):void{if(!has_permission($permission)){http_response_code(403);exit;}}
function seed_permission_catalog():void{}
function system_agent_name():string{return (string)setting('system_agent_name','Agent');}
function vp3_cloud_hosting_cpanel_public_state_v100():array{return ['configured'=>false];}
function vp3_cloud_hosting_normalize_cpanel_server_v100(string $s):string{return trim($s);}
function vp3_cloud_hosting_v110_normalize_domain(string $s,string $label):string{return trim($s);}
PHP;
$bootstrap.="\nrequire ".var_export($repo.'/includes/functions.php',true).";\nrequire __DIR__.'/ai-settings.php';\n";
$bootstrap.="require __DIR__.'/ai-runtime-v100.php';\n";
file_put_contents($root.'/includes/bootstrap.php',$bootstrap);
file_put_contents($root.'/admin/_header.php','<!doctype html><html><body><?php foreach(["notice","error"] as $type){$message=flash($type);if($message)echo "<p>".e($message)."</p>";} ?>');
file_put_contents($root.'/admin/_footer.php','</body></html>');
file_put_contents($root.'/includes/workspace-sidebar-v82.php','');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
if(!$socket)throw new RuntimeException('Cannot allocate HTTP fixture port.');
$address=stream_socket_get_name($socket,false);fclose($socket);
$command=[PHP_BINARY];if(php_ini_loaded_file())array_push($command,'-c',php_ini_loaded_file());
array_push($command,'-S',$address,'-t',$root);
$log=$root.'/server.log';$process=proc_open($command,[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);fclose($pipes[0]);
$cookie='PHPSESSID=vp3recovery'.bin2hex(random_bytes(6));$checks=0;
function check(bool $condition,string $message):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
function request(string $path,?array $body=null,bool $json=false,string $role='admin'):array{
 global $address,$cookie;
 $headers=['Cookie: '.$cookie,'X-Test-Role: '.$role];
 $options=['method'=>$body===null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>10];
 if($body!==null){$headers[]='Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded');$options['content']=$json?json_encode($body):http_build_query($body);}
 $options['header']=implode("\r\n",$headers);
 $result=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>$options]));
 return [(int)explode(' ',$http_response_header[0])[1],(string)$result,$http_response_header];
}
function rows():array{global $pdo;return $pdo->query('SELECT * FROM settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC);}
try{
 for($i=0;$i<50;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,.1);if($ready){fclose($ready);break;}usleep(100000);}
 [$status,$html]=request('/admin/ai.php');
 check($status===200,'Recovery page renders over HTTP');
 check(str_contains($html,'<h3>Reconnect saved services</h3>'),'Primary recovery form visible without opening a details control');
 check(str_contains($html,'recovery_keys[ai_anthropic_api_key]')&&str_contains($html,'recovery_keys[ai_elevenlabs_api_key]'),'One form includes Claude and ElevenLabs');
 check(!str_contains($html,'ai-overview-panel')&&!str_contains($html,'Save AI / API Settings'),'Conflicting normal save controls not rendered');
 check(!str_contains($html,'Restore /private/ai-key.php from the previous installation'),'Missing-key page does not repeat the file-hunting warning');
 preg_match('/name="csrf_token" value="([^"]+)"/',$html,$match);$csrf=$match[1]??'';check($csrf!=='','Production CSRF field present');
 preg_match('/<button\b[^>]*>Test Claude<\/button>/',$html,$testButton);
 check(isset($testButton[0])&&!str_contains($testButton[0],'disabled'),'Claude test stays usable when its credential cannot be decrypted');
 check(str_contains($html,'Test Cloud Chat')&&str_contains($html,'Test ElevenLabs'),'Chat and ElevenLabs tests stay visible during recovery');
 foreach(['anthropic','chat','elevenlabs'] as $provider){
  [$status,,$headers]=request('/admin/ai.php',['action'=>'test','provider'=>$provider,'csrf_token'=>$csrf]);
  check($status===302&&in_array('Location: /admin/ai.php#connection-tests',$headers,true),'Connection test returns to its result section');
  [$status,$html]=request('/admin/ai.php');
  check($status===200&&str_contains($html,'credential_missing_key'),'Connection test reports the actual missing-key code');
  check(rows()===$before&&!is_file($root.'/private/ai-key.php'),'Connection test never replaces keys or changes settings');
 }
 [$status]=request('/admin/ai.php',['action'=>'test','provider'=>'chat','csrf_token'=>$csrf],false,'viewer');
 check($status===403,'Connection tests retain administrator permission checks');
 [$status]=request('/admin/ai.php',['action'=>'test','provider'=>'chat','csrf_token'=>'invalid']);
 check($status===302&&rows()===$before,'Connection tests reject invalid CSRF without writes');
 request('/admin/ai.php');
 [$status,,$headers]=request('/admin/ai.php',['action'=>'save','csrf_token'=>$csrf,'system_agent_name'=>'Wrong Agent','active_provider'=>'openai','anthropic_api_key'=>'fixture-new-key']);
 check($status===302&&in_array('Location: /admin/ai.php#credential-recovery',$headers,true),'Stale normal save redirects to recovery');
 check(rows()===$before&&!is_file($root.'/private/ai-key.php'),'Stale save leaves credentials and selections unchanged');
 request('/admin/ai.php');
 [$status,$json]=request('/api/admin-elevenlabs-v102.php',['csrf_token'=>$csrf,'api_key'=>'fixture-new-voice-key','voice_id'=>'different-voice','model_id'=>'eleven_flash_v2'],true);
 check($status===422&&str_contains($json,'Reconnect the saved services'),'Stale ElevenLabs save directs to recovery');
 check(rows()===$before,'Stale ElevenLabs save cannot partially change voice settings');
 $reconnect=['action'=>'reconnect_credentials','csrf_token'=>$csrf,'confirm_reconnection'=>'1','recovery_keys'=>$keys];
 [$status]=request('/admin/ai.php',$reconnect,false,'viewer');check($status===403&&rows()===$before,'Non-admin recovery forbidden without writes');
 $invalid=$reconnect;$invalid['csrf_token']='invalid';[$status]=request('/admin/ai.php',$invalid);check($status===302&&rows()===$before&&!is_file($root.'/private/ai-key.php'),'Invalid CSRF recovery rejected without writes');
 request('/admin/ai.php');
 [$status]=request('/admin/ai.php',$reconnect);check($status===302&&is_file($root.'/private/ai-key.php'),'Real recovery POST creates installation key');
 foreach($keys as $name=>$value){$stmt=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$stmt->execute([$name]);$cipher=(string)$stmt->fetchColumn();$stmt->closeCursor();check(ai_decrypt_secret($cipher)===$value,'Reconnected credential readable: '.$name);}
 [$status,$html]=request('/admin/ai.php');
 check($status===200&&str_contains($html,'ai-overview-panel')&&str_contains($html,'Save AI / API Settings'),'Normal settings return after successful recovery');
 check(str_contains($html,'name="image_model"')&&str_contains($html,'name="image_enabled"'),'Existing admin settings expose separate image controls');
 preg_match('/<button\b[^>]*>Test Claude<\/button>/',$html,$testButton);
 check(isset($testButton[0])&&!str_contains($testButton[0],'disabled'),'Claude connection-test button becomes enabled after reconnection');
 check(!str_contains($html,'id="credential-recovery"'),'Recovery form no longer displayed once unlocked');
 [$status]=request('/admin/ai.php',['action'=>'save','csrf_token'=>$csrf,'system_agent_name'=>'Updated Agent','active_provider'=>'anthropic','anthropic_enabled'=>'1','anthropic_model'=>'claude-haiku-4-5','openai_model'=>'gpt-5.4-mini','image_enabled'=>'1','image_model'=>'gpt-image-2.5-flare']);
 check($status===302,'Ordinary save works after recovery');
 check($pdo->query("SELECT setting_value FROM settings WHERE setting_key='system_agent_name'")->fetchColumn()==='Updated Agent','Normal settings persist after recovery');
 check($pdo->query("SELECT setting_value FROM settings WHERE setting_key='ai_image_model'")->fetchColumn()==='gpt-image-2.5-flare'&&$pdo->query("SELECT setting_value FROM settings WHERE setting_key='ai_image_enabled'")->fetchColumn()==='1','Image options persist through the existing admin save');
 foreach($keys as $name=>$value){$stmt=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$stmt->execute([$name]);$cipher=(string)$stmt->fetchColumn();$stmt->closeCursor();check(ai_decrypt_secret($cipher)===$value,'Saving image options preserves existing encrypted credentials: '.$name);}
 [$status,$json]=request('/api/admin-elevenlabs-v102.php',['csrf_token'=>$csrf,'api_key'=>'','voice_id'=>'updated-voice','model_id'=>'eleven_flash_v2_5'],true);
 check($status===200&&!empty(json_decode($json,true)['ok']),'Normal ElevenLabs save works after recovery');
 check($pdo->query("SELECT setting_value FROM settings WHERE setting_key='ai_elevenlabs_voice_id'")->fetchColumn()==='updated-voice','Voice settings persist after recovery');

 $baseline=rows();
 $model=[
  'action'=>'save_provider_model','csrf_token'=>$csrf,'provider'=>'anthropic','model'=>'fixture-model:2026',
  'label'=>'Fixture <Model>','services'=>['conversation'],'anthropic_api_key'=>'must-not-be-saved',
 ];
 [$status]=request('/admin/ai.php',$model,false,'users_only');
 check($status===403&&rows()===$baseline,'Model changes require AI settings permission');
 [$status]=request('/admin/ai.php',array_replace($model,['csrf_token'=>'invalid']));
 check($status===302&&rows()===$baseline,'Model changes require CSRF');
 [$status,,$headers]=request('/admin/ai.php',$model);
 check($status===302&&in_array('Location: /admin/ai.php?models=anthropic#provider-models-anthropic',$headers,true),'Model save returns to its provider registry');
 [$status,$html]=request('/admin/ai.php?models=anthropic');
 check($status===200&&str_contains($html,'Fixture &lt;Model&gt;')&&str_contains($html,'fixture-model:2026')&&!str_contains($html,'Fixture <Model>'),'Custom model ID and escaped label appear in Admin');
 check(str_contains($html,'form="ai-model-save-anthropic"')&&str_contains($html,'form="ai-model-command-anthropic"'),'Registry controls have their own form owners');
 check(!str_contains($html,'name="service" value="code_build"')&&str_contains($html,'Planned services'),'Planned services appear without active route controls');
 check(array_values(array_filter(rows(),static fn(array $row):bool=>$row['setting_key']!=='vp3_ai_service_configuration_v1'))===$baseline,'Model registration leaves the saved key and defaults intact');

 $route=['action'=>'save_service_route','csrf_token'=>$csrf,'service'=>'conversation','primary_model'=>'anthropic:fixture-model:2026','primary_funding'=>'system'];
 [$status,,$headers]=request('/admin/ai.php',$route);
 check($status===302&&in_array('Location: /admin/ai.php?route=conversation#ai-service-route-conversation',$headers,true),'Conversation route save returns to its selected service');
 [, $html]=request('/admin/ai.php?route=conversation');
 check(str_contains($html,'value="anthropic:fixture-model:2026" selected'),'Route keeps the exact model ID including the colon');
 $afterRoute=rows();
 [$status]=request('/admin/ai.php',array_replace($route,['service'=>'initiative']));
 check($status===302&&rows()===$afterRoute,'A model can only be routed to its eligible services');
 [$status]=request('/admin/ai.php',array_replace($route,['service'=>'code_build']));
 check($status===302&&rows()===$afterRoute,'A direct POST cannot configure a planned service');
 [$status]=request('/admin/ai.php',[
  'action'=>'provider_model_command','csrf_token'=>$csrf,'provider'=>'anthropic','remove_model'=>'fixture-model:2026',
 ]);
 check($status===302&&rows()===$afterRoute,'Removing an in-use model cannot orphan a saved route');
 [$status]=request('/admin/ai.php',[
  'action'=>'provider_model_command','csrf_token'=>$csrf,'provider'=>'openai','test_model'=>'fixture-model:2026',
 ]);
 [, $html]=request('/admin/ai.php');
 check($status===302&&str_contains($html,'Save this model before testing it.'),'Model lookup rejects unknown model IDs before transport');

 $packagePath='/admin/ai-service-rules.php?scope=package&id=9&service=initiative';
 $userPath='/admin/ai-service-rules.php?scope=user&id=10&service=conversation';
 [$status,$html]=request($packagePath);
 check($status===200&&str_contains($html,'Fixture Package')&&str_contains($html,'Background tokens per day'),'Package Initiative editor shows the verified target and background budgets');
 [$status,$html]=request($userPath);
 check($status===200&&str_contains($html,'Fixture Member')&&!str_contains($html,'Background tokens per day'),'Account Conversation editor shows only its applicable settings');
 [$status]=request('/admin/ai-service-rules.php?scope=site',null,false,'ai_only');
 check($status===200,'Site rules require AI permission only');
 $rule=['csrf_token'=>$csrf,'enabled'=>'inherit','background_enabled'=>'block','model_rule'=>'selected','allowed_models'=>['anthropic:fixture-model:2026'],'funding'=>'system','daily_tokens'=>'0'];
 [$status]=request($userPath,$rule,false,'ai_only');
 check($status===403&&rows()===$afterRoute,'Account rules also require user-management permission');
 [$status]=request($packagePath,$rule,false,'users_only');
 check($status===403&&rows()===$afterRoute,'Package rules also require AI settings permission');
 [$status]=request($packagePath,array_replace($rule,['csrf_token'=>'invalid']));
 check($status===302&&rows()===$afterRoute,'Package rules require CSRF');
 [$status]=request($packagePath,$rule);
 check($status===302&&rows()===$afterRoute,'Rule form rejects a model outside the service inventory');
 $rule['allowed_models']=[];
 [$status]=request($packagePath,$rule);
 $saved=json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='vp3_ai_rule_v1_package_9_initiative'")->fetchColumn(),true);
 check($status===302&&($saved['rule']??null)===['background_enabled'=>false,'daily_tokens'=>0,'allowed_models'=>[],'allowed_funding'=>['system']]&&($saved['actor_id']??null)===7,'Rule POST preserves block, zero and empty allowlist while auditing the actor');
 [$status,$html]=request($packagePath);
 check($status===200&&str_contains($html,'AI service rules saved.')&&str_contains($html,'name="daily_tokens" min="0" max="1000000000" step="1" value="0"'),'Rule editor shows the saved zero cap');
 $rule['daily_tokens']='';
 $rule['background_enabled']='inherit';
 $rule['model_rule']='inherit';
 $rule['funding']='inherit';
 [$status]=request($packagePath,$rule);
 $saved=json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='vp3_ai_rule_v1_package_9_initiative'")->fetchColumn(),true);
 check($status===302&&($saved['rule']??null)===[],'Inherit clears service restrictions without granting wider access');
 $memberRoute=['action'=>'save_service_route','csrf_token'=>$csrf,'service'=>'initiative',
  'primary_model'=>'anthropic:claude-haiku-4-5','primary_funding'=>'own_key'];
 [$status]=request('/admin/ai.php',$memberRoute);
 check($status===302,'Admin can configure the exact personal Initiative route.');
 $memberKey='fixture-personal-key-for-initiative';
 $credential=$pdo->prepare('INSERT INTO user_llm_credentials VALUES (?,?,?,?)');
 $credential->execute([10,'anthropic','claude-haiku-4-5',ai_encrypt_secret($memberKey)]);
 $credential->execute([11,'anthropic','claude-haiku-4-5',ai_encrypt_secret('fixture-second-personal-key')]);
 $memberApi='/api/user-llm-v1.php';
 [$status,$html]=request('/settings-ai-providers.php',null,false,'member');
 check($status===200&&str_contains($html,'Personal key permissions by service'),'The member page renders the personal approvals control.');
 [$status,$json,$headers]=request($memberApi,null,false,'member');
 $memberState=json_decode($json,true)['state']??[];
 check($status===200&&in_array('Cache-Control: no-store, private',$headers,true)
    &&($memberState['service_permissions'][0]['can_approve']??null)===true
    &&($memberState['service_permissions'][0]['approved']??null)===false,'The current route is available for explicit approval in a private response.');
 $grant=['action'=>'grant_service','csrf_token'=>$csrf,'service'=>'initiative','provider'=>'anthropic','model'=>'claude-haiku-4-5'];
 [$status]=request($memberApi,$grant,true,'viewer');
 check($status===403,'An account without access cannot grant a personal key.');
 [$status]=request($memberApi,array_replace($grant,['csrf_token'=>'invalid']),true,'member');
 check($status===419,'The approval mutation requires CSRF.');
 [$status,$json]=request($memberApi,$grant,true,'member');
 $memberState=json_decode($json,true)['state']??[];
 check($status===200&&($memberState['service_permissions'][0]['approved']??null)===true
    &&($memberState['route']??null)==='system'&&!str_contains($json,$memberKey)
    &&!str_contains($json,'revision'),'Approval leaves Conversation funding independent and never returns grant credentials.');
 [$status,$json]=request($memberApi,null,false,'other_member');
 check($status===200&&(json_decode($json,true)['state']['service_permissions'][0]['approved']??null)===false,'A different member sees only their own approvals.');
 [$status,$json]=request($memberApi,array_replace($grant,['action'=>'revoke_service']),true,'member');
 check($status===200&&(json_decode($json,true)['state']['service_permissions'][0]['approved']??null)===false,'The exact service grant can be revoked.');
 $imageRoute=array_replace($memberRoute,['service'=>'image_generation','primary_model'=>'openai:gpt-image-1.5']);
 [$status]=request('/admin/ai.php',$imageRoute);
 check($status===302,'Admin can configure an existing image model for personal funding.');
 $credential->execute([10,'openai','gpt-5.4-mini',ai_encrypt_secret('fixture-personal-key-for-images')]);
 [$status,$json]=request($memberApi,null,false,'member');
 $routes=array_column(json_decode($json,true)['state']['service_permissions']??[] ,null,'service');
 check($status===200&&($routes['image_generation']['model']??null)==='gpt-image-1.5'
    &&($routes['image_generation']['can_approve']??null)===true,'The member API resolves the built-in image catalog separately from the saved Chat model.');
 echo 'AI_CREDENTIAL_RECOVERY_PAGE=PASS ('.$checks." assertions; actual production page/API over HTTP)\n";
}finally{
 proc_terminate($process);proc_close($process);
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
