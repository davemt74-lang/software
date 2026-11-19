<?php
declare(strict_types=1);

// Exercise the production compute readers. The ordinary image domain fixture
// replaces runtime routing and therefore cannot expose MySQL DDL auto-commits.
require __DIR__.'/fixtures/cloud-mission-isolated-mysql-v1.php';
if(getenv('VP3_CLOUD_SCHEDULE_DSN')){
    putenv('VP3_IMAGE_TEST_DSN='.getenv('VP3_CLOUD_SCHEDULE_DSN'));
    putenv('VP3_IMAGE_TEST_USER='.getenv('VP3_CLOUD_SCHEDULE_USER'));
    putenv('VP3_IMAGE_TEST_PASSWORD='.getenv('VP3_CLOUD_SCHEDULE_PASSWORD'));
}
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-image-runtime-'.bin2hex(random_bytes(5)));
mkdir(STONEFELLOW_ROOT,0700,true);
$GLOBALS['imageFixtureFile']=STONEFELLOW_ROOT.'/image.sqlite';
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-compute-v020.php';
require_once dirname(__DIR__).'/includes/agent-compute-v023.php';
require_once dirname(__DIR__).'/includes/agent-runtime-routing-v420.php';
require_once dirname(__DIR__).'/includes/homeserver-vp3.php';
require_once dirname(__DIR__).'/includes/homeserver-agent-v018.php';
require_once dirname(__DIR__).'/includes/homeserver-capabilities-v024.php';
require_once dirname(__DIR__).'/includes/homeserver-scope-v026.php';
require_once dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
require_once dirname(__DIR__).'/includes/chat-image-composer-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-reply-tool-plan-v1.php';

