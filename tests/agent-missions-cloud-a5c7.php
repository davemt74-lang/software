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
function homeserver_https_v1300_status($user){return ['connected'=>(($_SERVER['HTTP_X_TEST_CONNECTED']??'no')==='yes')];}
function homeserver_https_v1300_queue($user,$operation,$payload){file_put_contents(__DIR__.'/../queue.json',json_encode([$user,$operation,$payload]));return 'queued';}
function homeserver_https_v1300_wait($request,$timeout){return (($_SERVER['HTTP_X_TEST_TIMEOUT']??'no')==='yes')?null:['ok'=>true,'contract'=>'vp3.agent-missions.cloud.v1','route'=>'chat','reason'=>'Direct answer'];}
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);
$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,'-t',$root],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
if(!is_resource($process))throw new RuntimeException('Relay test server could not start.');
fclose($pipes[0]);
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function send(array $body,array $headers=[]):array{
    global $address;
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",array_merge(['Content-Type: application/json'],$headers)),'content'=>json_encode($body),'ignore_errors'=>true,'timeout'=>5]]);
    $text=file_get_contents('http://'.$address.'/api/agent-missions-cloud-v1.php',false,$context);
    preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$match);
    return [(int)($match[1]??0),json_decode((string)$text,true,24,JSON_THROW_ON_ERROR)];
}
try{
    $ready=false;
    for($i=0;$i<100;$i++){if($connection=@stream_socket_client('tcp://'.$address,$errno,$error,.05)){fclose($connection);$ready=true;break;}usleep(10000);}
    check($ready,'Relay test server did not become ready.');
    $body=['action'=>'task.route','csrf_token'=>'fixture','objective'=>'Review contacts','request_id'=>'44444444-4444-4444-8444-444444444444','thread_id'=>77];
    [$status,$result]=send($body);check($status===200&&$result['route']==='chat','Disconnected routing must retain ordinary chat.');
    check(!file_exists($root.'/queue.json'),'Offline routing must not queue work.');
    foreach([['csrf_token'=>'wrong'],['request_id'=>'invalid'],['thread_id'=>-1],['objective'=>''],['objective'=>str_repeat('x',4001)],['objective'=>[]]] as $invalid){
        [$status,$result]=send(array_replace($body,$invalid));check(in_array($status,[419,422],true)&&!$result['ok'],'Invalid offline request was accepted.');
    }
    [$status,$result]=send($body,['X-Test-Access: no']);check($status===403,'Chat permission must precede offline fallback.');
    [$status,$result]=send($body,['X-Test-User: 0']);check($status===401,'Login must precede offline fallback.');
    [$status,$result]=send(array_replace($body,['action'=>'task.prepare']));check($status===503&&!$result['ok'],'Explicit specialist mode cannot silently become chat.');
    check(!file_exists($root.'/queue.json'),'Rejected operations must not queue work.');
    [$status,$result]=send(array_merge($body,['source_app_key'=>'owner','parent_agent_id'=>1,'plan'=>['unsafe'=>true]]),['X-Test-Connected: yes']);
    check($status===200&&$result['route']==='chat','Connected routing did not relay.');
    $queued=json_decode(file_get_contents($root.'/queue.json'),true,24,JSON_THROW_ON_ERROR);
    check($queued[0]===12&&$queued[1]==='agent.missions.task.route','Relay lost current-user scope.');
    check($queued[2]===['objective'=>'Review contacts','request_id'=>$body['request_id'],'thread_id'=>77],'Client cannot submit raw plans or elevate the lead/source.');
    [$status,$result]=send($body,['X-Test-Connected: yes','X-Test-Timeout: yes']);check($status===503&&!$result['ok'],'Uncertain in-flight work must not fall back to ordinary chat.');
    echo "A5C7 relay PASS: real HTTP validation, auth/CSRF, offline zero-dispatch, source-scoped relay and uncertain-response fencing\n";
}finally{
    proc_terminate($process);proc_close($process);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}
    rmdir($root);
}
