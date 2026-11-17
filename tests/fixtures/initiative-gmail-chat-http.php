<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/includes/agent-connection-gmail-inbox-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-top-of-mind-v1.php';
if (getenv('VP3_INITIATIVE_HTTP_GMAIL_BACKLOG')!=='1') require_once dirname(__DIR__,2).'/includes/agent-email-triage-v1.php';

const INITIATIVE_HTTP_GMAIL_SUBJECT_V1 = 'Juniper jukebox rehearsal';
const INITIATIVE_HTTP_GMAIL_BODY_V1 = 'Could you confirm whether Juniper can host the jukebox rehearsal this Friday at 6 PM UTC?';
const INITIATIVE_HTTP_GMAIL_QUESTION_V1 = 'Should I draft a reply offering Saturday at 6 PM UTC?';
const INITIATIVE_HTTP_GMAIL_DRAFT_V1 = 'Saturday works for the Juniper jukebox rehearsal. The equipment is ready.';

function initiative_http_gmail_fresh_v1(): bool
{
    return getenv('VP3_INITIATIVE_HTTP_GMAIL_FRESH')==='1';
}

function initiative_http_gmail_backlog_v1(): bool
{
    return getenv('VP3_INITIATIVE_HTTP_GMAIL_BACKLOG')==='1';
}

function initiative_http_gmail_assert_cold_tick_v1(): void
{
    if (function_exists('agent_email_triage_ready_v1')) throw new LogicException('The scheduler fixture must start without triage loaded.');
}

