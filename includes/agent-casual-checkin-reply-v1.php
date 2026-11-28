<?php
declare(strict_types=1);

require_once __DIR__.'/agent-specialist-starters-v1.php';

function vp3_casual_checkin_intent_v1(string $text): string
{
    return match(true){
        preg_match('~\A(?:(?:open|review|choose|the) )?(?:1|first)(?: draft| one)?[.?!]*\z~iu',$text)===1=>'draft_1',
        preg_match('~\A(?:(?:open|review|choose|the) )?(?:2|second)(?: draft| one)?[.?!]*\z~iu',$text)===1=>'draft_2',
        preg_match('~\A(?:(?:open|review|choose|the) )?(?:3|third)(?: draft| one)?[.?!]*\z~iu',$text)===1=>'draft_3',
        preg_match('~\A(?:(?:yes|yeah|yep|sure|ok(?:ay)?|go ahead)(?:[ ,]+(?:please|let[\x{2019}\x27]s do it))?|let[\x{2019}\x27]s do it)[.?!]*\z~iu',$text)===1=>'accept',
        preg_match('~\A(?:no(?:,? (?:thanks|thank you))?|skip(?: it| this one| that one)?)[.?!]*\z~iu',$text)===1=>'decline',
        preg_match('~\A(?:not (?:right )?now|(?:maybe )?later|another time)[.?!]*\z~iu',$text)===1=>'defer',
        preg_match('~\A(?:(?:yes|sure)[, ]+)?(?:please )?(?:music(?:,? please)?|listen to (?:some )?music|play (?:some )?music)[.?!]*\z~iu',$text)===1=>'music',
        preg_match('~\A(?:(?:yes|sure)[, ]+)?(?:please )?(?:(?:a )?video|(?:watch|play) a video|watch something(?: on YouTube)?|YouTube)(?:,? please)?[.?!]*\z~iu',$text)===1=>'youtube',
        preg_match('~\A(?:(?:yes|sure)[, ]+)?(?:please )?play something[.?!]*\z~iu',$text)===1=>'play',
        default=>'clarify',
    };
}

