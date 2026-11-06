<?php
declare(strict_types=1);

// Extends the disposable account/SQLite/provider transport foundation used by
// the capability browser suite. Every activity/source/planner/worker/proof/Chat
// function below calls production code. No live packet or tool result is faked.
require_once __DIR__.'/agent-live-context-v1.php';
require_once __DIR__.'/agent-initiative-live-context-v1.php';
require_once __DIR__.'/agent-initiative-task-lists-v1.php';
require_once __DIR__.'/schema-metadata.php';
require_once __DIR__.'/chat-settings-v237.php';

/** MySQL PDO buffers SELECT results by default. Materialize the actual SQLite
 * rows once as well, so a fetched statement cannot retain an implicit stale
 * read snapshot across another HTTP worker's write and a later owner lock.
 * No query is repeated and no domain value or transaction boundary changes. */
class LiveBrowserBufferedStatementV1 extends PDOStatement
{
    private ?array $buffer=null;
    private array $names=[];
    private int $position=0;
    private int $defaultMode=PDO::FETCH_ASSOC;
    private array $defaultArgs=[];
    protected function __construct(){}
    public function execute(?array $params=null): bool
    {
        $this->buffer=null;$this->names=[];$this->position=0;
        return parent::execute($params);
    }
    private function materialize(): void
    {
        if($this->buffer!==null)return;
        for($index=0,$count=$this->columnCount();$index<$count;$index++){
            $metadata=parent::getColumnMeta($index);
            if(!is_array($metadata)||!is_string($metadata['name']??null))throw new LogicException('Fixture column metadata is unavailable.');
            $this->names[]=$metadata['name'];
        }
        $this->buffer=parent::fetchAll(PDO::FETCH_NUM);parent::closeCursor();
    }
    private function format(array $row,int $mode,array $args=[]): mixed
    {
        $mode=$mode===PDO::FETCH_DEFAULT?$this->defaultMode:$mode;
        if($mode===PDO::FETCH_NUM)return $row;
        if($mode===PDO::FETCH_COLUMN){
            $column=(int)($args[0]??0);
            if(!array_key_exists($column,$row))throw new ValueError('Column index is out of range.');
            return $row[$column];
        }
        $result=[];
        if(!in_array($mode,[PDO::FETCH_ASSOC,PDO::FETCH_BOTH,PDO::FETCH_OBJ],true))throw new LogicException('Unsupported fixture fetch mode: '.$mode);
        foreach($row as $index=>$value){$result[$this->names[$index]]=$value;if($mode===PDO::FETCH_BOTH)$result[$index]=$value;}
        return $mode===PDO::FETCH_OBJ?(object)$result:$result;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
    {
        if($cursorOrientation!==PDO::FETCH_ORI_NEXT||$cursorOffset!==0)throw new LogicException('Fixture statements are forward-only.');
        $this->materialize();
        return isset($this->buffer[$this->position])?$this->format($this->buffer[$this->position++],$mode,$this->defaultArgs):false;
    }
    public function fetchColumn(int $column=0): mixed
    {
        if($column<0)throw new ValueError('Column index must be nonnegative.');
        $this->materialize();
        if(!isset($this->buffer[$this->position]))return false;
        $row=$this->buffer[$this->position++];
        if(!array_key_exists($column,$row))throw new ValueError('Column index is out of range.');
        return $row[$column];
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        $this->materialize();$out=[];
        while(isset($this->buffer[$this->position])){
            $row=$this->buffer[$this->position++];
            if($mode===PDO::FETCH_KEY_PAIR){
                if(count($row)!==2)throw new LogicException('Fixture key pairs require exactly two columns.');
                $out[$row[0]]=$row[1];
            }else $out[]=$this->format($row,$mode,$args?:$this->defaultArgs);
        }
        return $out;
    }
    public function setFetchMode(int $mode,mixed ...$args): bool
    {
        $ok=parent::setFetchMode($mode,...$args);$this->defaultMode=$mode;$this->defaultArgs=$args;return $ok;
    }
    public function closeCursor(): bool
    {
        $this->buffer=[];$this->position=0;return parent::closeCursor();
    }
    public function getIterator(): Iterator
    {
        while(($row=$this->fetch())!==false)yield $row;
    }
}
db()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[LiveBrowserBufferedStatementV1::class]);

