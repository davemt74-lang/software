<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/agent-coordination-rules-v1.php';
require_once __DIR__.'/agent-coordination-source-registry-v1.php';
require_once __DIR__.'/agent-coordination-planning-material-v1.php';

/** The same scoped adapters discover new reports and re-read exact saved references. */
function vp3_coordination_sources_v1(PDO $pdo, array $user, array $binding, ?array $selectedRefs = null,
    ?DateTimeImmutable $now = null, bool $usePostedCasual = true, ?string $specialistOnly = null, ?int $officeBefore = null): array
{
    $agent = vp3_initiative_binding_agent_v1($binding);
    $conversation = $binding['conversation_id'] ?? null;
    if (!is_int($conversation) || $conversation < 1
        || (int)($binding['owner_user_id'] ?? $user['id'] ?? 0) !== (int)($user['id'] ?? 0)) {
        throw new RuntimeException('Specialist report access changed.',403);
    }
    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $registry = vp3_coordination_source_registry_v1();
    $selected = [];
    if ($selectedRefs !== null) {
        if (!array_is_list($selectedRefs) || count($selectedRefs) > 24) {
            throw new InvalidArgumentException('Invalid specialist report selection.');
        }
        foreach ($selectedRefs as $ref) {
            if (!is_string($ref) || !vp3_coordination_source_ref_valid_v1($ref)) {
                throw new InvalidArgumentException('Invalid specialist report reference.');
            }
            [$family,$native] = explode(':',$ref,2);
            $selected[$family][$native] = true;
        }
    }
    $read = static function () use ($pdo,$user,$agent,$conversation,$binding,$now,$registry,$selectedRefs,$selected,$usePostedCasual,$specialistOnly,$officeBefore): array {
        $scope = ['verification_only'=>true] + vp3_agent_activity_scope_v1($pdo,$user,$agent,$conversation);
        $out = ['items'=>[],'coverage'=>[]];
        foreach ($registry as $family=>$adapter) {
            $specialist = $adapter['specialist'];
            if ($specialistOnly !== null && $specialistOnly !== $specialist) continue;
            if ($selectedRefs !== null && !isset($selected[$family])) continue;
            if ($selectedRefs === null && ($adapter['sweep'] === 'never'
                || ($adapter['sweep'] === 'enabled' && ($binding['enabled']??false)!==true))) continue;
            $refs = $selectedRefs === null ? null : array_keys($selected[$family]);
            try {
                $result = $adapter['read']($pdo,$scope,$binding,$refs,$now,$officeBefore,$usePostedCasual);
            } catch (Throwable $error) {
                if (!$pdo->inTransaction()||($error instanceof PDOException
                    && (in_array((int)($error->errorInfo[1]??0),[1020,1205,1213],true)||(string)$error->getCode()==='40001'))) throw $error;
                if ($error instanceof Error && function_exists('agent_runtime_v125_trace')) {
                    agent_runtime_v125_trace('initiative.specialist_source_failed',['specialist'=>$specialist,'error_class'=>get_class($error)]);
                }
                $failure = match (true) {
                    $error instanceof PDOException => 'storage_error',
                    $error instanceof TypeError => 'invalid_internal_type',
                    $error instanceof JsonException,$error instanceof InvalidArgumentException => 'invalid_data',
                    $error->getCode()===403 => 'access_changed',
                    $error instanceof Error => 'code_error',
                    default => 'verification_unavailable',
                };
                $result = ['items'=>[],'coverage'=>$failure==='access_changed'?'unconfigured':'unavailable','failure'=>$failure];
            }
            $out['items'] += $result['items'];
            $out['coverage'][$specialist] = $result['coverage'];
            if (isset($result['failure'])) $out['failures'][$specialist] = $result['failure'];
            if (isset($result['offer_gate'])) $out['offer_gates'][$specialist] = $result['offer_gate'];
            if (isset($result['older_page'])) $out['office_page'] = $result['older_page'];
        }
        return $out;
    };
    return vp3_initiative_in_scope_v1($user,$agent,static fn(): array => $pdo->inTransaction()
        ? $read() : agent_commerce_atomic_pa2($pdo,$read));
}

/** Keep aggregate source observations without storing source identifiers or content. */
function vp3_coordination_discovery_summary_v1(array $snapshot): array
{
    $summary = [];
    foreach (vp3_coordination_source_registry_v1() as $adapter) {
        if ($adapter['sweep'] === 'never') continue;
        $specialist = $adapter['specialist'];
        $coverage = $snapshot['coverage'][$specialist]??null;
        if (!in_array($coverage,['ready','limited','unavailable','unconfigured'],true)) continue;
        if (in_array($specialist,['canvas','connected-sites','operator'],true) && $coverage==='unconfigured') continue;
        $summary[$specialist] = ['coverage'=>$coverage,'found'=>0];
        $failure = $snapshot['failures'][$specialist]??null;
        if ($failure !== null) $summary[$specialist]['failure'] = $failure;
        $offerGate = $snapshot['offer_gates'][$specialist]??null;
        if ($offerGate !== null) $summary[$specialist]['offer_gate'] = $offerGate;
    }
    foreach ($snapshot['items'] as $item) {
        $specialist = $item['specialist']??'';
        if (isset($summary[$specialist])) $summary[$specialist]['found']++;
    }
    return $summary;
}

function vp3_coordination_source_ref_valid_v1(string $ref): bool
{
    [$family,$native] = array_pad(explode(':',$ref,2),2,'');
    $pattern = vp3_coordination_source_registry_v1()[$family]['native_ref'] ?? null;
    if($pattern === null || preg_match('/\A(?:'.$pattern.')\z/D',$native) !== 1)return false;
    if($family==='profile'){
        require_once __DIR__.'/agent-profile-specialist-v1.php';
        return vp3_profile_specialist_ref_kind_v1($ref)!==null;
    }
    if($family==='marketing'&&!str_starts_with($native,'campaign-')){
        require_once __DIR__.'/agent-product-marketing-summary-v1.php';
        return vp3_product_marketing_ref_kind_v1($ref)!==null;
    }
    return true;
}

