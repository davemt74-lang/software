<?php
declare(strict_types=1);

/** Visitor-requested edits are private to the accepted public conversation. */
require_once __DIR__.'/agent-image-generation-v1.php';
require_once __DIR__.'/profile-image-catalog-v1.php';

const VP3_PROFILE_IMAGE_EDIT_V1='vp3.profile-image-edit.v1';
const VP3_PROFILE_IMAGE_INPUT_MAX_V1=4194304;

function vp3_profile_image_edit_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new LogicException('Install Profile image editing outside a transaction.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $blob=$mysql?'MEDIUMBLOB':'BLOB';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_image_edits_v1 (
      request_id CHAR(32) PRIMARY KEY,owner_user_id BIGINT NOT NULL,profile_agent_id BIGINT NOT NULL,
      conversation_id BIGINT NOT NULL,profile_session_id BIGINT NOT NULL,visitor_user_id BIGINT NOT NULL,
      visitor_key CHAR(64) NOT NULL,visitor_message_id BIGINT NOT NULL UNIQUE,turn_id BIGINT NULL UNIQUE,conversation_epoch INTEGER NOT NULL DEFAULT 0,
      root_message_id BIGINT NOT NULL,base_message_id BIGINT NOT NULL DEFAULT 0,
      request_hash CHAR(64) NOT NULL,prompt TEXT NOT NULL,input_name VARCHAR(180) NOT NULL,
      input_mime VARCHAR(30) NOT NULL,input_bytes INTEGER NOT NULL,input_sha256 CHAR(64) NOT NULL,
      input_width INTEGER NOT NULL,input_height INTEGER NOT NULL,input_content $blob NULL,
      catalog_json TEXT NOT NULL,config_json TEXT NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'queued',
      provider_started_at DATETIME NULL,output_sha256 CHAR(64) NOT NULL DEFAULT '',
      output_bytes INTEGER NOT NULL DEFAULT 0,output_width INTEGER NOT NULL DEFAULT 0,output_height INTEGER NOT NULL DEFAULT 0,
      result_message_id BIGINT NULL UNIQUE,error_code VARCHAR(60) NOT NULL DEFAULT '',usage_json TEXT NULL,
      created_at DATETIME NOT NULL,completed_at DATETIME NULL
    )".$tail);
    foreach(['idx_profile_image_owner'=>'owner_user_id,created_at','idx_profile_image_visitor'=>'owner_user_id,visitor_key,created_at','idx_profile_image_conversation'=>'owner_user_id,conversation_id,visitor_message_id'] as $name=>$columns){
        $q=$pdo->prepare($mysql?'SHOW INDEX FROM profile_image_edits_v1 WHERE Key_name=?':"SELECT 1 FROM sqlite_master WHERE type='index' AND name=?");$q->execute([$name]);
        if(!$q->fetch())$pdo->exec('CREATE INDEX '.$name.' ON profile_image_edits_v1 ('.$columns.')');
    }
}
function vp3_profile_image_edit_ready_v1(PDO $pdo): bool
{
    try{$pdo->query('SELECT request_id,visitor_message_id,turn_id,input_content,catalog_json,config_json,state,provider_started_at,output_sha256,result_message_id,usage_json,completed_at FROM profile_image_edits_v1 WHERE 1=0');return true;}
    catch(PDOException $e){return false;}
}
function vp3_profile_image_edit_error_v1(string $message,string $code='PROFILE_IMAGE_INVALID',int $status=422): never
{
    throw new VP3ProfileAgentPublicException($code,$message,$status);
}
function vp3_profile_image_edit_id_v1(mixed $value,bool $zero=false): int
{
    if((!is_int($value)&&(!is_string($value)||!preg_match('/^[0-9]{1,15}$/D',$value)))||(int)$value<($zero?0:1))vp3_profile_image_edit_error_v1('Choose an available photo style.');
    return (int)$value;
}
/** The byte validator is shared by PHP uploads and reauthorized saved outputs. */
function vp3_profile_image_edit_raster_v1(string $bytes,string $name,bool $generated=false): array
{
    $size=strlen($bytes);$max=$generated?vp3_image_output_bytes_limit_v1():VP3_PROFILE_IMAGE_INPUT_MAX_V1;
    if($size<1||$size>$max)vp3_profile_image_edit_error_v1('Choose a JPEG, PNG or WebP photo up to 4 MB.');
    $info=@getimagesizefromstring($bytes);$mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||($info['mime']??null)!==$mime||min($info[0],$info[1])<1||max($info[0],$info[1])>4096||($generated&&$mime!=='image/png'))vp3_profile_image_edit_error_v1('Choose a valid JPEG, PNG or WebP photo up to 4096 pixels per side.');
    if($mime==='image/png'){try{vp3_image_png_check_v1($bytes);}catch(Throwable $e){vp3_profile_image_edit_error_v1('The PNG photo is incomplete or damaged.');}}
    $name=mb_check_encoding($name,'UTF-8')?trim((string)preg_replace('/[\p{C}]/u','',basename(str_replace('\\','/',$name)))):'photo';
    $ext=match($mime){'image/jpeg'=>'jpg','image/png'=>'png',default=>'webp'};
    $name=(mb_substr(pathinfo($name,PATHINFO_FILENAME),0,160)?:'photo').'.'.$ext;
    return ['name'=>$name,'mime'=>$mime,'bytes'=>$size,'sha256'=>hash('sha256',$bytes),'width'=>$info[0],'height'=>$info[1],'content'=>$bytes];
}
function vp3_profile_image_edit_upload_v1(array $upload): array
{
    $tmp=$upload['tmp_name']??null;
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($tmp)||!is_uploaded_file($tmp))vp3_profile_image_edit_error_v1('Choose a JPEG, PNG or WebP photo up to 4 MB.');
    $bytes=@file_get_contents($tmp,false,null,0,VP3_PROFILE_IMAGE_INPUT_MAX_V1+1);
    if(!is_string($bytes))vp3_profile_image_edit_error_v1('The photo could not be read.');
    return vp3_profile_image_edit_raster_v1($bytes,is_string($upload['name']??null)?$upload['name']:'photo');
}
function vp3_profile_image_edit_intent_v1(array $input,?array $file): array
{
    $catalog=vp3_profile_image_edit_id_v1($input['catalog_id']??null);$image=vp3_profile_image_edit_id_v1($input['image_id']??0,true);
    $base=vp3_profile_image_edit_id_v1($input['base_message_id']??0,true);
    $revision=$input['catalog_revision']??null;
    if($revision!==null&&(!is_int($revision)&&(!is_string($revision)||!preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D',$revision))))vp3_profile_image_edit_error_v1('Refresh the photo styles and choose one again.');
    if(($file===null)===($base===0))vp3_profile_image_edit_error_v1('Choose one uploaded photo or a result from this conversation.');
    $fingerprint=hash('sha256',json_encode([$catalog,$image,$revision,$base,$file['sha256']??null,$file['name']??null,$file['mime']??null],JSON_THROW_ON_ERROR));
    return ['catalog_id'=>$catalog,'image_id'=>$image,'catalog_revision'=>$revision,'base_message_id'=>$base,'file'=>$file,'fingerprint'=>$fingerprint];
}
function vp3_profile_image_edit_row_v1(PDO $pdo,int $messageId,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM profile_image_edits_v1 WHERE visitor_message_id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$messageId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_profile_image_edit_path_v1(int $owner,string $requestId,bool $create=false): string
{
    if($owner<1||!preg_match('/^[a-f0-9]{32}$/D',$requestId))throw new RuntimeException('image_storage_unavailable');
    require_once __DIR__.'/media-studio-v86.php';
    $root=$create?media_studio_ensure_user_root($owner):media_studio_user_root($owner);$base=realpath(media_studio_private_root());$real=realpath($root);
    if(!$base||$real!==$base.DIRECTORY_SEPARATOR.$owner)throw new RuntimeException('image_storage_unavailable');
    $path=$real.'/profile-edit-'.$requestId.'.png';
    if(is_link($path))throw new RuntimeException('image_storage_unavailable');
    return $path;
}
function vp3_profile_image_edit_output_bytes_v1(array $row): string
{
    $bytes=@file_get_contents(vp3_profile_image_edit_path_v1((int)$row['owner_user_id'],$row['request_id']),false,null,0,vp3_image_output_bytes_limit_v1()+1);
    if(!is_string($bytes)||strlen($bytes)!==(int)$row['output_bytes']||strlen($bytes)>vp3_image_output_bytes_limit_v1()||!hash_equals((string)$row['output_sha256'],hash('sha256',$bytes)))vp3_profile_image_edit_error_v1('This saved image is unavailable.','PROFILE_IMAGE_UNAVAILABLE',409);
    return $bytes;
}
function vp3_profile_image_edit_base_v1(PDO $pdo,int $owner,int $cid,int $messageId,bool $lock=false): array
{
    $q=$pdo->prepare("SELECT e.* FROM profile_image_edits_v1 e INNER JOIN profile_agent_messages m ON m.id=e.result_message_id AND m.conversation_id=e.conversation_id AND m.sender_type='agent' WHERE e.owner_user_id=? AND e.conversation_id=? AND e.result_message_id=? AND e.state='completed'".($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$owner,$cid,$messageId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)vp3_profile_image_edit_error_v1('Choose a completed image from this conversation.','PROFILE_IMAGE_UNAVAILABLE',409);
    return $row;
}
/** Admission is under the same owner lock as visitor identity and the public turn. */
function vp3_profile_image_edit_admit_v1(PDO $pdo,array $ctx,array $conversation,int $messageId,array $intent): array
{
    if(!$pdo->inTransaction()||!vp3_profile_image_edit_ready_v1($pdo))vp3_profile_image_edit_error_v1('Photo editing needs a database upgrade.','PROFILE_IMAGE_UNAVAILABLE',503);
    $owner=(int)$ctx['owner_user_id'];$cid=(int)$conversation['id'];vp3_ai_service_usage_lock_owner_v1($pdo,$owner);
    $settings=vp3_profile_image_catalog_settings_v1($pdo,$owner);
    if(empty($settings['enabled']))vp3_profile_image_edit_error_v1('Photo editing is unavailable on this profile.','PROFILE_IMAGE_UNAVAILABLE',409);
    $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,$cid,true);
    if(($conversation['status']??'')==='owner_joined'||($thread&&($thread['mode']==='owner'||in_array($thread['live_status'],['waiting','accepted'],true)))||vp3_profile_handoff_media_active_pa3d($pdo,$owner,$cid,true))vp3_profile_image_edit_error_v1('Return to the Profile Agent before requesting a photo edit.','PROFILE_IMAGE_OWNER_ACTIVE',409);
    $visitor=(int)($ctx['visitor']['id']??0);$visitorKey=hash('sha256',$visitor>0?'member:'.$visitor:'session:'.(int)$ctx['session_id']);
    $since=gmdate('Y-m-d 00:00:00');
    $q=$pdo->prepare('SELECT COUNT(*) FROM profile_image_edits_v1 WHERE owner_user_id=? AND created_at>=?');$q->execute([$owner,$since]);
    if((int)$q->fetchColumn()>=(int)$settings['daily_limit'])vp3_profile_image_edit_error_v1('This profile has used its photo editing allowance for today.','PROFILE_IMAGE_LIMIT',429);
    $q=$pdo->prepare('SELECT COUNT(*) FROM profile_image_edits_v1 WHERE owner_user_id=? AND visitor_key=? AND created_at>=?');$q->execute([$owner,$visitorKey,$since]);
    if((int)$q->fetchColumn()>=(int)$settings['visitor_daily_limit'])vp3_profile_image_edit_error_v1('You have used the available photo edits for today.','PROFILE_IMAGE_LIMIT',429);
    $q=$pdo->prepare("SELECT 1 FROM profile_image_edits_v1 e INNER JOIN profile_agent_handoff_turns_pa3 t ON t.id=e.turn_id INNER JOIN agent_workflow_runs r ON r.id=t.run_id WHERE e.owner_user_id=? AND e.state IN ('queued','editing','staged') AND t.state IN ('agent_ready','agent_running') AND r.status IN ('approved','executing','paused') LIMIT 1");$q->execute([$owner]);
    if($q->fetchColumn())vp3_profile_image_edit_error_v1('Another photo is being edited. Please try again when it finishes.','PROFILE_IMAGE_BUSY',429);
    try{$snapshot=vp3_profile_image_catalog_resolve_v1($pdo,$owner,$intent['catalog_id'],$intent['image_id']);}
    catch(DomainException|InvalidArgumentException $e){vp3_profile_image_edit_error_v1('Choose a currently approved free photo style. Refresh the styles and try again.','PROFILE_IMAGE_STYLE_CHANGED',409);}
    if($intent['catalog_revision']!==null&&(string)$intent['catalog_revision']!==(string)$snapshot['catalog_revision'])vp3_profile_image_edit_error_v1('This photo style changed. Refresh the styles and choose it again.','PROFILE_IMAGE_STYLE_CHANGED',409);
    $file=$intent['file'];$rootMessage=$messageId;$proof=null;
    if($intent['base_message_id']>0){
        $base=vp3_profile_image_edit_base_v1($pdo,$owner,$cid,$intent['base_message_id'],true);$file=vp3_profile_image_edit_raster_v1(vp3_profile_image_edit_output_bytes_v1($base),'edited-photo.png',true);
        $rootMessage=(int)$base['root_message_id'];$proof=['request_id'=>$base['request_id'],'output_sha256'=>$base['output_sha256']];
    }
    if(!is_array($file))vp3_profile_image_edit_error_v1('Choose a photo for this edit.');
    if(!subscription_has_entitlement($ctx['owner_user'],'main_ai.access'))vp3_profile_image_edit_error_v1('Photo editing is unavailable under this profile’s AI allowance.','PROFILE_IMAGE_ALLOWANCE',429);
    try{$route=vp3_image_route_v1($pdo,$ctx['owner_user']);}catch(Throwable $e){vp3_profile_image_edit_error_v1('Photo editing is unavailable under this profile’s current AI settings.','PROFILE_IMAGE_UNAVAILABLE',503);}
    $prompt=(string)$ctx['image_edit_prompt'];$config=['mode'=>'profile_style_reference','count'=>1,'prompt'=>"Catalog style direction:\n".(string)($snapshot['instructions']??'')."\n\nVisitor edit request:\n".$prompt,'model'=>$route['target']['model'],'funding_source'=>$route['funding'],'size'=>'1024x1024','quality'=>'medium','background'=>'opaque','reference'=>['mime_type'=>$file['mime'],'sha256'=>$file['sha256'],'byte_size'=>$file['bytes']]+($proof?['generated_output'=>$proof]:[])];
    // Public display credit is frozen separately from the catalog's authority snapshot.
    // It is presentation metadata only; the provider builds its own whitelisted body.
    $config['source_credit_owner_name']=vp3_profile_image_edit_credit_label_v1($ctx['profile']['display_name']??null,200)
        ?:vp3_profile_image_edit_credit_label_v1($ctx['profile']['username']??null,200)?:'Profile owner';
    if($route['policy']['configured'])$config['provider_route']=$route['target'];
    $request=bin2hex(random_bytes(16));$q=$pdo->prepare('INSERT INTO profile_image_edits_v1 (request_id,owner_user_id,profile_agent_id,conversation_id,profile_session_id,visitor_user_id,visitor_key,visitor_message_id,root_message_id,base_message_id,request_hash,prompt,input_name,input_mime,input_bytes,input_sha256,input_width,input_height,input_content,catalog_json,config_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $values=[$request,$owner,(int)$ctx['agent_id'],$cid,(int)$ctx['session_id'],$visitor,$visitorKey,$messageId,$rootMessage,$intent['base_message_id'],$intent['fingerprint'],$prompt,$file['name'],$file['mime'],$file['bytes'],$file['sha256'],$file['width'],$file['height'],$intent['base_message_id']>0?null:$file['content'],json_encode($snapshot,JSON_THROW_ON_ERROR),json_encode($config,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')];
    foreach($values as $index=>$value)$q->bindValue($index+1,$value,$index===18&&$value!==null?PDO::PARAM_LOB:(is_int($value)?PDO::PARAM_INT:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR)));$q->execute();
    return vp3_profile_image_edit_row_v1($pdo,$messageId,true)??throw new RuntimeException('Photo edit was not saved.');
}

function vp3_profile_image_edit_bind_v1(PDO $pdo,array $turn): void
{
    $pdo->prepare('UPDATE profile_image_edits_v1 SET turn_id=?,conversation_epoch=? WHERE visitor_message_id=? AND owner_user_id=? AND turn_id IS NULL')->execute([(int)$turn['id'],(int)$turn['conversation_epoch'],(int)$turn['visitor_message_id'],(int)$turn['owner_user_id']]);
}
function vp3_profile_image_edit_authority_v1(PDO $pdo,array $turn,?array $row=null,bool $lock=false): array
{
    $ctx=vp3_profile_agent_accepted_context_pa3($pdo,$turn,$lock);$row=$row??vp3_profile_image_edit_row_v1($pdo,(int)$turn['visitor_message_id'],$lock);
    if(!$row)vp3_profile_image_edit_error_v1('This photo edit is unavailable.','PROFILE_IMAGE_UNAVAILABLE',409);
    foreach(['owner_user_id','profile_agent_id','profile_session_id','conversation_id','visitor_user_id','visitor_message_id','conversation_epoch'] as $key)if((int)$row[$key]!==(int)$turn[$key])vp3_profile_image_edit_error_v1('This photo edit stopped when the conversation changed.','PROFILE_IMAGE_CHANGED',409);
    if((int)$row['turn_id']!==(int)$turn['id']||!hash_equals((string)$row['prompt'],(string)$ctx['visitor_message']['message']))vp3_profile_image_edit_error_v1('This photo edit is unavailable.','PROFILE_IMAGE_CHANGED',409);
    $settings=vp3_profile_image_catalog_settings_v1($pdo,(int)$row['owner_user_id']);
    if(empty($settings['enabled']))vp3_profile_image_edit_error_v1('Photo editing was disabled on this profile.','PROFILE_IMAGE_CHANGED',409);
    $snapshot=json_decode($row['catalog_json'],true,20,JSON_THROW_ON_ERROR);
    vp3_profile_image_catalog_recheck_v1($pdo,(int)$row['owner_user_id'],$snapshot,false);
    return $ctx;
}
function vp3_profile_image_edit_source_v1(PDO $pdo,array $row): string
{
    if((int)$row['base_message_id']>0){$base=vp3_profile_image_edit_base_v1($pdo,(int)$row['owner_user_id'],(int)$row['conversation_id'],(int)$row['base_message_id']);$bytes=vp3_profile_image_edit_output_bytes_v1($base);}
    else $bytes=$row['input_content'];
    if(!is_string($bytes)||strlen($bytes)!==(int)$row['input_bytes']||!hash_equals($row['input_sha256'],hash('sha256',$bytes)))throw new RuntimeException('image_reference_changed');
    return 'data:'.$row['input_mime'].';base64,'.base64_encode($bytes);
}
function vp3_profile_image_edit_generated_v1(array $row,bool $ready): array
{
    return ['answer'=>$ready?'Your edited photo is ready. You can use this result for another edit.':'This photo edit could not be completed. You can choose a photo and try a new request.',
        'context'=>[],'history_sources'=>[],'needs_owner'=>false,'answer_mode'=>'profile_image_edit',
        'message_context'=>['sources'=>[],'source_dependencies'=>[],'answer_mode'=>'profile_image_edit','profile_image_request'=>$row['request_id'],'profile_image_ready'=>$ready,'visitor_message_id'=>(int)$row['visitor_message_id']]];
}
/** A replacement worker records uncertainty; it never resubmits a consumed call. */
function vp3_profile_image_edit_recover_usage_v1(PDO $pdo,array $user,array $row): void
{
    if(!vp3_ai_service_usage_ready_v1($pdo))return;
    $key=hash('sha256',(int)$row['owner_user_id'].'|image_generation|profile-image:'.$row['request_id']);
    $q=$pdo->prepare("SELECT id,service_status FROM ai_execution_ledger WHERE user_id=? AND service_attempt_key=? AND service_key='image_generation'");$q->execute([(int)$row['owner_user_id'],$key]);$usage=$q->fetch(PDO::FETCH_ASSOC);
    if(!$usage||$usage['service_status']!=='pending')return;
    $ticket=['metered'=>true,'id'=>(int)$usage['id'],'attempt_key'=>$key,'user'=>$user,'billing_scope'=>'image_generation'];
    vp3_ai_service_usage_finish_v1($pdo,$ticket,[],$row['provider_started_at']!==null?'unknown':'aborted');
}
/** One provider attempt per persisted public request, independently of job lease retries. */
function vp3_profile_image_edit_generate_v1(PDO $pdo,array $turn,array $claim,?callable $transport=null): array
{
    $row=vp3_profile_image_edit_row_v1($pdo,(int)$turn['visitor_message_id']);if(!$row)throw new RuntimeException('Image request unavailable.');
    try{$ctx=vp3_profile_image_edit_authority_v1($pdo,$turn,$row);}
    catch(DomainException|VP3ProfileAgentPublicException $e){
        $pdo->prepare("UPDATE profile_image_edits_v1 SET state=CASE WHEN state='cancelled' THEN state ELSE 'failed' END,error_code='source_authority_changed' WHERE request_id=? AND state<>'completed'")->execute([$row['request_id']]);
        vp3_profile_image_edit_error_v1('This photo style or conversation changed. Choose a currently available style for a new request.','PROFILE_IMAGE_CHANGED',409);
    }
    $owner=(int)$row['owner_user_id'];$user=$ctx['owner_user'];
    if($row['state']==='staged'){vp3_profile_image_edit_output_bytes_v1($row);return vp3_profile_image_edit_generated_v1($row,true);}
    if(in_array($row['state'],['failed','cancelled'],true))return vp3_profile_image_edit_generated_v1($row,false);
    if($row['provider_started_at']!==null){
        vp3_profile_image_edit_recover_usage_v1($pdo,$user,$row);
        $pdo->prepare("UPDATE profile_image_edits_v1 SET state='failed',error_code='provider_outcome_unconfirmed' WHERE request_id=? AND state='editing'")->execute([$row['request_id']]);
        return vp3_profile_image_edit_generated_v1($row,false);
    }
    $ticket=[];$reservation=0;$reported=[];$settled=false;$sent=false;$started=null;$confirmed=0;$target=[];$funding='';$file=null;$staged=false;
    $settle=static function(string $status,bool $noCharge=false)use($pdo,$row,$user,&$ticket,&$reservation,&$reported,&$settled,&$started,&$confirmed,&$target,&$funding):void{
        if($settled)return;
        if(!empty($ticket['metered'])){
            $options=$confirmed?['units'=>1,'unit'=>'image']:[];if($noCharge)$options['confirmed_no_charge']=true;if($started!==null)$options['latency_ms']=(int)round((microtime(true)-$started)*1000);
            $result=vp3_ai_service_usage_finish_v1($pdo,$ticket,$reported,$status,$options);
            if($status!=='aborted'&&(int)($result['id']??0)!==(int)$ticket['id'])throw new RuntimeException('service_usage_unavailable');
        }elseif($reported&&$funding==='system'){subscription_ai_commit_usage($reservation,$user,'image_generation',$target['provider'],$target['model'],$reported,'profile-image:'.$row['request_id']);$reservation=0;}
        $settled=true;if($reported)$pdo->prepare('UPDATE profile_image_edits_v1 SET usage_json=? WHERE request_id=?')->execute([json_encode($reported,JSON_THROW_ON_ERROR),$row['request_id']]);
    };
    try{
        if(!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('subscription_required');
        $config=json_decode($row['config_json'],true,20,JSON_THROW_ON_ERROR);$snapshot=json_decode($row['catalog_json'],true,20,JSON_THROW_ON_ERROR);
        $route=vp3_image_route_v1($pdo,$user,$config);$target=$route['target'];$funding=$route['funding'];$policy=$route['policy'];
        $inputTokens=max(1,(int)ceil(mb_strlen($config['prompt'])/3.5))+4096*(1+count($snapshot['references']));
        $ticket=vp3_ai_service_usage_begin_v1($pdo,$user,'image_generation',$target['provider'],$target['model'],$funding,$inputTokens,16000,[
            'agent_id'=>(int)$row['profile_agent_id'],'run_id'=>(int)$turn['run_id'],'stage'=>'profile_style_edit','attempt_key'=>'profile-image:'.$row['request_id'],
            'job_tokens'=>$policy['job_tokens'],'daily_tokens'=>$policy['daily_tokens'],'monthly_tokens'=>$policy['monthly_tokens'],'billing_scope'=>'image_generation','required'=>$policy['configured']||$funding==='own_key']);
        if(!empty($ticket['metered'])){if((int)$ticket['max_output_tokens']<16000)throw new RuntimeException('service_budget_exceeded');}
        elseif($funding==='system'){$quota=subscription_ai_preflight($user,'image_generation',$inputTokens,16000);$reservation=(int)$quota['reservation_id'];if((int)$quota['max_output_tokens']<16000)throw new RuntimeException('subscription_required');}
        else throw new RuntimeException('service_usage_unavailable');
        $source=null;$styles=null;
        vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$turn,$claim,$row,$owner,$user,$config,$snapshot,&$source,&$styles,&$route):void{
            vp3_ai_service_usage_lock_owner_v1($pdo,$owner);vp3_profile_handoff_execution_fence_pa3($pdo,$turn,$claim);
            $fresh=vp3_profile_image_edit_row_v1($pdo,(int)$row['visitor_message_id'],true);vp3_profile_image_edit_authority_v1($pdo,$turn,$fresh,true);
            if($fresh['provider_started_at']!==null||$fresh['state']!=='queued')throw new RuntimeException('provider_outcome_unconfirmed');
            $refs=vp3_profile_image_catalog_recheck_v1($pdo,$owner,$snapshot,true);$styles=array_column($refs,'image_url');
            if(array_sum(array_column($refs,'byte_size'))>vp3_image_profile_style_bytes_limit_v1())throw new RuntimeException('image_reference_changed');
            $source=vp3_profile_image_edit_source_v1($pdo,$fresh);$route=vp3_image_route_v1($pdo,$user,$config);
            $q=$pdo->prepare("UPDATE profile_image_edits_v1 SET state='editing',provider_started_at=? WHERE request_id=? AND provider_started_at IS NULL AND state='queued'");$q->execute([gmdate('Y-m-d H:i:s'),$row['request_id']]);if($q->rowCount()!==1)throw new RuntimeException('provider_outcome_unconfirmed');
        });
        $config['style_references']=$snapshot['references'];$row['input_content']=null;
        $sent=true;$started=microtime(true);
        $output=vp3_image_provider_v1($config,$user,$transport,vp3_image_take_source_v1($source),$route['api_key'],static function(array $usage)use(&$reported):void{$reported=$usage;},vp3_image_take_styles_v1($styles));
        $confirmed=count($output['images']);$settle('completed');
        if($confirmed!==1)throw new RuntimeException('provider_image_unconfirmed');
        vp3_profile_image_edit_authority_v1($pdo,$turn);$image=$output['images'][0];
        $file=vp3_profile_image_edit_path_v1($owner,$row['request_id'],true);$handle=@fopen($file,'x+b');if(!$handle)throw new RuntimeException('image_storage_unavailable');
        try{if(fwrite($handle,$image['bytes'])!==strlen($image['bytes']))throw new RuntimeException('image_storage_unavailable');}finally{fclose($handle);}@chmod($file,0600);
        vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$turn,$claim,$row,$image):void{
            vp3_profile_handoff_execution_fence_pa3($pdo,$turn,$claim);vp3_profile_image_edit_authority_v1($pdo,$turn,null,true);
            $pdo->prepare("UPDATE profile_image_edits_v1 SET state='staged',output_sha256=?,output_bytes=?,output_width=?,output_height=?,error_code='' WHERE request_id=? AND state='editing'")->execute([$image['sha256'],strlen($image['bytes']),$image['width'],$image['height'],$row['request_id']]);
        });$staged=true;
        return vp3_profile_image_edit_generated_v1($row,true);
    }catch(Throwable $e){
        $code=in_array($e->getMessage(),['provider_access_denied','provider_rate_limited','provider_request_rejected','provider_outcome_unconfirmed','provider_image_unconfirmed','provider_usage_unconfirmed','image_too_large','image_storage_unavailable','subscription_required','provider_unavailable','provider_route_changed','service_policy_denied','service_budget_exceeded','service_usage_unavailable','image_reference_changed'],true)?$e->getMessage():($sent?'provider_outcome_unconfirmed':'quota_preflight_failed');
        $rejected=in_array($code,['provider_access_denied','provider_rate_limited','provider_request_rejected'],true);
        try{$settle(!$sent?'aborted':($reported||$rejected?'failed':'unknown'),$sent&&$rejected&&!$reported);}catch(Throwable $ignored){$code='service_usage_unavailable';}
        if(!$sent&&empty($ticket['metered'])){try{vp3_profile_image_edit_recover_usage_v1($pdo,$user,$row);}catch(Throwable $ignored){$code='service_usage_unavailable';}}
        $pdo->prepare("UPDATE profile_image_edits_v1 SET state=CASE WHEN state='cancelled' THEN state ELSE 'failed' END,error_code=? WHERE request_id=? AND state<>'completed'")->execute([$code,$row['request_id']]);
        // The surrounding executor still owns responder authority and refuses a late reply.
        if($e instanceof VP3ProfileHandoffException||$e instanceof VP3ProfileAgentPublicException)throw $e;
        return vp3_profile_image_edit_generated_v1($row,false);
    }finally{if($reservation>0)subscription_ai_release_reservation($reservation);if(!$staged&&$file!==null&&is_file($file))@unlink($file);}
}

