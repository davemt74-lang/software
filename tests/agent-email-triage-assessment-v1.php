<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-email-triage-assessment-v1.php';

function triage_assessment_assert(bool $passed,string $description): void
{
    if (!$passed) throw new RuntimeException($description);
    echo "PASS ".$description.PHP_EOL;
}

$reference = new DateTimeImmutable('2026-11-11 23:55:00',new DateTimeZone('America/Phoenix'));
$received = $reference->getTimestamp()*1000;
$anchor = ['message_id'=>'renewal_1','thread_id'=>'thread_1','received_at_ms'=>(string)$received,
    'from'=>'supplier@example.test','subject'=>'Annual renewal','reply'=>false,'important'=>false,'labels'=>['INBOX','CATEGORY_PROMOTIONS']];
$renewal = ['id'=>'renewal_1','threadId'=>'thread_1','internalDate'=>(string)$received,'labelIds'=>['INBOX','CATEGORY_PROMOTIONS'],
    'payload'=>['mimeType'=>'text/plain','headers'=>[
        ['name'=>'From','value'=>'Supplier <supplier@example.test>'],
        ['name'=>'Date','value'=>'Wed, 11 Nov 2026 23:55:00 -0700'],
        ['name'=>'Message-ID','value'=>'<renewal_1@example.test>'],
    ],'body'=>['data'=>rtrim(strtr(base64_encode("Your subscription renewal is due tomorrow.\nPRIVATE BODY SENTINEL"),'+/','-_'),'=')]]];
$reply = ['id'=>'reply_1','threadId'=>'thread_2','internalDate'=>(string)($received+1000),'labelIds'=>['INBOX'],
    'payload'=>['mimeType'=>'text/plain','headers'=>[
        ['name'=>'From','value'=>'Supplier <supplier@example.test>'],
        ['name'=>'In-Reply-To','value'=>'<parent_1@example.test>'],
        ['name'=>'Message-ID','value'=>'<reply_1@example.test>'],
    ],'body'=>['data'=>base64_encode("Could we reschedule the delivery?\nPRIVATE REPLY SENTINEL")]]];
$replyAnchor = array_replace($anchor,['message_id'=>'reply_1','thread_id'=>'thread_2','received_at_ms'=>$received+1000,'subject'=>'Re: Delivery','reply'=>true,'labels'=>['INBOX']]);
$thread = ['id'=>'thread_2','messages'=>[
    ['id'=>'parent_1','threadId'=>'thread_2','internalDate'=>(string)($received-1000),'labelIds'=>['SENT'],
        'payload'=>['headers'=>[['name'=>'Message-ID','value'=>'<parent_1@example.test>']]]],
    ['id'=>'reply_1','threadId'=>'thread_2','internalDate'=>(string)($received+1000),'labelIds'=>['INBOX']],
]];
$claims = true;
$clearAfterRead = false;
$responses = [];
$requests = [];
$http = static function(string $method,string $url,array $headers,?array $body,bool $form)
    use (&$requests,&$responses,&$claims,&$clearAfterRead): array {
    triage_assessment_assert($method==='GET' && $body===null && !$form && str_starts_with($url,VP3_GMAIL_API_V1.'/'),
        'assessment uses only the existing read-only Gmail transport');
    $requests[] = substr($url,strlen(VP3_GMAIL_API_V1));
    if ($clearAfterRead) $claims = false;
    if (!$responses) throw new LogicException('Unexpected Gmail read in assessment fixture.');
    return array_shift($responses);
};
$current = static function() use (&$claims): bool { return $claims; };
$ok = static fn(array $data): array => ['status'=>200,'json'=>$data];
$budget = agent_email_triage_assessment_budget_v1();

for ($i=0;$i<23;$i++) {
    $promo = array_replace($anchor,['message_id'=>'promotion_'.$i,'subject'=>'Weekly sale']);
    $result = agent_email_triage_assess_v1('fixture-token',$promo,false,$current,$budget,$http);
    if ($result['status']!=='not_needed') throw new RuntimeException('Routine promotion consumed the body assessment budget.');
}
triage_assessment_assert($requests===[] && $budget['remaining']===3,
    'twenty-three obvious promotional messages consume zero body or thread reads');
triage_assessment_assert(agent_email_triage_assessment_priority_v1($replyAnchor,true)
    >agent_email_triage_assessment_priority_v1($anchor,false),
    'an open matter and transactional deadline outrank unclassified and bulk metadata');

$responses = [$ok($renewal),$ok($reply),$ok($thread)];
$result = agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$budget,$http);
triage_assessment_assert($result['status']==='assessed' && $result['reason']==='deadline' && $result['deadline_date']==='2026-11-12'
    && $result['deadline_at']===null && !str_contains(json_encode($result,JSON_THROW_ON_ERROR),'SENTINEL'),
    'exact body read resolves tomorrow in the message date zone and keeps date-only evidence separate from an instant');
$result = agent_email_triage_assess_v1('fixture-token',$replyAnchor,true,$current,$budget,$http);
triage_assessment_assert($result['status']==='assessed' && $result['reply_change']==='changed_commitment'
    && $result['thread_status']==='verified' && $result['reason']==='reply_change'
    && count($requests)===3 && str_starts_with($requests[2],'/threads/thread_2?format=metadata')
    && !str_contains(json_encode($result,JSON_THROW_ON_ERROR),'SENTINEL'),
    'exact current and recent parent context support a reply change without retaining content or claiming completion');