function vp3_coordination_curiosity_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    require_once __DIR__.'/agent-curiosity-v1.php';
    $user=$scope['user'];$agent=$scope['agent_id'];$conversation=$scope['conversation_id'];
    $questions=[];
    $coverage='ready';$offerGate='no_question';
    if($refs===null){
        $settings=vp3_coordination_rules_v1($pdo,$user,$agent);
        if(($settings['rules']['curiosity']['open_question']??null)==='watch'){
            return ['items'=>[],'coverage'=>'ready','offer_gate'=>'report_only'];
        }
        $report=vp3_curiosity_report_v1($pdo,$user,$agent,$conversation,$now->getTimestamp());
        if(is_array($report['next_question']??null))$questions[]=$report['next_question'];
        $coverage=$report['source_coverage'];
        if(($report['attention']??null)==='awaiting_reply')$offerGate='awaiting_reply';
    }else{
        foreach($refs as $native){
            [$thread,$question]=explode(':',$native,2);
            $verified=vp3_curiosity_question_v1($pdo,$user,$agent,$conversation,$thread,$question,$now->getTimestamp());
            if($verified!==null)$questions[]=$verified;
        }
    }
    $out=['items'=>[],'coverage'=>$coverage,'offer_gate'=>$offerGate];
    foreach($questions as $question){
        $ref='curiosity:'.$question['thread_id'].':'.$question['id'];
        if(!vp3_coordination_source_ref_valid_v1($ref))continue;
        $revision=[$question['title'],$question['question']];
        // Attachment threads carry the exact verified review that justified this question.
        if(isset($question['analysis_revision']))$revision[]=$question['analysis_revision'];
        $source=$question['sources'][0]??null;
        if(($source['label']??null)==='View previous answer')$revision[]=[$source['conversation_id'],$source['message_id']];
        $item=vp3_coordination_source_item_v1($ref,'curiosity',$question['title'],
            'Curiosity has a question about '.$question['title'].'.','',$question['question'],'','open','low',
            $revision);
        $item['trigger']='open_question';
        $out['items'][$ref]=$item;
        $out['offer_gate']='candidate';
    }
    return $out;
}

/** A selected Curiosity question uses the same owner Chat as other specialist posts. */
function vp3_coordination_curiosity_gate_v1(PDO $pdo,array $user,array $binding): string
{
    $agent=vp3_initiative_binding_agent_v1($binding);$conversation=$binding['conversation_id'];
    $gate=vp3_initiative_report_gate_v1($pdo,$user,$conversation,$agent);
    if($gate!=='ready')return $gate;
    require_once __DIR__.'/agent-curiosity-v1.php';
    return vp3_curiosity_offer_spacing_v1($pdo,$user,$agent,$conversation);
}

function vp3_coordination_read_canvas_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    require_once __DIR__.'/agent-coordination-canvas-v1.php';
    return vp3_coordination_canvas_sources_v1($pdo,$scope,$refs,$now);
}

function vp3_coordination_casual_sources_v1(PDO $pdo,array $scope,array $binding,array $refs,DateTimeImmutable $now,bool $usePosted): array
{
    require_once __DIR__.'/agent-casual-checkin-v1.php';
    return vp3_casual_checkin_sources_v1($pdo,$scope,$binding,
        array_map(static fn(string $ref):string=>'casual:'.$ref,$refs),$now,usePosted:$usePosted);
}

function vp3_coordination_media_sources_v1(PDO $pdo,array $scope,array $binding,?array $refs,DateTimeImmutable $now): array
{
    require_once __DIR__.'/agent-media-entertainment-v1.php';
    $fullRefs=$refs===null?null:array_map(static fn(string $ref):string=>'media:'.$ref,$refs);
    $report=vp3_media_sources_v1($pdo,$scope,$binding,$fullRefs,$now,true);
    // Offer candidates have their own exact source and stay out of unrelated specialist reports.
    $report['items']=array_filter($report['items'],static fn(array $item):bool=>($item['kind']??null)!=='research');
    if($refs===null&&$report['items']){
        $offerable=array_filter($report['items'],static fn(array $item):bool=>isset($item['trigger']));
        $report['offer_gate']=$offerable?vp3_media_offer_gate_v1($pdo,$scope['user'],$scope['conversation_id']):'report_only';
    }
    return $report;
}

function vp3_coordination_source_hash_v1(array $parts): string
{
    return hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}

function vp3_coordination_source_item_v1(string $ref, string $specialist, string $title, string $summary, string $nextStep,
    string $question, string $url, string $status, string $priority, array $revisionParts): array
{
    return ['ref'=>$ref,'revision'=>vp3_coordination_source_hash_v1([$ref,...$revisionParts]),'specialist'=>$specialist,
        'title'=>vp3_agent_activity_text_v1($title,190),'summary'=>vp3_agent_activity_text_v1($summary,1200),
        'next_step'=>vp3_agent_activity_text_v1($nextStep,320),'question'=>vp3_agent_activity_text_v1($question,320),
        'url'=>$url,'status'=>$status,'priority'=>$priority];
}

/** A verified legacy destination limits the mailbox to its Agent. Otherwise use the owner's active private Chat. */
function vp3_coordination_gmail_chat_authorized_v1(PDO $pdo, array $scope, array $binding, array $mailbox, bool $exact): bool
{
    $agent = $scope['agent_id'];
    $conversation = $scope['conversation_id'];
    if (!agent_connection_gmail_inbox_chat_target_v1($pdo,$mailbox,$agent,$conversation)) return false;
    $destination = agent_connection_gmail_inbox_update_destination_v1($pdo,$mailbox);
    if ($destination !== null) return $destination['agent_id'] === $agent;

    require_once __DIR__.'/agent-initiative-v1.php';
    $policy = vp3_initiative_policy_v1($scope['user'],$agent);
    if ($policy === [] || vp3_initiative_binding_agent_v1($policy) !== $agent
        || ($policy['owner_user_id'] ?? ($agent === 0 ? $mailbox['owner'] : null)) !== $mailbox['owner']) return false;
    // An exact saved work binding can recheck its evidence after a same-Agent
    // Chat switch or pause. Delivery separately checks the current enabled policy.
    if ($exact && ($binding['enabled'] ?? null) === true && is_string($binding['revision'] ?? null)
        && preg_match('/\A[a-f0-9]{32}\z/D',$binding['revision']) === 1) return true;
    return ($policy['enabled'] ?? null) === true && ($policy['conversation_id'] ?? null) === $conversation;
}

