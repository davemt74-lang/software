<?php
declare(strict_types=1);

define('VP3_CURIOSITY_EMAIL_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-initiative-email-canvas-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-curiosity-analysis-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-link-engagement-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-opportunity-schedule-v1.php';
require_once dirname(__DIR__).'/includes/agent-screen-sessions-v1.php';
$start=$checks;
vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null);
canvas_pipeline_email_seed($pdo,$owner,$mysql);
// The source fixture supplies the columns used by native read readiness; send/draft installation is tested separately.
$pdo->exec('CREATE TABLE IF NOT EXISTS agent_connection_sends_v1(operation_key VARCHAR(64));
    CREATE TABLE IF NOT EXISTS agent_connection_email_drafts_v1(authorization_key VARCHAR(64));
    CREATE TABLE IF NOT EXISTS agent_connection_email_followups_v1(claim_key VARCHAR(64))');
foreach(['account_email'=>"VARCHAR(190) NOT NULL DEFAULT 'owner@example.test'",'external_account_id'=>"VARCHAR(190) NOT NULL DEFAULT 'fixture-owner'",
    'access_token_enc'=>"TEXT",'refresh_token_enc'=>"TEXT",'access_expires_at'=>"BIGINT NOT NULL DEFAULT 0",
    'send_enabled'=>"INTEGER NOT NULL DEFAULT 0",'followups_enabled'=>"INTEGER NOT NULL DEFAULT 0"] as $column=>$type){
    if(!column_exists('agent_connections_v1',$column))$pdo->exec("ALTER TABLE agent_connections_v1 ADD COLUMN $column $type");
}
$pdo->prepare('UPDATE agent_connections_v1 SET access_token_enc=?,refresh_token_enc=?,access_expires_at=? WHERE id=88')
    ->execute([ai_encrypt_secret('fixture-only-access'),ai_encrypt_secret('fixture-only-refresh'),time()+3600]);
$binding=vp3_initiative_policy_v1($owner,7);
screen_sessions_schema_v1($pdo);
$screenSession='00000000-0000-4000-8000-000000009901';$screenCapture='00000000-0000-4000-8000-000000009902';
$screenAt=gmdate('Y-m-d\TH:i:s\Z');
$pdo->prepare("INSERT INTO agent_screen_sessions_v1(owner_user_id,session_id,folder_id,agent_id,title,status,created_at,updated_at)
    VALUES(1,?,0,7,'Email page review','active',?,?)")->execute([$screenSession,$screenAt,$screenAt]);
$screenBody=['captured_at'=>$screenAt,'surface'=>'browser','observation'=>['summary'=>'A shared page compares microphone choices.',
    'visible_text'=>'PRIVATE_LITERAL_SCREEN_TOKEN',
    'observations'=>[['kind'=>'product','label'=>'Microphone options','detail'=>'Two microphone choices are visible.','confidence'=>'high']]]];
$pdo->prepare("INSERT INTO agent_screen_captures_v1(owner_user_id,session_id,capture_id,kind,captured_at,knowledge_id,memory_id,revision,image_data,body_json)
    VALUES(1,?,?,'screen',?,0,0,1,'PRIVATE_SCREEN_PIXELS',?)")->execute([$screenSession,$screenCapture,$screenAt,json_encode($screenBody)]);
canvas_pipeline_check(vp3_curiosity_screen_briefs_v1($pdo,$owner,7)===[],
    'Capturing a page alone cannot override the existing Send to Brain choice');
screen_capture_brain_v1($pdo,$owner,$screenCapture);
$screenBrief=vp3_curiosity_screen_briefs_v1($pdo,$owner,7);
canvas_pipeline_check(count($screenBrief)===1&&!str_contains(json_encode($screenBrief),'PRIVATE_SCREEN_')
    &&!str_contains(json_encode($screenBrief),'PRIVATE_LITERAL_SCREEN_TOKEN'),
    'Curiosity reuses an explicitly retained Agent Eyes analysis without reading pixels or literal screen text');
canvas_pipeline_check(vp3_curiosity_screen_briefs_v1($pdo,$owner,8)===[]&&vp3_curiosity_screen_briefs_v1($pdo,['id'=>2],7)===[],
    'A different Agent or account cannot borrow the retained visual finding');
vp3_initiative_in_scope_v1($owner,7,static fn()=>agent_brain_v122_upsert_system_memory($GLOBALS['owner'],'task',
    'Workshop preparation','Prepare the microphone checklist before the workshop.',
    ['task_status'=>'open','due_at'=>gmdate('Y-m-d H:i:s',time()+3600)]));
$scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
$post=$pdo->query("SELECT id,context_json FROM chat_messages WHERE conversation_id=11 AND role='assistant' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$view=agent_connection_gmail_inbox_update_history_v1($pdo,$scope,7,11,json_decode($post['context_json'],true),(int)$post['id']);
$item=$view['review']['items'][0]['item_ref']??null;
canvas_pipeline_check(is_string($item),'The activity test starts from a real saved Gmail review item');
$native=agent_connection_gmail_inbox_item_v1($pdo,$owner,$item);
$httpCalls=0;
$http=static function(string $method,string $url)use(&$httpCalls,$native):array{
    ++$httpCalls;
    canvas_pipeline_check($method==='GET'&&!str_contains($url,'/send'),'Curiosity uses the existing read-only Gmail transport');
    if(str_contains($url,'/threads/'))return ['status'=>200,'json'=>['id'=>$native['message_id'],'messages'=>[]]];
    preg_match('~/messages/([^/?]+)~',$url,$match);$id=$match[1]??$native['message_id'];
    return ['status'=>200,'json'=>['id'=>$id,'threadId'=>str_replace('_message_','_thread_',$id),
        'labelIds'=>str_ends_with($id,'_2')?['SPAM']:['INBOX'],'internalDate'=>(string)((time()-20)*1000),
        'payload'=>['mimeType'=>'text/html','headers'=>[['name'=>'Subject','value'=>'Workshop preparation'],['name'=>'From','value'=>'writer@example.test']],
            'body'=>['data'=>rtrim(strtr(base64_encode('<p>Bring the microphone checklist to the workshop. Compare this with the saved preparation work.</p><a href="https://example.com/audio-guide">Microphone workshop guide</a><a href="https://example.com/account/verify?token=fixture-private">Account check</a><a href="https://example.com/workshop-notes">Workshop notes</a>'),'+/','-_'),'=')]]]];
};
// This is a background read, not an owner open.
$opened=agent_connection_gmail_inbox_open_item_v1($pdo,$owner,$item,$http,includeLinks:true);
canvas_pipeline_check(vp3_curiosity_email_activity_v1($pdo,$owner,$binding)===[],
    'An automatic email read cannot fabricate an owner open or a preference');
canvas_pipeline_check(vp3_curiosity_email_open_v1($pdo,$owner,$item,['subject'=>'Workshop preparation'],7,11),
    'A verified explicit owner open records one bounded activity observation');
canvas_pipeline_check(!vp3_curiosity_email_open_v1($pdo,$owner,$item,['subject'=>'Workshop preparation'],7,11),
    'An immediate browser retry does not inflate open history');
$activity=vp3_curiosity_email_activity_v1($pdo,$owner,$binding);
canvas_pipeline_check(count($activity)===1&&$activity[0]['action']==='message_open'&&$activity[0]['count']===1
    &&!str_contains(json_encode($activity),'Bring the microphone'), 'History retains a scoped reference and action rather than a copied email body');
$link=$opened['links'][0];
$token=vp3_gmail_chat_link_token_v1($scope,7,11,(int)$post['id'],$item,$link['url'],$link['label']);
canvas_pipeline_check(vp3_gmail_curiosity_record_link_v1($pdo,$owner,7,11,(int)$post['id'],$item,$link['url'],$token,$link['label']),
    'A click on an exact displayed email link joins the existing verified link history');
$activity=vp3_curiosity_email_activity_v1($pdo,$owner,$binding);
canvas_pipeline_check(count($activity)===2&&count(array_filter($activity,static fn(array $row):bool=>$row['action']==='link_open'))===1,
    'The analysis input remembers message opens and link clicks as separate observations');
$private=$opened['links'][1];$pageCalls=0;
$proof=vp3_gmail_chat_link_token_v1($scope,7,11,(int)$post['id'],$item,$private['url'],$private['label']);
canvas_pipeline_check(vp3_gmail_curiosity_record_link_v1($pdo,$owner,7,11,(int)$post['id'],$item,$private['url'],$proof,$private['label'],
    static function()use(&$pageCalls):array{++$pageCalls;throw new RuntimeException('A private action URL must not be fetched.');})&&$pageCalls===0,
    'A verified private navigation can be remembered without fetching its target');
$stored=implode('',array_map('strval',$pdo->query("SELECT metadata_json FROM agent_memory_items WHERE subject LIKE 'gmail-link:%'")->fetchAll(PDO::FETCH_COLUMN)));
canvas_pipeline_check(!str_contains($stored,'fixture-private')&&!str_contains($stored,'example.com/account'),
    'Click memory stores an opaque identity rather than a private URL or token');
canvas_pipeline_check(vp3_curiosity_email_open_v1($pdo,$owner,$item,$opened),
    'An explicit open from the main mailbox records owner-global activity without inventing a Chat post');
$global=$opened['links'][2];$proof=vp3_gmail_chat_link_token_v1($scope,0,0,0,$item,$global['url'],$global['label']);
canvas_pipeline_check(vp3_gmail_curiosity_record_link_v1($pdo,$owner,0,0,0,$item,$global['url'],$proof,$global['label']),
    'Main-mailbox links use their native signed item instead of borrowing a Chat anchor');
$activity=vp3_curiosity_email_activity_v1($pdo,$owner,$binding);
canvas_pipeline_check(count($activity)===5&&count(array_filter($activity,static fn(array $row):bool=>$row['action']==='link_open'))===3,
    'Curiosity combines owner-global mailbox actions with this Agent Chat’s verified observations');
$foreign=[];
try{$foreign=vp3_curiosity_email_activity_v1($pdo,['id'=>2,'is_active'=>1],vp3_initiative_policy_v1(['id'=>2],9));}
catch(RuntimeException $error){if($error->getCode()!==403)throw $error;}
canvas_pipeline_check($foreign===[],
    'Another owner cannot read this mailbox activity');
$beforeReads=$httpCalls;$beforeMessages=canvas_pipeline_count('chat_messages');
if(defined('VP3_CURIOSITY_ANALYSIS_FIXTURE'))return;
$snapshot=vp3_curiosity_analysis_snapshot_v1($pdo,$owner,$binding);
canvas_pipeline_check($httpCalls===$beforeReads&&in_array('gmail',$snapshot['input_specialists'],true),
    'A Curiosity snapshot observes activity without fetching email or running a model');
canvas_pipeline_check(vp3_curiosity_analysis_schedule_v1($pdo,$owner,$binding)==='queued',
    'Curiosity receives its own canonical private analysis assignment');
$runs=canvas_pipeline_runs('initiative.prepare');$run=(int)end($runs)['id'];
$row=agent_workflow_row_v1400($pdo,1,$run);
canvas_pipeline_check(vp3_initiative_runtime_job_specialist_v1($row)==='curiosity',
    'The leased analyst is identified as Curiosity rather than Brainstorming or OB1');
$calls=0;
$done=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($run),static function(string $prompt,string $protocol)use(&$calls):string{
    ++$calls;$input=json_decode($prompt,true);
    canvas_pipeline_check(!db()->inTransaction()&&$protocol==='vp3.initiative-draft.v1'&&$input['role']==='curiosity'
        &&$input['budget']['max_jobs']===0&&$input['budget']['max_emails']===3,'The metered analyst receives explicit non-executing bounds outside SQL locks');
    canvas_pipeline_check(str_contains($prompt,'Bring the microphone checklist')&&count(array_filter($input['email_activity'],static fn(array $row):bool=>$row['action']==='message_open'))===2
        &&!empty($input['specialists']['tasks']['sources'])&&isset($input['specialists']['media']),
        'Curiosity reads the actual bounded email and considers the other specialist briefs together');
    canvas_pipeline_check(count($input['email_samples'])<=3&&count(array_filter($input['email_samples'],
        static fn(array $row):bool=>$row['source']==='inbox_review'))>=1&&count($input['email_samples'])===2,
        'Curiosity reads recent unopened mail too, keeps a three-message limit and excludes current spam');
    canvas_pipeline_check(count($input['screen_findings'])===1&&!str_contains($prompt,'PRIVATE_LITERAL_SCREEN_TOKEN')
        &&!str_contains($prompt,'PRIVATE_SCREEN_PIXELS'),'The actual analyst receives permitted Eyes findings with email and task data');
    return json_encode(['draft'=>'Observed: the owner opened a workshop message. Its preparation request may connect with checklist work. The open alone does not establish a preference. Brainstorming could explore a small preparation aid.']);
},['gmail_http'=>$http],false);
canvas_pipeline_check($done['ok']&&$calls===1&&canvas_pipeline_count('chat_messages')===$beforeMessages,
    'One leased analysis saves a private receipt without posting a thought or executing a suggestion');
$report=vp3_curiosity_analysis_report_v1($pdo,$owner,$binding);
canvas_pipeline_check($report!==null&&$report['report']['preferences_confirmed']===false
    &&$report['report']['email_actions']===['message_opens'=>2,'link_opens'=>3],'The canonical finding distinguishes observed attention from confirmed taste');
require_once dirname(__DIR__).'/includes/agent-specialist-invitations-v1.php';
$invitationReports=vp3_specialist_invitation_reports_v1($pdo,vp3_agent_activity_scope_v1($pdo,$owner,7,(int)$binding['conversation_id']),$binding);
canvas_pipeline_check(count(array_filter($invitationReports,static fn(array $row):bool=>$row['family']==='curiosity'
    &&$row['key']===$report['key']))===1,'Invitations can consider the exact native Curiosity analysis without confirming a preference');
canvas_pipeline_check(vp3_curiosity_analysis_snapshot_v1($pdo,$owner,$binding)['revision']===$snapshot['revision']
    &&vp3_curiosity_analysis_schedule_v1($pdo,$owner,$binding)==='already_handled',
    'Curiosity cannot turn its own completed analysis into new work');
$brain=vp3_brainstorm_snapshot_v1($pdo,$owner,$binding);
$reports=array_values(array_filter($brain['reports'],static fn(array $r):bool=>($r['outcome']??null)==='analysis_ready'));
canvas_pipeline_check(count($reports)===1&&$brain['briefs']['curiosity']['state']==='current_context',
    'Brainstorming receives the exact current Curiosity analysis as a specialist input');
$task=array_key_first(array_filter($brain['evidence'],static fn(array $row):bool=>in_array($row['kind'],['task','open_task'],true)));
canvas_pipeline_check($task!==null&&vp3_brainstorm_schedule_v1($pdo,$owner,$binding)==='queued',
    'A new Curiosity finding admits a source-backed creative assessment');
$runs=canvas_pipeline_runs('initiative.prepare');$creative=(int)end($runs)['id'];
$creativeDone=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($creative),static function(string $prompt)use($task):string{
    $input=json_decode($prompt,true);
    $analyses=array_values(array_filter($input['specialist_reports'],static fn(array $row):bool=>($row['outcome']??null)==='analysis_ready'));
    canvas_pipeline_check($input['role']==='brainstorm'&&count($analyses)===1&&$analyses[0]['preferences_confirmed']===false,
        'The actual creative model reasons over the analyst finding without treating attention as confirmed taste');
    return json_encode(['jobs'=>[],'opportunities'=>[['tool'=>'work.prepare_private','primary'=>$task,'refs'=>[$task],
        'objective'=>'Prepare a useful workshop checklist','rationale'=>'The saved task and current private analysis suggest a practical preparation aid.']]]);
},null,false);
canvas_pipeline_check($creativeDone['ok'],'The canonical Brainstorming worker commits the source-backed follow-up idea');
$plan=['binding'=>$binding,'sources'=>array_column($brain['evidence'],'revision','ref'),
    'canonical_sources'=>array_column($brain['evidence'],'revision','ref')];
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.plan',$plan,hash('sha256','curiosity-to-creative-to-ob1'));
$plans=canvas_pipeline_runs('initiative.plan');$review=(int)end($plans)['id'];
$reviewed=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($review),static function(string $prompt)use($task):string{
    $input=json_decode($prompt,true);$reports=$input['specialist_reports'];
    canvas_pipeline_check(count(array_filter($reports,static fn(array $row):bool=>($row['outcome']??null)==='analysis_ready'))===1
        &&count(array_filter($reports,static fn(array $row):bool=>$row['specialist']==='brainstorm'))===1,
        'The actual OB1 model receives both the Curiosity analysis and the separate creative proposal');
    return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$task,'refs'=>[$task],
        'focus'=>'Evaluate a small workshop preparation aid','report'=>false]]]);
},null,false);
canvas_pipeline_check($reviewed['ok']&&(int)canvas_pipeline_result($review)['jobs']===1&&canvas_pipeline_count('chat_messages')===$beforeMessages,
    'OB1 alone admits the next private preparation while both specialists remain non-executing');
