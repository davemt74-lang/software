<?php
declare(strict_types=1);

require_once __DIR__.'/music-chat-v1.php';

/** History is retained receipt evidence. A query never searches a music provider. */
function vp3_music_history_chat_plan_v1(string $request,?string $timezone=null,?int $now=null): ?array
{
    $request=trim($request);
    if($request===''||mb_strlen($request)>6000)return null;
    $literals=[];
    $text=preg_replace_callback('/["“]([^"”]+)["”]|\x27([^\x27]+)\x27/u',static function(array $m)use(&$literals):string{
        $key='qzhistoryliteral'.count($literals).'zq';$literals[$key]=$m[1]!==''?$m[1]:$m[2];return $key;
    },$request)??$request;
    $text=trim(preg_replace('/[.!?]+\z/u','',$text)??$text);
    $text=preg_replace('/\A(?:please\s+|can you\s+|could you\s+|would you\s+)/iu','',$text)??$text;
    if(preg_match('/\b(?:don[’\x27]?t|do not|never|stop|delete|clear|erase|forget|disable|turn off)\b/iu',$text))return null;
    if(preg_match('/\A(?:how (?:does|do|can|to)|what (?:is|are)|explain|why)\b/iu',$text))return null;
    $restore=static fn(string $value):string=>trim(strtr($value,$literals));
    if(preg_match('/\A(?:show(?: me)? (?:more|older)(?: (?:music|listening|playback|watch))?(?: history| entries| plays)?|(?:the )?next page|older(?: history)?|more history)\z/iu',$text)){
        return ['intent'=>'page','context_required'=>true];
    }
    // Reuse MC1's source-aware ordinal/deictic grammar only when the current
    // assistant turn is a history page. Ordinary music follow-ups still fall through.
    $music=vp3_music_chat_plan_v1($request);
    if($music!==null&&isset($music['selection'])&&empty($music['selection']['all'])&&empty($music['selection']['playlist'])){
        return ['intent'=>'selection','context_required'=>true,'operation'=>$music['intent'],
            'selection'=>$music['selection'],'source'=>$music['source'],'playlist_name'=>$music['playlist_name']??null];
    }
    $ordinal='first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|last|[1-9][0-9]?(?:st|nd|rd|th)?';
    if(preg_match('/\A(?:resume|continue|replay)\s+(?:(?:the|that|this)\s+)?('.$ordinal.'|that|this|it)(?:\s+(?:one|song|track|video|entry|play|history entry))?\z/iu',$text,$m)){
        $word=mb_strtolower($m[1]);$deictic=in_array($word,['that','this','it'],true);
        $index=['first'=>1,'second'=>2,'third'=>3,'fourth'=>4,'fifth'=>5,'sixth'=>6,'seventh'=>7,'eighth'=>8,'ninth'=>9,'tenth'=>10,'last'=>-1][$word]??($deictic?1:(int)preg_replace('/\D/','',$word));
        return ['intent'=>'selection','context_required'=>true,'operation'=>preg_match('/\Areplay\b/iu',$text)?'play':'resume',
            'selection'=>['index'=>$index,'deictic'=>$deictic],'source'=>'both','playlist_name'=>null];
    }
    $latest=preg_match('/\A(?:resume|continue|replay|play)\s+(?:(?:my|the)\s+)?last\s+(?:song|track|video)(?:\s+(?:I\s+(?:played|watched|listened to)|from\s+(?:VP3|YouTube)))?\z/iu',$text)===1;
    $history=preg_match('/\b(?:(?:listening|music|playback|watch|VP3|YouTube)\s+history|(?:songs?|tracks?|music|videos?)\s+(?:that\s+)?I\s+(?:played|watched|listened(?:\s+to)?)|(?:what|which)\b.{0,100}\b(?:did I (?:play|watch|listen to)|I (?:played|watched|listened to)))\b/iu',$text)===1;
    if(!$history&&!$latest)return null;
    if(preg_match('/\b(?:dataset|browser history|search history|history of|medical history|purchase history)\b/iu',$text))return null;
    $vp3=preg_match('/\b(?:VP3|native)\b/iu',$text)===1;$youtube=preg_match('/\byou\s*tube\b/iu',$text)===1;
    $source=$vp3&&!$youtube?'vp3':($youtube&&!$vp3?'youtube':(preg_match('/\b(?:watch(?:ed)?|videos?)\b/iu',$text)&&!$vp3?'youtube':'both'));
    if(preg_match('/\b(?:without|except|not)\s+(?:you\s*tube)\b/iu',$text))$source='vp3';
    if(preg_match('/\b(?:without|except|not)\s+(?:VP3|native)\b/iu',$text))$source='youtube';
    if(preg_match('/\bonly\s+(?:VP3|native)\b/iu',$text)&&preg_match('/\bonly\s+you\s*tube\b/iu',$text))return ['intent'=>'clarify','answer'=>'Choose VP3, YouTube, or both sources for your listening history.'];
    try{$zone=new DateTimeZone($timezone?:'UTC');}catch(Throwable){throw new RuntimeException('Choose a valid history timezone.',422);}
    $clock=(new DateTimeImmutable('@'.($now??time())))->setTimezone($zone);$today=$clock->setTime(0,0);
    $filters=['source'=>$source,'query'=>'','timezone'=>$zone->getName(),'limit'=>$latest?1:20];
    $window=null;
    if(preg_match('/\blast night\b/iu',$text)){
        $from=$today->modify('-1 day')->setTime(18,0);$until=$today->setTime(6,0);
        if($until>$clock)$until=$clock;
        $filters['from_at']=$from->format(DATE_ATOM);$filters['until_at']=$until->format(DATE_ATOM);
        $window='Last night: '.$from->format('Y-m-d H:i T').' to '.$until->format('Y-m-d H:i T').' (end exclusive)';
    }elseif(preg_match('/\b(?:from|between)\s+(\d{4}-\d{2}-\d{2})\s+(?:to|through|and)\s+(\d{4}-\d{2}-\d{2})\b/u',$text,$dates)){
        $filters['from']=$dates[1];$filters['to']=$dates[2];
    }elseif(preg_match('/\b(?:on|from|since)\s+(\d{4}-\d{2}-\d{2})\b/u',$text,$date)){
        $filters['from']=$date[1];$filters['to']=preg_match('/\b(?:from|since)\s+'.preg_quote($date[1],'/').'/u',$text)?$clock->format('Y-m-d'):$date[1];
    }elseif(preg_match('/\byesterday\b/iu',$text)){
        $filters['from']=$filters['to']=$today->modify('-1 day')->format('Y-m-d');
    }elseif(preg_match('/\btoday\b/iu',$text)){
        $filters['from']=$filters['to']=$today->format('Y-m-d');
    }elseif(preg_match('/\blast week\b/iu',$text)){
        $monday=$today->modify('monday this week');$filters['from']=$monday->modify('-7 days')->format('Y-m-d');$filters['to']=$monday->modify('-1 day')->format('Y-m-d');
    }elseif(preg_match('/\bthis week\b/iu',$text)){
        $filters['from']=$today->modify('monday this week')->format('Y-m-d');$filters['to']=$today->format('Y-m-d');
    }elseif(preg_match('/\b(?:last|past)\s+([1-9][0-9]{0,2})\s+days?\b/iu',$text,$days)){
        $filters['from']=$today->modify('-'.((int)$days[1]-1).' days')->format('Y-m-d');$filters['to']=$today->format('Y-m-d');
    }
    if(preg_match('/\b(?:show|list|last|latest|recent)\s+(?:me\s+)?([1-9][0-9]?)\s+(?:plays?|entries|songs?|tracks?|videos?)\b/iu',$text,$count))$filters['limit']=min(50,(int)$count[1]);
    $search='';
    if(preg_match('/\b(?:containing|matching|for|by|about)\s+(.+?)\s+(?:in|from)\s+(?:my\s+)?(?:(?:listening|music|playback|watch|VP3|YouTube)\s+)?history\b/iu',$text,$match))$search=$match[1];
    elseif(preg_match('/\bhistory\s+(?:for|containing|matching|by|about)\s+(.+)\z/iu',$text,$match))$search=$match[1];
    elseif(preg_match('/\b(?:listened to|played|watched)\s+(.+)\z/iu',$text,$match))$search=$match[1];
    elseif($literals)$search=implode(' ',array_keys($literals));
    $search=preg_replace('/\b(?:from|between|on|since)\s+\d{4}-\d{2}-\d{2}(?:\s+(?:to|through|and)\s+\d{4}-\d{2}-\d{2})?\b/iu',' ',$search)??$search;
    $search=preg_replace('/\b(?:last night|yesterday|today|last week|this week|(?:last|past)\s+[1-9][0-9]{0,2}\s+days?|(?:on|from|only|without|except|not)\s+(?:VP3|native|you\s*tube))\b/iu',' ',$search)??$search;
    $search=trim(preg_replace('/\s+/u',' ',$search)??$search," \t\r\n?!.,");
    if(in_array(mb_strtolower($search),['','music','songs','tracks','videos','recently','earlier'],true))$search='';
    $filters['query']=mb_substr($restore($search),0,200);
    return ['intent'=>'query','filters'=>$filters,'window_label'=>$window,'latest_operation'=>$latest?(preg_match('/\A(?:resume|continue)\b/iu',$text)?'resume':'play'):null];
}

