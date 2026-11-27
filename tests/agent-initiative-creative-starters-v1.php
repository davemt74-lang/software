<?php
declare(strict_types=1);

define('VP3_BRAINSTORM_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-initiative-brainstorm-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-creative-starters-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-youtube-playlist-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-youtube-chat-v1.php';
$starterStart=$checks;$fresh=['id'=>2,'is_active'=>1];
// A different real owner/Agent namespace has no conversations, tasks or canvases to learn from.
$pdo->exec('DELETE FROM chat_messages WHERE conversation_id=13; DELETE FROM agent_memory_items WHERE user_id=2; DELETE FROM user_media_assets WHERE user_id=2');
vp3_coding_canvas_model_save_v1($pdo,$fresh,$selected);
$freshAgent=vp3_agent_chat_resolve_agent_v380($pdo,$fresh,9);
vp3_initiative_chat_v1($pdo,$fresh,vp3_agent_chat_principal_v380($fresh,$freshAgent),['conversation_id'=>13],'on',null);
$freshBinding=vp3_initiative_policy_v1($fresh,9);
agent_connection_youtube_consent_schema_v1($pdo);agent_connection_youtube_quota_schema_v1($pdo);
// The reusable image fixture has a legacy signed message ID; use the canonical FK type.
if($mysql)$pdo->exec('ALTER TABLE chat_messages MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
agent_connection_youtube_results_schema_v1($pdo);vp3_initiative_youtube_playlist_install_v1($pdo);
agent_connection_youtube_set_consent_v1($pdo,2,true);vp3_media_offers_save_v1($pdo,2,true);
putenv('VP3_YOUTUBE_API_KEY='.str_repeat('s',20));
$starterRef='creative-starter:2:9';
$sources=vp3_initiative_in_scope_v1($fresh,9,static function()use($fresh):array{
    $coverage=[];return vp3_initiative_evidence_v1(db(),$fresh,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,9);
});
canvas_pipeline_check(isset($sources[$starterRef])&&$sources[$starterRef]['kind']==='creative_starter',
    'A real empty owner/Agent namespace discovers useful starters without a history threshold');
$catalog=vp3_initiative_tools_catalog_v1($pdo,$fresh,$freshBinding,$sources);
canvas_pipeline_check(!empty($catalog['image.prepare']['ready'])&&!empty($catalog['canvas.prepare']['ready'])
    &&!empty($catalog['playlist.prepare']['ready']),
    'Images, functional Canvas proposals and verified playlists are available through actual configured capabilities');
canvas_pipeline_check(!str_contains(json_encode($sources),'Fixture Agent')
    &&str_contains($sources[$starterRef]['detail'],'generic possibilities'),
    'Starter context is explicitly generic and contains no borrowed owner history');
$mixed=$sources;
foreach(['account_readiness','business_readiness','calendar_readiness'] as $kind){
    $ref='setup-'.$kind;$mixed[$ref]=['ref'=>$ref,'kind'=>$kind,'title'=>'Setup context','revision'=>hash('sha256',$kind)];
}
foreach([10,11,13] as $cid){$ref='chat-history:'.$cid;$mixed[$ref]=['ref'=>$ref,'kind'=>'chat_history','title'=>'Owner conversation','revision'=>hash('sha256',$ref)];}
$selectedContext=vp3_brainstorm_sources_v1($mixed,$freshBinding);
canvas_pipeline_check(isset($selectedContext[$starterRef],$selectedContext['chat-history:13'])
    &&count(array_filter($selectedContext,static fn(array $row):bool=>$row['kind']==='chat_history'))===2,
    'Setup rows cannot crowd out starter capabilities or the current conversation within bounded context');
$beforeModels=count($GLOBALS['pipelineInference']);$beforeMessages=canvas_pipeline_count('chat_messages');
if(!$mysql)$pdo->exec('PRAGMA query_only=ON');
try{$readAgain=vp3_creative_starter_evidence_v1($pdo,$fresh,$freshBinding,[$starterRef],[]);}
finally{if(!$mysql)$pdo->exec('PRAGMA query_only=OFF');}
canvas_pipeline_check($readAgain[$starterRef]['revision']===$sources[$starterRef]['revision']
    &&$readAgain[$starterRef]['starter_tools']===$sources[$starterRef]['starter_tools']
    &&count($GLOBALS['pipelineInference'])===$beforeModels&&canvas_pipeline_count('chat_messages')===$beforeMessages,
    'Reading starter availability performs no inference, generation, provider search or Chat write');
$claim=static fn(int $id):array=>agent_job_claim_run_v1900($pdo,$fresh,$id,'cloud',agent_worker_cloud_worker_id_v1910(2),180)
    ?:throw new RuntimeException('Could not claim fresh-account work.');
canvas_pipeline_check(vp3_brainstorm_schedule_v1($pdo,$fresh,$freshBinding)==='queued',
    'Brainstorming starts from the real empty-account capability context');
$q=$pdo->query("SELECT id FROM agent_workflow_runs WHERE owner_user_id=2 AND capability_key='initiative.prepare' ORDER BY id DESC LIMIT 1");
$brain=(int)$q->fetchColumn();
$brainDone=vp3_initiative_execute_v1($pdo,$fresh,$claim($brain),static function(string $prompt)use($starterRef):string{
    $input=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($input['context_mode']==='starter'&&$input['role']==='brainstorm'
        &&$input['budget']['max_jobs']===0,
        'The creative worker explicitly handles little-context mode without inventing user history');
    return json_encode(['jobs'=>[],'opportunities'=>array_map(static fn(string $tool):array=>[
        'tool'=>$tool,'primary'=>$starterRef,'refs'=>[$starterRef],
        'objective'=>'Prepare a useful general '.($tool==='image.prepare'?'visual concept':($tool==='playlist.prepare'?'focus playlist':'private checklist')),
        'rationale'=>'This optional starter can be useful without personal account history.'],['image.prepare','playlist.prepare','canvas.prepare'])],JSON_THROW_ON_ERROR);
},null,false);
canvas_pipeline_check($brainDone['ok']&&count(canvas_pipeline_result($brain)['initiative_tool_result']['output']['ideas'])===3,
    'Brainstorming returns three usable starter directions through its private verified report');
$plan=['binding'=>$freshBinding,'sources'=>[$starterRef=>$sources[$starterRef]['revision']],
    'canonical_sources'=>[$starterRef=>$sources[$starterRef]['revision']]];
vp3_initiative_enqueue_v1($pdo,$fresh,'initiative.plan',$plan,hash('sha256','starter-supervisor-regression'));
$supervisor=(int)$pdo->query("SELECT id FROM agent_workflow_runs WHERE owner_user_id=2 AND capability_key='initiative.plan' ORDER BY id DESC LIMIT 1")->fetchColumn();
$review=vp3_initiative_execute_v1($pdo,$fresh,$claim($supervisor),static function(string $prompt)use($starterRef):string{
    $input=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);
    $reports=array_values(array_filter($input['specialist_reports'],static fn(array $report):bool=>$report['specialist']==='brainstorm'));
    canvas_pipeline_check(count($reports)===1&&count($reports[0]['ideas'])===3,
        'OB1 receives and evaluates the empty-account starter ideas');
    return json_encode(['jobs'=>array_map(static fn(string $tool):array=>['tool'=>$tool,'primary'=>$starterRef,'refs'=>[$starterRef],
        'focus'=>'Prepare the optional general starter for private review.','report'=>true],
        ['image.prepare','playlist.prepare','canvas.prepare'])],JSON_THROW_ON_ERROR);
},null,false);
canvas_pipeline_check($review['ok']&&(int)canvas_pipeline_result($supervisor)['jobs']===3,
    'OB1 assigns all three concrete outputs through existing governed executors');
$goal=static fn(string $tool):array=>['binding'=>$freshBinding,'tool'=>$tool,'primary'=>$starterRef,
    'sources'=>[$starterRef=>$sources[$starterRef]['revision']],'canonical_sources'=>[$starterRef=>$sources[$starterRef]['revision']],
    'report'=>true,'focus'=>'Prepare one useful general starter for private review.'];
$queue=static function(string $tool)use($pdo):int{
    $q=$pdo->query("SELECT id,goal FROM agent_workflow_runs WHERE owner_user_id=2 AND capability_key='initiative.prepare' ORDER BY id DESC");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if((json_decode($row['goal'],true)['tool']??null)===$tool)return (int)$row['id'];
    }
    throw new RuntimeException('OB1 did not assign the expected starter executor.');
};
$image=$queue('image.prepare');$beforeGenerations=canvas_pipeline_count('agent_workflow_runs',"source_kind='image_generation'");
$imageDone=vp3_initiative_execute_v1($pdo,$fresh,$claim($image),static fn():string=>
    json_encode(['draft'=>'An original abstract wallpaper with layered indigo paper, a warm copper circle and generous negative space. No text.'],JSON_THROW_ON_ERROR),null,false);
