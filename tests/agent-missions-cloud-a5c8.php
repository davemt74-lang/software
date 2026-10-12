<?php
declare(strict_types=1);
/** Exercise the production relay over HTTP with isolated auth/transport fixtures. */
$root=sys_get_temp_dir().'/vp3-a5c7-'.bin2hex(random_bytes(6));
mkdir($root.'/api',0700,true);mkdir($root.'/includes',0700,true);
copy(__DIR__.'/../api/agent-missions-cloud-v1.php',$root.'/api/agent-missions-cloud-v1.php');
file_put_contents($root.'/includes/bootstrap.php', <<<'PHP'
<?php
function require_permission($permission){if(($_SERVER['HTTP_X_TEST_ACCESS']??'yes')==='no')throw new RuntimeException('Permission denied.',403);}
function current_user(){return ['id'=>(int)($_SERVER['HTTP_X_TEST_USER']??'12')];}
function csrf_token(){return 'fixture';}
PHP);
file_put_contents($root.'/includes/homeserver-https-relay-v1300.php', <<<'PHP'
<?php
function db(){
    static $pdo=null;
    if(!$pdo){$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$pdo->exec('CREATE TABLE homeserver_https_requests(public_id TEXT,status TEXT,response_status INTEGER,response_json TEXT)');}
    return $pdo;
}
function homeserver_https_v1300_status($user){return ['connected'=>(($_SERVER['HTTP_X_TEST_CONNECTED']??'no')==='yes')];}
function homeserver_https_v1300_queue($user,$operation,$payload){
    file_put_contents(__DIR__.'/../queue.json',json_encode([$user,$operation,$payload]));
    $status=(int)($_SERVER['HTTP_X_TEST_UPSTREAM_STATUS']??200);
    $body=$status===200?['ok'=>true,'contract'=>'vp3.agent-missions.cloud.v1','route'=>'chat','reason'=>'Direct answer']:['detail'=>'Upstream authorization or review rejected.'];
    db()->prepare('INSERT INTO homeserver_https_requests VALUES(?,?,?,?)')->execute(['queued',($_SERVER['HTTP_X_TEST_TIMEOUT']??'no')==='yes'?'expired':($status===200?'completed':'failed'),$status,json_encode($body)]);
    return 'queued';
}
PHP);
// Exercise production receipt readback as well as the production HTTP endpoint.
// A mocked wait hides the loss of upstream permission/error status codes.
$relay=file_get_contents(__DIR__.'/../includes/homeserver-https-relay-v1300.php');
$start=strpos($relay,'function homeserver_https_v1300_wait(');
$end=strpos($relay,'function homeserver_https_v1300_remote_operation(',$start);
file_put_contents($root.'/includes/homeserver-https-relay-v1300.php',substr($relay,$start,$end-$start),FILE_APPEND);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);
$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,'-t',$root],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
if(!is_resource($process))throw new RuntimeException('Relay test server could not start.');
fclose($pipes[0]);
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function send(array $body,array $headers=[]):array{
    global $address;
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",array_merge(['Content-Type: application/json'],$headers)),'content'=>json_encode($body,JSON_UNESCAPED_UNICODE),'ignore_errors'=>true,'timeout'=>5]]);
    $text=file_get_contents('http://'.$address.'/api/agent-missions-cloud-v1.php',false,$context);
    preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$match);
    return [(int)($match[1]??0),json_decode((string)$text,true,24,JSON_THROW_ON_ERROR)];
}
try{
    $ready=false;
    for($i=0;$i<100;$i++){if($connection=@stream_socket_client('tcp://'.$address,$errno,$error,.05)){fclose($connection);$ready=true;break;}usleep(10000);}
    check($ready,'Relay test server did not become ready.');
    $base=['csrf_token'=>'fixture'];
    foreach(['progress.feed','notifications.list'] as $action){
        [$status,$result]=send($base+['action'=>$action,'source_app_key'=>'owner'],['X-Test-Connected: yes']);
        check($status===200,'Live operation did not relay.');
        $queue=json_decode(file_get_contents($root.'/queue.json'),true,24,JSON_THROW_ON_ERROR);
        check($queue===[12,'agent.missions.'.$action,[]],'Live feed cannot elevate source authority.');
        [$status,$result]=send($base+['action'=>$action],['X-Test-Connected: no']);check($status===503,'Disconnected feeds must remain unavailable.');
        [$status,$result]=send(['action'=>$action,'csrf_token'=>'wrong'],['X-Test-Connected: yes']);check($status===419,'Feed requires CSRF.');
        [$status,$result]=send($base+['action'=>$action],['X-Test-Access: no']);check($status===403,'Feed requires chat access.');
    }
    [$status,$result]=send($base+['action'=>'notifications.read','notice_id'=>4,'source_app_key'=>'owner'],['X-Test-Connected: yes']);check($status===200,'Exact read did not relay.');
    $queue=json_decode(file_get_contents($root.'/queue.json'),true,24,JSON_THROW_ON_ERROR);check($queue===[12,'agent.missions.notifications.read',['notice_id'=>4]],'Read must preserve exact identity and current-user scope.');
    foreach([0,-1,'4',true,null] as $invalid){[$status,$result]=send($base+['action'=>'notifications.read','notice_id'=>$invalid],['X-Test-Connected: yes']);check($status===422,'Invalid notification ID accepted.');}
    [$status,$result]=send($base+['action'=>'progress.feed'],['X-Test-Connected: yes','X-Test-Timeout: yes']);check($status===503,'Uncertain feed must not report healthy.');
    foreach([401,403,404,409,422,503] as $upstream){
        [$status,$result]=send($base+['action'=>'progress.feed'],['X-Test-Connected: yes','X-Test-Upstream-Status: '.$upstream]);
        check($status===$upstream && $result['ok']===false,'Upstream status must survive receipt readback and HTTP relay.');
    }
    echo "A5C8 relay PASS: real HTTP source scoping, authentication/CSRF, notification read identity, production receipt readback, upstream rejection status and disconnected feed handling\n";
}finally{
    proc_terminate($process);proc_close($process);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}
    rmdir($root);
}