function vp3_music_history_chat_dependencies_v1(): void
{
    vp3_music_chat_dependencies_v1();
    require_once __DIR__.'/music-history-v1.php';
    require_once __DIR__.'/agent-tool-receipts-v1.php';
}

function vp3_music_history_chat_request_hash_v1(string $query,array $input): string
{
    if(isset($input['reminder_timezone'])&&!is_string($input['reminder_timezone']))throw new RuntimeException('Choose a valid history timezone.',422);
    return hash('sha256',json_encode(['message'=>$query,'timezone'=>$input['reminder_timezone']??null],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function vp3_music_history_chat_request_v1(PDO $pdo,array $user,int $agent,int $cid,string $query,array $input=[]): bool
{
    $plan=vp3_music_history_chat_plan_v1($query,is_string($input['reminder_timezone']??null)?$input['reminder_timezone']:null);
    if($plan===null)return false;
    if(empty($plan['context_required']))return true;
    if(vp3_music_history_chat_latest_v1($pdo,$user,$agent,$cid)!==null)return true;
    if(is_string($input['music_request_id']??null)){
        vp3_music_history_chat_dependencies_v1();
        if(vp3_music_chat_ready_v1($pdo)){
            $q=$pdo->prepare('SELECT meta_json FROM music_chat_results_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND request_key=?');
            $q->execute([(int)$user['id'],$agent,$cid,hash('sha256',$input['music_request_id'])]);$meta=$q->fetchColumn();
            return is_string($meta)&&isset(vp3_music_chat_json_v1($meta)['history_ref']);
        }
    }
    return false;
}

/** The persisted projection contains no title, artist, provider response or playable action. */
function vp3_music_history_chat_snapshot_v1(array $scope,array $page,?string $window=null): array
{
    $refs=array_map(static fn(array $item):string=>(string)$item['entry_ref'],$page['items']);
    if(count($refs)>50||count(array_unique($refs))!==count($refs))throw new RuntimeException('History ordering could not be verified.',503);
    $snapshot=['protocol'=>VP3_MUSIC_HISTORY_PROTOCOL_V1,'version'=>1]+vp3_music_chat_scope_fields_v1($scope)+[
        'query_id'=>bin2hex(random_bytes(16)),'entry_refs'=>$refs,'filters'=>$page['filters'],
        'limit'=>$page['limit'],'as_of'=>$page['as_of'],'timezone'=>$page['timezone'],
        'next_cursor'=>$page['next_cursor'],'has_more'=>$page['has_more'],'window_label'=>$window];
    $snapshot['revision']=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $snapshot+['source_message_id'=>0];
}

function vp3_music_history_chat_reference_v1(array $payload): array
{
    return array_intersect_key($payload,array_flip(['protocol','version','owner_user_id','agent_id','conversation_id','source_message_id','revision']));
}

function vp3_music_history_chat_context_v1(PDO $pdo,array $user,int $agent,int $cid,int $messageId,?string $revision=null): array
{
    vp3_music_history_chat_dependencies_v1();$scope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);
    if($messageId<1)throw new RuntimeException('Choose a saved listening history page.',422);
    $q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant'");
    $q->execute([$messageId,$cid]);$raw=$q->fetchColumn();
    $context=is_string($raw)?json_decode($raw,true):null;$snapshot=$context['music_history_v1']??null;
    if(!is_array($snapshot)||($snapshot['protocol']??null)!==VP3_MUSIC_HISTORY_PROTOCOL_V1
        ||($snapshot['owner_user_id']??null)!==$scope['owner_user_id']||($snapshot['agent_id']??null)!==$agent
        ||($snapshot['conversation_id']??null)!==$cid||!is_array($snapshot['entry_refs']??null)
        ||!is_string($snapshot['revision']??null)||count($snapshot['entry_refs'])>50){
        throw new RuntimeException('This history page is unavailable in this Chat.',404);
    }
    if($revision!==null&&!hash_equals($snapshot['revision'],$revision))throw new RuntimeException('The history page changed. Refresh it before choosing a play.',409);
    $snapshot['source_message_id']=$messageId;return $snapshot;
}

/** Looking for a history marker must not apply history policy to unrelated MC1 turns. */
function vp3_music_history_chat_latest_v1(PDO $pdo,array $user,int $agent,int $cid): ?array
{
    if($cid<1)return null;
    $q=$pdo->prepare("SELECT m.id,m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.id=? AND c.user_id=? AND ".($agent>0?'c.user_agent_id=?':'c.user_agent_id IS NULL')." AND m.role='assistant' ORDER BY m.id DESC LIMIT 1");
    $q->execute([$cid,(int)$user['id'],...($agent>0?[$agent]:[])]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;$context=json_decode((string)($row['context_json']??''),true);
    if(is_array($context['music_history_v1']??null))return vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,(int)$row['id']);
    $ref=$context['music_history_followup_v1']??null;
    if(is_array($ref))return vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,(int)($ref['source_message_id']??0),(string)($ref['revision']??''));
    return null;
}

function vp3_music_history_chat_project_v1(PDO $pdo,array $user,int $agent,int $cid,array $snapshot): array
{
    vp3_music_history_chat_dependencies_v1();$scope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);
    $sources=vp3_music_history_sources_v1($pdo,$scope);
    $project=static function(string $ref)use($pdo,$user,$agent,$cid,$snapshot):array{
        try{
            $entry=vp3_music_history_entry_v1($pdo,$user,$agent,$cid,$ref);
            $entry['played_at_local']=(new DateTimeImmutable($entry['played_at']))->setTimezone(new DateTimeZone($snapshot['timezone']))->format(DATE_ATOM);
        }catch(RuntimeException $error){
            if(!in_array((int)$error->getCode(),[404,409,410],true))throw $error;
            // Keep the original ordinal without retaining removed source metadata.
            $entry=['entry_ref'=>$ref,'source'=>'','source_id'=>'','title'=>'Unavailable history entry','artist'=>'','album'=>'','cover'=>'',
                'played_at'=>null,'played_at_local'=>null,'listened_seconds'=>null,'evidence'=>'unavailable','measurement'=>'unavailable',
                'resume_seconds'=>null,'resume_available'=>false,'play_available'=>false,'metadata_available'=>false];
        }
        return $entry;
    };
    $items=array_map($project,$snapshot['entry_refs']);
    $current=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);
    if($sources!==vp3_music_history_sources_v1($pdo,$current))throw new RuntimeException('History source access changed while restoring this page. Refresh it.',409);
    // A later receipt lookup can outlive an earlier source/asset edit. Reproject
    // the same opaque identities before exposing metadata, without reranking.
    $items=array_map($project,$snapshot['entry_refs']);
    $current=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);
    if($sources!==vp3_music_history_sources_v1($pdo,$current))throw new RuntimeException('History source access changed while restoring this page. Refresh it.',409);
    return array_diff_key($snapshot,['entry_refs'=>true,'query_id'=>true])+['items'=>$items,
        'coverage'=>vp3_music_history_coverage_v1($sources,(int)strtotime($snapshot['as_of'])),
        'replayed'=>true];
}

