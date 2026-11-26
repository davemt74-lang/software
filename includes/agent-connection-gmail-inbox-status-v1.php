<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-chat-delivery-v1.php';

function agent_connection_gmail_inbox_status_time_v1(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) return null;
    $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $time && $time->format('Y-m-d H:i:s') === $value ? $time : null;
}

/** Give the normally scheduled worker two minutes, or wait for an existing lease to expire. */
function agent_connection_gmail_inbox_due_overdue_v1(?DateTimeImmutable $due, bool $claimed, DateTimeImmutable $now): bool
{
    return $due !== null && $due->getTimestamp() + ($claimed ? 0 : 120) <= $now->getTimestamp();
}

function agent_connection_gmail_inbox_overdue_ref_v1(array $policy): string
{
    return agent_connection_gmail_inbox_key_v1($policy,'overdue-check-v1:'.$policy['revision'].':'.$policy['next_check_at'].':'.$policy['claim_key']);
}

/** Offer one due thread or one initial catch-up for a previously opted-in account. */
function agent_connection_gmail_inbox_content_work_v1(PDO $pdo,array $scope,DateTimeImmutable $now): ?array
{
    $policy = $scope['policy'];
    if (!$scope['provider_ready'] || $scope['generation'] === null
        || !agent_email_running_report_enabled_v1($policy) || !agent_email_running_report_ready_v1($pdo)) return null;
    $bound = agent_email_matters_policy_scope_v1($policy);
    if ($bound === null) return null;
    $query = $pdo->prepare('SELECT r.* FROM agent_email_running_report_v1 r
        WHERE r.owner_user_id=? AND r.connection_id=? AND r.grant_key=? AND r.due_at<=?
        AND NOT EXISTS (SELECT 1 FROM agent_email_running_report_v1 active
            WHERE active.owner_user_id=r.owner_user_id AND active.connection_id=r.connection_id
                AND active.grant_key=r.grant_key AND active.lease_key IS NOT NULL AND active.due_at>?)
        ORDER BY r.importance DESC,r.due_at,r.thread_key LIMIT 1');
    $query->execute([...$bound,$now->modify('-120 seconds')->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s')]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $ref = agent_connection_gmail_inbox_key_v1($policy,implode(':',[
            'overdue-content-v1',$policy['revision'],$row['thread_key'],$row['source_triage_id'],$row['due_at'],
        ]));
        return ['ref'=>$ref,'row'=>$row];
    }
    $sync = agent_connection_gmail_inbox_sync_row_v1($pdo,$policy);
    if (!agent_connection_gmail_inbox_content_unseeded_v1($pdo,$policy,$sync,$now)) return null;
    return ['ref'=>agent_connection_gmail_inbox_key_v1($policy,'bootstrap-content-v1:'.$policy['revision']),'row'=>null];
}

/** Check only this owner's overdue review. Stale requests cannot advance a different checkpoint. */
function agent_connection_gmail_inbox_check_overdue_v1(PDO $pdo, array $user, string $checkRef, ?callable $http=null, ?array $chatTarget=null, ?callable $model=null): void
{
    if ($pdo->inTransaction()) return;
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if ($scope['state'] !== 'ready' || !$scope['provider_ready'] || $scope['generation'] === null) return;
    $policy = $scope['policy'];
    $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
    if (!hash_equals(agent_connection_gmail_inbox_overdue_ref_v1($policy),$checkRef)) {
        if ($chatTarget === null || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,
            $chatTarget['agent_id'],$chatTarget['conversation_id'])) return;
        $report = agent_connection_gmail_inbox_content_work_v1($pdo,$scope,$now);
        if ($report !== null && hash_equals($report['ref'],$checkRef)) {
            if ($report['row'] === null) {
                agent_connection_gmail_inbox_analyze_recent_v1($pdo,$user,$chatTarget['agent_id'],$chatTarget['conversation_id'],
                    $http,$model,$policy['revision']);
            } else {
                agent_email_running_report_process_v1($pdo,$report['row'],$http,$model,$policy['revision']);
            }
        }
        return;
    }
    if (!agent_connection_gmail_inbox_due_overdue_v1(agent_connection_gmail_inbox_status_time_v1($policy['next_check_at']),
            !empty($policy['claim_key']),$now)
        || !agent_connection_gmail_inbox_receipts_ready_v1($pdo) || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) return;

    $checked = agent_connection_gmail_inbox_run_due_v1($pdo,$policy,$http);
    if ($checked !== null && agent_email_running_report_enabled_v1($policy)) {
        agent_email_running_report_sweep_v1($pdo,$http,$model,(int)$scope['owner'],$policy['revision']);
    }
}

/** Read-only projection of the current owner's recurring review, independent of first review. */
function agent_connection_gmail_inbox_background_status_v1(
    PDO $pdo, array $user, ?int $agentId=null, ?DateTimeImmutable $now=null, bool $includeResults=true
): ?array {
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if ($scope['state'] !== 'ready') return null;
    return agent_connection_gmail_inbox_background_view_v1($pdo,$scope,$agentId,$now,$includeResults);
}

