<?php
declare(strict_types=1);

if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-profile-image-edit-'.bin2hex(random_bytes(8)));
function ai_provider_api_key(string $provider): string{return 'fixture-image-key';}
function ai_provider_enabled(string $provider): bool{return true;}
function subscription_has_entitlement(...$args): bool{return empty($GLOBALS['imageEntitlementDenied']);}
function subscription_ai_preflight(...$args): array{$GLOBALS['imageReservations'][]=$args;return ['reservation_id'=>23,'max_output_tokens'=>empty($GLOBALS['imageQuotaDenied'])?16000:1];}
function subscription_ai_commit_usage(...$args): void{$GLOBALS['imageSettlements'][]=$args;}
function subscription_ai_release_reservation(int $id): void{$GLOBALS['imageReleases'][]=$id;}
require_once __DIR__.'/fixtures/profile-handoff-pa3.php';
require dirname(__DIR__).'/includes/profile-image-edit-v1.php';

$checks=0;
function edit_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function edit_denied(callable $run,string $label,?string $code=null): void
{
    try{$run();}catch(VP3ProfileAgentPublicException|VP3ProfileHandoffException $e){edit_check($code===null||$e->publicCode===$code,$label.' ('.$e->publicCode.')');return;}throw new RuntimeException('Unexpected success: '.$label);
}
function edit_png(int $size=2,int $color=64): string
{
    $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$size,$size,8,6,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat(chr($color)."\x60\x80\xff",$size),$size))).$chunk('IEND','');
}
function edit_setup(): void
{
    $pdo=db();if(table_exists('profile_image_edits_v1'))$pdo->exec('DELETE FROM profile_image_edits_v1');
    foreach(['ai_execution_ledger','ai_token_reservations'] as $table)if(table_exists($table))$pdo->exec('DELETE FROM '.$table);
    pa3_reset();
    $GLOBALS['imageReservations']=[];$GLOBALS['imageSettlements']=[];$GLOBALS['imageReleases']=[];$GLOBALS['editProviderCalls']=[];$GLOBALS['editHook']=null;$GLOBALS['imageQuotaDenied']=false;$GLOBALS['imageEntitlementDenied']=false;
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$assetId=$mysql?'BIGINT UNSIGNED':'INTEGER';$ownerId=$mysql?'INT UNSIGNED':'INTEGER';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_media_assets(id $assetId PRIMARY KEY,user_id $ownerId,media_type VARCHAR(20),title VARCHAR(190),mime_type VARCHAR(80),file_path TEXT,file_size INTEGER,source VARCHAR(60),updated_at DATETIME)".$tail);
    vp3_media_collections_install_v1($pdo);vp3_profile_image_catalog_install_v1($pdo);vp3_profile_image_edit_install_v1($pdo);
    foreach(['profile_image_catalog_refs_v1','profile_image_catalogs_v1','profile_image_settings_v1','vp3_media_collection_assets_v1','vp3_media_collections_v1','user_media_assets'] as $table)$pdo->exec('DELETE FROM '.$table);
    $collection=vp3_media_collection_create_v1($pdo,1,'Warm style')['id'];$GLOBALS['editCollection']=$collection;
    foreach([11,12] as $id){$root=media_studio_ensure_user_root(1);$png=edit_png(2,$id);file_put_contents($root.'/style-'.$id.'.png',$png);
        $pdo->prepare('INSERT INTO user_media_assets VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,1,'photo','Style '.$id,'image/png','private/user-media/1/style-'.$id.'.png',strlen($png),'upload','2026-11-18 00:00:00']);
        vp3_media_collection_set_asset_v1($pdo,1,$collection,$id,true);
    }
    vp3_profile_image_catalog_save_v1($pdo,pa3_owner(),['action'=>'save_catalog','collection_id'=>$collection,'expected_revision'=>0,'approved'=>true,'style_instructions'=>'Soft light and muted colors.','default_price_cents'=>0,'references'=>[['asset_id'=>11,'price_override_cents'=>null],['asset_id'=>12,'price_override_cents'=>0]]]);
    vp3_profile_image_catalog_save_v1($pdo,pa3_owner(),['action'=>'save_settings','expected_revision'=>0,'settings'=>['enabled'=>true,'daily_limit'=>10,'visitor_daily_limit'=>3]]);
}
function edit_intent(array $extra=[],?string $bytes=null): array
{
    $input=array_replace(['catalog_id'=>$GLOBALS['editCollection'],'catalog_revision'=>vp3_profile_image_catalog_row_v1(db(),1,$GLOBALS['editCollection'])['revision']],$extra);
    return vp3_profile_image_edit_intent_v1($input,isset($input['base_message_id'])?null:vp3_profile_image_edit_raster_v1($bytes??edit_png(),'visitor.png'));
}
function edit_admit(int $cid=0,?string $request=null,array $extra=[],?string $bytes=null,int $visitor=0,string $browser='b',string $prompt='Match the catalog style and keep my face.'): array
{
    $ctx=pa3_context($visitor,$browser);$intent=edit_intent($extra,$bytes);$request??=bin2hex(random_bytes(16));
    return vp3_profile_agent_public_message_service_v110(db(),$ctx,$prompt,$cid,['async'=>true,'chat_mode'=>'agent','client_request_id'=>$request,'image_edit'=>$intent]);
}
function edit_transport(string $endpoint,array $body,string $key): array
{
    $GLOBALS['editProviderCalls'][]=[$endpoint,$body,$key];if($hook=$GLOBALS['editHook'])$hook();
    return ['data'=>[['b64_json'=>base64_encode(edit_png(1024,127))]],'usage'=>['input_tokens'=>50,'output_tokens'=>500,'total_tokens'=>550]];
}
function edit_dispatch(array $receipt): array{return vp3_profile_handoff_dispatch_pa3(db(),(int)$receipt['handoff']['turn_id'],'edit_transport');}
function edit_row(array $receipt): array{return vp3_profile_image_edit_row_v1(db(),(int)$receipt['visitor_message_id'])??throw new RuntimeException('Missing edit.');}
function edit_settings(array $changes): void
{
    $current=vp3_profile_image_catalog_settings_v1(db(),1);vp3_profile_image_catalog_save_v1(db(),pa3_owner(),['action'=>'save_settings','expected_revision'=>$current['revision'],'settings'=>array_replace(['enabled'=>true,'daily_limit'=>10,'visitor_daily_limit'=>3],$changes)]);
}
if(defined('VP3_PROFILE_IMAGE_TEST_HELPERS_ONLY'))return;
try{
    pa3_reset();edit_check(!vp3_profile_image_edit_availability_v1($pdo,1)['enabled'],'pre-upgrade image editing stays disabled without DDL');
    edit_setup();vp3_profile_image_edit_install_v1($pdo);edit_check(vp3_profile_image_edit_ready_v1($pdo),'image edit schema installs idempotently');
    edit_check(vp3_profile_image_edit_credit_label_v1("\u{FEFF}\u{00A0}Saved\u{2028}Owner\u{202F}",200)==='Saved Owner'&&vp3_profile_image_edit_credit_label_v1("\u{FEFF}\u{00A0}\u{2028}",200)==='','historical credit labels normalize Unicode spaces, separators and invisible format controls consistently');
    foreach(['not pixels',edit_png().str_repeat('x',VP3_PROFILE_IMAGE_INPUT_MAX_V1),substr(edit_png(),0,-8)] as $bad)edit_denied(fn()=>vp3_profile_image_edit_raster_v1($bad,'bad.png'),'invalid or oversized upload rejected before admission');
    edit_denied(fn()=>vp3_profile_image_edit_intent_v1(['catalog_id'=>$GLOBALS['editCollection']],null),'an edit requires one explicit subject');
    edit_denied(fn()=>vp3_profile_image_edit_intent_v1(['catalog_id'=>$GLOBALS['editCollection'],'base_message_id'=>1],vp3_profile_image_edit_raster_v1(edit_png(),'a.png')),'two subjects cannot silently pick a base');
    $pdo->prepare('UPDATE users SET display_name=? WHERE id=1')->execute(["  Fixture\n\tOwner  "]);
    $request=str_repeat('a',32);$receipt=edit_admit(0,$request);$row=edit_row($receipt);$cid=(int)$receipt['conversation_id'];
    edit_check($row['state']==='queued'&&(int)$row['turn_id']===(int)$receipt['handoff']['turn_id']&&$receipt['handoff']['state']==='agent_ready'&&!$GLOBALS['editProviderCalls'],'offline owner admits one durable native image turn without inference');
    $duplicate=edit_admit(0,$request);edit_check($duplicate['duplicate']&&pa3_count('profile_image_edits_v1')===1,'lost admission response recovers the original request without a second input');
    edit_denied(fn()=>edit_admit(0,$request,[],edit_png(2,99)),'same request ID cannot change pixels','HANDOFF_REPLAY_CONFLICT');
    edit_denied(fn()=>edit_admit(0,$request,['image_id'=>11]),'same request ID cannot change style selection','HANDOFF_REPLAY_CONFLICT');
    pa3_age();edit_denied(fn()=>edit_admit(),'another active edit cannot spend concurrently','PROFILE_IMAGE_BUSY');
    $projection=vp3_profile_image_edit_public_projection_v1($pdo,pa3_context(),$cid,vp3_profile_agent_public_state_service_v110($pdo,pa3_context(),$cid));
    edit_check($projection['image_edits'][0]['state']==='queued'&&$projection['messages'][0]['image_edit']['kind']==='input'&&str_contains($projection['messages'][0]['image_edit']['url'],'conversation_namespace='),'saved input and queued state project only as conversation URLs');
    edit_check(!isset($projection['messages'][0]['image_edit']['source_credit'])&&json_decode($row['config_json'],true)['source_credit_owner_name']==='Fixture Owner','admission freezes a bounded normalized public owner label without attributing the visitor input');
    edit_check(!str_contains(json_encode($projection),'input_content')&&!str_contains(json_encode($projection),'fixture-image-key')&&!str_contains(json_encode($projection),'private/user-media'),'public projections exclude private storage, pixel bytes and provider keys');
    pa3_login(4);$_COOKIE=[];$result=edit_dispatch($receipt);$row=edit_row($receipt);
    edit_check($result['reason']==='completed'&&$row['state']==='completed'&&(int)$row['result_message_id']>0,'public worker edits and publishes without an owner browser session');
    edit_check(count($GLOBALS['editProviderCalls'])===1&&count($GLOBALS['imageSettlements'])===1&&$GLOBALS['imageSettlements'][0][5]['total_tokens']===550,'one provider request records confirmed actual usage once');
    $body=$GLOBALS['editProviderCalls'][0][1];edit_check(count($body['images'])===3&&$body['n']===1&&$body['images'][0]['image_url']==='data:image/png;base64,'.base64_encode(edit_png())&&str_contains($body['prompt'],'Soft light and muted colors.'),'provider receives subject first, approved style pixels and frozen owner direction');
    edit_check(!isset($body['source_credit_owner_name']),'public source credit metadata is not sent to the image provider');
    $creditRow=$row;$credit=['contract'=>'vp3.profile-image-credit.v1','owner_name'=>'Fixture Owner','catalog_name'=>'Warm style','references'=>[['title'=>'Style 11'],['title'=>'Style 12']],'price_cents'=>0];
    $ctx=pa3_context();$completed=vp3_profile_image_edit_public_projection_v1($pdo,$ctx,$cid,vp3_profile_agent_public_state_service_v110($pdo,$ctx,$cid));$completedMessages=array_column($completed['messages'],null,'id');
    edit_check(($completedMessages[$row['result_message_id']]['image_edit']['source_credit']??null)===$credit,'completed public result credits exactly its frozen owner, collection and all selected references with zero price');
    $ownerMessages=vp3_profile_image_edit_messages_v1($pdo,1,$cid,[['id'=>(int)$row['result_message_id'],'sender_type'=>'agent']],true);
    edit_check(($ownerMessages[0]['image_edit']['source_credit']??null)===$credit,'authorized owner conversation receives the same bounded historical credit as the visitor');
    $legacy=$row;$legacyConfig=json_decode($row['config_json'],true);unset($legacyConfig['source_credit_owner_name']);$legacy['config_json']=json_encode($legacyConfig);
    edit_check(vp3_profile_image_edit_source_credit_v1($legacy)===array_replace($credit,['owner_name'=>'Profile owner']),'legacy verified snapshots use a stable generic owner instead of a current or invented identity');
    $saved=json_decode($row['catalog_json'],true);
    foreach([array_replace($saved,['price_cents'=>1]),array_replace($saved,['owner_user_id'=>4]),array_replace($saved,['selection_asset_id'=>11]),array_replace($saved,['references'=>[]]),array_replace($saved,['references'=>array_fill(0,4,$saved['references'][0])]),null] as $invalid){
        $invalidRow=array_replace($row,['catalog_json'=>json_encode($invalid)]);$fileView=vp3_profile_image_edit_file_projection_v1($invalidRow,'result',false,$ctx);
        edit_check(!isset($fileView['source_credit'])&&!empty($fileView['url']),'invalid historical credit cannot invent free provenance or hide the authorized image');
    }
    edit_dispatch($receipt);edit_check(count($GLOBALS['editProviderCalls'])===1&&pa3_count('profile_image_edits_v1',"state='completed'")===1,'completed dispatch retry cannot repurchase or double count a completed use');
    $file=vp3_profile_image_edit_file_v1($pdo,1,$cid,(int)$row['result_message_id']);edit_check($file['bytes']===edit_png(1024,127),'authorized published result serves exact validated PNG bytes');
    edit_denied(fn()=>vp3_profile_image_edit_file_v1($pdo,4,$cid,(int)$row['result_message_id']),'other owner cannot address output','PROFILE_IMAGE_NOT_FOUND');
    $ctx=pa3_context(0,'c');edit_denied(fn()=>vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid),'different visitor credential cannot read conversation','CONVERSATION_NOT_FOUND');
    $ctx=pa3_context();$history=vp3_profile_image_edit_owner_history_v1($pdo,pa3_owner());edit_check($history['total_completed']===1&&count($history['records'])===1&&str_contains($history['records'][0]['output_url'],'owner_image'),'owner has a private cataloged record and completed use count');
    edit_check(pa3_count('user_media_assets')===2,'visitor originals and results do not enter the reusable public/media catalog');
    pa3_age();$revision=edit_admit($cid,null,['base_message_id'=>(int)$row['result_message_id']]);$child=edit_row($revision);edit_dispatch($revision);$child=edit_row($revision);
    edit_check($child['state']==='completed'&&(int)$child['root_message_id']===(int)$row['visitor_message_id']&&(int)$child['base_message_id']===(int)$row['result_message_id'],'an explicit follow-up preserves original, parent result and conversation lineage');
    $config=json_decode($child['config_json'],true);edit_check($config['reference']['generated_output']['request_id']===$row['request_id'],'saved generated provenance comes from the authorized parent request');
    pa3_age();edit_denied(fn()=>edit_admit(0,null,['base_message_id'=>(int)$row['result_message_id']]),'a prior result cannot be imported into a different conversation','PROFILE_IMAGE_UNAVAILABLE');
    pa3_age();$third=edit_admit($cid);edit_dispatch($third);pa3_age();edit_denied(fn()=>edit_admit($cid),'visitor daily allowance counts separate completed edits','PROFILE_IMAGE_LIMIT');
    $pdo->exec("UPDATE users SET display_name='Renamed owner' WHERE id=1");$pdo->exec("UPDATE vp3_media_collections_v1 SET name='Renamed collection'");$pdo->exec("UPDATE user_media_assets SET title='Renamed image'");
    $ctx=pa3_context();$renamed=vp3_profile_image_edit_public_projection_v1($pdo,$ctx,$cid,vp3_profile_agent_public_state_service_v110($pdo,$ctx,$cid));$renamedMessages=array_column($renamed['messages'],null,'id');
    edit_check(($renamedMessages[$creditRow['result_message_id']]['image_edit']['source_credit']??null)===$credit,'later owner, collection and image renames cannot rewrite saved result credit');
    edit_setup();$selected=edit_admit(0,null,['image_id'=>12]);edit_dispatch($selected);$selected=edit_row($selected);
    edit_check(vp3_profile_image_edit_source_credit_v1($selected)===array_replace($credit,['references'=>[['title'=>'Style 12']]]),'an exact image edit credits only the selected reference rather than every image in its collection');

    edit_setup();$receipt=edit_admit();$GLOBALS['editHook']=function()use($receipt):void{$turn=vp3_profile_handoff_row_pa3(db(),(int)$receipt['handoff']['turn_id']);vp3_profile_handoff_owner_action_pa3(db(),pa3_owner(),['operation'=>'claim','turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],'conversation_id'=>(int)$turn['conversation_id'],'chat_conversation_id'=>11,'chat_agent_id'=>7,'request_id'=>str_repeat('c',32)]);};
    edit_dispatch($receipt);$row=edit_row($receipt);edit_check($row['state']==='cancelled'&&!$row['result_message_id']&&pa3_count('profile_agent_messages',"sender_type='agent'")===0,'human takeover during provider call withdraws the result before publication');
    edit_check(count($GLOBALS['imageSettlements'])===1,'provider usage remains recorded after human takeover');
    edit_check(!is_file(vp3_profile_image_edit_path_v1(1,$row['request_id'])),'withdrawn result leaves no untracked output file');

    edit_setup();$receipt=edit_admit();$pdo->exec('UPDATE profile_image_catalogs_v1 SET approved=0');edit_dispatch($receipt);edit_check(!$GLOBALS['editProviderCalls']&&!edit_row($receipt)['result_message_id'],'revoked catalog prevents provider dispatch and publication');
    edit_setup();$receipt=edit_admit();$GLOBALS['editHook']=function():void{db()->exec('UPDATE profile_image_catalogs_v1 SET default_price_cents=100,revision=revision+1');};edit_dispatch($receipt);$row=edit_row($receipt);edit_check(!$row['result_message_id']&&$row['state']==='failed'&&count($GLOBALS['imageSettlements'])===1,'price/approval change during generation blocks publication while preserving actual usage');

    edit_setup();$receipt=edit_admit();$pdo->prepare("UPDATE profile_image_edits_v1 SET state='editing',provider_started_at=? WHERE request_id=?")->execute([gmdate('Y-m-d H:i:s'),edit_row($receipt)['request_id']]);edit_dispatch($receipt);
    edit_check(!$GLOBALS['editProviderCalls']&&edit_row($receipt)['state']==='failed','a consumed attempt without saved output never invokes provider again');
    edit_setup();$receipt=edit_admit();$GLOBALS['editHook']=static function():void{throw new RuntimeException('provider_outcome_unconfirmed');};edit_dispatch($receipt);edit_dispatch($receipt);edit_check(count($GLOBALS['editProviderCalls'])===1&&edit_row($receipt)['state']==='failed','unknown provider outcome remains once-only across dispatch retries');

    edit_setup();$GLOBALS['imageQuotaDenied']=true;$receipt=edit_admit();edit_dispatch($receipt);edit_check(!$GLOBALS['editProviderCalls']&&edit_row($receipt)['provider_started_at']===null&&$GLOBALS['imageReleases']===[23],'insufficient AI quota rejects before provider and releases reservation');
    edit_setup();edit_settings(['daily_limit'=>1]);$receipt=edit_admit();edit_dispatch($receipt);pa3_age();edit_denied(fn()=>edit_admit(0,null,[],null,2,'c'),'owner daily allowance also bounds another visitor','PROFILE_IMAGE_LIMIT');

    edit_setup();$before=pa3_count('profile_agent_messages');$pdo->failSql='INSERT INTO profile_image_edits_v1';try{edit_admit();throw new RuntimeException('Missing failure');}catch(PDOException $e){$pdo->failSql='';}
    edit_check(pa3_count('profile_agent_messages')===$before&&pa3_count('profile_agent_handoff_turns_pa3')===0&&pa3_count('profile_image_edits_v1')===0,'input storage failure rolls back message, conversation, allowance and canonical work');
    edit_setup();$receipt=edit_admit();$pdo->failSql="SET state='completed',result_message_id";edit_dispatch($receipt);$pdo->failSql='';$row=edit_row($receipt);
    edit_check(!$row['result_message_id']&&pa3_count('profile_agent_messages',"sender_type='agent'")===0&&$row['state']==='staged','publication storage failure cannot expose a partial completed result');
    $pdo->exec('UPDATE agent_workflow_runs SET next_attempt_at=NULL');$pdo->exec('UPDATE agent_workflow_actions SET available_at=NULL');edit_dispatch($receipt);$row=edit_row($receipt);
    edit_check($row['state']==='completed'&&count($GLOBALS['editProviderCalls'])===1&&pa3_count('profile_image_edits_v1',"state='completed'")===1,'publication retry reuses staged bytes and commits one completed use without repurchase');

    edit_setup();$oldRequest=str_repeat('d',32);$receipt=edit_admit(0,$oldRequest);edit_dispatch($receipt);$row=edit_row($receipt);$cid=(int)$row['conversation_id'];
    $columns=array_keys($row);$insert=$pdo->prepare('INSERT INTO profile_image_edits_v1 ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
    for($i=0;$i<35;$i++){
        $pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,message,created_at) VALUES(?,'visitor',?,?)")->execute([$cid,'Later photo request '.$i,gmdate('Y-m-d H:i:s')]);$mid=(int)$pdo->lastInsertId();
        $later=array_replace($row,['request_id'=>bin2hex(random_bytes(16)),'visitor_message_id'=>$mid,'turn_id'=>null,'root_message_id'=>$mid,'base_message_id'=>0,'prompt'=>'Later photo request '.$i,'state'=>'failed','provider_started_at'=>null,'output_sha256'=>'','output_bytes'=>0,'output_width'=>0,'output_height'=>0,'result_message_id'=>null,'completed_at'=>null]);$insert->execute(array_values($later));
        for($j=0;$j<3;$j++)$pdo->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,message,created_at) VALUES(?,'owner',?,?)")->execute([$cid,'Later conversation text '.$i.' '.$j,gmdate('Y-m-d H:i:s')]);
    }
    $ctx=pa3_context();$recent=vp3_profile_image_edit_progress_v1($pdo,1,$cid);$normal=vp3_profile_agent_public_state_service_v110($pdo,$ctx,$cid);
    edit_check(count($recent)===25&&!in_array($row['request_id'],array_column($recent,'request_id'),true)&&!in_array((int)$row['visitor_message_id'],array_map('intval',array_column($normal['messages'],'id')),true),'old request lies outside both ordinary status and conversation history windows');
    $counts=[pa3_count('profile_image_edits_v1'),pa3_count('profile_agent_messages'),pa3_count('agent_workflow_receipts'),count($GLOBALS['editProviderCalls'])];
    $recovered=vp3_profile_image_edit_receipt_v1($pdo,$ctx,$oldRequest);$exact=array_values(array_filter($recovered['image_edits'],static fn(array $job):bool=>$job['request_id']===$row['request_id']));
    edit_check($recovered['found']&&count($recovered['image_edits'])===26&&count($exact)===1&&$exact[0]['state']==='ready'&&$exact[0]['result_message_id']===(int)$row['result_message_id'],'exact receipt includes its completed job even after thirty-five later image requests');
    $recoveredMessages=array_column($recovered['messages'],null,'id');$ids=array_map('intval',array_column($recovered['messages'],'id'));$sorted=$ids;sort($sorted,SORT_NUMERIC);
    edit_check(count($recovered['messages'])===122&&$ids===$sorted&&($recoveredMessages[$row['visitor_message_id']]['image_edit']['kind']??null)==='input'&&($recoveredMessages[$row['result_message_id']]['image_edit']['kind']??null)==='result','receipt adds only its original input/result beyond bounded history and preserves chronological order');
    edit_check($counts===[pa3_count('profile_image_edits_v1'),pa3_count('profile_agent_messages'),pa3_count('agent_workflow_receipts'),count($GLOBALS['editProviderCalls'])],'old receipt recovery performs no writes, provider calls or duplicated completion');

    edit_setup();$auto=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE ai_execution_ledger (id $auto,user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,run_id INTEGER,trace_id VARCHAR(120),source VARCHAR(80),provider VARCHAR(80),model VARCHAR(160),created_at DATETIME,input_tokens INTEGER,output_tokens INTEGER,total_tokens INTEGER,latency_ms INTEGER,estimated_cost_micros BIGINT,cost_rate_source VARCHAR(40),cloud_ledger_id INTEGER,cloud_tokens_charged INTEGER DEFAULT 0,failure_class VARCHAR(80))");
    $pdo->exec('CREATE TABLE ai_token_reservations (id INTEGER PRIMARY KEY,user_id INTEGER)');vp3_ai_service_usage_ensure_schema_v1($pdo);
    $receipt=edit_admit();edit_dispatch($receipt);$meter=$pdo->query('SELECT * FROM ai_execution_ledger')->fetch();
    edit_check(edit_row($receipt)['state']==='completed'&&$meter['service_status']==='completed'&&(int)$meter['total_tokens']===550&&(int)$meter['usage_units']===1&&$meter['conversation_id']===null&&count($GLOBALS['imageSettlements'])===1,'the shared usage ledger records one image and actual tokens under the public canonical run without impersonating private Chat');
    edit_setup();$receipt=edit_admit();$row=edit_row($receipt);$turn=vp3_profile_handoff_row_pa3($pdo,(int)$row['turn_id']);
    vp3_ai_service_usage_begin_v1($pdo,pa3_owner(),'image_generation','openai',json_decode($row['config_json'],true)['model'],'system',100,16000,['run_id'=>(int)$turn['run_id'],'attempt_key'=>'profile-image:'.$row['request_id'],'billing_scope'=>'image_generation']);
    $pdo->prepare("UPDATE profile_image_edits_v1 SET state='editing',provider_started_at=? WHERE request_id=?")->execute([gmdate('Y-m-d H:i:s'),$row['request_id']]);edit_dispatch($receipt);$meter=$pdo->query('SELECT * FROM ai_execution_ledger')->fetch();
    edit_check(!$GLOBALS['editProviderCalls']&&$meter['service_status']==='unknown'&&(int)$meter['usage_known']===0&&(int)$meter['service_budget_tokens']>0&&$meter['service_reservation_id']===null,'replacement worker settles a consumed but unconfirmed metered attempt as unknown and keeps its budget evidence without replay');
    edit_setup();$receipt=edit_admit();$row=edit_row($receipt);$turn=vp3_profile_handoff_row_pa3($pdo,(int)$row['turn_id']);
    vp3_ai_service_usage_begin_v1($pdo,pa3_owner(),'image_generation','openai',json_decode($row['config_json'],true)['model'],'system',100,16000,['run_id'=>(int)$turn['run_id'],'attempt_key'=>'profile-image:'.$row['request_id'],'billing_scope'=>'image_generation']);
    edit_dispatch($receipt);edit_check(!$GLOBALS['editProviderCalls']&&pa3_count('ai_execution_ledger')===0&&edit_row($receipt)['state']==='failed','interrupted quota reservation before the dispatch marker is released without a provider call');
    echo 'PROFILE_IMAGE_EDIT_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
}finally{
    if(is_dir(STONEFELLOW_ROOT)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir(STONEFELLOW_ROOT);}
}
