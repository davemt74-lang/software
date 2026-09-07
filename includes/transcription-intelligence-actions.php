<?php
declare(strict_types=1);

/**
 * VP3 transcription intelligence operational actions v303.
 *
 * Generated AI output never mutates another subsystem by itself. Only a
 * human-accepted intelligence item can be explicitly promoted into Main Chat,
 * Agent Brain, task lifecycle, Personal Knowledge, CRM or project notes.
 */
const VP3_TRANSCRIPTION_INTELLIGENCE_ACTIONS_V303 = 'transcription-intelligence-actions-v303-20260906';

function transcription_intelligence_item_snapshot_v303(array $master, string $appId, string $itemId): array
{
    $modules = transcription_app_modules_v301(is_array($master['analysis'] ?? null) ? $master['analysis'] : [], $master);
    $modules = transcription_intelligence_normalize_modules_v302($modules);
    [$sectionKey,$offset,$primary] = transcription_intelligence_find_item_v302($modules,$appId,$itemId);
    $item = $modules[$appId]['result'][$sectionKey][$offset] ?? null;
    if (!is_array($item)) throw new RuntimeException('Transcription intelligence item not found.');
    $text = transcription_app_clean_v300((string)($item[$primary] ?? ''), 1400);
    if ($text === '') throw new RuntimeException('This intelligence item has no actionable text.');
    return [
        'modules'=>$modules,'section_key'=>$sectionKey,'offset'=>$offset,'primary'=>$primary,
        'item'=>$item,'text'=>$text,
    ];
}

function transcription_intelligence_require_accepted_v303(array $item): void
{
    if ((string)($item['review_state'] ?? '') !== 'accepted') {
        throw new RuntimeException('Accept or edit this intelligence item before taking an operational action.');
    }
}

function transcription_intelligence_evidence_label_v303(array $item): string
{
    $labels = [];
    foreach ((array)($item['evidence_refs'] ?? []) as $ref) {
        if (!is_array($ref)) continue;
        $label = trim((string)($ref['label'] ?? ''));
        if ($label !== '') $labels[$label] = $label;
    }
    return implode(', ', array_values($labels));
}

function transcription_intelligence_action_key_v303(string $action, int $targetId = 0): string
{
    $action = strtolower(trim($action));
    if (!preg_match('/^[a-z_]{2,40}$/', $action)) throw new RuntimeException('Unsupported transcription intelligence action.');
    return $action . ($targetId > 0 ? ':'.$targetId : '');
}

function transcription_intelligence_existing_receipt_v303(array $item, string $actionKey): ?array
{
    $actions = is_array($item['actions'] ?? null) ? $item['actions'] : [];
    $receipt = $actions[$actionKey] ?? null;
    return is_array($receipt) ? $receipt : null;
}

function transcription_intelligence_record_receipt_v303(
    PDO $pdo,
    int $sessionId,
    array $master,
    string $appId,
    string $itemId,
    string $actionKey,
    array $receipt
): array {
    $snapshot = transcription_intelligence_item_snapshot_v303($master,$appId,$itemId);
    $modules = $snapshot['modules'];
    $sectionKey = (string)$snapshot['section_key'];
    $offset = (int)$snapshot['offset'];
    $item =& $modules[$appId]['result'][$sectionKey][$offset];
    $actions = is_array($item['actions'] ?? null) ? $item['actions'] : [];
    $receipt['action_key'] = $actionKey;
    $receipt['performed_at'] = (string)($receipt['performed_at'] ?? gmdate('c'));
    $actions[$actionKey] = transcription_app_sanitize_value_v300($receipt);
    $item['actions'] = $actions;
    $master['analysis'] = transcription_intelligence_persist_modules_v302($pdo,$sessionId,$modules);
    return $master;
}

