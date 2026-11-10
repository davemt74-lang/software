<?php
declare(strict_types=1);

/** Interpret only direct meeting commands. Source selection and every write are
 * independently authorized by the meeting action coordinator. */
function vp3_meeting_command_text_v1(string $query): string
{
    if (strlen($query)>24000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$query)) return '';
    $query=trim(preg_replace('/\s+/u',' ',$query)??'');
    $query=preg_replace('/\A(?:(?:could|can|would) you (?:please )?|please )/iu','',$query)??'';
    return rtrim($query," .?!");
}

function vp3_meeting_command_intent_v1(string $query): bool
{
    $q=vp3_meeting_command_text_v1($query);
    if (preg_match('/\A(?:Confirm|Cancel) meeting action [1-9][0-9]{0,15}\z/iD',$q)) return true;
    return (bool)(preg_match('/\A(?:turn|convert|make) .*(?:follow[- ]?up|commitment|action item|meeting finding).* (?:into|as) (?:a )?task\z/iuD',$q)
        ||preg_match('/\A(?:create|add) (?:a )?task (?:from|for) .*(?:follow[- ]?up|commitment|action item|meeting finding).*\z/iuD',$q)
        ||preg_match('/\Aremind me\b.*\bbefore\b.*\bmeeting(?:\s+#[1-9][0-9]*|\s+"[^"\r\n]+")?\z/iuD',$q)
        ||preg_match('/\Aadd .+ to (?:the |this |that |my |my next )?(?:meeting(?:\s+#[1-9][0-9]*|\s+"[^"\r\n]+")? )?agenda\z/iuD',$q)
        ||preg_match('/\A(?:move|reschedule|cancel) (?:the |this |that |my |my next )?(?:video )?meeting\b/iu',$q));
}

/** Only explicit ids and quoted titles select a meeting. Ordinary prose never
 * becomes a fuzzy match against the owner's Calendar. */
function vp3_meeting_command_selector_v1(string $query): array
{
    $q=vp3_meeting_command_text_v1($query);
    if (preg_match('/\bmeeting\s+#([1-9][0-9]{0,15})\b/iu',$q,$m)) return ['meeting_id'=>(int)$m[1]];
    if (preg_match('/\bmeeting\s+"([^"\r\n]{1,190})"/u',$q,$m)) return ['title'=>$m[1]];
    return [];
}

function vp3_meeting_command_clarify_v1(string $answer,array $choices=[]): array
{
    return ['status'=>'clarify','answer'=>$answer,'choices'=>$choices];
}

function vp3_meeting_command_finding_v1(string $query,array $selected): array
{
    $all=array_values(array_filter($selected['findings']??[],static fn($f):bool=>is_array($f)
        &&is_string($f['ref']??null)&&is_string($f['label']??null)&&trim($f['label'])!==''
        &&($f['kind']??'')!=='meeting'));
    if (preg_match('/\b((?:agenda|task):[1-9][0-9]{0,15}|transcript:[1-9][0-9]{0,15}:segment:[1-9][0-9]{0,15})\b/u',$query,$m)) {
        $exact=array_values(array_filter($all,static fn(array $f):bool=>$f['ref']===$m[1]));
        return count($exact)===1?['finding'=>$exact[0]]:vp3_meeting_command_clarify_v1('That finding is no longer available in the selected meeting. Ask for current meeting prep.');
    }
    $findingQuery=preg_replace('/\bmeeting\s+"[^"\r\n]+"/u','meeting',$query)??$query;
    if (preg_match_all('/"([^"\r\n]+)"/u',$findingQuery,$quotes)) {
        $exact=[];
        foreach ($all as $f) foreach ($quotes[1] as $quote) if (mb_strtolower($f['label'])===mb_strtolower($quote)) $exact[$f['ref']]=$f;
        if (count($exact)===1) return ['finding'=>array_values($exact)[0]];
        return vp3_meeting_command_clarify_v1('Quote the complete finding text or include its exact reference from the current meeting prep.',
            array_map(static fn(array $f):array=>['ref'=>$f['ref'],'label'=>$f['label']],array_slice($all,0,12)));
    }
    $actionable=array_values(array_filter($all,static fn(array $f):bool=>($f['actionable']??false)===true
        ||in_array($f['kind']??'',['follow_up','followup','commitment','action_item'],true)));
    if (count($actionable)===1) return ['finding'=>$actionable[0]];
    $choices=$actionable?:$all;
    if (!$choices) return vp3_meeting_command_clarify_v1('There is no available meeting finding to turn into a task. Ask for meeting prep or name the exact follow-up.');
    if (count($choices)===1) return ['finding'=>$choices[0]];
    return vp3_meeting_command_clarify_v1('Which meeting finding should become the task? Include its reference or quote its full text.',
        array_map(static fn(array $f):array=>['ref'=>$f['ref'],'label'=>$f['label']],array_slice($choices,0,12)));
}

