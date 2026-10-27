<?php
declare(strict_types=1);
// The real event editor over HTTP; only bootstrap, navigation and SQL dialect
// are fixture adapters. This catches discarded checkbox/select POST values.
$root=sys_get_temp_dir().'/vp3-calendar-page-'.bin2hex(random_bytes(6));$repo=dirname(__DIR__);
mkdir($root.'/includes',0700,true);mkdir($root.'/sessions',0700,true);
$pdo=new PDO('sqlite:'.$root.'/fixture.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT INTO users VALUES(1,1),(2,1)');
$pdo->exec("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,created_by_agent_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,recurrence TEXT DEFAULT 'none',agent_notifications INTEGER DEFAULT 0,source TEXT,source_reference TEXT,status TEXT,cancelled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
copy($repo.'/calendar-event.php',$root.'/calendar-event.php');
$bootstrap= <<<'PHP'
<?php
declare(strict_types=1);
ini_set('session.save_path',dirname(__DIR__).'/sessions');session_start();
function db():PDO{static $pdo;return $pdo??=new PDO('sqlite:'.dirname(__DIR__).'/fixture.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function current_user():array{return ['id'=>(int)($_SERVER['HTTP_X_TEST_USER']??1)];}
function require_permission(string $p):void{if(($_SERVER['HTTP_X_TEST_ROLE']??'member')==='denied'){http_response_code(403);exit;}}
function table_exists(string $t):bool{$s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$t]);return(bool)$s->fetchColumn();}
function column_exists(string $t,string $c):bool{return in_array($c,array_column(db()->query('PRAGMA table_info('.$t.')')->fetchAll(),'name'),true);}
function agent_scheduling_schema_ready_v430(PDO $p):bool{return false;}
function video_meeting_schema_ready_v1800(PDO $p):bool{return false;}
function redirect(string $u):never{header('Location: '.$u);exit;}
function url(string $u):string{return $u;}
function e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function system_agent_name():string{return 'Calendar fixture';}
function csrf_field():string{$_SESSION['csrf_token']??=bin2hex(random_bytes(16));return '<input type="hidden" name="csrf_token" value="'.$_SESSION['csrf_token'].'">';}
function verify_csrf():bool{return isset($_POST['csrf_token'],$_SESSION['csrf_token'])&&hash_equals($_SESSION['csrf_token'],(string)$_POST['csrf_token']);}
function agent_tool_log(...$args):void{}
PHP;
$bootstrap.="\nrequire_once ".var_export($repo.'/includes/user-calendar-v1300.php',true).";\n";
file_put_contents($root.'/includes/bootstrap.php',$bootstrap);
file_put_contents($root.'/includes/user-calendar-v1300.php','<?php require_once '.var_export($repo.'/includes/user-calendar-v1300.php',true).';');
file_put_contents($root.'/includes/workspace-sidebar-v82.php','<aside class="chat-sidebar"></aside>');
file_put_contents($root.'/includes/member-header.php','<header class="chat-header">Calendar</header>');
foreach(['calendar-v1300.css','chat.css','member-shell-v77.js'] as $asset)copy($repo.'/'.$asset,$root.'/'.$asset);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
$command=[PHP_BINARY];if(php_ini_loaded_file())array_push($command,'-c',php_ini_loaded_file());array_push($command,'-S',$address,'-t',$root);
$process=proc_open($command,[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);fclose($pipes[0]);
$cookie='PHPSESSID=calendar'.bin2hex(random_bytes(6));$checks=0;
function check(bool $ok,string $message):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function request(string $path,?array $post=null,string $extra=''):array{
 global $address,$cookie;
 $options=['method'=>$post===null?'GET':'POST','header'=>'Cookie: '.$cookie."\r\n".$extra,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10];
 if($post!==null){$options['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$options['content']=http_build_query($post);}
 $html=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>$options]));preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),(string)$html];
}
function revision(string $html):string{preg_match('/name="expected_revision" value="([^"]*)"/',$html,$m);return $m[1]??'';}
try{
 for($i=0;$i<100;$i++){if(@fsockopen('127.0.0.1',(int)substr(strrchr($address,':'),1))){break;}usleep(10000);}
 [$status,$html]=request('/calendar-event.php');
 check($status===200,'Real editor renders');
 check(str_contains($html,'One time')&&str_contains($html,'Every week')&&str_contains($html,'Include agent notifications'),'All requested controls are visible');
 check((bool)preg_match('/option value="none" selected/',$html),'New events default to one time');
 check(!(bool)preg_match('/name="agent_notifications"[^>]*checked/',$html),'Priority is opt-in');
 preg_match('/name="csrf_token" value="([^"]+)"/',$html,$m);$csrf=$m[1];
 $post=['action'=>'save','mutation_id'=>'page-create-0001','expected_revision'=>'','csrf_token'=>$csrf,'title'=>'Night shift','date'=>'2026-10-26','start_time'=>'23:00','end_date'=>'2026-10-27','end_time'=>'00:00','timezone'=>'America/Phoenix','recurrence'=>'weekly','agent_notifications'=>'1'];
 [$status]=request('/calendar-event.php',$post);check($status===302,'Create posts successfully');
 $event=$pdo->query('SELECT * FROM user_calendar_events')->fetch();check($event['recurrence']==='weekly'&&(int)$event['agent_notifications']===1,'Posted controls reach the database');
 request('/calendar-event.php',$post);check((int)$pdo->query('SELECT count(*) FROM user_calendar_events')->fetchColumn()===1,'An exact form retry creates one series');
 [$status,$html]=request('/calendar-event.php?id=1');
 check($status===200&&(bool)preg_match('/option value="weekly" selected/',$html),'Edit reload retains weekly selection');
 check((bool)preg_match('/name="agent_notifications"[^>]*checked/',$html),'Edit reload retains priority');
 if($out=getenv('CALENDAR_HTML_OUTPUT'))file_put_contents($out,str_replace('</head>','<style>'.file_get_contents($repo.'/chat.css').file_get_contents($repo.'/calendar-v1300.css').'</style></head>',$html));
 $post['event_id']=1;$post['expected_revision']=revision($html);$post['recurrence']='none';unset($post['agent_notifications']);
 [$status]=request('/calendar-event.php?id=1',$post);check($status===302,'Update submits');
 $event=$pdo->query('SELECT * FROM user_calendar_events WHERE id=1')->fetch();check($event['recurrence']==='none'&&!(bool)$event['agent_notifications'],'Clearing checkbox and recurrence persists');
 $post['title']='Stale change';[$status,$html]=request('/calendar-event.php?id=1',$post);check(str_contains($html,'This record changed'),'Stale editor cannot replace newer values');
 [$status,$html]=request('/calendar-event.php?id=1',null,"X-Test-User: 2\r\n");check($status===302&&!str_contains($html,'Night shift'),'Another account cannot open the event');
 $post['csrf_token']='wrong';[$status,$html]=request('/calendar-event.php?id=1',$post);check(str_contains($html,'Session expired'),'POST requires valid CSRF');
 [$status]=request('/calendar-event.php',null,"X-Test-Role: denied\r\n");check($status===403,'Editor requires account permission');
 check($pdo->query('SELECT title FROM user_calendar_events WHERE id=1')->fetchColumn()==='Night shift','Rejected requests leave event intact');
 echo 'CALENDAR_EVENT_PAGE_V1340=PASS ('.$checks.' assertions; actual production editor over HTTP)'.PHP_EOL;
}finally{
 proc_terminate($process);proc_close($process);
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
