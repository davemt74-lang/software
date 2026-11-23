<?php
declare(strict_types=1);

function vp3_phone_arrival_chat_plan_v1(string $text): ?array
{
    $search=null;
    if (preg_match('/^(when .+? tasks)[\s.,;]+(?:and )?if i (?:still )?need (.+)$/iu',$text,$parts)) {
        $text=$parts[1];
        if (!preg_match('/^(.+?),?\s+(?:then )?(?:suggest|recommend|find|look for|show(?: me)?) (?:some )?(?:nearby|local) (.+)$/iu',
            $parts[2],$fields)) return ['action'=>'arrival_search_help','target'=>''];
        $category=trim($fields[2]);
        $openToday=(bool)preg_match('/\s+(?:(?:that|which) are )?open today$/iu',$category);
        if ($openToday) $category=preg_replace('/\s+(?:(?:that|which) are )?open today$/iu','',$category);
        if (!$openToday && preg_match('/\bopen (?:now|tomorrow|tonight)\b/iu',$category)) {
            return ['action'=>'arrival_search_help','target'=>''];
        }
        try {
            $search=vp3_phone_arrival_validate_search_v1(['item_term'=>trim($fields[1]),'merchant_category'=>$category,'open_today'=>$openToday]);
        } catch (RuntimeException $error) {
            if ($error->getCode()!==422) throw $error;
            return ['action'=>'arrival_search_help','target'=>''];
        }
    }
    if (preg_match('/^when (.+?) (?:arrives? at|gets? to|reaches?) (.+?),?\s+(?:show|list|read|check|look at)(?: me)? (?:my |the )?open(?: (.+?))? tasks$/iu', $text, $m)) {
        $topic = trim($m[3] ?? '');
        if ($topic !== '' && !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} \x27’_-]{0,63}$/uD', $topic)) {
            return ['action'=>'arrival_help', 'target'=>''];
        }
        $place=preg_replace('/^[“"](.*)[”"]$/u','$1',trim($m[2]));
        return ['action'=>'arrival_setup', 'target'=>trim($m[1]), 'place'=>$place, 'topic'=>$topic, 'search_intent'=>$search];
    }
    if ($search !== null) return ['action'=>'arrival_search_help','target'=>''];
    if (preg_match('/^(?:list|show|check)(?: me)? (?:my |the )?arrival (?:alerts?|rules?)(?: status)?$/iu',$text)) {
        return ['action'=>'arrival_list','target'=>''];
    }
    if (preg_match('/^(?:stop|cancel|remove|disable) (?:my |the )?arrival (?:alert|rule)(?: for (.+?) at (.+)| #([a-f0-9]{32}))?$/iu',$text,$m)) {
        return ['action'=>'arrival_cancel', 'target'=>trim($m[1]??''), 'place'=>trim($m[2]??''), 'rule'=>$m[3]??''];
    }
    if (preg_match('/^confirm (?:the |my )?arrival (?:alert|rule)$/iu',$text)) {
        return ['action'=>'arrival_missing','target'=>''];
    }
    return null;
}

function vp3_phone_arrival_chat_topic_v1(string $topic): string
{
    return 'open '.($topic !== '' ? $topic.' ' : '').'tasks';
}

function vp3_phone_arrival_chat_offer_text_v1(array $search): string
{
    return 'If an open task still calls for '.$search['item_term'].', offer to find nearby '.$search['merchant_category']
        .($search['open_today'] ? ' open today' : '').'.';
}

function vp3_phone_arrival_chat_label_v1(string $value): string
{
    $safe = trim(preg_replace('/[\p{C}<>\s]+/u',' ',$value) ?? '');
    return $safe === '' || preg_match('/[-+]?\d{1,3}\.\d+\s*[,; ]\s*[-+]?\d{1,3}\.\d+/u',$safe) ? 'saved place' : $safe;
}