$authority=[];$reports=vp3_initiative_specialist_reports_v1($pdo,$owner,$binding,$brain['evidence'],$authority);
canvas_pipeline_check(count(array_filter($reports,static fn(array $r):bool=>($r['outcome']??null)==='analysis_ready'))===1
    &&($authority['curiosity_analysis']['run_id']??null)===$run,'OB1 reads the same analysis receipt rather than a second findings store');
$q=$pdo->prepare('SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');
$q->execute([$run]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$receipt['result_json'].' ',$receipt['id']]);
canvas_pipeline_check(vp3_curiosity_analysis_report_v1($pdo,$owner,$binding)===null,
    'A byte-mismatched analysis receipt cannot influence Brainstorming or OB1');
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$receipt['result_json'],$receipt['id']]);
$beforeReads=$httpCalls;$beforeModels=$calls;
if(!$mysql)$pdo->exec('PRAGMA query_only=ON');
try{$again=vp3_curiosity_analysis_report_v1($pdo,$owner,$binding);}
finally{if(!$mysql)$pdo->exec('PRAGMA query_only=OFF');}
canvas_pipeline_check($again!==null&&$httpCalls===$beforeReads&&$calls===$beforeModels,
    'Reading analysis status cannot run another model, fetch an email or write a row');
$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=88');
canvas_pipeline_check(vp3_curiosity_email_activity_v1($pdo,$owner,$binding)===[]
    &&vp3_curiosity_analysis_report_v1($pdo,$owner,$binding)===null,
    'Withdrawing Gmail read access withdraws the activity and its derived analysis from both specialists');