class ImageRuntimeStoragePDO extends ImageFixturePDO
{
    public int $transactionDdl=0;
    public array $unprotectedWrites=[];
    public bool $failEvent=false;
    public bool $legacyDdlOnPreference=false;
    public bool $denyHomeRead=false;
    public int $transportStatements=0;
    public function exec(string $statement): int|false
    {
        $ddl=preg_match('/\A\s*(?:CREATE|ALTER|DROP)\b/i',$statement)===1;
        if($ddl&&$this->inTransaction()){
            $this->transactionDdl++;
            // MySQL does this even for CREATE TABLE IF NOT EXISTS on an existing table.
            if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')parent::commit();
        }
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&str_contains($statement,'ENGINE=InnoDB')
            &&preg_match('/CREATE TABLE IF NOT EXISTS ([a-z0-9_]+)/i',$statement,$match)){
            $query=parent::prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $query->execute([$match[1]]);
            if($query->fetchColumn()!==false)return 0;
        }
        return parent::exec($statement);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if($this->denyHomeRead&&str_contains($query,'FROM homeserver_connections')
            &&preg_match('/\ASELECT\s+(?:[a-z_]+\.)?\*/',$query))throw new PDOException('Deliberate routing column read denial.');
        if(str_contains($query,'homeserver_https_requests'))$this->transportStatements++;
        if($this->legacyDdlOnPreference&&$this->inTransaction()
            &&str_starts_with($query,'SELECT preference FROM agent_compute_preferences')){
            // Recreate one request from the deployed reader to prove safe recovery
            // of its persisted job. Normal tests never invoke this fault hook.
            $this->legacyDdlOnPreference=false;agent_compute_v020_ensure_schema($this);
        }
        if($this->failEvent&&str_starts_with($query,'INSERT INTO agent_workflow_events')){
            throw new PDOException('Deliberate event-write storage failure.');
        }
        if(preg_match('/\AINSERT INTO (agent_workflow_runs|agent_workflow_actions|agent_image_generations_v1|user_media_assets)\b/',$query)
            &&!$this->inTransaction())$this->unprotectedWrites[]=$query;
        return parent::prepare($query,$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        if($this->denyHomeRead&&str_contains($query,'FROM homeserver_connections')
            &&preg_match('/\ASELECT\s+(?:[a-z_]+\.)?\*/',$query)){
            throw new PDOException('Deliberate routing column read denial.');
        }
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
}
$pdo=new ImageRuntimeStoragePDO($imageFixtureDsn,getenv('VP3_IMAGE_TEST_USER')?:null,getenv('VP3_IMAGE_TEST_PASSWORD')?:null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
else{
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('IF',fn($a,$b,$c)=>$a?$b:$c);
}
$checks=0;$calls=[];$owner=['id'=>1,'is_active'=>1];
$principal=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];
function storage_check(bool $value,string $label): void
{
    if(!$value)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label."\n";
}
function storage_transport(string $url,array $body,string $key): array
{
    $GLOBALS['calls'][]=[$url,$body,$key];return image_fixture_response_v1($body['size'],$body['n']);
}
function storage_counts(PDO $pdo): array
{
    return array_map(static fn(string $table):int=>(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),
        ['agent_workflow_runs','agent_workflow_actions','agent_workflow_events','agent_image_generations_v1','user_media_assets']);
}
try{
    if($mysql){agent_compute_v020_ensure_schema($pdo);agent_compute_v023_ensure_schema($pdo);homeserver_vp3_ensure_schema($pdo);}
    else{
        $pdo->exec("CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
            CREATE TABLE agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference TEXT,PRIMARY KEY(user_id,agent_id));
            CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,device_id TEXT,relay_token_enc TEXT,homeserver_token_enc TEXT,
                pending_request_id TEXT,pending_claim_token_enc TEXT,pending_code TEXT,status TEXT,installed_version TEXT,last_seen_at TEXT,
                last_checked_at TEXT,last_error TEXT,capabilities_json TEXT,created_at TEXT,updated_at TEXT);
            CREATE TABLE homeserver_releases(id INTEGER PRIMARY KEY,version TEXT,channel TEXT,release_notes TEXT,
                portable_name TEXT,portable_path TEXT,portable_sha256 TEXT,portable_size INTEGER,installer_name TEXT,installer_path TEXT,
                installer_sha256 TEXT,installer_size INTEGER,is_published INTEGER,is_latest INTEGER,created_by_user_id INTEGER,
                published_at TEXT,created_at TEXT,updated_at TEXT)");
    }
    $pdo->exec("INSERT INTO agent_compute_preferences(user_id,preference) VALUES(1,'vp3_cloud')");
    $bytes=base64_decode(image_fixture_response_v1()['data'][0]['b64_json']);
    $path=media_studio_ensure_user_root(1).'/uploaded-person.png';file_put_contents($path,$bytes);
    $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at)
        VALUES(1,'photo','Uploaded person','image/png','private/user-media/1/uploaded-person.png',?,0,'upload','{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([strlen($bytes)]);
    $asset=(int)$pdo->lastInsertId();$original=media_studio_asset($asset,$owner);
    $pdo->unprotectedWrites=[];
    $prompt='edit the image put a baseball cap on the users head, vintage truckers cap.';
    $plan=vp3_chat_image_reference_plan_v1($prompt);
    storage_check($plan!==null&&$plan['prompt']===$prompt,'the reported uploaded-image prompt preserves the exact edit instruction');
    $context=image_fixture_turn_v1();$context['image_reference_asset_id']=$asset;
    $receipt=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    if(($receipt['tool_receipt']['status']??null)!=='completed'){
        fwrite(STDERR,json_encode(['answer'=>$receipt['answer'],'receipt'=>$receipt['tool_receipt'],
            'transaction_ddl'=>$pdo->transactionDdl,'durable_rows'=>storage_counts($pdo)],JSON_THROW_ON_ERROR)."\n");
    }
    storage_check(($receipt['tool_receipt']['status']??null)==='completed'
        &&!str_contains($receipt['answer'],'storage could not be verified'),
        'real routing admits the uploaded-image edit and returns its durable queue receipt');
    $id=$receipt['canvas_media']['generation_ids'][0];$row=vp3_image_row_v1($pdo,$owner,$id);
    $frozen=json_decode($row['config_json'],true);
    storage_check($frozen['prompt']===$prompt&&$frozen['reference']['asset_id']===$asset
        &&$frozen['reference']['sha256']===hash('sha256',$bytes)&&!$pdo->inTransaction()
        &&$pdo->transactionDdl===0&&$pdo->unprotectedWrites===[],
        'admission commits one atomic request with the exact uploaded base and no transactional DDL');
    $before=storage_counts($pdo);$replay=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    storage_check($replay['canvas_media']['generation_ids']===[$id]&&storage_counts($pdo)===$before&&$calls===[],
        'the same owner turn recovers its existing durable job without a second request or provider attempt');
    $finished=vp3_image_tick_v1($pdo,$owner,$row,'storage_transport');
    storage_check($finished['status']==='completed'&&count($calls)===1
        &&$calls[0][0]==='https://api.openai.com/v1/images/edits'
        &&$calls[0][1]['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($bytes)]],
        'real worker routing sends the original bytes once and saves the requested edit');
    $query=$pdo->prepare('SELECT COUNT(*) FROM agent_workflow_receipts WHERE run_id=?');$query->execute([$row['run_id']]);
    storage_check((int)$query->fetchColumn()===1&&$finished['asset']['available']&&$finished['asset']['id']!==$asset
        &&media_studio_asset($asset,$owner)===$original&&file_get_contents($path)===$bytes
        &&$pdo->transactionDdl===0&&$pdo->unprotectedWrites===[]&&!$pdo->inTransaction(),
        'dispatch and publication keep their transaction, receipt, output and untouched original');
    vp3_image_tick_v1($pdo,$owner,vp3_image_row_v1($pdo,$owner,$id),'storage_transport');
    storage_check(count($calls)===1,'reloading a completed real-routing edit cannot charge again');

    foreach(['',gmdate('Y-m-d H:i:s')] as $dispatch){
        $expiredContext=image_fixture_turn_v1();$expiredContext['image_reference_asset_id']=$asset;
        $expired=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$expiredContext);
        $expiredId=$expired['canvas_media']['generation_ids'][0];$expiredRow=vp3_image_row_v1($pdo,$owner,$expiredId);
        agent_job_claim_run_v1900($pdo,$owner,(int)$expiredRow['run_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),300);
        $pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?')
            ->execute(['2001-01-01 00:00:00',(int)$expiredRow['run_id']]);
        $pdo->prepare('UPDATE agent_image_generations_v1 SET provider_started_at=? WHERE generation_id=?')
            ->execute([$dispatch,$expiredId]);
        $nextContext=image_fixture_turn_v1();$nextContext['image_reference_asset_id']=$asset;
        $next=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$nextContext);
        storage_check($next['tool_receipt']['status']==='completed'
            &&agent_workflow_row_v1400($pdo,1,(int)$expiredRow['run_id'])['status']==='failed'
            &&vp3_image_row_v1($pdo,$owner,$expiredId)['provider_started_at']===$dispatch
            &&count($calls)===1&&$pdo->transactionDdl===0&&$pdo->unprotectedWrites===[]&&!$pdo->inTransaction(),
            'real routing recovers an expired image '.($dispatch===''?'before':'after').' dispatch outside admission without DDL or provider replay');
        $nextRow=vp3_image_row_v1($pdo,$owner,$next['canvas_media']['generation_ids'][0]);
        $nextConfig=json_decode($nextRow['config_json'],true);
        storage_check($nextConfig['prompt']===$prompt&&$nextConfig['reference']['asset_id']===$asset
            &&$nextConfig['reference']['sha256']===hash('sha256',$bytes),
            'recovery admits the new edit with its exact prompt and original source proof');
        agent_job_cancel_v1900($pdo,$owner,(int)$nextRow['run_id']);
    }

    // A storage failure must roll back every new job row, even after source routing.
    $before=storage_counts($pdo);$context=image_fixture_turn_v1();$context['image_reference_asset_id']=$asset;
    $pdo->failEvent=true;$failed=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);$pdo->failEvent=false;
    storage_check($failed['tool_receipt']['status']==='failed'&&storage_counts($pdo)===$before
        &&count($calls)===1&&!$pdo->inTransaction(),
        'a late admission storage failure rolls back all job rows and never dispatches a provider');

    $pdo->exec("UPDATE agent_compute_preferences SET preference='auto' WHERE user_id=1");
    $context=image_fixture_turn_v1();$context['image_reference_asset_id']=$asset;
    $auto=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    storage_check(($auto['tool_receipt']['status']??null)==='completed'&&$pdo->transactionDdl===0,
        'Automatic routing with no paired HomeServer preserves the same Cloud decision without installer DDL');
    $autoRow=vp3_image_row_v1($pdo,$owner,$auto['canvas_media']['generation_ids'][0]);
    $autoFinished=vp3_image_tick_v1($pdo,$owner,$autoRow,'storage_transport');
    storage_check($autoFinished['status']==='completed'&&count($calls)===2&&$pdo->transactionDdl===0
        &&$pdo->unprotectedWrites===[],'Automatic-route worker rechecks also preserve dispatch and publication atomicity');

    $pdo->exec("INSERT INTO agent_compute_overrides(user_id,agent_id,preference) VALUES(1,7,'homeserver_only')");
    $before=storage_counts($pdo);$context=image_fixture_turn_v1();$context['image_reference_asset_id']=$asset;
    $blocked=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    storage_check($blocked['tool_receipt']['status']==='blocked'&&storage_counts($pdo)===$before&&count($calls)===2,
        'the real Agent HomeServer-only override still blocks a Cloud image edit');

    $pdo->exec('DELETE FROM agent_compute_overrides WHERE user_id=1 AND agent_id=7');
    foreach(['missing table','missing column','column read denial'] as $damage){
        if($damage==='missing table')$pdo->exec('ALTER TABLE homeserver_connections RENAME TO homeserver_connections_unavailable');
        elseif($damage==='missing column')$pdo->exec('ALTER TABLE homeserver_connections RENAME COLUMN capabilities_json TO unavailable_capabilities');
        else $pdo->denyHomeRead=true;
        $pdo->beginTransaction();$rejected=false;
        try{vp3_agent_runtime_plan_v420($pdo,$owner,7,'chat');}catch(Throwable $error){$rejected=true;}
        storage_check($rejected&&$pdo->inTransaction()&&$pdo->transactionDdl===0,
            'Automatic routing fails closed for '.$damage.' and leaves the caller transaction intact');
        $pdo->rollBack();$pdo->denyHomeRead=false;
        if($damage==='missing table')$pdo->exec('ALTER TABLE homeserver_connections_unavailable RENAME TO homeserver_connections');
        elseif($damage==='missing column')$pdo->exec('ALTER TABLE homeserver_connections RENAME COLUMN unavailable_capabilities TO capabilities_json');
    }

    // Owner 2 has no request-local scope cache. A real paired HTTPS capability
    // advertisement must not enqueue or wait for tools.list under an owner lock.
    $pdo->prepare("INSERT INTO homeserver_connections(user_id,status,homeserver_token_enc,capabilities_json)
        VALUES(2,'paired','fixture-unread-credential',?)")
        ->execute([json_encode(['features'=>['app.scopes.v1','agent.chat']])]);
    $pdo->beginTransaction();$coldRejected=false;$transportBefore=$pdo->transportStatements;
    try{vp3_agent_runtime_plan_v420($pdo,['id'=>2,'is_active'=>1],9,'chat');}
    catch(RuntimeException $error){$coldRejected=$error->getCode()===503;}
    storage_check($coldRejected&&$pdo->inTransaction()&&$pdo->transactionDdl===0
        &&$pdo->transportStatements===$transportBefore,
        'a cold paired HomeServer scope fails closed before transport while the caller lock remains intact');
    $pdo->rollBack();$pdo->exec('DELETE FROM homeserver_connections WHERE user_id=2');

    $pdo->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1");
    $context=image_fixture_turn_v1();$context['image_reference_asset_id']=$asset;
    $pdo->legacyDdlOnPreference=true;
    $oldFailure=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    $query=$pdo->prepare('SELECT * FROM agent_image_generations_v1 WHERE owner_user_id=1 AND user_message_id=?');
    $query->execute([$context['chat_user_message_id']]);$oldRow=$query->fetch();
    storage_check($oldFailure['answer']==='Image job storage could not be verified. Check its history before creating another request.'
        &&$oldFailure['tool_receipt']['status']==='failed'&&!isset($oldFailure['canvas_media'])
        &&$pdo->transactionDdl===1&&$oldRow&&$oldRow['provider_started_at']===''
        &&vp3_image_view_v1($pdo,$owner,$oldRow)['status']==='queued'&&count($calls)===2,
        'the deployed DDL failure can leave an approved never-dispatched job despite the failed Chat receipt');
    $pdo->transactionDdl=0;$pdo->unprotectedWrites=[];
    $before=storage_counts($pdo);$recovered=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    storage_check($recovered['canvas_media']['generation_ids']===[$oldRow['generation_id']]
        &&storage_counts($pdo)===$before&&count($calls)===2,
        'the corrected same-turn recovery retains the original durable request and creates no duplicate');
    // The generic SQLite fixture uses empty text defaults; production MySQL
    // stores an unset pause timestamp as NULL, which is what claim_next reads.
    if(!$mysql)$pdo->exec("UPDATE agent_workflow_runs SET pause_requested_at=NULL WHERE pause_requested_at=''");
    $claim=agent_worker_cloud_claim_v1910($pdo,$owner);
    storage_check($claim!==null&&(int)$claim['run_id']===(int)$oldRow['run_id'],
        'the canonical background worker discovers the original approved image request');
    $recoveryResult=vp3_image_execute_v1($pdo,$owner,$claim,'storage_transport');
    $recoveredView=vp3_image_view_v1($pdo,$owner,vp3_image_row_v1($pdo,$owner,$oldRow['generation_id']));
    storage_check($recoveryResult['ok']&&$recoveredView['status']==='completed'&&count($calls)===3
        &&$pdo->transactionDdl===0&&$pdo->unprotectedWrites===[]
        &&$calls[2][1]['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($bytes)]]
        &&media_studio_asset($asset,$owner)===$original&&file_get_contents($path)===$bytes,
        'the already-approved request finishes once after deployment without replacing its uploaded original');

    $context=image_fixture_turn_v1();$context['image_reference_asset_id']=$asset;
    $started=vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    $startedId=$started['canvas_media']['generation_ids'][0];
    $pdo->prepare('UPDATE agent_image_generations_v1 SET provider_started_at=? WHERE generation_id=?')
        ->execute([gmdate('Y-m-d H:i:s'),$startedId]);
    if(!$mysql)$pdo->exec("UPDATE agent_workflow_runs SET pause_requested_at=NULL WHERE pause_requested_at=''");
    $claim=agent_worker_cloud_claim_v1910($pdo,$owner);$result=vp3_image_execute_v1($pdo,$owner,$claim,'storage_transport');
    storage_check(!$result['ok']&&count($calls)===3
        &&vp3_image_view_v1($pdo,$owner,vp3_image_row_v1($pdo,$owner,$startedId))['status']==='failed',
        'recovery never resends a request whose provider dispatch was already recorded');

    $context=image_fixture_turn_v1();$context['image_reference_asset_id']=$asset;
    $pdo->legacyDdlOnPreference=true;
    vp3_capability_image_generate_v1($pdo,$owner,$principal,$plan,$context);
    $query=$pdo->prepare('SELECT * FROM agent_image_generations_v1 WHERE owner_user_id=1 AND user_message_id=?');
    $query->execute([$context['chat_user_message_id']]);$cancelRow=$query->fetch();
    $pdo->transactionDdl=0;$pdo->unprotectedWrites=[];
    $grizzlyPrompt='Make an image of a grizzly bear on a ski hill';
    $grizzly=vp3_agent_chat_reply_image_plan_v1($grizzlyPrompt);
    $detachedContext=image_fixture_turn_v1(); // The user removed the composer attachment.
    $before=storage_counts($pdo);
    $active=vp3_capability_image_generate_v1($pdo,$owner,$principal,$grizzly,$detachedContext);
    $activeConflict=$active['canvas_media']['image_conflict'];
    storage_check($active['tool_receipt']['status']==='blocked'&&$active['tool_receipt']['reason']==='active_request'
        &&$active['tool_receipt']['record_id']===0&&$active['canvas_media']['generation_ids']===[]
        &&$activeConflict['generation_id']===$cancelRow['generation_id']&&$activeConflict['job_id']===(int)$cancelRow['run_id']
        &&$activeConflict['manage_url']==='/agent-workflows.php?id='.(int)$cancelRow['run_id']
        &&array_column($active['actions'],'url')===[$activeConflict['manage_url'],$activeConflict['source_url']]
        &&storage_counts($pdo)===$before&&count($calls)===3
        &&vp3_image_view_v1($pdo,$owner,$cancelRow)['status']==='queued',
        'the historical orphan is explicitly manageable while the new detached prompt remains unadmitted');
    $cancelled=agent_job_cancel_v1900($pdo,$owner,(int)$cancelRow['run_id']);
    $newRequest=vp3_capability_image_generate_v1($pdo,$owner,$principal,$grizzly,$detachedContext);
    $newId=$newRequest['canvas_media']['generation_ids'][0]??'';
    storage_check($cancelled['status']==='cancelled'&&$newRequest['tool_receipt']['status']==='completed'
        &&$newId!==$cancelRow['generation_id']&&count($calls)===3
        &&!isset(json_decode(vp3_image_row_v1($pdo,$owner,$newId)['config_json'],true)['reference']),
        'the existing Workflows Cancel releases owner admission and the detached grizzly prompt queues without the old base');
    agent_job_cancel_v1900($pdo,$owner,(int)vp3_image_row_v1($pdo,$owner,$newId)['run_id']);
    echo 'IMAGE_RUNTIME_STORAGE_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).
        ($mysql?'; native prepared statements':'; MySQL DDL-commit surrogate').")\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
        if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());
    }
    rmdir(STONEFELLOW_ROOT);
}
