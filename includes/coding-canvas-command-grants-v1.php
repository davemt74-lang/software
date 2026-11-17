<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-commands-v1.php';
require_once __DIR__.'/schema-metadata.php';

function vp3_coding_canvas_command_grants_install_v1(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $index=$mysql?', KEY idx_vp3_canvas_data_review_v1 (owner_user_id,agent_id,app_data_review,canvas_id)':'';
    $options=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_coding_canvas_command_grants_v1 (
        canvas_id CHAR(36) PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
        app_data_access TINYINT NOT NULL DEFAULT 1,
        app_data_review TINYINT NOT NULL DEFAULT 0,
        app_data_review_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        gmail_access TINYINT NOT NULL DEFAULT 0,
        gmail_review TINYINT NOT NULL DEFAULT 0,
        gmail_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        gmail_scope_json TEXT NULL,
        gmail_connection_id BIGINT UNSIGNED NULL,
        gmail_connection_version CHAR(64) NULL,
        private_sources_json TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (canvas_id) REFERENCES vp3_coding_canvases_v1(id) ON DELETE CASCADE".$index.")".$options);
    if(!$mysql)$pdo->exec('CREATE INDEX IF NOT EXISTS idx_vp3_canvas_data_review_v1
        ON vp3_coding_canvas_command_grants_v1(owner_user_id,agent_id,app_data_review,canvas_id)');
    if(!vp3_coding_canvas_source_grants_ready_v1($pdo)){
        try{$pdo->exec('ALTER TABLE vp3_coding_canvas_command_grants_v1 ADD COLUMN private_sources_json TEXT NULL');}
        catch(PDOException $error){if(!vp3_coding_canvas_source_grants_ready_v1($pdo))throw $error;}
    }
}

function vp3_coding_canvas_source_grants_ready_v1(PDO $pdo): bool
{
    return schema_metadata_column_exists($pdo,'vp3_coding_canvas_command_grants_v1','private_sources_json');
}

function vp3_coding_canvas_command_grants_ready_v1(PDO $pdo): bool
{
    try{
        return $pdo->query('SELECT canvas_id,owner_user_id,agent_id,conversation_id,generation,
            app_data_access,app_data_review,app_data_review_generation,gmail_access,gmail_review,gmail_generation,
            gmail_scope_json,gmail_connection_id,gmail_connection_version,updated_at
            FROM vp3_coding_canvas_command_grants_v1 WHERE 1=0')!==false;
    }catch(PDOException $error){
        return false;
    }
}

function vp3_coding_canvas_command_missing_table_v1(PDOException $error): bool
{
    return $error->getCode()==='42S02'||((int)($error->errorInfo[1]??0)===1
        && str_contains($error->getMessage(),'no such table: vp3_coding_canvas_command_grants_v1'));
}

/** The caller supplies an already authorized, immutable Canvas row. */
function vp3_coding_canvas_command_grant_row_v1(PDO $pdo,array $canvas,bool $lock=false): ?array
{
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    try{
        $query=$pdo->prepare('SELECT * FROM vp3_coding_canvas_command_grants_v1 WHERE canvas_id=?'.$suffix);
        $query->execute([$canvas['id']]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
    }catch(PDOException $error){
        if(vp3_coding_canvas_command_missing_table_v1($error))return null;
        throw $error;
    }
    if(!$row)return null;
    foreach(['owner_user_id','agent_id','conversation_id'] as $field){
        if((int)$row[$field]!== (int)$canvas[$field])throw new RuntimeException('Canvas command scope changed.',409);
    }
    return $row;
}

function vp3_coding_canvas_command_scope_v1(mixed $scope): array
{
    $scope=$scope instanceof stdClass?(array)$scope:$scope;
    if(!is_array($scope))throw new InvalidArgumentException('Choose a selected Gmail search or specific threads.',422);
    $mode=$scope['mode']??null;
    if($mode==='search'&&!array_diff(array_keys($scope),['mode','query'])){
        $query=is_string($scope['query']??null)?trim($scope['query']):'';
        if($query===''||strlen($query)>256||!preg_match('//u',$query)||preg_match('/[\x00-\x1f\x7f]/',$query)){
            throw new InvalidArgumentException('Enter a specific Gmail search within 256 bytes.',422);
        }
        return ['mode'=>'search','query'=>$query];
    }
    if($mode==='threads'&&!array_diff(array_keys($scope),['mode','thread_ids'])){
        $ids=$scope['thread_ids']??null;
        if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>12){
            throw new InvalidArgumentException('Choose up to 12 exact Gmail thread IDs.',422);
        }
        foreach($ids as $id){
            if(!is_string($id)||!preg_match('/\A[a-zA-Z0-9_-]{1,190}\z/D',$id)){
                throw new InvalidArgumentException('Choose up to 12 valid Gmail thread IDs.',422);
            }
        }
        $ids=array_values(array_unique($ids));
        sort($ids,SORT_STRING);
        return ['mode'=>'threads','thread_ids'=>$ids];
    }
    throw new InvalidArgumentException('Choose a selected Gmail search or specific threads.',422);
}

function vp3_coding_canvas_command_settings_input_v1(array|stdClass $input): array
{
    $input=(array)$input;
    if(array_diff(array_keys($input),['app_data','gmail','sources'])){
        throw new InvalidArgumentException('Choose exact Canvas command permissions.',422);
    }
    $settings=[];
    foreach(['app_data','gmail'] as $command){
        $entry=$input[$command]??null;
        if($entry instanceof stdClass)$entry=(array)$entry;
        $keys=$command==='gmail'?['app_access','specialist_review','scope']:['app_access','specialist_review'];
        if(!is_array($entry)||array_diff(array_keys($entry),$keys)||!is_bool($entry['app_access']??null)
            ||!is_bool($entry['specialist_review']??null)){
            throw new InvalidArgumentException('Choose separate app and specialist permissions.',422);
        }
        $settings[$command]=['app_access'=>$entry['app_access'],'specialist_review'=>$entry['specialist_review']];
        if($command==='gmail')$settings[$command]['scope']=$entry['app_access']||$entry['specialist_review']
            ?vp3_coding_canvas_command_scope_v1($entry['scope']??null):null;
    }
    if(array_key_exists('sources',$input))$settings['sources']=vp3_coding_canvas_source_grant_flags_v1($input['sources']);
    return $settings;
}

function vp3_coding_canvas_source_grant_flags_v1(mixed $input): array
{
    if($input instanceof stdClass)$input=(array)$input;
    $commands=vp3_coding_canvas_private_source_commands_v1();
    if(!is_array($input)||array_diff(array_keys($input),$commands)){
        throw new InvalidArgumentException('Choose valid private Canvas source permissions.',422);
    }
    $settings=[];
    foreach($commands as $command){
        $flag=array_key_exists($command,$input)?$input[$command]:false;
        if(!is_bool($flag))throw new InvalidArgumentException('Choose whether this Canvas can read each source.',422);
        $settings[$command]=$flag;
    }
    return $settings;
}

function vp3_coding_canvas_command_settings_projection_v1(array $canvas,?array $row): array
{
    $generation=(int)($row['generation']??0);
    $scope=null;
    if(!empty($row['gmail_access'])||!empty($row['gmail_review'])){
        try{
            $scope=vp3_coding_canvas_command_scope_v1(json_decode((string)$row['gmail_scope_json'],true,8,JSON_THROW_ON_ERROR));
        }catch(JsonException|InvalidArgumentException $error){
            throw new RuntimeException('The saved Gmail scope could not be verified.',409,$error);
        }
    }
    try{
        $sourceFlags=empty($row['private_sources_json'])?[]:json_decode($row['private_sources_json'],true,6,JSON_THROW_ON_ERROR);
        $sources=vp3_coding_canvas_source_grant_flags_v1($sourceFlags);
    }catch(JsonException|InvalidArgumentException $error){
        throw new RuntimeException('The saved Canvas source permissions could not be verified.',409,$error);
    }
    return ['revision'=>hash('sha256',$canvas['id'].':'.$generation),
        'app_data'=>['app_access'=>$row===null||(bool)$row['app_data_access'],
            'specialist_review'=>(bool)($row['app_data_review']??false)],
        'gmail'=>['app_access'=>(bool)($row['gmail_access']??false),'specialist_review'=>(bool)($row['gmail_review']??false),
            'scope'=>$scope],'sources'=>$sources];
}

/** Read only after the caller verifies ownership and scope, including inside Canvas atomic saves. */
function vp3_coding_canvas_command_settings_v1(PDO $pdo,array $canvas): array
{
    return vp3_coding_canvas_command_settings_projection_v1($canvas,vp3_coding_canvas_command_grant_row_v1($pdo,$canvas));
}

function vp3_coding_canvas_command_canvas_v1(PDO $pdo,array $user,int $agentId,int $cid,string $canvasId): array
{
    require_once __DIR__.'/coding-canvas-jobs-v1.php';
    $live=vp3_coding_canvas_scope_v1($pdo,$user,$agentId,$cid);
    vp3_coding_canvas_authority_v1($pdo,$live,$agentId);
    return [$live,vp3_coding_canvas_require_row_v1($pdo,(int)$live['id'],$agentId,vp3_coding_canvas_id_v1($canvasId))];
}

function vp3_coding_canvas_commands_v1(PDO $pdo,array $user,int $agentId,int $cid,string $canvasId): array
{
    [, $canvas]=vp3_coding_canvas_command_canvas_v1($pdo,$user,$agentId,$cid,$canvasId);
    return vp3_coding_canvas_command_settings_v1($pdo,$canvas);
}

function vp3_coding_canvas_command_gmail_pin_v1(PDO $pdo,array $user,string|int $selector='gmail',bool $lock=false): array
{
    require_once __DIR__.'/agent-connections-v1.php';
    if(!agent_connections_schema_ready_v1($pdo))throw new RuntimeException('Gmail connection storage is unavailable.',503);
    $row=agent_connections_find_v1($pdo,(int)$user['id'],$selector,$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
    if(!agent_connection_gmail_inbox_authority_v1($user,$row)||!is_string($row['authorization_key']??null)
        ||!preg_match('/\A[a-f0-9]{64}\z/D',$row['authorization_key'])){
        throw new RuntimeException('Connect Gmail and enable Gmail read access first.',403);
    }
    return ['connection_id'=>(int)$row['id'],'connection_version'=>hash('sha256',$row['authorization_key'])];
}

function vp3_coding_canvas_commands_save_v1(PDO $pdo,array $user,int $agentId,int $cid,string $canvasId,
    array|stdClass $input,string $expectedRevision): array
{
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$expectedRevision))throw new InvalidArgumentException('Open the current Canvas permissions again.',422);
    $settings=vp3_coding_canvas_command_settings_input_v1($input);
    [$live,$canvas]=vp3_coding_canvas_command_canvas_v1($pdo,$user,$agentId,$cid,$canvasId);
    if(!vp3_coding_canvas_command_grants_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade for Canvas command permissions.',503);
    $owner=(int)$live['id'];
    return vp3_coding_canvas_atomic_v1($pdo,$owner,static function()use($pdo,$live,$agentId,$cid,$canvas,$settings,$expectedRevision,$owner):array{
        $live=vp3_coding_canvas_scope_v1($pdo,$live,$agentId,$cid);
        vp3_coding_canvas_authority_v1($pdo,$live,$agentId);
        $canvas=vp3_coding_canvas_require_row_v1($pdo,$owner,$agentId,$canvas['id'],true);
        // Connection policy revocation locks Gmail before it clears dependent Canvas grants.
        $pin=$settings['gmail']['scope']===null?['connection_id'=>null,'connection_version'=>null]:
            vp3_coding_canvas_command_gmail_pin_v1($pdo,$live,'gmail',true);
        $old=vp3_coding_canvas_command_grant_row_v1($pdo,$canvas,true);
        $current=vp3_coding_canvas_command_settings_projection_v1($canvas,$old);
        if(!hash_equals($current['revision'],$expectedRevision))throw new RuntimeException('Canvas permissions changed. Open them again.',409);
        $settings['sources']??=$current['sources'];
        $sourcesChanged=$settings['sources']!==$current['sources'];
        if($sourcesChanged&&!vp3_coding_canvas_source_grants_ready_v1($pdo)){
            throw new RuntimeException('Run Database Upgrade for private Canvas source permissions.',503);
        }
        $gmailChanged=$settings['gmail']!==$current['gmail']||$pin['connection_id']!==
            (isset($old['gmail_connection_id'])?(int)$old['gmail_connection_id']:null)
            ||$pin['connection_version']!==($old['gmail_connection_version']??null);
        if($settings['app_data']===$current['app_data']&&!$gmailChanged&&!$sourcesChanged)return $current;
        $generation=(int)($old['generation']??0)+1;
        $reviewChanged=$settings['app_data']['specialist_review']!==$current['app_data']['specialist_review'];
        $reviewGeneration=(int)($old['app_data_review_generation']??0)+(int)$reviewChanged;
        $gmailGeneration=(int)($old['gmail_generation']??0)+(int)$gmailChanged;
        $values=[$generation,(int)$settings['app_data']['app_access'],(int)$settings['app_data']['specialist_review'],
            $reviewGeneration,(int)$settings['gmail']['app_access'],(int)$settings['gmail']['specialist_review'],$gmailGeneration,
            $settings['gmail']['scope']===null?null:json_encode($settings['gmail']['scope'],JSON_THROW_ON_ERROR),
            $pin['connection_id'],$pin['connection_version']];
        if($old===null){
            $query=$pdo->prepare('INSERT INTO vp3_coding_canvas_command_grants_v1
                (canvas_id,owner_user_id,agent_id,conversation_id,generation,app_data_access,app_data_review,app_data_review_generation,
                gmail_access,gmail_review,gmail_generation,gmail_scope_json,gmail_connection_id,gmail_connection_version)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $query->execute([$canvas['id'],$owner,$agentId,(int)$canvas['conversation_id'],...$values]);
        }else{
            $query=$pdo->prepare('UPDATE vp3_coding_canvas_command_grants_v1 SET generation=?,app_data_access=?,app_data_review=?,
                app_data_review_generation=?,gmail_access=?,gmail_review=?,gmail_generation=?,gmail_scope_json=?,gmail_connection_id=?,
                gmail_connection_version=?,updated_at=CURRENT_TIMESTAMP WHERE canvas_id=?');
            $query->execute([...$values,$canvas['id']]);
        }
        if($sourcesChanged){
            $pdo->prepare('UPDATE vp3_coding_canvas_command_grants_v1 SET private_sources_json=? WHERE canvas_id=?')
                ->execute([json_encode($settings['sources'],JSON_THROW_ON_ERROR),$canvas['id']]);
        }
        if($reviewChanged){
            require_once __DIR__.'/agent-coordination-source-change-v1.php';
            vp3_coordination_source_changed_v1($pdo,$owner,$agentId);
        }
        return ['revision'=>hash('sha256',$canvas['id'].':'.$generation)]+$settings;
    });
}

function vp3_coding_canvas_command_source_v1(PDO $pdo,array $user,int $agentId,int $cid,string $canvasId,int $revision,string $command): array
{
    $descriptor=vp3_coding_canvas_command_library_v1()[$command]??null;
    if($descriptor===null||in_array($command,['app-data','gmail'],true)||$cid<1){
        throw new InvalidArgumentException('Choose a Canvas data command in Agent Chat.',422);
    }
    [$live,$canvas]=vp3_coding_canvas_command_canvas_v1($pdo,$user,$agentId,$cid,$canvasId);
    if((int)$canvas['active_revision']!==$revision||$revision<1){
        throw new RuntimeException('Open the currently applied Canvas revision.',409);
    }
    $artifact=vp3_coding_canvas_revision_artifact_v1($pdo,$canvas,$revision);
    if(!in_array($command,$artifact['commands'],true))throw new RuntimeException('This Canvas revision did not request the command.',403);
    if($descriptor['visibility']==='public'){
        require_once __DIR__.'/coding-canvas-public-sources-v1.php';
        return vp3_coding_canvas_public_command_data_v1($pdo,(int)$live['id'],$command);
    }
    $settings=vp3_coding_canvas_command_settings_v1($pdo,$canvas);
    if(empty($settings['sources'][$command]))throw new RuntimeException('This Canvas source permission is off.',403);
    require_once __DIR__.'/coding-canvas-private-sources-v1.php';
    $data=vp3_coding_canvas_private_source_v1($pdo,$live,$agentId,$cid,$command);
    $current=vp3_coding_canvas_require_row_v1($pdo,(int)$live['id'],$agentId,$canvas['id']);
    if((int)$current['active_revision']!==$revision
        ||!hash_equals($settings['revision'],vp3_coding_canvas_command_settings_v1($pdo,$current)['revision'])){
        throw new RuntimeException('This Canvas or its permission changed. Reopen the app.',409);
    }
    return $data;
}

function vp3_coding_canvas_command_grant_v1(PDO $pdo,array $user,int $agentId,int $cid,string $canvasId,int $revision,
    string $command,string $purpose='app_access'): array
{
    $key=match($command){'app-data'=>'app_data','gmail'=>'gmail',default=>null};
    if($key===null||!in_array($purpose,['app_access','specialist_review'],true)||$revision<1){
        throw new InvalidArgumentException('Choose a saved Canvas command and revision.',422);
    }
    [$live,$canvas]=vp3_coding_canvas_command_canvas_v1($pdo,$user,$agentId,$cid,$canvasId);
    $active=(int)$canvas['active_revision']===$revision;
    $preview=(int)$canvas['draft_revision']===$revision;
    if((!$active&&!$preview)||($purpose==='specialist_review'&&(!$active||$cid<1||(int)$canvas['conversation_id']!==$cid))
        ||($key==='gmail'&&(!$active||$cid<1))){
        throw new RuntimeException('Open the current authorized Canvas revision.',409);
    }
    $row=vp3_coding_canvas_command_grant_row_v1($pdo,$canvas);
    $settings=vp3_coding_canvas_command_settings_projection_v1($canvas,$row)[$key];
    if(!$settings[$purpose])throw new RuntimeException('This Canvas command permission is off.',403);
    if($key==='gmail'||$purpose==='specialist_review'){
        vp3_coding_canvas_state_v1($pdo,$canvas);
        $artifact=vp3_coding_canvas_revision_artifact_v1($pdo,$canvas,$revision);
        if($key==='gmail'&&!in_array($command,$artifact['commands'],true)){
            throw new RuntimeException('This Canvas revision did not request the command.',403);
        }
    }
    if($key==='app_data')return $settings+['generation'=>(int)($row['app_data_review_generation']??0)];
    if((int)($row['gmail_connection_id']??0)<1)throw new RuntimeException('Gmail access changed. Approve the app scope again.',409);
    $pin=vp3_coding_canvas_command_gmail_pin_v1($pdo,$live,(int)$row['gmail_connection_id']);
    if(!hash_equals((string)$row['gmail_connection_version'],$pin['connection_version'])){
        throw new RuntimeException('Gmail access changed. Approve the app scope again.',409);
    }
    return $settings+$pin+['generation'=>(int)$row['gmail_generation']];
}

/** Linked Gmail changes revoke only Gmail, leaving this app's local data and review state intact. */
function vp3_coding_canvas_command_gmail_revoke_v1(PDO $pdo,int $owner,int $connection,string $authorizationKey): void
{
    try{
        $query=$pdo->prepare('UPDATE vp3_coding_canvas_command_grants_v1 SET gmail_access=0,gmail_review=0,
            gmail_scope_json=NULL,gmail_connection_id=NULL,gmail_connection_version=NULL,
            generation=generation+1,gmail_generation=gmail_generation+1,updated_at=CURRENT_TIMESTAMP
            WHERE owner_user_id=? AND gmail_connection_id=? AND gmail_connection_version=?');
        $query->execute([$owner,$connection,hash('sha256',$authorizationKey)]);
    }catch(PDOException $error){
        if(!vp3_coding_canvas_command_missing_table_v1($error))throw $error;
    }
}
