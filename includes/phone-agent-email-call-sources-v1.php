<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-workspace-v1.php';
require_once __DIR__.'/phone-agent-workspace-sources-v1.php';
require_once __DIR__.'/phone-agent-email-call-decisions-schema-v1.php';

const VP3_PHONE_AGENT_EMAIL_CALL_FRESH_SECONDS_V1 = 120;
const VP3_PHONE_AGENT_EMAIL_CALL_PROTOCOL_V1 = 'vp3.phone-agent-email-call-decision.v1';

function vp3_phone_agent_email_call_hash_v1(array $value): string
{ return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }

/** The call grant is independent of push preferences and never requires a fabricated session. */
function vp3_phone_agent_email_call_context_revision_v1(array $context): string
{
    $p=$context['policy'];$e=$context['enrollment'];$preferences=$context['preferences'];$holder=$context['holder'];
    return vp3_phone_agent_email_call_hash_v1(['email-call-context-v1',(int)$context['user']['id'],
        (int)$p['revision'],(int)$preferences['revision'],(int)$preferences['phone_revision'],
        $e['device_id'],$e['grant_id'],(int)$e['revision'],$e['credential_hash'],$e['owner_fingerprint'],
        (int)$e['selected_agent_id'],(int)$e['conversation_id'],$p['line_binding'],$p['destination_hash'],
        $holder['profile_id'],(int)$holder['revision'],(int)$holder['phone_revision']]);
}

function vp3_phone_agent_email_call_context_current_v1(PDO $pdo,array $expected,int $now,bool $lock=false): array
{
    if (!function_exists('vp3_phone_agent_email_calls_context_v1')) require_once __DIR__.'/phone-agent-email-calls-settings-v1.php';
    $current=vp3_phone_agent_email_calls_context_v1($pdo,(int)$expected['user']['id'],$now,$lock);
    vp3_phone_agent_email_calls_check_v1(hash_equals(vp3_phone_agent_email_call_context_revision_v1($expected),
        vp3_phone_agent_email_call_context_revision_v1($current)),'policy_changed');
    return $current;
}

/** One received message is one owner-wide episode, even when it later has a prepared reply. */
function vp3_phone_agent_email_call_work_key_v1(int $owner,int $connection,string $grant,string $kind,string $id,int $revision=0): string
{ return vp3_phone_agent_email_call_hash_v1(['email-call-episode-v1',$owner,$connection,$grant,$kind,$id,$revision]); }

/** A date-only deadline remains a date; the adapter never manufactures a midnight instant. */
function vp3_phone_agent_email_call_deadline_v1(?string $value): array
{
    if ($value===null) return ['kind'=>'none','value'=>null];
    return ['kind'=>strlen($value)===10?'date':'instant','value'=>$value];
}