function vp3_music_history_chat_answer_v1(array $page): string
{
    $count=count($page['items']??[]);
    $answer=$count?'Here '.($count===1?'is 1 playback entry':'are '.$count.' playback entries').' on this history page. Repeated plays are shown separately.':'No retained playback entries match this history query.';
    $descriptions=[];
    foreach($page['items']??[] as $item){
        if(empty($item['metadata_available'])||empty($item['source_id']))continue;
        $descriptions[]='“'.vp3_music_chat_text_v1($item['title']??'',160).'” ('.($item['source']==='vp3'?'VP3':'YouTube').', '
            .vp3_music_chat_text_v1($item['played_at_local']??'',40).')';
        if(count($descriptions)===3)break;
    }
    if($descriptions)$answer.=' This page includes '.implode('; ',$descriptions).'.';
    if(!empty($page['window_label']))$answer.=' '.$page['window_label'].'.';
    if(!empty($page['has_more']))$answer.=' Older matching entries are available.';
    return $answer.' This covers playback recorded in VP3 with current access and metadata, not a complete lifetime or YouTube account history.';
}

function vp3_music_history_chat_reply_v1(array $page,?array $snapshot=null): array
{
    $out=['handled'=>true,'music_chat_handled_v1'=>true,'music_history_handled_v1'=>true,
        'answer'=>vp3_music_history_chat_answer_v1($page),'actions'=>[],'sources'=>[],
        'music_history_v1'=>$page,'tool_receipt'=>vp3_tool_receipt_v1('music.history.read','list',empty($page['items'])?'no_matches':'read_complete','',0,count($page['items']))];
    if($snapshot!==null)$out['music_history_snapshot_v1']=$snapshot;
    return $out;
}