function vp3_coordination_gmail_sources_v1(PDO $pdo, array $scope, array $binding, ?array $refs): array
{
    $out = ['items'=>[],'coverage'=>'unconfigured'];
    if (!table_exists('agent_connection_gmail_inbox_v1')) return $out;
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    require_once __DIR__.'/agent-email-running-report-v1.php';
    require_once __DIR__.'/agent-email-triage-v1.php';
    $mailbox = agent_connection_gmail_inbox_bound_scope_v1($pdo,$scope['user']);
    if ($mailbox['state'] !== 'ready') {
        if ($mailbox['state'] === 'unavailable') return ['items'=>[],'coverage'=>'unavailable','failure'=>'storage_unavailable'];
        return $out;
    }
    if (!agent_email_running_report_enabled_v1($mailbox['policy'])) return $out;
    if (empty($mailbox['provider_ready']) || !is_int($mailbox['generation']) || $mailbox['generation'] < 1) {
        return ['items'=>[],'coverage'=>'unavailable','failure'=>'provider_unavailable'];
    }
    if (!agent_email_running_report_ready_v1($pdo) || !agent_email_triage_ready_v1($pdo)) {
        return ['items'=>[],'coverage'=>'unavailable','failure'=>'storage_unavailable'];
    }
    if (!vp3_coordination_gmail_chat_authorized_v1($pdo,$scope,$binding,$mailbox,$refs !== null)) return $out;
    $bound = agent_email_matters_policy_scope_v1($mailbox['policy']);
    if ($bound === null) return $out;
    $out['coverage'] = 'ready';
    $where = "owner_user_id=? AND connection_id=? AND grant_key=? AND finding_enc IS NOT NULL
        AND due_at IS NULL AND failures=0 AND finding_state IN ('open','resolved')";
    $args = $bound;
    if ($refs !== null) {
        $where .= ' AND thread_key IN ('.implode(',',array_fill(0,count($refs),'?')).')';
        array_push($args,...$refs);
    }
    $findings = "SELECT thread_key,source_triage_id,received_ms,finding_enc,finding_state
        FROM agent_email_running_report_v1 WHERE $where";
    $query = $pdo->prepare("$findings ORDER BY finding_priority DESC,analyzed_at DESC,thread_key LIMIT 24");
    $query->execute($args);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if ($refs === null && count($rows) === 24) {
        $out['coverage'] = 'limited';
        // Preserve coverage of recent open mail when older important findings fill the priority window.
        $recent = $pdo->prepare("$findings AND finding_state='open' ORDER BY received_ms DESC,thread_key LIMIT 8");
        $recent->execute($args);
        $rows = array_column($rows,null,'thread_key') + array_column($recent->fetchAll(PDO::FETCH_ASSOC),null,'thread_key');
    }
    foreach ($rows as $row) {
        $item = vp3_coordination_gmail_item_v1($pdo,$mailbox,$bound,$row,$scope['agent_id']);
        if ($item !== null) $out['items'][$item['ref']] = $item;
    }
    if ($refs === null && $out['coverage'] === 'ready') {
        $pending = $pdo->prepare('SELECT 1 FROM agent_email_running_report_v1 WHERE owner_user_id=? AND connection_id=?
            AND grant_key=? AND (due_at IS NOT NULL OR failures>0) LIMIT 1');
        $pending->execute($bound);
        if ($pending->fetchColumn() !== false) $out['coverage'] = 'limited';
    }
    return $out;
}

function vp3_coordination_gmail_item_v1(PDO $pdo, array $mailbox, array $bound, array $row, int $agentId, bool $allowAnchor=true): ?array
{
    $key = $row['thread_key'];
    $finding = json_decode(ai_decrypt_secret($row['finding_enc']),true,8);
    $category = $finding['category'] ?? null;
    $state = $finding['state'] ?? null;
    $summary = $finding['summary'] ?? null;
    if (!in_array($category,['action_required','opportunity','decision'],true) || $state !== $row['finding_state']
        || !is_string($summary) || trim($summary) === '' || !vp3_coordination_source_ref_valid_v1('gmail:'.$key)) return null;

    $evidence = $finding['item_ref'] ?? null;
    if (!is_string($evidence) || !preg_match('/\A[a-f0-9]{64}\z/D',$evidence)) return null;
    $sourceTriage = null;
    foreach (array_unique([$row['source_triage_id'],$evidence]) as $id) {
        $triage = agent_email_triage_find_v1($pdo,$bound,$id);
        if ($triage === null) return null;
        $thread = agent_connection_gmail_id_v1(ai_decrypt_secret((string)$triage['thread_id_enc']));
        $message = agent_connection_gmail_id_v1(ai_decrypt_secret($triage['message_id_enc']));
        if ($thread === '' || $message === '' || !hash_equals($key,agent_email_matters_source_key_v1($mailbox['policy'],'thread',$thread))
            || !hash_equals($id,agent_email_matters_source_key_v1($mailbox['policy'],'triage',$message))
            || agent_connection_gmail_inbox_ignored_v1(['from'=>$triage['sender_address'],'subject'=>$triage['subject']],$mailbox['policy']['rules'])) {
            return null;
        }
        if ($id === $row['source_triage_id']) $sourceTriage = $triage;
    }

    $priority = $finding['priority'] ?? '';
    $priority = in_array($priority,['high','normal','low'],true) ? $priority : 'normal';
    $trigger = $state !== 'open' ? null : match (true) {
        ($finding['handoff'] ?? null) === 'needs_attention' => 'needs_attention',
        $category === 'opportunity' => 'opportunity',
        $priority === 'high' => 'important',
        default => null,
    };
    // Promoting an item already acted on in the digest changes its review priority only.
    // A newer independently verified message has its own triage identity and remains eligible.
    if (($sourceTriage['reason_key'] ?? null) === 'owner_digest_action') $trigger = null;
    if ($trigger !== null && $sourceTriage !== null) {
        // Keep current owner corrections and saved sender preferences effective
        // even when the underlying encrypted analysis was produced earlier.
        if (($sourceTriage['reason_key'] ?? null) === 'owner_correction') {
            $attention = $sourceTriage['attention_key'];
        } elseif (empty($sourceTriage['is_protected'])) {
            $rules = $pdo->prepare('SELECT attention_key FROM agent_email_triage_sender_rules_v1 WHERE owner_user_id=? AND sender_hash=?');
            $rules->execute([$bound[0],agent_email_triage_sender_hash_v1($bound[0],$sourceTriage['sender_address'])]);
            $attention = $rules->fetchColumn();
        } else {
            $attention = null;
        }
        if (in_array($attention,['read_later','informational'],true)) $trigger = null;
    }
    $title = agent_email_matters_metadata_v1($finding['subject'] ?? null,190);
    $next = agent_email_matters_metadata_v1($finding['next_step'] ?? null,320);
    $question = agent_email_matters_metadata_v1($finding['followup_question'] ?? null,320);
    if ($question === '') $question = agent_email_matters_metadata_v1($finding['handoff_question'] ?? null,320);
    $due = agent_email_triage_deadline_v1($sourceTriage['due_at'] ?? null)
        ?? agent_email_triage_date_v1($sourceTriage['due_date'] ?? null);
    $item = vp3_coordination_source_item_v1('gmail:'.$key,'gmail',$title !== '' ? $title : 'Gmail finding',$summary,$next,$question,
        url('/connections.php?app=gmail#gmail-inbox-review'),$state,$priority,
        [$mailbox['generation'],$row['source_triage_id'],(string)$row['received_ms'],$evidence,$category,$state,$priority,$title,$summary,$next,$question])
        + ['trigger'=>$trigger,
            'legacy_delivered'=>$trigger === 'needs_attention' && agent_connection_gmail_content_legacy_delivered_v1($pdo,$mailbox,$agentId,$key,$row['finding_enc']),
            'source_action'=>['type'=>'gmail_triage','item_ref'=>$evidence,'generation'=>$mailbox['generation']]];
    $received = agent_email_matters_received_ms_v1($row['received_ms']);
    if ($received > 0) $item['received_at'] = gmdate(DATE_ATOM,intdiv($received,1000));
    if ($sourceTriage !== null) {
        require_once __DIR__.'/agent-coordination-gmail-material-v1.php';
        $material = vp3_coordination_gmail_material_v1($bound,$sourceTriage,$finding,$item['ref']);
        if ($material) {
            $item['material'] = $material;
            $due ??= $material['deadline_at'] ?? $material['deadline_date'];
        }
    }
    // Triage deadlines are immutable within the exact triage identifier already in the revision.
    if ($due !== null) $item['due_at'] = $due;
    if ($allowAnchor && $trigger !== null && $sourceTriage !== null && isset($finding['coordination_anchor'])) {
        $anchor = vp3_coordination_gmail_anchor_v1($pdo,$bound,$item['ref'],$sourceTriage,$finding,$finding['coordination_anchor']);
        if ($anchor !== null) {
            $original = vp3_coordination_gmail_item_v1($pdo,$mailbox,$bound,$anchor+$row,$agentId,false);
            if (($original['trigger'] ?? null) === $trigger) {
                // The report still reflects the latest message while the posted question retains its exact proof.
                return $original;
            }
        }
    }
    return $item;
}

/** Keep current deadline classifications separate from the saved report revision. */
function vp3_coordination_task_trigger_v1(array $source, array $report, DateTimeImmutable $now): ?string
{
    $record = $source['record'];
    if (!empty($record['assigned_to_someone_else'])) return null;
    $completed = ($report['agent_task']['state'] ?? null) === 'completed'
        && trim((string)($report['agent_task']['result'] ?? '')) !== '';
    if ($source['closed']) return $completed ? 'completed' : null;
    // The report contract already reserves a follow-up question for missing
    // input that changes the next step. Owner attention marks its urgency.
    if (trim((string)($report['followup_question'] ?? '')) !== '') {
        return 'waiting_owner';
    }
    if ($completed) return 'completed';
    if ($source['kind'] === 'reminder') {
        require_once __DIR__.'/agent-catch-up-v1.php';
        $due = vp3_catch_up_timestamp_v1($record['next_delivery_at'] ?? null, new DateTimeZone('UTC'));
        return match (true) {
            $due === null => null,
            $due < $now->getTimestamp() => 'overdue',
            $due <= $now->modify('+2 days')->getTimestamp() => 'due_soon',
            default => null,
        };
    }
    $tasks = $source['kind'] === 'task_list' ? $record['items'] : [$record];
    $blocked = $dueSoon = false;
    foreach ($tasks as $task) {
        if (!empty($task['assigned_to_someone_else'])) continue;
        $attention = vp3_initiative_task_attention_v1($task,$now);
        if (($attention['deadline'] ?? null) === 'overdue') return 'overdue';
        $blocked = $blocked || ($attention['waiting'] ?? false);
        $dueSoon = $dueSoon || in_array($attention['deadline'] ?? null,['due_today','due_soon'],true);
    }
    return $blocked ? 'blocked' : ($dueSoon ? 'due_soon' : null);
}

/** Advance across saved Office workflow rows without loading their private reports yet. */
function vp3_coordination_office_report_page_v1(PDO $pdo, array $scope, ?int $before, int $size, array $seen = []): array
{
    $agent = $scope['agent_id'];
    $agentWhere = $agent > 0 ? 'agent_id=?' : 'agent_id IS NULL';
    $args = [(int)$scope['user']['id']];
    if ($agent > 0) $args[] = $agent;
    $cursorWhere = $before !== null ? 'AND id<?' : '';
    if ($before !== null) $args[] = $before;
    $query = $pdo->prepare("SELECT id,goal FROM agent_workflow_runs
        WHERE owner_user_id=? AND $agentWhere AND source_kind='agent_initiative'
            AND source_key LIKE 'office-entry:%' AND workflow_type='initiative' AND capability_key='initiative.prepare'
            AND execution_target='cloud'
            AND status IN ('completed','approved','queued','executing','paused','failed') $cursorWhere
        ORDER BY id DESC LIMIT 24");
    $query->execute($args);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $refs = [];
    $next = null;
    foreach ($rows as $index=>$row) {
        $goal = json_decode((string)$row['goal'],true);
        $ref = $goal['primary'] ?? null;
        if (($goal['office_entry'] ?? null) === true && is_string($ref) && vp3_coordination_source_ref_valid_v1('office:'.$ref)
            && !isset($seen[$ref])) $refs[$ref] = $ref;
        if (count($refs) === $size || $index === 23) {
            if ($index + 1 < count($rows) || count($rows) === 24) $next = (int)$row['id'];
            break;
        }
    }
    return [$refs,$next];
}

/** Reuse the receipt-backed Tasks workspace projection for each exact entry. */
function vp3_coordination_office_sources_v1(PDO $pdo, array $scope, array $binding, ?array $refs, DateTimeImmutable $now, ?int $before = null): array
{
    require_once __DIR__.'/agent-initiative-office-entry-work-v1.php';
    require_once __DIR__.'/agent-initiative-office-entry-source-v1.php';
    require_once __DIR__.'/agent-initiative-office-entry-projection-v1.php';
    require_once __DIR__.'/agent-job-engine-v1900.php';
    require_once __DIR__.'/agent-coordination-tasks-material-v1.php';
    if (!agent_job_engine_schema_ready_v1900($pdo)) return ['items'=>[],'coverage'=>'unavailable'];
    if (!personal_capability_has_v242('agent_brain.access',$scope['user'])) return ['items'=>[],'coverage'=>'unconfigured'];
    $coverage = 'ready';
    $out = [];
    $candidates = $refs;
    if ($candidates === null) {
        [$candidates,$olderStart] = vp3_coordination_office_report_page_v1($pdo,$scope,null,6);
        if ($olderStart !== null) $coverage = 'limited';
        if ($before !== null && $olderStart !== null) {
            $start = $before > 0 ? min($before,$olderStart) : $olderStart;
            [$older,$next] = vp3_coordination_office_report_page_v1($pdo,$scope,$start,4,$candidates);
            $candidates += $older;
            $out['older_page'] = ['before'=>$start,'next'=>$next,'refs'=>array_map(static fn(string $ref): string=>'office:'.$ref,$older)];
        }
    }
    $records = $cards = [];
    foreach ($candidates as $ref) {
        try {
            $record = vp3_initiative_office_entry_source_v1($pdo,$scope,$binding,$ref);
        } catch (RuntimeException $error) {
            if ($error->getCode() === 403 || $error->getCode() === 404 || $error->getCode() === 409) continue;
            $coverage = 'limited';
            continue;
        }
        if ($record === null) continue;
        $records[$ref] = $record;
        $cards[] = ['id'=>$record['kind']==='task_list'?'list:'.substr($ref,strlen('task-list:')):$ref,
            'kind'=>$record['kind'],'ref'=>$ref];
    }

    $items = [];
    $localNow = vp3_initiative_task_local_time_v1($pdo,$scope,$now);
    foreach (vp3_initiative_office_entry_projections_v1($pdo,$scope,$cards,$now) as $card) {
        $report = $card['office_report'] ?? null;
        if (!is_array($report) || ($report['state'] ?? null) !== 'ready') {
            if (in_array($report['state'] ?? null,['queued','running','failed','stale'],true)) $coverage = 'limited';
            continue;
        }
        $item = vp3_coordination_office_item_v1($scope,$records[$card['ref']],$report,$localNow);
        if ($item !== null) $items[$item['ref']] = $item;
    }
    return ['items'=>$items,'coverage'=>$coverage]+$out;
}

/** Use the same current Office finding for discovery and exact-source feedback. */
function vp3_coordination_office_item_v1(array $scope,array $record,array $report,DateTimeImmutable $localNow): ?array
{
    if (($report['state'] ?? null) !== 'ready' || ($report['office_disposition'] ?? null) === 'note') return null;
    $task = $report['agent_task'] ?? [];
    $child = ($task['state'] ?? null) === 'completed' ? (string)($task['result'] ?? '') : '';
    $summary = (string)($report['summary'] ?? '');
    if ($child !== '') $summary = mb_substr($summary,0,650).' Agent Task result: '.$child;
    if (trim($summary) === '') return null;
    $status = ($record['closed'] ?? false) ? 'resolved' : 'ready';
    $priority = !empty($report['owner_attention']) ? 'high' : 'normal';
    $sourceUrl = $report['chat_action']['href'] ?? vp3_agent_activity_chat_href_v1($scope['agent_id'],$scope['conversation_id']);
    $item = vp3_coordination_source_item_v1('office:'.$record['ref'],'tasks',$record['title'],$summary,
        (string)($report['next_step'] ?? ''),(string)($report['followup_question'] ?? ''),$sourceUrl,$status,$priority,
        [$record['revision'],$summary,$report['next_step'] ?? null,$report['followup_question'] ?? null,$report['action_plan'] ?? null,
            $priority,$status,$child]);
    $trigger = vp3_coordination_task_trigger_v1($record,$report,$localNow);
    if ($trigger !== null) $item['trigger'] = $trigger;
    if ($trigger === 'waiting_owner') {
        if (isset($report['chat_action'])) $item['legacy_delivered'] = true;
        // A revised report can keep the same native facts and owner question.
        // Only feedback may use this key. Actions still require the exact report revision.
        $question = mb_strtolower(rtrim($item['question'],'.!?'),'UTF-8');
        $item['response_revision'] = vp3_coordination_source_hash_v1([$item['ref'],$record['revision'],$question]);
    }
    if ($child !== '') $item['agent_task_result'] = vp3_agent_activity_text_v1($child,750);
    if (($record['limited'] ?? false) === true) $item['limited'] = true;
    if ($record['kind'] === 'task_list' && $status === 'ready' && empty($item['limited'])) {
        $item['member_task_refs'] = [];
        foreach ($record['record']['items'] as $member) {
            if (empty($member['assigned_to_someone_else']) && !in_array($member['status'],['completed','cancelled'],true)) {
                $item['member_task_refs'][] = 'office:task:'.$member['id'];
            }
        }
    }
    if ($status !== 'resolved') {
        $deadline = $record['record']['due_at'] ?? null;
        if ($record['kind'] === 'reminder') {
            require_once __DIR__.'/agent-catch-up-v1.php';
            $time = vp3_catch_up_timestamp_v1($record['record']['next_delivery_at'] ?? null,new DateTimeZone('UTC'));
            $deadline = $time !== null ? gmdate(DATE_ATOM,$time) : null;
        }
        if (is_string($deadline) && $deadline !== '') $item['due_at'] = vp3_agent_activity_text_v1($deadline,40);
    }
    require_once __DIR__.'/agent-coordination-tasks-material-v1.php';
    $material = vp3_coordination_tasks_material_v1($record,$report,$item);
    if ($material !== null) $item['material'] = $material;
    return $item;
}

function vp3_coordination_planning_sources_v1(PDO $pdo, array $scope, ?array $refs, DateTimeImmutable $now, bool $allowStart = false): array
{
    $out = ['items'=>[],'coverage'=>'unconfigured'];
    $coverage = [];
    $readinessRequested = $allowStart && $refs === ['readiness:start'];
    $completeEmpty = ($allowStart && $refs === null) || $readinessRequested;
    $readers = ['calendar'=>'vp3_coordination_calendar_sources_v1','scheduling'=>'vp3_coordination_appointment_sources_v1'];
    foreach ($readers as $prefix=>$read) {
        $selected = $refs === null || $readinessRequested ? null : array_values(array_filter($refs,
            static fn(string $ref): bool => str_starts_with($ref,$prefix.':')));
        if ($selected === []) continue;
        try {
            $result = $read($pdo,$scope,$selected,$now);
        } catch (Exception $error) {
            $result = ['items'=>[],'coverage'=>$error->getCode()===403?'unconfigured':'unavailable'];
        }
        if (!$readinessRequested) $out['items'] += $result['items'];
        $coverage[$prefix] = $result['coverage'];
        if ($result['coverage'] !== 'ready' || $result['items']) $completeEmpty = false;
    }
    if (in_array('limited',$coverage,true) || (in_array('ready',$coverage,true) && in_array('unavailable',$coverage,true))) {
        $out['coverage'] = 'limited';
    } elseif (in_array('ready',$coverage,true)) {
        $out['coverage'] = 'ready';
    } elseif (in_array('unavailable',$coverage,true)) {
        $out['coverage'] = 'unavailable';
    }
    $out['coverage_by_source'] = $coverage;
    $cohort = $timed = vp3_coordination_planning_timed_v1($out['items']);
    if ($timed) {
        if ($refs !== null) {
            // Exact receipt rereads need the same scoped potential overlap
            // as discovery even when they request only one side of the pair.
            foreach ($readers as $prefix=>$read) {
                try {
                    $neighbors = $prefix === 'calendar' ? $read($pdo,$scope,null,$now,false) : $read($pdo,$scope,null,$now);
                    $cohort += vp3_coordination_planning_timed_v1($neighbors['items']);
                } catch (Exception $error) {
                    if ($error->getCode() !== 403) $out['coverage'] = 'limited';
                }
            }
        }
        $out['items'] = vp3_coordination_planning_conflicts_v1($out['items'],$cohort);
    }
    $planningEvidence = $out['items'] + $cohort;
    foreach ($out['items'] as &$item) {
        $material = vp3_coordination_planning_material_v1($item,$planningEvidence);
        if ($material !== null) $item['material'] = $material;
    }
    unset($item);
    if ($completeEmpty) {
        require_once __DIR__.'/agent-initiative-readiness-v1.php';
        [$readiness,$records] = vp3_initiative_readiness_calendar_v1($pdo,$scope['user'],$now,null);
        if ($readiness === 'ready' && ($record = reset($records))) {
            $item = vp3_coordination_source_item_v1(VP3_COORDINATION_PLANNING_START_V1,'planning','Start a plan',
                'There are no events or appointments on your VP3 calendar for the next seven days.',
                '', 'What’s the next thing you’d like to plan?',url('/calendar.php'),'ready','low',[$record['revision']]);
            $out['items'][$item['ref']] = $item+['trigger'=>'start_plan'];
        }
        if ($readinessRequested && $readiness !== 'ready') $out['coverage'] = 'unavailable';
    }
    if ($readinessRequested && in_array('unavailable',$coverage,true)) $out['coverage'] = 'unavailable';
    return $out;
}

function vp3_coordination_planning_timed_v1(array $items): array
{
    return array_filter($items,static fn(array $item): bool => empty($item['all_day'])
        && in_array($item['status'],['ready','confirmed','pending','rescheduled'],true)
        && isset($item['start_at'],$item['end_at']));
}

/** Anchor each confirmed overlap once; keep the other event available as evidence. */
function vp3_coordination_planning_conflicts_v1(array $selected, array $cohort): array
{
    uasort($cohort,static fn(array $a,array $b): int => [$a['start_at'],$a['ref']] <=> [$b['start_at'],$b['ref']]);
    $timed = array_values($cohort);
    $used = [];
    $count = count($timed);
    foreach ($timed as $i=>$left) {
        $anchor = $left['ref'];
        if (isset($used[$anchor])) continue;
        for ($j = $i + 1; $j < $count; ++$j) {
            $right = $timed[$j];
            if ($right['start_at'] >= $left['end_at']) break;
            $other = $right['ref'];
            if (isset($used[$other])) continue;
            $used[$anchor] = $used[$other] = true;
            if (isset($selected[$anchor])) {
                $selected[$anchor]['trigger'] = 'conflict';
                $selected[$anchor]['related_ref'] = $other;
                $selected[$anchor]['summary'] = vp3_agent_activity_text_v1($left['summary'].' It overlaps '.$right['title'].'.',1200);
                $selected[$anchor]['next_step'] = 'Review which commitment can move.';
                $selected[$anchor]['revision'] = vp3_coordination_source_hash_v1([
                    $left['revision'],$other,$right['title'],$right['start_at'],$right['end_at'],
                ]);
            }
            break;
        }
    }
    return $selected;
}

function vp3_coordination_calendar_cancellation_v1(PDO $pdo, array $scope, array $selector, DateTimeImmutable $now): ?array
{
    if (isset($selector['occurrence'])) return null;
    $row = user_calendar_event_v1300($pdo,(int)$scope['user']['id'],$selector['id']);
    if (($row['status'] ?? null) !== 'cancelled') return null;
    $cancelled = agent_commerce_date_pa2($row['cancelled_at'] ?? null);
    if ($cancelled < $now->modify('-7 days')->getTimestamp() || $cancelled > $now->getTimestamp()) return null;
    $ref = 'planning:calendar:event:'.$selector['id'];
    $item = vp3_coordination_source_item_v1($ref,'planning',(string)$row['title'],'The saved calendar event was cancelled.','','',
        url('/calendar.php'),'cancelled','normal',[$row['title'],$row['start_at_utc'],$row['end_at_utc'],$row['cancelled_at']]);
    return $item + ['trigger'=>'changed'];
}

/** The trusted Calendar ledger distinguishes an actual time change from an ordinary edit. */
function vp3_coordination_calendar_time_changed_v1(PDO $pdo, array $scope, array $state, DateTimeImmutable $now): bool
{
    if (!table_exists('agent_event_inbox')) return false;
    $id = (string)$state['id'];
    try {
        $query = $pdo->prepare("SELECT event_type,occurred_at,payload_json FROM agent_event_inbox
            WHERE owner_user_id=? AND source='calendar' AND verification_status='trusted' AND external_event_id LIKE ?
            AND event_type IN ('calendar.event_created','calendar.event_updated') AND occurred_at<=?
            ORDER BY occurred_at DESC,id DESC LIMIT 8");
        $query->execute([(int)$scope['user']['id'],'calendar:'.$id.':calendar.event_%',gmdate('Y-m-d H:i:s',$now->getTimestamp())]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        return false;
    }
    $latest = $rows[0] ?? null;
    if (($latest['event_type'] ?? null) !== 'calendar.event_updated'
        || agent_commerce_date_pa2($latest['occurred_at']) < $now->modify('-7 days')->getTimestamp()) return false;
    $current = [$state['series_start_at_utc'],$state['series_end_at_utc']];
    foreach ($rows as $index=>$row) {
        $payload = json_decode((string)$row['payload_json'],true,16);
        $ref = $payload['object_refs'][0] ?? null;
        if (($payload['record_only'] ?? null) !== true || ($payload['domain_source'] ?? null) !== 'calendar'
            || ($ref['type'] ?? null) !== 'calendar_event' || ($ref['id'] ?? null) !== $id
            || ($ref['scope'] ?? null) !== 'personal') return false;
        $times = [$payload['start_at_utc'] ?? null,$payload['end_at_utc'] ?? null];
        $start = agent_commerce_date_pa2($times[0]);
        if ($start <= 0 || agent_commerce_date_pa2($times[1]) <= $start) return false;
        if ($times !== $current) return $index > 0;
        // The transition must fall inside the freshness window even if a
        // more recent description-only edit has the same start and end.
        if (agent_commerce_date_pa2($row['occurred_at']) < $now->modify('-7 days')->getTimestamp()) return false;
    }
    return false;
}

function vp3_coordination_calendar_sources_v1(PDO $pdo, array $scope, ?array $refs, DateTimeImmutable $now, bool $classifyChanges = true): array
{
    $out = ['items'=>[],'coverage'=>'unavailable'];
    if (!table_exists('user_calendar_events')) return $out;
    require_once __DIR__.'/agent-initiative-office-records-v1.php';
    vp3_initiative_office_load_v1($pdo,'calendar');
    if (!user_calendar_schema_ready_v1300($pdo)) return $out;
    $out['coverage'] = 'ready';
    $windowEnd = $now->modify('+7 days');
    if ($refs === null) {
        [$refs,$limited] = vp3_initiative_office_candidates_v1($pdo,$scope,'calendar',$now);
        if ($limited || count($refs)>8) $out['coverage'] = 'limited';
        $refs = array_slice($refs,0,8);
        if ($classifyChanges && column_exists('user_calendar_events','cancelled_at')) {
            $cancelled = $pdo->prepare("SELECT id FROM user_calendar_events WHERE owner_user_id=? AND status='cancelled'
                AND cancelled_at>=? AND cancelled_at<=? ORDER BY cancelled_at DESC,id DESC LIMIT 5");
            $cancelled->execute([(int)$scope['user']['id'],gmdate('Y-m-d H:i:s',$now->modify('-7 days')->getTimestamp()),
                gmdate('Y-m-d H:i:s',$now->getTimestamp())]);
            $ids = $cancelled->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids)>4) $out['coverage'] = 'limited';
            foreach (array_slice($ids,0,4) as $id) $refs[] = 'calendar:event:'.$id;
        }
    }
    foreach ($refs as $ref) {
        $selector = vp3_initiative_office_ref_v1($ref);
        $state = vp3_initiative_office_calendar_state_v1($pdo,$scope,$selector['id'],$selector['occurrence'] ?? null);
        if ($state === null) {
            $cancelled = $classifyChanges ? vp3_coordination_calendar_cancellation_v1($pdo,$scope,$selector,$now) : null;
            if ($cancelled !== null) $out['items'][$cancelled['ref']] = $cancelled;
            continue;
        }
        $start = user_calendar_validate_utc_v1300($state['start_at_utc']);
        $end = user_calendar_validate_utc_v1300($state['end_at_utc']);
        if ($end <= $now || $start >= $windowEnd) continue;
        $zone = new DateTimeZone($state['timezone']);
        $date = $start->setTimezone($zone)->format($state['all_day']?'D, M j':'D, M j, g:i A T');
        $summary = 'Saved calendar event on '.$date.($state['all_day']?' (all day).':'.');
        if ($state['description'] !== '') $summary .= ' '.mb_substr($state['description'],0,370);
        $prep = $state['prep'] ?? [];
        if (in_array($prep['agenda_status'] ?? '',['available','empty'],true)) {
            $summary .= ' Linked meeting agenda is '.$prep['agenda_status'].'.';
        }
        if (!empty($prep['actionable_items'])) {
            $summary .= ' Verified open meeting work: '.implode(' · ',array_slice($prep['actionable_items'],0,2)).'.';
        }
        if (!empty($prep['completed_items'])) {
            $summary .= ' Verified completed meeting work: '.implode(' · ',array_slice($prep['completed_items'],0,1)).'.';
        }
        $timeChanged = $classifyChanges && vp3_coordination_calendar_time_changed_v1($pdo,$scope,$state,$now);
        if ($timeChanged) $summary = 'The calendar time changed. '.$summary;
        $entry = vp3_initiative_office_entry_v1($ref,$selector,$state);
        $url = $prep['review_url'] ?? url('/calendar-event.php?id='.$selector['id']);
        $item = vp3_coordination_source_item_v1('planning:'.$ref,'planning',$state['title'],$summary,'','',$url,'ready','normal',
            [$entry['revision']]);
        $item['start_at'] = $start->format(DATE_ATOM);
        $item['end_at'] = $end->format(DATE_ATOM);
        $item['timezone'] = $zone->getName();
        if ($state['all_day']) $item['all_day'] = true;
        if ($timeChanged) $item['trigger'] = 'changed';
        elseif (!empty($prep['actionable_items'])) $item['trigger'] = 'followup';
        if ($state['content_truncated']) $item['limited'] = true;
        $out['items'][$item['ref']] = $item;
    }
    return $out;
}

/** Personal Scheduling shares the Agent Chat schedule and keeps booking states distinct from calendar events. */
function vp3_coordination_appointment_sources_v1(PDO $pdo, array $scope, ?array $refs, DateTimeImmutable $now): array
{
    $out = ['items'=>[],'coverage'=>'unconfigured'];
    if (!table_exists('agent_scheduling_schedules')) return $out;
    if (!function_exists('agent_scheduling_schema_ready_v430')) require_once __DIR__.'/agent-scheduling-v430.php';
    require_once __DIR__.'/agent-scheduling-tools-v460.php';
    if (!agent_scheduling_tools_ready_v460($pdo)) return ['items'=>[],'coverage'=>'unavailable'];
    require_once __DIR__.'/user-calendar-v1300.php';
    $schedule = agent_scheduling_tools_schedule_v460($pdo,$scope['user'],$scope['agent_id']);
    if ($schedule === null || ((int)$schedule['agent_id'] > 0 && (int)$schedule['agent_id'] !== $scope['agent_id'])) return $out;
    $owner = (int)$scope['user']['id'];
    $scheduleId = (int)$schedule['id'];
    $utc = $now->setTimezone(new DateTimeZone('UTC'));
    // The canonical booking duration is at most one day. Bound both ends of the indexed start range.
    $where = "b.owner_user_id=? AND b.schedule_id=? AND (b.agent_id IS NULL OR b.agent_id=?) AND b.start_at_utc>=? AND b.start_at_utc<?
        AND ((status IN ('pending','confirmed') AND end_at_utc>?) OR (status IN ('completed','cancelled','no_show') AND end_at_utc>?))";
    $args = [$owner,$scheduleId,$scope['agent_id'],$utc->modify('-8 days')->format('Y-m-d H:i:s'),
        $utc->modify('+7 days')->format('Y-m-d H:i:s'),$utc->format('Y-m-d H:i:s'),
        $utc->modify('-7 days')->format('Y-m-d H:i:s')];
    if ($refs !== null) {
        $where .= ' AND id IN ('.implode(',',array_fill(0,count($refs),'?')).')';
        foreach ($refs as $ref) $args[] = (int)substr($ref,strlen('scheduling:booking:'));
    }
    $hasLifecycle = column_exists('agent_scheduling_bookings','lifecycle_status');
    $status = $hasLifecycle ? 'b.lifecycle_status' : 'NULL AS lifecycle_status';
    $hasReplacement = column_exists('agent_scheduling_bookings','rescheduled_from_id');
    $replacement = $hasReplacement
        ? 'b.rescheduled_from_id,EXISTS(SELECT 1 FROM agent_scheduling_bookings r WHERE r.rescheduled_from_id=b.id
            AND r.owner_user_id=b.owner_user_id AND r.schedule_id=b.schedule_id AND (r.agent_id IS NULL OR r.agent_id=?)) AS replaced'
        : 'NULL AS rescheduled_from_id,0 AS replaced';
    if ($hasReplacement) array_unshift($args,$scope['agent_id']);
    $limit = $refs === null ? 7 : count($refs);
    $query = $pdo->prepare("SELECT id,event_title,guest_name,start_at_utc,end_at_utc,organizer_timezone,status,$status,$replacement
        FROM agent_scheduling_bookings b WHERE $where
        ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'confirmed' THEN 1 ELSE 2 END,start_at_utc,id LIMIT $limit");
    $query->execute($args);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $out['coverage'] = $refs === null && count($rows)>6 ? 'limited' : 'ready';
    if ($refs === null) $rows = array_slice($rows,0,6);
    foreach ($rows as $row) {
        $start = user_calendar_validate_utc_v1300($row['start_at_utc']);
        $end = user_calendar_validate_utc_v1300($row['end_at_utc']);
        if ($end <= $start) continue;
        $zone = new DateTimeZone(agent_scheduling_timezone_v430($row['organizer_timezone']));
        $rawStatus = $row['status'];
        $status = $rawStatus === 'confirmed' && ($row['lifecycle_status'] === 'rescheduled' || (int)$row['rescheduled_from_id'] > 0)
            ? 'rescheduled' : $rawStatus;
        $label = str_replace('_',' ',$status);
        $guest = vp3_agent_activity_text_v1($row['guest_name'],100);
        $summary = ucfirst($label).' appointment'.($guest !== '' ? ' with '.$guest : '').' on '
            .$start->setTimezone($zone)->format('D, M j, g:i A T').'.';
        $next = $status === 'pending' ? 'Check what is still needed to confirm the appointment.' : '';
        $priority = $status === 'pending' ? 'high' : (in_array($rawStatus,['pending','confirmed'],true) ? 'normal' : 'low');
        $id = (int)$row['id'];
        $ref = 'planning:scheduling:booking:'.$id;
        $item = vp3_coordination_source_item_v1($ref,'planning',$row['event_title'],$summary,$next,'',
            url('/scheduling.php?workspace_booking='.$id.'&schedule='.$scheduleId.'&tab=bookings#booking-'.$id),$status,$priority,
            [$scheduleId,$row['event_title'],$guest,$row['start_at_utc'],$row['end_at_utc'],$zone->getName(),$status]);
        $item['start_at'] = $start->format(DATE_ATOM);
        $item['end_at'] = $end->format(DATE_ATOM);
        $item['timezone'] = $zone->getName();
        if ($status === 'pending') $item['trigger'] = 'pending_confirmation';
        elseif ($status === 'rescheduled' || ($status === 'cancelled' && empty($row['replaced']))) $item['trigger'] = 'changed';
        $out['items'][$ref] = $item;
    }
    return $out;
}
