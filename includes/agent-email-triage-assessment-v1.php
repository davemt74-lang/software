<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-v1.php';

/** Limit additional Gmail reads per scan and reserve time for the native transport deadline. */
function agent_email_triage_assessment_budget_v1(): array
{
    return ['remaining'=>3,'deadline_ns'=>hrtime(true)+32_000_000_000];
}

function agent_email_triage_assessment_take_v1(array &$budget): bool
{
    if (!empty($budget['provider_blocked']) || ($budget['remaining'] ?? 0)<1
        || ($budget['deadline_ns'] ?? 0)-hrtime(true)<12_000_000_000) return false;
    $budget['remaining']--;
    return true;
}

/** Metadata chooses where to spend the read budget. Priority does not establish an outcome. */
function agent_email_triage_assessment_priority_v1(array $message,bool $openMatter): int
{
    if ($openMatter) return 5;
    $subject = mb_strcut((string)($message['subject'] ?? ''),0,500,'UTF-8');
    if (preg_match('/\b(?:renew(?:al)?|deadline|due|expires?|invoice|payment|billing|insurance|subscription|membership|contract|registration|tax)\b/iu',$subject)) return 4;
    if (!empty($message['reply']) || !empty($message['important']) || agent_connection_gmail_subject_priority_signals_v1($subject)) return 3;
    if (preg_match('/\b(?:request|approval|please|confirm|meeting|schedule|action)\b/iu',$subject)) return 2;
    if (array_intersect(['CATEGORY_PROMOTIONS','CATEGORY_SOCIAL','CATEGORY_FORUMS'],(array)($message['labels'] ?? []))
        || preg_match('/\b(?:newsletter|weekly digest|flash sale|special offer)\b/iu',$subject)) return 0;
    return 1;
}

function agent_email_triage_assessment_result_v1(string $status,string $reason): array
{
    return ['status'=>$status,'reason'=>$reason,'deadline_date'=>null,'deadline_at'=>null,'reply_change'=>null];
}

/** Evaluate only current text. Attachments, snippets, earlier quotes and signatures stay out. */
function agent_email_triage_assessment_text_v1(array $message): string
{
    $plain = $html = [];
    agent_connection_gmail_body_parts_v1((array)($message['payload'] ?? []),$plain,$html);
    $text = mb_strcut(implode("\n",$plain),0,12000,'UTF-8');
    if (trim($text)==='' && $html) {
        $text = mb_strcut(implode("\n",$html),0,24000,'UTF-8');
        // Mail clients wrap earlier messages in a blockquote or in a quote container.
        $text = preg_split('~<blockquote\b|<[^>]+\bclass=["\x27][^"\x27]*(?:gmail_quote|yahoo_quoted|moz-cite-prefix)~i',$text,2)[0];
        $text = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1\s*>~is','',$text) ?? '';
        $text = preg_replace('~<br\s*/?>|</(?:p|div|li|tr|h[1-6])\s*>~i',"\n",$text) ?? '';
        $text = html_entity_decode(strip_tags($text),ENT_QUOTES | ENT_HTML5,'UTF-8');
    }
    $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',$text) ?? '';
    $lines = preg_split('/\R/u',$text) ?: [];
    $fresh = [];
    foreach (array_slice($lines,0,90) as $line) {
        if (preg_match('/\A\h*(?:>|--\h*\z|[- ]*(?:original message|forwarded message)[- :]*\z|begin forwarded message:|on\h+.{1,300}\h+wrote:\h*\z|from:\h+[^\r\n]*@)/iu',$line)) break;
        if (preg_match('/\A\h*(?:unsubscribe|manage (?:email )?preferences|view (?:in|this email in) (?:your )?browser)\b/iu',$line)) continue;
        $fresh[] = $line;
    }
    return mb_strcut(trim(implode("\n",$fresh)),0,8000,'UTF-8');
}