function live_browser_fetch_contract_v1(PDO $pdo): array
{
    $q=$pdo->prepare("SELECT 1 AS id,NULL AS optional,'value' AS label UNION ALL SELECT 2,0,'next'");
    $q->execute();$default=$q->fetch();$remaining=$q->fetchAll(PDO::FETCH_NUM);$empty=$q->fetch();
    $q->execute();$both=$q->fetchAll(PDO::FETCH_BOTH);
    $q->execute();$columns=$q->fetchAll(PDO::FETCH_COLUMN,1);
    $q->execute();$object=get_object_vars($q->fetch(PDO::FETCH_OBJ));$q->closeCursor();$closed=$q->fetch();
    $q->setFetchMode(PDO::FETCH_NUM);$q->execute();$iteration=iterator_to_array($q);
    $q->execute();$column=$q->fetchColumn(1);$tail=$q->fetchAll(PDO::FETCH_ASSOC);
    return compact('default','remaining','empty','both','columns','object','closed','iteration','column','tail');
}

function live_browser_buffer_contract_v1(): array
{
    $pdo=db();$mode=(string)$pdo->query('PRAGMA journal_mode')->fetchColumn();
    capabilities_browser_value_v1('buffer_contract','original',true);
    $q=$pdo->prepare("SELECT value FROM capabilities_browser_state WHERE key='buffer_contract'");$q->execute();
    $original=$q->fetchColumn();
    $writer=new PDO('sqlite:'.dirname(__DIR__).'/state.sqlite');$writer->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $writer->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $writer->exec('PRAGMA busy_timeout=2000');
    $writer->prepare("UPDATE capabilities_browser_state SET value=? WHERE key='buffer_contract'")->execute(['"changed"']);
    $pdo->beginTransaction();
    try{
        $current=$pdo->query("SELECT value FROM capabilities_browser_state WHERE key='buffer_contract'")->fetchColumn();
        $transaction=$pdo->inTransaction();$pdo->rollBack();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $rows=$pdo->prepare("SELECT 1 AS id,NULL AS optional,'value' AS label UNION ALL SELECT 2,0,'next'");$rows->execute();
    $assoc=$rows->fetch(PDO::FETCH_ASSOC);$numeric=$rows->fetch(PDO::FETCH_NUM);$exhausted=$rows->fetch();
    $rows->execute();$null=$rows->fetchColumn(1);$remaining=$rows->fetchAll(PDO::FETCH_COLUMN,0);
    $rows->execute();$rows->setFetchMode(PDO::FETCH_NUM);$iteration=iterator_to_array($rows);
    $rows->execute();$both=$rows->fetch(PDO::FETCH_BOTH);$object=$rows->fetch(PDO::FETCH_OBJ);
    $rows->execute();$columns=$rows->fetchAll(PDO::FETCH_COLUMN,2);
    $rows->execute();$rows->closeCursor();$closed=$rows->fetch();
    $pairs=$pdo->query("SELECT 1 AS id,'value' AS label UNION ALL SELECT 2,'next'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $buffered_modes=live_browser_fetch_contract_v1($pdo);$native_modes=live_browser_fetch_contract_v1($writer);
    $native_modes_match=$buffered_modes===$native_modes;
    return compact('mode','original','current','transaction','assoc','numeric','exhausted','null','remaining','iteration','both','object','columns','closed','pairs',
        'native_modes_match','buffered_modes','native_modes');
}

/** Fixture-only exception telemetry; no arguments, media, credentials or model
 * prompts. Production error translation and every authority check stay intact. */
function live_browser_exception_v1(Throwable $error): void
{
    $chain=[];
    for($item=$error;$item!==null;$item=$item->getPrevious()){
        $row=['class'=>get_class($item),'code'=>$item->getCode(),'message'=>$item->getMessage(),
            'at'=>basename($item->getFile()).':'.$item->getLine()];
        if($item instanceof PDOException)$row['sqlstate']=['state'=>$item->errorInfo[0]??null,'driver_code'=>$item->errorInfo[1]??null];
        $chain[]=$row;
    }
    $frames=array_map(static fn(array $frame):array=>['file'=>basename($frame['file']??''),'line'=>$frame['line']??0,
        'function'=>$frame['function']??''],array_slice($error->getTrace(),0,14));
    $path=(string)getenv('VP3_LIVE_BROWSER_GATE').'.exceptions.jsonl';
    file_put_contents($path,json_encode(['at'=>gmdate('c'),'chain'=>$chain,'frames'=>$frames],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
}

function live_browser_model_v1(string $prompt,string $protocol): ?array
{
    if(!capabilities_browser_value_v1('live_mode'))return null;
    if(!in_array($protocol,['vp3.initiative-plan.v1','vp3.initiative-draft.v1','vp3.initiative-reply.v1'],true))return null;
    if(db()->inTransaction())throw new RuntimeException('Live inference held a database transaction.');
    $input=json_decode($prompt,true,40,JSON_THROW_ON_ERROR);
    foreach(['SIBLING CHAT SECRET','FOREIGN CHAT SECRET','OTHER CHAT PRIVATE SECRET'] as $secret){
        if(str_contains($prompt,$secret))throw new RuntimeException('Live inference crossed an account or Agent boundary.');
    }
    followthrough_fixture_log_v1('live_model',['protocol'=>$protocol,'prompt'=>$prompt,'in_transaction'=>false]);
    if($protocol==='vp3.initiative-reply.v1'||($input['response_kind']??'')==='followthrough_reply'){
        $delay=max(0,min(2000,(int)getenv('VP3_LIVE_BROWSER_REPLY_DELAY_MS')));
        if($delay>0)usleep($delay*1000);
    }
    if($protocol==='vp3.initiative-reply.v1'){
        return ['ok'=>true,'answer'=>json_encode(['intent'=>'discuss','focus'=>'Discuss the verified current scene and saved list.'],JSON_THROW_ON_ERROR)];
    }
    if(($input['response_kind']??'')==='followthrough_reply'){
        return ['ok'=>true,'answer'=>json_encode(['draft'=>'We can compare the article with your saved supplier choice. Which constraint matters most to you before we develop a private plan?'],JSON_THROW_ON_ERROR)];
    }
    $mode=capabilities_browser_value_v1('live_mode');
    if($protocol==='vp3.initiative-plan.v1'){
        $live=null;$list=null;
        foreach($input['evidence']??[] as $item){
            if(($item['kind']??'')==='live_context')$live=$item['ref'];
            if(($item['ref']??'')==='task-list:600')$list=$item['ref'];
        }
        if(!$live&&$mode!=='voice')return ['ok'=>true,'answer'=>'{"jobs":[]}'];
        $tool=$mode==='voice'?'chat.check_in':($mode==='list'?'tasks.lists.read':'live.context.read');
        $primary=in_array($mode,['voice','list'],true)?$list:$live;
        if(!$primary||empty($input['tools'][$tool]['ready'])||($mode!=='voice'&&!in_array($primary,$input['tools'][$tool]['source_refs']??[],true)))
            throw new RuntimeException('The real live/list capability was not admitted to the actual planner.');
        $refs=$mode==='list'?[$primary,$live]:[$primary];
        return ['ok'=>true,'answer'=>json_encode(['jobs'=>[['tool'=>$tool,'primary'=>$primary,'refs'=>$refs,
            'focus'=>'Consider the current shared article alongside the unresolved supplier choice. Ask one useful grounded question.',
            'report'=>true]]],JSON_THROW_ON_ERROR)];
    }
    if(($input['response_kind']??'')==='chat.check_in'){
        if(!str_contains($prompt,'Launch readiness'))throw new RuntimeException('Actual check-in source did not include its saved named list.');
        return ['ok'=>true,'answer'=>json_encode(['draft'=>'The launch list still has an unresolved supplier choice. Would comparing the options help?',
            'delivery'=>'voice'],JSON_THROW_ON_ERROR)];
    }
    $tool=$input['tool_result']['tool']??null;
    if(!in_array($tool,['live.context.read','tasks.lists.read'],true))throw new RuntimeException('Unexpected actual live preparation tool.');
    $records=json_encode($input['tool_result']['records']??[],JSON_THROW_ON_ERROR);
    if($tool==='live.context.read'&&!str_contains($records,'LIVE_SCREEN_CONTENT_SENTINEL')
        &&!str_contains($records,'provider_label'))throw new RuntimeException('The actual live reader did not return its current source.');
    if($tool==='tasks.lists.read'&&!str_contains($records,'LIVE_LIST_MEMBER_SENTINEL'))
        throw new RuntimeException('The actual list reader did not return its saved members.');
    if(($_GET['hold']??'')==='1'){
        $gate=(string)getenv('VP3_LIVE_BROWSER_GATE');
        file_put_contents($gate.'.entered',json_encode(['in_transaction'=>db()->inTransaction(),'tool'=>$tool]));
        $deadline=microtime(true)+18;
        while(!is_file($gate.'.release')){if(microtime(true)>$deadline)throw new RuntimeException('Live model transport gate timed out.');usleep(10000);}
    }
    $draft=$tool==='live.context.read'
        ?'You are reviewing a supplier comparison. Would comparing the lead times help you decide?'
        :'The launch list still has an unresolved supplier choice. Would comparing those options help?';
    return ['ok'=>true,'answer'=>json_encode(['draft'=>$draft],JSON_THROW_ON_ERROR)];
}

function live_browser_seed_v1(string $mode): array
{
    if(!in_array($mode,['live','list','voice'],true))throw new InvalidArgumentException('Invalid live fixture mode.');
    // Reuse the established real account/source initialization. Its initial
    // ordinary task assessment is removed before this test begins observing.
    capabilities_browser_value_v1('live_mode',null,true);
    capabilities_browser_seed_v1('tasks.read');
    $pdo=db();
    foreach(['agent_workflow_receipts','agent_workflow_action_dependencies','agent_workflow_events','agent_workflow_actions',
        'agent_workflow_runs','followthrough_fixture_calls'] as $table)$pdo->exec('DELETE FROM '.$table);
    $pdo->exec("DELETE FROM agent_memory_items WHERE memory_type='initiative_sweep';
        UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1");
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_user_presence(user_id INTEGER PRIMARY KEY,presence_mode TEXT,
        social_chat_enabled INTEGER,sound_enabled INTEGER,agent_voice_enabled INTEGER,agent_assisted_phone_chat_enabled INTEGER,
        last_seen_at TEXT,updated_at TEXT);
        INSERT OR REPLACE INTO team_user_presence(user_id,presence_mode,social_chat_enabled,sound_enabled,agent_voice_enabled,
            agent_assisted_phone_chat_enabled) VALUES(1,'online',1,1,1,0),(2,'online',1,1,1,0)");
    $pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title,updated_at) VALUES(14,1,7,'Other live Chat',NOW());
        INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at)
            VALUES(14,NULL,'assistant','OTHER CHAT PRIVATE SECRET','2000-01-01 00:00:00');
        UPDATE agent_memory_items SET memory_text='LIVE_LIST_MEMBER_SENTINEL: Confirm the supplier choice before updating the launch plan.' WHERE id=100");
    $insert=$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
        memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
        VALUES(?,1,7,'task',?,?,?,410,1,1,NOW(),NOW(),1,?)");
    $insert->execute([101,'Review the product image','The current image review is waiting.','live-browser-waiting',
        json_encode(['task_status'=>'waiting'],JSON_THROW_ON_ERROR)]);
    $insert->execute([102,'Verify the previous report','The prior report has been reviewed.','live-browser-completed',
        json_encode(['task_status'=>'completed'],JSON_THROW_ON_ERROR)]);
    $pdo->prepare('INSERT INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(600,1,7,?,?,?)')
        ->execute(['Launch readiness',vp3_task_list_key_v1('Launch readiness'),'2026-11-06T00:00:00Z']);
    $member=$pdo->prepare('INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(600,?,?,?)');
    foreach([100,101,102] as $id)$member->execute([$id,'live-member-'.$id,'2026-11-06T00:00:00Z']);
    capabilities_browser_value_v1('live_mode',$mode,true);
    capabilities_browser_value_v1('prepare_id',null,true);
    capabilities_browser_value_v1('claim',null,true);
    capabilities_browser_value_v1('voice_candidate',null,true);
    capabilities_browser_value_v1('voice_delivered',null,true);
    return live_browser_state_v1();
}

/** Controlled media-provider transport. Native browser capture, the actual
 * producer/coordinator and all application source/planner functions stay real. */
function live_browser_media_transport_v1(string $path): void
{
    header('Content-Type: application/json');
    $body=strtoupper($_SERVER['REQUEST_METHOD']??'GET')==='POST'
        ?(json_decode((string)file_get_contents('php://input'),true)?:$_POST):$_GET;
    $owner=(string)current_user()['id'];$action=$body['action']??'';
    $answer=['ok'=>true,'owner_key'=>$owner];
    if($path==='/fixture-screen-audio'){
        if(empty($_FILES['audio'])||($_FILES['audio']['size']??0)<33)throw new RuntimeException('Native browser audio was not uploaded.');
        $answer['capture']=['capture_id'=>$body['capture_id'],'session_id'=>$body['session_id'],'kind'=>'tab_audio',
            'captured_at'=>$body['captured_at'],'body'=>['transcript'=>'','duration_seconds'=>(float)$body['duration_seconds'],
                'audio_events'=>[['label'=>'laughter','start_seconds'=>0,'end_seconds'=>.5,
                    'provider'=>'elevenlabs','model'=>'scribe_v2','source'=>'shared_tab_audio','classification'=>'provider_label']]]];
        followthrough_fixture_log_v1('native_audio_transport',['bytes'=>(int)$_FILES['audio']['size'],
            'session_id'=>$body['session_id'],'capture_id'=>$body['capture_id'],'text'=>'']);
    }elseif($action==='start'){
        $answer['session']=['session_id'=>$body['session_id'],'title'=>$body['title'],
            'created_at'=>gmdate('c'),'folder_url'=>''];
    }elseif($action==='capture'){
        if(!str_starts_with((string)($body['image']??''),'data:image/jpeg;base64,'))throw new RuntimeException('A native screen frame was not uploaded.');
        $capture=['capture_id'=>$body['capture_id'],'session_id'=>$body['session_id'],'kind'=>'screen',
            'captured_at'=>$body['captured_at'],'body'=>['surface'=>$body['surface']]];
        capabilities_browser_value_v1('capture:'.$body['capture_id'],$capture,true);$answer['capture']=$capture;
    }elseif($action==='analyze'){
        $capture=capabilities_browser_value_v1('capture:'.$body['capture_id']);
        $capture['body']['observation']=['contract'=>'vp3.screen-observation.v1','owner_key'=>$owner,
            'summary'=>'LIVE_SCREEN_CONTENT_SENTINEL: A supplier comparison article is open.',
            'visible_text'=>'Two material options have different lead times.','site_hint'=>'supplier.example',
            'page_title'=>'Supplier materials comparison','observations'=>[
                ['label'=>'comparison','detail'=>'An article compares two suppliers.','confidence'=>'medium']]];
        $answer['capture']=$capture;
    }else{
        $answer+=['sessions'=>[],'captures'=>[],'available'=>false,'enabled'=>false,'revision'=>''];
    }
    echo json_encode($answer,JSON_THROW_ON_ERROR);
}

function live_browser_state_v1(): array
{
    $pdo=db();$state=followthrough_fixture_state_v1();
    $state['prepare_id']=capabilities_browser_value_v1('prepare_id');
    $state['live_mode']=capabilities_browser_value_v1('live_mode');
    $state['activity_rows']=$pdo->query('SELECT * FROM agent_activity_state ORDER BY user_id')->fetchAll();
    $state['activity_events']=$pdo->query('SELECT * FROM agent_activity_events ORDER BY id')->fetchAll();
    $state['memory_rows']=$pdo->query('SELECT * FROM agent_memory_items ORDER BY id')->fetchAll();
    $state['archive_rows']=$pdo->query('SELECT * FROM agent_chat_archive ORDER BY id')->fetchAll();
    $state['list_members']=$pdo->query('SELECT * FROM agent_task_list_items_v1 ORDER BY list_id,task_id')->fetchAll();
    $state['voice_delivered']=capabilities_browser_value_v1('voice_delivered');
    return $state;
}

function live_browser_control_v1(): never
{
    header('Content-Type: application/json');
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try{
        $pdo=db();$action=(string)($_GET['action']??'state');$extra=[];
        if($action==='buffer_contract'){$extra['transport']=live_browser_buffer_contract_v1();$state=[];}
        elseif($action==='seed')$state=live_browser_seed_v1((string)($_GET['mode']??'live'));
        elseif($action==='plan'){
            $before=(int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM agent_workflow_runs WHERE capability_key='initiative.plan'")->fetchColumn();
            vp3_initiative_schedule_v1($pdo,['id'=>1,'is_active'=>1],7);
            $plan=(int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM agent_workflow_runs WHERE capability_key='initiative.plan'")->fetchColumn();
            if($plan>$before){
                $extra['planned']=vp3_initiative_execute_v1($pdo,['id'=>1,'is_active'=>1],followthrough_fixture_claim_v1($plan));
                $id=(int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM agent_workflow_runs WHERE capability_key='initiative.prepare'")->fetchColumn();
                capabilities_browser_value_v1('prepare_id',$id?:null,true);
            }
            $extra['new_plan']=$plan>$before;$state=live_browser_state_v1();
        }elseif($action==='claim'){
            $id=(int)capabilities_browser_value_v1('prepare_id');$claim=followthrough_fixture_claim_v1($id);
            agent_job_heartbeat_v1900($pdo,['id'=>1,'is_active'=>1],$id,(int)$claim['action']['id'],$claim['lease_token'],40,'Reading current shared context',240);
            capabilities_browser_value_v1('claim',$claim,true);$state=live_browser_state_v1();
        }elseif($action==='execute'){
            $id=(int)capabilities_browser_value_v1('prepare_id');$claim=capabilities_browser_value_v1('claim');
            $claim=is_array($claim)?$claim:followthrough_fixture_claim_v1($id);
            $extra['execution_result']=vp3_initiative_execute_v1($pdo,['id'=>1,'is_active'=>1],$claim);
            $state=live_browser_state_v1();
        }elseif($action==='expire'){
            $extra['expired']=vp3_live_context_expire_v1($pdo,['id'=>1,'is_active'=>1],
                new DateTimeImmutable('+91 seconds',new DateTimeZone('UTC')));$state=live_browser_state_v1();
        }elseif($action==='voice'){
            capabilities_browser_value_v1('voice_candidate',['through_id'=>(int)($_GET['id']??9001),
                'message'=>'The supplier decision may be worth revisiting when you are ready.'],true);
            $state=live_browser_state_v1();
        }else $state=live_browser_state_v1();
        echo json_encode(['ok'=>true]+$extra+$state,JSON_THROW_ON_ERROR);
    }catch(Throwable $error){
        http_response_code(500);echo json_encode(['ok'=>false,'error'=>$error->getMessage(),
            'at'=>basename($error->getFile()).':'.$error->getLine()],JSON_THROW_ON_ERROR);
    }
    exit;
}

// The legacy presentation's large optional dashboards are controlled UI
// transport. Its work projection and all mutation requests remain canonical.
if(!defined('VP3_COGNITIVE_PRESENTATION_V510'))define('VP3_COGNITIVE_PRESENTATION_V510','live-browser-fixture');
if(!function_exists('vp3_cognitive_presentation_schema_ready_v510')){
    function vp3_cognitive_presentation_schema_ready_v510(PDO $pdo): bool{return true;}
    function vp3_cognitive_presentation_state_v510(PDO $pdo,array $user,string $namespace,int $agentId,bool $working,bool $preview,?int $cid=null): array
    {
        $state=['brief'=>['agent_name'=>'Longfellow','active'=>true,'status_label'=>'Active'],
            'voice_candidate'=>capabilities_browser_value_v1('voice_candidate')];
        if($cid!==null)$state['live_context']=['state'=>'quiet','attention'=>null,
            'initiative'=>vp3_initiative_foreground_status_v1($pdo,$user,$agentId,$cid)];
        return $state;
    }
    function vp3_cognitive_presentation_touch_v510(PDO $pdo,array $user,string $namespace): void{}
    function vp3_cognitive_presentation_voice_delivered_v510(PDO $pdo,array $user,string $namespace,int $through): void
    {capabilities_browser_value_v1('voice_delivered',$through,true);capabilities_browser_value_v1('voice_candidate',null,true);}
    function vp3_cognitive_presentation_voice_suppressed_v510(PDO $pdo,array $user,string $namespace,int $through): void
    {capabilities_browser_value_v1('voice_candidate',null,true);}
}
