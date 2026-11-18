<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/profile-workspace-calls-v1.php';
require_once __DIR__.'/fixtures/profile-call-outcomes-data-v1.php';
require_once dirname(__DIR__).'/includes/profile-specialist-workspace-v1.php';

$checks=0;
function pco_check(bool $ok,string $label): void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function pco_reject(callable $read,int $code,string $label): void
{try{$read();}catch(RuntimeException|InvalidArgumentException $error){pco_check($error->getCode()===$code,$label.' ['.$error->getCode().']');return;}throw new RuntimeException('FAIL accepted '.$label);}
function pco_reset(array $options=[]): array
{
    db()->beforeSql=db()->afterSql=null;pwc_reset();pco_schema_v1(db());
    db()->exec('DELETE FROM artist_transcript_master_analysis_v237');db()->exec('DELETE FROM artist_transcript_page_analysis_v237');
    return pco_seed_v1(db(),$options+['agent_id'=>8]);
}
function pco_guard(callable $read): mixed
{
    return pa4b_readonly(function()use($read){
        db()->beforeSql=static function(string $sql):void{
            if(!preg_match('/\A\s*(?:SELECT|SHOW)\b|\A\s*PRAGMA\s+(?:table_info|index_list|index_info|database_list)\b/i',$sql))
                throw new LogicException('Attempted non-read SQL in saved outcome: '.$sql);
        };
        try{return $read();}finally{db()->beforeSql=null;}
    });
}
function pco_read(array $seed,string $section='all',string $cursor='',int $agent=8,int $chat=12): array
{return pco_guard(fn()=>vp3_profile_workspace_call_outcome_v1(db(),pfs_scope($agent,$chat),$seed['call_id'],$seed['public_conversation_id'],$section,$cursor));}
function pco_list(string $filter='current',string $cursor='',array $selection=[]): array
{return pco_guard(fn()=>vp3_profile_workspace_calls_v1(db(),pfs_scope(8,12),$selection,$filter,$cursor));}
function pco_snapshot(): array
{
    $out=pwc_snapshot();
    foreach(['artist_transcript_master_analysis_v237','artist_transcript_page_analysis_v237','agent_memory_items','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts'] as $table)
        $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    $out['schema']=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?db()->query('SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC)
        :db()->query('SELECT table_name,column_name,column_type FROM information_schema.columns WHERE table_schema=DATABASE() ORDER BY table_name,ordinal_position')->fetchAll(PDO::FETCH_ASSOC);
    $out['providers']=$GLOBALS['pa3cProviderCalls'];return $out;
}
function pco_copy(array $row,int $number): int
{
    unset($row['id']);$public=substr(hash('sha256','history-copy-'.$number),0,32);$row['public_id']=$public;$row['room_name']='vp3-profile-'.$public;
    $row['owner_identity']='owner-'.$public;$row['visitor_identity']='visitor-'.$public;$row['binding_hash']='';$row['assisted']=0;
    db()->prepare('INSERT INTO profile_calls_pa3c('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
    $id=(int)db()->lastInsertId();$saved=vp3_profile_call_row_pa3c(db(),$id);db()->prepare('UPDATE profile_calls_pa3c SET binding_hash=? WHERE id=?')->execute([vp3_profile_call_binding_hash_pa3c($saved),$id]);return $id;
}

$seed=pco_reset();$saved=$seed['assisted'];$before=pco_snapshot();$out=pco_read($saved);
pco_check($out['contract']===VP3_PROFILE_CALL_OUTCOME_V1&&$out['call_id']===$saved['call_id']&&$out['public_conversation_id']===$saved['public_conversation_id'],'outcome preserves the exact numeric call and public thread independently from private scope');
pco_check($out['call']['detail_kind']==='outcome'&&$out['call']['state']==='ended','terminal detail comes from the saved canonical call');
pco_check(array_keys($out['sections'])===['transcript','analysis','research','followups'],'initial outcome has the four explicit saved sections');
foreach(['transcript'=>50,'analysis'=>3,'research'=>6,'followups'=>20] as $name=>$count)
    pco_check($out['sections'][$name]['availability']==='ready'&&count($out['sections'][$name]['items'])===$count,'bounded verified '.$name.' first page');
pco_check($out['sections']['analysis']['generated']&&$out['sections']['analysis']['fresh'],'saved analysis is projected only after current transcript proof');
pco_check($out===pco_read($saved)&&$before===pco_snapshot(),'repeated outcome reads are stable and write no schema, call, recording, job, task or notification');
$json=json_encode($out,JSON_THROW_ON_ERROR);
foreach(['provider_snapshot','participant_token','room_name','credential_hash','authority_json','binding_hash','delivery_id','duration_ms'] as $secret)
    pco_check(!str_contains($json,'"'.$secret.'"'),'outcome excludes '.$secret);
foreach(['transcript'=>5,'research'=>1,'followups'=>3] as $name=>$remaining){
    $first=$out['sections'][$name];$next=pco_read($saved,$name,$first['next_cursor']);$page=$next['sections'][$name];
    pco_check(array_keys($next['sections'])===[$name]&&count($page['items'])===$remaining&&!$page['has_more']&&$page['next_cursor']===null,'exact '.$name.' continuation is bounded and exhausted');
    pco_check($page['source_revision']===$first['source_revision']&&$next['context_revision']===$out['context_revision'],'stable '.$name.' source and context proof across pages');
    pco_check(array_intersect(array_column($first['items'],'id'),array_column($page['items'],'id'))===[],'no replay across '.$name.' pages');
}
pco_check(in_array('completed',array_column($out['sections']['followups']['items'],'task_status'),true)&&in_array('cancelled',array_column($out['sections']['followups']['items'],'task_status'),true),'reviewed completed and cancelled tasks remain visible with current status');
pco_check(pco_read($seed['ordinary'])['sections']['transcript']['availability']==='not_applicable','an ordinary direct call does not imply recording or assistance');
pco_reject(fn()=>pco_read($seed['ending']),409,'cleanup pending is never classified as a completed outcome');
$current=pco_list();$history=pco_list('history','',['call_id'=>$seed['ending']['call_id']]);
pco_check(array_column($current['calls'],'call_id')===[$seed['ending']['call_id']]&&$current['calls'][0]['detail_kind']==='current','Current includes ending without claiming live media');
pco_check(count($history['calls'])===2&&$history['selected']['detail_kind']==='current','exact selected call is independent of history filter');
pco_check(pco_list('current','',['call_id'=>$saved['call_id']])['selected']['call_id']===$saved['call_id'],'an undelivered historical call can be opened by exact ID');
pco_reject(fn()=>pco_list('unknown'),422,'unknown history filter is rejected');
pco_reject(fn()=>pco_read($saved,'join'),422,'saved outcomes expose no media operation');
pco_reject(fn()=>pco_read($saved,'all',$out['sections']['transcript']['next_cursor']),422,'an all-section read cannot borrow a section cursor');
pco_reject(fn()=>pco_read($saved,'research',$out['sections']['transcript']['next_cursor']),422,'a section cursor cannot cross source kinds');
pco_reject(fn()=>pco_read($saved,'transcript',$out['sections']['transcript']['next_cursor'],7,11),422,'a cursor cannot cross authorized private Agent and Chat scope');
pco_reject(fn()=>pco_read($saved,'all','',7,12),403,'another Agent cannot borrow the selected private Chat');
pco_reject(fn()=>vp3_profile_workspace_call_outcome_v1(db(),pfs_scope(8,12),$saved['call_id'],999999),404,'an exact call cannot be substituted for another public conversation');
pco_reject(fn()=>vp3_profile_workspace_call_outcome_v1(db(),pfs_scope(8,12),999999),404,'a missing exact call never falls back to history');

// Equal timestamps and independent new pages must not produce cursor replay.
$seed=pco_reset();$saved=$seed['assisted'];$row=vp3_profile_call_row_pa3c(db(),$seed['ordinary']['call_id']);
for($i=1;$i<=23;$i++)pco_copy($row,$i);
$page=pco_list('history');$next=pco_list('history',$page['next_cursor']);
pco_check(count($page['calls'])===20&&count($next['calls'])===5&&!$next['has_more'],'history advances past twenty rows with equal timestamps');
pco_check(!array_intersect(array_column($page['calls'],'call_id'),array_column($next['calls'],'call_id')),'history keyset pages have no duplicate calls');
pco_reject(fn()=>pco_list('current',$page['next_cursor']),422,'history cursor cannot cross filters');
$id=$page['calls'][19]['call_id'];db()->prepare("UPDATE profile_calls_pa3c SET binding_hash='broken' WHERE id=?")->execute([$id]);
$page=pco_list('history');pco_check(count($page['calls'])===19&&count(pco_list('history',$page['next_cursor'])['calls'])===5,'skipped unverifiable history row still advances from last scanned key');

foreach(['transcript','research','followups'] as $name){
    $seed=pco_reset();$saved=$seed['assisted'];$first=pco_read($saved) ['sections'][$name];
    if($name==='transcript')db()->prepare("UPDATE artist_transcript_segments_v172 SET transcript_text='Changed saved text with the same timestamp' WHERE id=?")->execute([$first['items'][0]['id']]);
    elseif($name==='research')db()->prepare('UPDATE profile_call_research_pa3c SET result_json=? WHERE call_id=? AND request_id=?')->execute([json_encode(['summary'=>'Changed result','sources'=>[]]),$saved['public_id'],$first['items'][0]['id']]);
    else{$q=db()->prepare('SELECT metadata_json FROM agent_memory_items WHERE id=?');$q->execute([$first['items'][0]['id']]);$meta=json_decode($q->fetchColumn(),true);$q->closeCursor();$meta['task_status']='completed';db()->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=?')->execute([json_encode($meta),$first['items'][0]['id']]);}
    pco_reject(fn()=>pco_read($saved,$name,$first['next_cursor']),409,'content change invalidates '.$name.' continuation without timestamp dependence');
}

foreach(['discarded','wrong_call','malformed','wrong_private_agent','wrong_owner','recording_deny','recording_entitlement'] as $mutation){
    $seed=pco_reset();$saved=$seed['assisted'];$session=$saved['session_id'];
    match($mutation){
        'discarded'=>db()->prepare("UPDATE artist_transcript_sessions_v172 SET discarded_at='2026-01-01' WHERE id=?")->execute([$session]),
        'wrong_call'=>db()->prepare('UPDATE artist_transcript_sessions_v172 SET metadata_json=? WHERE id=?')->execute([json_encode(['source'=>'profile_call_pa3c','profile_call_id'=>str_repeat('a',32)]),$session]),
        'malformed'=>db()->prepare("UPDATE artist_transcript_sessions_v172 SET metadata_json='{' WHERE id=?")->execute([$session]),
        'wrong_private_agent'=>db()->prepare('UPDATE artist_transcript_sessions_v172 SET conversation_id=11 WHERE id=?')->execute([$session]),
        'wrong_owner'=>db()->prepare('UPDATE artist_transcript_sessions_v172 SET created_by_user_id=4 WHERE id=?')->execute([$session]),
        'recording_deny'=>db()->prepare("INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode) VALUES(8,'recording',?,'deny')")->execute([(string)$session]),
        'recording_entitlement'=>db()->exec("DELETE FROM role_permissions WHERE permission_key='artist_listening.access'"),
    };
    $view=pco_read($saved);$expected=in_array($mutation,['wrong_private_agent','recording_deny','recording_entitlement'],true)?'forbidden':'unavailable';
    pco_check($view['call']['call_id']===$saved['call_id']&&array_unique(array_column($view['sections'],'availability'))===[$expected],'safe call survives restricted saved evidence: '.$mutation);
    pco_check(!str_contains(json_encode($view['sections']),'Saved call segment')&&!str_contains(json_encode($view['sections']),'session_id'),'restricted sections disclose no recording text or identifier: '.$mutation);
}

foreach(['artist_transcript_segments_v172'=>'transcript','artist_transcript_master_analysis_v237'=>'analysis','profile_call_research_pa3c'=>'research','agent_memory_items'=>'followups'] as $table=>$section){
    $seed=pco_reset();$saved=$seed['assisted'];db()->exec('ALTER TABLE '.$table.' RENAME TO pco_missing_store');
    try{$view=pco_read($saved);pco_check($view['sections'][$section]['availability']==='unavailable','missing '.$section.' store is unavailable without query-time DDL');
        pco_check($view['call']['call_id']===$saved['call_id']&&($section==='research'||$view['sections']['research']['availability']==='ready'),'unrelated verified evidence survives missing '.$section.' storage');}
    finally{db()->exec('ALTER TABLE pco_missing_store RENAME TO '.$table);}
}

$seed=pco_reset();$saved=$seed['assisted'];db()->prepare('DELETE FROM profile_call_assistance_pa3c WHERE call_id=?')->execute([$saved['public_id']]);
$view=pco_read($saved);pco_check($view['sections']['transcript']['availability']==='empty'&&$view['sections']['research']['availability']==='ready','no saved recording is distinct from authorized saved public research');
$seed=pco_reset();$saved=$seed['assisted'];db()->prepare('DELETE FROM artist_transcript_master_analysis_v237 WHERE session_id=?')->execute([$saved['session_id']]);
$view=pco_read($saved,'analysis')['sections']['analysis'];pco_check($view['availability']==='empty'&&!$view['generated'],'opening absent analysis does not generate it');
pco_analysis_v1(db(),$saved['session_id']);db()->prepare("UPDATE artist_transcript_segments_v172 SET transcript_text='Current replacement text' WHERE session_id=?")->execute([$saved['session_id']]);
$view=pco_read($saved,'analysis')['sections']['analysis'];pco_check($view['generated']&&!$view['fresh']&&$view['reason']==='transcript_changed'&&$view['items']===[],'stale saved analysis never becomes a current conclusion');
$seed=pco_reset();$saved=$seed['assisted'];db()->prepare("UPDATE profile_call_research_pa3c SET status='running',result_json=NULL WHERE call_id=?")->execute([$saved['public_id']]);
$before=pco_snapshot();$view=pco_read($saved,'research')['sections']['research'];pco_check($view['items'][0]['stored_status']==='running'&&$view['items'][0]['display_status']==='incomplete'&&$before===pco_snapshot(),'closed-call running research is shown as incomplete without changing saved status');
db()->prepare("UPDATE profile_call_research_pa3c SET status='complete',result_json='{' WHERE call_id=?")->execute([$saved['public_id']]);
pco_check(pco_read($saved,'research')['sections']['research']['availability']==='unavailable','malformed completed research is unavailable rather than successful empty evidence');

$seed=pco_reset();$saved=$seed['assisted'];$map=artist_listening_transcript_page_map(artist_listening_v172_segments(db(),$saved['session_id']));$hash=$map['source_hash'];
$analysis=['modules'=>['basic'=>['source_hash'=>$hash,'result'=>['summary'=>'Current saved basic analysis.','key_findings'=>[
    ['item_id'=>'accepted-call-finding','text'=>'The owner accepted the public opening-hours finding.','review_state'=>'accepted','evidence_refs'=>[['page'=>1,'label'=>'Page 1']]]]]]]];
$modules=transcription_app_modules_v306($analysis,['source_hash'=>$hash]);$input=transcription_output_reviewed_input_v306($modules,$hash);
$analysis['modules']['summary_output']=['source_hash'=>$hash,'context_hash'=>transcription_output_input_hash_v306($input,$hash),
    'result'=>['overview'=>[['summary'=>'Exact saved reviewed executive summary.']]]];
pco_analysis_v1(db(),$saved['session_id'],$analysis);$view=pco_read($saved,'analysis')['sections']['analysis'];
pco_check($view['fresh']&&$view['items'][0]['text']==='Exact saved reviewed executive summary.'&&!$view['items'][0]['reviewed']&&$view['items'][0]['based_on_reviewed_sources'],'generated summary requires exact accepted-input proof without claiming owner review of the output');
$analysis['modules']['basic']['result']['key_findings'][0]['review_state']='rejected';pco_analysis_v1(db(),$saved['session_id'],$analysis);
$view=pco_read($saved,'analysis')['sections']['analysis'];pco_check($view['items'][0]['text']==='Current saved basic analysis.'&&!$view['items'][0]['reviewed'],'changed review state cannot present an outdated reviewed summary as current');
pco_check(!in_array('The owner accepted the public opening-hours finding.',array_column($view['items'],'text'),true),'rejected basic findings are omitted from the compact outcome');
$analysis['modules']['basic']['result']['key_findings']=[
    ['item_id'=>'accepted-edited','text'=>'Old accepted wording.','review_state'=>'accepted','user_edited'=>true,'edited_text'=>'Exact owner-edited accepted wording.'],
    ['item_id'=>'ordinary-unreviewed','text'=>'Saved unreviewed finding.','review_state'=>'unreviewed'],
    ['item_id'=>'rejected-finding','text'=>'Rejected finding must not appear.','review_state'=>'rejected'],
];
$analysis['modules']['basic']['result']['open_questions']=[['item_id'=>'rejected-question','text'=>'Rejected question must not appear.','review_state'=>'rejected']];
unset($analysis['modules']['summary_output']);pco_analysis_v1(db(),$saved['session_id'],$analysis);
$view=pco_read($saved,'analysis')['sections']['analysis'];$texts=array_column($view['items'],'text');
pco_check(in_array('Exact owner-edited accepted wording.',$texts,true)&&!in_array('Old accepted wording.',$texts,true),'accepted edited finding uses canonical owner-edited text');
$accepted=$view['items'][array_search('Exact owner-edited accepted wording.',$texts,true)];$unreviewed=$view['items'][array_search('Saved unreviewed finding.',$texts,true)];
pco_check($accepted['reviewed']&&!$accepted['based_on_reviewed_sources']&&!$unreviewed['reviewed'],'accepted and unreviewed source findings retain distinct review provenance');
pco_check(!in_array('Rejected finding must not appear.',$texts,true)&&!in_array('Rejected question must not appear.',$texts,true),'rejected findings and open questions are excluded without changing the saved source');
db()->prepare('UPDATE artist_transcript_master_analysis_v237 SET analyzed_page_count=0 WHERE session_id=?')->execute([$saved['session_id']]);
$view=pco_read($saved,'analysis')['sections']['analysis'];pco_check($view['coverage']==='limited'&&$view['reason']==='partial_analysis'&&$view['analyzed_pages']===0,'partial saved analysis states its coverage rather than implying the whole transcript was analyzed');
db()->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?')->execute([str_repeat('x',524289),$saved['session_id']]);
$view=pco_read($saved,'analysis')['sections']['analysis'];pco_check($view['availability']==='unavailable'&&$view['reason']==='source_limit'&&isset($view['url']),'oversized saved analysis is bounded and retains only its authorized source link');

$seed=pco_reset();$saved=$seed['assisted'];$before=pco_read($saved);$first=$before['sections']['research'];
db()->prepare('UPDATE profile_calls_pa3c SET revision=revision+1 WHERE id=?')->execute([$saved['call_id']]);
pco_reject(fn()=>pco_read($saved,'research',$first['next_cursor']),409,'changed call context invalidates a section cursor before appending');
db()->prepare('UPDATE profile_calls_pa3c SET ended_at_ms=NULL WHERE id=?')->execute([$saved['call_id']]);
pco_reject(fn()=>pco_read($saved),409,'a terminal label without a verified closed timestamp is not an outcome');
pco_check(pco_list('history')['coverage']==='limited','history marks an unverifiable terminal row as a coverage gap');

$seed=pco_reset();$saved=$seed['assisted'];$before=pco_read($saved);
putenv('VP3_PROFILE_CALL_AGENT_ENABLED=0');putenv('VP3_PROFILE_CALL_WORKER_SECRET=');
pco_check(pco_read($saved)===$before,'saved evidence does not depend on current capture or research provider readiness');
$payload=['summary'=>'Saved <script>inert</script> research.', 'sources'=>[
    ['title'=>'Unsafe','url'=>'javascript:alert(1)'],['title'=>'Credentials','url'=>'https://user:pass@example.com/'],['title'=>'Public','url'=>'https://example.com/safe']]];
db()->prepare('UPDATE profile_call_research_pa3c SET result_json=? WHERE call_id=?')->execute([json_encode($payload),$saved['public_id']]);
$view=pco_read($saved,'research')['sections']['research'];pco_check(count($view['items'][0]['sources'])===1&&$view['items'][0]['sources'][0]['url']==='https://example.com/safe'&&$view['coverage']==='limited','research links permit credential-free HTTPS evidence only');

$seed=pco_reset(['tasks'=>205]);$saved=$seed['assisted'];$view=pco_read($saved,'followups')['sections']['followups'];
pco_check(count($view['items'])===20&&$view['coverage']==='limited'&&$view['source_revision']===null&&$view['next_cursor']===null&&isset($view['url']),'oversized reviewed followups return a bounded preview and authorized source without a false complete cursor');

// Large proofs are explicitly bounded; no timestamp-only continuation is offered.
$seed=pco_reset(['segments'=>4100]);$saved=$seed['assisted'];$view=pco_read($saved,'transcript')['sections']['transcript'];
pco_check($view['coverage']==='limited'&&count($view['items'])===50&&$view['source_revision']===null&&$view['next_cursor']===null&&$view['has_more']&&isset($view['url']),'oversized recording provides an explicit bounded preview and authorized source link');
pco_check(pco_read($saved,'analysis')['sections']['analysis']['reason']==='transcript_proof','oversized current proof does not certify saved analysis');

foreach(['private_scope','pointer','recording_rule','task_rule'] as $mutation){
    $seed=pco_reset();$saved=$seed['assisted'];$changed=false;
    db()->afterSql=static function(string $sql,PA3PDO $pdo)use($saved,$mutation,&$changed):void{
        if($changed||!str_starts_with($sql,'SELECT id,session_id,client_segment_key,segment_index'))return;
        $changed=true;$pdo->afterSql=null;
        if($mutation==='private_scope')$pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=12');
        elseif($mutation==='pointer')$pdo->prepare('UPDATE profile_call_assistance_pa3c SET transcript_session_id=0 WHERE call_id=?')->execute([$saved['public_id']]);
        else $pdo->prepare("INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode) VALUES(8,?,?,'deny')")
            ->execute([$mutation==='recording_rule'?'recording':'knowledge',$mutation==='recording_rule'?(string)$saved['session_id']:'brain:'.$saved['task_id']]);
    };
    if($mutation==='recording_rule'){$view=vp3_profile_workspace_call_outcome_v1(db(),pfs_scope(8,12),$saved['call_id']);pco_check($view['sections']['transcript']['availability']==='forbidden'&&$view['sections']['transcript']['items']===[],'late recording denial clears already projected private text');}
    elseif($mutation==='task_rule'){$view=vp3_profile_workspace_call_outcome_v1(db(),pfs_scope(8,12),$saved['call_id']);pco_check(!in_array($saved['task_id'],array_column($view['sections']['followups']['items'],'id'),true),'task policy is current after earlier transcript reading');}
    else pco_reject(fn()=>vp3_profile_workspace_call_outcome_v1(db(),pfs_scope(8,12),$saved['call_id']),$mutation==='private_scope'?403:409,'late '.$mutation.' change withholds saved outcome');
    pco_check($changed,'race reached the real transcript source read: '.$mutation);
}

// The common production dispatcher must preserve all exact scope/section fields.
$seed=pco_reset();$saved=$seed['assisted'];pa3_login(1);$owner=pa3_owner();$binding=vp3_catch_up_page_binding_v1($owner);
$input=['action'=>'call_outcome','chat_agent_id'=>8,'chat_conversation_id'=>12,'call_id'=>$saved['call_id'],'public_conversation_id'=>$saved['public_conversation_id'],'section'=>'all'];
$envelope=pco_guard(fn()=>vp3_profile_workspace_request_v1(db(),$owner,'GET',$input,$binding));
pco_check($envelope['data']['call_outcome']['call_id']===$saved['call_id']&&$envelope['scope']['chat_agent_id']===8,'real workspace dispatcher returns scoped saved outcome');
$input['section']='research';$input['cursor']=$envelope['data']['call_outcome']['sections']['research']['next_cursor'];
$envelope=pco_guard(fn()=>vp3_profile_workspace_request_v1(db(),$owner,'GET',$input,$binding));
pco_check(count($envelope['data']['call_outcome']['sections']['research']['items'])===1,'real workspace dispatcher preserves section continuation cursor');

// Actual call cleanup, not a seeded state label, establishes completion.
pwc_reset();pco_schema_v1(db());$live=pwc_live_assisted();$row=vp3_profile_call_row_pa3c(db(),$live['call']);
$projection=vp3_profile_call_projection_pa3c($row,'owner');vp3_profile_call_owner_action_pa3c(db(),pa3c_owner(),pa3c_owner_input($projection,'end'));
$selection=['call_id'=>$live['call'],'public_conversation_id'=>$live['conversation']];
pco_check(vp3_profile_call_row_pa3c(db(),$live['call'])['state']==='ending','real owner end retains pending cleanup');
pco_reject(fn()=>pco_read($selection),409,'outcome read cannot complete real pending cleanup');
$GLOBALS['pa3cProviderMode']='cleanup_error';vp3_profile_call_work_pa3c(db(),$live['call'],'pa3c_provider');
pco_reject(fn()=>pco_read($selection),409,'failed cleanup does not become a completed call');
$GLOBALS['pa3cProviderMode']='ok';$row=vp3_profile_call_row_pa3c(db(),$live['call']);
db()->prepare("UPDATE profile_calls_pa3c SET cleanup_not_before_ms=0,provider_lease_until_ms=0 WHERE id=?")->execute([$live['call']]);
db()->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01',lease_expires_at='2000-01-01' WHERE id=?")->execute([$row['cleanup_run_id']]);
db()->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01' WHERE id=?")->execute([$row['cleanup_action_id']]);
vp3_profile_call_work_pa3c(db(),$live['call'],'pa3c_provider');
$row=vp3_profile_call_row_pa3c(db(),$live['call']);$before=pco_snapshot();$view=pco_read($selection);
pco_check($row['state']==='ended'&&$row['cleanup_proof_json']!==null&&$view['call']['ended_at_ms']>0,'real canonical cleanup produces a durable exact outcome');
pco_check($before===pco_snapshot(),'reopening completed canonical cleanup neither recontacts provider nor writes a receipt');

echo 'PROFILE_CALL_OUTCOMES_V1=PASS ('.$checks.' checks; driver='.db()->getAttribute(PDO::ATTR_DRIVER_NAME)."; skips=0)\n";
