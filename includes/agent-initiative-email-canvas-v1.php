<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
require_once __DIR__.'/agent-email-triage-v1.php';

/** Authorize the current Email Agent and the exact linked private Chat. */
function vp3_initiative_email_canvas_scope_v1(PDO $pdo,array $user,array $binding): ?array
{
    $cid=$binding['conversation_id']??null;
    if(!is_int($cid)||$cid<1)return null;
    $scope=vp3_initiative_specialist_gmail_scope_v1($pdo,$user,$binding);
    if($scope===null||!agent_email_matters_ready_v1($pdo)||!agent_email_triage_ready_v1($pdo))return null;
    $bound=agent_email_triage_scope_v1($scope);
    if($bound===null)return null;
    $agent=vp3_initiative_binding_agent_v1($binding);
    $ref='email-canvas:'.agent_connection_gmail_inbox_key_v1($scope['policy'],
        'deadline-board-v1:'.$bound[0].':'.$bound[1].':'.$scope['generation'].':'.$agent.':'.$cid);
    return ['ref'=>$ref,'gmail'=>$scope,'bound'=>$bound];
}

/** Recent committed body deadlines, each bound to one exact Email Agent matter. */
function vp3_initiative_email_canvas_deadlines_v1(PDO $pdo,array $authority,bool $discovery): array
{
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $policy=$authority['gmail']['policy'];
    [$ended,$watchArgs]=agent_email_matters_ended_watch_clause_v1($pdo,$policy,$now->format('Y-m-d H:i:s'));
    $stages=$discovery?"'needs_review','awaiting_reply','followup_due'":"'needs_review','awaiting_reply','followup_due','reply_received','actioned'";
    $subject=$discovery?"''":'m.subject';
    $latest=$discovery?' AND m.latest_received_ms=t.received_ms':'';
    $date='COALESCE(NULLIF(t.due_date,\'\'),SUBSTR(t.due_at,1,10))';
    $query=$pdo->prepare("SELECT m.matter_id,$subject AS subject,m.latest_message_key,m.latest_received_ms,
            CASE WHEN m.stage='awaiting_reply' AND $ended THEN 'followup_due' ELSE m.stage END AS stage,
            t.triage_id,t.received_ms,t.message_id_enc,t.due_date,t.due_at
        FROM agent_email_triage_v1 t JOIN agent_email_matters_v1 m ON m.matter_id=t.matter_id
            AND m.owner_user_id=t.owner_user_id AND m.connection_id=t.connection_id AND m.grant_key=t.grant_key
        WHERE t.owner_user_id=? AND t.connection_id=? AND t.grant_key=?
            AND t.is_historical=0 AND t.is_protected=1 AND t.group_key='active' AND t.attention_key='act_soon'
            AND t.reason_key='body_deadline' AND t.received_ms>? AND t.observed_at>=?
            AND $date BETWEEN ? AND ? AND m.stage IN ($stages)$latest
        ORDER BY t.received_ms DESC,t.triage_id DESC LIMIT 72");
    $start=$now->modify('-14 days')->format('Y-m-d');$end=$now->modify('+14 days')->format('Y-m-d');
    $query->execute([...$watchArgs,...$authority['bound'],(int)$policy['enabled_at_ms'],
        $now->modify('-30 days')->format('Y-m-d H:i:s'),$start,$end]);
    $matches=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $id=(string)$row['matter_id'];
        if(isset($matches[$id]))continue;
        $at=agent_email_triage_deadline_v1($row['due_at']);
        $day=agent_email_triage_date_v1($row['due_date'])??($at===null?null:substr($at,0,10));
        if($day===null||$day<$start||$day>$end)continue;
        $providerId=agent_connection_gmail_id_v1(ai_decrypt_secret((string)$row['message_id_enc']));
        if($providerId===''||!hash_equals((string)$row['triage_id'],agent_email_matters_source_key_v1($policy,'triage',$providerId)))continue;
        $current=(int)$row['latest_received_ms']===(int)$row['received_ms']
            &&hash_equals((string)$row['latest_message_key'],agent_email_matters_source_key_v1($policy,'inbox',$providerId));
        // A verified reply can stay visible with its earlier deadline labelled as such.
        if(!$current&&($discovery||$row['stage']!=='reply_received'))continue;
        $matches[$id]=['id'=>$id,'stage'=>$row['stage'],'due_date'=>$day,'due_at'=>$at,'deadline_current'=>$current,
            'source_ref'=>$row['triage_id'],'subject'=>agent_email_matters_metadata_v1($row['subject'],160)];
        if(count($matches)>=12)break;
    }
    uasort($matches,static fn(array $left,array $right): int=>
        [$left['due_date'],$left['due_at']??'',$left['id']]<=>[$right['due_date'],$right['due_at']??'',$right['id']]);
    return array_values($matches);
}