/** A relative date needs the source message's date and a known timezone. */
function agent_email_triage_assessment_reference_v1(array $message,int $received,?DateTimeZone $zone): ?DateTimeImmutable
{
    if ($received<1) return null;
    $values = [];
    foreach ((array)($message['payload']['headers'] ?? []) as $header) {
        if (is_array($header) && strcasecmp((string)($header['name'] ?? ''),'Date')===0 && is_string($header['value'] ?? null)) $values[] = trim($header['value']);
    }
    if (count($values)===1 && preg_match('/\A(?:[A-Za-z]{3},\h+)?\d{1,2}\h+[A-Za-z]{3}\h+\d{4}\h+\d{2}:\d{2}(?::\d{2})?\h+[+-]\d{4}(?:\h+\([A-Za-z ]{1,25}\))?\z/D',$values[0])) {
        try {
            $date = new DateTimeImmutable($values[0]);
            if (abs($date->getTimestamp()-$received)<=86400 && $date->getTimestamp()<=$received+300) return $date;
        } catch (Exception) {
            // The provider's received time is the next evidence source.
        }
    }
    return $zone === null ? null : (new DateTimeImmutable('@'.$received))->setTimezone($zone);
}

function agent_email_triage_assessment_deadline_v1(string $phrase,?DateTimeImmutable $reference): ?array
{
    $phrase = trim($phrase);
    $leadingClock = '';
    if (preg_match('/\A((?:\d{1,2}(?::[0-5]\d)?\h*(?:am|pm)|\d{1,2}:[0-5]\d)(?:\h*(?:UTC|GMT|Z|[+-]\d{2}:?\d{2}))?)\h+(?=today\b|tomorrow\b|\d{4}-|[A-Za-z]{3,9}\.?(?:\h+\d))/i',$phrase,$leading)) {
        $leadingClock = $leading[1];
        $phrase = substr($phrase,strlen($leading[0]));
    }
    $date = null;
    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?=T\d|[^\p{L}\p{N}_-]|\z)/u',$phrase,$match)) {
        if ((int)$match[1]<1970 || !checkdate((int)$match[2],(int)$match[3],(int)$match[1])) return null;
        $date = $match[0];
    } elseif (preg_match('/\A(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\h+(\d{1,2})(?:st|nd|rd|th)?,?\h+(\d{4})\b/i',$phrase,$match)) {
        $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        $month = $months[strtolower(substr($match[1],0,3))];
        if ((int)$match[3]<1970 || !checkdate($month,(int)$match[2],(int)$match[3])) return null;
        $date = sprintf('%04d-%02d-%02d',(int)$match[3],$month,(int)$match[2]);
    } elseif ($reference !== null && preg_match('/\A(today|tomorrow)\b/i',$phrase,$match)) {
        $date = (strcasecmp($match[1],'tomorrow')===0 ? $reference->modify('+1 day') : $reference)->format('Y-m-d');
    }
    if ($date === null) return null;

    $tail = substr($phrase,strlen($match[0]));
    if (preg_match('/\A\h+or\b/i',$tail)) return null;
    if ($leadingClock!=='') $tail = ' at '.$leadingClock.$tail;
    $at = null;
    if (preg_match('/\A(?:T|\h+(?:at\h+)?)(\d{1,2})(?::([0-5]\d)(?::([0-5]\d))?)?\h*(am|pm)?\h*(UTC|GMT|Z|[+-]\d{2}:?\d{2})(?![\w:])/i',$tail,$clock)) {
        $hour = (int)$clock[1];
        $period = strtolower($clock[4]);
        if ($period !== '') {
            if ($hour<1 || $hour>12) return null;
            $hour = $hour%12+($period==='pm'?12:0);
        } elseif ($hour>23 || $clock[2]==='') return null;
        $offset = strtoupper($clock[5]);
        if (in_array($offset,['UTC','GMT','Z'],true)) $offset = '+00:00';
        elseif (!preg_match('/\A[+-](?:0\d|1[0-3]):?[0-5]\d\z|\A[+-]14:?00\z/D',$offset)) return null;
        if (strlen($offset)===5) $offset = substr($offset,0,3).':'.substr($offset,3);
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP',sprintf('%s %02d:%02d:%02d%s',$date,$hour,(int)$clock[2],(int)$clock[3],$offset));
        if ($instant !== false) $at = $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    return ['deadline_date'=>$date,'deadline_at'=>$at];
}

