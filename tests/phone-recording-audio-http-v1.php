<?php
declare(strict_types=1);

/** Real HTTP edge tests; only ownership/media dependencies are synthetic. */
$root=dirname(__DIR__);
$temporary=sys_get_temp_dir().'/vp3-phone-audio-http-'.bin2hex(random_bytes(8));
$process=null;$checks=0;
function phone_recording_audio_http_check_v1(bool $condition,string $message):void
{
    global $checks;
    if(!$condition)throw new RuntimeException($message);
    $checks++;
}
function phone_recording_audio_http_request_v1(string $base,string $query,string $method='GET',?string $range=null):array
{
    $headers=['Cookie: VP3PhoneAudioFixture=owner-fixture'];
    if($range!==null)$headers[]='Range: '.$range;
    $context=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>4,
        'header'=>implode("\r\n",$headers),'protocol_version'=>1.1]]);
    $body=@file_get_contents($base.'/api/phone-recording-audio-v1.php?'.$query,false,$context);
    $response=$http_response_header??[];$status=0;$normalized=[];
    foreach($response as $header){
        if(preg_match('~\AHTTP/\d(?:\.\d)? (\d{3})~D',$header,$match)){$status=(int)$match[1];$normalized=[];continue;}
        if(str_contains($header,':')){[$name,$value]=explode(':',$header,2);$normalized[strtolower(trim($name))]=trim($value);}
    }
    if($status===0)throw new RuntimeException('Fixture HTTP response was unavailable.');
    return ['status'=>$status,'headers'=>$normalized,'body'=>$body===false?'':$body];
}

