<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-tool-receipts-v1.php';
$checks=0;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
$created=vp3_tool_receipt_v1('reminders.manage','create','completed','',12);
check($created['changes_applied']&&$created['record_id']===12,'successful mutation has a server receipt and record reference');
$empty=vp3_tool_receipt_v1('reminders.manage','list','no_matches');
check(!$empty['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$empty]),'no matching records'),'empty read is not diagnosed as lost access');
$pending=vp3_tool_receipt_v1('calendar.manage','prepare','clarification_required','confirmation_required');
check(!$pending['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$pending]),'still pending'),'pending confirmation cannot be described as completed');
$failed=vp3_tool_receipt_v1('capabilities.inspect','inspect','failed','provider_unavailable');
check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$failed]),'AI service was unavailable'),'provider failure is distinguished from permission denial');
$perception=vp3_tool_receipt_v1('tracky.perception.request','request','completed');
check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$perception]),'request was accepted')&&!str_contains(vp3_tool_diagnosis_v1(['receipt'=>$perception]),'the requested change completed'),'observation request diagnosis does not claim a fresh scene');
$policy=vp3_tool_receipt_v1('radar.gateway.manage','set_policy','completed');
check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$policy]),'policy was saved')&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$policy]),'Enforcement depends'),'saved Gateway policy is distinct from external enforcement');
$policySame=vp3_tool_receipt_v1('radar.gateway.manage','set_policy','read_complete');
check(!$policySame['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$policySame]),'no new change'),'existing matching policy does not claim another write');
foreach(['tracky.context.read','radar.read','analytics.read'] as $cap){$read=vp3_tool_receipt_v1($cap,'list','read_complete');check(!$read['changes_applied']&&vp3_tool_receipt_normalize_v1([...$read,'status'=>'completed'])===null,$cap.' cannot turn a record read into a completed mutation');}
check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$created]),'current access and record state may have changed'),'historical success does not claim current record existence');
check(str_contains(vp3_tool_diagnosis_v1(null),'no verified tool receipt'),'untracked legacy result is explicitly unverified');
foreach([null,[],['contract'=>'vp3.tool-receipt.v1'],[...$created,'capability'=>'shell.execute'],[...$created,'operation'=>'eval'],[...$created,'record_id'=>'12'],[...$created,'status'=>'made_up'],[...$created,'checked_at'=>'yesterday'],[...$created,'reason'=>'SQL password'],[...$created,'reason'=>'execution_failed'],[...$created,'capability'=>'calendar.read','operation'=>'list']] as $bad)check(vp3_tool_receipt_normalize_v1($bad)===null,'malformed or unauthorized receipt is rejected');
$clean=vp3_tool_receipt_normalize_v1([...$empty,'changes_applied'=>true,'token'=>'secret']);check(!$clean['changes_applied']&&!array_key_exists('token',$clean),'normalization derives mutation state and strips arbitrary metadata');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER,user_id INTEGER,user_agent_id INTEGER);CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,role TEXT,context_json TEXT)');
$pdo->exec('INSERT INTO chat_conversations VALUES(1,1,NULL),(2,2,NULL),(3,1,7)');
$insert=$pdo->prepare('INSERT INTO chat_messages VALUES(?,?,?,?)');$insert->execute([1,1,'assistant',json_encode(['tool_receipt'=>$created])]);$insert->execute([2,2,'assistant',json_encode(['tool_receipt'=>$failed])]);$insert->execute([3,3,'assistant',json_encode(['tool_receipt'=>$empty])]);
$last=vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1]);check($last['receipt']===$created,'same-owner system conversation recalls exact executor receipt');
check(vp3_tool_last_receipt_v1($pdo,['id'=>2],['conversation_id'=>1])===null,'foreign account cannot diagnose owner execution');
check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>3])===null,'system agent cannot borrow named-agent receipts');
check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>3,'user_agent_id'=>7])['receipt']===$empty,'exact named-agent scope can diagnose its own turn');
$insert->execute([4,1,'assistant','{"tool_receipt":{"contract":"forged"}}']);check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1])===null,'invalid latest receipt does not revive an older success');
$insert->execute([5,1,'assistant',json_encode(['tool_receipt'=>$created])]);$insert->execute([6,1,'user','{}']);check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1])!==null,'current diagnostic question may follow the recorded response');
$insert->execute([7,1,'user','{}']);check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1])===null,'an unanswered failed turn prevents attribution of older success');

