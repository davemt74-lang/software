<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-triage-v1.php';

function vp3_coordination_gmail_topic_v1(string $subject): string
{
    $subject = trim(mb_strtolower($subject,'UTF-8'));
    for ($i=0;$i<4;$i++) {
        $plain = preg_replace('/\A(?:(?:re|fwd?|reminder|follow[ -]?up|reschedule(?:d)?|rescheduling|schedule change)\h*[:\x{2013}\x{2014}]\h*'
            .'|reschedule\h+(?!for\b|on\b|to\b))/u','',$subject) ?? '';
        if ($plain === $subject) break;
        $subject = $plain;
    }
    return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$subject) ?? '');
}

/** Signals from the exact incoming message, with quoted history and signatures already removed. */
function vp3_coordination_gmail_observation_v1(string $body,string $subject,?DateTimeImmutable $reference): array
{
    $clean = trim(preg_replace('/\s+/u',' ',$body) ?? '');
    if ($clean === '') return [];
    $repeatedBody = preg_replace('/\A(?:(?:a )?(?:quick |friendly )?reminder|follow[ -]?up)\h*[:.\x{2013}\x{2014}]\h*/iu','',$clean,1) ?? $clean;
    $triage = agent_email_triage_assessment_observe_v1($body,$reference);
    $reschedule = preg_match('/\b(?:reschedul(?:e|ed|ing)|schedule change|new (?:meeting|appointment) (?:date|time))\b/iu',$subject) === 1
        || preg_match('/\b(?:please|can we|could we|need to|can you|could you)\h+(?:[\p{L}]+\h+){0,2}(?:reschedule|move|shift)\b/iu',$body) === 1
        || preg_match('/\b(?:new (?:meeting|appointment) (?:date|time)|(?:meeting|appointment|rehearsal) (?:has been |was )?rescheduled)\b/iu',$body) === 1;
    // Match a proposed time only after a positive scheduling phrase, independent of the reply deadline.
    $meetings = [];
    if ($reschedule && preg_match_all('/\b(?:(?:reschedul(?:e|ed|ing)|move|shift)\h+[^\r\n.!?]{0,96}?\b(?:to|for)|'
        .'new (?:meeting|appointment) (?:date|time)\h+(?:is|will be))\h+(?=([^\r\n]{1,100}))/iu',$body,$matches)) {
        foreach ($matches[1] as $phrase) {
            $date = agent_email_triage_assessment_deadline_v1($phrase,$reference);
            if ($date !== null) $meetings[$date['deadline_at'] ?? $date['deadline_date']] = $date;
        }
    }
    $meeting = count($meetings) === 1 ? reset($meetings) : [];
    return ['version'=>1,'body_hash'=>hash('sha256',$clean),
        'reminder_body_hash'=>hash('sha256',$repeatedBody),
        'intent'=>$reschedule ? 'reschedule' : 'gmail',
        'repeat'=>$repeatedBody!==$clean || preg_match('/\b(?:a (?:quick |friendly )?reminder|just (?:a reminder|following up)|as previously requested)\b/iu',$body)===1,
        'transition'=>in_array($triage['candidate'],['declined_commitment','confirmed_commitment'],true) ? $triage['candidate'] : null,
        'deadline_at'=>$triage['deadline_at'],'deadline_date'=>$triage['deadline_date'],
        'meeting_at'=>$meeting['deadline_at'] ?? null,'meeting_date'=>$meeting['deadline_date'] ?? null];
}