canvas_pipeline_check($imageDone['ok']&&canvas_pipeline_result($image)['initiative_tool_result']['output']['status']==='concept_ready'
    &&canvas_pipeline_count('agent_workflow_runs',"source_kind='image_generation'")===$beforeGenerations,
    'An empty account receives a usable visual concept while paid generation retains its normal authorization');
$canvas=$queue('canvas.prepare');
$canvasDone=vp3_initiative_execute_v1($pdo,$fresh,$claim($canvas),static fn():string=>json_encode([
    'title'=>'Focus checklist','prompt'=>'Build a small private checklist with a working Add item button, checkboxes and saved local app state.',
    'draft'=>'I can prepare a small working focus checklist for your private Canvas.'],JSON_THROW_ON_ERROR),null,false);
canvas_pipeline_check($canvasDone['ok']&&canvas_pipeline_result($canvas)['initiative_tool_result']['output']['status']==='proposal_ready',
    'A functional Canvas workflow can be proposed with no personal records and retains its existing review requirement');
$requests=[];$ids=['starter0001','starter0002','starter0003'];
$http=static function(string $url)use(&$requests,$ids):array{
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    if(str_starts_with($url,VP3_YOUTUBE_SEARCH_ENDPOINT_V1.'?')){
        canvas_pipeline_check($query['q']===VP3_CREATIVE_STARTER_PLAYLIST_SUBJECT_V1,
            'The starter playlist sends only the fixed public theme to the provider');
        $requests[]='search';return ['status'=>200,'json'=>['kind'=>'youtube#searchListResponse',
            'items'=>array_map(static fn(string $id):array=>['id'=>['kind'=>'youtube#video','videoId'=>$id],
                'snippet'=>['title'=>'Instrumental '.$id,'channelTitle'=>'Fixture music']],$ids)]];
    }
    canvas_pipeline_check(str_starts_with($url,VP3_YOUTUBE_STATUS_ENDPOINT_V1.'?'),
        'Starter playlist candidates pass the existing exact video-status verifier');
    $requests[]='verify';return ['status'=>200,'json'=>['kind'=>'youtube#videoListResponse',
        'items'=>array_map(static fn(string $id):array=>['id'=>$id,
            'snippet'=>['title'=>'Instrumental '.$id,'channelTitle'=>'Fixture music','categoryId'=>'10'],
            'status'=>['privacyStatus'=>'public','embeddable'=>$id!==$ids[2],'madeForKids'=>false]],$ids)]];
};
$playlist=$queue('playlist.prepare');
$playlistDone=vp3_initiative_execute_v1($pdo,$fresh,$claim($playlist),static function():string{
    throw new RuntimeException('Playlist preparation must not invent videos with a model.');
},['youtube_http'=>$http],false);
canvas_pipeline_check($playlistDone['ok']&&$requests===['search','verify'],
    'The canonical Media job prepares a real verified playlist with one quota-metered search');