function vp3_phone_arrival_chat_phones_v1(array $devices,string $target): array
{
    $approved = array_values(array_filter($devices,static fn($d)=>$d['status']==='approved'));
    $key = vp3_phone_chat_name_key_v1($target);
    $key = preg_replace('/^(?:my|the) /u','',$key);
    $exact = array_values(array_filter($approved,static fn($d)=>
        in_array($key,[vp3_phone_chat_name_key_v1($d['name']),vp3_phone_chat_name_key_v1(vp3_phone_chat_label_v1($d))],true)));
    // "My iPhone" is a device description. Include every plausible name so even a stopped phone is never silently bypassed.
    if (in_array($key,['iphone','android','pixel'],true)) {
        return array_values(array_filter($approved,static fn($d)=>preg_match('/\b'.$key.'\b/iu',$d['name'])));
    }
    return $exact ?: vp3_phone_chat_matches_v1($approved,$target);
}

function vp3_phone_arrival_chat_reply_v1(array $draft,string $text): bool
{
    $reply = trim(mb_strtolower($text), " \t\r\n.!?");
    if (in_array($reply,['no','cancel','cancel setup','never mind','nevermind'],true)) return true;
    if ($draft['step']==='confirm') return in_array($reply,['yes','confirm','confirm arrival alert','confirm the arrival alert','confirm arrival rule'],true);
    return (bool)vp3_phone_chat_choice_matches_v1(['phones'=>$draft['phones']],$text)
        || (bool)array_filter($draft['phones'],static fn($p)=>'#'.$p['id']===$reply);
}

function vp3_phone_arrival_chat_confirm_v1(array $draft,array $phone): array
{
    if (empty($phone['allow_location']) || !in_array($phone['location_state'],['waiting','live','stale'],true)) {
        return ['Start live sharing on '.vp3_phone_chat_label_v1($phone).' before setting its arrival alert.','map','device_id'=>$phone['id']];
    }
    $draft['step']='confirm';
    $draft['phones']=[array_intersect_key($phone,array_flip(['id','generation','name']))];
    $label=vp3_phone_chat_label_v1($phone);
    $where=vp3_phone_arrival_chat_label_v1($draft['place_name']);
    $summary='When '.$label.' arrives at “'.$where.'”, check your '.vp3_phone_arrival_chat_topic_v1($draft['topic'])
        .' in this private Chat once per visit.';
    if (isset($draft['search_intent'])) {
        $summary.=' '.vp3_phone_arrival_chat_offer_text_v1($draft['search_intent'])
            .' You can choose the city or neighborhood and approve the public search when offered.';
    }
    return [$summary.' Reply “Confirm arrival alert” to ask the phone holder for permission, or “Cancel”.',
        'map','device_id'=>$phone['id'],'arrival_draft'=>$draft];
}