canvas_pipeline_check(vp3_brainstorm_report_v1($pdo,$owner,$binding)===null,
    'Withdrawal also invalidates the creative idea derived from the former analysis');
$beforeCuriosity=static fn():int=>count(array_filter(canvas_pipeline_runs('initiative.prepare'),
    static fn(array $row):bool=>(json_decode($row['goal'],true)['tool']??null)==='curiosity.analyze'));
$beforeCount=$beforeCuriosity();
vp3_initiative_in_scope_v1($owner,7,static function()use($owner,$binding):void{
    $lanes=[];
    foreach(VP3_INITIATIVE_OPPORTUNITY_LANES_V1 as $lane)if($lane!=='curiosity_analysis')$lanes[$lane]=['checked_at'=>time(),'state'=>'no_opportunity'];
    vp3_initiative_opportunity_save_v1($owner,$binding,$lanes);
});
$pdo->exec("UPDATE agent_activity_state SET last_heartbeat_at='2000-01-01 00:00:00';
    UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep' AND subject='last-review'");
$modelsBefore=count($GLOBALS['pipelineInference']);
vp3_initiative_sweep_v1($pdo,25);
canvas_pipeline_check($beforeCuriosity()===$beforeCount+1&&count($GLOBALS['pipelineInference'])===$modelsBefore,
    'The existing server sweep schedules fresh specialist analysis while the browser heartbeat is stale');
vp3_initiative_sweep_v1($pdo,25);
canvas_pipeline_check($beforeCuriosity()===$beforeCount+1,'A repeated closed-browser sweep cannot duplicate the ready analyst');
$runs=array_filter(canvas_pipeline_runs('initiative.prepare'),static fn(array $row):bool=>(json_decode($row['goal'],true)['tool']??null)==='curiosity.analyze');
$last=end($runs);$stale=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim((int)$last['id']),static function()use($screenCapture):string{
    db()->prepare('UPDATE agent_screen_captures_v1 SET revision=revision+1 WHERE capture_id=?')->execute([$screenCapture]);
    return json_encode(['draft'=>'An outdated screen interpretation must not be retained.']);
},null,false);
canvas_pipeline_check(!$stale['ok'],'A capture revision change during inference prevents a stale Curiosity report');
$pdo->prepare('DELETE FROM agent_memory_items WHERE id=(SELECT memory_id FROM agent_screen_captures_v1 WHERE capture_id=?)')->execute([$screenCapture]);
canvas_pipeline_check(vp3_curiosity_screen_briefs_v1($pdo,$owner,7)===[],
    'Withdrawing the retained Brain memory withdraws the visual input without creating a copied findings store');