/** Resolve the current offer, or a deliberately selected historical offer in the same private Chat. */
function vp3_casual_checkin_reply_target_v1(PDO $pdo,array $user,int $agent,int $cid,string $text,?array $explicit=null): ?array
{
    if($cid<1||vp3_participant_scope_v1()!==null)return null;
    $text=trim($text);
    if($text===''||mb_strlen($text)>6000)return null;
    $intent=vp3_casual_checkin_intent_v1($text);
    $youtubeAcceptance=false;
    if($intent==='clarify'){
        require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
        $youtubeAcceptance=agent_connection_youtube_offer_reply_v1($text)
            ||preg_match('~\A(?:please )?watch (?:it|that(?: one)?)[.!?]*\z~iu',$text)===1;
        if(!$youtubeAcceptance&&$explicit===null)return null;
    }

    // A specific playback reply can still target the adjacent Media offer if
    // the only newer messages are open Curiosity questions. Ordinary answers
    // and bare assent continue to belong to the latest Chat turn.
    $playbackFollowup=$explicit===null&&$youtubeAcceptance
        &&preg_match('/\b(?:it|that(?: one)?|the (?:video|music|pick))\b/iu',$text)===1;
    $limit=$playbackFollowup?5:1;
    $agentWhere=$agent>0?'c.user_agent_id=?':'c.user_agent_id IS NULL';
    $exact=$explicit===null?'':' AND m.id=?';
    $read=$pdo->prepare("SELECT m.id,m.role,m.user_id,m.context_json,m.created_at FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.user_id=? AND c.id=? AND $agentWhere$exact ORDER BY m.id DESC LIMIT $limit");
    $args=[(int)$user['id'],$cid,...($agent>0?[$agent]:[])];
    $read->execute([...$args,...($explicit===null?[]:[$explicit['message_id']])]);
    if($playbackFollowup)require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    do{
        $row=$read->fetch(PDO::FETCH_ASSOC);
        $decoded=json_decode((string)($row['context_json']??''),true);
        $context=is_array($decoded)?$decoded:null;
        $refs=$context['initiative_output']['evidence_revisions']??null;
    }while($playbackFollowup&&($row['role']??null)==='assistant'&&$row['user_id']===null
        &&vp3_initiative_followthrough_coordination_question_v1($context)
        &&is_array($refs)&&count($refs)===1
        &&preg_match('/\Acuriosity:[a-f0-9]{32}:[a-f0-9]{32}\z/D',(string)array_key_first($refs))===1);
    if(($row['role']??null)!=='assistant')return null;
    if($explicit===null){
        require_once __DIR__.'/agent-initiative-followthrough-v1.php';
        if(!vp3_initiative_followthrough_recent_post_v1((string)($row['created_at']??'')))return null;
    }
    $clarification=null;
    if($explicit===null&&($context['casual_checkin_clarification_v1']??null)===true){
        $clarification=$context['initiative_followthrough']??null;
        if(!is_int($clarification['message_id']??null)||$clarification['message_id']>=(int)$row['id'])return null;
        $read=$pdo->prepare("SELECT m.id,m.role,m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE c.user_id=? AND c.id=? AND $agentWhere AND m.id=? AND m.role='assistant'");
        $read->execute([...$args,$clarification['message_id']]);
        $row=$read->fetch(PDO::FETCH_ASSOC);
        $context=json_decode((string)($row['context_json']??''),true);
    }
    $output=$context['initiative_output']??null;
    $evidence=$output['evidence_revisions']??null;
    if(($context['source']??null)!=='agent_initiative'||($output['type']??null)!=='initiative_coordination'
        ||($output['state']??null)!=='needs_owner'||!is_array($evidence)||count($evidence)!==1)return null;
    $ref=array_key_first($evidence);
    if(!is_string($ref)||(!in_array($ref,['casual:media','casual:music','casual:youtube','casual:youtube_history','casual:email','casual:today','casual:resume'],true)
        &&vp3_casual_starter_specialist_v1($ref)===null))return null;
    if($intent==='defer'&&!in_array($ref,['casual:media','casual:music','casual:youtube','casual:youtube_history'],true))$intent='decline';
    if($youtubeAcceptance&&$ref==='casual:youtube_history')$intent='accept';
    elseif($intent==='clarify'&&$explicit===null)return null;
    if($ref==='casual:media'){
        if(in_array($intent,['accept','play'],true))$intent=($output['invitation']['kind']??null)==='music'?'music':'clarify';
    }elseif(in_array($intent,['music','youtube','play'],true)){
        if($ref==='casual:'.($intent==='play'?'music':$intent)
            ||($ref==='casual:youtube_history'&&in_array($intent,['youtube','play'],true)))$intent='accept';
        elseif($explicit===null)return null;
        else $intent='clarify';
    }
    if(str_starts_with($intent,'draft_')&&($ref!=='casual:email'||($output['invitation']['kind']??null)!=='email_drafts')){
        if($explicit===null)return null;
        $intent='clarify';
    }
    $state=$context['initiative_followthrough_state']??null;
    if($explicit!==null&&($state['last_reply_explicit']??false)===true
        &&$state['reply_ref']===$explicit['reply_ref']&&$state['revision']===$explicit['revision']+1
        &&hash_equals((string)($state['last_reply_hash']??''),hash('sha256',$text))){
        return ['message_id'=>(int)$row['id'],'ref'=>$ref,'intent'=>'replay','revision'=>$state['revision'],'reply_ref'=>$state['reply_ref']];
    }
    if(($state['state']??null)!=='open'){
        if($explicit!==null)throw new RuntimeException('This check-in has already been answered.',409);
        return null;
    }
    $selection=$explicit??$clarification;
    if($selection!==null&&(($state['reply_ref']??null)!==($selection['reply_ref']??null)
        ||($state['revision']??null)!==($selection['revision']??null))){
        throw new RuntimeException('This check-in changed.',409);
    }
    return ['message_id'=>(int)$row['id'],'ref'=>$ref,'intent'=>$intent,'revision'=>$state['revision'],'reply_ref'=>$state['reply_ref']];
}

