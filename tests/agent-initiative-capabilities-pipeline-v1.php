<?php
declare(strict_types=1);

// Canonical owner/Agent policy, sources, claimed workflow, action/receipt and
// saved Chat are production code. Only model transport and host availability
// are deterministic fixtures supplied by this existing disposable foundation.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-capabilities-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';

$capabilityPipelineChecks=0;
function capability_pipeline_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['capabilityPipelineChecks'];echo 'PASS '.$label.PHP_EOL;
}
function capability_pipeline_reject(callable $run,int $code,string $label):void
{
    try{$run();}catch(Throwable $error){capability_pipeline_check($error->getCode()===$code,$label.' ('.$error->getMessage().')');return;}
    throw new RuntimeException('Expected refusal: '.$label);
}
/** The foundation isolates host routing with its own function. Load the exact
 * production readonly helper without redefining that unrelated transport shim.
 * The body and its real dependencies are preserved, including __DIR__ semantics. */
function capability_pipeline_production_function(string $file,string $name,string $next):void
{
    if(function_exists($name))return;
    $path=dirname(__DIR__).'/includes/'.$file;$source=file_get_contents($path);
    $start=strpos($source,'function '.$name.'(');$end=$start===false?false:strpos($source,"\nfunction ".$next.'(',$start);
    if($start===false||$end===false)throw new RuntimeException('Canonical policy helper could not be isolated.');
    $body=substr($source,$start,$end-$start);
    eval(str_replace('__DIR__',var_export(dirname($path),true),$body));
}
// Only the authenticated remote probe is controlled. Its real Cloud-block
// predicate and the canonical meeting policy below remain production code.
function homeserver_scope_v026_fetch(int $owner,bool $forceRefresh=false,bool $readonly=false,?PDO $connection=null):array
{
    ++$GLOBALS['capabilityPipelinePolicyReads'];
    if($owner!==1||!$forceRefresh||db()->inTransaction())throw new LogicException('The remote processing-policy probe must be fresh and outside a transaction.');
    if($readonly&&$connection!==db())throw new LogicException('Readonly policy must retain the actual source connection.');
    if(!empty($GLOBALS['capabilityPipelinePolicyUnavailable']))return ['version'=>'v0.26','supported'=>true,
        'available'=>false,'reason'=>'homeserver_unavailable','scope'=>null,'last_known_cloud_blocked'=>false];
    return ['version'=>'v0.26','supported'=>true,'available'=>true,'reason'=>'scope_ready',
        'scope'=>homeserver_scope_v026_normalize(['cloud_allowed'=>empty($GLOBALS['capabilityPipelineCloudBlocked'])]),
        'last_known_cloud_blocked'=>false];
}
function capability_pipeline_reset(array $user,int $agent,int $cid,bool $visible=true):void
{
    $GLOBALS['initiativeBrainAccess']=true;$GLOBALS['imageDenied']=false;$GLOBALS['scopeRouteBlocked']=false;
    db()->exec('UPDATE users SET is_active=1; UPDATE user_agents SET is_active=1; DELETE FROM user_agent_data_rules');
    scope_command($user,$agent,$cid,'off');scope_command($user,$agent,$cid,'on');
    db()->prepare("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=?")->execute([$cid]);
    db()->prepare("UPDATE agent_activity_state SET surface='chat',context_key=?,activity_state='paused',last_heartbeat_at=NOW(),details_json=? WHERE user_id=?")
        ->execute(['chat:'.$cid,json_encode(['visible'=>$visible,'conversation_id'=>$cid,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR),(int)$user['id']]);
}
function capability_pipeline_stored(int $run,bool $allowEmpty=false):array
{
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$run]);$action=(string)$q->fetchColumn();
    $q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');$q->execute([$run]);$receipt=(string)$q->fetchColumn();
    capability_pipeline_check(($allowEmpty||$action!=='')&&$action===$receipt,'canonical action and actual execution receipt contain the identical result for run '.$run);
    if($action===''&&$allowEmpty)return [];
    return json_decode($action,true,32,JSON_THROW_ON_ERROR);
}
function capability_pipeline_restore_row(string $table,array $row):void
{
    if(!in_array($table,['agent_memory_items','artist_transcript_sessions_v172','artist_transcript_segments_v172'],true)
        ||array_filter(array_keys($row),static fn(string $column):bool=>preg_match('/\A[a-z_][a-z0-9_]*\z/Di',$column)!==1)){
        throw new LogicException('Invalid fixture restoration.');
    }
    db()->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')
        ->execute(array_values($row));
}
function capability_pipeline_feed(array $user,int $agent,int $cid,int $run,string $state):void
{
    $before=count($GLOBALS['scopeInference']);$feed=vp3_top_of_mind_v1(db(),$user,$agent,$cid);
    $found=array_values(array_filter($feed['items'],static fn(array $item):bool=>$item['run_id']===$run));
    capability_pipeline_check(count($found)===1&&$found[0]['state']===$state&&count($GLOBALS['scopeInference'])===$before,
        'the same live Brain feed projects actual '.$state.' capability work without inference');
}
function capability_pipeline_plan(array $user,int $agent,string $tool,string $ref,string $title,?callable $during=null):array
{
    $before=count(scope_runs((int)$user['id'],$agent,'initiative.prepare'));
    $GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($tool,$ref,$during):string{
        if(!empty($GLOBALS['capabilityPipelineAnonymous']))capability_pipeline_check(current_user()===null,
            'anonymous worker planning does not impersonate a browser owner');
        capability_pipeline_check($protocol==='vp3.initiative-plan.v1'&&!db()->inTransaction(),
            'actual capability planning calls the model outside a database transaction');
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        capability_pipeline_check(!empty($input['tools'][$tool]['ready'])&&isset($input['tools'][$tool]['tool_contract'])
            &&in_array($ref,$input['tools'][$tool]['source_refs'],true),'the real planner receives a current admitted source and declared shared tool '.$tool);
        if($during)$during($input);
        return json_encode(['jobs'=>[['tool'=>$tool,'primary'=>$ref,'refs'=>[$ref],
            'focus'=>'Prepare a useful private next step from the selected saved evidence.','report'=>true]]],JSON_THROW_ON_ERROR);
    };
    $plan=scope_make_plan($user,$agent,$title);
    $done=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$plan));
    capability_pipeline_check(!empty($done['ok'])&&count(scope_runs((int)$user['id'],$agent,'initiative.prepare'))===$before+1,
        'model-selected '.$tool.' queues exactly one canonical preparation');
    $run=array_slice(scope_runs((int)$user['id'],$agent,'initiative.prepare'),-1)[0];
    $goal=json_decode($run['goal'],true,32,JSON_THROW_ON_ERROR);
    capability_pipeline_check($goal['tool']===$tool&&$goal['primary']===$ref&&array_keys($goal['sources'])===[$ref],
        'queued work retains the exact leaf and selected source without a second engine');
    return ['id'=>(int)$run['id'],'goal'=>$goal,'plan_id'=>$plan];
}
function capability_pipeline_prepare(array $user,int $agent,int $cid,string $tool,string $ref,string $sentinel,
    bool $visible=true,?callable $during=null,bool $reset=true):array
{
    static $serial=0;++$serial;
    if($reset)capability_pipeline_reset($user,$agent,$cid,$visible);
    $run=capability_pipeline_plan($user,$agent,$tool,$ref,'Capability pipeline '.$serial.' '.$tool,
        static function(array $input)use($sentinel):void{
            capability_pipeline_check(!str_contains(json_encode($input['evidence'],JSON_THROW_ON_ERROR),$sentinel),
                'the protected raw-record sentinel is absent from situation previews');
        });
    capability_pipeline_feed($user,$agent,$cid,$run['id'],'queued');
    $draft=mb_substr(str_repeat('A useful private result grounded in the verified saved source. ',150),0,7999).'.';
    $draftCalls=0;$readCoverage=[];
    $GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($tool,$sentinel,$draft,$during,&$draftCalls,&$readCoverage):string{
        if(!empty($GLOBALS['capabilityPipelineAnonymous']))capability_pipeline_check(current_user()===null,
            'anonymous worker preparation does not create a browser login context');
        ++$draftCalls;$input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        $readCoverage=$input['tool_result']['coverage'];
        capability_pipeline_check($protocol==='vp3.initiative-draft.v1'&&!db()->inTransaction()
            &&$input['tool_result']['tool']===$tool&&str_contains(json_encode($input['tool_result']['records'],JSON_THROW_ON_ERROR),$sentinel),
            'actual '.$tool.' records, not a preview or fabricated result, reach model preparation outside a transaction');
        capability_pipeline_check($input['tool_result']['status']==='read_complete'
            &&str_contains($input['tool_result']['effect'],'No research, new transcript analysis, image generation'),
            'the preparation request distinguishes a bounded read from consequential domain execution');
        if($during)$during();
        return json_encode(['draft'=>$draft],JSON_THROW_ON_ERROR);
    };
    $messages=count(scope_messages());$claim=scope_claim($user,$run['id']);
    capability_pipeline_feed($user,$agent,$cid,$run['id'],'working');
    $done=vp3_initiative_execute_v1(db(),$user,$claim);
    $result=capability_pipeline_stored($run['id'],$during!==null);
    if($during){
        capability_pipeline_check(empty($done['ok'])&&count(scope_messages())===$messages&&$draftCalls===1,
            'a concurrent source change rejects model output without posting or repeating inference');
        return $run+['result'=>$result,'completed'=>$done,'draft'=>$draft,'draft_calls'=>$draftCalls];
    }
    $prepared=$result['initiative_tool_result']??[];$proof=$prepared['output']??[];
    capability_pipeline_check(!empty($done['ok'])&&$draftCalls===1&&($proof['type']??null)==='initiative_capability_read'
        &&$proof['tool']===$tool&&$proof['primary']===$ref&&$proof['owner_user_id']===(int)$user['id']
        &&$proof['agent_id']===$agent&&$proof['conversation_id']===$cid&&preg_match('/\A[a-f0-9]{64}\z/D',$proof['read_fingerprint'])===1,
        'the completed leased worker retains the exact scoped capability proof in its canonical receipt');
    capability_pipeline_check($prepared['draft']===$draft&&mb_strlen($prepared['draft'])===8000
        &&$prepared['tool_receipt']['changes_applied']===false&&$proof['record_count']>0,
        'the full eight-thousand-character private result is retained without claiming a domain mutation');
    capability_pipeline_check($proof['coverage_fingerprint']===vp3_initiative_capability_fingerprint_v1($readCoverage)
        &&!isset($proof['coverage']),
        'the durable receipt binds actual coverage by fingerprint without duplicating the private read packet');
    if($visible){
        $q=db()->prepare('SELECT * FROM chat_messages WHERE id=? AND conversation_id=? AND role=\'assistant\'');
        $q->execute([$result['initiative_message_id']??0,$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
        capability_pipeline_check($result['initiative_delivery']==='delivered'&&$message&&$message['message']===$draft
            &&count(scope_messages())===$messages+1,'the verified result returns once to the exact existing Chat');
        $context=json_decode($message['context_json'],true,32,JSON_THROW_ON_ERROR);
        capability_pipeline_check(($context['initiative_output']??null)===$proof
            &&($context['tool_receipt']??null)===$prepared['tool_receipt']
            &&isset($context['initiative_followthrough_state']['reply_ref']),
            'the existing live Chat card carries verified proof, read receipt and its owner-reply anchor');
        capability_pipeline_feed($user,$agent,$cid,$run['id'],'completed');
    }else{
        capability_pipeline_check($result['initiative_delivery']==='pending'&&count(scope_messages())===$messages,
            'owner absence retains the actual completed result privately until delivery is appropriate');
    }
    $before=count($GLOBALS['scopeInference']);$all=count(scope_messages());
    vp3_initiative_execute_v1(db(),$user,$claim);
    capability_pipeline_check(count($GLOBALS['scopeInference'])===$before&&count(scope_messages())===$all,
        'reusing an already-consumed claim cannot generate or post the completed result twice');
    return $run+['result'=>$result,'completed'=>$done,'draft'=>$draft,'draft_calls'=>$draftCalls,'coverage'=>$readCoverage];
}

// Real saved-record schemas. Empty supporting Commerce ledgers establish schema
// readiness, never a payment or provider configuration; no remote requests run.
db()->exec("CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,title TEXT,status TEXT,started_at TEXT,last_activity_at TEXT,metadata_json TEXT);
    CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at TEXT,updated_at TEXT);
    CREATE TABLE artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));
    CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT);
    CREATE TABLE agent_commerce_products_v800(id INTEGER PRIMARY KEY,owner_user_id INTEGER,workspace_owner_user_id INTEGER,product_key TEXT,title TEXT,description TEXT,product_type TEXT,fulfillment_type TEXT,payment_mode TEXT,price_cents INTEGER,deposit_cents INTEGER,currency TEXT,is_active INTEGER,metadata_json TEXT,created_at TEXT,updated_at TEXT);
    CREATE TABLE agent_commerce_product_bindings_v800(product_id INTEGER,binding_type TEXT,binding_id INTEGER);
    CREATE TABLE agent_commerce_provider_connections_v800(id INTEGER);
    CREATE TABLE agent_commerce_team_provider_v800(id INTEGER);
    CREATE TABLE agent_commerce_orders_v800(id INTEGER,fulfillment_ref_type TEXT);
    CREATE TABLE agent_commerce_order_items_v800(id INTEGER,fulfillment_ref_type TEXT);
    CREATE TABLE agent_commerce_payments_v800(id INTEGER,external_payment_id TEXT);
    CREATE TABLE agent_commerce_refunds_v800(id INTEGER);
    CREATE TABLE agent_commerce_audit_v800(id INTEGER);
    CREATE TABLE agent_commerce_checkout_attempts_v800(provider_order_id TEXT,provider_account_id TEXT,provider_location_id TEXT,creation_lease_token TEXT,creation_lease_expires_at TEXT,creation_attempt_count INTEGER,creation_request_json TEXT,last_error_class TEXT);
    CREATE TABLE agent_commerce_webhook_events_v800(recovery_json TEXT,lease_token TEXT,lease_expires_at TEXT,attempt_count INTEGER,next_attempt_at TEXT,last_error_class TEXT);
    CREATE TABLE agent_commerce_fulfillment_jobs_pa2(job_key TEXT,order_id INTEGER,order_item_id INTEGER,payment_id INTEGER,event_type TEXT,status TEXT,attempt_count INTEGER,next_attempt_at TEXT,lease_token TEXT,lease_expires_at TEXT,last_error_class TEXT,completed_at TEXT);");
