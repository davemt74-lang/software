<?php
declare(strict_types=1);

require __DIR__.'/fixtures/cloud-mission-isolated-mysql-v1.php';
if(getenv('VP3_CLOUD_SCHEDULE_DSN')){
    putenv('VP3_IMAGE_TEST_DSN='.getenv('VP3_CLOUD_SCHEDULE_DSN'));
    putenv('VP3_IMAGE_TEST_USER='.getenv('VP3_CLOUD_SCHEDULE_USER'));
    putenv('VP3_IMAGE_TEST_PASSWORD='.getenv('VP3_CLOUD_SCHEDULE_PASSWORD'));
}
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-image-composer-test-'.bin2hex(random_bytes(5)));
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-reply-tool-plan-v1.php';
require_once dirname(__DIR__).'/includes/participant-chat-v1.php';
require_once dirname(__DIR__).'/includes/chat-image-composer-v1.php';

$checks=0;$owner=['id'=>1,'is_active'=>1];
$principal=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];
function composer_check(bool $value,string $label): void
{
    if(!$value)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo "PASS $label\n";
}
function composer_reject(callable $operation,string $label,?int $status=null): void
{
    try{$operation();}catch(Throwable $error){
        if($status!==null&&(int)$error->getCode()!==$status)throw new RuntimeException($label.' returned '.$error->getCode(),0,$error);
        composer_check(true,$label);return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}

try{
    foreach([
        'Generate an image of three cats'=>1,
        'Generate an image of 3 cats'=>1,
        'Create an image of four paintings on a wall'=>1,
        'Create a 3D illustration of a house'=>1,
        'Generate 3 cats in an image'=>1,
        'Generate two images of a mountain lake'=>2,
        'Please create 3 album cover images of the sea'=>3,
        'Could you render four square watercolor illustrations of roses'=>4,
        'Make me two new portrait versions of the base image'=>2,
        'Generate a set of three landscape images of a lake'=>3,
        'Design 4 album-cover variations of this photo'=>4,
        'Create 2 photographs of three cats'=>2,
        'Generate 100 images of a mountain lake'=>100,
        'Make five variations of the base image'=>5,
        'Generate twenty images of a mountain lake'=>20,
        'Generate one hundred images of a mountain lake'=>5,
        'Generate 0 images of a mountain lake'=>0,
        'Generate -2 images of a mountain lake'=>-2,
        'Generate 2.5 images of a mountain lake'=>0,
    ] as $query=>$expected){
        composer_check(vp3_agent_chat_image_count_v1($query)===$expected,'output count is bound to the output noun: '.$query);
    }
    foreach(['Generate five images of a lake','Create 100 album cover images of the sea',
        'Make 0 variations of this image','Render 2.5 pictures of roses','Generate one hundred images of a lake'] as $query){
        composer_reject(fn()=>vp3_image_text_plan_v1(['prompt'=>$query,'count'=>vp3_agent_chat_image_count_v1($query)]),
            'invalid explicit quantity cannot become a silently reduced paid batch: '.$query);
    }
    $single=vp3_agent_chat_reply_image_plan_v1('Generate an image of three red cats');
    composer_check($single!==null&&$single['prompt']==='three red cats'&&!isset($single['count']),
        'ordinary single-image plans preserve legacy count omission');
    $batch=vp3_agent_chat_reply_image_plan_v1('Create four portrait images of a quiet coast');
    composer_check($batch!==null&&$batch['count']===4&&$batch['size']==='1024x1536',
        'literal batch plans preserve the explicit quantity and aspect ratio');
    composer_check(vp3_agent_chat_image_needs_scene_v1('Generate three images'),
        'an explicit multi-image request without a scene enters clarification');
    $edit=vp3_chat_image_reference_plan_v1('Please make this image look like a watercolor painting');
    composer_check($edit!==null&&$edit['count']===1&&str_contains($edit['prompt'],'watercolor'),
        'a concrete edit uses the attached base and literal owner prompt');
    $edit=vp3_chat_image_reference_plan_v1('Create two variations of this image with a transparent background');
    composer_check($edit!==null&&$edit['count']===2&&$edit['background']==='transparent',
        'attached variations preserve quantity and transparency');
    $edit=vp3_chat_image_reference_plan_v1('Make this image blue without a transparent background');
    composer_check($edit!==null&&$edit['background']==='opaque','negated transparency is respected');
    foreach(['What do you think of this image?','Can you edit images?','Maybe we could make a variation later',
        'Make a variation of this image then publish it','Create image generation support for my app',
        'Make a variation of this image, but do not generate it yet','Make this image blue tomorrow',
        'Remix this image tomorrow with a warmer palette','Modify this image later with a blue background'] as $query){
        composer_check(vp3_chat_image_reference_plan_v1($query)===null,
            'attachment alone does not turn discussion or external actions into generation: '.$query);
    }
    composer_check(vp3_chat_image_reference_input_v1([])===0,'an absent reference is explicitly detached');
    foreach([0,-1,'1',true,false,null,1.2,9007199254740992,[]] as $value){
        composer_reject(fn()=>vp3_chat_image_reference_input_v1(['image_reference_asset_id'=>$value]),
            'reference IDs reject nonpositive, coerced or unsafe values: '.json_encode($value),422);
    }
    $context=['conversation_id'=>11,'user_agent_id'=>7,'image_reference_asset_id'=>999,
        'image_reference'=>['attached'=>true,'title'=>'Stale client context']];
    $privateReference=['stale'=>true];
    composer_check(vp3_chat_image_reference_context_v1($pdo,$owner,$principal,[],$context,$privateReference)
        ===['conversation_id'=>11,'user_agent_id'=>7]&&$privateReference===null,
        'stale nested context cannot reattach an image omitted from the current request');
    $bytes=base64_decode(image_fixture_response_v1()['data'][0]['b64_json']);
    $path=media_studio_ensure_user_root(1).'/composer-base.png';file_put_contents($path,$bytes);
    $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at) VALUES(1,'photo','My base image','image/png','private/user-media/1/composer-base.png',?,0,'upload','{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([strlen($bytes)]);
    $asset=(int)$pdo->lastInsertId();$input=['image_reference_asset_id'=>$asset];
    $GLOBALS['noImageKey']=true;
    $attached=vp3_chat_image_reference_context_v1($pdo,$owner,$principal,$input,$context,$privateReference);
    composer_check($attached===['conversation_id'=>11,'user_agent_id'=>7,'image_reference_asset_id'=>$asset,
        'image_reference'=>['attached'=>true,'title'=>'My base image']],
        'attachment returns only validated request-scoped metadata and needs no image-provider key');
    composer_check($privateReference['asset_id']===$asset&&$privateReference['sha256']===hash('sha256',$bytes)
        &&!isset($privateReference['bytes'])&&!isset($attached['image_reference']['sha256']),
        'private source validation is available by reference without adding pixels or hashes to conversational context');
    $requestInput=$input+['message'=>' Make this image blue '];
    $binding=vp3_chat_image_reference_request_v1($pdo,$owner,$principal,$requestInput,$context);
    composer_check($binding===['asset_id'=>$asset,'sha256'=>hash('sha256',$bytes),'revision'=>$privateReference['revision'],
        'query_hash'=>hash('sha256','Make this image blue'),'conversation_id'=>11,'agent_id'=>7]
        &&$binding===vp3_chat_image_reference_request_v1($pdo,$owner,$principal,$requestInput,$context),
        'private replay binding covers exact source bytes and revision plus trimmed prompt, Agent and Chat');
    $changed=base64_decode(image_fixture_response_v1('1536x1024')['data'][0]['b64_json']);
    file_put_contents($path,$changed);
    $pdo->prepare('UPDATE user_media_assets SET file_size=? WHERE id=?')->execute([strlen($changed),$asset]);
    $changedBinding=vp3_chat_image_reference_request_v1($pdo,$owner,$principal,$requestInput,$context);
    composer_check($changedBinding['asset_id']===$binding['asset_id']&&$changedBinding['sha256']!==$binding['sha256']
        &&$changedBinding['revision']!==$binding['revision'],
        'replacing valid pixels under the same asset ID invalidates the private replay binding');
    file_put_contents($path,$bytes);
    $pdo->prepare('UPDATE user_media_assets SET file_size=? WHERE id=?')->execute([strlen($bytes),$asset]);
    $changedPrompt=vp3_chat_image_reference_request_v1($pdo,$owner,$principal,
        array_replace($requestInput,['message'=>'Make this image green']),$context);
    composer_check($changedPrompt['sha256']===$binding['sha256']&&$changedPrompt['query_hash']!==$binding['query_hash']
        &&vp3_chat_image_reference_request_v1($pdo,$owner,$principal,[],$context)===null,
        'changed prompts and explicit detachment cannot match an attached-image replay binding');
    foreach([['ephemeral_protocol'=>true],['initiative_reply'=>null]] as $blocked){
        composer_reject(fn()=>vp3_chat_image_reference_context_v1($pdo,$owner,$principal,$input+$blocked,$context),
            'base images require a separate private owner turn: '.json_encode($blocked),403);
    }
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>8];
    composer_reject(fn()=>vp3_chat_image_reference_context_v1($pdo,$owner,$principal,$input,$context),
        'participant scope cannot attach the owner private base image',403);
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    composer_reject(fn()=>vp3_chat_image_reference_context_v1($pdo,$owner,$principal,$input,['conversation_id'=>12]),
        'attachment cannot use another Agent conversation',403);
    composer_reject(fn()=>vp3_chat_image_reference_context_v1($pdo,['id'=>2,'is_active'=>1],
        ['kind'=>'user_agent','owner_user_id'=>2,'agent_id'=>9],$input,['conversation_id'=>13]),
        'a different owner cannot attach this private image',404);
    $GLOBALS['imageDenied']=true;
    composer_reject(fn()=>vp3_chat_image_reference_context_v1($pdo,$owner,$principal,$input,$context),
        'revoked Chat permission invalidates an attachment',403);
    $GLOBALS['imageDenied']=false;
    composer_check(!table_exists('agent_image_generations_v1')&&empty($GLOBALS['imageUsage']),
        'parsing and attachment validation never admit a generation or record image usage');
    echo 'CHAT_IMAGE_COMPOSER=PASS ('.$checks.' assertions; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
}finally{
    if(is_dir(STONEFELLOW_ROOT)){
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
            if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());
        }
        rmdir(STONEFELLOW_ROOT);
    }
}