/** The coordinator sees the workflow evidence and success checks, without message records. */
function vp3_initiative_email_canvas_evidence_v1(PDO $pdo,array $user,array $binding,?array $selectedRefs=null,bool $ownerRequested=false): array
{
    if($selectedRefs!==null&&!array_filter($selectedRefs,static fn(mixed $ref): bool=>
        is_string($ref)&&preg_match('/\Aemail-canvas:[a-f0-9]{64}\z/D',$ref)===1))return [];
    $authority=vp3_initiative_email_canvas_scope_v1($pdo,$user,$binding);
    if($authority===null||($selectedRefs!==null&&!in_array($authority['ref'],$selectedRefs,true)))return [];
    $matches=vp3_initiative_email_canvas_deadlines_v1($pdo,$authority,true);
    $threadCount=count($matches);
    $dayCount=count(array_unique(array_column($matches,'due_date')));
    $qualified=$threadCount>=3&&$dayCount>=2;
    if($threadCount<1||(!$qualified&&!$ownerRequested&&$selectedRefs===null))return [];
    $ref=$authority['ref'];
    return [$ref=>['ref'=>$ref,'kind'=>'email_canvas_opportunity','title'=>'Email deadline board',
        'detail'=>($qualified?"$threadCount distinct open Gmail matters have explicit deadlines spanning $dayCount dates.":'Current Gmail follow-ups include a verified deadline.')
            .' Gmail Inbox Review groups these by attention. A date-ordered view makes it easier to plan the deadlines together.',
        'url'=>url('/chat.php?agent='.(vp3_initiative_binding_agent_v1($binding)?:'system').'&conversation_id='.$binding['conversation_id']),
        // The proposal describes an ongoing workflow. Recheck eligibility each
        // time, while keeping its identity across routine updates to the rows.
        'revision'=>hash('sha256',$ref.':verified-deadline-board-need-v1'),
        'email_canvas'=>['workflow'=>'deadline_board','discovery_qualified'=>$qualified,'thread_count'=>$threadCount,'deadline_day_count'=>$dayCount,
            'native_view'=>'gmail_inbox_review','gap'=>'Planning across dated obligations in different threads.',
            'goal'=>'Show the Email Agent’s current dated follow-ups in one private board ordered by deadline.',
            'checks'=>['Show one row per matching thread ordered by deadline.','Update the matching row when its verified reply changes its state.',
                'Ignore unrelated email.','Clear the rows when the Gmail connection is no longer authorized.']]]];
}

/** Read live rows using the stable workflow reference from an authorized Canvas. */
function vp3_initiative_email_canvas_projection_v1(PDO $pdo,array $user,array $binding,string $ref): ?array
{
    $authority=vp3_initiative_email_canvas_scope_v1($pdo,$user,$binding);
    if($authority===null||!hash_equals($authority['ref'],$ref))return null;
    $rows=vp3_initiative_email_canvas_deadlines_v1($pdo,$authority,false);
    $live=vp3_initiative_email_canvas_scope_v1($pdo,$user,$binding);
    if($live===null||!hash_equals($live['ref'],$ref))return null;
    return ['workflow'=>'deadline_board','generation'=>$live['gmail']['generation'],'rows'=>$rows];
}