$user=['id'=>1,'is_active'=>1];$now=gmdate('Y-m-d H:i:s');
db()->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(301,1,170,?,?,?,?,?)')
    ->execute(['Fresh saved recording','draft',$now,$now,'{}']);
$transcriptText=str_repeat('Ordinary recorded context. ',24).'TRANSCRIPT_RAW_RECORD_SENTINEL. Discuss the unresolved delivery date.';
db()->prepare("INSERT INTO artist_transcript_segments_v172 VALUES(301,301,'segment-301',0,'transcript','Owner',?,1000,2000,.9,?,?)")
    ->execute([$transcriptText,$now,$now]);
$taskText=str_repeat('A saved task detail. ',24).'TASK_RAW_RECORD_SENTINEL. Verify the supplier response before deciding.';
db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=1002')->execute([$taskText]);
db()->prepare("INSERT INTO agent_commerce_products_v800 VALUES(401,1,1,'owned-service','Owner service',?,'service','digital','full',12000,0,'usd',1,'{\"source_asset_id\":\"501\"}',?,?)")
    ->execute(['PRODUCT_DESCRIPTION_SENTINEL. A two-hour consultation with a written private report.',$now,$now]);
db()->prepare("INSERT INTO user_media_assets(id,user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json) VALUES(501,1,'photo','Recorded service artwork','image/png','private/metadata-only-not-read.png',2048,0,'PRODUCT_MEDIA_RECORD_SENTINEL','{}')")->execute();