function initiative_http_gmail_backlog_batch_v1(): never
{
    require_once dirname(__DIR__,2).'/includes/agent-email-triage-v1.php';
    require_once dirname(__DIR__,2).'/includes/agent-coordination-schedule-v1.php';
    $body=json_decode((string)file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
    if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals(csrf_token(),(string)($body['csrf_token']??''))){http_response_code(419);exit;}
    $pdo=db();
    $policy=agent_connection_gmail_inbox_policy_v1($pdo,1);
    $bound=agent_email_matters_policy_scope_v1($policy);
    $pdo->beginTransaction();
    vp3_initiative_owner_lock_v1($pdo,current_user());
    $triage=$pdo->prepare("INSERT INTO agent_email_triage_v1(triage_id,owner_user_id,connection_id,grant_key,message_id_enc,thread_id_enc,
        received_ms,sender_address,subject,context_key,purpose_key,sender_key,attention_key,group_key,reason_key,is_historical,observed_at)
        VALUES(?,?,?,?,?,?,?,'owner-fixture@example.test',?,'unknown','request','unknown',?,?,'unclassified',1,?)");
    $report=$pdo->prepare('INSERT INTO agent_email_running_report_v1(thread_key,owner_user_id,connection_id,grant_key,source_triage_id,received_ms,
        importance,failures,finding_enc,finding_priority,finding_state,analyzed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $now=time();
    for($i=1;$i<=221;$i++){
        $messageId='fixture_backlog_message_'.$i;
        $threadId='fixture_backlog_thread_'.$i;
        $item=agent_email_matters_source_key_v1($policy,'triage',$messageId);
        $thread=agent_email_matters_source_key_v1($policy,'thread',$threadId);
        $received=($now-1800-$i)*1000;
        $analysisAt=gmdate('Y-m-d H:i:s',$now-$i);
        $subject='Mailbox fixture item '.$i;
        $ask=$i<=10;
        $triage->execute([$item,...$bound,ai_encrypt_secret($messageId),ai_encrypt_secret($threadId),$received,$subject,
            $ask?'review':'read_later',$ask?'active':'historical',$analysisAt]);
        if($i>28)continue;
        $finding=[
            'category'=>$ask?'action_required':'informational','priority'=>$ask?'high':'low','state'=>'open',
            'subject'=>$subject,'summary'=>$ask?'A rehearsal booking needs a decision for stage '.$i.'.':'A routine newsletter arrived.',
            'importance_reason'=>$ask?'The venue asked for a booking decision.':'This email can be read later.',
            'next_step'=>$ask?'Check whether stage '.$i.' is still needed.':'',
            'followup_question'=>$ask?'Should I hold stage '.$i.' for the rehearsal?':'',
            'evidence_message_id'=>$messageId,'source_is_evidence'=>true,'item_ref'=>$item,
            'handoff'=>$ask?'needs_attention':'','handoff_question'=>'',
        ];
        $analyzed=$i<=20;
        $report->execute([$thread,...$bound,$item,$received,$ask?2:0,$analyzed?0:4,
            $analyzed?ai_encrypt_secret(json_encode($finding,JSON_THROW_ON_ERROR)):null,$analyzed?($ask?2:0):null,
            $analyzed?'open':null,$analyzed?$analysisAt:null]);
    }
    vp3_coordination_source_changed_v1($pdo,1);
    $pdo->commit();
    header('Content-Type: application/json');echo '{"ok":true}';exit;
}

function initiative_http_gmail_fresh_seed_v1(PDO $pdo): void
{
    require_once dirname(__DIR__,2).'/includes/agent-connection-email-chat-v1.php';
    agent_connection_email_draft_schema_v1($pdo);
    agent_connection_email_followup_install_v1($pdo);
    agent_email_matters_install_v1($pdo);
    agent_connection_gmail_inbox_activity_install_v1($pdo);
    $pdo->exec('CREATE TABLE agent_connection_sends_v1 (operation_key TEXT);
        CREATE TABLE fixture_gmail_arrival (id INTEGER PRIMARY KEY,received_ms INTEGER);
        INSERT INTO fixture_gmail_arrival VALUES (1,0);
        CREATE TABLE fixture_gmail_wire (id INTEGER PRIMARY KEY,method TEXT,path TEXT,format TEXT,cursor TEXT);
        CREATE TABLE user_calendar_events (id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,description TEXT,
            location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,recurrence TEXT,
            agent_notifications INTEGER,source TEXT,source_reference TEXT,status TEXT,created_by_agent_id INTEGER,updated_at TEXT);');
    $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $friday = $now->setTime(18,0);
    if ($friday->format('N')!=='5' || $friday <= $now->modify('+1 hour')) $friday = $now->modify('next Friday')->setTime(18,0);
    $saturday = $friday->modify('+1 day');
    // CI can run when there is no future Friday/Saturday pair in the seven-day read window.
    if ($saturday >= $now->modify('+7 days')) {
        $friday = $now->modify('+1 day')->setTime(18,0);
        $saturday = $friday->modify('+1 day');
    }
    $calendar=$pdo->prepare("INSERT INTO user_calendar_events VALUES(?,1,?,?,'',?,?,'UTC',0,'none',0,'user','','active',7,CURRENT_TIMESTAMP)");
    foreach ([[701,'Juniper jukebox rehearsal — Friday','Proposed Juniper jukebox rehearsal on Friday at 6 PM UTC.',$friday],
        [702,'Juniper jukebox rehearsal — Friday stage booking','This confirmed Juniper stage booking overlaps the Friday jukebox rehearsal.',$friday->modify('+30 minutes')],
        [703,'Juniper jukebox rehearsal — Saturday alternative','The Juniper room is available Saturday at 6 PM UTC as an alternative for the jukebox rehearsal.',$saturday]]
        as [$id,$title,$description,$at]) {
        $calendar->execute([$id,$title,$description,$at->format('Y-m-d H:i:s'),$at->modify('+1 hour')->format('Y-m-d H:i:s')]);
    }
    $task=$pdo->prepare('UPDATE agent_memory_items SET subject=?,memory_text=?,metadata_json=? WHERE id=100');
    $task->execute(['Juniper jukebox rehearsal equipment','The jukebox equipment and cables are ready for the Juniper rehearsal on either day.',
        json_encode(['task_status'=>'open','source_kind'=>'explicit_user','conversation_id'=>11],JSON_THROW_ON_ERROR)]);
    $pdo->exec('DELETE FROM agent_memory_items WHERE id<>100; DELETE FROM chat_messages');
    require_once dirname(__DIR__,2).'/includes/agent-coordination-rules-v1.php';
    $owner=['id'=>1,'is_active'=>1];
    $rules=vp3_coordination_rule_settings_v1($pdo,$owner,7,11);
    vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$rules['revision'],['planning'=>['conflict'=>'watch']]);
}

/** Simulate a new mailbox notification without inserting classification or report rows. */
function initiative_http_gmail_arm_v1(): never
{
    initiative_http_gmail_control_authority_v1();
    $pdo=db();
    $q=$pdo->prepare('UPDATE fixture_gmail_arrival SET received_ms=? WHERE id=1 AND received_ms=0');
    $q->execute([(time()-1)*1000]);
    if($q->rowCount()!==1){http_response_code(409);exit;}
    $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET next_check_at=? WHERE owner_user_id=1')
        ->execute([gmdate('Y-m-d H:i:s',time()-180)]);
    $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,source_kind,dedupe_key,title,goal,status)
        VALUES(1,7,'agent_initiative',?,'Earlier Media suggestion',?,'completed')")
        ->execute([hash('sha256','fixture-media-before-gmail'),json_encode([
            'tool'=>'coordination.review','coordination_route'=>['specialist'=>'media','action'=>'ask'],
        ],JSON_THROW_ON_ERROR)]);
    $mediaRun=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
        VALUES(11,NULL,'assistant','Want to listen to a song?',?,NOW())")
        ->execute([json_encode(['source'=>'agent_initiative','initiative_run_id'=>$mediaRun],JSON_THROW_ON_ERROR)]);
    header('Content-Type: application/json');echo '{"ok":true}';exit;
}