function vp3_music_history_chat_read_v1(PDO $pdo,array $user,int $agent,int $cid,int $messageId,string $revision): array
{
    $snapshot=vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,$messageId,$revision);
    return vp3_music_history_chat_project_v1($pdo,$user,$agent,$cid,$snapshot);
}

/** Bind to the actual assistant message; the stored message remains a generic receipt. */
function vp3_music_history_chat_bind_reply_v1(PDO $pdo,array $user,array $principal,int $cid,int $messageId,array $tool): array
{
    if(!is_array($tool['music_history_snapshot_v1']??null))return $tool;
    vp3_music_history_chat_dependencies_v1();$agent=(int)($principal['agent_id']??0);
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$messageId,$tool):array{
        vp3_music_history_scope_v1($pdo,$user,$agent,$cid);
        $q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant'");$q->execute([$messageId,$cid]);$raw=$q->fetchColumn();
        if($raw===false)throw new RuntimeException('The history reply was not saved to this Chat.',409);
        $snapshot=$tool['music_history_snapshot_v1'];$snapshot['source_message_id']=$messageId;
        $context=json_decode((string)$raw,true);if(!is_array($context))$context=[];
        $context['music_history_v1']=$snapshot;$context['skip_brain_archive']=true;
        $pdo->prepare("UPDATE chat_messages SET message=?,context_json=? WHERE id=? AND conversation_id=?")
            ->execute(['Retained listening history was displayed in this Chat.',json_encode($context,JSON_THROW_ON_ERROR),$messageId,$cid]);
        $page=vp3_music_history_chat_project_v1($pdo,$user,$agent,$cid,$snapshot);$page['replayed']=false;
        return vp3_music_history_chat_reply_v1($page,$snapshot);
    });
}