// A consumed Feed confirmation may only prove a queued request or local save.
// That distinction must survive the next-turn diagnosis and persisted receipt.
require_once dirname(__DIR__).'/includes/agent-vrss-outcomes-v1.php';
$vrssBase=vp3_tool_receipt_v1('system_apps.manage','confirm','completed');
$vrssSummaries=[
    'queued'=>['schema'=>VP3_VRSS_OUTCOME_SCHEMA_V1,'action'=>'vrss.refresh_all','phase'=>'refresh_queued','final_verified'=>false,'queued_count'=>2],
    'pending'=>['schema'=>VP3_VRSS_OUTCOME_SCHEMA_V1,'action'=>'vrss.social.like','phase'=>'reaction_pending','final_verified'=>false,'enabled'=>true],
    'local'=>['schema'=>VP3_VRSS_OUTCOME_SCHEMA_V1,'action'=>'vrss.social.unfollow','phase'=>'local_relationship_saved','final_verified'=>false],
    'delivered'=>['schema'=>VP3_VRSS_OUTCOME_SCHEMA_V1,'action'=>'vrss.social.like','phase'=>'reaction_delivered','final_verified'=>true,'enabled'=>false],
];
$vrssWords=['queued'=>'queued 2','pending'=>'waiting for delivery','local'=>'saved locally','delivered'=>'reported delivery'];
foreach($vrssSummaries as $phase=>$summary){
    $r=vp3_tool_receipt_normalize_v1([...$vrssBase,'vrss_outcome'=>$summary,'token'=>'SECRET raw credential']);
    check($r!==null&&$r['vrss_outcome']===$summary&&!str_contains(json_encode($r),'SECRET'),$phase.' phase evidence survives normalization without unrelated payloads');
    $diagnosis=vp3_tool_diagnosis_v1(['receipt'=>$r]);
    check(str_contains($diagnosis,$vrssWords[$phase])&&!str_contains($diagnosis,'the requested change completed')&&str_contains($diagnosis,'Recorded at ')&&str_contains($diagnosis,'current state may have changed'),$phase.' diagnosis uses the evidenced Feed phase and historical timestamp');
    $replay=vp3_tool_receipt_normalize_v1([...$r,'status'=>'read_complete']);$diagnosis=vp3_tool_diagnosis_v1(['receipt'=>$replay]);
    check(!$replay['changes_applied']&&str_contains($diagnosis,$vrssWords[$phase])&&str_contains($diagnosis,'not executed again')&&!str_contains($diagnosis,'the requested change completed'),$phase.' replay remains a saved phase rather than a second action or generic completion');
}
$queued=$vrssSummaries['queued'];
foreach([
    'null'=>null,'empty'=>[],
    'legacy schema'=>array_replace($queued,['schema'=>'vp3.vrss.action-outcome.v0']),
    'invented action'=>array_replace($queued,['action'=>'vrss.delete']),
    'wrong action phase'=>array_replace($queued,['action'=>'vrss.social.like']),
    'queued marked final'=>array_replace($queued,['final_verified'=>true]),
    'string count'=>array_replace($queued,['queued_count'=>'2']),
    'raw field'=>[...$queued,'url'=>'https://SECRET.example/post'],
] as $label=>$summary){
    $raw=[...$vrssBase,'vrss_outcome'=>$summary];
    check(vp3_tool_receipt_normalize_v1($raw)===null,'invalid Feed summary '.$label.' is rejected instead of stripped into generic success');
    $diagnosis=vp3_tool_diagnosis_v1(['receipt'=>$raw]);
    check(str_contains($diagnosis,'could not be verified')&&!str_contains($diagnosis,'the requested change completed')&&!str_contains($diagnosis,'SECRET'),'invalid Feed summary '.$label.' cannot regain completion through direct diagnosis');
}
foreach([
    'other capability'=>['capability'=>'reminders.manage','operation'=>'create'],
    'preparation'=>['operation'=>'prepare','status'=>'read_complete'],
    'navigation'=>['operation'=>'navigate','status'=>'read_complete'],
    'failed'=>['status'=>'failed','reason'=>'outcome_unverified'],
    'blocked'=>['status'=>'blocked','reason'=>'permission_denied'],
    'no matches'=>['status'=>'no_matches'],
    'confirmation required'=>['status'=>'clarification_required','reason'=>'confirmation_required'],
] as $label=>$change)check(vp3_tool_receipt_normalize_v1([...$vrssBase,...$change,'vrss_outcome'=>$queued])===null,'Feed phase evidence cannot attach to '.$label.' receipt');