function initiative_http_gmail_control_authority_v1(): void
{
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!initiative_http_gmail_fresh_v1() || ($_SERVER['REQUEST_METHOD']??'')!=='POST'
        || (int)(current_user()['id']??0)!==1 || !hash_equals(csrf_token(),(string)($body['csrf_token']??''))){
        http_response_code(403);exit;
    }
}

/** Only the provider boundary is controlled. History, reads and cursor handling run production code. */
function initiative_http_gmail_wire_v1(string $method,string $url,array $headers,?array $body,bool $form): array
{
    $pdo=db();
    $pdo->prepare('INSERT INTO fixture_gmail_provider_calls VALUES(?)')->execute([$method]);
    if(!initiative_http_gmail_fresh_v1() || $method!=='GET' || $body!==null || $form || !str_starts_with($url,VP3_GMAIL_API_V1)
        || !in_array('Authorization: Bearer fixture-access',$headers,true))throw new LogicException('Unexpected Gmail provider operation.');
    $path=(string)parse_url($url,PHP_URL_PATH);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $format=$query['format']??'';
    $cursor=$query['startHistoryId']??'';
    $pdo->prepare('INSERT INTO fixture_gmail_wire(method,path,format,cursor) VALUES (?,?,?,?)')->execute([$method,$path,$format,$cursor]);
    $received=(int)$pdo->query('SELECT received_ms FROM fixture_gmail_arrival WHERE id=1')->fetchColumn();
    if(str_ends_with($path,'/history') && in_array($cursor,['1234','1235'],true)){
        $events=$received>0&&$cursor==='1234'?[['id'=>'1235','messagesAdded'=>array_map(static fn(string $id):array=>['message'=>['id'=>$id]],
            ['fixture_venue_message','fixture_promotion_message'])]]:[];
        return ['status'=>200,'json'=>['historyId'=>$received>0?'1235':'1234','history'=>$events]];
    }
    if($received<1)throw new LogicException('Gmail read before the fixture arrival.');
    $venue=['id'=>'fixture_venue_message','threadId'=>'fixture_venue_thread','labelIds'=>['INBOX'],'internalDate'=>(string)$received,
        'payload'=>['mimeType'=>'text/plain','headers'=>[
            ['name'=>'From','value'=>'Juniper venue <venue@example.test>'],['name'=>'To','value'=>'owner@example.test'],
            ['name'=>'Subject','value'=>INITIATIVE_HTTP_GMAIL_SUBJECT_V1],['name'=>'Message-ID','value'=>'<juniper-fixture@example.test>'],
        ],'body'=>['data'=>rtrim(strtr(base64_encode(INITIATIVE_HTTP_GMAIL_BODY_V1),'+/','-_'),'=')]]];
    if(str_ends_with($path,'/threads/fixture_venue_thread') && $format==='metadata'){
        unset($venue['payload']['body']);
        return ['status'=>200,'json'=>['id'=>'fixture_venue_thread','messages'=>[$venue]]];
    }
    if(str_ends_with($path,'/messages/fixture_venue_message') && in_array($format,['metadata','full'],true)){
        if($format==='metadata')unset($venue['payload']['body']);
        return ['status'=>200,'json'=>$venue];
    }
    if(str_ends_with($path,'/messages/fixture_promotion_message') && in_array($format,['metadata','full'],true)){
        return ['status'=>200,'json'=>['id'=>'fixture_promotion_message','threadId'=>'fixture_promotion_thread',
            'labelIds'=>['INBOX','CATEGORY_PROMOTIONS'],'internalDate'=>(string)($received+1),'payload'=>['headers'=>[
                ['name'=>'From','value'=>'offers@example.test'],['name'=>'Subject','value'=>'Weekly sale'],
            ]]]];
    }
    throw new LogicException('Unexpected Gmail provider read: '.$path.' '.$format.' '.$cursor);
}

