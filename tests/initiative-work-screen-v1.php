<?php
declare(strict_types=1);

ini_set('zend.exception_ignore_args','1');
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-screen-edit-'.bin2hex(random_bytes(5)));
try {
    require __DIR__.'/fixtures/image-generation-v1.php';
} catch (PDOException) {
    fwrite(STDERR,'The disposable screen-image test database could not be initialized.'.PHP_EOL);
    exit(1);
}
require_once __DIR__.'/../includes/knowledge.php';
require_once __DIR__.'/../includes/initiative-work-screen-v1.php';
require_once __DIR__.'/../includes/initiative-workbench-v1.php';
require_once __DIR__.'/../includes/agent-screen-insights-v1.php';

function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $aid,string $surface): array
{
    if($run=$GLOBALS['fixtureCancelRun']??null){
        unset($GLOBALS['fixtureCancelRun']);
        agent_job_cancel_v1900($pdo,$user,$run);
    }
    $cloud=empty($GLOBALS['fixtureHomeOnly']);
    return ['route'=>$cloud?'vp3_cloud':'homeserver','cloud'=>['ready'=>$cloud],'blocked'=>!$cloud];
}
function check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $call,int $code,string $message): void
{
    try{$call();}catch(Throwable $e){check($e->getCode()===$code,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}

$GLOBALS['initiativeScreenAccess']=true;
$owner=['id'=>1,'is_active'=>1];
$scope=['user'=>$owner,'agent_id'=>7,'conversation_id'=>11];
$frame='data:image/jpeg;base64,'.trim(file_get_contents(__DIR__.'/fixtures/tv-frame.b64'));
$uuid=static fn(int $i):string=>sprintf('00000000-0000-4000-8000-%012d',$i);
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';

try{
    screen_sessions_schema_v1($pdo);
    screen_insights_schema_v1($pdo);
    $pdo->exec('CREATE TABLE artist_transcript_folders_v177(id INTEGER PRIMARY KEY,created_by_user_id INTEGER NOT NULL,folder_name VARCHAR(80) NOT NULL)');
    $pdo->exec('CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER NOT NULL,folder_id INTEGER NOT NULL,
        knowledge_scope VARCHAR(20) NOT NULL,file_type VARCHAR(40) NOT NULL,file_name VARCHAR(190) NOT NULL)');
    $pdo->exec('CREATE TABLE knowledge_chunks(id INTEGER PRIMARY KEY,knowledge_id INTEGER NOT NULL,chunk_text TEXT)');
    $session=$pdo->prepare("INSERT INTO agent_screen_sessions_v1
        (owner_user_id,session_id,folder_id,agent_id,title,status,created_at,updated_at) VALUES (?,?,?,?,?,'ended',?,?)");
    $folder=$pdo->prepare('INSERT INTO artist_transcript_folders_v177(id,created_by_user_id,folder_name) VALUES (?,?,?)');
    $capture=$pdo->prepare('INSERT INTO agent_screen_captures_v1
        (owner_user_id,session_id,capture_id,kind,captured_at,knowledge_id,image_data,body_json) VALUES (?,?,?,?,?,?,?,?)');
    $note=$pdo->prepare("INSERT INTO knowledge_items(id,created_by_user_id,folder_id,knowledge_scope,file_type,file_name)
        VALUES (?,?,?,'personal','personal_note',?)");
    $stamp='2030-02-06T12:00:00Z';
    foreach([[1,1,7,'screen'],[2,1,8,'screen'],[3,2,9,'screen'],[4,1,7,'tab_audio'],[5,1,7,'screen']] as [$id,$uid,$aid,$kind]){
        $folder->execute([$id,$uid,'Folder '.$id]);
        $session->execute([$uid,$uuid($id),$id,$aid,'Fixture capture '.$id,$stamp,$stamp]);
        $note->execute([$id,$uid,$id,'personal-'.sha1('screen-capture:'.$uuid(100+$id)).'.txt']);
        $capture->execute([$uid,$uuid($id),$uuid(100+$id),$kind,$stamp,$id,$frame,'{"transcript":"PRIVATE OBSERVATION"}']);
    }
    $pdo->prepare("INSERT INTO agent_screen_reviews_v1(owner_user_id,capture_id,state,note,updated_at) VALUES (1,?,'irrelevant','',?)")
        ->execute([$uuid(105),$stamp]);

    if($mysql){
        vp3_image_schema_v1($pdo);
        $pdo->exec('ALTER TABLE agent_image_generations_v1 MODIFY user_message_id BIGINT NOT NULL');
    }
    $input=['source_id'=>$uuid(101),'mode'=>'cleanup','instruction'=>'Improve the title legibility.'];
    $preview=static fn(array $data):array=>initiative_work_screen_preview_v1($pdo,1,$scope,$data);
    $approve=static function (array $data,?array $review=null) use ($pdo,$scope,$preview):array {
        $review??=$preview($data);
        $id=initiative_workbench_request_v1(1,$scope,'screen',$data,$review);
        return initiative_work_screen_create_v1($pdo,1,$scope,$data+['request_id'=>$id],$review);
    };
    $countAssets=static fn():int=>(int)$pdo->query('SELECT COUNT(*) FROM user_media_assets')->fetchColumn();
    $tick=static fn(array $result,callable $transport):array=>vp3_image_tick_v1($pdo,$owner,
        vp3_image_row_v1($pdo,$owner,$result['generation']['id']),$transport);
    $calls=[];
    $transport=static function (string $endpoint,array $body,string $key) use (&$calls):array {
        $calls[]=[$endpoint,$body,$key];
        return image_fixture_response_v1($body['size']);
    };

    check(initiative_work_screen_sources_v1($pdo,1,$scope)===[['source_id'=>$uuid(101),'source_title'=>'Fixture capture 1','captured_at'=>$stamp]],
        'discovery lists only current saved screen captures for the exact owner and Agent');
    foreach([102,103,104,105] as $wrong){
        rejects(fn()=>$preview([...$input,'source_id'=>$uuid($wrong)]),404,'wrong-Agent, foreign, audio or withdrawn capture is denied');
    }
    rejects(fn()=>initiative_work_screen_preview_v1($pdo,1,[...$scope,'conversation_id'=>12],$input),404,'wrong Agent conversation cannot inspect an owned capture');
    $review=$preview($input);
    $visible=$preview($input+['show_image'=>true]);
    check($review['revision']===$visible['revision']&&$visible['details']['image_data']===$frame&&$visible['details']['width']>=160
        &&!isset($review['details']['image_data'])&&$review['details']['sends_capture']===true,'visual review returns only the exact JPEG and does not change confirmation identity');
    check(str_contains($review['details']['summary'],'can change details'),'AI cleanup discloses that details may change');
    $defaultReview=$preview([...$input,'instruction'=>'']);
    $changedTreatment=$preview([...$input,'mode'=>'redraw','instruction'=>'Use a clean diagram style.']);
    check($defaultReview['reference_revision']===$defaultReview['revision']
        &&$changedTreatment['reference_revision']===$defaultReview['reference_revision']
        &&$changedTreatment['revision']!==$defaultReview['revision'],
        'Agent capture identity stays stable across owner edit choices while confirmation remains treatment-specific');
    rejects(fn()=>$approve([...$input,'mode'=>'redraw'],$review),409,'approval of cleanup cannot authorize redraw');
    rejects(fn()=>$approve([...$input,'instruction'=>'Replace the title'],$review),409,'approval cannot be reused for changed direction');
    $queued=$approve($input,$review);
    $id=$queued['generation']['id'];
    check($queued['canvas_media']['generation_ids']===[$id]&&$queued['generation']['status']==='queued'&&$calls===[],
        'approval queues the canonical image card without uploading to the provider');
    check($approve($input,$review)['generation']['id']===$id&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===0
        &&vp3_image_row_v1($pdo,$owner,$id)['user_message_id']===null,'replay recovers the same job without minting a fake Chat turn');
    check(!str_contains(vp3_image_row_v1($pdo,$owner,$id)['config_json'],'data:image'), 'canonical queue stores the exact reference and omits private pixels');
    rejects(fn()=>$approve([...$input,'mode'=>'redraw']),409,'another image cannot bypass the canonical owner admission limit');

    $saved=$tick($queued,$transport);
    check($saved['status']==='completed'&&$saved['mode']==='cleanup'&&$saved['asset']['available']&&$countAssets()===1,
        'the existing worker publishes one authenticated private PNG and completion card');
    [$endpoint,$body,$key]=$calls[0];
    check($endpoint==='https://api.openai.com/v1/images/edits'&&$body['images']===[['image_url'=>$frame]]&&$body['n']===1
        &&$body['output_format']==='png'&&!str_contains($body['prompt'],'PRIVATE OBSERVATION')&&str_contains($body['prompt'],'Preserve the original layout'),
        'only the reviewed frame goes to the fixed edit endpoint with the faithful cleanup prompt');
    check($GLOBALS['imagePreflight'][0][2]>4096&&$GLOBALS['imageUsage'][0][5]['total_tokens']===220,
        'quota reserves image input and records verifiable actual usage');
    $tick($queued,$transport);
    check(count($calls)===1&&$countAssets()===1,'repeated worker ticks cannot upload the screenshot or save the output twice');

    $redraw=[...$input,'mode'=>'redraw','instruction'=>'Use watercolor.'];
    $queued=$approve($redraw);
    $oldReference=$preview($redraw)['reference_revision'];
    $pdo->prepare('UPDATE agent_screen_captures_v1 SET revision=revision+1 WHERE capture_id=?')->execute([$uuid(101)]);
    check($preview($redraw)['reference_revision']!==$oldReference,'a changed saved capture changes the Agent reference even for redraw');
    $failed=$tick($queued,$transport);
    check($failed['status']==='failed'&&str_contains($failed['message'],'capture changed')&&count($calls)===1,
        'stale capture revisions stop before provider dispatch');

    $queued=$approve($redraw);
    $GLOBALS['fixtureHomeOnly']=true;
    check($preview($redraw+['show_image'=>true])['details']['image_data']===$frame,'owner can inspect the original under HomeServer-only policy');
    rejects(fn()=>$approve($redraw),503,'HomeServer-only policy cannot approve a Cloud screenshot upload');
    check($tick($queued,$transport)['status']==='failed'&&count($calls)===1,'live compute policy also blocks an already queued upload');
    $GLOBALS['fixtureHomeOnly']=false;

    $queued=$approve([...$redraw,'instruction'=>'Use ink.']);
    $GLOBALS['fixtureCancelRun']=$queued['generation']['job_id'];
    $cancelled=$tick($queued,$transport);
    check($cancelled['status']==='cancelled'&&!$cancelled['provider_started']&&count($calls)===1,
        'cancelling after the lease is claimed and before dispatch leaves the provider marker unset');

    $queued=$approve([...$redraw,'instruction'=>'Use collage.']);
    $cancelled=$tick($queued,static function (string $endpoint,array $body,string $key) use ($pdo,$owner,$queued,$transport):array {
        agent_job_cancel_v1900($pdo,$owner,$queued['generation']['job_id']);
        return $transport($endpoint,$body,$key);
    });
    check($cancelled['status']==='cancelled'&&$cancelled['provider_started']&&$cancelled['asset']===null&&$countAssets()===1,
        'cancelling after the provider marker prevents publishing a late result');

    $queued=$approve([...$redraw,'instruction'=>'Use charcoal.']);
    $usageBefore=count($GLOBALS['imageUsage']);
    $removed=$tick($queued,static function (string $endpoint,array $body,string $key) use ($pdo,$uuid,$transport):array {
        check(str_contains($body['prompt'],'new visual interpretation')&&str_contains($body['prompt'],'Use charcoal.'),
            'redraw uses the exact reviewed creative direction');
        $pdo->prepare("UPDATE agent_screen_sessions_v1 SET status='deleted' WHERE owner_user_id=1 AND session_id=?")->execute([$uuid(1)]);
        return $transport($endpoint,$body,$key);
    });
    check($removed['status']==='failed'&&$removed['asset']===null&&$countAssets()===1&&count($GLOBALS['imageUsage'])===$usageBefore+1,
        'a source removed during the provider call records the charge without publishing an edited image');
    $tick($queued,$transport);
    check(count($calls)===3,'a revoked edit cannot replay its paid request');
    echo 'INITIATIVE_WORK_SCREEN_V1=PASS ('.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
}finally{
    if(is_dir(STONEFELLOW_ROOT)){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $path){if($path->isDir())rmdir($path->getPathname());else unlink($path->getPathname());}
        rmdir(STONEFELLOW_ROOT);
    }
}