/** Take the next small step using only evidence from this offer and a live owner decision. */
function vp3_casual_checkin_reply_chat_v1(PDO $pdo,array $user,array $principal,int $cid,string $text,?array $explicit,array $target,
    string $inputMode,?callable $authority): array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-coordination-work-v1.php';
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$principal,$cid,$text,$explicit,$target,$inputMode,$authority):array{
        vp3_initiative_owner_lock_v1($pdo,$user);
        $agent=(int)$principal['agent_id'];
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
        if(vp3_casual_checkin_reply_target_v1($pdo,$live,$agent,$cid,$text,$explicit)!==$target){
            throw new RuntimeException('This check-in is no longer current.',409);
        }
        $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$live,$principal,$cid,$target['message_id'],true);
        $goal=$origin['goal'];
        $kind=substr($target['ref'],strlen('casual:'));
        if(($goal['tool']??null)!=='coordination.review'||($goal['primary']??null)!==$target['ref']
            ||($goal['coordination_route']??null)!==['specialist'=>'casual','trigger'=>$kind,'action'=>'ask']){
            throw new RuntimeException('This check-in changed.',409);
        }
        $intent=$target['intent'];
        if($intent==='replay'){
            return vp3_casual_checkin_saved_reply_v1($pdo,$origin,$text,$explicit,$inputMode)+['replayed'=>true];
        }
        if(($origin['state']['state']??null)!=='open')throw new RuntimeException('This check-in is no longer current.',409);
        $evidence=null;
        $media=in_array($kind,['media','music','youtube','youtube_history'],true);
        if(!in_array($intent,['decline','defer'],true)){
            try{
                $sources=vp3_coordination_result_evidence_v1($pdo,$live,$goal['binding'],$goal,$origin['result']['initiative_tool_result']);
                vp3_coordination_route_current_v1($pdo,$live,$goal,$sources);
                $evidence=$sources[$target['ref']];
            }catch(Vp3InitiativeSourcesChangedV1 $error){
                $evidence=null;
            }catch(RuntimeException $error){
                if(!in_array((int)$error->getCode(),[403,409],true))throw $error;
            }
        }
        $answer='That option is no longer available.';
        $stateName='stale';$actions=[];$playback=null;$youtube=null;
        if(in_array($intent,['decline','defer'],true)){
            $stateName=$intent==='defer'?'deferred':'declined';
            $answer=$media&&$intent==='decline'?'I’ll skip that one.':'Okay, we can leave that for later.';
        }elseif($evidence!==null){
            if($intent==='clarify'){
                $stateName='open';
                $question=(string)$origin['result']['initiative_tool_result']['output']['question'];
                $answer=$kind==='media'?'Which would you prefer, music or a video?':$question.' You can say yes or skip it.';
            }else{
                $stateName='owner_resolved';
                $example=VP3_SPECIALIST_STARTERS_V1[$kind]??null;
                if($example!==null){
                    $answer=$example['specialist']==='mission'
                        ?'Open the sample Mission objective, edit it if you like, then select Prepare Mission.'
                        :'Open the example in Chat, edit it if you like, then send it when you’re ready.';
                    $actions[]=['type'=>'specialist_example','specialist'=>$example['specialist'],'label'=>$example['label']];
                }else switch($kind==='media'?$intent:$kind){
                    case 'music':
                        require_once __DIR__.'/agent-media-playback-v1.php';
                        $playback=vp3_media_native_pick_action_v1($pdo,$live,$agent,$cid,
                            (int)($evidence['track_id']??0),(string)($evidence['track_revision']??''),$target['message_id']);
                        if($playback===null){
                            $stateName='stale';
                            break;
                        }
                        $trackTitle=vp3_coordination_saved_text_v1((string)($evidence['source_title']??''),145);
                        $answer=$trackTitle!==''?'I’ll open “'.$trackTitle.'” in the VP3 Media Player.':'I’ll open that pick in the VP3 Media Player.';
                        break;
                    case 'youtube':
                        if(!isset($evidence['history_playback'])&&!isset($evidence['history_topic'])){
                            $answer='What would you like to watch? Say “Search YouTube for” followed by your topic.';
                            break;
                        }
                        // A named choice reuses the same native saved-video acceptance below.
                    case 'youtube_history':
                        require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
                        $saved=$evidence['history_playback']??null;
                        if(is_array($saved)&&is_int($saved['expires_at']??null)&&$saved['expires_at']>time()
                            &&is_array($saved['videos']??null)&&count($saved['videos'])===1
                            &&($saved['videos'][0]['video_id']??null)===($saved['selected_video_id']??null)
                            &&($saved['videos'][0]['made_for_kids']??null)===false){
                            $youtube=$saved;
                            unset($youtube['source_message_id'],$youtube['play_selected']);
                            $youtube['initiative_suggestion']=true;
                            $answer='Here’s the YouTube video I offered.';
                            break;
                        }
                        $topic=vp3_coordination_saved_text_v1((string)($evidence['history_topic']??''),145);
                        if($topic!==''){
                            $answer='I’m ready to search YouTube for videos about “'.$topic.'”.';
                            $actions[]=['type'=>'prompt','label'=>'Search YouTube','prompt'=>'Search YouTube for '.$topic];
                        }else $stateName='stale';
                        break;
                    case 'email':
                        if(isset($evidence['email_draft_targets'])){
                            $targets=$evidence['email_draft_targets'];
                            $items=$evidence['invitation']['items'];
                            $selection=str_starts_with($intent,'draft_')?(int)substr($intent,6)-1:null;
                            if($selection!==null&&!isset($targets[$selection])){
                                $stateName='open';$answer='Choose one of the drafts listed in this invitation.';break;
                            }
                            $indices=$selection===null?array_keys($targets):[$selection];
                            if($selection===null&&count($targets)>1)$stateName='open';
                            foreach($indices as $index){
                                $selected=$targets[$index];
                                $actions[]=['type'=>'email_review','id'=>$selected['id'],'conversation_id'=>$selected['conversation_id'],
                                    'is_reply'=>$selected['is_reply'],'auto'=>false,'invitation_title'=>$items[$index]['title']];
                            }
                            $answer=$stateName==='open'?'Choose a draft below, or say first, second or third. Each opens for your review.'
                                :'Open the selected saved draft below. You can edit it and review it before sending.';
                            break;
                        }
                        $answer='Who should the draft go to, and what should it say? Try “Draft an email to name@example.com about our meeting.” You can review the draft in Chat.';
                        break;
                    case 'today':
                    case 'resume':
                        $title=vp3_coordination_saved_text_v1((string)($evidence['source_title']??''),160);
                        if($title===''){
                            $stateName='stale';
                            break;
                        }
                        $summary=vp3_coordination_saved_text_v1((string)($evidence['source_summary']??''),440);
                        $answer=($kind==='today'?'Next up is ':'You can pick up ').'“'.$title.'”.';
                        if($summary!==''&&$summary!==$title)$answer.=' '.$summary;
                        $url=$evidence['source_url']??null;
                        if(is_string($url)&&preg_match('~\A/(?![/\\\\])[^\\\\\p{C}\s]+\z~u',$url)===1){
                            $actions[]=['type'=>'open_url','url'=>$url,'label'=>$kind==='today'?'Open calendar item':'Open item'];
                        }
                        break;
                }
            }
        }

        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json)
            VALUES(?,?,'user',?,?)")->execute([$cid,(int)$live['id'],$text,
                '{"skip_brain_archive":true,"casual_checkin_reply_v1":true}']);
        $userMessage=(int)$pdo->lastInsertId();
        $state=$origin['state'];
        ++$state['revision'];
        $state['state']=$stateName;
        $state['last_intent']=match($stateName){'owner_resolved'=>'resolve','declined'=>'decline','deferred'=>'defer',default=>'discuss'};
        $state['last_reply_message_id']=$userMessage;
        $state['last_reply_hash']=hash('sha256',$text);
        $state['last_reply_explicit']=$explicit!==null;
        $state['updated_at']=gmdate(DATE_ATOM);
        if($stateName==='stale')$state['coordination_source_changed']=true;
        $outcome=vp3_initiative_followthrough_store_v1($pdo,$origin,$state);
        $context=['skip_brain_archive'=>true,'chat_user_message_id'=>$userMessage,'initiative_followthrough'=>$outcome,
            'initiative_coordination_reply_v1'=>true,'coordination_reply'=>true,'casual_checkin_reply_v1'=>true,'actions'=>$actions];
        if($stateName==='open')$context['casual_checkin_clarification_v1']=true;
        if($stateName==='stale')$context['initiative_coordination_source_changed_v1']=true;
        if($playback!==null)$context['media_offer_decision_v1']=['kind'=>'native','track_id'=>$playback['track_id']];
        if($youtube!==null){
            // The accepted copy is now an ordinary, expiring asset in this Chat.
            $context['youtube_search_results_v1']=true;
            $context['media_offer_decision_v1']=['kind'=>'youtube','video_id'=>$youtube['selected_video_id']];
            unset($context['initiative_coordination_reply_v1']);
        }
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json)
            VALUES(?,NULL,'assistant',?,?)")->execute([$cid,$answer,json_encode($context,JSON_THROW_ON_ERROR)]);
        $assistant=(int)$pdo->lastInsertId();
        if($youtube!==null){
            // Keep the provider’s existing expiry, and give playback the accepted result in this Chat.
            agent_connection_youtube_results_save_v1($pdo,(int)$live['id'],$cid,$assistant,$youtube);
            $youtube+=['source_message_id'=>$assistant,'play_selected'=>true];
        }
        if($media&&$stateName!=='open'){
            agent_workflow_event_v1400($pdo,(int)$live['id'],(int)$origin['run']['id'],'initiative_owner_decision','open',
                $stateName,'owner','Owner answered a saved Media offer.',[
                    'message_id'=>$target['message_id'],'reply_message_id'=>$userMessage,'revision'=>$state['revision'],
                    'intent'=>$state['last_intent'],'continuation_run_id'=>0,
                ]);
        }
        $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,(int)$live['id']]);
        return ['ok'=>true,'conversation_id'=>$cid,'user_message_id'=>$userMessage,'assistant_message_id'=>$assistant,
            'agent_id'=>$agent,'agent_name'=>(string)($principal['display_name']??'Agent'),'answer'=>$answer,
            'input_mode'=>$inputMode,'sources'=>[],'actions'=>$actions,'initiative_followthrough'=>$outcome,'coordination_reply'=>true,
            'casual_checkin_reply_v1'=>true]
            +($playback!==null?['media_playback'=>$playback]:[])+($youtube!==null?['youtube_results'=>$youtube]:[]);
    });
}