function initiative_http_gmail_model_v1(string $protocol,array $input): array
{
    if($protocol==='vp3.gmail-thread-analysis.v1'){
        $trigger=$input['trigger_ref']??0;
        $message=$input['messages'][$trigger-1]??[];
        if(($message['body_excerpt']??null)!==INITIATIVE_HTTP_GMAIL_BODY_V1 || ($message['subject']??null)!==INITIATIVE_HTTP_GMAIL_SUBJECT_V1)
            throw new RuntimeException('Gmail analysis must receive the newly arrived exact message body.');
        return ['category'=>'opportunity','priority'=>'high','state'=>'open','summary'=>'Juniper asked to hold the jukebox rehearsal on Friday at 6 PM UTC.',
            'importance_reason'=>'The venue needs a confirmed rehearsal time.','next_step'=>'Check the saved schedule and equipment before replying.',
            'followup_question'=>'Can you confirm Friday for the Juniper rehearsal?','evidence_ref'=>$trigger,
            'handoff'=>'needs_attention','handoff_question'=>''];
    }
    if($protocol==='vp3.initiative-plan.v1')return ['jobs'=>[],'opportunities'=>[]];
    if($protocol==='vp3.initiative-reply.v1'){
        if(($input['owner_message']['text']??null)!=='Saturday'||($input['coordination']['question']??null)!==INITIATIVE_HTTP_GMAIL_QUESTION_V1
            ||!isset($input['gmail_reply_option']))throw new RuntimeException('The bare Saturday answer must bind the exact Gmail decision.');
        return ['intent'=>'advance','focus'=>'Draft a reply offering Saturday at 6 PM UTC.','prepare_gmail_reply'=>true];
    }
    $expected=($input['response_kind']??null)==='office_entry_review'
        ?VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1:'vp3.initiative-draft.v1';
    if($protocol!==$expected)throw new RuntimeException('Unexpected fresh Gmail model protocol.');
    switch($input['response_kind']??''){
        case 'office_entry_review':
            $records=$input['tool_result']['records']??[];
            if(array_column($records,'source')!==['task:100']||!str_contains($records[0]['text']??'','equipment and cables are ready'))
                throw new RuntimeException('Tasks must read the exact saved equipment entry.');
            return ['office_disposition'=>'review','draft'=>'The jukebox equipment and cables are ready for the Juniper rehearsal on either day.','owner_attention'=>false];
        case 'coordination_review':
            $primary=$input['primary'];
            if(!str_starts_with($primary,'gmail:')){
                return ['title'=>'Friday overlap','summary'=>'The saved Friday rehearsal overlaps a Juniper stage booking.',
                    'next_step'=>'Review the alternate slot.','question'=>'Which rehearsal commitment can move?','state'=>'needs_owner','refs'=>[$primary]];
            }
            $sources=array_column($input['specialist_reports']??[],null,'ref');
            $needed=[$primary,'office:task:100','planning:calendar:event:701','planning:calendar:event:702','planning:calendar:event:703'];
            $related=$input['related_specialist_refs']??[];
            if(array_diff($needed,array_keys($sources))||array_diff(array_slice($needed,1),array_keys($related))
                ||!str_contains($sources[$primary]['summary'],'Friday at 6 PM UTC')
                ||!str_contains($sources['office:task:100']['summary'],'equipment and cables are ready')
                ||!str_contains($sources['planning:calendar:event:701']['summary'],'overlaps')
                ||!str_contains($sources['planning:calendar:event:703']['summary'],'available Saturday at 6 PM UTC')){
                throw new RuntimeException('The synthesized decision requires fresh Gmail, exact Friday conflict, saved Saturday availability, and the native Tasks report.');
            }
            $first=$sources['planning:calendar:event:701'];$overlap=$sources['planning:calendar:event:702'];
            $alternative=$sources['planning:calendar:event:703'];
            if(!isset($first['start_at'],$first['end_at'],$overlap['start_at'],$overlap['end_at'],$alternative['start_at'])
                ||$first['start_at'] >= $overlap['end_at']||$overlap['start_at'] >= $first['end_at']
                ||$alternative['start_at'] <= max($first['end_at'],$overlap['end_at'])){
                throw new RuntimeException('The saved Planning times must verify the overlap and the separate alternative.');
            }
            return ['title'=>'Juniper jukebox rehearsal','summary'=>'Juniper asked for Friday at 6 PM UTC. The saved calendar shows a Friday stage booking overlaps that rehearsal. Saturday at 6 PM UTC is available, and Tasks confirms the equipment is ready.',
                'next_step'=>'Offer the saved Saturday slot in a private reply draft.','question'=>INITIATIVE_HTTP_GMAIL_QUESTION_V1,
                'state'=>'needs_owner','refs'=>$needed];
        case 'followthrough_reply':
            if(($input['request']??null)!=='Saturday')throw new RuntimeException('The Chat reply must use the exact owner decision.');
            return ['draft'=>'I’ll draft the reply offering Saturday at 6 PM UTC for your review.'];
        case 'coordination_gmail_reply_draft':
            if(($input['owner_reply']??null)!=='Saturday'||($input['owner_question']??null)!==INITIATIVE_HTTP_GMAIL_QUESTION_V1
                ||($input['original_message']['subject']??null)!==INITIATIVE_HTTP_GMAIL_SUBJECT_V1
                ||($input['original_message']['excerpt']??null)!==INITIATIVE_HTTP_GMAIL_BODY_V1){
                throw new RuntimeException('The reply draft must use the exact owner decision and original Gmail message.');
            }
            return ['draft'=>INITIATIVE_HTTP_GMAIL_DRAFT_V1];
        default:
            throw new RuntimeException('Unexpected fresh Gmail model request kind.');
    }
}

