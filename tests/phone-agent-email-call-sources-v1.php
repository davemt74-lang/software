<?php
declare(strict_types=1);
require __DIR__.'/fixtures/phone-agent-email-call-native-v1.php';

$f=pec_fixture_v1();$pdo=$f['pdo'];$context=$f['context'];$now=$f['now'];
$candidates=static fn():array=>vp3_phone_agent_email_call_candidates_v1($pdo,$context,time());
$find=static function(string $id)use($candidates):array{
    foreach($candidates() as $candidate)if($candidate['source_id']===$id)return $candidate;
    throw new RuntimeException('Expected current native email-call candidate.');
};
$refresh=static fn(array $candidate,string $stage='admit'):array=>vp3_phone_agent_email_call_refresh_v1($pdo,$context,$candidate,time(),'pec_http_v1',$stage);
$current=static fn(array $candidate,string $stage='admit'):array=>vp3_phone_agent_email_call_source_current_v1($pdo,$context,$candidate,time(),$stage);
$edit=static function(array $draft,string $body)use($pdo):array{
    $row=agent_connection_email_chat_draft_v1($pdo,1,7,11,$draft['id']);$payload=agent_connection_email_chat_payload_v1($row);
    $payload['body']=$body;$payload['composer']['saved_at']=time();
    $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?')->execute([ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),$draft['id']]);
    return $payload;
};