function vp3_music_history_chat_clarify_v1(array $snapshot,string $answer): array
{
    return ['handled'=>true,'music_chat_handled_v1'=>true,'music_history_handled_v1'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[],
        'music_history_followup_v1'=>vp3_music_history_chat_reference_v1($snapshot),
        'tool_receipt'=>vp3_tool_receipt_v1('music.history.read','list','clarification_required')];
}

/** Materialize one current receipt identity, never the deduplicated whole history page. */
function vp3_music_history_chat_select_v1(PDO $pdo,array $user,int $agent,int $cid,array $snapshot,string $entryRef,
    string $operation,string $requestKey,?string $playlistId=null,?int $playlistRevision=null,?string $requestHash=null): array
{
    vp3_music_history_chat_dependencies_v1();
    if(!in_array($operation,['play','resume','show','queue_append','play_next','add_to_playlist'],true))throw new RuntimeException('Choose Play, Resume or Add to playlist.',422);
    if(!in_array($entryRef,$snapshot['entry_refs'],true))throw new RuntimeException('Choose an entry from the displayed history page.',409);
    $hash=$requestHash??hash('sha256',json_encode([$snapshot['source_message_id'],$snapshot['revision'],$entryRef,$operation,$playlistId,$playlistRevision],JSON_THROW_ON_ERROR));
    $prior=vp3_music_chat_result_request_v1($pdo,$user,$agent,$cid,$requestKey,$hash);
    if($prior!==null)return vp3_music_chat_reply_v1($prior);
    $selected=vp3_music_history_item_v1($pdo,$user,$agent,$cid,$entryRef);
    if($operation==='resume'&&empty($selected['entry']['resume_available']))throw new RuntimeException('This exact play has no verified position to resume. Use Play to start it again.',409);
    $intent=$operation==='resume'?'play':$operation;
    $meta=['intent'=>$intent,'source'=>$selected['item']['source'],'requested_count'=>1,'query'=>'','status'=>'ready',
        'history_ref'=>$entryRef,'history_operation'=>$operation==='resume'?'resume':'play',
        'autoplay'=>in_array($operation,['play','resume','queue_append','play_next'],true)];
    if($operation==='add_to_playlist'){
        if($playlistId===null||$playlistRevision===null)throw new RuntimeException('Choose the saved target playlist.',422);
        $meta['target_playlist_id']=$playlistId;$meta['expected_target_revision']=$playlistRevision;
    }
    return vp3_music_chat_reply_v1(vp3_music_chat_result_store_v1($pdo,$user,$agent,$cid,$requestKey,$hash,[$selected['item']],$meta));
}