function initiative_http_seed_gmail_v1(PDO $pdo): void
{
    require_once dirname(__DIR__,2).'/includes/agent-connection-gmail-inbox-initial-v1.php';
    require_once dirname(__DIR__,2).'/includes/agent-email-triage-v1.php';
    $pdo->exec("CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
        CREATE TABLE agent_connections_v1(id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider TEXT,status TEXT,
            read_enabled INTEGER,send_enabled INTEGER,followups_enabled INTEGER,authorization_key TEXT,client_fingerprint TEXT,
            access_token_enc TEXT,refresh_token_enc TEXT,account_email TEXT,external_account_id TEXT,access_expires_at INTEGER);
        CREATE TABLE fixture_gmail_provider_calls(method TEXT);");
    agent_connection_gmail_inbox_install_v1($pdo);
    agent_connection_gmail_inbox_initial_install_v1($pdo);
    agent_connection_gmail_inbox_receipts_install_v1($pdo);
    agent_email_triage_install_v1($pdo);
    $grant=str_repeat('a',64);
    $now=time();
    $received=($now-7200)*1000;
    $pdo->prepare("INSERT INTO agent_connections_v1 VALUES(71,1,'gmail','connected',1,0,0,?,?,?,?,'owner@example.test','fixture-mailbox',?)")
        ->execute([$grant,agent_connections_client_fingerprint_v1(),ai_encrypt_secret('fixture-access'),ai_encrypt_secret('fixture-refresh'),$now+3600]);
    $rules=agent_connection_gmail_inbox_rules_v1(['triage_enabled'=>'1','content_analysis'=>'1']);
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,history_id,enabled_at_ms,next_check_at,last_checked_at,last_status)
        VALUES(1,71,?,1,?,?,'1234',?,?,?,'checked')")
        ->execute([$grant,json_encode($rules,JSON_THROW_ON_ERROR),str_repeat('b',32),($now-86400)*1000,
            gmdate('Y-m-d H:i:s',$now+3600),gmdate('Y-m-d H:i:s',$now-60)]);
    $policy=agent_connection_gmail_inbox_policy_v1($pdo,1);
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_sync_v1(owner_user_id,connection_id,grant_key,mode,status,baseline,since_ms,before_ms,page_token,checked,reviewed)
        VALUES(?,?,?,'catchup','complete','1234',?,?,'',1,1)")
        ->execute([...agent_connection_gmail_inbox_sync_scope_v1($policy),($now-86400)*1000,$now*1000]);
    if(initiative_http_gmail_fresh_v1()){
        initiative_http_gmail_fresh_seed_v1($pdo);
        return;
    }
    $bound=agent_email_matters_policy_scope_v1($policy);
    if(initiative_http_gmail_backlog_v1()){
        require_once dirname(__DIR__,2).'/includes/agent-media-entertainment-v1.php';
        agent_email_matters_install_v1($pdo);
        $pdo->prepare('INSERT INTO agent_email_matter_scans_v1(owner_user_id,connection_id,grant_key,completed_at) VALUES(?,?,?,?)')
            ->execute([...$bound,gmdate('Y-m-d H:i:s',$now-60)]);
        $pdo->exec('ALTER TABLE chat_conversations ADD COLUMN created_at TEXT');
        initiative_media_http_install_v1($pdo);
        vp3_media_offers_save_v1($pdo,1,true);
        return;
    }
    $thread=agent_email_matters_source_key_v1($policy,'thread','fixture_venue_thread');
    $item=agent_email_matters_source_key_v1($policy,'triage','fixture_venue_message');
    $pdo->prepare("INSERT INTO agent_email_triage_v1(triage_id,owner_user_id,connection_id,grant_key,message_id_enc,thread_id_enc,
        received_ms,sender_address,subject,context_key,purpose_key,sender_key,attention_key,group_key,reason_key,is_historical,observed_at)
        VALUES(?,?,?,?,?,?,?,'venue@example.test','Jukebox event dates','unknown','request','unknown','review','active','unclassified',1,?)")
        ->execute([$item,...$bound,ai_encrypt_secret('fixture_venue_message'),ai_encrypt_secret('fixture_venue_thread'),$received,gmdate('Y-m-d H:i:s',$now-1800)]);
    $finding=[
        'category'=>'opportunity','priority'=>'high','state'=>'open',
        'summary'=>'A venue wants to host a jukebox session.',
        'importance_reason'=>'A venue is considering an event.',
        'next_step'=>'Check whether the jukebox is available in March.',
        'followup_question'=>'Which March dates can you offer the venue?',
        'evidence_message_id'=>'fixture_venue_message','source_is_evidence'=>true,'item_ref'=>$item,
        'handoff'=>'needs_attention','handoff_question'=>'',
    ];
    $pdo->prepare("INSERT INTO agent_email_running_report_v1(thread_key,owner_user_id,connection_id,grant_key,source_triage_id,received_ms,
        importance,finding_enc,finding_priority,finding_state,analyzed_at) VALUES(?,?,?,?,?,?,2,?,2,'open',?)")
        ->execute([$thread,...$bound,$item,$received,ai_encrypt_secret(json_encode($finding,JSON_THROW_ON_ERROR)),gmdate('Y-m-d H:i:s',$now-1800)]);
    $pdo->exec('DELETE FROM agent_memory_items; DELETE FROM chat_messages');
    if (getenv('VP3_INITIATIVE_HTTP_GMAIL_LEGACY_ANCHOR')==='0') return;
    // The Gmail connection already has a verified destination on this Agent,
    // in an older Chat. The owner opens a different saved Chat for that Agent.
    $pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES (14,1,7,'Gmail setup')");
    $scope=agent_connection_gmail_inbox_initial_scope_v1($pdo,['id'=>1,'is_active'=>1]);
    $run=str_repeat('c',32);
    $ref=agent_connection_gmail_inbox_chat_ref_v1($scope,$run);
    $context=json_encode(['source'=>'agent_connection_gmail_first_review',
        'gmail_first_review_v1'=>['run_id'=>$run,'report_ref'=>$ref]],JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json)
        VALUES (14,NULL,'assistant','Your first Gmail inbox review is ready.',?)")->execute([$context]);
    $delivery=['run_id'=>$run,'report_ref'=>$ref,'agent_id'=>7,'conversation_id'=>14,'message_id'=>(int)$pdo->lastInsertId()];
    $report=['total'=>0,'scanned'=>0,'items'=>[],'at'=>gmdate('Y-m-d H:i:s',$now-3600),'chat_delivery'=>$delivery];
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_initial_v1
        (owner_user_id,connection_id,generation_id,rules_hash,run_id,days,started_at_ms,phase,report_json,updated_at)
        VALUES (1,71,?,?,?,3,?,'ready',?,?)")->execute([$scope['generation'],$scope['rules_hash'],$run,
            ($now-3600)*1000,json_encode($report,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$now-3600)]);
}