$transcript=capability_pipeline_prepare($user,17,170,'transcripts.read','transcript:301','TRANSCRIPT_RAW_RECORD_SENTINEL');
capability_pipeline_check($transcript['coverage']['items']['transcript:301']['analysis_state']==='missing'
    &&(int)db()->query('SELECT COUNT(*) FROM artist_transcript_master_analysis_v237')->fetchColumn()===0,
    'a new recording without analysis becomes real useful work while no analysis engine or saved analysis is created');
$forged=$transcript['result']['initiative_tool_result'];
$forged['raw_private_packet']='UNTRUSTED_PRIVATE_PACKET_DO_NOT_KEEP';
$forged['output']['raw_private_packet']='UNTRUSTED_PRIVATE_PACKET_DO_NOT_KEEP';
$forged['output']['coverage']=['raw'=>'UNTRUSTED_PRIVATE_PACKET_DO_NOT_KEEP'];
$normalized=agent_workflow_initiative_result_v1400($forged);
capability_pipeline_check(!str_contains(json_encode($normalized,JSON_THROW_ON_ERROR),'UNTRUSTED_PRIVATE_PACKET_DO_NOT_KEEP')
    &&$normalized['output']===$transcript['result']['initiative_tool_result']['output'],
    'the actual worker projection retains only bounded capability proof and drops extra private packets');
foreach(['tool'=>[],'primary'=>[],'owner_user_id'=>0,'agent_id'=>'17','record_count'=>-1,'coverage_state'=>'completed'] as $key=>$value){
    $forged=$transcript['result']['initiative_tool_result'];$forged['output'][$key]=$value;
    $normalized=agent_workflow_initiative_result_v1400($forged);
    capability_pipeline_check(!isset($normalized['output']),'malformed capability '.$key.' cannot become a durable proof');
}

// Owner follow-through reloads the exact saved new-tool proof using readonly
// verification even after the owner pauses autonomous work.
scope_command($user,17,170,'off');$modelCount=count($GLOBALS['scopeInference']);$workCount=count(scope_runs(1,17,'initiative.prepare'));
$root=vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,17),170,(int)$transcript['result']['initiative_message_id']);
capability_pipeline_check($root['state']['state']==='open'&&count($GLOBALS['scopeInference'])===$modelCount,
    'pausing autonomy preserves a source-verified readonly review of the exact delivered transcript proposal');
$scope=['user'=>$user,'principal'=>scope_principal($user,17),'agent_id'=>17,'conversation_id'=>170,'verification_only'=>true];
$coverage=[];$evidence=vp3_initiative_capabilities_evidence_v1(db(),$scope,$transcript['goal']['binding'],new DateTimeImmutable('now',new DateTimeZone('UTC')),['transcript:301'],$coverage);
capability_pipeline_reject(static fn()=>vp3_initiative_capability_execute_v1(db(),$scope,$transcript['goal']['binding'],
    ['tool'=>'transcripts.read','primary'=>'transcript:301','refs'=>['transcript:301'],'focus'=>'A new preparation','report'=>true],$evidence,
    static fn()=>throw new LogicException('Readonly verification reached a model.')),403,
    'readonly source verification cannot be reused to execute a preparation');
capability_pipeline_reject(static fn()=>vp3_initiative_capabilities_catalog_v1(db(),$scope,$transcript['goal']['binding'],$evidence),403,
    'readonly source verification cannot produce a work-selection catalog');
db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user','Explain that recorded follow-up')");$reply=(int)db()->lastInsertId();
$ref=['message_id'=>(int)$root['message']['id'],'reply_ref'=>$root['state']['reply_ref'],'revision'=>$root['state']['revision']];
$discuss=vp3_initiative_followthrough_reply_v1(db(),$user,$root['principal'],170,$reply,$ref,'Explain that recorded follow-up',null,
    static fn(string $prompt,string $protocol):string=>json_encode(['intent'=>'discuss','focus'=>'Discuss the verified saved recording.'],JSON_THROW_ON_ERROR));
capability_pipeline_check(($discuss['initiative_followthrough']['state']??null)==='discussed'
    &&!empty($discuss['advisory_context']['verified_sources'])&&count(scope_runs(1,17,'initiative.prepare'))===$workCount,
    'a natural owner reply discusses the actual transcript result while paused without queuing another job');
vp3_initiative_followthrough_current_v1(db(),$user,$root['principal'],170,$discuss['initiative_followthrough']);
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','The saved recording leaves the delivery date unresolved; clarifying that is a useful next step.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$discuss['initiative_followthrough']],JSON_THROW_ON_ERROR)]);