function vp3_music_history_chat_execute_v1(PDO $pdo,array $user,array $principal,int $cid,int $userMessageId,string $query,array $input=[],?callable $authority=null): ?array
{
    $plan=vp3_music_history_chat_plan_v1($query,is_string($input['reminder_timezone']??null)?$input['reminder_timezone']:null);
    if($plan===null)return null;$agent=(int)($principal['agent_id']??0);
    $requestKey=is_string($input['music_request_id']??null)?$input['music_request_id']:'history-chat:'.$userMessageId;
    $requestHash=vp3_music_history_chat_request_hash_v1($query,$input);
    if($plan['intent']==='selection'||!empty($plan['latest_operation'])){
        vp3_music_history_chat_dependencies_v1();
        if(vp3_music_chat_ready_v1($pdo)){
            $q=$pdo->prepare('SELECT meta_json FROM music_chat_results_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND request_key=?');
            $q->execute([(int)$user['id'],$agent,$cid,hash('sha256',$requestKey)]);$meta=$q->fetchColumn();
            if(is_string($meta)&&isset(vp3_music_chat_json_v1($meta)['history_ref'])){
                return vp3_music_chat_reply_v1(vp3_music_chat_result_request_v1($pdo,$user,$agent,$cid,$requestKey,$requestHash));
            }
        }
    }
    $prior=null;
    if(!empty($plan['context_required'])){
        $prior=vp3_music_history_chat_latest_v1($pdo,$user,$agent,$cid);
        if($prior===null)return null;
    }
    if($authority)$authority();vp3_music_history_chat_dependencies_v1();$scope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);
    if($plan['intent']==='clarify')return ['handled'=>true,'music_chat_handled_v1'=>true,'music_history_handled_v1'=>true,
        'answer'=>$plan['answer'],'actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('music.history.read','list','clarification_required')];
    if($plan['intent']==='selection'){
        $selection=$plan['selection'];$index=(int)$selection['index'];
        if(!empty($selection['deictic'])&&count($prior['entry_refs'])!==1)return vp3_music_history_chat_clarify_v1($prior,'Which play do you mean? Choose its number in the displayed history page.');
        $index=$index===-1?count($prior['entry_refs'])-1:$index-1;
        if(!isset($prior['entry_refs'][$index]))return vp3_music_history_chat_clarify_v1($prior,'That position is not in the displayed history page.');
        $entry=vp3_music_history_entry_v1($pdo,$user,$agent,$cid,$prior['entry_refs'][$index]);
        if($plan['source']!=='both'&&$entry['source']!==$plan['source'])return vp3_music_history_chat_clarify_v1($prior,'That numbered history entry is from another source. Choose its displayed source or a different entry.');
        $playlist=null;
        if($plan['operation']==='add_to_playlist'){
            $playlist=vp3_music_chat_playlist_find_v1($pdo,$user,$agent,$cid,(string)$plan['playlist_name']);
            if($playlist===null)return vp3_music_history_chat_clarify_v1($prior,'I could not identify that saved private playlist. Name the existing playlist to add this exact entry.');
        }
        return vp3_music_history_chat_select_v1($pdo,$user,$agent,$cid,$prior,$prior['entry_refs'][$index],$plan['operation'],
            $requestKey,$playlist['playlist']['id']??null,$playlist['playlist']['revision']??null,$requestHash);
    }
    if($plan['intent']==='page'){
        if(empty($prior['has_more'])||!is_string($prior['next_cursor']))return vp3_music_history_chat_clarify_v1($prior,'There are no older matching entries in this retained history query.');
        $filters=$prior['filters']+['limit'=>$prior['limit'],'cursor'=>$prior['next_cursor']];$window=$prior['window_label']??null;
    }else{$filters=$plan['filters'];$window=$plan['window_label']??null;}
    // Resolve the account's IANA timezone when no explicit browser/user zone was sent.
    if(!isset($input['reminder_timezone'])&&$plan['intent']==='query'){
        $zone=vp3_music_history_timezone_v1($pdo,$scope['user']);
        $plan=vp3_music_history_chat_plan_v1($query,$zone);$filters=$plan['filters'];$window=$plan['window_label']??null;
    }
    $page=vp3_music_history_read_v1($pdo,$user,$agent,$cid,$filters);
    $snapshot=vp3_music_history_chat_snapshot_v1($scope,$page,$window);
    if(!empty($plan['latest_operation'])&&!empty($snapshot['entry_refs'])){
        // The exact last retained receipt is selected; no catalog/provider lookup is substituted.
        return vp3_music_history_chat_select_v1($pdo,$user,$agent,$cid,$snapshot,$snapshot['entry_refs'][0],$plan['latest_operation'],
            $requestKey,null,null,$requestHash);
    }
    return vp3_music_history_chat_reply_v1(array_diff_key($snapshot,['entry_refs'=>true,'query_id'=>true])+['items'=>$page['items'],'coverage'=>$page['coverage'],'replayed'=>false],$snapshot);
}