$result = agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$budget,$http);
triage_assessment_assert($result['status']==='deferred' && count($requests)===3,
    'exhausted shared budget preserves a deferred outcome without another call');

$lateBudget = ['remaining'=>3,'deadline_ns'=>hrtime(true)+1_000_000_000];
triage_assessment_assert(agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$lateBudget,$http)['status']==='deferred'
    && $lateBudget['remaining']===3,'near deadline leaves enough time for the existing transport to finish');

$text = agent_email_triage_assessment_observe_v1("Please submit the renewal by November 12, 2026 at 5 PM -0700.",$reference);
triage_assessment_assert($text['reason']==='deadline' && $text['deadline_date']==='2026-11-12'
    && $text['deadline_at']==='2026-11-13T00:00:00Z','explicit timestamp is normalized while preserving the source calendar date');
$text = agent_email_triage_assessment_observe_v1("Payment due 2026-11-12T23:59:59Z.",null);
triage_assessment_assert($text['deadline_at']==='2026-11-12T23:59:59Z','an explicit ISO deadline preserves the supplied seconds');
$text = agent_email_triage_assessment_observe_v1('Please confirm by 5 PM -0700 tomorrow.',$reference);
triage_assessment_assert($text['deadline_at']==='2026-11-13T00:00:00Z','an explicit time before a relative date uses the verified source timezone');
triage_assessment_assert(agent_email_triage_assessment_observe_v1('Total amount due $0.00.',$reference)['reason']==='no_action_evidence'
    && agent_email_triage_assessment_observe_v1('Payment due $250 by 2026-11-12.',$reference)['deadline_date']==='2026-11-12',
    'an amount due alone stays informational while an explicit payment date surfaces');
foreach ([
    'Renewal is due 2026-02-30.',
    'Renewal is due tomorrow.',
    'Payment is due 2026-11-12 and the signature is due 2026-11-14.',
    'Payment is due tomorrow or Friday.',
] as $index=>$text) {
    $result = agent_email_triage_assessment_observe_v1($text,$index===3?$reference:null);
    triage_assessment_assert($result['reason']==='deadline_unclear' && $result['deadline_date']===null,
        'ambiguous or impossible deadline remains for review (case '.($index+1).')');
}
triage_assessment_assert(agent_email_triage_assessment_observe_v1('You do not need to reply by tomorrow. No payment is due until further notice.',$reference)['reason']==='no_action_evidence',
    'explicitly negated deadlines do not create a deadline');
triage_assessment_assert(agent_email_triage_assessment_observe_v1('Please do not send the previous form.',$reference)['reason']==='no_action_evidence',
    'a direct negation does not become a request to send');

$quoted = $renewal;
$quoted['payload']['body']['data'] = base64_encode("Thanks for the heads-up.\n\nOn Monday, Supplier wrote:\n> Your renewal is due tomorrow.");
$quoted['snippet'] = 'Your renewal is due tomorrow.';
triage_assessment_assert(agent_email_triage_assessment_observe_v1(agent_email_triage_assessment_text_v1($quoted),$reference)['reason']==='no_action_evidence',
    'quoted old requests and Gmail snippets cannot create new urgency');
$quoted['payload']['mimeType'] = 'text/html';
$quoted['payload']['body']['data'] = base64_encode('<p>Thanks.</p><div class="gmail_quote"><p>Renewal due tomorrow.</p></div>');
triage_assessment_assert(agent_email_triage_assessment_text_v1($quoted)==='Thanks.',
    'HTML quoted history is excluded from current message analysis');

$budget = agent_email_triage_assessment_budget_v1();
$responses = [$ok(array_replace($renewal,['threadId'=>'unrelated_thread'])),['status'=>429,'json'=>[]],$ok($renewal)];
$result = agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$budget,$http);
triage_assessment_assert($result['status']==='unavailable' && $result['reason']==='source_changed','a substituted thread cannot supply body evidence');
$result = agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$budget,$http);
triage_assessment_assert($result['status']==='unavailable' && $result['reason']==='provider_unavailable','a provider failure remains an unavailable assessment');
$before = count($requests);
$result = agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$budget,$http);
triage_assessment_assert($result['status']==='deferred' && $budget['provider_blocked'] === true && count($requests)===$before,
    'a throttled Gmail response stops the remaining optional assessment reads');
$budget = agent_email_triage_assessment_budget_v1();
$clearAfterRead = true;
$result = agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$budget,$http);
triage_assessment_assert($result['status']==='revoked' && $result['deadline_date']===null,'a revoked claim discards the returned body before analysis');
$before = count($requests);
$budget = agent_email_triage_assessment_budget_v1();
$result = agent_email_triage_assess_v1('fixture-token',$anchor,false,$current,$budget,$http);
triage_assessment_assert($result['status']==='revoked' && count($requests)===$before,'a revoked claim performs no provider request');

$claims = true;
$clearAfterRead = false;
$thread['messages'][0]['payload']['headers'][0]['value'] = '<different_parent@example.test>';
$responses = [$ok($reply),$ok($thread)];
$result = agent_email_triage_assess_v1('fixture-token',$replyAnchor,true,$current,$budget,$http);
triage_assessment_assert($result['status']==='assessed' && $result['reply_change']===null && $result['thread_status']==='unlinked',
    'same thread without the exact recent RFC parent cannot establish a commitment change');

echo "Email triage body and recent-thread assessment passed.\n";
