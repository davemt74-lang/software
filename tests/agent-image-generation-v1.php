<?php
declare(strict_types=1);
// Optional CI MySQL run receives a fresh disposable database.
require __DIR__.'/fixtures/cloud-mission-isolated-mysql-v1.php';
if(getenv('VP3_CLOUD_SCHEDULE_DSN')){putenv('VP3_IMAGE_TEST_DSN='.getenv('VP3_CLOUD_SCHEDULE_DSN'));putenv('VP3_IMAGE_TEST_USER='.getenv('VP3_CLOUD_SCHEDULE_USER'));putenv('VP3_IMAGE_TEST_PASSWORD='.getenv('VP3_CLOUD_SCHEDULE_PASSWORD'));}
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-image-test-'.bin2hex(random_bytes(5)));
require __DIR__.'/fixtures/image-generation-v1.php';
if(($argv[1]??'')==='--race'){
    try{$g=vp3_image_queue_v1($pdo,['id'=>1,'is_active'=>1],['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7],image_fixture_plan_v1(),image_fixture_turn_v1());echo 'QUEUED';}catch(Throwable $e){echo $e->getCode()===409?'BLOCKED':'ERROR';}exit;
}
$checks=0;$calls=[];$u=['id'=>1,'is_active'=>1];$principal=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];$plan=image_fixture_plan_v1();
function check($v,$label){if(!$v)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function reject($fn,$label){try{$fn();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function queueImage($extra=[]): array{return vp3_image_queue_v1(db(),$GLOBALS['u'],$GLOBALS['principal'],array_replace($GLOBALS['plan'],$extra),image_fixture_turn_v1());}
function transportImage($url,$body,$key): array{$GLOBALS['calls'][]=[$url,$body,$key];return image_fixture_response_v1($body['size']);}
function imageCount(): int{return(int)db()->query('SELECT COUNT(*) FROM user_media_assets')->fetchColumn();}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $aid,string $surface): array
{
    return empty($GLOBALS['imageMarketingLocalOnly'])
        ? ['route'=>'vp3_cloud','cloud'=>['ready'=>true],'blocked'=>false]
        : ['route'=>'homeserver','cloud'=>['ready'=>true],'blocked'=>false];
}
function initiative_work_marketing_promotion_row_v1(PDO $pdo,int $owner,string $id,bool $lock=false): array
{
    $row=$GLOBALS['imageMarketingVariants'][$id]??null;
    if(!$row||$owner!==1)throw new DomainException('Fixture variant unavailable.',404);
    return $row;
}
function imageFixtureMarketingVariant(int $level,int $asset,string $digest): array
{
    $id=bin2hex(random_bytes(16));
    return $GLOBALS['imageMarketingVariants'][$id]=[
        'id'=>$id,'product_id'=>71,'draft_key'=>str_repeat('d',64),'source_asset_id'=>$asset,
        'source_sha256'=>$digest,'agent_id'=>7,'conversation_id'=>11,'generation_id'=>'',
        'settings'=>['level'=>$level,'headline'=>'Exact blue mug','copy'=>'A ceramic mug for daily coffee.',
            'prompt'=>'Put it on a café counter.','style'=>'clean','remove_background'=>true,'tolerance'=>24],
    ];
}
function queueMarketingFixture(array &$variant): array
{
    $generation=initiative_marketing_creative_queue_v1(db(),1,['agent_id'=>7,'conversation_id'=>11],$variant);
    $variant['generation_id']=$generation['id'];
    $GLOBALS['imageMarketingVariants'][$variant['id']]=$variant;
    return $generation;
}
try{
    $ctx=image_fixture_turn_v1();$g=vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx);
    check($g['status']==='queued'&&count($calls)===0&&imageCount()===0,'Chat queues a canonical image job without blocking on the provider');
    check(vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx)['id']===$g['id'],'same saved user turn recovers the same image request');
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'prompt'=>'Different image'],$ctx),'a consumed turn cannot silently become a different image');
    reject(fn()=>queueImage(),'one owner cannot accidentally admit a second active image request');
    reject(fn()=>vp3_image_row_v1($pdo,['id'=>2],$g['id']),'another owner cannot read the generation record');
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,$plan,['conversation_id'=>12,'chat_user_message_id'=>$ctx['chat_user_message_id']]),'an Agent cannot queue into another Agent conversation');
    $finished=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($finished['status']==='completed'&&imageCount()===1&&count($calls)===1,'one leased worker calls OpenAI and saves one actual PNG');
    check($finished['asset']['available']&&$finished['asset']['source']==='openai_image'&&str_contains($finished['asset']['url'],'media-library-file'),'the completed card references the existing private media route');
    $asset=media_studio_asset($finished['asset']['id'],$u);$meta=json_decode($asset['metadata_json'],true);check($meta['prompt']===$plan['prompt']&&$meta['usage']['total_tokens']===220&&$meta['width']===1024,'the private asset retains source prompt, model, dimensions and real usage');
    check(count($GLOBALS['imageUsage'])===1&&$GLOBALS['imageUsage'][0][5]['total_tokens']===220,'actual image-token usage reaches the existing quota accounting path');
    vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');check(count($calls)===1&&imageCount()===1,'reloading or repeated ticks never generate or save a second image');
    $run=agent_workflow_row_v1400($pdo,1,$g['job_id']);$q=$pdo->prepare('SELECT COUNT(*) FROM agent_workflow_receipts WHERE run_id=?');$q->execute([$run['id']]);check((int)$q->fetchColumn()===1,'the image has exactly one canonical durable completion receipt');
    $result=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'Desert','media_type'=>'photo'],['conversation_id'=>11]);check($result['canvas_media']['asset_ids']===[$finished['asset']['id']]&&$result['tool_receipt']['count']===1,'ordinary Cloud photo search returns saved generated-image references');
    $pdo->exec("INSERT INTO user_media_assets(user_id,media_type,title,source) VALUES(1,'photo','Uploaded capture','upload')");$uploaded=(int)$pdo->lastInsertId();
    $generated=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'','media_type'=>'photo','view'=>'generated'],['conversation_id'=>11]);check($generated['canvas_media']['asset_ids']===[$finished['asset']['id']],'generated-only searches filter provenance before the result limit');
    $uploads=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'','media_type'=>'photo','view'=>'uploaded'],['conversation_id'=>11]);check($uploads['canvas_media']['asset_ids']===[$uploaded],'uploaded-only searches do not mix in generated images');
    $pdo->prepare('UPDATE user_media_assets SET metadata_json=? WHERE id=?')->execute([json_encode(['caption'=>'Original blue mug','tags'=>['ceramic']]),$uploaded]);
    $caption=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'blue mug','media_type'=>'photo','view'=>'uploaded'],['conversation_id'=>11]);
    check($caption['canvas_media']['asset_ids']===[$uploaded]&&str_contains($caption['answer'],'matched caption'),'uploaded original can be recalled by caption with an exact asset ID and match reason');
    $tag=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'ceramic','media_type'=>'photo','view'=>'all'],['conversation_id'=>11]);
    check($tag['canvas_media']['asset_ids']===[$uploaded]&&str_contains($tag['answer'],'matched tags'),'saved media tags support exact recall');
    $pdo->exec('CREATE TABLE agent_commerce_products_v800 (owner_user_id INTEGER,title TEXT,metadata_json TEXT)');
    $pdo->prepare('INSERT INTO agent_commerce_products_v800 VALUES (?,?,?)')
        ->execute([1,'Ceramic catalog listing',json_encode(['source_asset_id'=>(string)$uploaded])]);
    $linked=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'catalog listing','media_type'=>'photo','view'=>'uploaded'],['conversation_id'=>11]);
    check($linked['canvas_media']['asset_ids']===[$uploaded]&&str_contains($linked['answer'],'matched linked product title'),'the owned product link can recall the exact uploaded image');
    $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,source) VALUES(2,'photo','SECRET foreign image','image/png','foreign.png',1,'upload')")->execute();
    $result=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'SECRET','media_type'=>'photo'],['conversation_id'=>11]);check($result['tool_receipt']['status']==='no_matches'&&!str_contains(json_encode($result),'SECRET foreign'),'private media search cannot retrieve another owner assets');
    $result=vp3_cloud_media_read_v1($pdo,$u,$principal,['query'=>'%','media_type'=>'photo'],['conversation_id'=>11]);check($result['tool_receipt']['status']==='no_matches','percent and underscore search characters are literal, not wildcard scope expansion');
    check(media_studio_assets_by_ids([$finished['asset']['id']],['id'=>2])===[],'direct canvas asset lookup retains owner authorization');
    $g=queueImage(['background'=>'transparent','size'=>'1536x1024']);vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');$body=end($calls)[1];check($body['background']==='transparent'&&$body['n']===1&&$body['size']==='1536x1024'&&$body['output_format']==='png'&&!isset($body['response_format']),'the actual request preserves transparency, selected aspect ratio and current GPT Image parameters');
    check(end($calls)[0]==='https://api.openai.com/v1/images/generations'&&!str_contains(json_encode(vp3_image_view_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']))),'fixture-image-secret'),'the key stays server-side and the provider endpoint is fixed');
    foreach(['timeout','bad_base64','bad_image','truncated_png','corrupt_crc','wrong_size','missing_usage','bad_usage'] as $mode){
        $g=queueImage();$count=imageCount();$before=count($calls);
        $transport=function($url,$body,$key)use($mode){$GLOBALS['calls'][]=[$url,$body,$key];if($mode==='timeout')throw new RuntimeException('provider_outcome_unconfirmed');$r=image_fixture_response_v1($mode==='wrong_size'?'1024x1536':$body['size']);if($mode==='bad_base64')$r['data'][0]['b64_json']='invalid!';if($mode==='bad_image')$r['data'][0]['b64_json']=base64_encode('<script>SECRET</script>');if($mode==='truncated_png')$r['data'][0]['b64_json']=base64_encode(substr(base64_decode($r['data'][0]['b64_json']),0,33));if($mode==='corrupt_crc'){$bytes=base64_decode($r['data'][0]['b64_json']);$bytes[strlen($bytes)-1]=chr(ord($bytes[strlen($bytes)-1])^1);$r['data'][0]['b64_json']=base64_encode($bytes);}if($mode==='missing_usage')unset($r['usage']);if($mode==='bad_usage')$r['usage']['total_tokens']=1;return $r;};
        $failed=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$transport);check($failed['status']==='failed'&&imageCount()===$count&&!str_contains(json_encode($failed),'SECRET'),'provider '.$mode.' cannot become a fabricated image or successful job');
        vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$transport);check(count($calls)===$before+1,'provider '.$mode.' is not automatically retried');
    }
    foreach(['cancel','reassign','deactivate','revoke'] as $mode){
        $g=queueImage();$count=imageCount();
        $transport=function($url,$body,$key)use($pdo,$u,$g,$mode){if($mode==='cancel')agent_job_cancel_v1900($pdo,$u,$g['job_id']);if($mode==='reassign')$pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=11');if($mode==='deactivate')$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');if($mode==='revoke')$GLOBALS['imageDenied']=true;return image_fixture_response_v1($body['size']);};
        try{$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$transport);}catch(Throwable $ignored){}
        $GLOBALS['imageDenied']=false;$pdo->exec('UPDATE users SET is_active=1 WHERE id=1;UPDATE chat_conversations SET user_agent_id=7 WHERE id=11');
        check(imageCount()===$count,'late '.$mode.' prevents publishing provider bytes or saving an asset');
        $v=vp3_image_view_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']));check(in_array($v['status'],['cancelled','failed'],true),'late '.$mode.' does not report a generated image');
    }
    $g=queueImage();$before=count($calls);agent_job_cancel_v1900($pdo,$u,$g['job_id']);$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');check($v['status']==='cancelled'&&count($calls)===$before,'cancellation before execution makes no paid provider call');
    $GLOBALS['lowImageQuota']=true;$g=queueImage();$before=count($calls);$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');check($v['status']==='failed'&&count($calls)===$before&&end($GLOBALS['imageReleased'])===23,'insufficient quota releases its reservation and makes no provider call');$GLOBALS['lowImageQuota']=false;
    $GLOBALS['noImageKey']=true;reject(fn()=>queueImage(),'a missing saved key prevents job admission');$GLOBALS['noImageKey']=false;
    $GLOBALS['noImageEntitlement']=true;reject(fn()=>queueImage(),'missing AI entitlement prevents paid job admission');$GLOBALS['noImageEntitlement']=false;
    $g=queueImage();$claim=agent_job_claim_run_v1900($pdo,$u,$g['job_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),300);$pdo->prepare('UPDATE agent_image_generations_v1 SET provider_started_at=? WHERE generation_id=?')->execute([gmdate('Y-m-d H:i:s'),$g['id']]);$before=count($calls);vp3_image_execute_v1($pdo,$u,$claim,'transportImage');check(count($calls)===$before,'a consumed provider attempt cannot be replayed through a new worker claim');
    $g=queueImage();$claim=agent_job_claim_run_v1900($pdo,$u,$g['job_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),300);$pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?')->execute(['2001-01-01 00:00:00',$g['job_id']]);$before=count($calls);$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');check($v['status']==='failed'&&count($calls)===$before,'abandoned single-attempt jobs recover as failed without reissuing a paid POST');
    $g=queueImage();$claim=agent_job_claim_run_v1900($pdo,$u,$g['job_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),300);$pdo->beginTransaction();agent_worker_cloud_result_v1910($pdo,$u,$claim,true,'Nested receipt fixture',['asset_id'=>123],'',false);$pdo->rollBack();check(agent_workflow_row_v1400($pdo,1,$g['job_id'])['status']==='executing','canonical result joins a caller transaction and rolls back with asset persistence');agent_job_cancel_v1900($pdo,$u,$g['job_id']);
    $g=queueImage();$pdo->prepare('UPDATE agent_workflow_runs SET agent_id=8 WHERE id=?')->execute([$g['job_id']]);$before=count($calls);reject(fn()=>vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage'),'a reassigned canonical job cannot execute its old image binding');check(count($calls)===$before,'a mismatched job binding never reaches the provider');$pdo->prepare('UPDATE agent_workflow_runs SET agent_id=7 WHERE id=?')->execute([$g['job_id']]);agent_job_cancel_v1900($pdo,$u,$g['job_id']);
    $snapshot=['capabilities'=>[['id'=>'images.generate'],['id'=>'media.assets.read']]];
    foreach([['n'=>10],['model'=>'unapproved-model'],['url'=>'https://foreign.test/'],['background'=>'auto'],['size'=>'4096x4096'],['quality'=>'max'],['prompt'=>''],['confirmed'=>true]] as $extra)reject(fn()=>vp3_capability_plan_v1(json_encode(array_replace($plan,$extra)),$snapshot),'strict model plan rejects unsupported generation fields/options');
    foreach(['asset','receipt'] as $failure){
        $g=queueImage();$before=imageCount();$files=glob(media_studio_user_root(1).'/*.png')?:[];$table=$failure==='asset'?'user_media_assets':'agent_workflow_receipts';
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql="CREATE TRIGGER image_atomic_fixture BEFORE INSERT ON $table".($failure==='receipt'?" WHEN NEW.status='completed'":'')." BEGIN SELECT RAISE(ABORT,'fixture storage failure'); END";
        else $sql="CREATE TRIGGER image_atomic_fixture BEFORE INSERT ON $table FOR EACH ROW ".($failure==='receipt'?"BEGIN IF NEW.status='completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt failure'; END IF; END":"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture storage failure'");
        $pdo->exec($sql);$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');$pdo->exec('DROP TRIGGER image_atomic_fixture');
        check($v['status']==='failed'&&imageCount()===$before&&count(glob(media_studio_user_root(1).'/*.png')?:[])===count($files),'a failed '.$failure.' commit rolls back the asset, output binding and completion receipt and removes its new file');
    }
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql)$pdo->exec('ALTER TABLE agent_image_generations_v1 MODIFY user_message_id BIGINT NOT NULL');
    $request=bin2hex(random_bytes(16));$before=count($calls);
    $conversations=(int)$pdo->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn();
    $messages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
    $g=vp3_image_queue_workspace_v1($pdo,$u,$plan,$request);
    $row=vp3_image_row_v1($pdo,$u,$g['id']);$run=vp3_image_run_v1($pdo,$u,$row);
    check(vp3_image_workspace_row_v1($row)&&$run['origin']==='media_library'&&$g['status']==='queued'&&count($calls)===$before,'Media Library queues a canonical image job under its own owner scope');
    check((int)$pdo->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn()===$conversations&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$messages,'Media Library generation creates no synthetic Chat conversation or message');
    if($mysql)check($pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agent_image_generations_v1' AND COLUMN_NAME='user_message_id'")->fetchColumn()==='YES','workspace admission is compatible with the original non-null Chat image schema');
    check(vp3_image_queue_workspace_v1($pdo,$u,$plan,$request)['id']===$g['id'],'retrying the same Media Library request recovers the exact job');
    reject(fn()=>vp3_image_queue_workspace_v1($pdo,$u,[...$plan,'prompt'=>'A different subject'],$request),'a Media Library review key cannot become a different request');
    reject(fn()=>queueImage(),'Media Library and Chat share the one-active-image-per-owner limit');
    reject(fn()=>vp3_image_view_v1($pdo,['id'=>2],$row),'a Media Library record cannot be viewed by another owner');
    $count=imageCount();$v=vp3_image_tick_v1($pdo,$u,$row,'transportImage');
    check($v['status']==='completed'&&$v['asset']['available']&&imageCount()===$count+1&&count($calls)===$before+1&&end($calls)[0]==='https://api.openai.com/v1/images/generations','the Media Library worker saves one private image via text-to-image generation and verified provider usage');
    check(vp3_image_queue_workspace_v1($pdo,$u,$plan,$request)['asset']['id']===$v['asset']['id'],'a confirmed Media Library save returns the original private asset on retry');
    $g=vp3_image_queue_workspace_v1($pdo,$u,$plan,bin2hex(random_bytes(16)));$before=count($calls);
    agent_job_cancel_v1900($pdo,$u,$g['job_id']);
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='cancelled'&&count($calls)===$before,'cancelled Media Library work makes no paid provider call');
    $g=vp3_image_queue_workspace_v1($pdo,$u,$plan,bin2hex(random_bytes(16)));
    $GLOBALS['noImageEntitlement']=true;
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='failed'&&count($calls)===$before,'the Media Library worker rechecks AI entitlement before using the provider');
    $GLOBALS['noImageEntitlement']=false;
    require_once dirname(__DIR__).'/includes/initiative-marketing-creative-v1.php';
    require_once dirname(__DIR__).'/includes/initiative-product-launch-state-v1.php';
    $pdo->exec('ALTER TABLE agent_commerce_products_v800 ADD COLUMN id INTEGER');
    $pdo->exec('UPDATE agent_commerce_products_v800 SET id=71 WHERE owner_user_id=1');
    $pdo->exec('CREATE TABLE agent_commerce_marketing_drafts_v1 (owner_user_id INTEGER,product_id INTEGER,request_key VARCHAR(64),draft_json TEXT)');
    $assetId=$finished['asset']['id'];
    $path=initiative_work_product_file_v1(1,media_studio_asset($assetId,$u)['file_path']);
    $original=file_get_contents($path);
    $digest=hash('sha256',$original);
    $pdo->prepare('INSERT INTO agent_commerce_marketing_drafts_v1 VALUES (1,71,?,?)')
        ->execute([str_repeat('d',64),json_encode(['asset_id'=>$assetId,'asset_sha256'=>$digest],JSON_THROW_ON_ERROR)]);
    $scope=['agent_id'=>7,'conversation_id'=>11];
    $variant=imageFixtureMarketingVariant(1,$assetId,$digest);
    $before=count($calls);
    reject(fn()=>initiative_marketing_creative_queue_v1($pdo,1,$scope,$variant),'level one does not admit a paid marketing image job');
    check(count($calls)===$before,'the original-photo treatment does not call the image provider');
    check(initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant)===['stage'=>'creative_ready'],
        'original-photo creative readiness needs no image job or provider call');
    $variant=imageFixtureMarketingVariant(7,$assetId,$digest);
    $g=queueMarketingFixture($variant);
    check($g['status']==='queued'&&$g['mode']==='marketing_scene'&&queueMarketingFixture($variant)['id']===$g['id'],'the exact background request queues once and retries recover the same generation');
    check(initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant)['stage']==='image_pending',
        'admission alone cannot become a launch creative completion');
    reject(fn()=>initiative_marketing_creative_generation_v1($pdo,1,['agent_id'=>8,'conversation_id'=>12],$variant),'a different Agent Chat cannot open the marketing generation');
    $other=imageFixtureMarketingVariant(7,$assetId,$digest);$other['generation_id']=$g['id'];$GLOBALS['imageMarketingVariants'][$other['id']]=$other;
    reject(fn()=>initiative_marketing_creative_generation_v1($pdo,1,$scope,$other),'a generation from a different variant cannot be substituted');
    $v=initiative_marketing_creative_generation_v1($pdo,1,$scope,$variant,true,'transportImage');
    $wire=end($calls);
    check($v['status']==='completed'&&$wire[0]==='https://api.openai.com/v1/images/generations'&&!isset($wire[1]['images'])
        &&str_contains($wire[1]['prompt'],'environment only')&&str_contains($wire[1]['prompt'],'café counter'),'a level seven background goes through the shared queue without sending the product photo');
    $imageState=initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant);
    check($imageState===['generation_id'=>$g['id'],'stage'=>'creative_ready','generated_asset_id'=>$v['asset']['id']],
        'a real completion receipt and exact private asset prove the launch creative is ready');
    reject(fn()=>initiative_product_launch_image_state_v1($pdo,$u,['agent_id'=>8,'conversation_id'=>12],$variant),
        'another Chat cannot read the launch creative proof');
    reject(fn()=>initiative_product_launch_image_state_v1($pdo,$u,$scope,$other),
        'another creative cannot reuse the launch image completion proof');
    $receiptQuery=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');
    $receiptQuery->execute([$g['job_id']]);
    $receiptJson=$receiptQuery->fetchColumn();
    $receiptQuery->closeCursor();
    $updateReceipt=$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=?');
    $updateReceipt->execute(['{}',$g['job_id']]);
    check(initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant)['stage']==='creative_failed',
        'a completed run with a changed receipt cannot announce a ready creative');
    $updateReceipt->execute([$receiptJson,$g['job_id']]);
    $creativeFile=initiative_work_product_file_v1(1,media_studio_asset($v['asset']['id'],$u)['file_path']);
    $creativeBytes=file_get_contents($creativeFile);
    $changed=$creativeBytes;
    $changed[0]="\0";
    $before=count($calls);
    try {
        file_put_contents($creativeFile,$changed);
        check(initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant)['stage']==='creative_failed',
            'changed creative bytes cannot qualify as the saved worker output');
    } finally {
        file_put_contents($creativeFile,$creativeBytes);
    }
    check(initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant)===$imageState&&count($calls)===$before,
        'rechecking the launch proof reads the same result without another provider call');
    $variant=imageFixtureMarketingVariant(10,$assetId,$digest);
    $before=count($calls);
    check(initiative_marketing_creative_recover_v1($pdo,1,$scope,$variant)===null&&count($calls)===$before,
        'status does not queue a marketing generation when its admission never committed');
    $g=initiative_marketing_creative_queue_v1($pdo,1,$scope,$variant);
    check(initiative_marketing_creative_recover_v1($pdo,1,$scope,$variant)===$g['id']&&count($calls)===$before,
        'status recovers exactly the committed unattached generation without another provider call');
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    $wire=end($calls);
    check($v['status']==='completed'&&$wire[0]==='https://api.openai.com/v1/images/edits'
        &&$wire[1]['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($original)]],
        'the canonical worker uses the exact reviewed product reference even when it claims just before the variant attaches the job');
    check(initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant)===['stage'=>'image_starting'],
        'the launch waits for the exact attachment even when the worker committed early');
    check(initiative_product_launch_image_state_v1($pdo,$u,$scope,$variant+['created_at'=>'2026-01-01 00:00:00'])===['stage'=>'creative_failed'],
        'an unattached design that has exceeded the attachment window needs owner attention');
    $variant['generation_id']=$g['id'];$GLOBALS['imageMarketingVariants'][$variant['id']]=$variant;
    check(initiative_marketing_creative_generation_v1($pdo,1,$scope,$variant)['asset']['id']===$v['asset']['id']&&file_get_contents($path)===$original,
        'the approved reference job exposes its own private candidate and leaves the original file intact');
    $variant=imageFixtureMarketingVariant(8,$assetId,$digest);$g=queueMarketingFixture($variant);
    file_put_contents($path,base64_decode(image_fixture_response_v1('32x32')['data'][0]['b64_json']));
    $before=count($calls);
    $v=initiative_marketing_creative_generation_v1($pdo,1,$scope,$variant,true,'transportImage');
    file_put_contents($path,$original);
    check($v['status']==='failed'&&count($calls)===$before&&str_contains($v['message'],'product photo changed'),
        'a modified original fails closed before any paid reference request');
    $variant=imageFixtureMarketingVariant(10,$assetId,$digest);$g=queueMarketingFixture($variant);
    $count=imageCount();$usage=count($GLOBALS['imageUsage']);
    $v=initiative_marketing_creative_generation_v1($pdo,1,$scope,$variant,true,static function($url,$body,$key)use($variant){
        $GLOBALS['imageMarketingVariants'][$variant['id']]['settings']['tolerance']=30;
        return transportImage($url,$body,$key);
    });
    check($v['status']==='failed'&&imageCount()===$count&&count($GLOBALS['imageUsage'])===$usage+1,
        'a late variant change saves no candidate and still records actual provider usage');
    $variant=imageFixtureMarketingVariant(9,$assetId,$digest);$g=queueMarketingFixture($variant);
    $GLOBALS['imageMarketingLocalOnly']=true;$before=count($calls);
    $v=initiative_marketing_creative_generation_v1($pdo,1,$scope,$variant,true,'transportImage');
    check($v['status']==='failed'&&count($calls)===$before,'a live local-only policy blocks an already queued marketing photo upload');
    $variant=imageFixtureMarketingVariant(9,$assetId,$digest);
    reject(fn()=>initiative_marketing_creative_queue_v1($pdo,1,$scope,$variant),'local-only Chat cannot admit a marketing photo upload');
    $GLOBALS['imageMarketingLocalOnly']=false;
    $variant=imageFixtureMarketingVariant(5,$assetId,$digest);$g=queueMarketingFixture($variant);
    agent_job_cancel_v1900($pdo,$u,$g['job_id']);$before=count($calls);
    $v=initiative_marketing_creative_generation_v1($pdo,1,$scope,$variant,true,'transportImage');
    check($v['status']==='cancelled'&&count($calls)===$before,'standard Job Control cancels reviewed marketing work before a provider call');
    if($mysql){
        $running=[];for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,__FILE__,'--race'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$running[]=[$p,$pipes];}
        $results=[];foreach($running as [$p,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);}
        sort($results);check($results===['BLOCKED','QUEUED'],'concurrent MySQL admission permits exactly one active image per owner');
        $q=$pdo->query("SELECT g.run_id FROM agent_image_generations_v1 g JOIN agent_workflow_runs r ON r.id=g.run_id WHERE r.status='approved'");foreach($q->fetchAll() as $r)agent_job_cancel_v1900($pdo,$u,(int)$r['run_id']);
    }
    echo 'IMAGE_GENERATION_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
}finally{
    if(is_dir(STONEFELLOW_ROOT)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir(STONEFELLOW_ROOT);}
}