/** Explicit UI query/filter/page actions alone may bind or replace a Chat history card. */
function vp3_music_history_chat_query_v1(PDO $pdo,array $user,int $agent,int $cid,array $filters,int $previousId=0,?string $expectedRevision=null): array
{
    vp3_music_history_chat_dependencies_v1();
    if(($previousId>0)!==($expectedRevision!==null))throw new RuntimeException('Supply the current history page and revision together.',422);
    if($previousId>0)vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,$previousId,$expectedRevision);
    $page=vp3_music_history_read_v1($pdo,$user,$agent,$cid,$filters);
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$page,$previousId,$expectedRevision):array{
        $scope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);
        if(agent_commerce_sqlite_pa2($pdo))$pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([(int)$user['id']]);
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));$q->execute([(int)$user['id']]);$q->fetchColumn();
        if($previousId>0)vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,$previousId,$expectedRevision);
        $q=$pdo->prepare("SELECT id FROM chat_messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");$q->execute([$cid]);$latest=(int)$q->fetchColumn();
        $snapshot=vp3_music_history_chat_snapshot_v1($scope,$page);$id=$previousId>0&&$latest===$previousId?$previousId:0;
        if($id===0){$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,NULL,'assistant',?,?)")
            ->execute([$cid,'Retained listening history was displayed in this Chat.','{}']);$id=(int)$pdo->lastInsertId();}
        $snapshot['source_message_id']=$id;
        $context=['music_history_v1'=>$snapshot,'skip_brain_archive'=>true];
        $pdo->prepare("UPDATE chat_messages SET message=?,context_json=? WHERE id=? AND conversation_id=? AND role='assistant'")
            ->execute(['Retained listening history was displayed in this Chat.',json_encode($context,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$id,$cid]);
        $fresh=vp3_music_history_chat_project_v1($pdo,$user,$agent,$cid,$snapshot);$fresh['replayed']=false;
        return ['assistant_message_id'=>$id,'answer'=>vp3_music_history_chat_answer_v1($fresh),'music_history_v1'=>$fresh];
    });
}

