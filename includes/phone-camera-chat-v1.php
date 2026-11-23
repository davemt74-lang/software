<?php
declare(strict_types=1);

require_once __DIR__.'/phone-camera-v1.php';

/** Explicit private camera commands. Camera capture on the phone always requires a separate holder action. */
function vp3_phone_camera_chat_plan_v1(string $text): ?array
{
    if (preg_match('/^use (.+?) as (?:a |the |my )?(?:private )?camera$/iuD',$text,$match)) {
        $target=trim($match[1]);
        return ['action'=>'camera_request','target'=>$target,
            'implicit'=>!preg_match('/\b(?:phone|iphone|android|pixel|device)\b/iu',$target)];
    }
    if (preg_match('/^stop using (.+?) as (?:a |the |my )?(?:private )?camera$/iuD',$text,$match)
        || preg_match('/^stop sharing (.+?)(?:[’\x27]s)? camera$/iuD',$text,$match)
        || preg_match('/^stop (?:sharing (?:the )?(?:phone )?camera|(?:phone )?camera sharing|(?:the |my )?(?:private |phone )camera preview)(?: (?:from|on|for) (.+))?$/iuD',$text,$match)) {
        $target=trim($match[1]??'');
        return ['action'=>'camera_stop','target'=>$target,
            'implicit'=>$target!=='' && !preg_match('/\b(?:phone|iphone|android|pixel|device)\b/iu',$target)];
    }
    if (preg_match('/^(?:(?:show|check) (?:my |the )?)?(?:phone camera|private camera|camera sharing) status(?: (?:for|on) (.+))?$/iuD',$text,$match)) {
        return ['action'=>'camera_status','target'=>trim($match[1]??'')];
    }
    return null;
}

function vp3_phone_camera_chat_selection_v1(array $phones,string $reply): array
{
    $reply=trim($reply," \t\r\n.!?");
    if (preg_match('/^#?([1-9]\d*)$/D',$reply,$number)) return isset($phones[(int)$number[1]-1])?[$phones[(int)$number[1]-1]]:[];
    return vp3_phone_chat_choice_matches_v1(['phones'=>$phones],$reply);
}

function vp3_phone_camera_chat_reply_v1(array $draft,string $reply): bool
{
    return in_array(mb_strtolower(trim($reply," \t\r\n.!?")),['cancel','never mind','nevermind'],true)
        || (bool)vp3_phone_camera_chat_selection_v1($draft['phones'],$reply);
}

function vp3_phone_camera_chat_turn_v1(PDO $pdo,int $uid,int $aid,int $cid,array $plan): array
{
    $action=$plan['action'];
    $target=$plan['target'];
    $selected=null;
    if ($action==='camera_choice') {
        $choice=vp3_phone_chat_place_choice_v1($pdo,$uid,$aid,$cid);
        $draft=$choice['camera']??null;
        if (!$draft || (int)$choice['assistant_message_id']!==$plan['choice_id']) {
            return ['That phone choice expired. Ask for the camera again.',''];
        }
        if (in_array(mb_strtolower(trim($target," \t\r\n.!?")),['cancel','never mind','nevermind'],true)) {
            return ['Camera selection cancelled.',''];
        }
        $matches=vp3_phone_camera_chat_selection_v1($draft['phones'],$target);
        if (count($matches)!==1) {
            return ['Which phone? Reply with its number.','', 'camera_draft'=>$draft];
        }
        $action=$draft['action'];
        // The saved selection identifies the pairing. Its ID must never be interpreted as a new nickname.
        $selected=$matches[0]['id'];
    }
    if ($selected!==null) {
        $devices=vp3_phone_owner_v1($pdo,$uid,'state',[])['devices'];
        $device=array_column($devices,null,'id')[$selected]??null;
        if (!$device || $device['status']!=='approved') {
            return ['That phone is no longer paired. Ask for the camera again.',''];
        }
    } elseif ($action==='camera_request' || $target!=='') {
        $devices=vp3_phone_owner_v1($pdo,$uid,'state',[])['devices'];
        $matches=vp3_phone_arrival_chat_phones_v1($devices,$target);
        if (!$matches) return ['I could not find an approved phone by that name. Ask me to list your phones.',''];
        if (count($matches)>1) {
            $phones=[];
            $options=[];
            foreach ($matches as $i=>$phone) {
                $label=vp3_phone_chat_label_v1($phone);
                $phones[]=['id'=>$phone['id'],'name'=>$label];
                $options[]=($i+1).'. '.$label.' ('.$phone['role'].', '.substr($phone['id'],-6).')';
            }
            return ["Which phone? Reply with its number or name.\n".implode("\n",$options),'',
                'camera_draft'=>['type'=>'camera','action'=>$action,'phones'=>$phones]];
        }
        $selected=$matches[0]['id'];
    }
    $input=$selected===null?[]:['device_id'=>$selected];
    if ($action==='camera_request') {
        $camera=vp3_phone_camera_owner_v1($pdo,$uid,$aid,$cid,'request',$input)['camera'];
    } else {
        $camera=vp3_phone_camera_owner_v1($pdo,$uid,$aid,$cid,'status',$input)['camera'];
    }
    if (!$camera || $camera['state']==='stopped') return ['There is no active phone camera preview in this Chat.',''];
    $label=vp3_phone_chat_label_v1(['name'=>$camera['device_name']]);
    if ($action==='camera_stop') {
        vp3_phone_camera_owner_v1($pdo,$uid,$aid,$cid,'stop',['session_id'=>$camera['session_id']]);
        // The direct reply is the stop receipt. Store it once, even if activity polling runs immediately afterward.
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_sessions_v1 SET announced_stop=1,
            announced_start=CASE WHEN started_at IS NULL THEN 0 ELSE 1 END
            WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND id=? AND state='stopped'",
            [$uid,$aid,$cid,$camera['session_id']]);
        return ['Stopped the private camera preview for '.$label.'.','','announce'=>true];
    }
    $answer=match($camera['state']) {
        'requested'=>'Camera requested from '.$label.'. Tap Start Sharing in the announcement on that phone to preview it privately.',
        'sharing'=>$label.' has started camera sharing. Open the private preview to connect.',
        'answered'=>$label.' is sharing its camera with this private editor session. Open the preview to check the live feed.',
        default=>'The camera preview is unavailable.'
    };
    return [$answer,'camera','device_id'=>$camera['session_id'],'announce'=>$action==='camera_request'];
}

/** Persist holder transitions to the originating private conversation in the same transaction as their receipts. */
function vp3_phone_camera_chat_updates_v1(PDO $pdo,int $uid,int $aid,int $cid): array
{
    $updates=[];
    foreach (vp3_phone_camera_events_v1($pdo,$uid,$aid,$cid) as $event) {
        $label=vp3_phone_chat_label_v1(['name'=>$event['device_name']]);
        $started=$event['event']==='started';
        $answer=match ($event['event']) {
            'started'=>$label.' started sharing its camera with the private editor.',
            'cancelled'=>'The camera request for '.$label.' closed before sharing started.',
            default=>'Private camera sharing ended for '.$label.'.'
        };
        $row=vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,'',$answer,$started?'camera':'',null,null,$started?$event['session_id']:null);
        $updates[]=vp3_phone_chat_announcement_v1($row);
    }
    return $updates;
}