/** Return only structured observations. A message can state a commitment, not complete it. */
function agent_email_triage_assessment_observe_v1(string $text,?DateTimeImmutable $reference): array
{
    $deadline = null;
    $unclear = false;
    $deadlines = [];
    $pattern = '/\b(?:due(?!\h+to\b)(?:\h+(?:on|by))?|deadline(?:\h+is)?|'
        .'(?:renewal|subscription|license|policy|contract|coverage|registration)\h+expires(?:\h+on)?|'
        .'(?:reply|respond|renew|pay|confirm|send|submit|sign|complete|deliver|review)\h+(?:[^\r\n.!?]{1,60}?\h+)?(?:by|no later than))'
        .'\h*:?\h+(?=(?<phrase>[^\r\n]{1,100}))/iu';
    if (preg_match_all($pattern,$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $prefix = substr($text,max(0,$match[0][1]-55),min(55,$match[0][1]));
            if (preg_match('/\b(?:not|never|no(?: longer)?)\h+(?:[\p{L}]+\h+){0,2}\z/iu',$prefix)
                || preg_match('/\A(?:not|none|waived|cancelled|canceled)\b/iu',$match['phrase'][0])) continue;
            $phrase = $match['phrase'][0];
            if (preg_match('/\A(?:[$£€]\h*|(?:USD|CAD|GBP|EUR)\h+)\d[\d,.]*/u',$phrase,$amount)) {
                // Statements of account often have an amount due without a deadline.
                if (!preg_match('/\A\h+(?:on|by|no later than)\h+(.+)/i',substr($phrase,strlen($amount[0])),$payment)) continue;
                $phrase = $payment[1];
            }
            $candidate = agent_email_triage_assessment_deadline_v1($phrase,$reference);
            if ($candidate === null) $unclear = true;
            else $deadlines[$candidate['deadline_date'].':'.($candidate['deadline_at'] ?? '')] = $candidate;
        }
    }
    if (count($deadlines)===1 && !$unclear) $deadline = reset($deadlines);
    $request = preg_match('/\b(?:please|could you|can you|would you|we need you to|you must)\h+'
        .'(?!(?:do\h+not|not|never|no\h+longer)\b)(?:[\p{L}]+\h+){0,2}'
        .'(?:reply|respond|renew|pay|confirm|send|submit|sign|complete|deliver|review|approve|reschedule)\b/iu',$text)===1;
    $change = match (true) {
        preg_match('/\b(?:can no longer|cannot make|won[\x27’]t be able|declin(?:e|ed)|(?:is|was|has been) cancelled)\b/iu',$text)===1 => 'declined_commitment',
        preg_match('/\b(?:rescheduled|(?:please|can we|could we|need to)\h+(?:move|reschedule|change)|new (?:date|time|deadline)\h+(?:is|will be))\b/iu',$text)===1 => 'changed_commitment',
        preg_match('/\b(?:i (?:can confirm|accept|approve)|we (?:can confirm|accept|approve)|(?:is|has been) confirmed|that works for (?:me|us))\b/iu',$text)===1 => 'confirmed_commitment',
        $request => 'new_request',
        default => null,
    };
    return ['reason'=>$deadline!==null?'deadline':(($unclear||count($deadlines)>1)?'deadline_unclear':($request?'action_requested':'no_action_evidence')),
        'deadline_date'=>$deadline['deadline_date']??null,'deadline_at'=>$deadline['deadline_at']??null,'candidate'=>$change];
}

/** Verify an exact recent RFC parent, without reading another message body. */
function agent_email_triage_assessment_linked_reply_v1(string $access,array $mail,?callable $http): bool
{
    $parent = trim($mail['in_reply_to']);
    if (!agent_connection_gmail_reply_rfc_id_v1($parent)) return false;
    $auth = $mail['authentication_results'];
    if (preg_match('/\Amx\.google\.com\s*;/i',$auth) && preg_match('/;\s*dmarc\s*=\s*fail\b/i',$auth)) return false;
    $threadId = $mail['thread_id'];
    $fields = 'id,messages(id,threadId,internalDate,labelIds,payload(headers))';
    $thread = agent_connection_gmail_api_v1($access,'/threads/'.$threadId
        .'?format=metadata&metadataHeaders=Message-ID&fields='.rawurlencode($fields),null,$http);
    if (($thread['id']??null)!==$threadId || !is_array($thread['messages']??null) || !array_is_list($thread['messages'])) return false;
    $seenCurrent = $seenParent = false;
    foreach (array_slice($thread['messages'],-12) as $raw) {
        if (!is_array($raw) || ($raw['threadId']??null)!==$threadId) return false;
        $item = agent_connection_gmail_message_v1($raw);
        if (array_intersect(['DRAFT','TRASH','SPAM'],$item['labels'])) continue;
        if ($item['message_id']===$mail['message_id'] && $item['received_at_ms']===$mail['received_at_ms']) $seenCurrent = true;
        if ($item['message_id']!==$mail['message_id'] && $item['received_at_ms']>0 && $item['received_at_ms']<=$mail['received_at_ms']
            && $item['rfc_message_id']===$parent) $seenParent = true;
    }
    return $seenCurrent && $seenParent;
}

