<?php
declare(strict_types=1);

require_once __DIR__.'/music-preferences-v1.php';
require_once __DIR__.'/music-chat-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Standalone owner instructions only; a refusal, failed play or quoted provider title is not taste. */
function vp3_music_preferences_chat_plan_v1(string $request): ?array
{
    if(!mb_check_encoding($request,'UTF-8')||mb_strlen($request)>180||preg_match('/[\p{Cc}\p{Cf}]/u',$request))return null;
    $text=mb_strtolower(trim(str_replace('’',"'",$request)));$text=rtrim($text,' .!');
    $text=preg_replace('/\Aplease\s+/u','',$text)??'';
    if(preg_match('/\A(?:show|list|open|review)(?: me)? (?:my )?(?:saved )?music (?:preferences|likes and dislikes)\z/u',$text))return ['operation'=>'read'];
    $choice=null;$target='';
    if(preg_match('/\A(?:i )?(?:do not|don\'t) like (.+)\z/u',$text,$match)){
        $choice='dislike';$target=$match[1];
    }elseif(preg_match('/\A(?:i )?(like|love|dislike|hate) (.+)\z/u',$text,$match)){
        $choice=in_array($match[1],['like','love'],true)?'like':'dislike';$target=$match[2];
    }elseif(preg_match('/\A(?:do not|don\'t) (?:recommend|suggest) (.+?)(?: again)?\z/u',$text,$match)){
        $choice='dislike';$target=$match[1];
    }elseif(preg_match('/\A(?:clear|remove|reset) (?:my )?(?:music )?(?:preference|feedback)(?: for)? (.+)\z/u',$text,$match)){
        $choice='clear';$target=$match[1];
    }
    if($choice===null)return null;
    $ordinal='first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|last|[1-9][0-9]?(?:st|nd|rd|th)?';
    if(preg_match('/\A(?:(?:the|song|track|video|number|result|entry)\s+|#)?('.$ordinal.')(?:\s+(?:one|song|track|video|result|entry|history entry))?\z/u',$target,$match)){
        $index=['first'=>1,'second'=>2,'third'=>3,'fourth'=>4,'fifth'=>5,'sixth'=>6,'seventh'=>7,'eighth'=>8,'ninth'=>9,'tenth'=>10,'last'=>-1][$match[1]]??(int)preg_replace('/\D/','',$match[1]);
        return ['operation'=>'set','preference'=>$choice,'index'=>$index,'deictic'=>false];
    }
    if(preg_match('/\A(?:this|that|it)(?: (?:one|song|track|video|item))?\z/u',$target))return ['operation'=>'set','preference'=>$choice,'index'=>1,'deictic'=>true];
    return null;
}

function vp3_music_preferences_chat_latest_v1(PDO $pdo,array $user,int $agent,int $cid): ?array
{
    if($cid<1)return null;
    $q=$pdo->prepare("SELECT m.id,m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.id=? AND c.user_id=? AND ".($agent>0?'c.user_agent_id=?':'c.user_agent_id IS NULL')." AND m.role='assistant' ORDER BY m.id DESC LIMIT 1");
    $q->execute([$cid,(int)($user['id']??0),...($agent>0?[$agent]:[])]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;$context=json_decode((string)($row['context_json']??''),true);
    if(!is_array($context)||!vp3_music_history_private_context_v1($row['context_json']))return null;
    foreach(['music_preferences_v1','music_history_v1','music_history_followup_v1','music_chat_v1','music_chat_followup_v1'] as $key){
        if(is_array($context[$key]??null))return ['message_id'=>(int)$row['id'],'kind'=>$key,'reference'=>$context[$key]];
    }
    return null;
}

function vp3_music_preferences_chat_request_v1(PDO $pdo,array $user,int $agent,int $cid,string $query,array $input=[]): bool
{
    $plan=vp3_music_preferences_chat_plan_v1($query);
    if($plan===null)return false;
    if($plan['operation']==='read'||vp3_music_preferences_chat_latest_v1($pdo,$user,$agent,$cid)!==null)return true;
    $request=$input['music_request_id']??$input['idempotency_key']??null;
    if(!is_string($request)||!vp3_music_preferences_ready_v1($pdo))return false;
    $q=$pdo->prepare('SELECT 1 FROM music_preference_requests_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND request_key=?');
    $q->execute([(int)($user['id']??0),$agent,$cid,vp3_music_preferences_request_v1($request)]);
    return (bool)$q->fetchColumn();
}

function vp3_music_preferences_chat_target_v1(PDO $pdo,array $user,int $agent,int $cid,array $plan): ?array
{
    $latest=vp3_music_preferences_chat_latest_v1($pdo,$user,$agent,$cid);if(!$latest)return null;
    $ref=$latest['reference'];$scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);$count=0;$base=null;$refs=[];
    if($latest['kind']==='music_preferences_v1'){
        if(($ref['action']??null)!=='feedback'||!is_string($ref['reference']??null))return null;
        $base=['kind'=>'preference','reference'=>$ref['reference']];$count=1;
    }elseif(in_array($latest['kind'],['music_history_v1','music_history_followup_v1'],true)){
        require_once __DIR__.'/music-history-chat-v1.php';
        $message=(int)($ref['source_message_id']??$latest['message_id']);
        $history=vp3_music_history_chat_context_v1($pdo,$user,$agent,$cid,$message,(string)($ref['revision']??''));
        $refs=$history['entry_refs'];$count=count($refs);
        $base=['kind'=>'history','source_message_id'=>$message,'revision'=>$history['revision']];
    }else{
        $row=vp3_music_chat_result_row_v1($pdo,$scope,(string)($ref['result_id']??''),savedReference:true);
        if(is_array($ref['playlist']??null)){
            $playlist=vp3_music_chat_playlist_row_v1($pdo,$scope,(string)$ref['playlist']['id']);
            if((int)$playlist['revision']!==($ref['playlist']['revision']??null))throw new RuntimeException('The displayed playlist changed. Refresh it before giving numbered feedback.',409);
            $count=count(vp3_music_chat_json_v1($playlist['items_json']));
            $base=['kind'=>'playlist','playlist_id'=>$playlist['playlist_id'],'revision'=>(int)$playlist['revision']];
        }else{
            if($row['playlist_id']!==null||!is_string($ref['revision']??null)||!hash_equals($row['revision'],$ref['revision']))throw new RuntimeException('The displayed selection changed. Refresh it.',409);
            $count=count(vp3_music_chat_json_v1($row['items_json']));
            $base=['kind'=>'result','result_id'=>$row['result_id'],'revision'=>$row['revision']];
        }
    }
    if(!$base||!$count||(!empty($plan['deictic'])&&$count!==1))return null;
    $index=$plan['index']===-1?$count-1:$plan['index']-1;if($index<0||$index>=$count)return null;
    if($base['kind']==='history')$base['entry_ref']=$refs[$index];
    elseif($base['kind']!=='preference')$base['index']=$index;
    return $base;
}