/** Keep work state independent of the cursor and the last completed inbox scan. */
function agent_connection_gmail_inbox_analysis_work_v1(PDO $pdo,array $scope,DateTimeImmutable $now): ?array
{
    $policy=$scope['policy'];
    if(!$scope['provider_ready']||$scope['generation']===null||!agent_email_running_report_enabled_v1($policy)
        ||!agent_email_running_report_ready_v1($pdo))return null;
    $bound=agent_email_matters_policy_scope_v1($policy);
    if($bound===null)return null;
    $query=$pdo->prepare('SELECT MAX(CASE WHEN lease_key IS NOT NULL AND due_at>? THEN due_at END) AS lease_until,
        MAX(CASE WHEN due_at IS NOT NULL THEN 1 ELSE 0 END) AS pending,
        MAX(CASE WHEN due_at IS NULL AND failures=0 AND finding_enc IS NOT NULL THEN analyzed_at END) AS latest
        FROM agent_email_running_report_v1 WHERE owner_user_id=? AND connection_id=? AND grant_key=?');
    $query->execute([$now->format('Y-m-d H:i:s'),...$bound]);
    $state=$query->fetch(PDO::FETCH_ASSOC);
    $lease=agent_connection_gmail_inbox_status_time_v1($state['lease_until']??null);
    if($lease!==null)return ['state'=>'working','lease_until'=>$lease->format('Y-m-d\TH:i:s\Z')];
    if((int)($state['pending']??0)>0)return ['state'=>'queued','lease_until'=>null];
    $latest=agent_connection_gmail_inbox_status_time_v1($state['latest']??null);
    return $latest!==null&&$latest<=$now&&$latest>$now->modify('-120 seconds')
        ?['state'=>'ready','lease_until'=>null]:null;
}

/** Recent real arrivals stay visible after empty checks, even when triage sent no alert. */
function agent_connection_gmail_inbox_latest_arrivals_v1(PDO $pdo,array $scope): ?array
{
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    $policy = $scope['policy'];
    $raw = (string)($policy['last_review_json'] ?? '');
    $report = agent_connection_gmail_inbox_batch_report_v1($raw);
    $id = null;
    if ($report !== null) {
        $candidate = agent_connection_gmail_inbox_batch_id_v1($policy,$raw);
        if (agent_connection_gmail_inbox_batch_v1($pdo,$scope,$candidate) !== null) $id = $candidate;
    } else {
        $query = $pdo->prepare('SELECT batch_id,report_json FROM agent_connection_gmail_inbox_batches_v1
            WHERE owner_user_id=? AND connection_id=? AND generation_id=? ORDER BY id DESC LIMIT 1');
        $query->execute([$scope['owner'],$policy['connection_id'],$scope['generation']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $raw = $row['report_json'];
        $id = agent_connection_gmail_inbox_batch_id_v1($policy,$raw);
        if (!hash_equals($id,$row['batch_id'])) return null;
        $report = agent_connection_gmail_inbox_batch_report_v1($raw);
    }
    if ($report === null) return null;
    $at = agent_connection_gmail_inbox_status_time_v1($report['at']);
    if ($at === null) return null;
    $version = $id === null ? 'latest:'.hash('sha256',$raw) : 'batch:'.$id;
    $items = [];
    foreach (array_slice($report['items'],0,6) as $index=>$item) {
        if (!is_int($index) || !is_array($item)
            || !is_string($item['subject'] ?? null) || !is_string($item['from'] ?? null)
            || agent_connection_gmail_inbox_ignored_v1($item,$policy['rules'])) continue;
        $ref = agent_connection_gmail_inbox_item_reference_v1($scope,$version,$index,$item);
        if ($ref === null) continue;
        $items[] = ['subject'=>agent_email_matters_metadata_v1($item['subject'],240),
            'from'=>agent_email_matters_metadata_v1($item['from'],254),
            'category'=>in_array($item['category'] ?? null,['important','reply'],true) ? $item['category'] : 'new',
            'item_ref'=>$ref];
    }
    $total = $report['total'];
    return ['at'=>$at->format('Y-m-d\TH:i:s\Z'),'total'=>$total,
        'important'=>max(0,min($total,(int)($report['important'] ?? 0))),
        'replies'=>max(0,min($total,(int)($report['replies'] ?? 0))),
        'filtered'=>max(0,min(50,(int)($report['filtered'] ?? 0))),'items'=>$items];
}

function agent_connection_gmail_inbox_background_view_v1(
    PDO $pdo, array $scope, ?int $agentId=null, ?DateTimeImmutable $now=null, bool $includeResults=true,
    bool $includeCheckRef=false, bool $includeAnalysisWork=true
): array {
    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $policy = $scope['policy'];
    $lastChecked = agent_connection_gmail_inbox_status_time_v1($policy['last_checked_at']);
    $due = agent_connection_gmail_inbox_status_time_v1($policy['next_check_at']);
    $claimed = !empty($policy['claim_key']);
    $working = $claimed && $due !== null && $due > $now;
    $canReadBatches = $scope['provider_ready'] && $scope['generation'] !== null
        && agent_connection_gmail_inbox_batches_ready_v1($pdo);
    $ready = $canReadBatches && agent_connection_gmail_inbox_receipts_ready_v1($pdo);

    $state = match (true) {
        !$ready => 'unavailable',
        $policy['last_status'] === 'restart_required' && $due === null => 'restart_required',
        $due === null => 'unavailable',
        $working => 'working',
        agent_connection_gmail_inbox_due_overdue_v1($due,$claimed,$now) => 'overdue',
        $policy['last_status'] === 'retrying' => 'retrying',
        $policy['last_status'] === 'checked' && $lastChecked !== null => 'checked',
        in_array($policy['last_status'],['waiting','restart_required'],true) => 'scheduled',
        default => 'unavailable',
    };
    $latest = $includeResults && $canReadBatches && $agentId !== null && $agentId >= 0
        ? agent_connection_gmail_inbox_update_latest_v1($pdo,$scope,$agentId) : null;

    $view = [
        'state'=>$state,
        'last_checked_at'=>$lastChecked?->format('Y-m-d\TH:i:s\Z'),
        'next_check_at'=>$claimed ? null : $due?->format('Y-m-d\TH:i:s\Z'),
        'lease_until'=>$ready && $working ? $due->format('Y-m-d\TH:i:s\Z') : null,
        'pending'=>$includeResults && $canReadBatches && agent_connection_gmail_inbox_update_has_pending_v1($pdo,$scope),
        'latest'=>$latest === null ? null : array_intersect_key($latest,array_flip(['agent_id','conversation_id','message_id'])),
    ];
    if ($state === 'overdue') {
        $view['overdue_reason'] = $claimed ? 'claim_expired' : ($policy['last_status'] === 'retrying' ? 'retry_due' : 'schedule_due');
    }
    $analysisWork=$includeAnalysisWork ? agent_connection_gmail_inbox_analysis_work_v1($pdo,$scope,$now) : null;
    if($analysisWork!==null)$view['analysis_work']=$analysisWork;
    if ($includeResults && $canReadBatches && $agentId !== null && $agentId >= 0) {
        $arrivals = agent_connection_gmail_inbox_latest_arrivals_v1($pdo,$scope);
        if ($arrivals !== null) $view['latest_arrivals'] = $arrivals;
        require_once __DIR__.'/agent-email-matters-v1.php';
        if (agent_email_matters_ready_v1($pdo)) {
            $view['scan'] = agent_email_matters_scan_status_v1($pdo,$scope);
            $view['matters'] = agent_email_matters_summary_v1($pdo,$scope+['agent_id'=>$agentId],3);
        }
        if (agent_connection_gmail_inbox_triage_enabled_v1($pdo,$policy)) {
            $view['triage'] = ['generation'=>$scope['generation']]+agent_email_triage_summary_v1($pdo,$scope);
            $runningReport = agent_email_running_report_view_v1($pdo,$scope);
            if ($runningReport !== null) {
                $view['report'] = [
                    'running_report'=>$runningReport,
                    'handoffs'=>agent_email_running_report_handoffs_v1($runningReport),
                ];
            }
            $sync = agent_connection_gmail_inbox_sync_view_v1($pdo,$policy);
            if ($sync !== null) $view['sync'] = $sync;
        }
    }
    if ($includeCheckRef) {
        if ($state === 'overdue') $view['check_ref'] = agent_connection_gmail_inbox_overdue_ref_v1($policy);
        $reportCheck = agent_connection_gmail_inbox_content_work_v1($pdo,$scope,$now);
        if ($reportCheck !== null) $view['content_check_ref'] = $reportCheck['ref'];
    }
    if ($includeResults && $agentId !== null && $agentId >= 0) {
        $view['diagnostics'] = agent_connection_gmail_diagnostics_projection_v1($pdo,$scope,$view,$agentId,null);
        $analysis = $view['diagnostics']['stages'][3]['state'];
        $view['analysis_state'] = match (true) {
            !empty($view['report']['running_report']['findings']) => 'ready',
            ($view['diagnostics']['stages'][0]['state'] ?? '') === 'blocked' => 'setup_required',
            $analysis === 'disabled' => 'disabled',
            $analysis === 'blocked' => 'unavailable',
            in_array($analysis,['working','queued','retrying'],true) => 'processing',
            ($view['sync']['status'] ?? '') === 'complete' => 'empty',
            default => 'waiting',
        };
    }
    return $view;
}

/** Diagnostic data is read-only and contains no mailbox text or identifiers. */
function agent_connection_gmail_diagnostics_packet_v1(array $steps): array
{
    $stages = [];
    $labels = ['Gmail setup','Inbox scan','Classification','Content analysis','Chat delivery'];
    foreach ($labels as $index=>$label) {
        $step = $steps[$index] ?? ['waiting','Waiting for the previous step.'];
        $stages[] = ['label'=>$label,'state'=>$step[0],'detail'=>$step[1]]+(isset($step[2])?['code'=>$step[2]]:[]);
    }
    $delivery = $stages[4];
    if (in_array($delivery['code']??null,['gmail_finding_ready_for_chat','gmail_handoff_unassigned','gmail_handoff_queued',
        'gmail_handoff_working','gmail_handoff_pending_delivery','gmail_handoff_retry_scheduled','gmail_handoff_failed',
        'gmail_handoff_verification_unavailable'],true)) {
        return ['state'=>$delivery['state'],'next_step'=>$delivery['detail'],'stages'=>$stages];
    }
    foreach (['blocked','disabled','retrying','overdue','working','queued','waiting','partial'] as $priority) {
        foreach ($steps as [$state,$detail]) {
            if ($state === $priority) return ['state'=>$state,'next_step'=>$detail,'stages'=>$stages];
        }
    }
    return ['state'=>'ready','next_step'=>'Open Gmail to review the saved findings.','stages'=>$stages];
}

function agent_connection_gmail_diagnostics_v1(PDO $pdo,array $user,int $agentId,int $conversationId): array
{
    $unavailable = [['blocked','Open a saved private Agent Chat to verify Gmail activity.','private_chat_required']];
    try {
        $owner = (int)($user['id'] ?? 0);
        if ($owner < 1 || $agentId < 0 || $conversationId < 1
            || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
            return agent_connection_gmail_diagnostics_packet_v1($unavailable);
        }
        $live = agent_connection_gmail_inbox_user_v1($pdo,$owner);
        if (empty($live['is_active']) || !has_permission('account.access',$live) || !has_permission('chat.access',$live)
            || !agent_connection_gmail_inbox_chat_target_v1($pdo,['owner'=>$owner,'user'=>$live],$agentId,$conversationId)) {
            return agent_connection_gmail_diagnostics_packet_v1($unavailable);
        }
        if (!agent_connection_gmail_inbox_ready_v1($pdo)) {
            return agent_connection_gmail_diagnostics_packet_v1([['blocked','Run Database Upgrade. Gmail inbox storage is missing.','inbox_storage_unavailable']]);
        }
        $connection = agent_connections_find_v1($pdo,$owner,'gmail');
        if (($connection['status'] ?? null) !== 'connected') {
            return agent_connection_gmail_diagnostics_packet_v1([['blocked','Connect or reconnect Gmail in Connections.','gmail_connection_required']]);
        }
        if (!agent_connections_permissions_v1($connection)['read']) {
            return agent_connection_gmail_diagnostics_packet_v1([['blocked','Enable Gmail read access in Connections.','gmail_read_disabled']]);
        }
        $policy = agent_connection_gmail_inbox_policy_v1($pdo,$owner);
        if (empty($policy['enabled'])) {
            return agent_connection_gmail_diagnostics_packet_v1([['disabled','Enable Review new Gmail messages in Connections.','inbox_review_disabled']]);
        }
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$live);
        if ($scope['state'] !== 'ready') {
            return agent_connection_gmail_diagnostics_packet_v1([['blocked','Save Gmail Inbox Review again. Its saved authorization no longer matches the connection.','inbox_grant_changed']]);
        }
    } catch (Throwable) {
        return agent_connection_gmail_diagnostics_packet_v1([['blocked','The Gmail connection and Inbox Review settings could not be verified.','gmail_setup_read_failed']]);
    }
    $view = agent_connection_gmail_inbox_background_view_v1($pdo,$scope,includeResults:false,includeAnalysisWork:false);
    return agent_connection_gmail_diagnostics_projection_v1($pdo,$scope,$view,$agentId,$conversationId);
}

function agent_connection_gmail_diagnostics_projection_v1(PDO $pdo,array $scope,array $view,int $agentId,?int $conversationId): array
{
    if (!$scope['provider_ready'] || $scope['generation'] === null) {
        return agent_connection_gmail_diagnostics_packet_v1([['blocked','Gmail provider configuration or the saved inbox authorization is unavailable. Check the Gmail connection.','gmail_provider_unavailable']]);
    }
    $steps = [['complete','Gmail read access and Inbox Review are enabled.']];
    try {
        $steps[] = agent_connection_gmail_diagnostics_scan_v1($pdo,$scope,$view);
    } catch (Throwable) {
        $steps[] = !empty($view['last_checked_at'])
            ? ['complete','An inbox scan completed. Diagnostics could not verify the message count.','gmail_scan_count_unavailable']
            : ['blocked','The inbox scan checkpoint could not be read.','gmail_scan_read_failed'];
    }
    $classified = 0;
    try {
        if (empty($scope['policy']['rules']['triage_enabled'])) {
            $steps[] = ['disabled','Enable Classify and prioritize inbox mail in Connections.','classification_disabled'];
        } elseif (!agent_connection_gmail_inbox_triage_enabled_v1($pdo,$scope['policy'])) {
            $steps[] = ['blocked','Classification storage is unavailable. Run Database Upgrade.','classification_storage_unavailable'];
        } else {
            $triage = $view['triage'] ?? agent_email_triage_summary_v1($pdo,$scope);
            $classified = array_sum(array_column($triage['groups'],'count'));
            $steps[] = $classified > 0
                ? ['complete',sprintf('%d inbox messages have saved classifications.', $classified)]
                : ['waiting','No classifications have been saved for this Gmail connection.'];
        }
    } catch (Throwable) {
        $steps[] = ['blocked','The saved Gmail classification counts could not be read.','classification_read_failed'];
    }
    $analysisLease=null;
    try {
        $steps[] = agent_connection_gmail_diagnostics_analysis_v1($pdo,$scope,$classified,$analysisLease);
    } catch (Throwable) {
        $steps[] = ['blocked','The saved Gmail content-analysis state could not be read.','content_analysis_read_failed'];
    }
    try {
        $steps[] = agent_connection_gmail_diagnostics_delivery_v1($pdo,$scope,$agentId,$conversationId);
    } catch (JsonException) {
        $steps[] = ['blocked','A saved Gmail delivery record could not be decoded.','gmail_delivery_record_invalid'];
    } catch (Throwable) {
        $steps[] = ['blocked','The Gmail delivery state for this Agent Chat could not be verified.','gmail_delivery_read_failed'];
    }
    $packet=agent_connection_gmail_diagnostics_packet_v1($steps);
    $activity=agent_connection_gmail_diagnostics_activity_v1($view,$analysisLease,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    if($activity!==null)$packet['activity']=$activity;
    return $packet;
}

/** The pipeline may retain pending work after a crash. Only a live claim can pulse. */
function agent_connection_gmail_diagnostics_activity_v1(array $view,?string $analysisLease,DateTimeImmutable $now): ?array
{
    $lease=$view['lease_until']??null;
    $inbox=($view['state']??null)==='working'&&is_string($lease)&&preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$lease)
        ?agent_connection_gmail_inbox_status_time_v1(substr($lease,0,10).' '.substr($lease,11,8)):null;
    $analysis=agent_connection_gmail_inbox_status_time_v1($analysisLease);
    $scanning=$inbox!==null&&$inbox>$now;
    $expires=$scanning?$inbox:$analysis;
    if($expires===null||$expires<=$now)return null;
    return ['state'=>$scanning?'scanning':'working','at'=>$now->format(DATE_ATOM),'expires_at'=>$expires->format(DATE_ATOM)];
}

function agent_connection_gmail_diagnostics_scan_v1(PDO $pdo,array $scope,array $view): array
{
    $scan = $view['scan'] ?? agent_email_matters_scan_status_v1($pdo,$scope);
    $sync = $view['sync'] ?? agent_connection_gmail_inbox_sync_view_v1($pdo,$scope['policy']);
    return match (true) {
        ($view['state'] ?? '') === 'unavailable' => ['blocked','Inbox scan storage or its schedule is unavailable. Check Database Upgrade and Gmail settings.'],
        ($view['state'] ?? '') === 'restart_required' => ['blocked','Gmail history expired. Restart Inbox Review in Connections.'],
        ($view['state'] ?? '') === 'retrying' => ['retrying','The last inbox scan failed and has a retry scheduled. Its failure reason was not recorded.'],
        ($view['state'] ?? '') === 'overdue' => match ($view['overdue_reason'] ?? '') {
            'claim_expired' => ['overdue','The inbox worker claim expired. Keep Agent Chat open to resume the scan.','gmail_scan_claim_expired'],
            'retry_due' => ['overdue','The last inbox scan failed and its retry is due. Keep Agent Chat open to retry.','gmail_scan_retry_due'],
            default => ['overdue','A scheduled inbox scan is due. Keep Agent Chat open to run it.','gmail_scan_due'],
        },
        ($sync['status'] ?? '') === 'pending' => ['working',sprintf('Recent-inbox scan is in progress. %d messages checked.',(int)$sync['checked'])],
        ($sync['status'] ?? '') === 'truncated' => ['blocked',sprintf('Recent-inbox scan reached its limit after %d messages. Review may be incomplete.',(int)$sync['checked'])],
        ($view['state'] ?? '') === 'working' => ['working','An inbox scan has an active worker claim.'],
        isset($scan['total']) => agent_connection_gmail_diagnostics_completed_scan_v1($pdo,$scope,$scan),
        ($sync['status'] ?? '') === 'complete' => ['complete',sprintf('The recent-inbox scan checked %d messages.',(int)$sync['checked'])],
        !empty($view['last_checked_at']) => ['complete','An inbox scan completed. The message count was not recorded.'],
        default => ['waiting','No completed inbox scan has been recorded yet.'],
    };
}

/** Correlate the exact saved new-arrivals batch with current analysis rows, without fetching mail. */
function agent_connection_gmail_diagnostics_completed_scan_v1(PDO $pdo,array $scope,array $scan): array
{
    $total = (int)$scan['total'];
    if ($total === 0) return ['complete','The last completed inbox scan accepted no new messages.','gmail_scan_empty'];
    $summary = $total === 1 ? 'The last completed inbox scan reviewed 1 message.'
        : sprintf('The last completed inbox scan reviewed %d messages.',$total);
    $policy = $scope['policy'];
    if (!agent_email_running_report_enabled_v1($policy)) return ['complete',$summary];
    $batch = agent_connection_gmail_inbox_batch_report_v1((string)($policy['last_review_json']??''));
    $at = agent_connection_gmail_inbox_status_time_v1($batch['at']??null);
    $bound = agent_email_matters_policy_scope_v1($policy);
    if ($at === null || $at->format('Y-m-d\TH:i:s\Z')!==($scan['at']??null) || $batch['total']!==$total
        || count($batch['items'])!==$total || $bound === null || !agent_email_running_report_ready_v1($pdo)) {
        return ['complete',$summary.' This scan does not have a complete saved message-to-analysis record.'];
    }
    $refs = [];
    foreach ($batch['items'] as $item) {
        $id = $item['triage']['id']??null;
        if (is_string($id) && preg_match('/\A[a-f0-9]{64}\z/D',$id)) $refs[$id] = true;
    }
    if (count($refs)!==$total) return ['complete',$summary.' This scan does not have a complete saved message-to-analysis record.'];
    try {
        $marks = implode(',',array_fill(0,count($refs),'?'));
        $query = $pdo->prepare("SELECT COUNT(*) AS matched,
            SUM(CASE WHEN failures>=4 THEN 1 ELSE 0 END) AS stopped,
            SUM(CASE WHEN due_at IS NOT NULL THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN failures=0 AND due_at IS NULL AND finding_enc IS NOT NULL THEN 1 ELSE 0 END) AS analyzed
            FROM agent_email_running_report_v1 WHERE owner_user_id=? AND connection_id=? AND grant_key=?
                AND source_triage_id IN ($marks)");
        $query->execute([...$bound,...array_keys($refs)]);
        $counts = array_map('intval',$query->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (PDOException) {
        return ['complete',$summary.' The scan’s content-analysis links could not be verified.','gmail_scan_analysis_links_unavailable'];
    }
    if (empty($counts['matched'])) {
        return ['complete',$summary.' None is the current source of an analyzed, queued, or stopped thread.','gmail_scan_no_current_analysis'];
    }
    $detail = sprintf(' Current analysis linked to this scan: %d complete, %d pending, %d stopped.',
        $counts['analyzed'],$counts['pending'],$counts['stopped']);
    return ['complete',$summary.$detail,'gmail_scan_analysis_links'];
}

function agent_connection_gmail_diagnostics_analysis_v1(PDO $pdo,array $scope,int $classified,?string &$leaseUntil=null): array
{
    $policy = $scope['policy'];
    if (!agent_email_running_report_enabled_v1($policy)) {
        return ['disabled','Enable Analyze email content for the running report in Connections. First Inbox Review checks message headers.'];
    }
    $bound = agent_email_matters_policy_scope_v1($policy);
    if ($bound === null || !agent_email_running_report_ready_v1($pdo)) {
        return ['blocked','Content analysis storage is unavailable. Run Database Upgrade.'];
    }
    $query = $pdo->prepare('SELECT COUNT(*) AS total, COUNT(finding_enc) AS analyzed,
        SUM(CASE WHEN due_at IS NOT NULL THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN lease_key IS NOT NULL AND due_at>? THEN 1 ELSE 0 END) AS working,
        MAX(CASE WHEN lease_key IS NOT NULL THEN due_at END) AS lease_until,
        SUM(CASE WHEN failures BETWEEN 1 AND 3 THEN 1 ELSE 0 END) AS retrying,
        SUM(CASE WHEN failures>=4 THEN 1 ELSE 0 END) AS failed
        FROM agent_email_running_report_v1 WHERE owner_user_id=? AND connection_id=? AND grant_key=?');
    $query->execute([agent_commerce_time_pa2(),...$bound]);
    $row=$query->fetch(PDO::FETCH_ASSOC)?:[];
    $leaseUntil=is_string($row['lease_until']??null)?$row['lease_until']:null;
    unset($row['lease_until']);
    $counts = array_map('intval',$row);
    $counts += ['total'=>0,'analyzed'=>0,'pending'=>0,'working'=>0,'retrying'=>0,'failed'=>0];
    $detail = sprintf('%d analyzed, %d pending, %d stopped.', $counts['analyzed'],$counts['pending'],$counts['failed']);
    if ($counts['retrying'] > 0 || $counts['failed'] > 0) {
        $detail .= ' '.agent_connection_gmail_diagnostics_analysis_failures_v1($pdo,$bound);
    }
    require_once __DIR__.'/agent-runtime-routing-v420.php';
    try {
        $route = vp3_agent_runtime_preference_readonly_v420($pdo,(int)$scope['owner'],0)['effective_preference'] ?? '';
    } catch (Throwable) {
        if ($counts['working'] > 0) return ['working',$detail.' A thread has an active analysis claim. The default Agent route could not be read.','gmail_analysis_route_unavailable'];
        return ['blocked',$detail.' The default Agent route for Gmail content analysis could not be read.','gmail_analysis_route_unavailable'];
    }
    if ($route === 'homeserver_only' && $counts['working'] === 0) {
        return ['blocked',$detail.' The default Agent is set to HomeServer only. Gmail content analysis uses VP3 Cloud.','gmail_analysis_homeserver_only'];
    }
    $routeDetail = match ($route) {
        'vp3_cloud' => ' The default Agent is set to VP3 Cloud.',
        'auto' => ' The default Agent uses Automatic routing. Gmail checks for an eligible Cloud route when analysis runs.',
        default => '',
    };
    $detail .= $routeDetail;
    if ($counts['working'] > 0) return ['working',$detail.' A thread has an active analysis claim.'];
    if ($counts['retrying'] > 0) return ['retrying',$detail,'gmail_content_retries_scheduled'];
    if ($counts['pending'] > 0) return ['queued',$detail.' Waiting for the next content-analysis run.'];
    if ($counts['failed'] > 0) return [$counts['analyzed'] > 0 ? 'partial' : 'blocked',$detail,'gmail_content_stopped'];
    if ($counts['analyzed'] > 0) return ['complete',$detail.' Content findings are available in Gmail.'];
    return ['waiting',$classified > 0
        ? 'Messages were classified, but no thread has entered content analysis. Run a recent-inbox analysis in Gmail.'
        : 'No threads have been submitted for content analysis yet.'];
}

/** Summarize recorded causes without reading findings or returning mailbox data. */
function agent_connection_gmail_diagnostics_analysis_failures_v1(PDO $pdo,array $bound): string
{
    if (!agent_email_running_report_failure_ready_v1($pdo)) {
        return 'Run Database Upgrade to record reasons for future attempts.';
    }
    try {
        $query = $pdo->prepare('SELECT failure_reason,COUNT(*) AS total FROM agent_email_running_report_v1
            WHERE owner_user_id=? AND connection_id=? AND grant_key=? AND failures>0
            GROUP BY failure_reason ORDER BY total DESC,failure_reason LIMIT 4');
        $query->execute($bound);
        $reasons = [];
        $unknown = 0;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reason = agent_email_running_report_failure_text_v1($row['failure_reason']);
            if ($reason === null) $unknown += (int)$row['total'];
            else $reasons[] = sprintf('%d: %s',(int)$row['total'],$reason);
        }
        if ($unknown > 0) $reasons[] = sprintf('%d saved attempts have no specific cause.', $unknown);
        return implode(' ',$reasons);
    } catch (Throwable) {
        return 'The recorded analysis reasons could not be read.';
    }
}

function agent_connection_gmail_diagnostics_delivery_v1(PDO $pdo,array $scope,int $agentId,?int $conversationId): array
{
    if ($conversationId === null) return ['unavailable','Open Initiative Debug in a saved Agent Chat to check its exact delivery.'];
    if (!agent_email_running_report_enabled_v1($scope['policy'])) {
        return ['disabled','Gmail content analysis is off. Inbox scan notices do not confirm analysis delivery.','gmail_content_disabled'];
    }
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    $destination = agent_connection_gmail_inbox_update_destination_v1($pdo,$scope);
    if ($destination !== null && $destination['agent_id'] !== $agentId) {
        return ['waiting','Gmail findings go through Top of Mind for the Agent linked to the inbox review.','gmail_linked_to_another_agent'];
    }
    if (!function_exists('vp3_initiative_policy_v1') || !table_exists('agent_workflow_runs')) {
        return ['waiting','Gmail content analysis has no verified delivery in this Chat yet.','gmail_delivery_waiting'];
    }
    $policy = vp3_initiative_policy_v1($scope['user'],$agentId);
    if (($policy['enabled'] ?? null) !== true || ($policy['conversation_id'] ?? null) !== $conversationId) {
        return ['waiting','Keep this private Agent Chat active with Top of Mind on so it can receive Gmail findings.','gmail_chat_not_linked'];
    }
    return agent_connection_gmail_diagnostics_active_delivery_v1($pdo,$scope['user'],$policy);
}

/** Verify current rule and source first. A saved job or receipt is a separate fact. */
function agent_connection_gmail_diagnostics_active_delivery_v1(PDO $pdo,array $user,array $policy): array
{
    require_once __DIR__.'/agent-coordination-work-v1.php';
    require_once __DIR__.'/agent-coordination-sources-v1.php';
    $agent = vp3_initiative_binding_agent_v1($policy);
    $conversation = $policy['conversation_id'];
    $snapshot = vp3_coordination_sources_v1($pdo,$user,$policy,specialistOnly:'gmail');
    $coverage = $snapshot['coverage']['gmail']??'unavailable';
    if ($coverage==='unavailable') {
        $detail = match ($snapshot['failures']['gmail']??null) {
            'provider_unavailable' => 'Top of Mind could not verify the Gmail provider and inbox authorization.',
            'storage_unavailable' => 'Top of Mind could not verify the saved Gmail report and classification storage.',
            'storage_error' => 'The database could not read the saved Gmail findings for Top of Mind.',
            'code_error','invalid_internal_type' => 'The Gmail reader failed before Top of Mind could check the saved findings.',
            'invalid_data' => 'The Gmail reader could not interpret the saved findings for Top of Mind.',
            default => 'Top of Mind could not verify the saved Gmail findings for this Chat.',
        };
        return ['blocked',$detail,'gmail_findings_unavailable'];
    }
    $settings = vp3_coordination_rules_v1($pdo,$user,$agent);
    $routes = [];
    $progress = [];
    foreach ($snapshot['items'] as $ref=>$item) {
        if (empty($item['trigger'])) continue;
        $route = vp3_coordination_item_route_v1($settings,$item);
        $routes[$ref] = $route;
        if (vp3_coordination_chat_route_v1($route)) $progress[$ref] = empty($item['legacy_delivered'])?'unassigned':'legacy_handled';
    }

    if ($progress) {
        // The scheduler only assigns the newest finding for a verified Gmail matter.
        [, $covered] = vp3_coordination_material_discovery_v1(array_intersect_key($snapshot['items'],$progress),$routes);
        foreach ($covered as $ref=>$_) $progress[$ref] = 'covered';
        $lastMessageId = 0;
        $progress = agent_connection_gmail_diagnostics_handoffs_v1($pdo,$user,$policy,$snapshot['items'],$routes,$progress,$lastMessageId);
        $counts = array_count_values($progress);
        if (isset($counts['pending'])) return ['waiting','A verified Gmail handoff is saved and waiting for the next delivery pass.','gmail_handoff_pending_delivery'];
        if (isset($counts['working'])) return ['working','Top of Mind is preparing a Gmail Chat handoff.','gmail_handoff_working'];
        if (isset($counts['queued'])) return ['queued','A Gmail Chat handoff is queued for a worker to prepare.','gmail_handoff_queued'];
        if (isset($counts['retrying'])) return ['retrying','A current Gmail handoff has a scheduled retry.','gmail_handoff_retry_scheduled'];
        if (isset($counts['verification_unavailable'])) return ['waiting','Top of Mind could not verify a prior Gmail handoff. It will check again before posting another copy.','gmail_handoff_verification_unavailable'];
        if (isset($counts['unassigned'])) {
            $detail = $counts['unassigned']===1
                ? 'One current Gmail finding matches an Ask or Report rule. No current Chat handoff is recorded for it.'
                : sprintf('%d current Gmail findings match an Ask or Report rule. No current Chat handoff is recorded for them.',$counts['unassigned']);
            try {
                require_once __DIR__.'/agent-coordination-schedule-v1.php';
                $scan = vp3_coordination_scan_checkpoint_v1($user,$policy);
            } catch (Throwable) {
                $scan = null;
            }
            $gmail = $scan['specialists']['gmail']??null;
            if (($gmail['coverage']??null)==='unavailable') {
                $detail .= ' The last specialist scan could not read Gmail.';
            } elseif (($gmail['coverage']??null)==='unconfigured') {
                $detail .= ' Gmail was unavailable to the last specialist scan for this Chat.';
            } elseif (isset($gmail['found'],$gmail['candidate_count'])) {
                $detail .= sprintf(' Last specialist scan: %d verified Gmail findings, %d Chat candidates.',
                    $gmail['found'],$gmail['candidate_count']);
            }
            return ['waiting',$detail,'gmail_handoff_unassigned'];
        }
        if (isset($counts['failed'])) return ['blocked','A Gmail handoff for a current finding stopped before delivery.','gmail_handoff_failed'];
        if (isset($counts['paused']) || isset($counts['cancelled']) || isset($counts['withheld'])) {
            return ['waiting','An assignment for a current Gmail finding was paused, cancelled, or withheld.','gmail_handoff_not_delivered'];
        }
        $coveredNote = match ($counts['covered']??0) {
            0 => '',
            1 => ' One older finding is covered by a newer finding for the same matter.',
            default => sprintf(' %d older findings are covered by newer findings for the same matters.',$counts['covered']),
        };
        if (isset($counts['posted'])) return ['complete','A current Gmail handoff was verified in this Chat at message '.$lastMessageId.'.'.$coveredNote,'gmail_handoff_delivered'];
        return ['complete','The reviewed Gmail finding already has a delivery record.'.$coveredNote,'gmail_handoff_already_handled'];
    }
    return match (true) {
        in_array('prepare',array_column($routes,'action'),true)
            => ['waiting','A verified Gmail finding matches a Prepare privately rule and stays in the specialist report.','gmail_finding_private'],
        $routes !== []
            => ['waiting','The matching Gmail rules keep current findings in Top of Mind.','gmail_rules_watch'],
        $coverage==='limited'
            => ['waiting','No reviewed Gmail finding currently qualifies for Chat. Some findings are still pending or could not be checked.','gmail_findings_limited'],
        default => ['waiting','No current Gmail finding qualifies for a proactive Chat message. Routine arrivals appear in Gmail.','gmail_no_eligible_findings'],
    };
}

/**
 * Only current, exact Gmail-primary work can explain whether a Gmail handoff exists.
 * Other posts that cite a Gmail finding are supporting evidence.
 */
function agent_connection_gmail_diagnostics_handoffs_v1(PDO $pdo,array $user,array $policy,array $items,array $routes,array $progress,
    int &$lastMessageId=0): array
{
    $active=array_filter($progress,static fn(string $state): bool=>$state==='unassigned');
    $versions=array_column(array_intersect_key($items,$active),'revision','ref');
    foreach (vp3_coordination_runs_v1($pdo,$user,$policy,null,60,'gmail',$versions) as $run) {
        $goal = json_decode((string)$run['goal'],true,32);
        $ref = $goal['primary']??null;
        $state = is_string($ref)?($progress[$ref]??null):null;
        if (!in_array($state,['unassigned','failed','paused','cancelled','withheld','deduplicated','verification_unavailable'],true) || !is_array($goal)
            || isset($goal['coordination_work']) || isset($goal['followthrough'])
            || !vp3_coordination_handoff_matches_primary_v1($goal,$ref,$routes[$ref]['action'])
            || ($goal['sources'][$ref]??null)!==$items[$ref]['revision']) continue;
        $current = ($goal['binding']??null)===$policy && ($goal['coordination_route']??null)===$routes[$ref];
        $saved = vp3_coordination_verified_run_v1($pdo,$user,$run);
        $delivery = $saved['initiative_delivery']??null;
        if ($delivery==='delivered') {
            $post=null;
            $proof=vp3_coordination_chat_handoff_proof_v1($pdo,$user,$policy,$run,$saved,$items,$post);
            if($proof==='verified'){
                $progress[$ref]='posted';
                $lastMessageId=max($lastMessageId,$post['message_id']);
            }
            elseif($proof==='answered')$progress[$ref]='deduplicated';
            elseif($proof==='unavailable'&&$state==='unassigned')$progress[$ref]='verification_unavailable';
            continue;
        }
        if (!$current || $state!=='unassigned') continue;
        if ($delivery==='pending') {
            $progress[$ref]='pending';
        } elseif ($delivery==='private' && ($saved['initiative_delivery_reason']??null)==='duplicate') {
            $repair=vp3_coordination_handoff_repairable_v1($pdo,$user,$run,$saved,$items);
            if($repair!==true)$progress[$ref]=$repair===null?'verification_unavailable':'deduplicated';
        } elseif ($delivery==='withheld') {
            $repair=vp3_coordination_handoff_repairable_v1($pdo,$user,$run,$saved,$items);
            if($repair!==true)$progress[$ref]=$repair===null?'verification_unavailable':'withheld';
        } elseif (in_array($run['status'],['approved','queued'],true)) {
            $retryAt = agent_connection_gmail_inbox_status_time_v1($run['next_attempt_at']??null)?->getTimestamp()??0;
            $progress[$ref]=$retryAt>time()?'retrying':'queued';
        } elseif ($run['status']==='executing' && agent_connection_gmail_inbox_status_time_v1($run['lease_expires_at']??null)?->getTimestamp()>time()) {
            $progress[$ref]='working';
        } elseif (in_array($run['status'],['failed','paused','cancelled'],true)) {
            $progress[$ref]=$run['status'];
        }
    }
    foreach ($progress as $ref=>$state) {
        if ($state!=='unassigned') continue;
        $route = $routes[$ref];
        $goal = ['binding'=>$policy,'primary'=>$ref,'coordination_route'=>$route];
        $output = ['evidence_revisions'=>[$ref=>$items[$ref]['revision']],'state'=>$route['action']==='ask'?'needs_owner':'watching'];
        if (vp3_coordination_other_chat_delivered_v1($pdo,$user,$goal,$output)) $progress[$ref]='deduplicated';
    }
    return $progress;
}