function vp3_profile_image_edit_recheck_v1(PDO $pdo,array $turn,array $generated,bool $lock): void
{
    $id=$generated['message_context']['profile_image_request']??null;if($id===null)return;
    $row=vp3_profile_image_edit_row_v1($pdo,(int)$turn['visitor_message_id'],$lock);
    if(!$row||!hash_equals($row['request_id'],(string)$id))vp3_profile_image_edit_error_v1('This photo edit changed.','PROFILE_IMAGE_CHANGED',409);
    vp3_profile_image_edit_authority_v1($pdo,$turn,$row,$lock);
    if(!empty($generated['message_context']['profile_image_ready'])){
        if(!in_array($row['state'],['staged','completed'],true))vp3_profile_image_edit_error_v1('This photo edit changed.','PROFILE_IMAGE_CHANGED',409);
        vp3_profile_image_edit_output_bytes_v1($row);
    }
}
/** Called inside the accepted-turn publication transaction; completed use is recorded once. */
function vp3_profile_image_edit_publish_v1(PDO $pdo,array $turn,array $generated,int $messageId): void
{
    if(empty($generated['message_context']['profile_image_ready']))return;
    vp3_profile_image_edit_recheck_v1($pdo,$turn,$generated,true);
    $q=$pdo->prepare("UPDATE profile_image_edits_v1 SET state='completed',result_message_id=?,completed_at=? WHERE visitor_message_id=? AND owner_user_id=? AND state='staged' AND result_message_id IS NULL");$q->execute([$messageId,gmdate('Y-m-d H:i:s'),(int)$turn['visitor_message_id'],(int)$turn['owner_user_id']]);
    if($q->rowCount()!==1)throw new RuntimeException('Photo result publication changed.');
}
function vp3_profile_image_edit_cancel_v1(PDO $pdo,int $owner,int $cid): void
{
    if(!vp3_profile_image_edit_ready_v1($pdo))return;
    $pdo->prepare("UPDATE profile_image_edits_v1 SET state='cancelled',error_code='conversation_changed' WHERE owner_user_id=? AND conversation_id=? AND turn_id IS NOT NULL AND state IN ('queued','editing','staged')")->execute([$owner,$cid]);
}