/** UTC storage and an IANA zone must both come from the revalidated source. */
function vp3_meeting_command_times_v1(array $meeting): ?array
{
    $zone=$meeting['timezone']??null;
    if (!is_string($zone)||($zone!=='UTC'&&!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))) return null;
    $times=[];
    foreach (['start_at_utc','end_at_utc'] as $key) {
        $raw=$meeting[$key]??null;
        if (!is_string($raw)) return null;
        $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new DateTimeZone('UTC'));
        if (!$at||$at->format('Y-m-d H:i:s')!==$raw) return null;
        $times[]=$at;
    }
    $duration=$times[1]->getTimestamp()-$times[0]->getTimestamp();
    return $duration>0&&$duration<=86400?['start'=>$times[0],'end'=>$times[1],'timezone'=>$zone,'duration'=>$duration]:null;
}

function vp3_meeting_command_parse_v1(string $query,array $selectedSource,array $user,?DateTimeImmutable $now=null): array
{
    $q=vp3_meeting_command_text_v1($query);
    if (preg_match('/\A(Confirm|Cancel) meeting action ([1-9][0-9]{0,15})\z/iD',$q,$m))
        return ['status'=>strtolower($m[1])==='confirm'?'confirm':'cancel','plan_id'=>(int)$m[2]];
    if (!vp3_meeting_command_intent_v1($query)) return ['status'=>'unhandled'];
    $meeting=is_array($selectedSource['meeting']??null)?$selectedSource['meeting']:[];
    $id=$meeting['id']??$selectedSource['source']['meeting_id']??0;
    if (($selectedSource['status']??null)!=='selected'||!is_int($id)||$id<1)
        return vp3_meeting_command_clarify_v1('Which meeting should I use? Ask for meeting prep or give the meeting number.');
    if (preg_match('/\A(?:turn|convert|make)\b|\A(?:create|add) (?:a )?task\b/iu',$q)) {
        $finding=vp3_meeting_command_finding_v1($q,$selectedSource);
        if (!isset($finding['finding'])) return $finding;
        $f=$finding['finding'];
        // Keep the exact source identity even when the canonical task title has
        // less space than a meeting finding. The review displays this proposed
        // title before the owner approves the write.
        $title=mb_strlen($f['label'])>180?mb_substr($f['label'],0,179).'…':$f['label'];
        return ['status'=>'ready','request'=>['adapter'=>'task.create','finding_ref'=>$f['ref'],'arguments'=>['title'=>$title]]];
    }
    if (preg_match('/\Aremind me\b/iu',$q)) {
        if (!preg_match('/\Aremind me ([1-9][0-9]{0,4})\s*(minutes?|mins?|hours?|hrs?|days?) before (?:the |this |that |my |my next )?(?:video )?meeting(?:\s+#[1-9][0-9]*|\s+"[^"\r\n]+")?\z/iuD',$q,$m))
            return vp3_meeting_command_clarify_v1('How long before the meeting should I remind you? For example, 15 minutes before.');
        $times=vp3_meeting_command_times_v1($meeting);
        if (!$times) return vp3_meeting_command_clarify_v1('The meeting time or timezone is unavailable. Refresh its Calendar details before setting this reminder.');
        $unit=mb_strtolower($m[2]);$factor=str_starts_with($unit,'d')?86400:((str_starts_with($unit,'h'))?3600:60);
        $seconds=(int)$m[1]*$factor;
        if ($seconds>366*86400) return vp3_meeting_command_clarify_v1('Choose a reminder within one year before the meeting.');
        $at=$times['start']->modify('-'.$seconds.' seconds');
        if ($at<=($now??new DateTimeImmutable('now',new DateTimeZone('UTC'))))
            return vp3_meeting_command_clarify_v1('That reminder time has already passed. Choose a shorter interval before the meeting.');
        $title='Meeting: '.trim((string)($meeting['title']??'Upcoming meeting'));
        if (mb_strlen($title)>240) $title=mb_substr($title,0,240);
        return ['status'=>'ready','request'=>['adapter'=>'reminder.create','finding_ref'=>'meeting:'.$id,'arguments'=>['schedule'=>[
            'title'=>$title,'timezone'=>$times['timezone'],'start_local'=>$at->setTimezone(new DateTimeZone($times['timezone']))->format('Y-m-d\TH:i:s'),
            'recurrence'=>'none','delivery'=>'both']]]];
    }
    if (preg_match('/\Aadd (.+) to (?:the |this |that |my |my next )?(?:meeting(?:\s+#[1-9][0-9]*|\s+"[^"\r\n]+")? )?agenda\z/iuD',$q,$m)) {
        $text=trim($m[1]," \"'");
        if (in_array(mb_strtolower($text),['this','that','it'],true)) {
            $finding=vp3_meeting_command_finding_v1($q,$selectedSource);
            if (!isset($finding['finding'])) return $finding;
            $text=$finding['finding']['label'];
        }
        if ($text===''||mb_strlen($text)>2000) return vp3_meeting_command_clarify_v1('What text should I add to the meeting agenda? Keep it within 2,000 characters.');
        return ['status'=>'ready','request'=>['action'=>'agenda_add','meeting_id'=>$id,'item_text'=>$text,'item_type'=>'discussion','priority'=>'normal']];
    }
    if (preg_match('/\A(?:move|reschedule|cancel)\b/iu',$q)&&preg_match('/\b(?:notify|notification|send|email|invite|invitation|attendees|participants)\b/iu',$q))
        return vp3_meeting_command_clarify_v1('Use the meeting editor to change the meeting and send attendee notices together. Chat can review a local meeting change.');
    if (preg_match('/\Acancel (?:the |this |that |my |my next )?(?:video )?meeting(?:\s+#[1-9][0-9]*|\s+"[^"\r\n]+")?\z/iuD',$q))
        return ['status'=>'ready','request'=>['action'=>'cancel','meeting_id'=>$id]];
    if (preg_match('/\A(?:move|reschedule)\b/iu',$q)) {
        $times=vp3_meeting_command_times_v1($meeting);
        if (!$times) return vp3_meeting_command_clarify_v1('The meeting time or timezone is unavailable. Refresh its Calendar details before moving it.');
        if (!preg_match('/\A(?:move|reschedule) (?:the |this |that |my |my next )?(?:video )?meeting(?:\s+#[1-9][0-9]*|\s+"[^"\r\n]+")? to (\d{4}-\d{2}-\d{2}|today|tomorrow)(?: at | )([0-9]{1,2}:[0-9]{2})(?:\s*(am|pm))?(?:\s+([A-Za-z_]+\/[A-Za-z_]+(?:\/[A-Za-z_]+)?|UTC))?\z/iuD',$q,$m))
            return vp3_meeting_command_clarify_v1('What date and time should I move it to? Use YYYY-MM-DD at HH:MM. I will keep the meeting timezone and duration unless you specify a timezone.');
        $zone=$m[4]??$times['timezone'];
        if ($zone!== 'UTC'&&!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))
            return vp3_meeting_command_clarify_v1('Choose an IANA timezone such as America/Phoenix for the new meeting time.');
        $tz=new DateTimeZone($zone);$now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $date=mb_strtolower($m[1]);
        if ($date==='today'||$date==='tomorrow') $date=$now->setTimezone($tz)->modify($date==='tomorrow'?'+1 day':'+0 days')->format('Y-m-d');
        [$hour,$minute]=array_map('intval',explode(':',$m[2]));
        if (!empty($m[3])) {
            if ($hour<1||$hour>12) return vp3_meeting_command_clarify_v1('Choose a valid meeting time.');
            $hour=$hour%12+(mb_strtolower($m[3])==='pm'?12:0);
        }
        $wall=$date.'T'.sprintf('%02d:%02d:00',$hour,$minute);
        $at=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$wall,$tz);
        if (!$at||$at->format('Y-m-d\TH:i:s')!==$wall||$at<=$now)
            return vp3_meeting_command_clarify_v1('Choose a valid future meeting date and time. That local time may not exist during a clock change.');
        // Repeated fall-back wall times need an explicit unambiguous selection.
        $transitions=$tz->getTransitions($at->getTimestamp()-86400,$at->getTimestamp()+86400);
        foreach (is_array($transitions)?$transitions:[] as $i=>$transition) {
            if ($i===0) continue;
            $fold=$transitions[$i-1]['offset']-$transition['offset'];
            if ($fold>0&&$at->getTimestamp()>=$transition['ts']-$fold&&$at->getTimestamp()<$transition['ts']+$fold)
                return vp3_meeting_command_clarify_v1('That local time occurs twice during a clock change. Choose an unambiguous time or give the new time in UTC.');
        }
        $end=$at->setTimezone(new DateTimeZone('UTC'))->modify('+'.$times['duration'].' seconds')->setTimezone($tz);
        return ['status'=>'ready','request'=>['action'=>'reschedule','meeting_id'=>$id,'date'=>$at->format('Y-m-d'),'start_time'=>$at->format('H:i'),
            'end_date'=>$end->format('Y-m-d'),'end_time'=>$end->format('H:i'),'timezone'=>$zone]];
    }
    return vp3_meeting_command_clarify_v1('Please specify the meeting change you want to review.');
}