function vp3_phone_arrival_chat_execute_v1(PDO $pdo,int $uid,int $aid,int $cid,array $plan): array
{
    $action=$plan['action'];
    if ($action==='arrival_missing') return ['There is no current arrival alert to confirm in this Chat. Send the arrival request again.','map'];
    if ($action==='arrival_search_help') return ['Name the task topic, item and kind of business. For example, “When my iPhone arrives at Studio, check my open recording tasks. If I still need XLR cables, suggest nearby music stores open today.”',''];
    if ($action==='arrival_help') return ['Name the topic you want to match, for example “show me my open recording tasks”.',''];
    if ($action==='arrival_list' || $action==='arrival_cancel') {
        $rules=vp3_phone_arrival_list_v1($pdo,$uid,$aid,$cid);
        if (!$rules) return ['This private Chat has no arrival alerts.','map'];
        if ($action==='arrival_list') {
            $lines=[];
            foreach ($rules as $r) {
                $lines[]=vp3_phone_chat_label_v1(['name'=>$r['device_name']]).' at “'.vp3_phone_arrival_chat_label_v1($r['place_name']).'” — '
                    .vp3_phone_arrival_chat_topic_v1($r['task_query']).' ('.($r['state']==='active'?'active':'waiting for phone approval').').'
                    .(isset($r['search_intent']) ? ' '.vp3_phone_arrival_chat_offer_text_v1($r['search_intent']) : '');
            }
            return [implode("\n",$lines),'map'];
        }
        if ($plan['rule']!=='') $rules=array_values(array_filter($rules,static fn($r)=>$r['id']===$plan['rule']));
        elseif ($plan['target']!=='') {
            $roster=array_map(static fn($r)=>['id'=>$r['device_id'],'name'=>$r['device_name'],'status'=>'approved','role'=>''],$rules);
            $ids=array_column(vp3_phone_arrival_chat_phones_v1($roster,$plan['target']),'id');
            $place=vp3_phone_chat_name_key_v1($plan['place']);
            $rules=array_values(array_filter($rules,static fn($r)=>in_array($r['device_id'],$ids,true)
                && vp3_phone_chat_name_key_v1($r['place_name'])===$place));
        }
        if (!$rules) return ['I could not find that arrival alert in this private Chat. Ask me to list your arrival alerts.','map'];
        if (count($rules)>1) {
            $lines=['Which arrival alert should I stop? Reply with its command.'];
            foreach ($rules as $r) $lines[]=vp3_phone_chat_label_v1(['name'=>$r['device_name']]).' at “'.vp3_phone_arrival_chat_label_v1($r['place_name'])
                .'” — Stop arrival alert #'.$r['id'];
            return [implode("\n",$lines),'map'];
        }
        $r=$rules[0];
        vp3_phone_arrival_cancel_v1($pdo,$uid,$aid,$cid,$r['id']);
        return ['Stopped the arrival alert for '.vp3_phone_chat_label_v1(['name'=>$r['device_name']]).' at “'
            .vp3_phone_arrival_chat_label_v1($r['place_name']).'”.','map','announce'=>true,'device_id'=>$r['device_id']];
    }
    $devices=vp3_phone_owner_v1($pdo,$uid,'state',[])['devices'];
    if ($action==='arrival_setup') {
        try { $placeName=vp3_phone_place_name_v1($plan['place']); }
        catch (RuntimeException $e) {
            if ($e->getCode()!==422) throw $e;
            return ['Use the exact name of one of your saved places.','places'];
        }
        $place=vp3_phone_sql_v1($pdo,'SELECT id,name FROM vp3_phone_places_v1 WHERE owner_user_id=? AND name_hash=?',
            [$uid,hash('sha256',mb_strtolower($placeName,'UTF-8'))])->fetch(PDO::FETCH_ASSOC);
        if (!$place) return ['Which saved place? Open Saved places, then repeat the request with its exact name.','places'];
        $phones=vp3_phone_arrival_chat_phones_v1($devices,$plan['target']);
        if (!$phones) return ['Which phone should trigger the alert? Ask me to list your phones, then repeat your request with its name.','map'];
        $draft=['type'=>'arrival','step'=>'phone','place_id'=>$place['id'],'place_name'=>$place['name'],'topic'=>$plan['topic'],
            'search_intent'=>$plan['search_intent']??null];
        if (count($phones)===1) return vp3_phone_arrival_chat_confirm_v1($draft,$phones[0]);
        $labels=array_map('vp3_phone_chat_label_v1',$phones);
        $counts=array_count_values(array_map('vp3_phone_chat_name_key_v1',$labels));
        $draft['phones']=[];
        foreach ($phones as $i=>$phone) {
            $draft['phones'][]=array_intersect_key($phone,array_flip(['id','generation','name']));
            if ($counts[vp3_phone_chat_name_key_v1($labels[$i])]>1) $labels[$i].=' (#'.$phone['id'].')';
        }
        return ['Which phone? Reply with its name, or its #ID when names match. '.implode(', ',$labels).'.','map','arrival_draft'=>$draft];
    }
    $choice=vp3_phone_chat_place_choice_v1($pdo,$uid,$aid,$cid);
    $draft=$choice['arrival']??null;
    if (!$draft || (int)$choice['assistant_message_id']!==$plan['choice_id']) {
        return ['That arrival request changed. Send your arrival request again.','map'];
    }
    $reply=trim(mb_strtolower($plan['target'])," \t\r\n.!?");
    if (in_array($reply,['no','cancel','cancel setup','never mind','nevermind'],true)) return ['Arrival alert setup cancelled.',''];
    $phones=$draft['phones'];
    if ($draft['step']==='phone') {
        $phones=str_starts_with($reply,'#') ? array_values(array_filter($phones,static fn($p)=>'#'.$p['id']===$reply))
            : vp3_phone_chat_choice_matches_v1(['phones'=>$phones],$plan['target']);
        if (count($phones)!==1) return ['Several phones share that name. Reply with one of the #IDs from my question.','map','arrival_draft'=>$draft];
    } elseif (!vp3_phone_arrival_chat_reply_v1($draft,$reply)) {
        return ['Confirm the current arrival request in this private Chat.','map'];
    }
    $phone=array_column($devices,null,'id')[$phones[0]['id']]??null;
    if (!$phone || $phone['status']!=='approved' || !$phone['allow_location'] || $phone['generation']!==$phones[0]['generation']
        || !in_array($phone['location_state'],['waiting','live','stale'],true)) {
        return ['The selected phone’s sharing session changed. Start live sharing and send the arrival request again.','map'];
    }
    $place=vp3_phone_sql_v1($pdo,'SELECT name FROM vp3_phone_places_v1 WHERE owner_user_id=? AND id=?',[$uid,$draft['place_id']])->fetchColumn();
    if ($place===false || $place!==$draft['place_name']) return ['The saved place changed. Send the arrival request again.','places'];
    if ($draft['step']==='phone') return vp3_phone_arrival_chat_confirm_v1($draft,$phone);
    try {
        $rule=vp3_phone_arrival_request_v1($pdo,$uid,$aid,$cid,$phone['id'],$phone['generation'],$draft['place_id'],$draft['topic'],$draft['search_intent']??null);
    } catch (RuntimeException $error) {
        // Only turn known, user-correctable domain outcomes into a private reply. Authorization and database errors propagate.
        $reply=match ([$error->getCode(),$error->getMessage()]) {
            [409,'Start live sharing on the selected phone first.'] => ['Start live sharing on the selected phone, then send the arrival request again.','map'],
            [409,'Save a more accurate location for this place before using arrival alerts.'] => ['Save the place again from a more accurate GPS reading, then request the alert.','places'],
            [409,'Stop the existing arrival rule before changing its task topic.'] => ['This phone already has an alert for this place. To change its task topic, stop the existing alert and send the new request.','map'],
            [409,'Stop the existing arrival rule before changing its local search.'] => ['This phone already has an alert for this place. To change its local search, stop the existing alert and confirm a new one.','map'],
            [429,'Stop an unused arrival rule on this phone first.'] => ['This phone has reached its arrival alert limit. List your arrival alerts and stop one before adding another.','map'],
            [404,'Select a saved place from this account.'] => ['That place is unavailable. Choose a current saved place from this account.','places'],
            [422,'Use a task topic of up to 64 characters.'] => ['Use a task topic of up to 64 characters and send the arrival request again.',''],
            default => null
        };
        if ($reply===null) throw $error;
        return $reply;
    }
    $label=vp3_phone_chat_label_v1($phone);
    $where=vp3_phone_arrival_chat_label_v1($place);
    $answer=$rule['state']==='active' ? 'The arrival alert for '.$label.' at “'.$where.'” is active.'
        : 'Arrival alert requested for '.$label.' at “'.$where.'”. The phone holder must approve it on '.$label.'.';
    return [$answer,'map','announce'=>true,'device_id'=>$phone['id']];
}