$task=capability_pipeline_prepare($user,17,170,'tasks.read','task:1002','TASK_RAW_RECORD_SENTINEL');
$root=vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,17),170,(int)$task['result']['initiative_message_id']);
$before=count(scope_runs(1,17,'initiative.prepare'));
$advanceQuery='Yes, develop that private next step. '.str_repeat('Use the saved source details before proposing an action. ',10)
    .' Preserve this final detail: OWNER_CONTINUATION_TAIL_SENTINEL.';
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user',?)")->execute([$advanceQuery]);$reply=(int)db()->lastInsertId();
$ref=['message_id'=>(int)$root['message']['id'],'reply_ref'=>$root['state']['reply_ref'],'revision'=>$root['state']['revision']];
$advance=vp3_initiative_followthrough_reply_v1(db(),$user,$root['principal'],170,$reply,$ref,$advanceQuery,null,
    static fn(string $prompt,string $protocol):string=>json_encode(['intent'=>'advance','focus'=>'Develop the source-grounded private task follow-up.'],JSON_THROW_ON_ERROR));
$outcome=$advance['initiative_followthrough'];$child=(int)$outcome['continuation_run_id'];
$childRow=agent_workflow_row_v1400(db(),1,$child);$childGoal=json_decode($childRow['goal'],true,32,JSON_THROW_ON_ERROR);
capability_pipeline_check($outcome['state']==='queued'&&count(scope_runs(1,17,'initiative.prepare'))===$before+1
    &&$childGoal['tool']==='tasks.read'&&$childGoal['sources']===$task['goal']['sources']
    &&$childGoal['capability_sources']===$task['goal']['capability_sources'],
    'an owner reply to a real capability result queues one private continuation with the original exact source classification');
vp3_initiative_followthrough_current_v1(db(),$user,$root['principal'],170,$outcome);
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','I will develop that private task follow-up from the verified saved evidence.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$outcome],JSON_THROW_ON_ERROR)]);
db()->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170");
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($advanceQuery):string{
    capability_pipeline_check($protocol==='vp3.initiative-draft.v1'&&!db()->inTransaction(),
        'the actual continuation uses the existing governed leaf inference outside a transaction');
    $request=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    capability_pipeline_check(($request['tool_result']['tool']??null)==='tasks.read'
        &&str_contains(json_encode($request['tool_result']['records'],JSON_THROW_ON_ERROR),'TASK_RAW_RECORD_SENTINEL'),
        'an owner continuation executes its original readonly leaf and retrieves fresh canonical records');
    capability_pipeline_check(($request['owner_continuation']['kind']??null)==='owner_requested_private_continuation'
        &&($request['owner_continuation']['owner_reply']??null)===$advanceQuery
        &&str_contains($request['owner_continuation']['owner_reply'],'OWNER_CONTINUATION_TAIL_SENTINEL')
        &&mb_strlen($request['owner_continuation']['proposal_text'])===2400,
        'the report model receives the complete saved owner request beyond the planning-focus limit and its verified original proposal context');
    return json_encode(['draft'=>'The saved task calls for checking the supplier response. A useful next private step is to list the unresolved delivery questions before contacting anyone.'],JSON_THROW_ON_ERROR);
};
$done=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$child));$childResult=capability_pipeline_stored($child);
$fresh=vp3_initiative_followthrough_load_v1(db(),$user,$root['principal'],170,(int)$root['message']['id']);
capability_pipeline_check(!empty($done['ok'])&&$fresh['state']['state']==='completed'
    &&$childResult['initiative_delivery']==='delivered'&&(int)$childResult['initiative_message_id']>0,
    'the real continuation finishes through its canonical receipt, updates the original proposal and reports back to the same Chat');
$product=capability_pipeline_prepare($user,0,142,'products.content.prepare','product-context:401','PRODUCT_MEDIA_RECORD_SENTINEL');
capability_pipeline_check($product['coverage']['pixels']==='not_read',
    'product promotion preparation reads owned metadata without pretending to inspect pixels');

// A source family can span several bounded batches in status/late validation.
// An unavailable earlier batch must not be overwritten by a later ready batch.
$selectedTasks=[];$copyTask=db()->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    SELECT ?,user_id,user_agent_id,memory_type,?,memory_text,?,memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json FROM agent_memory_items WHERE id=1002");
for($i=1100;$i<1108;$i++){$copyTask->execute([$i,'Batch task '.$i,'batch-capability-'.$i]);$selectedTasks[]='task:'.$i;}
try{
    $binding=vp3_initiative_policy_v1($user,17);$scope=vp3_initiative_tools_scope_v1(db(),$user,$binding);
    db()->exec("UPDATE agent_memory_items SET metadata_json='{' WHERE id=1100");
    $beforeChanges=(int)db()->query('SELECT total_changes()')->fetchColumn();$beforeModels=count($GLOBALS['scopeInference']);
    $coverage=[];$records=vp3_initiative_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),$selectedTasks,$coverage);
    capability_pipeline_check(($coverage['office_tasks']??null)==='unavailable'&&!array_intersect(array_keys($records),$selectedTasks),
        'an unavailable first selected-task batch remains unavailable and discards later ready-family records');
    $coverage=[];$records=vp3_initiative_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),array_reverse($selectedTasks),$coverage);
    capability_pipeline_check(($coverage['office_tasks']??null)==='unavailable'&&!array_intersect(array_keys($records),$selectedTasks),
        'a later unavailable selected-task batch also discards earlier partial-family records');
    capability_pipeline_check((int)db()->query('SELECT total_changes()')->fetchColumn()===$beforeChanges&&count($GLOBALS['scopeInference'])===$beforeModels,
        'failed multi-batch source verification changes no records and performs no inference');
    db()->exec('UPDATE agent_memory_items SET metadata_json=(SELECT metadata_json FROM agent_memory_items WHERE id=1002) WHERE id=1100');
    $coverage=[];$records=vp3_initiative_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),$selectedTasks,$coverage);
    capability_pipeline_check(($coverage['office_tasks']??null)==='ready'&&count(array_intersect(array_keys($records),$selectedTasks))===8,
        'a later actual retry with all valid metadata verifies every selected task across both batches');
}finally{db()->exec('DELETE FROM agent_memory_items WHERE id BETWEEN 1100 AND 1107');}

// Delay a real completed task result, then verify it can be delivered once on
// owner return without another model call or manufactured owner message.
$pending=capability_pipeline_prepare($user,17,170,'tasks.read','task:1002','TASK_RAW_RECORD_SENTINEL',false);
$messages=count(scope_messages());$modelCount=count($GLOBALS['scopeInference']);
capability_pipeline_check(vp3_initiative_foreground_status_v1(db(),$user,17,170)['state']==='ready',
    'readonly status verifies the actual current pending task result');
db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',context_key='chat:170',last_heartbeat_at=NOW() WHERE user_id=1");
capability_pipeline_check(vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1
    &&count($GLOBALS['scopeInference'])===$modelCount,'owner return delivers the exact saved current task result once without another model call');
capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1,
    'a second delivery sweep cannot repeat that saved capability result');

// Audio-library organization is not a semantic edit to a saved recording.
// A real pending result must survive those touches without another inference.
$organized=capability_pipeline_prepare($user,17,170,'transcripts.read','transcript:301','TRANSCRIPT_RAW_RECORD_SENTINEL',false);
$sessionBefore=db()->query('SELECT * FROM artist_transcript_sessions_v172 WHERE id=301')->fetch(PDO::FETCH_ASSOC);
$messages=count(scope_messages());$modelCount=count($GLOBALS['scopeInference']);
try{
    db()->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=?,metadata_json=? WHERE id=301')
        ->execute([gmdate('Y-m-d H:i:s',time()+1),json_encode(['favorite'=>true,'capture_mode'=>'audio_library',
            'folder_id'=>9,'name'=>'A library-only label'],JSON_THROW_ON_ERROR)]);
    $current=vp3_initiative_current_sources_v1(db(),$user,$organized['goal']['sources'],false,
        $organized['goal']['canonical_sources'],17,$organized['goal']['capability_sources']);
    capability_pipeline_check(array_map(static fn(array $row):string=>$row['revision'],$current)===$organized['goal']['sources']
        &&count($GLOBALS['scopeInference'])===$modelCount,
        'timestamp, favorite, folder and audio-library label changes preserve the exact transcript source revision');
    db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',context_key='chat:170',last_heartbeat_at=NOW() WHERE user_id=1");
    capability_pipeline_check(vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1
        &&count($GLOBALS['scopeInference'])===$modelCount,
        'a real pending transcript result remains deliverable after harmless library organization without model replay');
    capability_pipeline_check(capability_pipeline_stored($organized['id'])['initiative_delivery']==='delivered',
        'the canonical action and receipt preserve successful delivery after nonsemantic transcript touches');
}finally{
    db()->prepare('UPDATE artist_transcript_sessions_v172 SET last_activity_at=?,metadata_json=? WHERE id=301')
        ->execute([$sessionBefore['last_activity_at'],$sessionBefore['metadata_json']]);
}

// A fresh background result with changed private content must be withheld even
// when the old source title and situation preview remain unchanged.
$stale=capability_pipeline_prepare($user,17,170,'tasks.read','task:1002','TASK_RAW_RECORD_SENTINEL',false);
$messages=count(scope_messages());$modelCount=count($GLOBALS['scopeInference']);
db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=1002')->execute([$taskText.' A later source correction.']);
capability_pipeline_check(vp3_initiative_foreground_status_v1(db(),$user,17,170)['state']!=='ready',
    'readonly status removes a completed result after a semantic source edit');
db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
    &&count($GLOBALS['scopeInference'])===$modelCount,'delayed delivery cannot expose the stale private task result or regenerate it');
$withheld=capability_pipeline_stored($stale['id']);
capability_pipeline_check($withheld['initiative_delivery']==='withheld',
    'the actual action and receipt both record the changed-source result as withheld');
db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=1002')->execute([$taskText]);

// Unknown verification failures stay pending, while known deleted exact sources
// retire their saved output. Both use the actual Office/transcript readers.
$unavailable=capability_pipeline_prepare($user,17,170,'tasks.read','task:1002','TASK_RAW_RECORD_SENTINEL',false);
$savedTask=db()->query('SELECT * FROM agent_memory_items WHERE id=1002')->fetch(PDO::FETCH_ASSOC);
$unchanged=capability_pipeline_stored($unavailable['id']);$models=count($GLOBALS['scopeInference']);$messages=count(scope_messages());
db()->exec("UPDATE agent_memory_items SET metadata_json='{' WHERE id=1002");
capability_pipeline_reject(static fn()=>vp3_initiative_current_sources_v1(db(),$user,$unavailable['goal']['sources'],false,
    $unavailable['goal']['canonical_sources'],17,$unavailable['goal']['capability_sources']),503,
    'unknown malformed selected task data is unavailable rather than known deletion');
db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
    &&count($GLOBALS['scopeInference'])===$models&&capability_pipeline_stored($unavailable['id'])===$unchanged,
    'a real503 source-verification failure leaves the exact pending action and receipt result unchanged');
db()->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=1002')->execute([$savedTask['metadata_json']]);
capability_pipeline_check(vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1
    &&count($GLOBALS['scopeInference'])===$models,'the same pending result recovers from unavailable source verification without model replay');
foreach([
    ['tasks.read','task:1002','TASK_RAW_RECORD_SENTINEL'],
    ['transcripts.read','transcript:301','TRANSCRIPT_RAW_RECORD_SENTINEL'],
] as [$tool,$source,$sentinel]){
    $deleted=capability_pipeline_prepare($user,17,170,$tool,$source,$sentinel,false);
    $messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
    if($tool==='tasks.read'){
        $saved=db()->query('SELECT * FROM agent_memory_items WHERE id=1002')->fetch(PDO::FETCH_ASSOC);
        db()->exec('DELETE FROM agent_memory_items WHERE id=1002');
    }else{
        $session=db()->query('SELECT * FROM artist_transcript_sessions_v172 WHERE id=301')->fetch(PDO::FETCH_ASSOC);
        $segment=db()->query('SELECT * FROM artist_transcript_segments_v172 WHERE id=301')->fetch(PDO::FETCH_ASSOC);
        db()->exec('DELETE FROM artist_transcript_segments_v172 WHERE session_id=301; DELETE FROM artist_transcript_sessions_v172 WHERE id=301');
    }
    try{
        capability_pipeline_reject(static fn()=>vp3_initiative_current_sources_v1(db(),$user,$deleted['goal']['sources'],false,
            $deleted['goal']['canonical_sources'],17,$deleted['goal']['capability_sources']),409,
            'a known deleted '.$tool.' source is an exact terminal source change');
        db()->exec("UPDATE agent_activity_state SET details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
        capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
            &&count($GLOBALS['scopeInference'])===$models,'a deleted '.$tool.' source cannot post or regenerate its saved result');
        capability_pipeline_check(capability_pipeline_stored($deleted['id'])['initiative_delivery']==='withheld',
            'known '.$tool.' deletion records withheld instead of endlessly retrying unavailable');
    }finally{
        if($tool==='tasks.read')capability_pipeline_restore_row('agent_memory_items',$saved);
        else{capability_pipeline_restore_row('artist_transcript_sessions_v172',$session);capability_pipeline_restore_row('artist_transcript_segments_v172',$segment);}
    }
}

// Exact source edits occurring inside model transport represent a concurrent
// writer: the model output cannot become a trusted completion afterward.
capability_pipeline_prepare($user,17,170,'tasks.read','task:1002','TASK_RAW_RECORD_SENTINEL',true,
    static fn()=>db()->exec("UPDATE agent_memory_items SET memory_text=memory_text||' Concurrent edit.' WHERE id=1002"));
