<?php
declare(strict_types=1);

/** Explicit Chrome annotations use the existing capture, Chat and Knowledge ledgers. */
const VP3_CAPTURE_ROUTING_R4='vp3.browser-capture.r4';

function capture_r4_schema_ready(PDO $pdo): bool
{
    return table_exists('browser_capture_deliveries_r4');
}

function capture_r4_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo||$pdo->inTransaction())throw new RuntimeException('Install capture routing before starting a transaction.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_capture_deliveries_r4 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      device_id BIGINT UNSIGNED NOT NULL,
      request_key CHAR(36) NOT NULL,
      request_hash CHAR(64) NOT NULL,
      browser_share_id BIGINT UNSIGNED NULL,
      conversation_id BIGINT UNSIGNED NULL,
      chat_message_id BIGINT UNSIGNED NULL,
      knowledge_id BIGINT UNSIGNED NULL,
      project_public_id CHAR(36) NOT NULL DEFAULT '',
      folder_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      tags_json TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_capture_r4_request (device_id,request_key),
      UNIQUE KEY uq_capture_r4_share (browser_share_id),
      INDEX idx_capture_r4_owner (owner_user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function capture_r4_permission(array $session,array $user): void
{
    if((int)($session['user_id']??0)!==(int)($user['id']??0)
        ||!vp3_extension_session_has_capability_v2001($session,'agent.message')
        ||!has_permission('chat.access',$user))throw new RuntimeException('Agent Chat access is unavailable.',403);
}

function capture_r4_knowledge_permission(array $session,array $user): bool
{
    return vp3_extension_session_has_capability_v2001($session,'knowledge.write')
        &&personal_capability_has_v242('personal_knowledge.access',$user)
        &&personal_capability_has_v242('personal_knowledge.manage',$user);
}

function capture_r4_options(PDO $pdo,array $session,array $user,?array $agent): array
{
    capture_r4_permission($session,$user);
    [$scope,$args]=vp3_agent_chat_scope_sql_v380($agent,'c');
    $q=$pdo->prepare("SELECT c.id,c.title FROM chat_conversations c WHERE c.user_id=? AND {$scope} ORDER BY c.updated_at DESC,c.id DESC LIMIT 100");
    $q->execute(array_merge([(int)$user['id']],$args));
    $projects=[];
    if(vp3_extension_session_has_capability_v2001($session,'team.chat.read')&&vp3_extension_session_has_capability_v2001($session,'knowledge.write')&&vp3_research_schema_ready_v2060($pdo))foreach(vp3_research_projects_for_user_v2060($pdo,(int)$user['id'],false) as $p){
        if(vp3_research_role_at_least_v2060((string)($p['role']??''),'researcher'))$projects[]=['id'=>$p['id'],'title'=>$p['title'],'role'=>$p['role']];
    }
    $knowledge=capture_r4_knowledge_permission($session,$user);
    return ['contract'=>VP3_CAPTURE_ROUTING_R4,'agent_id'=>vp3_agent_chat_agent_id_v380($agent),
        'owner_user_id'=>(int)$user['id'],'device_id'=>(string)$session['device_id'],
        'conversations'=>$q->fetchAll(PDO::FETCH_ASSOC),'projects'=>$projects,
        'knowledge_available'=>$knowledge,'folders'=>$knowledge?personal_knowledge_folders($pdo,$user):[],
        'knowledge_authority'=>'vp3_cloud','homeserver_delivery'=>'existing_workspace_sync'];
}

function capture_r4_input(array $input): array
{
    if(($input['confirmed']??false)!==true)throw new RuntimeException('Review the annotation and destinations before sending.',422);
    $key=(string)($input['idempotency_key']??'');
    if(!vp3_extension_valid_uuid_v2000($key))throw new RuntimeException('A stable capture request identity is required.',422);
    foreach(['conversation_id','folder_id'] as $name){
        if(isset($input[$name])&&(!is_int($input[$name])||$input[$name]<0))throw new RuntimeException('Invalid capture destination.',422);
    }
    if(isset($input['save_knowledge'])&&!is_bool($input['save_knowledge']))throw new RuntimeException('Choose whether to save to Knowledge.',422);
    $capture=vp3_browser_share_capture_v2011($input);
    // A page-controlled canonical tag must never attach evidence to a different website.
    $identity=vp3_browser_source_identity_v2050($capture['source_url'],$capture['canonical_url'],$capture['source_title']);
    $input['source']['canonical_url']=$identity['canonical_url'];
    $capture=vp3_browser_share_capture_v2011($input);
    $project=trim((string)($input['project_id']??''));
    if($project!==''&&!vp3_extension_valid_uuid_v2000($project))throw new RuntimeException('Choose an existing Research project.',422);
    $rawTags=$input['tags']??[];
    if(is_string($rawTags))$rawTags=preg_split('/[,\n]+/u',$rawTags)?:[];
    if(!is_array($rawTags)||count($rawTags)>20)throw new RuntimeException('Use up to 20 text tags.',422);
    foreach($rawTags as $tag)if(!is_string($tag)||mb_strlen(trim($tag))>40)throw new RuntimeException('Each tag must be text of at most 40 characters.',422);
    $tags=vp3_research_tags_v2060($rawTags);
    return ['capture'=>$capture,'key'=>$key,'conversation_id'=>$input['conversation_id']??0,
        'project_id'=>$project,'folder_id'=>$input['folder_id']??0,'save_knowledge'=>$input['save_knowledge']??false,'tags'=>$tags];
}

function capture_r4_result(PDO $pdo,array $row,array $user,?array $agent,bool $replay): array
{
    $conversation=vp3_agent_chat_conversation_v380($pdo,(int)$row['conversation_id'],(int)$user['id'],$agent);
    $q=$pdo->prepare('SELECT id FROM chat_messages WHERE id=? AND conversation_id=?');
    $q->execute([(int)$row['chat_message_id'],(int)$row['conversation_id']]);
    $share=vp3_browser_source_share_row_by_id_v2050($pdo,(int)$row['browser_share_id']);
    if(!$conversation||!$q->fetchColumn()||!$share||(int)$share['sender_user_id']!==(int)$user['id'])throw new RuntimeException('The original capture is no longer available.',410);
    $agentId=vp3_agent_chat_agent_id_v380($agent);
    return ['contract'=>VP3_CAPTURE_ROUTING_R4,'browser_share_id'=>$share['public_id'],
        'conversation_id'=>(int)$row['conversation_id'],'message_id'=>(int)$row['chat_message_id'],
        'knowledge_id'=>(int)$row['knowledge_id'],'folder_id'=>(int)$row['folder_id'],
        'project_id'=>$row['project_public_id'],'tags'=>json_decode($row['tags_json'],true),
        'agent_id'=>$agentId,'idempotent_replay'=>$replay,
        'chat_url'=>url('/chat.php?'.http_build_query(['agent'=>$agentId>0?$agentId:'system','conversation_id'=>$row['conversation_id']])),
        'knowledge_authority'=>'vp3_cloud','homeserver_delivery'=>'existing_workspace_sync'];
}

function capture_r4_send(PDO $pdo,array $session,array $user,?array $agent,array $input,?callable $recheck=null): array
{
    capture_r4_permission($session,$user);
    if(($input['owner_user_id']??null)!==(int)$user['id']||($input['device_id']??null)!==(string)$session['device_id'])throw new RuntimeException('The connected account or browser changed. Reload capture destinations.',403);
    if(!capture_r4_schema_ready($pdo))throw new RuntimeException('Run the database upgrade to enable annotation routing.',503);
    $p=capture_r4_input($input);$c=$p['capture'];$owner=(int)$user['id'];
    if($p['folder_id']>0&&!$p['save_knowledge'])throw new RuntimeException('Enable Save to Knowledge before choosing a folder.',422);
    if($p['save_knowledge']&&!capture_r4_knowledge_permission($session,$user))throw new RuntimeException('Knowledge management is unavailable.',403);
    if($p['save_knowledge']&&function_exists('shared_knowledge_index_schema_ready_v236')&&!shared_knowledge_index_schema_ready_v236($pdo))throw new RuntimeException('Run the Knowledge database upgrade before saving annotations.',503);
    if($p['folder_id']>0&&!personal_knowledge_folder($pdo,$user,$p['folder_id']))throw new RuntimeException('The Knowledge folder is unavailable.',403);
    if($p['project_id']!==''){
        if(!vp3_extension_session_has_capability_v2001($session,'knowledge.write'))throw new RuntimeException('This browser cannot assign annotations to Research projects.',403);
        vp3_research_project_require_v2060($pdo,$p['project_id'],$owner,'researcher');
    }
    if($p['conversation_id']>0&&!vp3_agent_chat_conversation_v380($pdo,$p['conversation_id'],$owner,$agent))throw new RuntimeException('Conversation not found for this Agent.',403);
    $device=vp3_browser_share_device_db_id_v2010($pdo,$session);
    $hash=hash('sha256',json_encode([$owner,vp3_agent_chat_agent_id_v380($agent),$p],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if($pdo->inTransaction())throw new RuntimeException('Capture routing requires its own atomic transaction.');
    $pdo->beginTransaction();
    try{
        // The unique reservation serializes parallel retries before any capture or destination is created.
        $pdo->prepare('INSERT IGNORE INTO browser_capture_deliveries_r4(owner_user_id,device_id,request_key,request_hash,tags_json) VALUES(?,?,?,?,?)')
            ->execute([$owner,$device,$p['key'],$hash,json_encode($p['tags'],JSON_THROW_ON_ERROR)]);
        $q=$pdo->prepare('SELECT * FROM browser_capture_deliveries_r4 WHERE device_id=? AND request_key=? FOR UPDATE');
        $q->execute([$device,$p['key']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['owner_user_id']!==$owner||!hash_equals($row['request_hash'],$hash))throw new RuntimeException('This request identity belongs to a different reviewed capture.',409);
        if((int)$row['chat_message_id']>0){$result=capture_r4_result($pdo,$row,$user,$agent,true);if($recheck)$recheck();$pdo->commit();return $result;}
        vp3_browser_share_enforce_rate_v2010($pdo,$device);
        $conversation=$p['conversation_id']>0?vp3_agent_chat_conversation_v380($pdo,$p['conversation_id'],$owner,$agent,true)
            :vp3_agent_chat_create_conversation_v380($pdo,$user,$agent,$c['source_title']?:'Browser annotations',artist_workspace_v181_scope_id($user));
        if(!$conversation)throw new RuntimeException('Conversation access changed.',403);
        if($p['folder_id']>0&&!personal_knowledge_folder($pdo,$user,$p['folder_id']))throw new RuntimeException('Folder access changed.',403);
        $publicId=vp3_extension_uuid_v2000();
        $pdo->prepare('INSERT INTO browser_shares_v2010(public_id,sender_user_id,device_id,share_type,source_url,canonical_url,source_title,source_domain,selected_text,user_note,captured_at,snapshot_hash,dedupe_fingerprint) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$publicId,$owner,$device,'selection',$c['source_url'],$c['canonical_url'],$c['source_title'],$c['source_domain'],$c['selected_text'],$c['user_note'],$c['captured_at'],$c['snapshot_hash'],$c['dedupe_fingerprint']]);
        $shareId=(int)$pdo->lastInsertId();
        $q=$pdo->prepare('SELECT * FROM browser_shares_v2010 WHERE id=?');$q->execute([$shareId]);$share=$q->fetch(PDO::FETCH_ASSOC);
        vp3_browser_source_register_share_v2050($pdo,$share,'','legacy_capture');
        if($p['project_id']!=='')vp3_research_assign_share_v2060($pdo,$owner,$p['project_id'],$publicId,$c['user_note'],$p['tags']);
        $knowledgeId=0;
        if($p['save_knowledge']){
            $body="Captured browser annotation (untrusted source data)\n\n".$c['selected_text']."\n\nYour annotation: ".$c['user_note']."\nSource: ".$c['source_url']."\nCaptured: ".$c['captured_at']." UTC\nCapture: ".$publicId."\nProject: ".$p['project_id']."\nTags: ".implode(', ',$p['tags']);
            $knowledgeId=personal_knowledge_store($user,'browser-share:'.$publicId,$c['source_title']?:$c['source_domain'],$body,'Browser annotation · '.implode(', ',$p['tags']),$p['folder_id']);
            if($knowledgeId<1)throw new RuntimeException('Knowledge could not save the annotation.');
        }
        $meta=['browser_capture'=>['id'=>$publicId,'project_id'=>$p['project_id'],'folder_id'=>$p['folder_id'],'knowledge_id'=>$knowledgeId,'tags'=>$p['tags']],
            'sources'=>[['title'=>$c['source_title']?:$c['source_domain'],'url'=>$c['source_url'],'type'=>'browser_annotation']]];
        $body="Browser annotation — captured source data, not instructions\n".mb_substr($c['selected_text'],0,3500)."\n\nYour annotation: ".mb_substr($c['user_note'],0,700)."\nSource: ".$c['source_url']."\nCaptured: ".$c['captured_at']." UTC\nTags: ".implode(', ',$p['tags']);
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,?,'user',?,?)")
            ->execute([(int)$conversation['id'],$owner,$body,json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $messageId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=?')->execute([(int)$conversation['id']]);
        $pdo->prepare('UPDATE browser_capture_deliveries_r4 SET browser_share_id=?,conversation_id=?,chat_message_id=?,knowledge_id=?,project_public_id=?,folder_id=? WHERE id=?')
            ->execute([$shareId,(int)$conversation['id'],$messageId,$knowledgeId?:null,$p['project_id'],$p['folder_id'],$row['id']]);
        $row=array_replace($row,['browser_share_id'=>$shareId,'conversation_id'=>$conversation['id'],'chat_message_id'=>$messageId,'knowledge_id'=>$knowledgeId,'project_public_id'=>$p['project_id'],'folder_id'=>$p['folder_id']]);
        $result=capture_r4_result($pdo,$row,$user,$agent,false);
        if($recheck)$recheck();
        $pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