/** This runs only after the report and exact provider triage row have passed their live grant checks. */
function vp3_coordination_gmail_material_v1(array $bound,array $triage,array $finding,string $threadRef): array
{
    $observation = $finding['material_observation'] ?? [];
    if (!is_array($observation) || ($observation['version'] ?? null) !== 1
        || !is_string($observation['body_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D',$observation['body_hash']) !== 1) return [];
    $subject = (string)($triage['subject'] ?? '');
    $topic = vp3_coordination_gmail_topic_v1($subject);
    $repeat = ($observation['repeat'] ?? null) === true
        || preg_match('/\A\s*(?:(?:re|fwd?)\h*:\h*){0,4}(?:reminder|follow[ -]?up)\h*[:\x{2013}\x{2014}]/iu',$subject)===1;
    $sender = agent_connection_gmail_address_v1($triage['sender_address'] ?? null);
    $date = agent_email_triage_date_v1($triage['due_date'] ?? null)
        ?? agent_email_triage_date_v1($observation['deadline_date'] ?? null);
    $deadline = agent_email_triage_deadline_v1($triage['due_at'] ?? null)
        ?? agent_email_triage_deadline_v1($observation['deadline_at'] ?? null);
    $meetingDate = agent_email_triage_date_v1($observation['meeting_date'] ?? null);
    $meeting = agent_email_triage_deadline_v1($observation['meeting_at'] ?? null);
    $intent = ($observation['intent'] ?? null) === 'reschedule' ? 'reschedule' : 'gmail';
    $specific = preg_match_all('/\b[\p{L}\p{N}]{3,}\b/u',$topic,$words) >= 2
        && array_diff($words[0],['meeting','appointment','event','calendar','schedule','rehearsal','reschedule','date','time','request','change','update','confirmation','confirm']);
    // A sender and subject cannot establish a shared matter by themselves. Require a
    // specific scheduling subject and server-observed rescheduling before aliasing threads.
    $verifiedTopic = $sender !== '' && $specific && $intent === 'reschedule'
        && preg_match('/\b(?:meeting|appointment|rehearsal|interview|workshop|consultation|session|visit)\b/u',$topic) === 1;
    $crossThread = $verifiedTopic && $meeting !== null;
    $scope = [(int)$bound[0],(int)$bound[1],(string)$bound[2]];
    $alias = $crossThread ? [$sender,$topic,$meeting] : [$threadRef];
    $confirmed = in_array($observation['transition'] ?? null,['declined_commitment','confirmed_commitment'],true)
        ? $observation['transition'] : null;
    // Without an exact proposed date/time, retain the original message fingerprint.
    // Requests with equal reply deadlines alone can ask to move to different times.
    $body = $crossThread ? null : $observation['body_hash'];
    $facts = [($finding['state'] ?? null)==='resolved' ? 'resolved' : 'open',$intent,
        $deadline ?? $date,$meeting ?? $meetingDate,$confirmed,$body];
    $observed = agent_email_matters_received_ms_v1($triage['received_ms'] ?? null);
    $key = vp3_coordination_source_hash_v1(['gmail-matter-v1',$scope,$alias]);
    return ['key'=>$key,
        'group_key'=>$crossThread ? $key : null,
        'revision'=>vp3_coordination_source_hash_v1(['gmail-material-v1',$facts]),
        'topic'=>$verifiedTopic ? $topic : '','intent'=>$intent,'observed_at'=>$observed,
        'repeat'=>$repeat,
        'deadline_at'=>$deadline,'deadline_date'=>$date,'meeting_at'=>$meeting,'meeting_date'=>$meetingDate];
}

/** Preserve an exact, authorized question across a literal copy of the same incoming request. */
function vp3_coordination_gmail_anchor_v1(PDO $pdo,array $scope,string $threadRef,array $triage,array $finding,mixed $anchor): ?array
{
    if (!is_array($anchor) || !is_string($anchor['source_triage_id'] ?? null)
        || !is_int($anchor['received_ms'] ?? null) || $anchor['received_ms']<1
        || !is_string($anchor['finding_enc'] ?? null) || strlen($anchor['finding_enc'])>16000
        || ($finding['state'] ?? null)!=='open') return null;
    $current = vp3_coordination_gmail_material_v1($scope,$triage,$finding,$threadRef);
    $received = agent_email_matters_received_ms_v1($triage['received_ms'] ?? null);
    if (empty($current['group_key']) || empty($current['repeat']) || $received<=$anchor['received_ms']) return null;
    $original = json_decode(ai_decrypt_secret($anchor['finding_enc']),true,8);
    $originalObservation = $original['material_observation'] ?? null;
    $currentObservation = $finding['material_observation'] ?? null;
    if (!is_array($originalObservation) || !is_array($currentObservation) || isset($original['coordination_anchor'])
        || ($currentObservation['source_triage_id'] ?? null)!==($triage['triage_id'] ?? null)
        || ($currentObservation['source_received_ms'] ?? null)!==$received
        || ($originalObservation['source_triage_id'] ?? null)!==$anchor['source_triage_id']
        || ($originalObservation['source_received_ms'] ?? null)!==$anchor['received_ms']
        || ($original['state'] ?? null)!=='open' || ($original['category'] ?? null)!==($finding['category'] ?? null)
        || ($original['priority'] ?? null)!==($finding['priority'] ?? null) || ($original['handoff'] ?? null)!==($finding['handoff'] ?? null)
        || !is_string($originalObservation['reminder_body_hash'] ?? null)
        || ($currentObservation['reminder_body_hash'] ?? null)!==$originalObservation['reminder_body_hash']) return null;
    $originalTriage = agent_email_triage_find_v1($pdo,$scope,$anchor['source_triage_id']);
    if ($originalTriage === null || agent_email_matters_received_ms_v1($originalTriage['received_ms'] ?? null)!==$anchor['received_ms']) return null;
    $prior = vp3_coordination_gmail_material_v1($scope,$originalTriage,$original,$threadRef);
    if (($prior['group_key'] ?? null)!==$current['group_key'] || ($prior['revision'] ?? null)!==$current['revision']) return null;
    return ['source_triage_id'=>$anchor['source_triage_id'],'received_ms'=>$anchor['received_ms'],'finding_enc'=>$anchor['finding_enc']];
}