function vp3_phone_agent_email_call_draft_v1(PDO $pdo,int $owner,int $agent,string $id): ?array
{
    $q=$pdo->prepare("SELECT d.*,c.title AS conversation_title,m.id AS request_message_id,m.created_at AS request_created_at
        FROM agent_connection_email_drafts_v1 d
        JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id AND COALESCE(c.user_agent_id,0)=d.agent_id
        JOIN chat_messages m ON m.id=d.user_message_id AND m.conversation_id=d.conversation_id AND m.user_id=d.owner_user_id AND m.role='user'
        WHERE d.owner_user_id=? AND d.agent_id=? AND d.id=?");
    $q->execute([$owner,$agent,$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

/** A current locking read must agree with the native projection's repeatable-read snapshot. */
function vp3_phone_agent_email_call_rows_current_v1(PDO $pdo,string $sql,array $parameters): void
{
    if (!vp3_phone_transaction_active_v1($pdo)) return;
    $before=vp3_phone_sql_v1($pdo,$sql,$parameters)->fetchAll(PDO::FETCH_ASSOC);
    $current=vp3_phone_sql_v1($pdo,$sql.vp3_phone_lock_v1($pdo),$parameters)->fetchAll(PDO::FETCH_ASSOC);
    vp3_phone_agent_email_calls_check_v1($before===$current,'source_changed');
}

/** Keep legacy source readers intact, while refusing a stale snapshot at a locked call boundary. */
function vp3_phone_agent_email_call_material_current_v1(PDO $pdo,array $context,array $material): void
{
    if (!vp3_phone_transaction_active_v1($pdo)) return;
    $owner=(int)$context['user']['id'];$candidate=$material['candidate'];$agent=$candidate['selected_agent_id'];
    $lock=vp3_phone_lock_v1($pdo);
    if ($agent>0) {
        $exists=vp3_phone_sql_v1($pdo,'SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1'.$lock,[$agent,$owner])->fetchColumn();
        vp3_phone_agent_email_calls_check_v1($exists!==false,'source_changed');
    }
    foreach (array_unique([$candidate['conversation_id'],(int)$context['policy']['conversation_id']]) as $chat) {
        vp3_phone_agent_email_calls_check_v1(vp3_agent_chat_conversation_v380($pdo,$chat,$owner,$agent>0?['id'=>$agent]:null,true)!==null,'source_changed');
    }
    if (isset($material['draft'])) {
        $draft=$material['draft'];
        vp3_phone_agent_email_call_rows_current_v1($pdo,'SELECT * FROM agent_connection_email_drafts_v1 WHERE id=? AND owner_user_id=?',[$draft['id'],$owner]);
        $exists=vp3_phone_sql_v1($pdo,"SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'".$lock,
            [(int)$draft['user_message_id'],$candidate['conversation_id'],$owner])->fetchColumn();
        vp3_phone_agent_email_calls_check_v1($exists!==false,'source_changed');
        agent_connections_require_grant_v1($pdo,$owner,$material['connection_id'],'reply',$material['authorization_key']);
        if (isset($material['followthrough'])) {
            $bound=agent_email_followthrough_binding_current_v1($pdo,$owner,$agent,$candidate['conversation_id'],$material['binding'],true);
            vp3_phone_agent_email_calls_check_v1($bound['record']===$material['followthrough']['record']&&$bound['watch']===$material['followthrough']['watch'],'source_changed');
            vp3_phone_agent_email_call_rows_current_v1($pdo,'SELECT * FROM agent_connection_email_drafts_v1 WHERE owner_user_id=? AND id=?',[$owner,$bound['watch']['draft_id']]);
            vp3_phone_agent_email_call_rows_current_v1($pdo,'SELECT * FROM agent_connection_sends_v1 WHERE owner_user_id=? AND operation_key=?',[$owner,$bound['watch']['operation_key']]);
        }
    }
    // Local inbox/matter changes are current evidence too; a provider proof cannot revive them.
    require_once __DIR__.'/agent-connection-gmail-inbox-activity-v1.php';
    require_once __DIR__.'/agent-email-triage-v1.php';
    $mailbox=agent_connection_gmail_inbox_bound_scope_v1($pdo,$context['user'],true);
    $bound=agent_email_triage_scope_v1($mailbox);
    if ($candidate['source_kind']==='gmail_attention') {
        vp3_phone_agent_email_calls_check_v1($bound!==null&&($mailbox['generation']??null)===$material['source']['target']['generation'],'source_changed');
        $triage=agent_email_triage_find_v1($pdo,$bound,$candidate['source_id'],true);
        vp3_phone_agent_email_calls_check_v1($triage===$material['triage'],'source_changed');
    }
    if ($bound!==null&&(int)$bound[1]===$material['connection_id']
        &&hash_equals((string)$mailbox['policy']['authorization_key'],$material['authorization_key'])) {
        $matter=agent_email_matters_source_key_v1($mailbox['policy'],'thread',$material['binding']['thread_id']);
        vp3_phone_agent_email_call_rows_current_v1($pdo,'SELECT * FROM agent_email_matters_v1 WHERE owner_user_id=? AND connection_id=? AND grant_key=? AND matter_id=?',[...$bound,$matter]);
        if (agent_connection_gmail_inbox_activity_ready_v1($pdo)) {
            vp3_phone_agent_email_call_rows_current_v1($pdo,'SELECT * FROM agent_connection_gmail_inbox_activity_v1 WHERE matter_id=?',[$matter]);
            $anchor=agent_email_matters_source_key_v1($mailbox['policy'],'inbox',$material['binding']['message_id']);
            $event=agent_email_matters_source_key_v1($mailbox['policy'],'gmail-sent-anchor',$anchor);
            vp3_phone_agent_email_call_rows_current_v1($pdo,'SELECT * FROM agent_email_matter_events_v1 WHERE matter_id=? AND event_key=?',[$matter,$event]);
        }
    }
    vp3_phone_agent_email_call_rows_current_v1($pdo,'SELECT item_ref,source_revision FROM vp3_phone_agent_items_v1 WHERE owner_user_id=? AND item_ref=?',[$owner,$candidate['item_ref']]);
}

/** Current native facts only. Private source material stays local to refresh and never enters a model prompt. */
function vp3_phone_agent_email_call_material_v1(PDO $pdo,array $context,string $kind,string $id,int $now): ?array
{
    if (!in_array($kind,['gmail_draft','gmail_attention'],true)||!vp3_phone_agent_source_id_v1($kind,$id)) return null;
    $owner=(int)$context['user']['id'];$agent=(int)$context['policy']['selected_agent_id'];
    $source=vp3_phone_agent_workspace_source_v1($pdo,$context['user'],$agent,$kind,$id,$now);
    if ($source===null||$source['state']!=='needs_review'||!$source['needs_action']||($source['notification_eligible']??true)!==true) return null;
    $material=['source'=>$source];$priority='normal';$deadline=null;$provenance='';$origin='';
    if ($kind==='gmail_draft') {
        $draft=vp3_phone_agent_email_call_draft_v1($pdo,$owner,$agent,$id);
        if ($draft===null) return null;
        $payload=agent_connection_email_chat_payload_v1($draft);
        $public=agent_email_review_queue_row_v1($draft,random_bytes(32),$now);
        // Version 1 is an ordinary composer save. Only the two committed Agent preparation
        // milestones have a server-owned origin and an immutable created_at timestamp.
        $composer=$payload['composer']??[];
        if (!in_array($composer['version']??null,[2,3],true)||isset($payload['auto_revision'])
            ||$public['status']!=='prepared'||empty($public['available'])||empty($public['can_send'])
            ||!agent_email_composer_complete_v1($draft,$payload)) return null;
        $origin=$composer['origin'];$epoch=(int)$composer['created_at'];
        $priority=$composer['source']['priority'];$deadline=$composer['source']['deadline'];
        $connection=(int)$draft['connection_id'];$grant=$draft['authorization_key'];
        if (isset($payload['outbound_followup'])) {
            require_once __DIR__.'/agent-email-followthrough-v1.php';
            $binding=$payload['outbound_followup'];
            $bound=agent_email_followthrough_binding_current_v1($pdo,$owner,$agent,(int)$draft['conversation_id'],$binding);
            if (($bound['record']['generated_draft_id']??null)!==$id
                ||(int)($bound['record']['generated_revision']??0)!==(int)$binding['revision']) return null;
            $work=vp3_phone_agent_email_call_work_key_v1($owner,$connection,$grant,'followup',$binding['followthrough_id'],$binding['revision']);
            $provenance='owner_response_deadline';$material['followthrough']=$bound;
        } else {
            $binding=$payload['reply'];
            $work=vp3_phone_agent_email_call_work_key_v1($owner,$connection,$grant,'incoming',$binding['message_id']);
            $provenance='owner_requested_agent_reply';
        }
        $material+=['draft'=>$draft,'payload'=>$payload,'connection_id'=>$connection,'authorization_key'=>$grant,
            'mailbox'=>$draft['account_email'],'binding'=>$binding];
        $category='draft';
    } else {
        $scope=vp3_phone_agent_source_scope_v1($pdo,$context['user'],$agent);
        $attention=vp3_phone_agent_source_attention_context_v1($pdo,$scope);
        if ($attention===null) return null;
        $row=agent_email_triage_find_v1($pdo,$attention['bound'],$id);
        $anchor=agent_email_triage_source_v1($pdo,$attention['mailbox'],$id);
        if ($row===null||$anchor===null||!empty($row['is_historical'])||empty($row['should_alert'])) return null;
        $thread=agent_connection_gmail_id_v1(ai_decrypt_secret((string)$row['thread_id_enc']));
        if ($thread==='') return null;
        $connection=(int)$anchor['connection_id'];$grant=$anchor['authorization_key'];
        $live=agent_connections_require_grant_v1($pdo,$owner,$connection,'read',$grant);
        $work=vp3_phone_agent_email_call_work_key_v1($owner,$connection,$grant,'incoming',$anchor['message_id']);
        $epoch=$source['updated_epoch'];$category='urgent';$origin='important_arrival';
        $priority='high';$deadline=$row['due_at']?:($row['due_date']?:null);$provenance=(string)$row['reason_key'];
        $material+=['triage'=>$row,'connection_id'=>$connection,'authorization_key'=>$grant,'mailbox'=>$live['account_email'],
            'binding'=>['message_id'=>$anchor['message_id'],'thread_id'=>$thread]];
    }
    if ($epoch<1||$epoch>$now) return null;
    $facts=['kind'=>$origin,'ready_for_review'=>true,'priority'=>$priority,'provenance'=>$provenance,
        'deadline'=>vp3_phone_agent_email_call_deadline_v1($deadline),'milestone_epoch'=>$epoch,
        'historical'=>false,'provider_coverage'=>'exact_thread','mail_content_included'=>false];
    // Edits retain one call episode and one usefulness decision for unchanged typed facts.
    // The separate exact source hash still invalidates every stale prepared request/review.
    $semantic=vp3_phone_agent_email_call_hash_v1(['email-call-semantic-v1',$work,$category,$facts]);
    $item=vp3_phone_sql_v1($pdo,'SELECT item_ref,source_revision FROM vp3_phone_agent_items_v1
        WHERE owner_user_id=? AND agent_id=? AND source_kind=? AND source_id=? AND conversation_id=?',
        [$owner,$agent,$kind,$id,$source['conversation_id']])->fetch(PDO::FETCH_ASSOC);
    $candidate=['work_key'=>$work,'semantic_revision'=>$semantic,'source_kind'=>$kind,'source_id'=>$id,
        'source_revision'=>$source['source_revision'],'item_ref'=>$item&&hash_equals($item['source_revision'],$source['source_revision'])?$item['item_ref']:'',
        'selected_agent_id'=>$agent,'conversation_id'=>$source['conversation_id'],'source_updated_epoch'=>$epoch,
        'category'=>$category,'facts'=>$facts];
    $last=vp3_phone_agent_workspace_source_v1($pdo,$context['user'],$agent,$kind,$id,$now);
    if ($last===null||!hash_equals($source['source_revision'],$last['source_revision'])) return null;
    return $material+['candidate'=>$candidate];
}

/** Bounded saved work discovery; old pending work is indexed silently and cannot cross a consent cutover. */
function vp3_phone_agent_email_call_candidates_v1(PDO $pdo,array $context,int $now,int $limit=40): array
{
    $limit=max(1,min(40,$limit));$context=vp3_phone_agent_email_call_context_current_v1($pdo,$context,$now);
    if (!vp3_phone_agent_workspace_ready_v1($pdo)||!vp3_phone_agent_email_call_decisions_ready_v1($pdo)) return [];
    $owner=(int)$context['user']['id'];$agent=(int)$context['policy']['selected_agent_id'];$refs=[];
    if (!empty($context['policy']['drafts_enabled'])&&table_exists('agent_connection_email_drafts_v1')) {
        $q=$pdo->prepare("SELECT id FROM agent_connection_email_drafts_v1 WHERE owner_user_id=? AND agent_id=? AND status='prepared' ORDER BY user_message_id DESC LIMIT 40");
        $q->execute([$owner,$agent]);foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $refs[]=['gmail_draft',$id];
    }
    if (!empty($context['policy']['urgent_enabled'])) {
        try {
            $attention=vp3_phone_agent_source_attention_context_v1($pdo,vp3_phone_agent_source_scope_v1($pdo,$context['user'],$agent));
            if ($attention!==null) {
                $bound=$attention['bound'];
                $q=$pdo->prepare("SELECT triage_id FROM agent_email_triage_v1 WHERE owner_user_id=? AND connection_id=? AND grant_key=?
                    AND attention_key='act_soon' AND group_key='active' AND is_historical=0 AND should_alert=1 ORDER BY observed_at DESC,triage_id LIMIT 40");
                $q->execute($bound);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $refs[]=['gmail_attention',$id];
            }
        } catch (PDOException $error) { throw $error; }
        catch (RuntimeException|DomainException $unavailable) { /* A disabled source does not borrow another mailbox. */ }
    }
    $out=[];
    foreach ($refs as [$kind,$id]) {
        try {
            $material=vp3_phone_agent_email_call_material_v1($pdo,$context,$kind,$id,$now);
            if ($material===null) continue;
            $candidate=$material['candidate'];$category=$candidate['category']==='draft'?'drafts':'urgent';
            if ($candidate['source_updated_epoch']<=(int)$context['policy'][$category.'_since_epoch']) continue;
            vp3_phone_agent_workspace_ingest_v1($pdo,$owner,$agent,[$material['source']],$now);
            $material=vp3_phone_agent_email_call_material_v1($pdo,$context,$kind,$id,$now);
            if ($material===null||$material['candidate']['item_ref']==='') continue;
            $candidate=$material['candidate'];
            // Drafts are visited first and replace the arrival with its actual next review action.
            if (!isset($out[$candidate['work_key']])) $out[$candidate['work_key']]=$candidate;
        } catch (PDOException $error) { throw $error; }
        catch (RuntimeException|DomainException $unavailable) { continue; }
    }
    vp3_phone_agent_email_call_context_current_v1($pdo,$context,$now);
    $out=array_values($out);
    usort($out,static fn(array $a,array $b):int=>($b['facts']['priority']==='high')<=>($a['facts']['priority']==='high')
        ?: $a['source_updated_epoch']<=>$b['source_updated_epoch'] ?:strcmp($a['work_key'],$b['work_key']));
    return array_slice($out,0,$limit);
}

/** Owner-wide interruption suppression uses only current, source-bound receipts, not delivery or heartbeat. */
function vp3_phone_agent_email_call_receipt_reason_v1(PDO $pdo,array $context,array $candidate,int $now): ?string
{
    $owner=(int)$context['user']['id'];$fingerprint=hash('sha256',$owner.':'.(string)$context['user']['password_hash']);
    $q=$pdo->prepare("SELECT r.*,i.agent_id,i.conversation_id,i.source_kind,i.source_id,i.source_revision AS indexed_source_revision
        FROM vp3_phone_agent_receipts_v1 r JOIN vp3_phone_agent_items_v1 i ON i.owner_user_id=r.owner_user_id AND i.item_ref=r.item_ref
        JOIN vp3_phone_agent_enrollments_v1 e ON e.owner_user_id=r.owner_user_id AND e.device_id=r.device_id AND e.grant_id=r.grant_id
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=e.owner_user_id AND d.id=e.device_id
        WHERE r.owner_user_id=? AND e.enabled=1 AND e.owner_fingerprint=? AND e.credential_hash=d.token_hash
        AND d.device_status='approved' AND d.expires_at>? AND e.selected_agent_id=i.agent_id
        AND i.source_kind IN ('gmail_attention','gmail_draft') AND r.updated_epoch>=?
        AND (r.read_epoch IS NOT NULL OR r.acknowledged_epoch IS NOT NULL OR r.snoozed_until>?)
        ORDER BY r.updated_epoch DESC LIMIT 201".(vp3_phone_transaction_active_v1($pdo)?vp3_phone_lock_v1($pdo):''));
    $q->execute([$owner,$fingerprint,$now,$candidate['source_updated_epoch'],$now]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>200) return 'receipt_coverage_unavailable';
    foreach ($rows as $row) {
        if (!hash_equals((string)$row['source_revision'],(string)$row['indexed_source_revision'])) continue;
        try {
            $other=$context;$other['policy']['selected_agent_id']=(int)$row['agent_id'];
            $material=vp3_phone_agent_email_call_material_v1($pdo,$other,$row['source_kind'],$row['source_id'],$now);
            if ($material===null||!hash_equals($row['source_revision'],$material['candidate']['source_revision'])
                ||!hash_equals($candidate['work_key'],$material['candidate']['work_key'])) continue;
            $other['policy']['conversation_id']=$material['candidate']['conversation_id'];
            vp3_phone_agent_email_call_material_current_v1($pdo,$other,$material);
            if ($row['acknowledged_epoch']!==null&&(int)$row['acknowledged_epoch']>=$candidate['source_updated_epoch']) return 'owner_acknowledged';
            if ($row['read_epoch']!==null&&(int)$row['read_epoch']>=$candidate['source_updated_epoch']) return 'owner_read';
            if ((int)$row['snoozed_until']>$now) return 'owner_snoozed';
        } catch (PDOException $error) { throw $error; }
        catch (RuntimeException|DomainException $unavailable) { continue; }
    }
    return null;
}

function vp3_phone_agent_email_call_memo_v1(PDO $pdo,int $owner,array $candidate,bool $lock=false): ?array
{
    return vp3_phone_sql_v1($pdo,'SELECT * FROM phone_agent_email_call_decisions_v1 WHERE owner_user_id=? AND work_key=? AND semantic_revision=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$candidate['work_key'],$candidate['semantic_revision']])->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Internal stage check can omit freshness only while establishing a new exact provider observation. */
function vp3_phone_agent_email_call_current_v1(PDO $pdo,array $context,array $record,int $now,string $stage,bool $proof): array
{
    if (!in_array($stage,['admit','submit','active','review'],true)) throw new InvalidArgumentException('Invalid email-call source phase.');
    try {
        $locked=vp3_phone_transaction_active_v1($pdo);
        $context=vp3_phone_agent_email_call_context_current_v1($pdo,$context,$now,$locked);
        $material=vp3_phone_agent_email_call_material_v1($pdo,$context,(string)($record['source_kind']??''),(string)($record['source_id']??''),$now);
        if ($material===null) return ['reason'=>'source_unavailable'];
        vp3_phone_agent_email_call_material_current_v1($pdo,$context,$material);
        $candidate=$material['candidate'];
        foreach (['work_key','semantic_revision','source_kind','source_id','source_revision','item_ref'] as $field)
            if (!is_string($record[$field]??null)||$record[$field]===''||!hash_equals($candidate[$field],$record[$field])) return ['reason'=>'source_changed'];
        foreach (['selected_agent_id','conversation_id'] as $field)
            if ((int)($record[$field]??-1)!==$candidate[$field]) return ['reason'=>'source_changed'];
        foreach (['policy_revision'=>(int)$context['policy']['revision'],'preferences_revision'=>(int)$context['preferences']['revision'],
            'phone_revision'=>(int)$context['preferences']['phone_revision']] as $field=>$value)
            if (isset($record[$field])&&(int)$record[$field]!==$value) return ['reason'=>'policy_changed'];
        $category=$candidate['category']==='draft'?'drafts':'urgent';$policy=$context['policy'];
        if (empty($policy[$category.'_enabled'])) return ['reason'=>'category_disabled'];
        if ($candidate['source_updated_epoch']<=(int)$policy[$category.'_since_epoch']) return ['reason'=>'historical_work'];
        if (in_array($stage,['admit','submit'],true)) {
            if ($candidate['source_updated_epoch']+(int)$policy['grace_seconds']>$now) return ['reason'=>'grace_period'];
            $receipt=vp3_phone_agent_email_call_receipt_reason_v1($pdo,$context,$candidate,$now);
            if ($receipt!==null) return ['reason'=>$receipt];
        }
        if ($proof) {
            $memo=vp3_phone_agent_email_call_memo_v1($pdo,(int)$context['user']['id'],$candidate,$locked);
            if ($memo===null||!hash_equals($memo['source_revision'],$candidate['source_revision'])
                ||!hash_equals($memo['context_revision'],vp3_phone_agent_email_call_context_revision_v1($context))
                ||!is_string($memo['evidence_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$memo['evidence_hash'])
                ||(int)$memo['checked_epoch']>$now||(int)$memo['checked_epoch']<$now-VP3_PHONE_AGENT_EMAIL_CALL_FRESH_SECONDS_V1)
                return ['reason'=>'freshness_required'];
        }
        vp3_phone_agent_email_call_context_current_v1($pdo,$context,$now,$locked);
        return ['reason'=>'eligible','source'=>$candidate];
    } catch (PDOException $error) { throw $error; }
    catch (RuntimeException|DomainException $error) {
        return ['reason'=>$error instanceof VP3PhoneAgentEmailCallException?$error->reason:'source_unavailable'];
    }
}

function vp3_phone_agent_email_call_source_current_v1(PDO $pdo,array $context,array $record,int $now,string $stage): array
{ return vp3_phone_agent_email_call_current_v1($pdo,$context,$record,$now,$stage,true); }

/** Complete incoming-thread absence check. Any later received OR sent message withdraws the old episode. */
function vp3_phone_agent_email_call_incoming_evidence_v1(array $binding,array $messages,string $mailbox): string
{
    $anchor=null;$found=false;$parts=[];
    foreach ($messages as $message) if ($message['message_id']===$binding['message_id']) { $anchor=$message;break; }
    if ($anchor===null||array_intersect(['SENT','DRAFT','SPAM','TRASH'],$anchor['labels'])
        ||agent_connection_gmail_address_v1($anchor['from'])===$mailbox||$anchor['received_at_ms']<1
        ||(isset($binding['in_reply_to'])&&$binding['in_reply_to']!==$anchor['rfc_message_id'])) throw new DomainException('source_changed',409);
    foreach ($messages as $message) {
        $id=$message['message_id'];$at=$message['received_at_ms'];
        if ($at<1||$at>(time()+1)*1000) throw new DomainException('source_changed',409);
        if ($id===$binding['message_id']) $found=true;
        elseif (!array_intersect(['DRAFT','SPAM','TRASH'],$message['labels'])
            &&($at>$anchor['received_at_ms']||($at===$anchor['received_at_ms']&&$found))) throw new DomainException('source_changed',409);
        $parts[]=[$id,$at,$message['labels'],$message['rfc_message_id']];
    }
    return vp3_phone_agent_email_call_hash_v1(['incoming-thread-current-v1',$binding,$parts]);
}

/** Provider calls occur outside the caller's SQL transaction. Only observation hashes/time are persisted. */
function vp3_phone_agent_email_call_refresh_v1(PDO $pdo,array $context,array $record,int $now,?callable $http=null,string $stage='admit'): array
{
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Refresh email-call evidence outside a transaction.');
    $current=vp3_phone_agent_email_call_current_v1($pdo,$context,$record,$now,$stage,false);
    if ($current['reason']!=='eligible') return $current;
    $candidate=$current['source'];$owner=(int)$context['user']['id'];
    try {
        $material=vp3_phone_agent_email_call_material_v1($pdo,$context,$candidate['source_kind'],$candidate['source_id'],$now);
        if ($material===null) return ['reason'=>'source_changed'];
        $guardedHttp=static function(string $method,string $url,array $headers,?array $body,bool $form)use($pdo,$context,$candidate,$http,$stage):array {
            $check=vp3_phone_agent_email_call_current_v1($pdo,$context,$candidate,time(),$stage,false);
            if ($check['reason']!=='eligible') throw new DomainException('source_changed',409);
            return ($http??'agent_connection_gmail_http_v1')($method,$url,$headers,$body,$form);
        };
        if (isset($material['followthrough'])) {
            $bound=$material['followthrough'];
            $source=agent_email_followthrough_source_v1($pdo,$bound['record'],$bound['watch'],$guardedHttp,false);
            if ($source['outbound_followup']!==$material['binding']||$source['subject']!==$material['payload']['subject']
                ||$source['to']!==$material['draft']['recipient']) throw new DomainException('source_changed',409);
            $evidence=vp3_phone_agent_email_call_hash_v1(['followthrough-current-v1',$source['outbound_followup']]);
        } else {
            $access=agent_connections_access_v1($owner,$material['connection_id'],'read',$guardedHttp,$material['authorization_key']);
            $source=agent_connection_gmail_reply_source_v1($access['access_token'],$material['binding']['message_id'],$material['mailbox'],false,$guardedHttp);
            if ($source['reply']['thread_id']!==$material['binding']['thread_id']
                ||(isset($material['payload'])&&($source['reply']!==$material['binding']
                    ||$source['subject']!==$material['payload']['subject']||$source['to']!==$material['draft']['recipient']))) throw new DomainException('source_changed',409);
            $messages=agent_connection_gmail_thread_v1($access['access_token'],$source['reply']['thread_id'],$guardedHttp,true);
            $evidence=vp3_phone_agent_email_call_incoming_evidence_v1($source['reply'],$messages,$material['mailbox']);
        }
        // Observation time is sampled after the provider result, not after a delayed database commit.
        $checked=time();$current=vp3_phone_agent_email_call_current_v1($pdo,$context,$candidate,$checked,$stage,false);
        if ($current['reason']!=='eligible') return $current;
        return vp3_phone_transaction_v1($pdo,static function()use($pdo,$context,$candidate,$checked,$owner,$evidence,$stage):array {
            vp3_phone_agent_email_call_context_current_v1($pdo,$context,$checked,true);
            $current=vp3_phone_agent_email_call_current_v1($pdo,$context,$candidate,$checked,$stage,false);
            if ($current['reason']!=='eligible') return $current;
            $memo=vp3_phone_agent_email_call_memo_v1($pdo,$owner,$candidate,true);
            $contextRevision=vp3_phone_agent_email_call_context_revision_v1($context);
            if ($memo===null) {
                vp3_phone_sql_v1($pdo,'INSERT INTO phone_agent_email_call_decisions_v1
                    (owner_user_id,work_key,semantic_revision,source_kind,source_id,source_revision,item_ref,context_revision,
                     checked_epoch,evidence_hash,created_epoch,updated_epoch) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$owner,$candidate['work_key'],$candidate['semantic_revision'],$candidate['source_kind'],$candidate['source_id'],
                        $candidate['source_revision'],$candidate['item_ref'],$contextRevision,$checked,$evidence,$checked,$checked]);
            } else {
                vp3_phone_sql_v1($pdo,'UPDATE phone_agent_email_call_decisions_v1 SET source_revision=?,item_ref=?,context_revision=?,
                    checked_epoch=?,evidence_hash=?,updated_epoch=? WHERE owner_user_id=? AND work_key=? AND semantic_revision=?',
                    [$candidate['source_revision'],$candidate['item_ref'],$contextRevision,$checked,$evidence,$checked,$owner,$candidate['work_key'],$candidate['semantic_revision']]);
            }
            return ['reason'=>'eligible','source'=>$candidate,'checked_epoch'=>$checked];
        });
    } catch (PDOException $error) { throw $error; }
    catch (RuntimeException|DomainException $error) {
        // A failed fresh observation cannot leave an older successful proof eligible for dialing.
        vp3_phone_sql_v1($pdo,'UPDATE phone_agent_email_call_decisions_v1 SET checked_epoch=0,evidence_hash=NULL,updated_epoch=?
            WHERE owner_user_id=? AND work_key=? AND semantic_revision=? AND source_revision=?',
            [time(),$owner,$candidate['work_key'],$candidate['semantic_revision'],$candidate['source_revision']]);
        return ['reason'=>$error instanceof DomainException?'source_changed':'source_unavailable'];
    }
}

function vp3_phone_agent_email_call_consumed_v1(PDO $pdo,int $owner,string $work): bool
{
    return (bool)vp3_phone_sql_v1($pdo,'SELECT 1 FROM phone_agent_email_call_requests_v1 WHERE owner_user_id=? AND work_key=?'
        .(vp3_phone_transaction_active_v1($pdo)?vp3_phone_lock_v1($pdo):''),[$owner,$work])->fetchColumn();
}

/** A fresh source observation alone never authorizes an Operator request. */
function vp3_phone_agent_email_call_decision_current_v1(PDO $pdo,array $context,array $candidate,int $now): bool
{
    $memo=vp3_phone_agent_email_call_memo_v1($pdo,(int)$context['user']['id'],$candidate,vp3_phone_transaction_active_v1($pdo));
    return $memo!==null&&$memo['decision_state']==='ready'&&$memo['decision']==='request_call'
        &&(int)$memo['dispatched_epoch']>0&&(int)$memo['finished_epoch']>0&&(int)$memo['not_before_epoch']<=$now
        &&hash_equals($memo['source_revision'],$candidate['source_revision'])
        &&is_string($memo['decision_context_revision'])
        &&hash_equals($memo['decision_context_revision'],vp3_phone_agent_email_call_context_revision_v1($context));
}

/** Fixed output vocabulary: the model cannot choose a phone, source, URL, action or approval. */
function vp3_phone_agent_email_call_decision_parse_v1(string $answer): array
{
    require_once __DIR__.'/agent-tool-json-v1.php';
    $value=vp3_tool_json_object_v1($answer,['decision','reason'],2048);
    if (count($value)!==2||!in_array($value['decision']??null,['skip','defer','request_call'],true)
        ||!in_array($value['reason']??null,['timely_owner_action','ready_for_approval','wait_for_review','insufficient_urgency'],true))
        throw new UnexpectedValueException('Invalid email-call usefulness decision.');
    return $value;
}

/** At most one external dispatch for these semantic facts. A defer is not future permission to call. */
function vp3_phone_agent_email_call_decide_v1(PDO $pdo,array $context,array $candidate,int $now,?callable $decision=null,?callable $http=null): array
{
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Decide email-call usefulness outside a transaction.');
    $owner=(int)$context['user']['id'];
    if (vp3_phone_agent_email_call_consumed_v1($pdo,$owner,$candidate['work_key'])) return ['reason'=>'episode_consumed','inferred'=>false];
    $memo=vp3_phone_agent_email_call_memo_v1($pdo,$owner,$candidate);
    if ($memo!==null&&$memo['decision_state']==='ready') {
        if (!is_string($memo['decision_context_revision'])||!hash_equals($memo['decision_context_revision'],
            vp3_phone_agent_email_call_context_revision_v1($context))) return ['reason'=>'decision_context_changed','inferred'=>false];
        if ($memo['decision']!=='request_call') return ['reason'=>'agent_'.$memo['decision'],'inferred'=>false];
        if ((int)$memo['not_before_epoch']>$now) return ['reason'=>'agent_wait','inferred'=>false];
        $fresh=vp3_phone_agent_email_call_refresh_v1($pdo,$context,$candidate,$now,$http);
        return $fresh+['decision'=>$fresh['reason']==='eligible'?'request_call':null,'inferred'=>false];
    }
    if ($memo!==null&&($memo['dispatched_epoch']>0||$memo['decision_state']==='failed')) return ['reason'=>'decision_consumed','inferred'=>false];
    if ($memo!==null&&$memo['decision_state']==='claimed'&&(int)$memo['lease_epoch']>$now) return ['reason'=>'decision_in_progress','inferred'=>false];
    if ($memo!==null&&(int)$memo['not_before_epoch']>$now) return ['reason'=>'decision_wait','inferred'=>false];
    $fresh=vp3_phone_agent_email_call_refresh_v1($pdo,$context,$candidate,$now,$http);
    if ($fresh['reason']!=='eligible') return $fresh+['inferred'=>false];
    $candidate=$fresh['source'];$attempt=bin2hex(random_bytes(16));$dispatched=false;$claimed=false;
    try {
        $claim=vp3_phone_transaction_v1($pdo,static function()use($pdo,$context,$candidate,$owner,$attempt):array {
            $clock=time();vp3_phone_agent_email_call_context_current_v1($pdo,$context,$clock,true);
            $check=vp3_phone_agent_email_call_source_current_v1($pdo,$context,$candidate,$clock,'admit');
            if ($check['reason']!=='eligible') return ['claimed'=>false,'reason'=>$check['reason']];
            if (vp3_phone_agent_email_call_consumed_v1($pdo,$owner,$candidate['work_key'])) return ['claimed'=>false,'reason'=>'episode_consumed'];
            $memo=vp3_phone_agent_email_call_memo_v1($pdo,$owner,$candidate,true);
            if ($memo===null||$memo['decision_state']==='ready'||$memo['dispatched_epoch']>0
                ||($memo['decision_state']==='claimed'&&(int)$memo['lease_epoch']>$clock)) return ['claimed'=>false,'reason'=>'decision_in_progress'];
            vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_decisions_v1 SET decision_state='claimed',attempt_key=?,lease_epoch=?,decision_context_revision=?,
                reason=NULL,updated_epoch=? WHERE owner_user_id=? AND work_key=? AND semantic_revision=?",
                [$attempt,$clock+240,vp3_phone_agent_email_call_context_revision_v1($context),$clock,$owner,$candidate['work_key'],$candidate['semantic_revision']]);
            return ['claimed'=>true];
        });
        if (!$claim['claimed']) return ['reason'=>$claim['reason'],'inferred'=>false];
        $claimed=true;
        $before=static function()use($pdo,$context,$candidate,$owner,$attempt,$http,&$dispatched):void {
            if ($dispatched) throw new DomainException('decision_consumed',409);
            // This runs after the actual model's metering reservation and immediately before its sole dispatch.
            $fresh=vp3_phone_agent_email_call_refresh_v1($pdo,$context,$candidate,time(),$http);
            if ($fresh['reason']!=='eligible') throw new DomainException('source_changed',409);
            vp3_phone_transaction_v1($pdo,static function()use($pdo,$context,$candidate,$owner,$attempt):array {
                $clock=time();vp3_phone_agent_email_call_context_current_v1($pdo,$context,$clock,true);
                $check=vp3_phone_agent_email_call_source_current_v1($pdo,$context,$candidate,$clock,'admit');
                if ($check['reason']!=='eligible'||vp3_phone_agent_email_call_consumed_v1($pdo,$owner,$candidate['work_key'])) throw new DomainException('source_changed',409);
                $memo=vp3_phone_agent_email_call_memo_v1($pdo,$owner,$candidate,true);
                if ($memo===null||$memo['decision_state']!=='claimed'||$memo['attempt_key']!==$attempt
                    ||(int)$memo['lease_epoch']<=$clock||(int)$memo['dispatched_epoch']!==0) throw new DomainException('decision_consumed',409);
                vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_decisions_v1 SET decision_state='dispatched',dispatched_epoch=?,updated_epoch=?
                    WHERE owner_user_id=? AND work_key=? AND semantic_revision=? AND attempt_key=?",
                    [$clock,$clock,$owner,$candidate['work_key'],$candidate['semantic_revision'],$attempt]);
                return ['dispatched'=>true];
            });
            $dispatched=true;
        };
        $preferences=$context['preferences'];
        $prompt=json_encode(['task'=>'email_call_usefulness','now_epoch'=>time(),'facts'=>$candidate['facts'],
            'owner_call_preferences'=>['grace_seconds'=>(int)$context['policy']['grace_seconds'],
                'max_calls_per_day'=>(int)$preferences['max_calls_per_day'],'min_gap_minutes'=>(int)$preferences['min_gap_minutes']],
            'source_status'=>['verified_at'=>$fresh['checked_epoch'],'scope'=>'one_exact_current_thread','unreviewed_after_grace'=>true],
            'delivery'=>'A generic Operator call directs the owner to this exact private Phone Agent item. No email details or send approval are disclosed by this decision.'],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $job=['background'=>true,'phone_agent_email_call_decision'=>true,'conversation_id'=>$candidate['conversation_id'],
            'job_key'=>'email-call:'.$candidate['work_key'].':'.$candidate['semantic_revision']];
        require_once __DIR__.'/agent-initiative-model-v1.php';
        $answer=$decision!==null?$decision($prompt,VP3_PHONE_AGENT_EMAIL_CALL_PROTOCOL_V1,$before,$job,true):
            vp3_initiative_infer_v1($pdo,$context['user'],$candidate['selected_agent_id'],$prompt,VP3_PHONE_AGENT_EMAIL_CALL_PROTOCOL_V1,true,$before,$job);
        if (!$dispatched) throw new DomainException('decision_not_dispatched',409);
        $value=vp3_phone_agent_email_call_decision_parse_v1($answer);
        $after=vp3_phone_agent_email_call_refresh_v1($pdo,$context,$candidate,time(),$http);
        if ($after['reason']!=='eligible') throw new DomainException('source_changed',409);
        return vp3_phone_transaction_v1($pdo,static function()use($pdo,$context,$candidate,$owner,$attempt,$value):array {
            $clock=time();vp3_phone_agent_email_call_context_current_v1($pdo,$context,$clock,true);
            $check=vp3_phone_agent_email_call_source_current_v1($pdo,$context,$candidate,$clock,'admit');
            if ($check['reason']!=='eligible') throw new DomainException('source_changed',409);
            $memo=vp3_phone_agent_email_call_memo_v1($pdo,$owner,$candidate,true);
            if ($memo===null||$memo['decision_state']!=='dispatched'||$memo['attempt_key']!==$attempt
                ||(int)$memo['lease_epoch']<=$clock) throw new DomainException('decision_consumed',409);
            vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_decisions_v1 SET decision_state='ready',decision=?,reason=?,
                not_before_epoch=?,finished_epoch=?,lease_epoch=0,updated_epoch=?
                WHERE owner_user_id=? AND work_key=? AND semantic_revision=? AND attempt_key=?",
                [$value['decision'],$value['reason'],$clock,$clock,$clock,$owner,
                    $candidate['work_key'],$candidate['semantic_revision'],$attempt]);
            return ['reason'=>$value['decision']==='request_call'?'eligible':'agent_'.$value['decision'],
                'decision'=>$value['decision'],'source'=>$candidate,'inferred'=>true];
        });
    } catch (PDOException $error) { throw $error; }
    catch (Throwable $error) {
        $reason=$error instanceof DomainException?'source_changed':($dispatched?'decision_unavailable':'model_unavailable');
        if ($claimed) vp3_phone_transaction_v1($pdo,static function()use($pdo,$owner,$candidate,$attempt,$reason,$dispatched):array {
            vp3_phone_agent_workspace_user_v1($pdo,$owner,true);
            vp3_phone_sql_v1($pdo,'UPDATE phone_agent_email_call_decisions_v1 SET decision_state=?,reason=?,not_before_epoch=?,
                finished_epoch=?,lease_epoch=0,updated_epoch=? WHERE owner_user_id=? AND work_key=? AND semantic_revision=? AND attempt_key=?',
                [$dispatched?'failed':'blocked',$reason,time()+60,time(),time(),$owner,$candidate['work_key'],$candidate['semantic_revision'],$attempt]);
            return ['recorded'=>true];
        });
        return ['reason'=>$reason,'inferred'=>$dispatched];
    }
}

/** Rotating, bounded preparation only. The scheduled Operator worker remains the sole telephone dispatcher. */
function vp3_phone_agent_email_call_sweep_v1(PDO $pdo,int $now,?callable $decision=null,int $limit=10,?callable $http=null): array
{
    if ($now<1||$limit<1||$limit>25) throw new InvalidArgumentException('Choose a bounded email-call sweep.');
    if (vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Email-call sweep requires an independent worker boundary.');
    if (!function_exists('vp3_phone_agent_email_calls_ready_v1')) require_once __DIR__.'/phone-agent-email-calls-settings-v1.php';
    $out=['state'=>'upgrade_required','owners_checked'=>0,'candidates'=>0,'inferences'=>0,'prepared'=>0,'unavailable'=>0];
    if (!vp3_phone_agent_email_calls_ready_v1($pdo)||!vp3_phone_agent_email_call_decisions_ready_v1($pdo)) return $out;
    $out['state']='ready';
    $rows=$pdo->query('SELECT owner_user_id,revision FROM phone_agent_email_call_policies_v1 WHERE urgent_enabled=1 OR drafts_enabled=1
        ORDER BY checked_epoch,owner_user_id LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $policy) {
        $owner=(int)$policy['owner_user_id'];$out['owners_checked']++;$reason='no_pending_work';
        try {
            $context=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now);
            if ((int)$context['policy']['revision']!==(int)$policy['revision']) continue;
            foreach (vp3_phone_agent_email_call_candidates_v1($pdo,$context,$now,40) as $candidate) {
                $out['candidates']++;
                if (vp3_phone_agent_email_call_consumed_v1($pdo,$owner,$candidate['work_key'])) continue;
                $result=vp3_phone_agent_email_call_decide_v1($pdo,$context,$candidate,$now,$decision,$http);
                $reason=$result['reason'];$out['inferences']+=!empty($result['inferred'])?1:0;
                if (($result['decision']??null)==='request_call'&&$reason==='eligible') {
                    $fresh=vp3_phone_agent_email_call_refresh_v1($pdo,$context,$candidate,time(),$http);
                    if ($fresh['reason']!=='eligible') { $reason=$fresh['reason'];break; }
                    require_once __DIR__.'/agent-operator-owner-email-v1.php';
                    $prepared=vp3_operator_owner_email_prepare_v1($pdo,$context,$fresh['source'],time());
                    $reason=(string)($prepared['reason']??'operator_unavailable');
                    if (($prepared['state']??null)==='prepared') $out['prepared']++;
                    break;
                }
                // One new inference per sweep bounds worker cost; cached decisions require no new model work.
                if ($out['inferences']>0) break;
            }
        } catch (PDOException $error) { throw $error; }
        catch (RuntimeException|DomainException $error) {
            $reason=$error instanceof VP3PhoneAgentEmailCallException?$error->reason:'source_unavailable';$out['unavailable']++;
        }
        vp3_phone_sql_v1($pdo,'UPDATE phone_agent_email_call_policies_v1 SET checked_epoch=?,decision_code=?,decision_epoch=?
            WHERE owner_user_id=? AND revision=?',[time(),$reason,time(),$owner,(int)$policy['revision']]);
        if ($out['inferences']>0) break;
    }
    return $out;
}
