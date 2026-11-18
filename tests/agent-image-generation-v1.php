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
function transportImage($url,$body,$key): array{$GLOBALS['calls'][]=[$url,$body,$key];return image_fixture_response_v1($body['size'],$body['n']);}
function imageCount(): int{return(int)db()->query('SELECT COUNT(*) FROM user_media_assets')->fetchColumn();}
function ai_decrypt_secret(string $encrypted): string{return str_starts_with($encrypted,'fixture-cipher:')?substr($encrypted,15):'';}
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
    check($g['status']==='queued'&&$g['active_lease_expires_at']===null&&count($calls)===0&&imageCount()===0,'Chat queues a canonical image job without blocking on the provider or projecting active work');
    check(vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx)['id']===$g['id'],'same saved user turn recovers the same image request');
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'prompt'=>'Different image'],$ctx),'a consumed turn cannot silently become a different image');
    reject(fn()=>queueImage(),'one owner cannot accidentally admit a second active image request');
    reject(fn()=>vp3_image_row_v1($pdo,['id'=>2],$g['id']),'another owner cannot read the generation record');
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,$plan,['conversation_id'=>12,'chat_user_message_id'=>$ctx['chat_user_message_id']]),'an Agent cannot queue into another Agent conversation');
    $finished=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($finished['status']==='completed'&&$finished['active_lease_expires_at']===null&&imageCount()===1&&count($calls)===1,'one leased worker calls OpenAI, saves one actual PNG and clears its lease projection');
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
    $g=queueImage();$claim=agent_job_claim_run_v1900($pdo,$u,$g['job_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),300);
    $v=vp3_image_view_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']));
    check($v['status']==='generating'&&$v['active_lease_expires_at']===strtotime($claim['lease_expires_at'].' UTC')*1000
        &&!str_contains(json_encode($v),$claim['lease_token']),'an executing image projects only the active lease expiry');
    $pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?')->execute(['2001-01-01 00:00:00',$g['job_id']]);
    $v=vp3_image_view_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']));
    check($v['status']==='generating'&&$v['active_lease_expires_at']===null,'an expired lease clears the private work signal before ordinary recovery');
    $before=count($calls);$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');check($v['status']==='failed'&&count($calls)===$before,'abandoned single-attempt jobs recover as failed without reissuing a paid POST');
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
    foreach([0,5,'2',2.5,true,null] as $invalid){
        $before=count($calls);
        reject(fn()=>queueImage(['count'=>$invalid]),'image count rejects '.json_encode($invalid).' before admission');
        check(count($calls)===$before,'an invalid image count makes no provider request');
    }
    $ctx=image_fixture_turn_v1();$g=vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'count'=>4],$ctx);
    check($g['count']===4&&$g['completed_count']===0&&$g['assets']===[]&&$g['provider_started_at']===null,'a four-image request projects one queued batch with no invented progress');
    $before=count($calls);$beforeAssets=imageCount();$beforeUsage=count($GLOBALS['imageUsage']);
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');$batchIds=array_column($v['assets'],'id');
    check($v['status']==='completed'&&$v['count']===4&&$v['completed_count']===4&&count(array_unique($batchIds))===4
        &&$v['asset']['id']===$batchIds[0]&&imageCount()===$beforeAssets+4&&count($calls)===$before+1&&end($calls)[1]['n']===4,
        'one canonical worker makes one paid POST and saves four ordered private images');
    check(end($GLOBALS['imagePreflight'])[3]===64000&&count($GLOBALS['imageUsage'])===$beforeUsage+1
        &&end($GLOBALS['imageUsage'])[5]['total_tokens']===820,'batch quota covers all four outputs and actual aggregate usage is committed once');
    check(is_string($v['provider_started_at'])&&preg_match('/\A\d{4}-\d{2}-\d{2}T.*\+00:00\z/',$v['provider_started_at'])===1,
        'elapsed generation time uses an actual UTC provider dispatch timestamp');
    $q=$pdo->prepare("SELECT result_json FROM agent_workflow_receipts WHERE run_id=? AND status='completed'");$q->execute([$g['job_id']]);$receipts=$q->fetchAll(PDO::FETCH_COLUMN);
    $batchReceipt=json_decode($receipts[0],true);
    check(count($receipts)===1&&$batchReceipt['image_asset_ids']===$batchIds&&(int)$batchReceipt['asset_id']===$batchIds[0]
        &&(int)$batchReceipt['image_count']===4,'all batch assets share one exact canonical completion receipt');
    foreach([[],[1,2,3,4,5],['1'],[0],[-1],[1,1],[1,true],[1,2.5],['first'=>1],'[1,2]'] as $invalid){
        $safe=agent_workflow_public_json_v1400(json_encode(['image_asset_ids'=>$invalid,'api_token'=>'secret','other_array'=>[1]]));
        check(!isset($safe['image_asset_ids'])&&!isset($safe['api_token'])&&!isset($safe['other_array']),
            'receipt image IDs reject malformed '.json_encode($invalid).' while retaining existing redaction');
    }
    $again=vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'count'=>4],$ctx);vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($again['id']===$g['id']&&array_column($again['assets'],'id')===$batchIds&&count($calls)===$before+1,
        'batch history reload and same-turn recovery preserve every image without another provider attempt');
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'count'=>2],$ctx),'a consumed batch turn cannot change its image count');
    require_once dirname(__DIR__).'/includes/agent-image-reference-v1.php';
    $originalBatch=[];
    foreach($batchIds as $id){
        $saved=media_studio_asset($id,$u);
        $originalBatch[$id]=['asset'=>$saved,'bytes'=>file_get_contents(STONEFELLOW_ROOT.'/'.$saved['file_path'])];
    }
    check(count(array_unique(array_map(static fn(array $item):string=>hash('sha256',$item['bytes']),$originalBatch)))===4,
        'the generated batch has four distinct saved pixel sources for exact follow-up selection');
    $third=$batchIds[2];$thirdBytes=$originalBatch[$third]['bytes'];
    $editContext=image_fixture_turn_v1();$editContext['image_reference_asset_id']=$third;
    $thirdReference=vp3_image_reference_validate_v1($pdo,$u,$principal,$editContext,$third);
    check($thirdReference['asset_id']===$third&&$thirdReference['sha256']===hash('sha256',$thirdBytes),
        'choosing the third generated output validates that exact saved asset and bytes');
    $followupPlan=[...$plan,'prompt'=>'Make the background blue and keep everything else the same.','title'=>'Blue background','count'=>2];
    $followup=vp3_image_queue_v1($pdo,$u,$principal,$followupPlan,$editContext);
    $followupRow=vp3_image_row_v1($pdo,$u,$followup['id']);
    $frozen=json_decode($followupRow['config_json'],true);
    check($frozen['reference']['asset_id']===$third&&$frozen['reference']['revision']===$thirdReference['revision']
        &&$frozen['reference']['sha256']===$thirdReference['sha256'],
        'a follow-up job freezes the selected batch output identity, revision and content hash');
    $before=count($calls);$beforeAssets=imageCount();
    $edited=vp3_image_tick_v1($pdo,$u,$followupRow,'transportImage');$wire=end($calls);
    check($edited['status']==='completed'&&$edited['reference_asset_id']===$third&&$edited['count']===2
        &&imageCount()===$beforeAssets+2&&count($calls)===$before+1
        &&$wire[0]==='https://api.openai.com/v1/images/edits'&&$wire[1]['n']===2
        &&$wire[1]['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($thirdBytes)]],
        'editing image three sends only image three pixels and saves the requested new batch');
    foreach($originalBatch as $id=>$saved){
        check(file_get_contents(STONEFELLOW_ROOT.'/'.$saved['asset']['file_path'])===$saved['bytes']
            &&media_studio_asset($id,$u)===$saved['asset'],
            'a follow-up edit preserves the original batch asset and file '.$id);
    }
    $replayed=vp3_image_queue_v1($pdo,$u,$principal,$followupPlan,$editContext);
    check($replayed['id']===$edited['id']&&array_column($replayed['assets'],'id')===array_column($edited['assets'],'id')
        &&count($calls)===$before+1,'a completed follow-up edit replays its saved results without generating again');
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,$followupPlan,[...$editContext,'image_reference_asset_id'=>$batchIds[0]]),
        'replaying the same edit turn cannot silently replace image three with image one');
    $nextBase=$edited['assets'][1]['id'];$nextSaved=media_studio_asset($nextBase,$u);
    $nextContext=image_fixture_turn_v1();$nextContext['image_reference_asset_id']=$nextBase;
    $next=vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'prompt'=>'Remove the background.','background'=>'transparent'],$nextContext);
    $next=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$next['id']),'transportImage');
    check($next['status']==='completed'&&$next['reference_asset_id']===$nextBase
        &&end($calls)[1]['images']===[['image_url'=>'data:image/png;base64,'.base64_encode(file_get_contents(STONEFELLOW_ROOT.'/'.$nextSaved['file_path']))]]
        &&end($calls)[1]['background']==='transparent',
        'a newly edited result can itself become the exact base for the next transparent edit');
    foreach(['short_batch','malformed_last'] as $failure){
        $g=queueImage(['count'=>3]);$before=count($calls);$beforeAssets=imageCount();$beforeUsage=count($GLOBALS['imageUsage']);
        $files=glob(media_studio_user_root(1).'/*.png')?:[];
        $badBatch=static function($url,$body,$key)use($failure):array{
            $GLOBALS['calls'][]=[$url,$body,$key];$result=image_fixture_response_v1($body['size'],$body['n']);
            if($failure==='short_batch')array_pop($result['data']);else $result['data'][2]['b64_json']='broken!';
            return $result;
        };
        $failed=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$badBatch);
        check($failed['status']==='failed'&&$failed['assets']===[]&&$failed['completed_count']===0&&imageCount()===$beforeAssets
            &&count(glob(media_studio_user_root(1).'/*.png')?:[])===count($files),'a '.$failure.' response cannot publish a partial batch');
        check(count($GLOBALS['imageUsage'])===$beforeUsage+1&&end($GLOBALS['imageUsage'])[5]['total_tokens']===620,
            'a '.$failure.' response still records confirmed paid token usage');
        vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$badBatch);
        check(count($calls)===$before+1,'a '.$failure.' response never replays paid generation');
    }
    foreach(['second_asset','batch_receipt'] as $failure){
        $g=queueImage(['count'=>3]);$beforeAssets=imageCount();$files=glob(media_studio_user_root(1).'/*.png')?:[];
        $table=$failure==='second_asset'?'user_media_assets':'agent_workflow_receipts';
        $condition=$failure==='second_asset'?"NEW.title LIKE '%2 of 3'":"NEW.status='completed'";
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ?"CREATE TRIGGER image_batch_atomic_fixture BEFORE INSERT ON $table WHEN $condition BEGIN SELECT RAISE(ABORT,'batch storage failure'); END"
            :"CREATE TRIGGER image_batch_atomic_fixture BEFORE INSERT ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='batch storage failure'; END IF; END";
        $pdo->exec($sql);$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');$pdo->exec('DROP TRIGGER image_batch_atomic_fixture');
        $q=$pdo->prepare('SELECT COUNT(*) FROM agent_image_generation_assets_v1 WHERE generation_id=?');$q->execute([$g['id']]);
        check($v['status']==='failed'&&imageCount()===$beforeAssets&&(int)$q->fetchColumn()===0
            &&count(glob(media_studio_user_root(1).'/*.png')?:[])===count($files),'a '.$failure.' failure rolls back the entire batch and every new file');
    }
    $legacyContext=image_fixture_turn_v1();$legacy=vp3_image_queue_v1($pdo,$u,$principal,$plan,$legacyContext);
    $legacyRow=vp3_image_row_v1($pdo,$u,$legacy['id']);$legacyConfig=json_decode($legacyRow['config_json'],true);unset($legacyConfig['count']);
    $pdo->prepare('UPDATE agent_image_generations_v1 SET config_json=?,request_hash=? WHERE generation_id=?')->execute([
        json_encode($legacyConfig),hash('sha256',json_encode([11,7,$legacyConfig],JSON_THROW_ON_ERROR)),$legacy['id']]);
    $before=count($calls);$recovered=vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'count'=>1],$legacyContext);
    check($recovered['id']===$legacy['id']&&$recovered['count']===1&&count($calls)===$before,
        'a pre-upgrade frozen Chat config without count recovers the exact job with no new charge');
    agent_job_cancel_v1900($pdo,$u,$legacy['job_id']);
    require_once dirname(__DIR__).'/includes/agent-image-reference-v1.php';
    $referenceBytes=base64_decode(image_fixture_response_v1()['data'][0]['b64_json']);
    $referencePath=media_studio_ensure_user_root(1).'/uploaded-base.png';file_put_contents($referencePath,$referenceBytes);
    $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at) VALUES(1,'photo','Uploaded base','image/png','private/user-media/1/uploaded-base.png',?,0,'upload','{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([strlen($referenceBytes)]);
    $referenceAsset=(int)$pdo->lastInsertId();$ctx=image_fixture_turn_v1();$ctx['image_reference_asset_id']=$referenceAsset;
    $reference=vp3_image_reference_validate_v1($pdo,$u,$principal,$ctx,$referenceAsset);
    check($reference['asset_id']===$referenceAsset&&$reference['sha256']===hash('sha256',$referenceBytes)
        &&$reference['width']===1024&&!isset($reference['bytes']),'attachment validation returns private identity and dimensions without exposing pixels');
    reject(fn()=>vp3_image_reference_validate_v1($pdo,['id'=>2,'is_active'=>1],['kind'=>'user_agent','owner_user_id'=>2,'agent_id'=>9],['conversation_id'=>13],$referenceAsset),
        'another owner cannot attach a private base image');
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,['conversation_id'=>12],$referenceAsset),
        'an attachment cannot cross into another Agent conversation');
    $before=count($calls);$beforeAssets=imageCount();$g=vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'count'=>2],$ctx);
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');$wire=end($calls);
    check($v['status']==='completed'&&$v['count']===2&&$v['reference_asset_id']===$referenceAsset
        &&$v['reference_asset']['id']===$referenceAsset&&imageCount()===$beforeAssets+2&&count($calls)===$before+1
        &&$wire[0]==='https://api.openai.com/v1/images/edits'&&$wire[1]['n']===2
        &&$wire[1]['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($referenceBytes)]],
        'an attached base image generates two private edited results with one exact source-bound POST');
    check(file_get_contents($referencePath)===$referenceBytes&&media_studio_asset($referenceAsset,$u)['source']==='upload',
        'base-image generation preserves the original uploaded asset and file');
    $again=vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'count'=>2],$ctx);
    check($again['id']===$g['id']&&count($calls)===$before+1,'the exact base-image turn is replay safe after completion');
    foreach(['before_send','during_provider','local_policy'] as $change){
        file_put_contents($referencePath,$referenceBytes);$ctx=image_fixture_turn_v1();$ctx['image_reference_asset_id']=$referenceAsset;
        $g=vp3_image_queue_v1($pdo,$u,$principal,[...$plan,'count'=>2],$ctx);$before=count($calls);$beforeAssets=imageCount();
        if($change==='before_send')file_put_contents($referencePath,$referenceBytes.'changed');
        $sourceRace=static function($url,$body,$key)use($change,$referencePath,$referenceBytes):array{
            $GLOBALS['calls'][]=[$url,$body,$key];
            if($change==='during_provider')file_put_contents($referencePath,$referenceBytes.'changed');
            if($change==='local_policy')$GLOBALS['imageMarketingLocalOnly']=true;
            return image_fixture_response_v1($body['size'],$body['n']);
        };
        $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$sourceRace);$GLOBALS['imageMarketingLocalOnly']=false;
        check($v['status']==='failed'&&$v['assets']===[]&&imageCount()===$beforeAssets
            &&count($calls)===$before+($change==='before_send'?0:1),'a base-image '.$change.' change prevents publishing any edited result');
    }
    file_put_contents($referencePath,$referenceBytes);$GLOBALS['imageMarketingLocalOnly']=true;
    $ctx=image_fixture_turn_v1();$ctx['image_reference_asset_id']=$referenceAsset;$before=count($calls);
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx),'a local-only Agent cannot admit a base-image upload to the provider');
    check(count($calls)===$before,'a blocked base-image route sends no pixels');$GLOBALS['imageMarketingLocalOnly']=false;
    foreach(['invalid raster'=>'not an image','oversized file'=>str_repeat('x',4*1024*1024+1),
        'oversized dimensions'=>base64_decode(image_fixture_response_v1('4112x16')['data'][0]['b64_json'])] as $invalid=>$bytes){
        file_put_contents($referencePath,$bytes);
        $pdo->prepare('UPDATE user_media_assets SET file_size=? WHERE id=?')->execute([strlen($bytes),$referenceAsset]);
        reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$ctx,$referenceAsset),
            'an '.$invalid.' base image fails attachment validation before dispatch');
    }
    file_put_contents($referencePath,$referenceBytes);
    $foreignPath=media_studio_ensure_user_root(2).'/foreign-base.png';file_put_contents($foreignPath,$referenceBytes);
    $pdo->prepare('UPDATE user_media_assets SET file_path=?,file_size=? WHERE id=?')
        ->execute(['private/user-media/2/foreign-base.png',strlen($referenceBytes),$referenceAsset]);
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$ctx,$referenceAsset),
        'an owned media row cannot attach bytes from another owner private directory');
    $pdo->prepare('UPDATE user_media_assets SET file_path=? WHERE id=?')
        ->execute(['private/user-media/1/uploaded-base.png',$referenceAsset]);
    check(count($calls)===$before,'invalid reference files never reach the paid image provider');
    $smallSource=vp3_image_reference_source_v1($pdo,$u,$referenceAsset);$smallSaved=media_studio_asset($referenceAsset,$u);
    check(!isset($smallSource['generated_output'])&&$smallSource['revision']===hash('sha256',json_encode([
        1,$referenceAsset,$smallSaved['file_path'],$smallSaved['mime_type'],(int)$smallSaved['file_size'],
        (string)$smallSaved['updated_at'],hash('sha256',$referenceBytes)],JSON_THROW_ON_ERROR)),
        'ordinary four-MiB references retain the exact pre-existing frozen revision format');

    $largeResponse=image_fixture_response_v1();$largePng=base64_decode($largeResponse['data'][0]['b64_json']);
    $padding='Fixture'."\0".str_repeat('x',4*1024*1024);
    $largeChunk=pack('N',strlen($padding)).'tEXt'.$padding.pack('N',crc32('tEXt'.$padding));
    $largePng=substr($largePng,0,-12).$largeChunk.substr($largePng,-12);
    $largeResponse['data'][0]['b64_json']=base64_encode($largePng);unset($padding,$largeChunk);
    $largeGeneration=queueImage();
    $largeView=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$largeGeneration['id']),
        static function($url,$body,$key)use($largeResponse):array{$GLOBALS['calls'][]=[$url,$body,$key];return $largeResponse;});
    $largeAssetId=$largeView['asset']['id'];$largeAsset=media_studio_asset($largeAssetId,$u);
    $largePath=STONEFELLOW_ROOT.'/'.$largeAsset['file_path'];$largeMetadata=json_decode($largeAsset['metadata_json'],true);
    $largeContext=image_fixture_turn_v1();$largeContext['image_reference_asset_id']=$largeAssetId;
    $before=count($calls);$largeSource=vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$largeAssetId);
    check($largeView['status']==='completed'&&strlen($largePng)>4*1024*1024
        &&$largeSource['generated_output']===['generation_id'=>$largeGeneration['id'],'sequence_no'=>1]
        &&$largeSource['sha256']===hash('sha256',$largePng)&&!isset($largeSource['bytes'])&&count($calls)===$before,
        'a canonical generated PNG over four MiB can become an exact validated base without a provider call');
    $largeEdit=vp3_image_queue_v1($pdo,$u,$principal,$plan,$largeContext);
    $largeEditRow=vp3_image_row_v1($pdo,$u,$largeEdit['id']);
    check(json_decode($largeEditRow['config_json'],true)['reference']['generated_output']===$largeSource['generated_output'],
        'the extended allowance is frozen from server-side generated-output provenance');
    $largeEdited=vp3_image_tick_v1($pdo,$u,$largeEditRow,'transportImage');
    check($largeEdited['status']==='completed'&&$largeEdited['reference_asset_id']===$largeAssetId
        &&end($calls)[1]['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($largePng)]]
        &&count($calls)===$before+1&&file_get_contents($largePath)===$largePng,
        'a verified large generated result is sent byte-for-byte as the next edit base and remains unchanged');
    $before=count($calls);
    $updateLarge=static function(array $changes)use($pdo,$largeAssetId):void{
        foreach($changes as $field=>$value)$pdo->prepare('UPDATE user_media_assets SET '.$field.'=? WHERE id=?')->execute([$value,$largeAssetId]);
    };
    foreach([
        'ordinary upload'=>['source'=>'upload'],
        'forged source metadata'=>['metadata_json'=>json_encode([...$largeMetadata,'generation_id'=>'11111111-2222-3333-4444-555555555555'])],
        'incorrect output index'=>['metadata_json'=>json_encode([...$largeMetadata,'generation_index'=>2])],
        'incorrect dimensions'=>['metadata_json'=>json_encode([...$largeMetadata,'width'=>1536])],
        'incorrect saved hash'=>['metadata_json'=>json_encode([...$largeMetadata,'sha256'=>str_repeat('0',64)])],
        'over-ceiling record'=>['file_size'=>vp3_image_output_bytes_limit_v1()+1],
    ] as $reason=>$changes){
        $updateLarge($changes);
        reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$largeAssetId),
            'the generated-input allowance rejects an '.$reason);
        $updateLarge(['source'=>$largeAsset['source'],'metadata_json'=>$largeAsset['metadata_json'],'file_size'=>$largeAsset['file_size']]);
    }
    $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at) VALUES(1,'photo','Forged generated label','image/png',?,?,0,'openai_image',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$largeAsset['file_path'],$largeAsset['file_size'],$largeAsset['metadata_json']]);
    $forgedId=(int)$pdo->lastInsertId();
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$forgedId),
        'copying generated source metadata to an unbound owner asset cannot raise its input limit');
    $pdo->prepare('DELETE FROM user_media_assets WHERE id=?')->execute([$forgedId]);
    $removeLargeBinding=static fn()=> $pdo->prepare('DELETE FROM agent_image_generation_assets_v1 WHERE generation_id=? AND owner_user_id=1 AND asset_id=?')->execute([$largeGeneration['id'],$largeAssetId]);
    $restoreLargeBinding=static fn()=> $pdo->prepare('INSERT INTO agent_image_generation_assets_v1(generation_id,owner_user_id,sequence_no,asset_id) VALUES(?,1,1,?)')->execute([$largeGeneration['id'],$largeAssetId]);
    $removeLargeBinding();
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$largeAssetId),
        'missing authoritative output membership cannot fall back to editable generation metadata');
    reject(fn()=>vp3_image_queue_v1($pdo,$u,$principal,$plan,$largeContext),
        'a completed edit replay cannot return its old result after the generated-source proof is removed');
    $restoreLargeBinding();
    $pdo->prepare("UPDATE agent_workflow_runs SET status='failed' WHERE id=?")->execute([$largeGeneration['job_id']]);
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$largeAssetId),
        'only a completed authoritative generation grants the larger source allowance');
    $pdo->prepare("UPDATE agent_workflow_runs SET status='completed' WHERE id=?")->execute([$largeGeneration['job_id']]);
    reject(fn()=>vp3_image_reference_validate_v1($pdo,['id'=>2,'is_active'=>1],['kind'=>'user_agent','owner_user_id'=>2,'agent_id'=>9],['conversation_id'=>13],$largeAssetId),
        'large generated sources retain exact owner isolation');
    $GLOBALS['imageDenied']=true;
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$largeAssetId),
        'revoked Chat permission blocks large generated-source attachment');$GLOBALS['imageDenied']=false;
    rename($largePath,$largePath.'.unavailable');
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$largeAssetId),
        'an unavailable generated file cannot be attached');rename($largePath.'.unavailable',$largePath);
    $modifiedLarge=str_replace('Fixture'."\0",'Changed'."\0",$largePng);
    file_put_contents($largePath,$modifiedLarge);
    reject(fn()=>vp3_image_reference_validate_v1($pdo,$u,$principal,$largeContext,$largeAssetId),
        'changed generated bytes cannot reuse the stored output proof');file_put_contents($largePath,$largePng);unset($modifiedLarge);
    check(count($calls)===$before,'all invalid large-source selections and replays make no provider attempt');
    foreach(['remove_proof','change_proof','remove_binding'] as $race){
        $editContext=image_fixture_turn_v1();$editContext['image_reference_asset_id']=$largeAssetId;
        $edit=vp3_image_queue_v1($pdo,$u,$principal,$plan,$editContext);$row=vp3_image_row_v1($pdo,$u,$edit['id']);
        if($race==='remove_binding')$removeLargeBinding();
        else{
            $config=json_decode($row['config_json'],true);
            if($race==='remove_proof')unset($config['reference']['generated_output']);
            else $config['reference']['generated_output']['sequence_no']=2;
            $pdo->prepare('UPDATE agent_image_generations_v1 SET config_json=? WHERE generation_id=?')->execute([json_encode($config),$edit['id']]);
        }
        $before=count($calls);$failed=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$edit['id']),'transportImage');
        if($race==='remove_binding')$restoreLargeBinding();
        check($failed['status']==='failed'&&count($calls)===$before,
            'a large-source '.$race.' change fails closed before paid dispatch');
    }
    $editContext=image_fixture_turn_v1();$editContext['image_reference_asset_id']=$largeAssetId;
    $edit=vp3_image_queue_v1($pdo,$u,$principal,$plan,$editContext);$beforeAssets=imageCount();$before=count($calls);
    $failed=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$edit['id']),static function($url,$body,$key)use($removeLargeBinding):array{
        $GLOBALS['calls'][]=[$url,$body,$key];$removeLargeBinding();return image_fixture_response_v1($body['size'],$body['n']);
    });$restoreLargeBinding();
    check($failed['status']==='failed'&&$failed['assets']===[]&&imageCount()===$beforeAssets&&count($calls)===$before+1,
        'generated-source provenance is revalidated after the provider and before publishing edited pixels');
    unset($largePng,$largeResponse,$largeSource,$largeAsset,$largeMetadata,$largeEdited,$largeView);
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
    check(vp3_image_queue_workspace_v1($pdo,$u,[...$plan,'count'=>1],$request)['id']===$g['id']&&count($calls)===$before,
        'a pre-upgrade Media Library plan without count accepts an explicit default without a new job or charge');
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
        ->execute([str_repeat('d',64),json_encode(['product_id'=>71,'asset_id'=>$assetId,'asset_sha256'=>$digest],JSON_THROW_ON_ERROR)]);
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
    $replaceConfig=static function(array $row,array $config)use($pdo):void{
        $hash=hash('sha256',json_encode([(int)$row['conversation_id'],(int)$row['agent_id'],$config],JSON_THROW_ON_ERROR));
        $pdo->prepare('UPDATE agent_image_generations_v1 SET config_json=?,request_hash=? WHERE generation_id=?')
            ->execute([json_encode($config,JSON_THROW_ON_ERROR),$hash,$row['generation_id']]);
    };
    $ctx=image_fixture_turn_v1();$g=vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx);
    $row=vp3_image_row_v1($pdo,$u,$g['id']);$oldConfig=json_decode($row['config_json'],true,16,JSON_THROW_ON_ERROR);
    unset($oldConfig['funding_source']);$replaceConfig($row,$oldConfig);
    check(vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx)['id']===$g['id'],'a queued pre-upgrade legacy request retains its original idempotency hash');
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='completed'&&end($calls)[2]==='fixture-image-secret','a pre-upgrade legacy image can still dispatch with the same site funding and model');
    $pdo->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT)');
    vp3_ai_service_save_model_v1($pdo,'openai','gpt-image-fixture-exact','Fixture image model',['image_generation']);
    $primary=['provider'=>'openai','model'=>'gpt-image-fixture-exact'];
    $fallback=['provider'=>'openai','model'=>'gpt-image-1-mini'];
    vp3_ai_service_save_route_v1($pdo,'image_generation',$primary,$fallback);
    $before=count($calls);
    reject(fn()=>queueImage(),'configured image routes require the service usage database upgrade');
    check(count($calls)===$before,'a missing service ledger cannot bypass image metering');
    if($mysql){
        require_once dirname(__DIR__).'/includes/ai-usage-accounting-v032.php';
        ai_usage_accounting_v032_ensure_schema($pdo);
    }else{
        $pdo->exec("CREATE TABLE ai_execution_ledger (
            id INTEGER PRIMARY KEY, user_id INTEGER, agent_id INTEGER, conversation_id INTEGER, run_id INTEGER,
            trace_id TEXT, source TEXT, provider TEXT, model TEXT, created_at TEXT,
            input_tokens INTEGER, output_tokens INTEGER, total_tokens INTEGER, latency_ms INTEGER,
            estimated_cost_micros INTEGER, cost_rate_source TEXT, cloud_ledger_id INTEGER, cloud_tokens_charged INTEGER DEFAULT 0, failure_class TEXT
        )");
    }
    $pdo->exec('CREATE TABLE ai_token_reservations (id INTEGER PRIMARY KEY,user_id INTEGER)');
    vp3_ai_service_usage_ensure_schema_v1($pdo);
    $meter=$pdo->prepare('SELECT * FROM ai_execution_ledger WHERE service_key=\'image_generation\' AND run_id=?');
    $usageCount=count($GLOBALS['imageUsage']);
    $g=queueImage();
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='completed'&&end($calls)[1]['model']===$primary['model']&&end($calls)[2]==='fixture-image-secret',
        'Chat image work dispatches the registered exact service model with the approved site key');
    check($entry['service_status']==='completed'&&(int)$entry['user_id']===1&&(int)$entry['agent_id']===7
        &&(int)$entry['conversation_id']===11&&(int)$entry['total_tokens']===220&&(int)$entry['usage_units']===1
        &&$entry['credential_source']==='system'&&count($GLOBALS['imageUsage'])===$usageCount+1,
        'one image attempt records owner, Agent, Chat, exact funding, verified tokens and one subscription settlement');
    $before=count($calls);$usageCount=count($GLOBALS['imageUsage']);$g=queueImage(['count'=>3]);
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    $meter->execute([$g['job_id']]);$entries=$meter->fetchAll();$meter->closeCursor();$entry=$entries[0]??[];
    check($v['status']==='completed'&&count($calls)===$before+1&&count($entries)===1
        &&$entry['service_status']==='completed'&&(int)$entry['user_id']===1&&(int)$entry['agent_id']===7
        &&(int)$entry['conversation_id']===11&&(int)$entry['total_tokens']===620&&(int)$entry['usage_units']===3
        &&count($GLOBALS['imageUsage'])===$usageCount+1,
        'a three-image batch meters three confirmed image units and aggregate tokens in exactly one attempt and settlement');
    $g=queueImage();$row=vp3_image_row_v1($pdo,$u,$g['id']);$oldConfig=json_decode($row['config_json'],true,16,JSON_THROW_ON_ERROR);
    unset($oldConfig['funding_source']);$oldConfig['provider_route']['funding']='system';$replaceConfig($row,$oldConfig);
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='completed'&&end($calls)[1]['model']===$primary['model']&&end($calls)[2]==='fixture-image-secret',
        'a pre-upgrade configured site job can still dispatch with the same exact route and funding');
    vp3_ai_service_save_rule_v1($pdo,'user',1,'image_generation',['allowed_models'=>['openai:'.$fallback['model']]]);
    $g=queueImage();
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='completed'&&end($calls)[1]['model']===$fallback['model'],
        'an explicitly configured eligible fallback can be selected before image dispatch');
    vp3_ai_service_save_rule_v1($pdo,'user',1,'image_generation',[]);
    $ctx=image_fixture_turn_v1();$g=vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx);$before=count($calls);
    vp3_ai_service_save_route_v1($pdo,'image_generation',$fallback,null);
    check(vp3_image_queue_v1($pdo,$u,$principal,$plan,$ctx)['id']===$g['id'],'a saved Chat turn retains its original job after the admin changes routes');
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before,'a queued image cannot silently switch to a different model or funding route');
    $g=queueImage();
    vp3_ai_service_save_route_v1($pdo,'image_generation',null,null);
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before,'clearing a configured route stops a job admitted under that route');
    vp3_ai_service_save_route_v1($pdo,'image_generation',$fallback,null);
    vp3_ai_service_save_rule_v1($pdo,'user',1,'image_generation',['job_tokens'=>500]);
    $g=queueImage();$v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before&&$entry===false,
        'a per-job token ceiling below the image allowance blocks dispatch and releases the unused reservation');
    vp3_ai_service_save_rule_v1($pdo,'user',1,'image_generation',[]);
    $g=queueImage();$assetCount=imageCount();
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),static function($url,$body,$key){
        $response=transportImage($url,$body,$key);$response['data'][0]['b64_json']='invalid';return $response;
    });
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='failed'&&imageCount()===$assetCount&&$entry['service_status']==='failed'&&(int)$entry['total_tokens']===220,
        'invalid image bytes stay unpublished while verifiable paid usage is recorded');
    $g=queueImage();$before=count($calls);
    $timeout=static function($url,$body,$key){$GLOBALS['calls'][]=[$url,$body,$key];throw new RuntimeException('provider_outcome_unconfirmed');};
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$timeout);
    vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),$timeout);
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='failed'&&$v['provider_started']&&count($calls)===$before+1&&$entry['service_status']==='unknown'
        &&(int)$entry['usage_known']===0&&$entry['usage_units']===null&&(int)$entry['service_budget_tokens']>=16000,
        'unknown image outcomes keep the conservative budget and never fabricate usage or replay the paid call');
    $g=queueImage();
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),static function(){throw new RuntimeException('provider_access_denied');});
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='failed'&&$v['provider_started']&&$entry['service_status']==='rejected'&&(int)$entry['service_budget_tokens']===0,
        'a definitive provider refusal keeps an attempt record and frees the reserved service allowance');
    $pdo->exec('CREATE TABLE user_llm_credentials (user_id INTEGER,provider VARCHAR(32),encrypted_key TEXT,model VARCHAR(160),PRIMARY KEY(user_id,provider))');
    $pdo->exec('CREATE TABLE user_llm_preferences (user_id INTEGER PRIMARY KEY,route VARCHAR(24),provider VARCHAR(32))');
    $pdo->exec("INSERT INTO user_llm_preferences VALUES (1,'own_key','anthropic')");
    $keys=$pdo->prepare('INSERT INTO user_llm_credentials VALUES (?,?,?,?)');
    $keys->execute([1,'anthropic','fixture-cipher:fixture-owner-anthropic-key','claude-haiku-5-5']);
    $keys->execute([2,'openai','fixture-cipher:fixture-foreign-personal-key','gpt-5.6-luna']);
    vp3_ai_service_save_route_v1($pdo,'image_generation',$primary,$fallback);
    $usageCount=count($GLOBALS['imageUsage']);$preflightCount=count($GLOBALS['imagePreflight']);
    $before=count($calls);
    reject(fn()=>queueImage(),'personal funding requires the current owner’s OpenAI key even with a configured fallback');
    check(count($calls)===$before&&count($GLOBALS['imagePreflight'])===$preflightCount,
        'a missing personal key does not fall back to a site key or use another owner’s key');
    $keys->execute([1,'openai','fixture-cipher:fixture-owner-personal-key','gpt-5.6-luna']);
    $g=queueImage();$row=vp3_image_row_v1($pdo,$u,$g['id']);$v=vp3_image_tick_v1($pdo,$u,$row,'transportImage');
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='completed'&&end($calls)[1]['model']===$primary['model']&&end($calls)[2]==='fixture-owner-personal-key'
        &&$entry['credential_source']==='own_key'&&(int)$entry['total_tokens']===220
        &&count($GLOBALS['imagePreflight'])===$preflightCount&&count($GLOBALS['imageUsage'])===$usageCount
        &&!str_contains($row['config_json'],'fixture-owner-personal-key'),
        'account-wide personal funding uses the saved OpenAI key and exact model, meters usage and leaves site quota untouched');
    $g=queueImage();$before=count($calls);$row=vp3_image_row_v1($pdo,$u,$g['id']);
    $oldConfig=json_decode($row['config_json'],true,16,JSON_THROW_ON_ERROR);
    unset($oldConfig['funding_source']);$oldConfig['provider_route']['funding']='own_key';$replaceConfig($row,$oldConfig);
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before,
        'old per-service personal grants do not dispatch jobs under the new account-wide consent');
    $g=queueImage();$before=count($calls);
    $pdo->exec("UPDATE user_llm_preferences SET route='system' WHERE user_id=1");
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before,
        'switching an account to site funding stops its queued personal image without changing funding');
    $g=queueImage();$before=count($calls);
    $pdo->exec("UPDATE user_llm_preferences SET route='own_key' WHERE user_id=1");
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before&&count($GLOBALS['imagePreflight'])===$preflightCount,
        'switching an account to personal funding stops its queued site image before quota or dispatch');
    vp3_ai_service_save_route_v1($pdo,'image_generation',null,null);
    $GLOBALS['noImageProvider']=true;
    $g=vp3_image_queue_workspace_v1($pdo,$u,$plan,bin2hex(random_bytes(16)));
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='completed'&&end($calls)[1]['model']===vp3_image_settings_v1()['model']&&end($calls)[2]==='fixture-owner-personal-key'
        &&$entry['credential_source']==='own_key'&&(int)$entry['total_tokens']===220
        &&count($GLOBALS['imagePreflight'])===$preflightCount&&count($GLOBALS['imageUsage'])===$usageCount,
        'Media Library uses the account’s personal key and tracks usage even with the default image model and site key disabled');
    $GLOBALS['noImageProvider']=false;
    $g=queueImage();$before=count($calls);
    $pdo->exec("DELETE FROM user_llm_credentials WHERE user_id=1 AND provider='openai'");
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before&&count($GLOBALS['imagePreflight'])===$preflightCount,
        'removing the saved OpenAI key stops a queued personal image without charging the site');
    $pdo->exec("UPDATE user_llm_preferences SET route='system' WHERE user_id=1");
    $g=queueImage();$before=count($calls);
    $update="UPDATE user_llm_preferences SET route='own_key' WHERE user_id=NEW.user_id";
    $pdo->exec('CREATE TRIGGER image_funding_change AFTER INSERT ON ai_execution_ledger '.($mysql?'FOR EACH ROW '.$update:'BEGIN '.$update.'; END'));
    $v=vp3_image_tick_v1($pdo,$u,vp3_image_row_v1($pdo,$u,$g['id']),'transportImage');
    $pdo->exec('DROP TRIGGER image_funding_change');
    $meter->execute([$g['job_id']]);$entry=$meter->fetch();$meter->closeCursor();
    check($v['status']==='failed'&&!$v['provider_started']&&count($calls)===$before&&$entry===false&&count($GLOBALS['imageUsage'])===$usageCount,
        'a funding change after reservation is detected at dispatch and releases the unused allowance');
    echo 'IMAGE_GENERATION_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
}finally{
    if(is_dir(STONEFELLOW_ROOT)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir(STONEFELLOW_ROOT);}
}