/** The caller owns the authorized scan lease. No email text crosses this function's result boundary. */
function agent_email_triage_assess_v1(string $access,array $message,bool $openMatter,callable $claimCurrent,array &$budget,
    ?callable $http=null,?DateTimeZone $zone=null): array
{
    if (agent_email_triage_assessment_priority_v1($message,$openMatter)===0) return agent_email_triage_assessment_result_v1('not_needed','bulk_metadata');
    $id = agent_connection_gmail_id_v1($message['message_id']??null);
    $thread = agent_connection_gmail_id_v1($message['thread_id']??null);
    $sender = agent_connection_gmail_address_v1($message['from']??null);
    $received = filter_var($message['received_at_ms']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($id==='' || $thread==='' || $sender==='' || $received===false) return agent_email_triage_assessment_result_v1('unavailable','source_unverified');
    if (!$claimCurrent()) return agent_email_triage_assessment_result_v1('revoked','claim_changed');
    if (!agent_email_triage_assessment_take_v1($budget)) return agent_email_triage_assessment_result_v1('deferred','read_budget');

    try {
        $raw = agent_connection_gmail_get_v1($access,$id,true,$http);
        if (!$claimCurrent()) return agent_email_triage_assessment_result_v1('revoked','claim_changed');
        $mail = agent_connection_gmail_message_v1($raw);
        if ($mail['thread_id']!==$thread || $mail['received_at_ms']!==$received || agent_connection_gmail_address_v1($mail['from'])!==$sender
            || array_intersect(['SENT','DRAFT','TRASH','SPAM'],$mail['labels'])) {
            return agent_email_triage_assessment_result_v1('unavailable','source_changed');
        }
        $text = agent_email_triage_assessment_text_v1($raw);
        if ($text==='') return agent_email_triage_assessment_result_v1('unavailable','body_unavailable');
        $observation = agent_email_triage_assessment_observe_v1($text,agent_email_triage_assessment_reference_v1($raw,intdiv($received,1000),$zone));
    } catch (AgentConnectionGmailExceptionV1 $error) {
        if (!$claimCurrent()) return agent_email_triage_assessment_result_v1('revoked','claim_changed');
        if (in_array($error->getCode(),[401,403,429],true)) $budget['provider_blocked'] = true;
        return agent_email_triage_assessment_result_v1('unavailable',(int)$error->getCode()===404?'message_unavailable':'provider_unavailable');
    }

    $result = ['status'=>'assessed','reason'=>$observation['reason'],'deadline_date'=>$observation['deadline_date'],
        'deadline_at'=>$observation['deadline_at'],'reply_change'=>null];
    if (!$openMatter || empty($message['reply'])) return $result;
    if ($result['reason']==='no_action_evidence') $result['reason'] = 'reply_observed';
    if ($observation['candidate']===null) return $result;
    if (!$claimCurrent()) return agent_email_triage_assessment_result_v1('revoked','claim_changed');
    if (!agent_email_triage_assessment_take_v1($budget)) return $result + ['thread_status'=>'deferred'];

    try {
        $linked = agent_email_triage_assessment_linked_reply_v1($access,$mail,$http);
        if (!$claimCurrent()) return agent_email_triage_assessment_result_v1('revoked','claim_changed');
        if (!$linked) return $result + ['thread_status'=>'unlinked'];
    } catch (AgentConnectionGmailExceptionV1 $error) {
        if (!$claimCurrent()) return agent_email_triage_assessment_result_v1('revoked','claim_changed');
        if (in_array($error->getCode(),[401,403,429],true)) $budget['provider_blocked'] = true;
        return $result + ['thread_status'=>'unavailable'];
    }
    $result['reply_change'] = $observation['candidate'];
    if (in_array($result['reason'],['reply_observed','no_action_evidence'],true)) $result['reason'] = 'reply_change';
    return $result + ['thread_status'=>'verified'];
}