$playlistResult=canvas_pipeline_result($playlist);
$payload=vp3_initiative_youtube_playlist_load_v1($pdo,2,$playlist);
canvas_pipeline_check(array_column($payload['videos'],'video_id')===array_slice($ids,0,2)
    &&$playlistResult['initiative_tool_result']['output']['playlist_run_id']===$playlist,
    'Only playable verified videos enter the existing private player payload');
$q=$pdo->prepare('SELECT attempts,successes FROM agent_connection_youtube_usage_v1 WHERE owner_user_id=2');$q->execute();$usage=$q->fetch();
canvas_pipeline_check((int)$usage['attempts']===1&&(int)$usage['successes']===1,
    'Starter playlists use the existing owner quota and provider-success accounting');
canvas_pipeline_check(vp3_starter_playlist_tool_result_v1($playlistResult['initiative_tool_result'])!==null,
    'The committed playlist has a typed source-bound receipt rather than fabricated video text');
canvas_pipeline_check(canvas_pipeline_count('chat_messages')===$beforeMessages,
    'Background preparation finishes without inserting messages before delivery is allowed');
for($i=0;$i<3;$i++){
    canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$fresh,9),
        'The normal delivery path posts one verified starter result '.($i+1));
}
$messages=$pdo->query("SELECT id,context_json FROM chat_messages WHERE conversation_id=13 AND role='assistant' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$byRun=[];
foreach($messages as $message){$context=json_decode($message['context_json'],true,32);$byRun[$context['initiative_run_id']]=[$message,$context];}
$playlistMessage=(int)$byRun[$playlist][0]['id'];
$restored=agent_connection_youtube_results_read_v1($pdo,2,9,13,[$playlistMessage]);
canvas_pipeline_check(count($restored[$playlistMessage]['videos'])===2
    &&vp3_initiative_youtube_playlist_load_v1($pdo,2,$playlist)===null&&$requests===['search','verify'],
    'Delivery moves the verified playable playlist into Chat history without another provider request');
vp3_starter_playlist_current_v1($pdo,$fresh,$freshBinding,$goal('playlist.prepare'),$playlistResult['initiative_tool_result']);
$afterDelivery=canvas_pipeline_count('chat_messages');
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$fresh,9)&&count($messages)===3
    &&canvas_pipeline_count('chat_messages')===$afterDelivery,
    'Reloading delivery cannot duplicate the starter messages or regenerate their contents');