function transcription_intelligence_crm_targets_v303(PDO $pdo, array $user, array $session): array
{
    if (!function_exists('transcription_app_crm_context_v301')) return [];
    $segments = artist_listening_v172_segments($pdo,(int)$session['id']);
    $context = transcription_app_crm_context_v301($pdo,$user,$session,$segments);
    if (empty($context['available']) || empty($context['matched'])) return [];
    $targets = [];
    foreach ((array)($context['contacts'] ?? []) as $contact) {
        if (!is_array($contact)) continue;
        $leadId = max(0,(int)($contact['lead_id'] ?? 0));
        if ($leadId < 1) continue;
        $targets[] = [
            'lead_id'=>$leadId,
            'contact_id'=>max(0,(int)($contact['contact_id'] ?? 0)),
            'name'=>transcription_app_clean_v300((string)($contact['name'] ?? ''),120),
            'company'=>transcription_app_clean_v300((string)($contact['company'] ?? ''),190),
            'email'=>strtolower(trim((string)($contact['email'] ?? ''))),
            'lead_stage'=>(string)($contact['lead_stage'] ?? ''),
        ];
    }
    return $targets;
}

function transcription_intelligence_project_context_v303(PDO $pdo, array $user, array $session): array
{
    $trackId = max(0,(int)($session['project_track_id'] ?? 0));
    if ($trackId < 1 || !function_exists('artist_listening_v172_track_allowed') || !artist_listening_v172_track_allowed($pdo,$user,$trackId)) {
        return ['available'=>false,'track_id'=>0,'title'=>''];
    }
    $allowed = has_permission('track_notes.manage',$user) || has_permission('tracks.manage',$user) || has_permission('producer.access',$user);
    if (!$allowed || !table_exists('track_notes')) return ['available'=>false,'track_id'=>0,'title'=>''];
    $title = '';
    try {
        $stmt=$pdo->prepare('SELECT title FROM tracks WHERE id=? LIMIT 1');
        $stmt->execute([$trackId]);
        $title=transcription_app_clean_v300((string)$stmt->fetchColumn(),190);
    } catch (Throwable $e) {}
    return ['available'=>true,'track_id'=>$trackId,'title'=>$title];
}

function transcription_intelligence_operational_context_v303(PDO $pdo, array $user, array $session): array
{
    $permissions = transcription_app_permissions_v300($user);
    $chat = has_permission('chat.access',$user) && function_exists('agent_chat_v101_append_ecosystem_message');
    $brain = !empty($permissions['agent_brain_write']) && agent_brain_schema_ready() && function_exists('agent_brain_v122_upsert_system_memory');
    $knowledge = !empty($permissions['personal_knowledge_write']) && function_exists('personal_knowledge_available')
        && personal_knowledge_available($user) && function_exists('personal_knowledge_store');
    $project = transcription_intelligence_project_context_v303($pdo,$user,$session);
    $crmTargets = function_exists('crm_v180_can_manage') && crm_v180_can_manage($user) && function_exists('crm_v180_schema_ready') && crm_v180_schema_ready($pdo)
        ? transcription_intelligence_crm_targets_v303($pdo,$user,$session) : [];
    return [
        'main_chat'=>['available'=>$chat,'url'=>function_exists('url')?url('/chat.php'):'/chat.php'],
        'agent_brain'=>['available'=>$brain],
        'agent_task'=>['available'=>$brain && table_exists('agent_memory_items')],
        'personal_knowledge'=>['available'=>$knowledge],
        'project_note'=>$project,
        'crm'=>['available'=>(bool)$crmTargets,'targets'=>$crmTargets],
    ];
}

function transcription_intelligence_task_kind_v303(string $appId, string $sectionKey): string
{
    return $appId === 'decisions' && $sectionKey === 'commitments' ? 'commitment' : 'task';
}