// Location Specialist contributes only explicit, opted-in repeat visits.
// This analyst fixture has simplified conversation IDs. Install only its actual
// location inputs; the complete Phone schema is covered by native phone tests.
$locationOptions=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
$pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_devices_v1(
    id CHAR(32) NOT NULL PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,device_name VARCHAR(48) NOT NULL,device_role VARCHAR(12) NOT NULL,
    device_status VARCHAR(12) NOT NULL,allow_location SMALLINT NOT NULL,generation BIGINT NOT NULL,
    location_state VARCHAR(12) NOT NULL,created_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,last_seen_at BIGINT NOT NULL,
    UNIQUE(owner_user_id,id)
)".$locationOptions);
vp3_phone_checkin_install_v1($pdo,$locationOptions);
$locationDevice=str_repeat('9',32);$locationNow=time();
$pdo->prepare("INSERT INTO vp3_phone_devices_v1(id,owner_user_id,token_hash,device_name,device_role,device_status,allow_location,generation,location_state,created_at,expires_at,last_seen_at) VALUES(?,1,?,'Private phone','owner','approved',1,1,'live',?,?,?)")
    ->execute([$locationDevice,str_repeat('8',64),$locationNow,$locationNow+86400,$locationNow]);
$beforeLocation=vp3_curiosity_analysis_snapshot_v1($pdo,$owner,$binding);
for($day=0;$day<3;$day++)$pdo->prepare("INSERT INTO vp3_phone_checkins_v1(owner_user_id,device_id,visit_id,generation,version,latitude,longitude,accuracy,started_epoch,ended_epoch,last_fix_epoch,outside_epoch,reason,learn,label) VALUES(1,?,?,1,1,33.45,-112.07,10,?,?,?,NULL,'manual',1,'')")
    ->execute([$locationDevice,md5('curiosity-location-'.$day),$locationNow-$day*86400,$locationNow-$day*86400+3600,$locationNow-$day*86400]);
$withLocation=vp3_curiosity_analysis_snapshot_v1($pdo,$owner,$binding);
canvas_pipeline_check(count($withLocation['location_checkins'])===1&&in_array('location',$withLocation['input_specialists'],true)
    &&$withLocation['revision']!==$beforeLocation['revision'],'Opted-in repeat check-ins enter the current Curiosity analysis and its revision');
canvas_pipeline_check($withLocation['location_checkins'][0]['label']===''&&!str_contains(json_encode($withLocation['location_checkins']),'latitude'),
    'Curiosity sees a tentative repeat-place question without GPS coordinates or an invented work/school label');
$pdo->prepare('UPDATE vp3_phone_checkins_v1 SET learn=0 WHERE device_id=?')->execute([$locationDevice]);
$forgotten=vp3_curiosity_analysis_snapshot_v1($pdo,$owner,$binding);
canvas_pipeline_check($forgotten['location_checkins']===[]&&$forgotten['revision']!==$withLocation['revision'],
    'Forgetting check-in learning withdraws its Curiosity input and invalidates the old report revision');
echo 'CURIOSITY_ANALYSIS=PASS ('.($checks-$start).' cases, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