/** An exact repeat may recover the saved Chat turn. A signed playback is live-only. */
function vp3_casual_checkin_saved_reply_v1(PDO $pdo,array $origin,string $text,?array $explicit,string $inputMode): array
{
    $cid=$origin['conversation_id'];
    $state=$origin['state'];
    $outcome=vp3_initiative_followthrough_outcome_v1($origin);
    $messageId=$outcome['reply_message_id'];
    if($messageId<1||!hash_equals($state['last_reply_hash'],hash('sha256',$text))
        ||$state['last_reply_explicit']!==($explicit!==null)
        ||($explicit!==null&&$explicit!==['message_id'=>$outcome['message_id'],'reply_ref'=>$outcome['reply_ref'],
            'revision'=>$outcome['revision']-1])){
        throw new RuntimeException('The saved check-in reply changed.',409);
    }
    $q=$pdo->prepare('SELECT id,user_id,role,message,context_json FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 2');
    $q->execute([$cid]);
    [$assistant,$owner]=array_pad($q->fetchAll(PDO::FETCH_ASSOC),2,null);
    $context=json_decode((string)($assistant['context_json']??''),true);
    if(($assistant['role']??null)!=='assistant'||($owner['role']??null)!=='user'||(int)($owner['id']??0)!==$messageId
        ||(int)($owner['user_id']??0)!==$outcome['owner_user_id']||($owner['message']??null)!==$text
        ||($context['casual_checkin_reply_v1']??null)!==true||($context['initiative_followthrough']??null)!==$outcome){
        throw new RuntimeException('The conversation advanced after this check-in reply.',409);
    }
    $goal=$origin['goal'];
    if(in_array($state['state'],['owner_resolved','open'],true)&&(isset($origin['result']['initiative_tool_result']['output']['invitation'])
        ||in_array($goal['primary'],['casual:today','casual:resume','casual:youtube_history'],true)
        ||vp3_casual_starter_specialist_v1($goal['primary'])!==null)){
        vp3_coordination_result_evidence_v1($pdo,$origin['user'],$goal['binding'],$goal,$origin['result']['initiative_tool_result']);
    }
    $played=$state['state']==='owner_resolved'&&($goal['primary']==='casual:music'
        ||($goal['primary']==='casual:media'&&vp3_casual_checkin_intent_v1($text)==='music'));
    $answer=$played
        ?'I saved your answer. You can say “Start DJ” to start a new queue.':$assistant['message'];
    return ['ok'=>true,'conversation_id'=>$cid,'user_message_id'=>$messageId,'assistant_message_id'=>(int)$assistant['id'],
        'agent_id'=>$outcome['agent_id'],'agent_name'=>(string)($origin['principal']['display_name']??'Agent'),
        'answer'=>$answer,'input_mode'=>$inputMode,'sources'=>[],'actions'=>$context['actions']??[],
        'initiative_followthrough'=>$outcome,'coordination_reply'=>true,'casual_checkin_reply_v1'=>true];
}

/** Streaming cache replay rechecks the exact owner turn without issuing a second action. */
function vp3_casual_checkin_reply_replay_v1(PDO $pdo,array $user,array $principal,array $input,array $cached,?callable $authority): ?array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-coordination-work-v1.php';
    $outcome=$cached['initiative_followthrough']??null;
    if(!is_array($outcome)||($cached['casual_checkin_reply_v1']??null)!==true)return null;
    if($authority)$authority();
    if(!is_string($input['message']??null)||(int)($input['conversation_id']??0)!==$outcome['conversation_id']
        ||($cached['conversation_id']??null)!==$outcome['conversation_id']){
        throw new RuntimeException('The saved check-in reply changed.',409);
    }
    $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$outcome['conversation_id'],$outcome['message_id']);
    if(($origin['goal']['coordination_route']['specialist']??null)!=='casual'||vp3_initiative_followthrough_outcome_v1($origin)!==$outcome){
        throw new RuntimeException('The saved check-in reply changed.',409);
    }
    return vp3_casual_checkin_saved_reply_v1($pdo,$origin,trim($input['message']),vp3_agent_chat_initiative_reply_v2160($input),'voice');
}