function transcription_intelligence_upsert_task_v303(
    PDO $pdo,
    array $user,
    array $session,
    string $appId,
    string $sectionKey,
    array $item,
    string $text
): int {
    if (!agent_brain_schema_ready() || !table_exists('agent_memory_items')) throw new RuntimeException('Agent task storage is unavailable.');
    $uid=max(0,(int)($user['id'] ?? 0));
    if ($uid < 1) throw new RuntimeException('Sign in before creating an Agent task.');
    $kind=transcription_intelligence_task_kind_v303($appId,$sectionKey);
    $itemId=(string)($item['item_id'] ?? '');
    $memoryHash=sha1('transcription-intelligence|'.$uid.'|'.$kind.'|'.$itemId);
    $find=$pdo->prepare('SELECT id,metadata_json FROM agent_memory_items WHERE user_id=? AND memory_hash=? LIMIT 1');
    $find->execute([$uid,$memoryHash]);
    $existingRow=$find->fetch()?:null;

    $evidence=transcription_intelligence_evidence_label_v303($item);
    $body=$text . ($evidence !== '' ? "\nEvidence: ".$evidence : '');
    $priorMeta=is_array($existingRow) ? json_decode((string)($existingRow['metadata_json'] ?? ''),true) : [];
    if (!is_array($priorMeta)) $priorMeta=[];
    $priorStatus=(string)($priorMeta['task_status'] ?? 'open');
    if (!in_array($priorStatus,['open','in_progress','waiting','completed','cancelled'],true)) $priorStatus='open';
    $meta=$priorMeta + [
        'source_kind'=>'transcription_intelligence','source_session_id'=>(int)$session['id'],
        'plugin_id'=>$appId,'section_key'=>$sectionKey,'item_id'=>$itemId,
        'evidence_refs'=>(array)($item['evidence_refs'] ?? []),'task_status'=>$priorStatus,'task_kind'=>$kind,
        'task_key'=>sha1('transcription-intelligence|'.$itemId),'timing'=>(string)($item['timing'] ?? ''),
        'priority'=>(string)($item['priority'] ?? ''),'source_title'=>(string)($session['title'] ?? ''),
        'created_from_reviewed_item'=>true,'created_at'=>gmdate('c'),
    ];
    foreach ([
        'source_kind'=>'transcription_intelligence','source_session_id'=>(int)$session['id'],'plugin_id'=>$appId,
        'section_key'=>$sectionKey,'item_id'=>$itemId,'evidence_refs'=>(array)($item['evidence_refs'] ?? []),
        'task_kind'=>$kind,'timing'=>(string)($item['timing'] ?? ''),'priority'=>(string)($item['priority'] ?? ''),
        'source_title'=>(string)($session['title'] ?? ''),'created_from_reviewed_item'=>true,
    ] as $key=>$value) $meta[$key]=$value;
    $meta['task_status']=$priorStatus;
    $meta['updated_from_reviewed_item']=true;
    $meta['updated_at']=gmdate('c');
    $json=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

    if (is_array($existingRow) && (int)($existingRow['id'] ?? 0) > 0) {
        $id=(int)$existingRow['id'];
        $update=$pdo->prepare(
            'UPDATE agent_memory_items SET subject=?,memory_text=?,confidence=GREATEST(confidence,?),last_seen_at=NOW(),is_active=1,metadata_json=? WHERE id=? AND user_id=?'
        );
        $update->execute([
            mb_strimwidth($text,0,190,'…'),mb_strimwidth($body,0,6000,'…'),0.99,
            is_string($json)?$json:'{}',$id,$uid
        ]);
        return $id;
    }

    $stmt=$pdo->prepare(
        'INSERT INTO agent_memory_items
         (user_id,memory_type,subject,memory_text,memory_hash,source_archive_id,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
         VALUES (?,?,?,?,?,NULL,?,1,NOW(),NOW(),1,?)'
    );
    $stmt->execute([
        $uid,$kind,mb_strimwidth($text,0,190,'…'),mb_strimwidth($body,0,6000,'…'),$memoryHash,
        0.99,is_string($json)?$json:'{}'
    ]);
    $id=(int)$pdo->lastInsertId();
    if ($id < 1) throw new RuntimeException('Could not create the Agent task.');
    return $id;
}

function transcription_intelligence_validate_crm_target_v303(array $targets, int $leadId): array
{
    foreach ($targets as $target) {
        if (is_array($target) && (int)($target['lead_id'] ?? 0) === $leadId) return $target;
    }
    throw new RuntimeException('Choose an explicitly matched CRM lead for this transcript.');
}

function transcription_intelligence_execute_action_v303(
    PDO $pdo,
    array $user,
    array $session,
    array $master,
    string $appId,
    string $itemId,
    string $action,
    int $targetId = 0
): array {
    $allowedActions=['main_chat','agent_brain','agent_task','personal_knowledge','project_note','crm_note','crm_task'];
    $action=strtolower(trim($action));
    if (!in_array($action,$allowedActions,true)) throw new RuntimeException('Unsupported transcription intelligence action.');

    $snapshot=transcription_intelligence_item_snapshot_v303($master,$appId,$itemId);
    $item=$snapshot['item'];
    transcription_intelligence_require_accepted_v303($item);
    $sectionKey=(string)$snapshot['section_key'];
    $text=(string)$snapshot['text'];
    $operations=transcription_intelligence_operational_context_v303($pdo,$user,$session);
    if ($action === 'project_note') $targetId=max(0,(int)($operations['project_note']['track_id'] ?? 0));
    $actionKey=transcription_intelligence_action_key_v303($action,$targetId);
    $existing=transcription_intelligence_existing_receipt_v303($item,$actionKey);
    if ($existing) {
        return ['master'=>$master,'receipt'=>$existing,'existing'=>true,'operations'=>$operations];
    }

    $registry=transcription_app_registry_v301();
    $appTitle=(string)($registry[$appId]['title'] ?? $appId);
    $evidence=transcription_intelligence_evidence_label_v303($item);
    $sourceLabel=trim((string)($session['title'] ?? 'Transcript')) ?: 'Transcript';
    $receipt=['action'=>$action,'record_id'=>0,'target_id'=>$targetId,'label'=>'','target_url'=>'','performed_at'=>gmdate('c')];

    if ($action === 'main_chat') {
        if (empty($operations['main_chat']['available'])) throw new RuntimeException('Main AI Chat is unavailable for this account.');
        $message='Reviewed transcription intelligence from “'.$sourceLabel.'”. '.$appTitle.': '.$text;
        if ($evidence !== '') $message .= ' Evidence: '.$evidence.'.';
        $conversationId=agent_chat_v101_append_ecosystem_message($user,$message,[
            'source'=>'transcription_intelligence','session_id'=>(int)$session['id'],'plugin_id'=>$appId,
            'section_key'=>$sectionKey,'item_id'=>$itemId,'evidence_refs'=>(array)($item['evidence_refs'] ?? []),
            'review_state'=>'accepted','target_url'=>url('/artist-listening.php'),
        ]);
        if ($conversationId < 1) throw new RuntimeException('Could not send this intelligence item to Main AI Chat.');
        $receipt['record_id']=$conversationId;$receipt['label']='Sent to Main Chat';$receipt['target_url']=(string)$operations['main_chat']['url'];
    } elseif ($action === 'agent_brain') {
        if (empty($operations['agent_brain']['available'])) throw new RuntimeException('Agent Brain is unavailable for this account.');
        $memoryId=agent_brain_v122_upsert_system_memory(
            $user,'transcript_intelligence','transcription-item:'.$itemId,$text,
            ['source'=>'transcription_intelligence','session_id'=>(int)$session['id'],'plugin_id'=>$appId,'section_key'=>$sectionKey,
             'item_id'=>$itemId,'evidence_refs'=>(array)($item['evidence_refs'] ?? []),'review_state'=>'accepted','source_title'=>$sourceLabel,'saved_at'=>gmdate('c')],
            0.99
        );
        if ($memoryId < 1) throw new RuntimeException('Could not save this item to Agent Brain.');
        $receipt['record_id']=$memoryId;$receipt['label']='Saved to Agent Brain';
    } elseif ($action === 'agent_task') {
        if (empty($operations['agent_task']['available'])) throw new RuntimeException('Agent task storage is unavailable for this account.');
        $memoryId=transcription_intelligence_upsert_task_v303($pdo,$user,$session,$appId,$sectionKey,$item,$text);
        $kind=transcription_intelligence_task_kind_v303($appId,$sectionKey);
        $receipt['record_id']=$memoryId;$receipt['label']=$kind === 'commitment' ? 'Created Agent commitment' : 'Created Agent task';
    } elseif ($action === 'personal_knowledge') {
        if (empty($operations['personal_knowledge']['available'])) throw new RuntimeException('Personal Knowledge is unavailable for this account.');
        $knowledgeId=personal_knowledge_store(
            $user,'transcription-intelligence-item:'.$itemId,
            mb_strimwidth($appTitle.' · '.$sourceLabel,0,190,'…'),$text,
            'Reviewed transcription intelligence · '.$appTitle.' · '.($evidence !== '' ? $evidence : 'source transcript')
        );
        $receipt['record_id']=$knowledgeId;$receipt['label']='Saved to Personal Knowledge';
    } elseif ($action === 'project_note') {
        $project=$operations['project_note'] ?? [];
        if (empty($project['available']) || $targetId < 1) throw new RuntimeException('No writable project track is linked to this transcript.');
        $note='[Reviewed transcription intelligence · '.$appTitle."]\n".$text;
        if ($evidence !== '') $note .= "\nEvidence: ".$evidence;
        $stmt=$pdo->prepare('INSERT INTO track_notes (track_id,user_id,note) VALUES (?,?,?)');
        $stmt->execute([$targetId,(int)$user['id'],mb_strimwidth($note,0,65000,'…')]);
        $noteId=(int)$pdo->lastInsertId();
        if ($noteId < 1) throw new RuntimeException('Could not create the project note.');
        $receipt['record_id']=$noteId;$receipt['label']='Added project note';
    } elseif ($action === 'crm_note' || $action === 'crm_task') {
        if (empty($operations['crm']['available'])) throw new RuntimeException('No explicitly matched CRM lead is available for this transcript.');
        $targets=(array)($operations['crm']['targets'] ?? []);
        $target=transcription_intelligence_validate_crm_target_v303($targets,$targetId);
        $leadId=(int)$target['lead_id'];
        if ($action === 'crm_note') {
            $activityId=crm_v180_activity($pdo,$leadId,'transcript_intelligence',$text,(int)$user['id'],[
                'source_session_id'=>(int)$session['id'],'plugin_id'=>$appId,'section_key'=>$sectionKey,
                'item_id'=>$itemId,'evidence_refs'=>(array)($item['evidence_refs'] ?? []),'review_state'=>'accepted',
            ]);
            if ($activityId < 1) throw new RuntimeException('Could not add the CRM note.');
            $receipt['record_id']=$activityId;$receipt['label']='Added CRM note';
        } else {
            $taskId=crm_v180_create_task($pdo,$leadId,[
                'title'=>$text,'task_type'=>'follow_up','assigned_user_id'=>0,'due_at'=>'',
            ],(int)$user['id']);
            if ($taskId < 1) throw new RuntimeException('Could not create the CRM follow-up task.');
            $receipt['record_id']=$taskId;$receipt['label']='Created CRM task';
        }
        $receipt['target_id']=$leadId;$receipt['target_url']=url('/admin/crm-lead.php?id='.$leadId);
    }

    $master=transcription_intelligence_record_receipt_v303($pdo,(int)$session['id'],$master,$appId,$itemId,$actionKey,$receipt);
    return ['master'=>$master,'receipt'=>$receipt,'existing'=>false,'operations'=>$operations];
}