db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=1002')->execute([$taskText]);
foreach([
    'owner deactivation'=>static fn()=>db()->exec('UPDATE users SET is_active=0 WHERE id=1'),
    'Agent deactivation'=>static fn()=>db()->exec('UPDATE user_agents SET is_active=0 WHERE id=17'),
    'source permission revocation'=>static fn()=>db()->exec("INSERT INTO user_agent_data_rules VALUES(17,'knowledge','brain:1002','deny')"),
] as $case=>$change){
    capability_pipeline_prepare($user,17,170,'tasks.read','task:1002','TASK_RAW_RECORD_SENTINEL',true,$change);
    capability_pipeline_check(true,'the actual new-tool pipeline remains fail-closed during '.$case);
}
capability_pipeline_reset($user,17,170);

// Existing saved results remain private across account, Agent and participant
// changes, and source deletion cannot turn a saved preparation into authority.
$before=count($GLOBALS['scopeInference']);
capability_pipeline_reject(static fn()=>vp3_initiative_followthrough_load_v1(db(),['id'=>2,'is_active'=>1],scope_principal(['id'=>2,'is_active'=>1],19),190,(int)$task['result']['initiative_message_id']),409,
    'another account cannot reuse the actual new-tool proposal');
capability_pipeline_reject(static fn()=>vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,18),180,(int)$task['result']['initiative_message_id']),409,
    'a sibling Agent cannot reuse the actual new-tool proposal');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>9];
capability_pipeline_reject(static fn()=>vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,17),170,(int)$task['result']['initiative_message_id']),403,
    'participant scope cannot inspect a private capability proposal');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
db()->exec('DELETE FROM artist_transcript_segments_v172 WHERE session_id=301; DELETE FROM artist_transcript_sessions_v172 WHERE id=301');
capability_pipeline_reject(static fn()=>vp3_initiative_followthrough_load_v1(db(),$user,scope_principal($user,17),170,(int)$transcript['result']['initiative_message_id']),409,
    'a deleted transcript invalidates the original saved proposal before owner follow-through');
capability_pipeline_check(count($GLOBALS['scopeInference'])===$before,
    'account, Agent, participant and deleted-source checks perform no new inference');

// Restore the real recording for the mismatched-leaf admission test.
db()->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(301,1,170,?,?,?,?,?)')
    ->execute(['Fresh saved recording','draft',$now,$now,'{}']);
db()->prepare("INSERT INTO artist_transcript_segments_v172 VALUES(301,301,'segment-301',0,'transcript','Owner',?,1000,2000,.9,?,?)")
    ->execute([$transcriptText,$now,$now]);