function vp3_music_preferences_chat_reply_v1(array $scope,string $action,?string $reference=null,bool $replayed=false): array
{
    $payload=['protocol'=>VP3_MUSIC_PREFERENCES_PROTOCOL_V1]+vp3_music_chat_scope_fields_v1($scope)+['action'=>$action];
    if($reference!==null)$payload['reference']=$reference;
    return ['handled'=>true,'music_chat_handled_v1'=>true,'music_preferences_handled_v1'=>true,'actions'=>[],'sources'=>[],
        'answer'=>$action==='list'?'Your current saved music preferences are shown below.':'Your current music preference is shown below.',
        'music_preferences_v1'=>$payload,'replayed'=>$replayed,
        'tool_receipt'=>vp3_tool_receipt_v1('music.preferences',$action==='list'?'read':'set',$action==='list'?'read_complete':'completed','',0,$action==='list'?0:1)];
}

function vp3_music_preferences_chat_execute_v1(PDO $pdo,array $user,array $principal,int $cid,int $messageId,string $query,array $input=[],?callable $authority=null): ?array
{
    $plan=vp3_music_preferences_chat_plan_v1($query);if($plan===null)return null;
    if($authority)$authority();$agent=(int)($principal['agent_id']??0);$scope=vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
    vp3_music_preferences_require_v1($pdo);
    if($plan['operation']==='read'){
        vp3_music_preferences_list_v1($pdo,$user,$agent,$cid,['limit'=>20]);
        return vp3_music_preferences_chat_reply_v1($scope,'list');
    }
    $request=(string)($input['music_request_id']??$input['idempotency_key']??('message:'.$messageId));
    $key=vp3_music_preferences_request_v1($request);$chatHash=hash('sha256',trim($query));
    $q=$pdo->prepare('SELECT * FROM music_preference_requests_v1 WHERE owner_user_id=? AND request_key=?');$q->execute([$scope['owner_user_id'],$key]);$prior=$q->fetch(PDO::FETCH_ASSOC);
    if($prior){
        if($prior['operation']!=='set'||!hash_equals($prior['chat_hash'],$chatHash)||(int)$prior['agent_id']!==$agent||(int)$prior['conversation_id']!==$cid)throw new RuntimeException('This music request identity was used for a different instruction.',409);
        $reference='mp1.'.$prior['preference_id'];vp3_music_preferences_read_v1($pdo,$user,$agent,$cid,['kind'=>'preference','reference'=>$reference]);
        return vp3_music_preferences_chat_reply_v1($scope,'feedback',$reference,true);
    }
    $selection=vp3_music_preferences_chat_target_v1($pdo,$user,$agent,$cid,$plan);
    if($selection===null){
        $reply=['handled'=>true,'music_chat_handled_v1'=>true,'music_preferences_handled_v1'=>true,
            'answer'=>'Which song do you mean? Choose its number in the current music or history results, or use its Like, Dislike, or Clear controls.',
            'actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('music.preferences','set','clarification_required')];
        $latest=vp3_music_preferences_chat_latest_v1($pdo,$user,$agent,$cid);
        if($latest&&in_array($latest['kind'],['music_history_v1','music_history_followup_v1'],true)){
            require_once __DIR__.'/music-history-chat-v1.php';
            $reply['music_history_followup_v1']=vp3_music_history_chat_reference_v1($latest['reference']);
        }elseif($latest&&in_array($latest['kind'],['music_chat_v1','music_chat_followup_v1'],true)){
            $reply['music_chat_followup_v1']=vp3_music_chat_result_reference_v1($latest['reference']);
        }elseif($latest&&($latest['reference']['action']??null)==='feedback')$reply['music_preferences_v1']=$latest['reference'];
        return $reply;
    }
    $state=vp3_music_preferences_read_v1($pdo,$user,$agent,$cid,$selection);
    $result=vp3_music_preferences_set_v1($pdo,$user,$agent,$cid,$selection,$plan['preference'],$state['revision'],$request,$chatHash);
    if($authority)$authority();vp3_music_preferences_scope_v1($pdo,$user,$agent,$cid);
    return vp3_music_preferences_chat_reply_v1($scope,'feedback',$result['state']['reference'],$result['replayed']);
}