$filler=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
    VALUES(13,NULL,'assistant','Another saved Chat turn.','{}',CURRENT_TIMESTAMP)");
for($i=0;$i<35;$i++)$filler->execute();
vp3_starter_playlist_current_v1($pdo,$fresh,$freshBinding,$goal('playlist.prepare'),$playlistResult['initiative_tool_result']);
canvas_pipeline_check($requests===['search','verify'],
    'An older playlist remains verifiable after many newer Chat messages without another search');
// Advance the exact Canvas through its normal owner review, then run the real coding worker.
[$canvasMessage,$canvasContext]=$byRun[$canvas];$state=$canvasContext['initiative_followthrough_state'];
$text='Yes, build that exact private checklist draft now.';
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES(13,2,'user',?,CURRENT_TIMESTAMP)")->execute([$text]);
$reply=(int)$pdo->lastInsertId();
$GLOBALS['pipelineModel']=static fn():string=>json_encode(['intent'=>'advance','focus'=>'Build the exact reviewed private checklist'],JSON_THROW_ON_ERROR);
$advanced=vp3_initiative_followthrough_reply_v1($pdo,$fresh,vp3_agent_chat_principal_v380($fresh,$freshAgent),13,$reply,
    ['message_id'=>(int)$canvasMessage['id'],'reply_ref'=>$state['reply_ref'],'revision'=>$state['revision']],$text);
canvas_pipeline_check(($advanced['initiative_followthrough']['state']??null)==='queued',
    'An explicit owner reply advances only the exact starter Canvas proposal');
$continuation=(int)$pdo->query("SELECT id FROM agent_workflow_runs WHERE owner_user_id=2 AND capability_key='initiative.prepare' ORDER BY id DESC LIMIT 1")->fetchColumn();
$GLOBALS['pipelineModel']=static fn()=>throw new RuntimeException('An approved starter must reuse its exact reviewed proposal.');
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$fresh,$claim($continuation),null,null,false)['ok'],
    'The approved starter reuses its proposal and queues the canonical coding executor');
$continued=canvas_pipeline_result($continuation);$coding=(int)$continued['initiative_tool_result']['output']['coding_run_id'];
// Reuse the inherited streaming wire fixture; the native worker, meter and store still run.
canvas_pipeline_check(canvas_pipeline_coding_execute($pdo,$fresh,$claim($coding))['ok'],
    'A new account can produce a real private app draft through the selected metered Coding model');
$canvasId=$continued['initiative_tool_result']['output']['canvas_id'];
$draft=vp3_coding_canvas_read_v1($pdo,$fresh,9,$canvasId);
canvas_pipeline_check($draft['canvas']['active_revision']===null&&$draft['revision']['id']===1
    &&str_contains(json_encode($draft['revision']),'CanvasData.save'),
    'The saved app contains a functional interaction and state handler, with activation still under owner control');
$rev=$sources[$starterRef]['revision'];
agent_connection_youtube_set_consent_v1($pdo,2,false);
$now=vp3_creative_starter_evidence_v1($pdo,$fresh,$freshBinding,[$starterRef],[]);
canvas_pipeline_check($now[$starterRef]['revision']===$rev&&!in_array('playlist.prepare',$now[$starterRef]['starter_tools'],true),
    'Revoking YouTube removes that capability without invalidating unrelated generic image or Canvas context');
$denied=false;try{vp3_starter_playlist_current_v1($pdo,$fresh,$freshBinding,$goal('playlist.prepare'),$playlistResult['initiative_tool_result']);}
catch(RuntimeException $error){$denied=true;}
canvas_pipeline_check($denied,'Withdrawn consent removes the saved playlist from later private display');
echo 'INITIATIVE_CREATIVE_STARTERS=PASS ('.($checks-$starterStart).' cases, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