try {
    $arrival=pec_attention_v1($f,'same_episode');$draft=pec_draft_v1($f,'same_episode');
    $attention=pec_attention_v1($f,'attention_only');
    pec_attention_v1($f,'historical',$now-600,true);pec_attention_v1($f,'before_opt_in',$now-7200);
    $manual=pec_draft_v1($f,'manual',['manual'=>true]);
    $failed=pec_draft_v1($f,'failed',['status'=>'write_failed']);$incomplete=pec_draft_v1($f,'incomplete',['body'=>'']);
    $sending=pec_draft_v1($f,'sending',['status'=>'sending']);$old=pec_draft_v1($f,'old',['created_epoch'=>$now-7200]);
    $sibling=pec_draft_v1($f,'sibling',['agent_id'=>8,'conversation_id'=>13]);
    $userTurns=(int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='user'")->fetchColumn();
    $items=$candidates();$ids=array_column($items,'source_id');
    pec_check_v1(count($items)===2&&in_array($draft['id'],$ids,true)&&in_array($attention,$ids,true),
        'one ready organic reply and one important arrival are selected; failed, incomplete, manual, sending, historical and other-Agent work remain quiet');
    pec_check_v1($GLOBALS['pecHttpCalls']===0&&(int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='user'")->fetchColumn()===$userTurns,
        'candidate discovery uses actual saved sources without provider I/O or invented owner turns');
    $draftCandidate=$find($draft['id']);$attentionCandidate=$find($attention);
    $arrivalMaterial=vp3_phone_agent_email_call_material_v1($pdo,$context,'gmail_attention',$arrival,time());
    pec_check_v1($arrivalMaterial['candidate']['work_key']===$draftCandidate['work_key']&&!in_array($arrival,$ids,true),
        'the arrival and exact organic reply share one owner-wide episode with the current draft as review target');
    $encoded=json_encode($items,JSON_THROW_ON_ERROR);
    foreach(['PRIVATE_','BODY_SENTINEL','owner@example','sender@example','Private fixture subject','LOCAL_FIXTURE_TOKEN','review_token'] as $private)
        pec_check_v1(!str_contains($encoded,$private),'call facts omit '.$private);
    pec_check_v1($attentionCandidate['facts']['deadline']['kind']==='date'
        &&$attentionCandidate['facts']['deadline']['value']===gmdate('Y-m-d',$now+86400),
        'saved date-only urgency is preserved without fabricating a midnight deadline');
    pec_check_v1($current($draftCandidate)['reason']==='freshness_required'
        &&!vp3_phone_agent_email_call_decision_current_v1($pdo,$context,$draftCandidate,time()),
        'a saved actionable card is neither fresh provider evidence nor a Main Agent call decision');
    pec_check_v1($refresh($draftCandidate)['reason']==='eligible'&&$GLOBALS['pecHttpCalls']===2,
        'freshness reads the exact native incoming message and complete strict thread through the controlled provider boundary');
    $reads=$GLOBALS['pecHttpCalls'];
    pec_check_v1($current($draftCandidate)['reason']==='eligible'&&$GLOBALS['pecHttpCalls']===$reads,
        'locked admission uses current source and fresh proof without provider I/O');
    $memo=vp3_phone_agent_email_call_memo_v1($pdo,1,$draftCandidate);
    pec_check_v1($memo['checked_epoch']<=time()&&strlen($memo['evidence_hash'])===64
        &&!str_contains(json_encode($memo),'PRIVATE_')&&!str_contains(json_encode($memo),'example.test'),
        'fresh proof stores only bound identities, hashes and its actual observation time');

    $peer=pec_peer_v1($f);
    $warmRejected=static function(callable $check):bool{
        try{return $check()['reason']!=='eligible';}
        catch(PDOException $error){
            // MariaDB may abort the old snapshot directly; that database conflict must propagate,
            // never be swallowed into an eligible result or followed by writes without its lock.
            if((int)($error->errorInfo[1]??0)!==1020)throw $error;return true;
        }
    };
    foreach(['admit','submit','active','review'] as $phase){
        $pdo->beginTransaction();$pdo->query('SELECT status FROM agent_connection_email_drafts_v1')->fetchAll();
        $peer->prepare("UPDATE agent_connection_email_drafts_v1 SET status='discarded' WHERE id=?")->execute([$draft['id']]);
        pec_check_v1($warmRejected(static fn():array=>$current($draftCandidate,$phase)),
            'a warm transaction rejects a newly discarded draft at the '.$phase.' boundary');
        if($pdo->inTransaction())$pdo->rollBack();
        $peer->prepare("UPDATE agent_connection_email_drafts_v1 SET status='prepared' WHERE id=?")->execute([$draft['id']]);
    }
    $refresh($attentionCandidate);
    $pdo->beginTransaction();$pdo->query('SELECT stage FROM agent_email_matters_v1')->fetchAll();
    $peer->prepare("UPDATE agent_email_matters_v1 m JOIN agent_email_triage_v1 t ON t.matter_id=m.matter_id SET m.stage='resolved' WHERE t.triage_id=?")
        ->execute([$attention]);
    pec_check_v1($warmRejected(static fn():array=>$current($attentionCandidate,'active')),
        'a warm transaction cannot disclose an important arrival after its exact native matter was resolved');
    if($pdo->inTransaction())$pdo->rollBack();$peer->prepare("UPDATE agent_email_matters_v1 m JOIN agent_email_triage_v1 t ON t.matter_id=m.matter_id SET m.stage='needs_review' WHERE t.triage_id=?")
        ->execute([$attention]);

    $scope=vp3_phone_agent_workspace_scope_v1($pdo,$f['owner'],$f['second_token'],$f['second_device_id'],false,time());
    $mutate=static function(string $operation,array $candidate,array $extra=[])use($pdo,$scope):void{
        vp3_phone_transaction_v1($pdo,static function()use($pdo,$scope,$operation,$candidate,$extra):array{
            $item=vp3_phone_agent_workspace_item_v1($pdo,$scope,$candidate['item_ref'])['item'];
            return vp3_phone_agent_workspace_mutate_v1($pdo,$scope,['operation'=>$operation,'item_ref'=>$candidate['item_ref'],
                'expected_revision'=>$item['revision']]+$extra);
        });
    };
    $mutate('seen',$draftCandidate);
    pec_check_v1($current($draftCandidate)['reason']==='eligible','seen and delivery are not owner acknowledgment');
    $mutate('read',$draftCandidate);
    pec_check_v1($current($draftCandidate)['reason']==='owner_read'&&$current($draftCandidate,'submit')['reason']==='owner_read',
        'reading the exact draft on another authorized owner phone suppresses queued and immediate dial admission');
    pec_check_v1($current($draftCandidate,'active')['reason']==='eligible'&&$current($draftCandidate,'review')['reason']==='eligible'
        &&$refresh($draftCandidate,'active')['reason']==='eligible',
        'opening and reading during an active call preserves exact-source review and active freshness checks');
    $pdo->prepare('UPDATE vp3_phone_devices_v1 SET token_hash=? WHERE id=?')->execute([hash('sha256','replacement-credential'),$f['second_device_id']]);
    pec_check_v1($current($draftCandidate)['reason']==='eligible','a receipt from a revoked phone credential cannot assert current owner handling');
    $pdo->prepare('UPDATE vp3_phone_devices_v1 SET token_hash=? WHERE id=?')->execute([hash('sha256',$f['second_token']),$f['second_device_id']]);
    $pdo->exec('DELETE FROM vp3_phone_agent_receipts_v1');$mutate('snooze',$draftCandidate,['snooze_until'=>gmdate('Y-m-d\TH:i:s\Z',time()+300)]);
    pec_check_v1($current($draftCandidate)['reason']==='owner_snoozed'&&$current($draftCandidate,'active')['reason']==='eligible',
        'owner snooze suppresses a new interruption while an already-open exact review remains valid');
    $pdo->exec('DELETE FROM vp3_phone_agent_receipts_v1');$mutate('ack',$draftCandidate);
    pec_check_v1($current($draftCandidate)['reason']==='owner_acknowledged','explicit acknowledgment is distinct from read and suppresses a new call');
    $pdo->exec('DELETE FROM vp3_phone_agent_receipts_v1');

    $pdo->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET selected_agent_id=8,conversation_id=13,revision=2 WHERE device_id=?')->execute([$f['second_device_id']]);
    $otherSource=vp3_phone_agent_workspace_source_v1($pdo,$f['owner'],8,'gmail_attention',$arrival,time());
    $stored=vp3_phone_agent_workspace_ingest_v1($pdo,1,8,[$otherSource],time());
    $otherScope=vp3_phone_agent_workspace_scope_v1($pdo,$f['owner'],$f['second_token'],$f['second_device_id'],false,time());
    vp3_phone_transaction_v1($pdo,static function()use($pdo,$otherScope,$stored):array{
        $item=vp3_phone_agent_workspace_item_v1($pdo,$otherScope,$stored[0]['item_ref'])['item'];
        return vp3_phone_agent_workspace_mutate_v1($pdo,$otherScope,['operation'=>'read','item_ref'=>$item['item_ref'],'expected_revision'=>$item['revision']]);
    });
    pec_check_v1($otherSource['source_revision']!==$arrivalMaterial['candidate']['source_revision']
        &&$current($draftCandidate)['reason']==='owner_read',
        'a correlated mailbox item read under another selected Agent suppresses duplicate owner calls without sharing projection hashes');
    pec_check_v1(vp3_phone_transaction_v1($pdo,static fn():array=>$current($draftCandidate))['reason']==='owner_read',
        'owner-wide cross-Agent handling also suppresses under the locked admission boundary');
    $pdo->exec('DELETE FROM vp3_phone_agent_receipts_v1');

    $thread='thread_same_episode';$base=$GLOBALS['pecThreads'][$thread];
    foreach([
        pec_message_v1('new_inbound',$thread,$now-1),
        pec_message_v1('external_send',$thread,$now-1,'owner@example.test',['SENT']),
        pec_message_v1('same_time_later',$thread,$now-600),
        pec_message_v1('future_observation',$thread,time()+300),
    ] as $later){
        $GLOBALS['pecThreads'][$thread]=[...$base,$later];$result=$refresh($draftCandidate);
        pec_check_v1($result['reason']==='source_changed'&&$current($draftCandidate)['reason']==='freshness_required',
            'a newer incoming or own sent message withdraws the earlier exact-thread proof');
        $GLOBALS['pecThreads'][$thread]=$base;pec_check_v1($refresh($draftCandidate)['reason']==='eligible','restored exact source can be checked without assuming a reply outcome');
    }
    $badThread=static function(...$args):array{$response=pec_http_v1(...$args);if(str_contains($args[1],'/threads/'))$response['json']['nextPageToken']='not-complete';return $response;};
    pec_check_v1(vp3_phone_agent_email_call_refresh_v1($pdo,$context,$draftCandidate,time(),$badThread)['reason']==='source_unavailable'
        &&$current($draftCandidate)['reason']==='freshness_required','incomplete provider evidence fails closed and clears an older successful observation');
    $refresh($draftCandidate);
    $revoke=static function(...$args)use($pdo):array{$response=pec_http_v1(...$args);$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');return $response;};
    $reads=$GLOBALS['pecHttpCalls'];
    pec_check_v1(vp3_phone_agent_email_call_refresh_v1($pdo,$context,$draftCandidate,time(),$revoke)['reason']==='source_changed'
        &&$GLOBALS['pecHttpCalls']===$reads+1,'Gmail revocation between the message and thread request prevents the next provider fetch');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');$refresh($draftCandidate);
    $pdo->prepare('UPDATE phone_agent_email_call_decisions_v1 SET checked_epoch=? WHERE work_key=?')->execute([time()-121,$draftCandidate['work_key']]);
    pec_check_v1($current($draftCandidate,'active')['reason']==='freshness_required','active disclosure cannot use an expired saved provider check');
    $refresh($draftCandidate,'active');

    $dispatches=0;$inference=static function(string $prompt,string $protocol,Closure $before,array $job,bool $transient)use(&$dispatches):string{
        $data=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
        pec_check_v1($protocol===VP3_PHONE_AGENT_EMAIL_CALL_PROTOCOL_V1&&$job['background']&&$job['phone_agent_email_call_decision']
            &&$transient&&$job['conversation_id']===11&&!str_contains($prompt,'PRIVATE_')&&!str_contains($prompt,'example.test')
            &&array_keys($data)===['task','now_epoch','facts','owner_call_preferences','source_status','delivery'],
            'bounded usefulness prompt contains typed current facts, permission limits and exact selected Chat without private email contents');
        $before();++$dispatches;return '{"decision":"request_call","reason":"ready_for_approval"}';
    };
    $decision=vp3_phone_agent_email_call_decide_v1($pdo,$context,$draftCandidate,time(),$inference,'pec_http_v1');
    pec_check_v1($decision['reason']==='eligible'&&$decision['decision']==='request_call'&&$dispatches===1
        &&vp3_phone_agent_email_call_decision_current_v1($pdo,$context,$draftCandidate,time()),
        'one actual source-guarded Main Agent recommendation is durably required before Operator preparation');
    vp3_phone_agent_email_call_decide_v1($pdo,$context,$draftCandidate,time(),$inference,'pec_http_v1');
    pec_check_v1($dispatches===1,'repeated unchanged source polls reuse the durable decision without another model dispatch');
    $edit($draft,'An edited body remains the same call episode.');$edited=$find($draft['id']);
    pec_check_v1($edited['source_revision']!==$draftCandidate['source_revision']&&$edited['work_key']===$draftCandidate['work_key']
        &&$edited['semantic_revision']===$draftCandidate['semantic_revision']&&$current($draftCandidate,'review')['reason']==='source_changed',
        'editing a draft fences an old exact review while preserving the semantic call episode');
    vp3_phone_agent_email_call_decide_v1($pdo,$context,$edited,time(),$inference,'pec_http_v1');
    pec_check_v1($dispatches===1&&vp3_phone_agent_email_call_decision_current_v1($pdo,$context,$edited,time()),
        'unchanged usefulness facts survive body-only saves without a new inference or new episode');

    $skip=pec_draft_v1($f,'skip_episode');$skipCandidate=$find($skip['id']);$skips=0;
    $skipInference=static function($prompt,$protocol,$before)use(&$skips):string{$before();++$skips;return '{"decision":"defer","reason":"wait_for_review"}';};
    $deferred=vp3_phone_agent_email_call_decide_v1($pdo,$context,$skipCandidate,time(),$skipInference,'pec_http_v1');
    $reads=$GLOBALS['pecHttpCalls'];vp3_phone_agent_email_call_decide_v1($pdo,$context,$skipCandidate,time(),$skipInference,'pec_http_v1');
    pec_check_v1($deferred['reason']==='agent_defer'&&$skips===1&&$GLOBALS['pecHttpCalls']===$reads
        &&!vp3_phone_agent_email_call_decision_current_v1($pdo,$context,$skipCandidate,time()),
        'defer stays in the app without later implicit call permission, model retry or repeated freshness HTTP');

    $pre=pec_draft_v1($f,'before_dispatch');$preCandidate=$find($pre['id']);$preCount=0;
    $beforeReply=static function($prompt,$protocol,$before)use($now,&$preCount):string{
        $GLOBALS['pecThreads']['thread_before_dispatch'][]=pec_message_v1('reply_before_dispatch','thread_before_dispatch',$now-1);
        $before();++$preCount;return '{"decision":"request_call","reason":"ready_for_approval"}';
    };
    $blocked=vp3_phone_agent_email_call_decide_v1($pdo,$context,$preCandidate,time(),$beforeReply,'pec_http_v1');
    pec_check_v1($blocked['reason']==='source_changed'&&$preCount===0
        &&!vp3_phone_agent_email_call_decision_current_v1($pdo,$context,$preCandidate,time()),
        'a fresh reply immediately before the actual model boundary prevents dispatch and any call recommendation');
    $race=pec_draft_v1($f,'during_dispatch');$raceCandidate=$find($race['id']);$raceCount=0;
    $during=static function($prompt,$protocol,$before)use($now,&$raceCount):string{
        $before();++$raceCount;$GLOBALS['pecThreads']['thread_during_dispatch'][]=pec_message_v1('reply_during_dispatch','thread_during_dispatch',$now-1);
        return '{"decision":"request_call","reason":"ready_for_approval"}';
    };
    $blocked=vp3_phone_agent_email_call_decide_v1($pdo,$context,$raceCandidate,time(),$during,'pec_http_v1');
    vp3_phone_agent_email_call_decide_v1($pdo,$context,$raceCandidate,time(),$during,'pec_http_v1');
    pec_check_v1($blocked['reason']==='source_changed'&&$raceCount===1
        &&!vp3_phone_agent_email_call_decision_current_v1($pdo,$context,$raceCandidate,time()),
        'a reply during inference withholds the result and the consumed dispatch is never retried');
    $invalid=pec_draft_v1($f,'invalid_output');$invalidCandidate=$find($invalid['id']);$badCount=0;
    $bad=static function($prompt,$protocol,$before)use(&$badCount):string{$before();++$badCount;return '{"decision":"request_call","reason":"ready_for_approval","to":"+15551234567"}';};
    $blocked=vp3_phone_agent_email_call_decide_v1($pdo,$context,$invalidCandidate,time(),$bad,'pec_http_v1');
    vp3_phone_agent_email_call_decide_v1($pdo,$context,$invalidCandidate,time(),$bad,'pec_http_v1');
    pec_check_v1($blocked['reason']==='decision_unavailable'&&$badCount===1
        &&!vp3_phone_agent_email_call_decision_current_v1($pdo,$context,$invalidCandidate,time()),
        'an extra destination/tool field cannot become a call and malformed paid decisions are not replayed');

    require_once dirname(__DIR__).'/includes/agent-operator-owner-email-v1.php';
    $prepared=vp3_operator_owner_email_prepare_v1($pdo,$context,$edited,time());
    pec_check_v1($prepared['state']==='prepared'&&(int)$pdo->query('SELECT COUNT(*) FROM phone_operator_carrier_attempts_v1')->fetchColumn()===0,
        'a verified recommendation prepares the existing native Operator occurrence without dialing');
    $edit($draft,'Edited after an occurrence exists.');$newest=$find($draft['id']);
    $consumed=vp3_phone_agent_email_call_decide_v1($pdo,$context,$newest,time(),$inference,'pec_http_v1');
    pec_check_v1($consumed['reason']==='episode_consumed'&&$dispatches===1
        &&(int)$pdo->query('SELECT COUNT(*) FROM phone_agent_email_call_requests_v1')->fetchColumn()===1,
        'a prepared call consumes the owner episode even if the draft changes before dialing; no model replay or redial is manufactured');
    $sweepCalls=0;$sweepInfer=static function($prompt,$protocol,$before)use(&$sweepCalls):string{$before();++$sweepCalls;return '{"decision":"skip","reason":"insufficient_urgency"}';};
    $sweep=vp3_phone_agent_email_call_sweep_v1($pdo,time(),$sweepInfer,10,'pec_http_v1');
    pec_check_v1($sweep['owners_checked']===1&&$sweep['inferences']<=1&&$sweepCalls<=1
        &&(int)$pdo->query('SELECT COUNT(*) FROM phone_operator_carrier_attempts_v1')->fetchColumn()===0,
        'bounded sweep is independent of push consent and never invokes a carrier');

    $follow=pec_followthrough_v1($f,'followthrough');$followCandidate=$find($follow['id']);
    pec_check_v1($followCandidate['facts']['kind']==='outbound_followup'
        &&$followCandidate['facts']['provenance']==='owner_response_deadline'
        &&$followCandidate['facts']['deadline']['kind']==='instant'&&$refresh($followCandidate)['reason']==='eligible',
        'a real verified sent receipt, owner deadline and generated follow-up produce one current review episode');
    $followRow=static fn():array=>vp3_phone_sql_v1($pdo,'SELECT * FROM agent_email_followthrough_v1 WHERE id=?',[$follow['row_id']])->fetch(PDO::FETCH_ASSOC);
    $control=static function(string $operation,array $extra=[])use($pdo,$f,$follow,$followRow):array{
        return agent_email_followthrough_update_v1($pdo,$f['owner'],$follow['principal'],11,
            ['followthrough_ref'=>$follow['row_id'],'expected_revision'=>(int)$followRow()['revision'],'operation'=>$operation]+$extra,str_repeat('k',32));
    };
    $pdo->beginTransaction();$pdo->query('SELECT paused FROM agent_email_followthrough_v1')->fetchAll();
    $peer->prepare('UPDATE agent_email_followthrough_v1 SET paused=1,revision=revision+1 WHERE id=?')->execute([$follow['row_id']]);
    pec_check_v1($warmRejected(static fn():array=>$current($followCandidate,'active')),
        'a warm transaction cannot disclose a follow-up after a concurrent owner pause');
    if($pdo->inTransaction())$pdo->rollBack();$peer->prepare('UPDATE agent_email_followthrough_v1 SET paused=0,revision=revision-1 WHERE id=?')->execute([$follow['row_id']]);
    $control('pause');pec_check_v1(!in_array($follow['id'],array_column($candidates(),'source_id'),true),
        'the actual owner Pause control withdraws follow-up call eligibility');
    $control('resume');agent_email_followthrough_check_v1($pdo,$f['owner'],$follow['principal'],11,
        ['followthrough_ref'=>$follow['row_id'],'expected_revision'=>(int)$followRow()['revision']],str_repeat('k',32),'pec_http_v1');
    $resumed=$find($follow['id']);
    pec_check_v1($resumed['work_key']===$followCandidate['work_key']&&$resumed['semantic_revision']===$followCandidate['semantic_revision']
        &&$refresh($resumed)['reason']==='eligible','pause and resume preserve one explicit-deadline episode after a fresh verified check');
    $peer->prepare("UPDATE agent_connection_sends_v1 SET status='pending' WHERE owner_user_id=1 AND operation_key=?")->execute([$follow['operation']]);
    pec_check_v1(!in_array($follow['id'],array_column($candidates(),'source_id'),true),
        'a generated follow-up loses eligibility when its exact original confirmed-send proof is unavailable');
    $peer->prepare("UPDATE agent_connection_sends_v1 SET status='sent' WHERE owner_user_id=1 AND operation_key=?")->execute([$follow['operation']]);
    $reply=pec_message_v1('followthrough_reply',$follow['thread'],time()-1,'recipient@example.test');
    $reply['payload']['headers'][]=['name'=>'In-Reply-To','value'=>'<sent_followthrough@example.test>'];
    $GLOBALS['pecThreads'][$follow['thread']][]=$reply;
    pec_check_v1($refresh($resumed)['reason']==='source_changed'&&$current($resumed,'review')['reason']==='freshness_required',
        'a fresh exact follow-up reply withdraws call/review proof instead of inventing a still-overdue result');
    array_pop($GLOBALS['pecThreads'][$follow['thread']]);$refresh($resumed);
    $control('deadline',['deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'time_zone'=>'UTC','prepare_if_overdue'=>true,'priority'=>'high']);
    pec_check_v1((int)$followRow()['source_revision']>1&&!in_array($follow['id'],array_column($candidates(),'source_id'),true),
        'a new explicit deadline invalidates the previous generated draft without treating it as a new ready call');
    $control('resolve');pec_check_v1(!in_array($follow['id'],array_column($candidates(),'source_id'),true),
        'an owner-resolved follow-up remains unavailable to the call sweep');

    $pdo->exec('UPDATE phone_operator_owner_preferences_v1 SET revision=revision+1,min_gap_minutes=300 WHERE owner_user_id=1');
    $newContext=vp3_phone_agent_email_calls_context_v1($pdo,1,time());
    pec_check_v1(!vp3_phone_agent_email_call_decision_current_v1($pdo,$newContext,$edited,time()),
        'a prior usefulness result cannot be applied under changed owner call preferences');
    $reads=$GLOBALS['pecHttpCalls'];
    pec_check_v1($current($attentionCandidate)['reason']==='policy_changed'&&$GLOBALS['pecHttpCalls']===$reads,
        'stale channel context fails before Gmail/provider access');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
    pec_check_v1(vp3_phone_agent_email_call_candidates_v1($pdo,$newContext,time())===[],
        'Gmail grant withdrawal removes every actionable source without copying private content');

    echo 'PHONE_AGENT_EMAIL_CALL_SOURCES_V1=PASS '.($GLOBALS['pecChecks']??0).PHP_EOL;
} finally { pec_cleanup_v1($f); }