/** Same UTC-day reservations and principal key as admission; never invent a balance. */
function vp3_profile_image_edit_allowance_v1(PDO $pdo,int $owner,?array $visitorContext=null): array
{
    $out=['contract'=>'vp3.profile-image-allowance.v1','ready'=>false,'remaining'=>null,'resets_at_utc_ms'=>null];
    if($visitorContext===null||(int)($visitorContext['owner_user_id']??0)!==$owner||$owner<1||!vp3_profile_image_edit_ready_v1($pdo))return $out;
    $visitor=(int)($visitorContext['visitor']['id']??0);$session=(int)($visitorContext['session_id']??0);
    if($visitor<0||$session<0||$visitor===$owner)return $out;
    try{
        $settings=vp3_profile_image_catalog_settings_v1($pdo,$owner);
        if(empty($settings['enabled']))return $out;
        $now=time();$since=gmdate('Y-m-d 00:00:00',$now);
        $key=$visitor>0?hash('sha256','member:'.$visitor):($session>0?hash('sha256','session:'.$session):null);
        $q=$pdo->prepare('SELECT COUNT(*) FROM profile_image_edits_v1 WHERE owner_user_id=? AND created_at>=?');$q->execute([$owner,$since]);$ownerUsed=(int)$q->fetchColumn();
        $visitorUsed=0;
        if($key!==null){$q=$pdo->prepare('SELECT COUNT(*) FROM profile_image_edits_v1 WHERE owner_user_id=? AND visitor_key=? AND created_at>=?');$q->execute([$owner,$key,$since]);$visitorUsed=(int)$q->fetchColumn();}
        $remaining=max(0,min((int)$settings['daily_limit']-$ownerUsed,(int)$settings['visitor_daily_limit']-$visitorUsed));
        return ['contract'=>'vp3.profile-image-allowance.v1','ready'=>true,'remaining'=>$remaining,
            'resets_at_utc_ms'=>(int)(strtotime($since.' UTC')+86400)*1000];
    }catch(PDOException){return $out;}
}