function vp3_music_history_chat_selection_api_v1(PDO $pdo,array $user,int $agent,int $cid,int $messageId,string $revision,string $entryRef,
    string $operation,string $requestId,?string $playlistId=null,?int $playlistRevision=null): array
{
    $snapshot=vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,$messageId,$revision);
    // Opening the existing saved-list picker prepares a quiet exact selection;
    // only its later canonical target/revision mutation can claim an addition.
    if($operation==='add_to_playlist'&&$playlistId===null&&$playlistRevision===null)$operation='show';
    $tool=vp3_music_history_chat_select_v1($pdo,$user,$agent,$cid,$snapshot,$entryRef,$operation,$requestId,$playlistId,$playlistRevision);
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$agent,$cid,$tool):array{
        $scope=vp3_music_chat_scope_v1($pdo,$user,$agent,$cid,true);
        $row=vp3_music_chat_result_row_v1($pdo,$scope,$tool['music_chat_v1']['result_id'],true);$id=(int)$row['assistant_message_id'];
        if($id<1){
            $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,NULL,'assistant',?,?)")
                ->execute([$cid,'Preparing the selected history entry.','{}']);$id=(int)$pdo->lastInsertId();
            $payload=vp3_music_chat_result_bind_v1($pdo,$user,$agent,$cid,$row['result_id'],$id);$tool=vp3_music_chat_reply_v1($payload);
            $pdo->prepare("UPDATE chat_messages SET message=?,context_json=? WHERE id=? AND conversation_id=?")
                ->execute(['The selected history entry is available in this Chat.',json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($payload),
                    'skip_brain_archive'=>true,'tool_receipt'=>$tool['tool_receipt']],JSON_THROW_ON_ERROR),$id,$cid]);
        }else $tool=vp3_music_chat_reply_v1(vp3_music_chat_result_projection_v1($pdo,$scope,$row,true));
        return ['assistant_message_id'=>$id,'answer'=>$tool['answer'],'music_chat_v1'=>$tool['music_chat_v1'],'tool_receipt'=>$tool['tool_receipt']];
    });
}