// A real meeting recording uses the canonical compute preference and remote
// policy, including expiration across a slow owner-reply classification.
capability_pipeline_production_function('agent-runtime-routing-v420.php','vp3_agent_runtime_preference_readonly_v420','vp3_agent_runtime_cloud_state_v420');
capability_pipeline_production_function('homeserver-scope-v026.php','homeserver_scope_v026_string_list','homeserver_scope_v026_normalize');
capability_pipeline_production_function('homeserver-scope-v026.php','homeserver_scope_v026_normalize','homeserver_scope_v026_supported');
capability_pipeline_production_function('homeserver-scope-v026.php','homeserver_scope_v026_blocks_cloud','homeserver_scope_v026_public');
$GLOBALS['capabilityPipelinePolicyReads']=0;$GLOBALS['capabilityPipelineCloudBlocked']=false;
db()->exec("CREATE TABLE agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
    CREATE TABLE agent_compute_overrides(user_id INTEGER,agent_id INTEGER,preference TEXT);
    INSERT INTO agent_compute_preferences VALUES(1,'auto');
    CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,organizer_agent_id INTEGER,public_id TEXT,room_name TEXT,agent_mode TEXT,title TEXT);
    CREATE TABLE video_meeting_participants(id INTEGER,invite_token TEXT);
    CREATE TABLE video_meeting_transcript_segments(id INTEGER);
    CREATE TABLE video_meeting_artifacts(id INTEGER);
    CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,capabilities_json TEXT,relay_token_enc TEXT,homeserver_token_enc TEXT,device_id TEXT);
    INSERT INTO homeserver_connections VALUES(1,'connected','{\"features\":[\"app.scopes.v1\"]}','fixture-relay-ciphertext','fixture-home-ciphertext','fixture-device');
    INSERT INTO video_meetings VALUES(700,1,17,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','pipeline-room','assist','Recorded meeting');");
db()->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(302,1,170,?,?,?,?,?)')
    ->execute(['Meeting recording','draft',$now,$now,'{"capture_mode":"vp3_video_meeting","video_meeting_id":700}']);
db()->prepare("INSERT INTO artist_transcript_segments_v172 VALUES(302,302,'segment-302',0,'transcript','Owner',?,1000,2000,.9,?,?)")
    ->execute(['MEETING_RAW_RECORD_SENTINEL. Clarify the remaining supply commitment.',$now,$now]);
$meeting=capability_pipeline_prepare($user,17,170,'transcripts.read','transcript:302','MEETING_RAW_RECORD_SENTINEL',false);
$reads=$GLOBALS['capabilityPipelinePolicyReads'];$scheduled=count(scope_runs(1,17,'initiative.plan'));
vp3_initiative_schedule_v1(db(),$user,17);vp3_initiative_schedule_v1(db(),$user,17);
capability_pipeline_check($GLOBALS['capabilityPipelinePolicyReads']===$reads&&count(scope_runs(1,17,'initiative.plan'))===$scheduled,
    'a fresh unchanged review does not add remote policy probes or new plans on ordinary scheduler polls');
$originalTimezone=date_default_timezone_get();
try{
    date_default_timezone_set('America/Phoenix');
    $last=vp3_initiative_in_scope_v1($user,17,static fn()=>agent_brain_v122_memory($user,'initiative_sweep','last-review'));
    capability_pipeline_check(vp3_initiative_review_recent_v1(db(),$last,$meeting['goal']['binding']),
        'a just-saved review remains recent under a Phoenix PHP host using the paired SQL UTC clock');
    db()->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_sweep' AND subject='last-review'");
    $old=vp3_initiative_in_scope_v1($user,17,static fn()=>agent_brain_v122_memory($user,'initiative_sweep','last-review'));
    capability_pipeline_check(!vp3_initiative_review_recent_v1(db(),$old,$meeting['goal']['binding']),
        'forcing an old SQL review timestamp remains stale even when checked-at metadata is fresh');
    $reads=$GLOBALS['capabilityPipelinePolicyReads'];vp3_initiative_schedule_v1(db(),$user,17);
    $last=vp3_initiative_in_scope_v1($user,17,static fn()=>agent_brain_v122_memory($user,'initiative_sweep','last-review'));
    capability_pipeline_check($GLOBALS['capabilityPipelinePolicyReads']>$reads
        &&vp3_initiative_review_recent_v1(db(),$last,$meeting['goal']['binding']),
        'a due Phoenix-hosted assessment refreshes real meeting policy and saves a fresh UTC review');
    $metadata=$last['metadata'];$metadata['checked_at']='2000-01-01T00:00:00+00:00';
    db()->prepare("UPDATE agent_memory_items SET last_seen_at=NOW(),metadata_json=? WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_sweep' AND subject='last-review'")
        ->execute([json_encode($metadata,JSON_THROW_ON_ERROR)]);
    $old=vp3_initiative_in_scope_v1($user,17,static fn()=>agent_brain_v122_memory($user,'initiative_sweep','last-review'));
    capability_pipeline_check(!vp3_initiative_review_recent_v1(db(),$old,$meeting['goal']['binding']),
        'old UTC checked-at metadata cannot be concealed by a fresh SQL last-seen timestamp');
    vp3_initiative_schedule_v1(db(),$user,17);$reads=$GLOBALS['capabilityPipelinePolicyReads'];
    vp3_initiative_schedule_v1(db(),$user,17);
    capability_pipeline_check($GLOBALS['capabilityPipelinePolicyReads']===$reads,
        'the next unchanged Phoenix-hosted poll skips remote policy work after the due review completes');
}finally{date_default_timezone_set($originalTimezone);}
$binding=$meeting['goal']['binding'];
$meetingScope=['user'=>$user,'principal'=>scope_principal($user,17),'agent_id'=>17,'conversation_id'=>170];
$session=vp3_transcript_session_v1(db(),$user,$meetingScope['principal'],302);
$beforeOutage=capability_pipeline_stored($meeting['id']);$models=count($GLOBALS['scopeInference']);$messages=count(scope_messages());
$GLOBALS['capabilityPipelinePolicyUnavailable']=true;
try{
    capability_pipeline_reject(static fn()=>vp3_initiative_transcript_capabilities_cloud_v1(db(),$meetingScope,$session),503,
        'an unavailable paired HomeServer policy is unknown verification rather than permission to process');
    db()->exec("UPDATE agent_activity_state SET context_key='chat:170',details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
    capability_pipeline_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
        &&count($GLOBALS['scopeInference'])===$models&&capability_pipeline_stored($meeting['id'])===$beforeOutage,
        'actual pending meeting work survives a paired-policy503 with its action and receipt result unchanged and no model replay');
}finally{$GLOBALS['capabilityPipelinePolicyUnavailable']=false;}
$GLOBALS['capabilityPipelineCloudBlocked']=true;
capability_pipeline_reject(static fn()=>vp3_initiative_transcript_capabilities_cloud_v1(db(),$meetingScope,$session),403,
    'a real remote meeting-policy rejection clears the old same-request processing proof');
$GLOBALS['capabilityPipelineCloudBlocked']=false;$reads=$GLOBALS['capabilityPipelinePolicyReads'];$calls=count($GLOBALS['scopeInference']);
db()->exec("UPDATE agent_activity_state SET context_key='chat:170',details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1");
capability_pipeline_check(vp3_initiative_deliver_pending_v1(db(),$user,17)
    &&$GLOBALS['capabilityPipelinePolicyReads']>$reads&&count($GLOBALS['scopeInference'])===$calls,
    'actual delayed delivery refreshes a cleared meeting proof outside its final transaction without another model call');
$meetingSaved=capability_pipeline_stored($meeting['id']);
$root=vp3_initiative_followthrough_load_v1(db(),$user,$meetingScope['principal'],170,(int)$meetingSaved['initiative_message_id']);
db()->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user','Explain the recorded meeting follow-up')");$reply=(int)db()->lastInsertId();
$ref=['message_id'=>(int)$root['message']['id'],'reply_ref'=>$root['state']['reply_ref'],'revision'=>$root['state']['revision']];
$readsAfterSlowModel=0;$slowStart=hrtime(true);
$discuss=vp3_initiative_followthrough_reply_v1(db(),$user,$meetingScope['principal'],170,$reply,$ref,'Explain the recorded meeting follow-up',null,
    static function(string $prompt,string $protocol)use(&$readsAfterSlowModel):string{
        capability_pipeline_check($protocol==='vp3.initiative-reply.v1'&&!db()->inTransaction(),
            'slow owner intent classification runs outside the actual source/decision transaction');
        usleep(15_050_000);$readsAfterSlowModel=$GLOBALS['capabilityPipelinePolicyReads'];
        return json_encode(['intent'=>'discuss','focus'=>'Explain the saved meeting commitment.'],JSON_THROW_ON_ERROR);
    });
capability_pipeline_check(hrtime(true)-$slowStart>=15_000_000_000
    &&($discuss['initiative_followthrough']['state']??null)==='discussed'
    &&$GLOBALS['capabilityPipelinePolicyReads']>$readsAfterSlowModel,
    'owner follow-through survives actual fifteen-second proof expiry by refreshing canonical meeting policy after the model and before the decision commits');
vp3_initiative_followthrough_current_v1(db(),$user,$meetingScope['principal'],170,$discuss['initiative_followthrough']);
db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant','The saved meeting leaves a supply commitment to clarify.',?)")
    ->execute([json_encode(['initiative_followthrough'=>$discuss['initiative_followthrough']],JSON_THROW_ON_ERROR)]);

// Recording edits can omit the presentation capture_mode while retaining the
// canonical meeting binding. That must not erase the meeting's compute policy.
$beforeModels=count($GLOBALS['scopeInference']);
db()->exec("UPDATE artist_transcript_sessions_v172 SET metadata_json='{\"video_meeting_id\":700}' WHERE id=302;
    UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1");
try{
    $restricted=vp3_transcript_session_v1(db(),$user,$meetingScope['principal'],302);
    capability_pipeline_reject(static fn()=>vp3_initiative_transcript_capabilities_cloud_v1(db(),$meetingScope,$restricted),403,
        'retaining an actual meeting ID preserves explicit HomeServer-only privacy when recording edits omit capture mode');
    $coverage=[];$admitted=vp3_initiative_capabilities_evidence_v1(db(),$meetingScope,vp3_initiative_policy_v1($user,17),
        new DateTimeImmutable('now',new DateTimeZone('UTC')),['transcript:302'],$coverage);
    capability_pipeline_check(!isset($admitted['transcript:302'])&&($coverage['transcripts']??null)==='forbidden'
        &&count($GLOBALS['scopeInference'])===$beforeModels,
        'the actual canonical capability admission cannot send an edited meeting recording to Cloud preparation');
}finally{
    db()->exec("UPDATE artist_transcript_sessions_v172 SET metadata_json='{\"capture_mode\":\"vp3_video_meeting\",\"video_meeting_id\":700}' WHERE id=302;
        UPDATE agent_compute_preferences SET preference='auto' WHERE user_id=1");
}

// Browser presence is not a prerequisite for an authenticated canonical worker
// to process its owner's authorized work. The queue's explicit owner scope,
// source permissions and live remote privacy policy remain fully authoritative.
db()->exec("INSERT INTO video_meetings VALUES(701,1,0,'cccccccccccccccccccccccccccccccc','system-pipeline-room','assist','System recorded meeting')");
db()->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(303,1,142,?,?,?,?,?)')
    ->execute(['System meeting recording','draft',$now,$now,'{"capture_mode":"vp3_video_meeting","video_meeting_id":701}']);
db()->prepare("INSERT INTO artist_transcript_segments_v172 VALUES(303,303,'segment-303',0,'transcript','Owner',?,1000,2000,.9,?,?)")
    ->execute(['MEETING_RAW_RECORD_SENTINEL. Clarify the remaining System meeting commitment.',$now,$now]);
foreach([[17,170,302],[0,142,303]] as [$workerAgent,$workerConversation,$workerSource]){
    capability_pipeline_reset($user,$workerAgent,$workerConversation,false);
    $anonymousHeader=$_SERVER['HTTP_X_FIXTURE_ANONYMOUS']??null;
    $_SERVER['HTTP_X_FIXTURE_ANONYMOUS']='1';$GLOBALS['capabilityPipelineAnonymous']=true;
    try{
        capability_pipeline_check(current_user()===null,'the CLI-style Agent '.$workerAgent.' workflow begins without a signed-in browser user');
        $reads=$GLOBALS['capabilityPipelinePolicyReads'];
        $anonymous=capability_pipeline_prepare($user,$workerAgent,$workerConversation,'transcripts.read','transcript:'.$workerSource,'MEETING_RAW_RECORD_SENTINEL',false,null,false);
        capability_pipeline_check(current_user()===null&&($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']??null)==='1'
            &&$GLOBALS['capabilityPipelinePolicyReads']>$reads
            &&$anonymous['result']['initiative_delivery']==='pending'
            &&$anonymous['result']['initiative_tool_result']['output']['owner_user_id']===1,
            'the actual anonymous Agent '.$workerAgent.' worker completes its private meeting workflow through fresh scoped policy without impersonation');
    }finally{
        $GLOBALS['capabilityPipelineAnonymous']=false;
        if($anonymousHeader===null)unset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']);
        else $_SERVER['HTTP_X_FIXTURE_ANONYMOUS']=$anonymousHeader;
    }
}

// This setup explicitly reviews and executes an ordinary existing Mission. Its
// public-page/model transports are controlled; the Mission's collection,
// worker, stored actions and completed receipts are actual production records.
require_once dirname(__DIR__).'/includes/cloud-missions-v1.php';
cloud_mission_schema_v1(db());
$m=cloud_mission_prepare_v1(db(),$user,['objective'=>'Compare saved public evidence before proposing next work',
    'thread_id'=>170,'agent_id'=>17,'request_id'=>'capability-pipeline-mission']);
$assignments=$m['chat_task']['draft'];
$assignments['assignments'][0]=array_replace($assignments['assignments'][0],[
    'tools'=>['web.search','web.read'],'max_calls'=>2,
    'web'=>['query'=>'controlled source comparison','domains'=>['research.example.org'],'max_pages'=>1]]);
cloud_mission_configure_v1(db(),$user,['mission_id'=>$m['id'],'request_id'=>cloud_mission_uuid_v1('capability-pipeline-review'),
    'expected_revision'=>0,'confirmed'=>true,'assignments'=>$assignments]);
cloud_mission_service_v1(db(),$user,'tools.start',['mission_id'=>$m['id'],'expected_revision'=>1,'confirmed'=>true]);
$GLOBALS['capabilityPipelineMissionWebReads']=0;
$transport=['search'=>static fn()=>['ok'=>true,'results'=>[['url'=>'https://research.example.org/study','title'=>'Controlled public study']]],
    'get'=>static function():array{
        ++$GLOBALS['capabilityPipelineMissionWebReads'];
        return ['status'=>200,'content_type'=>'text/html','body'=>'<title>Controlled public study</title><main>'
            .str_repeat('MISSION_SAVED_EVIDENCE_SENTINEL. The controlled study reports a bounded public finding. ',40).'</main>'];
    }];
$missionModel=static fn(string $instruction,array $context,array $owner):array=>['ok'=>true,
    'answer'=>'A source-backed research interpretation. '.str_repeat('Review the saved findings before any next assignment. ',12)];
cloud_mission_tick_v1(db(),$user,$m['id'],$missionModel,$transport);
cloud_mission_tick_v1(db(),$user,$m['id'],$missionModel,$transport);
$missionRow=cloud_mission_row_v1(db(),$user,$m['id']);$missionRun=cloud_mission_run_v1(db(),$user,$missionRow);
capability_pipeline_check($missionRun['status']==='completed'&&$GLOBALS['capabilityPipelineMissionWebReads']>0,
    'the public evidence fixture has actually executed an explicitly reviewed canonical Mission');
$webReads=$GLOBALS['capabilityPipelineMissionWebReads'];
$mission=capability_pipeline_prepare($user,17,170,'missions.evidence.read','mission-evidence:'.$m['id'],'MISSION_SAVED_EVIDENCE_SENTINEL');
capability_pipeline_check($GLOBALS['capabilityPipelineMissionWebReads']===$webReads
    &&($mission['coverage']['new_research_performed']??null)===false
    &&($mission['coverage']['model_assessments_included']??null)===false,
    'the Initiative reads actual completed Mission excerpts without rerunning research or treating its generated assessment as independent evidence');

// The task tool is ready, but a transcript primary cannot be smuggled into it.
capability_pipeline_reset($user,17,170);$before=count(scope_runs(1,17,'initiative.prepare'));$draftReached=false;
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use(&$draftReached):string{
    if($protocol!=='vp3.initiative-plan.v1'){$draftReached=true;throw new LogicException('Mismatched leaf reached preparation.');}
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    capability_pipeline_check(!empty($input['tools']['tasks.read']['ready'])&&isset(array_column($input['evidence'],null,'ref')['transcript:301']),
        'the mismatch test contains a genuinely ready task leaf and a separately authorized transcript source');
    return json_encode(['jobs'=>[['tool'=>'tasks.read','primary'=>'transcript:301','refs'=>['transcript:301'],'focus'=>'Wrong leaf primary','report'=>true]]],JSON_THROW_ON_ERROR);
};
$plan=scope_make_plan($user,17,'Reject a task tool bound to a transcript');
$failed=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$plan));
capability_pipeline_check(empty($failed['ok'])&&!$draftReached&&count(scope_runs(1,17,'initiative.prepare'))===$before,
    'a model-selected leaf with the wrong source is rejected before any preparation queues');

capability_pipeline_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,'all connected capability paths restore the incoming owner and Agent namespace');
echo 'AGENT_INITIATIVE_CAPABILITIES_PIPELINE_V1=PASS ('.$capabilityPipelineChecks." cases; real source/model/queue/action/receipt/Chat/follow-through)\n";