/** Collection browsing reads an existing visitor identity without creating a visit. */
function vp3_profile_image_collection_visitor_v1(PDO $pdo,int $owner,?array $visitor): ?array
{
    $sid=0;$cookie=$_COOKIE['sf_profile_visitor']??null;$legacy=$_SESSION['profile_visit_tokens'][(string)$owner]??null;
    $cookie=is_string($cookie)?strtolower(trim($cookie)):'';$legacy=is_string($legacy)?strtolower(trim($legacy)):'';
    $token=preg_match('/^(?:[a-f0-9]{48}|[a-f0-9]{64})$/D',$cookie)?$cookie:(preg_match('/^[a-f0-9]{48}$/D',$legacy)?$legacy:'');
    if($token!==''){
        try{
            $q=$pdo->prepare('SELECT id FROM profile_visit_sessions WHERE owner_user_id=? AND session_key=? LIMIT 1');
            $q->execute([$owner,hash('sha256',$token.'|'.$owner)]);$sid=(int)$q->fetchColumn();
        }catch(PDOException){return null;}
    }
    return ['owner_user_id'=>$owner,'visitor'=>$visitor,'session_id'=>$sid];
}

function vp3_profile_image_edit_availability_v1(PDO $pdo,int $owner,?string $publicUsername=null,?array $visitorContext=null): array
{
    $out=['contract'=>VP3_PROFILE_IMAGE_EDIT_V1,'enabled'=>false,'max_input_bytes'=>VP3_PROFILE_IMAGE_INPUT_MAX_V1,'catalogs'=>[],'daily_limit'=>0,'visitor_daily_limit'=>0,'reason'=>'Photo editing is unavailable on this profile.',
        'allowance'=>vp3_profile_image_edit_allowance_v1($pdo,$owner,$visitorContext)];
    if(!vp3_profile_image_edit_ready_v1($pdo))return $out;
    $settings=vp3_profile_image_catalog_settings_v1($pdo,$owner);$out['daily_limit']=(int)$settings['daily_limit'];$out['visitor_daily_limit']=(int)$settings['visitor_daily_limit'];
    if(empty($settings['enabled'])){$out['reason']='The profile owner has not enabled the public image collection yet.';return $out;}
    $out['catalogs']=vp3_profile_image_catalog_public_v1($pdo,$owner);$out['enabled']=$out['catalogs']!==[];
    if($publicUsername!==null)$out['catalogs']=vp3_profile_image_catalog_preview_urls_v1($out['catalogs'],$publicUsername);
    $out['reason']=$out['enabled']?'Use an approved free photo style. The profile provides a limited editing allowance.':'There are no approved free photo styles available.';
    return $out;
}
function vp3_profile_image_edit_url_v1(int $cid,int $messageId,bool $ownerView,?array $ctx=null,bool $preview=true): string
{
    $args=['action'=>$ownerView?'owner_image':'image','conversation_id'=>$cid,'message_id'=>$messageId];
    if(!$ownerView){
        if(!$ctx)return '';
        $args['username']=(string)$ctx['profile']['username'];$args['conversation_namespace']=hash('sha256','profile-widget-v1|'.(string)$ctx['conversation_authority']['credential_hash']);
    }
    if($preview)$args['preview']='1';return url('/api/profile-agent.php').'?'.http_build_query($args,'','&',PHP_QUERY_RFC3986);
}
function vp3_profile_image_edit_credit_label_v1(mixed $value,int $limit): string
{
    if(!is_string($value)||strlen($value)>$limit*4)return '';
    $label=preg_replace('/[\p{Cc}\p{Cf}\p{Z}]+/u',' ',$value);
    return is_string($label)&&mb_strlen(trim($label))<=$limit?trim($label):'';
}
/** Historical labels only. Never substitute today's catalog, reference, or owner names. */
function vp3_profile_image_edit_source_credit_v1(array $row): ?array
{
    $json=$row['catalog_json']??null;$owner=(int)($row['owner_user_id']??0);
    if(!is_string($json)||strlen($json)>65536||$owner<1)return null;
    $saved=json_decode($json,true,20);if(!is_array($saved))return null;$references=$saved['references']??null;
    if(!is_array($saved)||($saved['snapshot_version']??null)!==1||($saved['owner_user_id']??null)!==$owner
        ||!is_int($saved['collection_id']??null)||$saved['collection_id']<1
        ||!is_int($saved['catalog_revision']??null)||$saved['catalog_revision']<1
        ||!is_int($saved['selection_asset_id']??null)||$saved['selection_asset_id']<0||($saved['price_cents']??null)!==0
        ||!is_array($references)||!array_is_list($references)||count($references)<1||count($references)>3)return null;
    $catalog=vp3_profile_image_edit_credit_label_v1($saved['catalog_name']??null,100);if($catalog==='')return null;
    $selected=$saved['selection_asset_id'];$names=[];$ids=[];$bytes=0;
    foreach($references as $reference){
        if(!is_array($reference)||!is_int($reference['asset_id']??null)||$reference['asset_id']<1
            ||isset($ids[$reference['asset_id']])||!is_string($reference['title']??null)
            ||strlen($reference['title'])>2000||mb_strlen($reference['title'])>500||!is_int($reference['byte_size']??null)||$reference['byte_size']<1
            ||!in_array($reference['mime_type']??null,['image/jpeg','image/png','image/webp'],true)
            ||!is_string($reference['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$reference['sha256'])
            ||!is_string($reference['revision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$reference['revision'])
            ||!is_int($reference['width']??null)||!is_int($reference['height']??null)
            ||min($reference['width'],$reference['height'])<1||max($reference['width'],$reference['height'])>4096)return null;
        $ids[$reference['asset_id']]=true;$bytes+=$reference['byte_size'];
        $names[]=['title'=>vp3_profile_image_edit_credit_label_v1($reference['title'],500)?:'Untitled image'];
    }
    if($bytes>VP3_PROFILE_IMAGE_CATALOG_REFERENCE_BYTES_V1||($selected>0&&(count($ids)!==1||!isset($ids[$selected]))))return null;
    $config=$row['config_json']??null;
    $config=is_string($config)&&strlen($config)<=65536?json_decode($config,true,20):null;
    $name=vp3_profile_image_edit_credit_label_v1(is_array($config)?($config['source_credit_owner_name']??null):null,200);
    return ['contract'=>'vp3.profile-image-credit.v1','owner_name'=>$name?:'Profile owner','catalog_name'=>$catalog,
        'references'=>$names,'price_cents'=>0];
}
function vp3_profile_image_edit_file_projection_v1(array $row,string $kind,bool $ownerView,?array $ctx=null): array
{
    $result=$kind==='result';$mid=(int)$row[$result?'result_message_id':'visitor_message_id'];$cid=(int)$row['conversation_id'];
    $view=['kind'=>$kind,'request_id'=>$row['request_id'],'source_message_id'=>(int)$row['visitor_message_id'],'root_message_id'=>(int)$row['root_message_id'],
        'filename'=>$result?'edited-photo.png':$row['input_name'],'mime'=>$result?'image/png':$row['input_mime'],'bytes'=>(int)$row[$result?'output_bytes':'input_bytes'],
        'url'=>vp3_profile_image_edit_url_v1($cid,$mid,$ownerView,$ctx),'download_url'=>vp3_profile_image_edit_url_v1($cid,$mid,$ownerView,$ctx,false)];
    if($result&&($credit=vp3_profile_image_edit_source_credit_v1($row))!==null)$view['source_credit']=$credit;
    return $view;
}
/** Call only after the caller has authorized this exact public conversation. */
function vp3_profile_image_edit_messages_v1(PDO $pdo,int $owner,int $cid,array $messages,bool $ownerView=false,?array $ctx=null): array
{
    if(!$messages||!vp3_profile_image_edit_ready_v1($pdo))return $messages;
    $ids=array_values(array_filter(array_map('intval',array_column($messages,'id'))));if(!$ids)return $messages;
    $in=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT request_id,owner_user_id,conversation_id,visitor_message_id,root_message_id,result_message_id,input_name,input_mime,input_bytes,output_bytes,state,catalog_json,config_json FROM profile_image_edits_v1 WHERE owner_user_id=? AND conversation_id=? AND (visitor_message_id IN ($in) OR result_message_id IN ($in))");$q->execute(array_merge([$owner,$cid],$ids,$ids));$map=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$map[(int)$row['visitor_message_id']]=[$row,'input'];if($row['state']==='completed'&&(int)$row['result_message_id']>0)$map[(int)$row['result_message_id']]=[$row,'result'];}
    foreach($messages as &$message){$entry=$map[(int)$message['id']]??null;if($entry&&($message['sender_type']??null)===($entry[1]==='input'?'visitor':'agent'))$message['image_edit']=vp3_profile_image_edit_file_projection_v1($entry[0],$entry[1],$ownerView,$ctx);}unset($message);
    return $messages;
}
function vp3_profile_image_edit_progress_v1(PDO $pdo,int $owner,int $cid,?string $requestId=null): array
{
    if($cid<1||!vp3_profile_image_edit_ready_v1($pdo))return [];
    $exact=$requestId!==null?' AND e.request_id=?':'';
    $q=$pdo->prepare('SELECT e.request_id,e.visitor_message_id,e.result_message_id,e.root_message_id,e.base_message_id,e.state,e.provider_started_at,e.error_code,e.created_at,e.catalog_json,t.state turn_state,r.status run_status,r.lease_expires_at FROM profile_image_edits_v1 e LEFT JOIN profile_agent_handoff_turns_pa3 t ON t.id=e.turn_id LEFT JOIN agent_workflow_runs r ON r.id=t.run_id WHERE e.owner_user_id=? AND e.conversation_id=?'.$exact.' ORDER BY e.visitor_message_id DESC LIMIT '.($requestId!==null?'1':'25'));$q->execute($requestId!==null?[$owner,$cid,$requestId]:[$owner,$cid]);$out=[];
    foreach(array_reverse($q->fetchAll(PDO::FETCH_ASSOC)) as $row){
        $state=vp3_profile_image_edit_state_v1($row);
        $lease=$row['run_status']==='executing'?(strtotime((string)$row['lease_expires_at'].' UTC')?:0)*1000:0;
        $snapshot=json_decode($row['catalog_json'],true);$error=$state==='failed'?'The photo edit could not be completed. It was not automatically retried.':($state==='cancelled'?'The photo edit stopped when the conversation changed.':'');
        $out[]=['request_id'=>$row['request_id'],'input_message_id'=>(int)$row['visitor_message_id'],'result_message_id'=>(int)$row['result_message_id'],'root_message_id'=>(int)$row['root_message_id'],'base_message_id'=>(int)$row['base_message_id'],'state'=>$state,
            'catalog_id'=>(int)($snapshot['collection_id']??0),'image_id'=>(int)($snapshot['selection_asset_id']??0),'started_at_ms'=>$row['provider_started_at']!==null?(strtotime($row['provider_started_at'].' UTC')?:0)*1000:null,
            'active_lease_expires_at'=>$state==='editing'&&$lease>(int)(microtime(true)*1000)?$lease:null,'error'=>$error,'created_at'=>$row['created_at']];
    }
    return $out;
}
function vp3_profile_image_edit_state_v1(array $row): string
{
    $state=match($row['state']){'completed'=>'ready','staged'=>'editing',default=>$row['state']};
    if(in_array($state,['queued','editing'],true)&&in_array($row['turn_state']??null,['owner_claimed','cancelled'],true))return 'cancelled';
    if(in_array($state,['queued','editing'],true)&&(in_array($row['turn_state']??null,['blocked'],true)||in_array($row['run_status']??null,['failed','cancelled'],true)))return 'failed';
    return $state;
}
function vp3_profile_image_edit_public_projection_v1(PDO $pdo,array $ctx,int $cid,array $result): array
{
    $owner=(int)$ctx['owner_user_id'];$result['image_editing']=vp3_profile_image_edit_availability_v1($pdo,$owner,(string)$ctx['profile']['username'],$ctx);$result['image_edits']=vp3_profile_image_edit_progress_v1($pdo,$owner,$cid);
    if(isset($result['messages']))$result['messages']=vp3_profile_image_edit_messages_v1($pdo,$owner,$cid,$result['messages'],false,$ctx);
    if(isset($result['visitor_message_id'])&&vp3_profile_image_edit_ready_v1($pdo)){
        $messages=vp3_profile_image_edit_messages_v1($pdo,$owner,$cid,[['id'=>(int)$result['visitor_message_id'],'sender_type'=>'visitor']],false,$ctx);
        if(isset($messages[0]['image_edit']))$result['image_edit']=$messages[0]['image_edit'];
    }
    return $result;
}
/** Recover a lost multipart admission acknowledgment without replaying or requiring the File object. */
function vp3_profile_image_edit_receipt_v1(PDO $pdo,array $ctx,string $requestId): array
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$requestId))vp3_profile_image_edit_error_v1('Choose a saved photo edit request.');
    $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx);
    if(($ctx['conversation_authority']['transport']??null)!=='native')vp3_profile_agent_public_unavailable_v1();
    $empty=['found'=>false,'client_request_id'=>$requestId];if(!vp3_profile_image_edit_ready_v1($pdo))return $empty;
    $q=$pdo->prepare('SELECT t.* FROM profile_agent_handoff_turns_pa3 t INNER JOIN profile_image_edits_v1 e ON e.turn_id=t.id AND e.visitor_message_id=t.visitor_message_id AND e.owner_user_id=t.owner_user_id AND e.conversation_id=t.conversation_id WHERE t.owner_user_id=? AND t.profile_session_id=? AND t.client_request_id=?');
    $q->execute([(int)$ctx['owner_user_id'],(int)$ctx['session_id'],$requestId]);$turn=$q->fetch(PDO::FETCH_ASSOC);if(!$turn)return $empty;
    if((int)$turn['profile_agent_id']!==(int)$ctx['agent_id']||(int)$turn['visitor_user_id']!==(int)($ctx['visitor']['id']??0)||$turn['transport']!=='native'||!hash_equals((string)$turn['credential_hash'],(string)$ctx['conversation_authority']['credential_hash']))return $empty;
    $cid=(int)$turn['conversation_id'];vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid);
    $state=vp3_profile_agent_public_state_service_v110($pdo,$ctx,$cid);
    // A receipt is exact even when ordinary conversation/status pages have
    // moved beyond this request. Add only its own input and published result.
    $q=$pdo->prepare('SELECT request_id,visitor_message_id,result_message_id FROM profile_image_edits_v1 WHERE owner_user_id=? AND conversation_id=? AND turn_id=? AND visitor_message_id=?');
    $q->execute([(int)$ctx['owner_user_id'],$cid,(int)$turn['id'],(int)$turn['visitor_message_id']]);$image=$q->fetch(PDO::FETCH_ASSOC);if(!$image)return $empty;
    $q=$pdo->prepare("SELECT id,sender_type,message,created_at FROM profile_agent_messages WHERE conversation_id=? AND ((id=? AND sender_type='visitor') OR (id=? AND sender_type='agent')) ORDER BY id");
    $q->execute([$cid,(int)$image['visitor_message_id'],(int)$image['result_message_id']]);$messages=[];
    foreach(array_merge($state['messages']??[],$q->fetchAll(PDO::FETCH_ASSOC)) as $message)$messages[(int)$message['id']]=$message;
    ksort($messages,SORT_NUMERIC);$state['messages']=array_values($messages);
    $result=['found'=>true,'accepted'=>true,'duplicate'=>true,'client_request_id'=>$requestId,'conversation_id'=>$cid,'visitor_message_id'=>(int)$turn['visitor_message_id'],'answer'=>'']+$state;
    vp3_profile_agent_public_live_v1($pdo,$ctx);
    $result=vp3_profile_image_edit_public_projection_v1($pdo,$ctx,$cid,$result);
    if(!in_array($image['request_id'],array_column($result['image_edits'],'request_id'),true)){
        $exact=vp3_profile_image_edit_progress_v1($pdo,(int)$ctx['owner_user_id'],$cid,$image['request_id']);
        $result['image_edits']=array_merge($result['image_edits'],$exact);
        usort($result['image_edits'],static fn(array $a,array $b):int=>$a['input_message_id']<=>$b['input_message_id']);
    }
    return $result;
}
/** Call only after native visitor/owner conversation authorization. */
function vp3_profile_image_edit_file_v1(PDO $pdo,int $owner,int $cid,int $messageId): array
{
    if(!vp3_profile_image_edit_ready_v1($pdo)||$cid<1||$messageId<1)vp3_profile_image_edit_error_v1('Image not found.','PROFILE_IMAGE_NOT_FOUND',404);
    $q=$pdo->prepare("SELECT e.*,m.sender_type FROM profile_image_edits_v1 e INNER JOIN profile_agent_messages m ON m.conversation_id=e.conversation_id AND m.id=? WHERE e.owner_user_id=? AND e.conversation_id=? AND (e.visitor_message_id=? OR (e.result_message_id=? AND e.state='completed'))");$q->execute([$messageId,$owner,$cid,$messageId,$messageId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)vp3_profile_image_edit_error_v1('Image not found.','PROFILE_IMAGE_NOT_FOUND',404);
    $result=(int)$row['result_message_id']===$messageId;
    if($row['sender_type']!==($result?'agent':'visitor'))vp3_profile_image_edit_error_v1('Image not found.','PROFILE_IMAGE_NOT_FOUND',404);
    if($result)$bytes=vp3_profile_image_edit_output_bytes_v1($row);
    elseif((int)$row['base_message_id']>0)$bytes=vp3_profile_image_edit_output_bytes_v1(vp3_profile_image_edit_base_v1($pdo,$owner,$cid,(int)$row['base_message_id']));
    else $bytes=$row['input_content'];
    $expected=$result?$row['output_sha256']:$row['input_sha256'];if(!is_string($bytes)||!hash_equals($expected,hash('sha256',$bytes)))vp3_profile_image_edit_error_v1('Image not found.','PROFILE_IMAGE_NOT_FOUND',404);
    return ['bytes'=>$bytes,'mime'=>$result?'image/png':$row['input_mime'],'name'=>$result?'edited-photo.png':$row['input_name']];
}
function vp3_profile_image_edit_output_v1(PDO $pdo,int $owner,int $cid,int $messageId,bool $preview): never
{
    $file=vp3_profile_image_edit_file_v1($pdo,$owner,$cid,$messageId);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    header('Content-Type: '.($preview?$file['mime']:'application/octet-stream'));header('Content-Length: '.strlen($file['bytes']));
    header('Content-Disposition: '.($preview?'inline':'attachment').'; filename="photo.'.pathinfo($file['name'],PATHINFO_EXTENSION).'"; filename*=UTF-8\'\''.rawurlencode($file['name']));
    header('Cache-Control: private, no-store');header('Cross-Origin-Resource-Policy: same-origin');header("Content-Security-Policy: default-src 'none'; sandbox");header('X-Content-Type-Options: nosniff');echo $file['bytes'];exit;
}
function vp3_profile_image_edit_owner_history_v1(PDO $pdo,array $user,int $limit=20): array
{
    if(!vp3_profile_image_edit_ready_v1($pdo))return ['records'=>[],'total_completed'=>0];$owner=(int)$user['id'];$limit=max(1,min(100,$limit));
    $q=$pdo->prepare('SELECT e.request_id,e.conversation_id,e.visitor_message_id,e.result_message_id,e.root_message_id,e.base_message_id,e.prompt,e.catalog_json,e.state,e.created_at,e.completed_at,t.state turn_state,r.status run_status FROM profile_image_edits_v1 e LEFT JOIN profile_agent_handoff_turns_pa3 t ON t.id=e.turn_id LEFT JOIN agent_workflow_runs r ON r.id=t.run_id WHERE e.owner_user_id=? ORDER BY e.created_at DESC,e.visitor_message_id DESC LIMIT '.$limit);$q->execute([$owner]);$records=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$catalog=json_decode($row['catalog_json'],true);$result=$row['state']==='completed'&&(int)$row['result_message_id']>0;
        $records[]=['request_id'=>$row['request_id'],'catalog_name'=>(string)($catalog['catalog_name']??('Photo style #'.(int)($catalog['collection_id']??0))),'source_count'=>count($catalog['references']??[]),'status'=>vp3_profile_image_edit_state_v1($row),
            'prompt'=>mb_substr((string)$row['prompt'],0,2000),'rightsowner_user_id'=>(int)($catalog['owner_user_id']??$owner),'collection_id'=>(int)($catalog['collection_id']??0),
            'references'=>array_map(static fn(array $reference):array=>['asset_id'=>(int)$reference['asset_id'],'title'=>(string)$reference['title']],$catalog['references']??[]),
            'created_at'=>$row['created_at'],'completed_at'=>$row['completed_at'],'conversation_id'=>(int)$row['conversation_id'],'input_message_id'=>(int)$row['visitor_message_id'],'result_message_id'=>(int)$row['result_message_id'],'root_message_id'=>(int)$row['root_message_id'],'base_message_id'=>(int)$row['base_message_id'],
            'input_url'=>vp3_profile_image_edit_url_v1((int)$row['conversation_id'],(int)$row['visitor_message_id'],true),'output_url'=>$result?vp3_profile_image_edit_url_v1((int)$row['conversation_id'],(int)$row['result_message_id'],true):null];
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM profile_image_edits_v1 WHERE owner_user_id=? AND state='completed' AND result_message_id IS NOT NULL");$q->execute([$owner]);return ['records'=>$records,'total_completed'=>(int)$q->fetchColumn()];
}
