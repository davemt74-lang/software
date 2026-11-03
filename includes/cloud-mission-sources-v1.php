<?php
declare(strict_types=1);

function cloud_mission_sources_admin_v1(array $user): bool
{
    return has_permission('admin.access',$user)&&has_permission('ai.manage',$user);
}
function cloud_mission_sources_schema_v1(PDO $pdo): void
{
    static $ready=[];$key=spl_object_id($pdo);if(isset($ready[$key]))return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_sources_v1 (
        source_id CHAR(36) NOT NULL PRIMARY KEY,owner_user_id BIGINT NOT NULL,scope VARCHAR(12) NOT NULL,
        label VARCHAR(120) NOT NULL,category VARCHAR(50) NOT NULL,url VARCHAR(1800) NOT NULL,kind VARCHAR(12) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',revision INT NOT NULL DEFAULT 1,
        created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_cloud_mission_source_ops_v1 (
        owner_user_id BIGINT NOT NULL,request_id CHAR(36) NOT NULL,request_hash CHAR(64) NOT NULL,source_id CHAR(36) NOT NULL,
        PRIMARY KEY(owner_user_id,request_id))");
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('CREATE INDEX IF NOT EXISTS idx_cloud_mission_sources_owner ON agent_cloud_mission_sources_v1(scope,owner_user_id,status)');elseif(!agent_job_engine_index_exists_v1900($pdo,'agent_cloud_mission_sources_v1','idx_cloud_mission_sources_owner'))$pdo->exec('CREATE INDEX idx_cloud_mission_sources_owner ON agent_cloud_mission_sources_v1(scope,owner_user_id,status)');
    $defaults=json_decode((string)file_get_contents(__DIR__.'/mission-source-master-v1.json'),true,16,JSON_THROW_ON_ERROR);
    $existing=array_flip($pdo->query("SELECT source_id FROM agent_cloud_mission_sources_v1 WHERE scope='master'")->fetchAll(PDO::FETCH_COLUMN));
    $q=$pdo->prepare("INSERT IGNORE INTO agent_cloud_mission_sources_v1(source_id,owner_user_id,scope,label,category,url,kind,created_at,updated_at) VALUES(?,0,'master',?,?,?,'website',?,?)");$now=gmdate('Y-m-d H:i:s');
    foreach($defaults as $source){$id=cloud_mission_uuid_v1('master-source:'.parse_url($source['url'],PHP_URL_HOST));if(!isset($existing[$id]))$q->execute([$id,$source['label'],$source['category'],$source['url'],$now,$now]);}$ready[$key]=true;
}
function cloud_mission_sources_row_v1(PDO $pdo,array $user,string $id,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_sources_v1 WHERE source_id=? AND (scope=\'master\' OR owner_user_id=?)'.($lock?' FOR UPDATE':''));$q->execute([$id,(int)$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Saved web source not found.',404);return $row;
}
function cloud_mission_sources_list_v1(PDO $pdo,array $user): array
{
    if((int)($user['id']??0)<1||!has_permission('chat.access',$user))throw new RuntimeException('Chat access is required.',403);
    $admin=cloud_mission_sources_admin_v1($user);$masterFilter=$admin?'1=1':"status='active'";$q=$pdo->prepare("SELECT * FROM agent_cloud_mission_sources_v1 WHERE (scope='master' AND $masterFilter) OR (scope='personal' AND owner_user_id=?) ORDER BY category,label,source_id LIMIT 400");$q->execute([(int)$user['id']]);$items=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$items[]=['id'=>$row['source_id'],'label'=>$row['label'],'category'=>$row['category'],'url'=>$row['url'],'kind'=>$row['kind'],'scope'=>$row['scope'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'can_edit'=>$row['scope']==='personal'||$admin];
    return ['ok'=>true,'source_catalog'=>['items'=>$items,'can_manage_master'=>$admin]];
}
function cloud_mission_sources_values_v1(array $input): array
{
    $label=$input['label']??null;$category=$input['category']??'My sources';$kind=$input['kind']??null;
    if(!is_string($label)||trim($label)===''||mb_strlen($label)>120||!is_string($category)||mb_strlen($category)>50||!in_array($kind,['website','page'],true)||!is_string($input['url']??null))throw new InvalidArgumentException('Choose a source name, category, public URL and website/page type.');
    $url=cloud_mission_web_url_v1(trim($input['url']));$p=parse_url($url);
    if($kind==='website'&&(($p['path']??'/')!=='/'||isset($p['query'])))throw new InvalidArgumentException('Use the website homepage for a website entry, or select Public page for a product/category URL.');
    return ['label'=>trim($label),'category'=>trim($category)?:'My sources','url'=>$url,'kind'=>$kind];
}
function cloud_mission_sources_mutate_v1(PDO $pdo,array $user,string $action,array $input): array
{
    if(!has_permission('chat.access',$user))throw new RuntimeException('Chat access is required.',403);
    $rid=$input['request_id']??null;if(!is_string($rid)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$rid)||($input['confirmed']??false)!==true)throw new InvalidArgumentException('Confirm this saved-source operation.');
    $hash=hash('sha256',json_encode([$action,$input['source_id']??null,$input['expected_revision']??null,$input['scope']??null,$input['label']??null,$input['category']??null,$input['kind']??null,$input['url']??null],JSON_THROW_ON_ERROR));$now=gmdate('Y-m-d H:i:s');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM agent_cloud_mission_source_ops_v1 WHERE owner_user_id=? AND request_id=?');$q->execute([(int)$user['id'],$rid]);$op=$q->fetch(PDO::FETCH_ASSOC);
        if($op){if(!hash_equals($op['request_hash'],$hash))throw new RuntimeException('This request ID belongs to a different source operation.',409);$pdo->commit();return cloud_mission_sources_list_v1($pdo,$user);}
        if($action==='sources.create'){
            $scope=$input['scope']??'personal';if(!in_array($scope,['personal','master'],true))throw new InvalidArgumentException('Choose personal or master source storage.');
            if($scope==='master'&&!cloud_mission_sources_admin_v1($user))throw new RuntimeException('Only an administrator can manage master web sources.',403);
            $owner=$scope==='master'?0:(int)$user['id'];$q=$pdo->prepare("SELECT COUNT(*) FROM agent_cloud_mission_sources_v1 WHERE owner_user_id=? AND scope=? AND status='active'");$q->execute([$owner,$scope]);if((int)$q->fetchColumn()>=($scope==='master'?200:100))throw new RuntimeException('Disable an unneeded saved source before adding more.',409);
            $values=cloud_mission_sources_values_v1($input);$id=cloud_mission_uuid_v1('source:'.$user['id'].':'.$rid);
            $pdo->prepare('INSERT INTO agent_cloud_mission_sources_v1(source_id,owner_user_id,scope,label,category,url,kind,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$owner,$scope,$values['label'],$values['category'],$values['url'],$values['kind'],$now,$now]);
        }else{
            $id=(string)($input['source_id']??'');$row=cloud_mission_sources_row_v1($pdo,$user,$id,true);
            if($row['scope']==='master'&&!cloud_mission_sources_admin_v1($user))throw new RuntimeException('Only an administrator can manage master web sources.',403);
            if(($input['expected_revision']??null)!==(int)$row['revision'])throw new RuntimeException('The saved source changed. Refresh before editing it.',409);
            if($action==='sources.enable'&&$row['status']!=='active'){$q=$pdo->prepare("SELECT COUNT(*) FROM agent_cloud_mission_sources_v1 WHERE owner_user_id=? AND scope=? AND status='active'");$q->execute([(int)$row['owner_user_id'],$row['scope']]);if((int)$q->fetchColumn()>=($row['scope']==='master'?200:100))throw new RuntimeException('Disable another source before restoring this entry.',409);}
            if($action==='sources.update'){
                if(isset($input['scope'])&&$input['scope']!==$row['scope'])throw new InvalidArgumentException('Create a separate source to change personal/master scope.');$values=cloud_mission_sources_values_v1($input);
                $pdo->prepare('UPDATE agent_cloud_mission_sources_v1 SET label=?,category=?,url=?,kind=?,revision=revision+1,updated_at=? WHERE source_id=?')->execute([$values['label'],$values['category'],$values['url'],$values['kind'],$now,$id]);
            }elseif(in_array($action,['sources.disable','sources.enable'],true))$pdo->prepare('UPDATE agent_cloud_mission_sources_v1 SET status=?,revision=revision+1,updated_at=? WHERE source_id=?')->execute([$action==='sources.disable'?'disabled':'active',$now,$id]);
            else throw new InvalidArgumentException('Unsupported saved-source operation.');
        }
        $pdo->prepare('INSERT INTO agent_cloud_mission_source_ops_v1(owner_user_id,request_id,request_hash,source_id) VALUES(?,?,?,?)')->execute([(int)$user['id'],$rid,$hash,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return cloud_mission_sources_list_v1($pdo,$user);
}
function cloud_mission_source_bindings_v1(PDO $pdo,array $user,mixed $refs): array
{
    if(!is_array($refs)||!array_is_list($refs)||count($refs)>5)throw new InvalidArgumentException('Select up to five saved web sources.');$out=[];$seen=[];
    foreach($refs as $ref){
        if(!is_array($ref)||!is_string($ref['id']??null)||!is_int($ref['revision']??null))throw new InvalidArgumentException('Refresh the saved web sources before reviewing this assignment.');
        $row=cloud_mission_sources_row_v1($pdo,$user,$ref['id']);
        if($row['status']!=='active')throw new RuntimeException('A selected saved web source was disabled. Review a new assignment before collecting again.',409);
        if((int)$row['revision']!==$ref['revision']&&(($ref['url']??null)!==$row['url']||($ref['kind']??null)!==$row['kind']))throw new RuntimeException('A selected saved web source changed. Review its current URL before collecting again.',409);
        if(isset($seen[$row['source_id']]))continue;$seen[$row['source_id']]=true;
        $out[]=['id'=>$row['source_id'],'revision'=>(int)$row['revision'],'url'=>$row['url'],'kind'=>$row['kind']];
    }
    return $out;
}
function cloud_mission_web_check_catalog_v1(PDO $pdo,array $user,array $config): void
{
    foreach($config['assignments']??[] as $entry)if(!empty($entry['web']['sources']))cloud_mission_source_bindings_v1($pdo,$user,$entry['web']['sources']);
}