function initiative_http_gmail_state_v1(PDO $pdo,array $user): array
{
    $monitor=vp3_top_of_mind_gmail_v1($pdo,$user,7,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    $finding=$pdo->query('SELECT finding_enc FROM agent_email_running_report_v1')->fetchColumn();
    require_once dirname(__DIR__,2).'/includes/agent-coordination-sources-v1.php';
    $sources=vp3_coordination_sources_v1($pdo,$user,['agent_id'=>7,'conversation_id'=>11]);
    $mailbox=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    $delivery=agent_connection_gmail_diagnostics_delivery_v1($pdo,$mailbox,7,11);
    $state=['handoffs'=>$monitor['report']['handoffs']??null,
        'finding_revision'=>hash('sha256',(string)$finding),
        'source_triggers'=>array_column($sources['items'],'trigger','ref'),
        'source_coverage'=>$sources['coverage'],
        'delivery_stage'=>$delivery[0],'delivery_detail'=>$delivery[1],
        'provider_calls'=>(int)$pdo->query('SELECT COUNT(*) FROM fixture_gmail_provider_calls')->fetchColumn()];
    if(!initiative_http_gmail_fresh_v1())return $state;
    $drafts=[];
    foreach($pdo->query('SELECT id,recipient,conversation_id,user_message_id,status,message_enc FROM agent_connection_email_drafts_v1 ORDER BY id') as $draft){
        $draft['payload']=json_decode(ai_decrypt_secret($draft['message_enc']),true,16,JSON_THROW_ON_ERROR);
        unset($draft['message_enc']);
        $drafts[]=$draft;
    }
    return $state+['history_id'=>$mailbox['policy']['history_id']??null,
        'triage'=>$pdo->query('SELECT subject,attention_key,group_key,reason_key FROM agent_email_triage_v1 ORDER BY subject')->fetchAll(),
        'finding_count'=>(int)$pdo->query('SELECT COUNT(*) FROM agent_email_running_report_v1 WHERE finding_enc IS NOT NULL')->fetchColumn(),
        'wire'=>$pdo->query('SELECT method,path,format,cursor FROM fixture_gmail_wire ORDER BY id')->fetchAll(),
        'drafts'=>$drafts,'sent_count'=>(int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()];
}