try{
    foreach([$temporary,$temporary.'/api',$temporary.'/includes',$temporary.'/sessions'] as $directory)
        if(!mkdir($directory,0700)&&!is_dir($directory))throw new RuntimeException('Fixture directory unavailable.');
    $frame="\xff\xfb\x90\x00".str_repeat("\0",413);
    $bytes=$frame.$frame;$retained=$frame.$frame.$frame;$legacy=$frame.$frame.$frame.$frame;
    file_put_contents($temporary.'/bytes.mp3',$bytes);
    file_put_contents($temporary.'/retained.mp3',$retained);
    file_put_contents($temporary.'/legacy.mp3',$legacy);
    // The endpoint body is copied unchanged. Its real relative requires now
    // resolve to a fixture bootstrap/media adapter and the real range helper.
    if(!copy($root.'/api/phone-recording-audio-v1.php',$temporary.'/api/phone-recording-audio-v1.php')
        ||!copy($root.'/includes/artist-recording-playback-v243.php',$temporary.'/includes/artist-recording-playback-v243.php'))
        throw new RuntimeException('Production playback source unavailable.');
    file_put_contents($temporary.'/includes/bootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);
session_save_path(dirname(__DIR__).'/sessions');
session_name('VP3PhoneAudioFixture');
session_start();
ob_start();echo 'This buffered bootstrap prefix must never enter an audio response.';
final class PhoneAudioFixturePDO extends PDO{public function __construct(){}}
function current_user():?array{
    $mode=$_GET['fixture_user']??'owner';
    if($mode==='guest')return null;
    return ['id'=>7,'is_active'=>$mode!=='inactive','fixture_permission'=>$mode!=='forbidden',
        'fixture_assigned'=>$mode!=='unassigned','fixture_number'=>$mode==='reassigned'?'different-DID':'original-DID'];
}
function has_permission(string $permission,array $user):bool{return $user['fixture_permission'];}
function db():PDO{return new PhoneAudioFixturePDO();}
PHP);
    file_put_contents($temporary.'/includes/phone-workspace-v1.php', <<<'PHP'
<?php
declare(strict_types=1);
function vp3_phone_workspace_available_v1(PDO $pdo,array $user):bool{return !empty($user['fixture_assigned']);}
PHP);
    file_put_contents($temporary.'/includes/phone-recording-pipeline-v1.php', <<<'PHP'
<?php
declare(strict_types=1);
const VP3_PHONE_RECORDING_MAX_BYTES_V1=26214400;
function vp3_phone_recording_audio_any_v1(PDO $pdo,array $user,string $id):array{
    if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('Playback still holds the owner session lock.');
    if($user['id']!==7)throw new RuntimeException('Another owner: /private/secret.mp3',404);
    return match($id){
        'job-1'=>['bytes'=>file_get_contents(dirname(__DIR__).'/bytes.mp3'),'mime'=>'audio/mpeg'],
        'job-2'=>['path'=>dirname(__DIR__).'/retained.mp3','mime'=>'audio/mpeg'],
        'legacy-1'=>['bytes'=>file_get_contents(dirname(__DIR__).'/legacy.mp3'),'mime'=>'audio/mpeg'],
        'job-8'=>['bytes'=>'<html>Not audio</html>','mime'=>'text/html'],
        'job-9','legacy-9'=>throw new RuntimeException('Other owner: https://provider.invalid/private.mp3',404),
        default=>throw new RuntimeException('Private server path /private/credentials.json is unavailable.'),
    };
}
PHP);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$socketError,$socketMessage);
    if($socket===false)throw new RuntimeException('Fixture port unavailable.');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    if(!is_string($address)||!preg_match('/:(\d+)\z/D',$address,$port))throw new RuntimeException('Fixture port invalid.');
    $base='http://127.0.0.1:'.$port[1];
    $process=proc_open([PHP_BINARY,'-d','display_errors=0','-S','127.0.0.1:'.$port[1],'-t',$temporary],
        [0=>['pipe','r'],1=>['file',$temporary.'/server.log','a'],2=>['file',$temporary.'/server.log','a']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Fixture PHP server could not start.');
    fclose($pipes[0]);$ready=false;
    for($attempt=0;$attempt<200;$attempt++){
        $probe=@stream_socket_client('tcp://127.0.0.1:'.$port[1],$error,$message,.05);
        if(is_resource($probe)){fclose($probe);$ready=true;break;}
        if(!proc_get_status($process)['running'])throw new RuntimeException('Fixture PHP server exited before acceptance.');
        usleep(10000);
    }
    if(!$ready)throw new RuntimeException('Fixture PHP server did not become ready.');
    foreach(['job-1'=>$bytes,'job-2'=>$retained,'legacy-1'=>$legacy] as $id=>$expected){
        $response=phone_recording_audio_http_request_v1($base,'id='.$id);
        phone_recording_audio_http_check_v1($response['status']===200&&$response['body']===$expected,'GET returns only the private '.$id.' audio and removes buffered bootstrap output.');
        phone_recording_audio_http_check_v1(str_contains($response['headers']['cache-control']??'','private')&&str_contains($response['headers']['cache-control']??'','no-store')
            &&($response['headers']['x-content-type-options']??'')==='nosniff'&&($response['headers']['accept-ranges']??'')==='bytes'
            &&($response['headers']['content-type']??'')==='audio/mpeg'&&!isset($response['headers']['location']),'Playback headers remain private without provider redirects.');
        $response=phone_recording_audio_http_request_v1($base,'id='.$id,'HEAD');
        phone_recording_audio_http_check_v1($response['status']===200&&$response['body']===''&&(int)$response['headers']['content-length']===strlen($expected),'HEAD returns the same length without a media body.');
        $response=phone_recording_audio_http_request_v1($base,'id='.$id,'GET','bytes=3-10');
        phone_recording_audio_http_check_v1($response['status']===206&&$response['body']===substr($expected,3,8)
            &&$response['headers']['content-range']==='bytes 3-10/'.strlen($expected),'Closed range selects the exact '.$id.' bytes.');
        $response=phone_recording_audio_http_request_v1($base,'id='.$id,'HEAD','bytes=3-10');
        phone_recording_audio_http_check_v1($response['status']===206&&$response['body']===''&&(int)$response['headers']['content-length']===8,'Ranged HEAD contains headers only.');
    }
    $response=phone_recording_audio_http_request_v1($base,'id=job-1','GET','bytes=-4');
    phone_recording_audio_http_check_v1($response['status']===206&&$response['body']===substr($bytes,-4),'Suffix range returns the last bytes.');
    foreach(['bytes=9999-','bytes=0-1,4-5','bytes=-0','bytes=10-2','broken'] as $range){
        $response=phone_recording_audio_http_request_v1($base,'id=job-1','GET',$range);
        phone_recording_audio_http_check_v1($response['status']===416&&$response['body']===''&&($response['headers']['content-range']??'')==='bytes */'.strlen($bytes),'Malformed, multiple or unavailable ranges fail with 416.');
    }
    foreach(['job-9','legacy-9','1','legacy-0','job-0','job-01','job-1%2Fsecret'] as $id){
        $response=phone_recording_audio_http_request_v1($base,'id='.$id);
        phone_recording_audio_http_check_v1($response['status']===404&&$response['body']==='','Wrong-owner and invalid typed identifiers remain opaque.');
    }
    $response=phone_recording_audio_http_request_v1($base,'id=job-1&owner_user_id=99&user_id=99');
    phone_recording_audio_http_check_v1($response['status']===200&&$response['body']===$bytes,'Query-supplied owners cannot replace the signed-in identity.');
    foreach(['guest'=>401,'inactive'=>403,'forbidden'=>403,'unassigned'=>403] as $mode=>$status){
        $response=phone_recording_audio_http_request_v1($base,'id=job-1&fixture_user='.$mode);
        phone_recording_audio_http_check_v1($response['status']===$status&&$response['body']==='','Owner authentication and current permissions protect playback.');
    }
    foreach(['job-2'=>$retained,'legacy-1'=>$legacy] as $id=>$expected){
        $response=phone_recording_audio_http_request_v1($base,'id='.$id.'&fixture_user=reassigned');
        phone_recording_audio_http_check_v1($response['status']===200&&$response['body']===$expected,'An owner assigned a different current DID keeps historical recording playback.');
    }
    $response=phone_recording_audio_http_request_v1($base,'id=job-1','POST');
    phone_recording_audio_http_check_v1($response['status']===405&&$response['body']===''&&($response['headers']['allow']??'')==='GET, HEAD','Mutating methods are rejected.');
    foreach(['job-8'=>404,'job-7'=>503] as $id=>$status){
        $response=phone_recording_audio_http_request_v1($base,'id='.$id);
        phone_recording_audio_http_check_v1($response['status']===$status&&$response['body']===''&&!isset($response['headers']['location']),'Invalid media and server errors reveal no path or provider data.');
    }
    echo 'PHONE_RECORDING_AUDIO_HTTP_V1=PASS ('.$checks.' assertions; production endpoint, real ranges/session release, synthetic ownership/media)'.PHP_EOL;
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    if(is_dir($temporary)){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}
        rmdir($temporary);
    }
}