$storedId=20;
$rememberVrss=static function(array $context)use($pdo,$insert,&$storedId): ?array{
    $insert->execute([$storedId++,1,'assistant',json_encode($context)]);
    return vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1]);
};
$safeVrss=static fn(array $summary):array=>['ok'=>true,'verified'=>true,'reconcile_pending'=>false,'rolled_back'=>false,'vrss_outcome'=>$summary];
$vrssPlan=['action_type'=>'vrss.invoke','app_key'=>'vp3.feed','completed'=>true,'result'=>$safeVrss($queued)];
$queuedReceipt=vp3_tool_receipt_normalize_v1([...$vrssBase,'vrss_outcome'=>$queued]);
$last=$rememberVrss(['tool_receipt'=>$queuedReceipt,'system_app_plan'=>$vrssPlan]);
check($last['receipt']===$queuedReceipt&&str_contains(vp3_tool_diagnosis_v1($last),'queued 2'),'matching persisted Feed receipt and plan preserve the queue diagnosis');
$last=$rememberVrss(['tool_receipt'=>$vrssBase,'system_app_plan'=>$vrssPlan]);
check(($last['receipt']['vrss_outcome']??null)===$queued&&str_contains(vp3_tool_diagnosis_v1($last),'queued 2'),'a valid versioned stored plan supplies phase evidence missing from an older receipt');
foreach([
    'old generic success'=>['verified'=>true,'token'=>'SECRET old raw payload'],
    'missing versioned evidence'=>[],
    'invalid saved flags'=>array_replace($safeVrss($queued),['verified'=>'true']),
    'contradictory action'=>$safeVrss($vrssSummaries['pending']),
    'contradictory queue count'=>$safeVrss(array_replace($queued,['queued_count'=>3])),
    'malformed saved summary'=>$safeVrss([...$queued,'error'=>'SECRET provider diagnostic']),
] as $label=>$result){
    $last=$rememberVrss(['tool_receipt'=>$queuedReceipt,'system_app_plan'=>array_replace($vrssPlan,['result'=>$result])]);$r=$last['receipt']??[];
    check(($r['status']??null)==='failed'&&($r['reason']??null)==='outcome_unverified'&&($r['changes_applied']??null)===false&&!isset($r['vrss_outcome']),$label.' cannot establish a successful persisted Feed outcome');
    $diagnosis=vp3_tool_diagnosis_v1($last);
    check(!str_contains($diagnosis,'the requested change completed')&&!str_contains($diagnosis,'SECRET')&&str_contains($diagnosis,'did not verify the final outcome'),$label.' diagnosis remains unverified without exposing stored content');
}
$last=$rememberVrss(['tool_receipt'=>[...$vrssBase,'vrss_outcome'=>null],'system_app_plan'=>$vrssPlan]);
check($last===null,'a malformed supplied Feed receipt is not silently repaired from a valid plan');
$last=$rememberVrss(['tool_receipt'=>[...$vrssBase,'status'=>'failed','reason'=>'execution_failed'],'system_app_plan'=>$vrssPlan]);
check(($last['receipt']['status']??null)==='failed'&&($last['receipt']['reason']??null)==='outcome_unverified','failed persisted confirmation cannot borrow successful phase evidence from a contradictory plan');
$last=$rememberVrss(['tool_receipt'=>[...$queuedReceipt,'token'=>'SECRET transport token'],'system_app_plan'=>[...$vrssPlan,'result'=>[...$vrssPlan['result'],'body'=>'SECRET source body']]]);
check(($last['receipt']['vrss_outcome']??null)===$queued&&!str_contains(json_encode($last),'SECRET'),'valid saved Feed evidence discards unrelated plan and receipt payloads');
check(vp3_tool_last_receipt_v1($pdo,['id'=>2],['conversation_id'=>1])===null&&vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1,'user_agent_id'=>7])===null,'phase-aware receipt recall retains exact owner and Agent boundaries');
// Profile return receipts retain the exact server outcome rather than generic completion.
require_once dirname(__DIR__).'/includes/agent-profile-webmcp-outcomes-v1.php';
$profileBase=vp3_tool_receipt_v1('profile.webmcp.return.read','read','read_complete');
check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$profileBase]),'no verified outcome evidence'),'a direct Profile read receipt without outcome proof cannot claim success');
$insert->execute([$storedId++,1,'assistant',json_encode(['tool_receipt'=>$profileBase,'profile_webmcp_return'=>['phase'=>'completed','status_text'=>'The Profile action completed']])]);
$missingProfile=vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1]);
check($missingProfile['receipt']['status']==='failed'&&$missingProfile['receipt']['reason']==='outcome_unverified','stored Profile success without proof is downgraded even if old browser text claims completion');
$profileTime=gmdate('Y-m-d\TH:i:s\Z',time()-10);
$profileOutcomes=[
    'prepared'=>['contract'=>'vp3.webmcp.outcome.v1','verification'=>'verified','operation'=>'booking.create','state'=>'prepared','result'=>'awaiting_confirmation','committed_at'=>null,'replayed'=>false],
    'executing'=>['contract'=>'vp3.webmcp.outcome.v1','verification'=>'verified','operation'=>'booking.create','state'=>'executing','result'=>'execution_pending','committed_at'=>null,'replayed'=>false],
];
foreach(vp3_profile_webmcp_outcome_operations_v1() as $op=>$spec)$profileOutcomes[$op]=['contract'=>'vp3.webmcp.outcome.v1','verification'=>'verified','operation'=>$op,'state'=>'recorded','result'=>$spec[3],'committed_at'=>$profileTime,'replayed'=>false];
foreach($profileOutcomes as $label=>$outcome){
    $receipt=vp3_tool_receipt_normalize_v1([...$profileBase,'profile_webmcp_outcome'=>$outcome,'token'=>'SECRET']);
    check($receipt!==null&&$receipt['profile_webmcp_outcome']===$outcome&&!$receipt['changes_applied']&&!str_contains(json_encode($receipt),'SECRET'),$label.' Profile result remains a bounded verified read');
    $text=vp3_tool_diagnosis_v1(['receipt'=>$receipt]);
    check(str_contains($text,vp3_profile_webmcp_outcome_text_v1($outcome))&&str_contains($text,'current state may have changed')&&!str_contains($text,'the requested change completed'),$label.' Profile diagnosis preserves the actual business result and time');
    $insert->execute([$storedId++,1,'assistant',json_encode(['tool_receipt'=>$receipt])]);
    check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1])['receipt']===$receipt,$label.' Profile result survives saved Chat history');
}
$unverified=vp3_profile_webmcp_outcome_unverified_v1();
$unverifiedReceipt=vp3_tool_receipt_normalize_v1([...$profileBase,'status'=>'failed','reason'=>'outcome_unverified','profile_webmcp_outcome'=>$unverified]);
check($unverifiedReceipt!==null&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$unverifiedReceipt]),'could not be verified'),'unverified Profile return never claims an action failed without effects');
$recorded=$profileOutcomes['commerce.checkout'];
foreach([
    'extra token'=>[...$recorded,'token'=>'SECRET'],
    'arbitrary operation'=>[...$recorded,'operation'=>'shell.execute'],
    'wrong result'=>[...$recorded,'result'=>'payment_complete'],
    'future receipt'=>[...$recorded,'committed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+100)],
    'missing receipt'=>[...$recorded,'committed_at'=>null],
    'string replay'=>[...$recorded,'replayed'=>'true'],
    'unverified success'=>[...$recorded,'verification'=>'unverified'],
    'prepared with time'=>[...$profileOutcomes['prepared'],'committed_at'=>$profileTime],
    'empty'=>[],
] as $label=>$bad){
    $receipt=[...$profileBase,'profile_webmcp_outcome'=>$bad];
    check(vp3_tool_receipt_normalize_v1($receipt)===null,$label.' Profile evidence rejects the whole receipt');
    check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$receipt]),'could not be verified')&&!str_contains(vp3_tool_diagnosis_v1(['receipt'=>$receipt]),'SECRET'),$label.' Profile diagnosis cannot restore generic success or raw payload');
}
foreach([
    'other read'=>['capability'=>'analytics.read','operation'=>'list'],
    'handoff'=>['capability'=>'profile.webmcp.handoff','operation'=>'prepare'],
    'completed'=>['status'=>'completed'],
    'empty'=>['status'=>'no_matches'],
    'blocked'=>['status'=>'blocked','reason'=>'permission_denied'],
    'failed verified'=>['status'=>'failed','reason'=>'outcome_unverified'],
] as $label=>$change)check(vp3_tool_receipt_normalize_v1([...$profileBase,...$change,'profile_webmcp_outcome'=>$recorded])===null,'Profile evidence cannot attach to '.$label.' receipt');
check(vp3_tool_receipt_normalize_v1([...$profileBase,'profile_webmcp_outcome'=>$unverified])===null,'unverified Profile evidence cannot become a successful read');
$handoff=vp3_tool_receipt_v1('profile.webmcp.handoff','prepare','clarification_required','confirmation_required');
check(!$handoff['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$handoff]),'prepared for review'),'Profile handoff is pending existing surface confirmation');
$release=vp3_tool_receipt_v1('music.releases.create','create','completed','',101,1);
check($release['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$release]),'internal release plan'),'created release receipt describes internal planning only');
$replayedRelease=vp3_tool_receipt_normalize_v1([...$release,'status'=>'read_complete']);
check(!$replayedRelease['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$replayedRelease]),'no second plan'),'same-turn release replay cannot claim another write');
foreach([['music.releases.read','list'],['profile.webmcp.read','inspect'],['profile.webmcp.handoff','prepare'],['profile.webmcp.return.read','read']] as [$cap,$op])check(vp3_tool_receipt_normalize_v1([...$profileBase,'capability'=>$cap,'operation'=>$op,'status'=>'completed'])===null,$cap.' cannot claim a completed mutation');
echo "TOOL_RECEIPTS=PASS ($checks cases)\n";
