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
$keys=['ai_anthropic_api_key'=>'fixture-claude-reconnected','ai_elevenlabs_api_key'=>'fixture-eleven-reconnected'];
$old=random_bytes(32);
$insert=$pdo->prepare('INSERT INTO settings VALUES(?,?)');
foreach($keys as $name=>$key)$insert->execute([$name,ai_encrypt_secret_with_key($key,$old)]);
foreach(['system_agent_name'=>'Original Agent','ai_active_provider'=>'anthropic','ai_anthropic_enabled'=>'1','ai_anthropic_model'=>'claude-haiku-4-5','ai_openai_model'=>'gpt-5.4-mini','ai_elevenlabs_voice_id'=>'original-voice','ai_elevenlabs_model_id'=>'eleven_flash_v2_5'] as $name=>$value)$insert->execute([$name,$value]);
$before=$pdo->query('SELECT * FROM settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC);
copy($repo.'/admin/ai.php',$root.'/admin/ai.php');
copy($repo.'/api/admin-elevenlabs-v102.php',$root.'/api/admin-elevenlabs-v102.php');
copy($repo.'/includes/ai-credential-recovery.php',$root.'/includes/ai-credential-recovery.php');
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
function current_user():array{return ['id'=>7];}
function has_permission(string $permission,?array $user=null):bool{return ($_SERVER['HTTP_X_TEST_ROLE']??'admin')==='admin';}
function require_permission(string $permission):void{if(!has_permission($permission)){http_response_code(403);exit;}}
function seed_permission_catalog():void{}
function system_agent_name():string{return (string)setting('system_agent_name','Agent');}
function vp3_cloud_hosting_cpanel_public_state_v100():array{return ['configured'=>false];}
function vp3_cloud_hosting_normalize_cpanel_server_v100(string $s):string{return trim($s);}
function vp3_cloud_hosting_v110_normalize_domain(string $s,string $label):string{return trim($s);}
PHP;
$bootstrap.="\nrequire ".var_export($repo.'/includes/functions.php',true).";\nrequire ".var_export($repo.'/includes/ai-settings.php',true).";\n";
file_put_contents($root.'/includes/bootstrap.php',$bootstrap);
file_put_contents($root.'/admin/_header.php','<!doctype html><html><body><?php foreach(["notice","error"] as $type){$message=flash($type);if($message)echo "<p>".e($message)."</p>";} ?>');
file_put_contents($root.'/admin/_footer.php','</body></html>');
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
 preg_match('/<button\b[^>]*>Test Claude<\/button>/',$html,$testButton);
 check(isset($testButton[0])&&!str_contains($testButton[0],'disabled'),'Claude connection-test button becomes enabled after reconnection');
 check(!str_contains($html,'id="credential-recovery"'),'Recovery form no longer displayed once unlocked');
 [$status]=request('/admin/ai.php',['action'=>'save','csrf_token'=>$csrf,'system_agent_name'=>'Updated Agent','active_provider'=>'anthropic','anthropic_enabled'=>'1','anthropic_model'=>'claude-haiku-4-5','openai_model'=>'gpt-5.4-mini']);
 check($status===302,'Ordinary save works after recovery');
 check($pdo->query("SELECT setting_value FROM settings WHERE setting_key='system_agent_name'")->fetchColumn()==='Updated Agent','Normal settings persist after recovery');
 [$status,$json]=request('/api/admin-elevenlabs-v102.php',['csrf_token'=>$csrf,'api_key'=>'','voice_id'=>'updated-voice','model_id'=>'eleven_flash_v2_5'],true);
 check($status===200&&!empty(json_decode($json,true)['ok']),'Normal ElevenLabs save works after recovery');
 check($pdo->query("SELECT setting_value FROM settings WHERE setting_key='ai_elevenlabs_voice_id'")->fetchColumn()==='updated-voice','Voice settings persist after recovery');
 echo 'AI_CREDENTIAL_RECOVERY_PAGE=PASS ('.$checks." assertions; actual production page/API over HTTP)\n";
}finally{
 proc_terminate($process);proc_close($process);
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
