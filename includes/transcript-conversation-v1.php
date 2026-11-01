<?php
declare(strict_types=1);

const VP3_TRANSCRIPT_CONTEXT_V1='vp3.transcript-context.v1';

/** Only server-written Chat metadata is a source attachment; browser fields are never adopted. */
function vp3_transcript_context_v1(PDO $pdo,array $user,array $principal,array $context): ?array
{
    $cid=(int)($context['conversation_id']??0);if($cid<1||!knowledge_retrieval_v162_owner_session($user,$principal)||!has_permission('chat.access',$user))return null;
    $aid=($principal['kind']??'')==='user_agent'?(int)$principal['agent_id']:0;
    $sql="SELECT m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id WHERE c.id=? AND c.user_id=? AND ".($aid?'c.user_agent_id=?':'c.user_agent_id IS NULL')." AND m.role='assistant' AND m.context_json LIKE ? ORDER BY m.id DESC LIMIT 1";
    $args=[$cid,(int)$user['id']];if($aid)$args[]=$aid;$args[]='%vp3.transcript-context.v1%';
    $q=$pdo->prepare($sql);$q->execute($args);$json=$q->fetchColumn();if(!$json)return null;
    try{$data=json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){return null;}
    $p=$data['transcript_context']??null;
    if(!is_array($p)||($p['contract']??'')!==VP3_TRANSCRIPT_CONTEXT_V1||!in_array($p['state']??'',['active','choosing','detached'],true))return null;
    if(!is_int($p['session_id']??null)||$p['session_id']<0)return null;
    return $p;
}
function vp3_transcript_attachment_v1(int $id,string $hash='',?int $next=null,string $state='active',string $intent='discuss'): array
{
    return ['contract'=>VP3_TRANSCRIPT_CONTEXT_V1,'state'=>$state,'session_id'=>$id,'source_hash'=>$hash,'next_offset'=>$next,'summary_complete'=>false,'intent'=>$intent];
}
function vp3_transcript_attach_result_v1(array $result,array $attachment): array
{
    $result['transcript_context']=$attachment;return $result;
}

/** Bounded source evidence, with explicit full/partial coverage and stable segment locators. */
function vp3_transcript_evidence_v1(array $map,string $operation,string $question,int $offset=0,int $limit=48000): array
{
    $all=[];$total=0;
    foreach($map['pages'] as $page)foreach($page['segments'] as $s){
        $text=(string)($s['transcript_text']??'');if($text==='')continue;
        $all[]=['id'=>(int)$s['id'],'page'=>(int)$page['page_number'],'kind'=>(string)($s['segment_type']??'transcript'),'speaker'=>(string)$s['speaker_label'],'at'=>artist_listening_v172_time((int)$s['started_ms']),'text'=>$text,'offset'=>$total];$total+=mb_strlen($text);
    }
    $selected=[];$used=0;$ranked=$all;
    $focused=$operation==='discuss'&&$total>$limit;
    if($focused){
        $terms=array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($question))?:[],static fn($t)=>mb_strlen($t)>=4)));
        foreach($ranked as &$row){$row['score']=0;foreach(array_slice($terms,0,30) as $term)if(mb_stripos($row['text'],$term)!==false)$row['score']++;}unset($row);
        usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['offset']<=>$b['offset']));
    }
    foreach($ranked as $row){
        $length=mb_strlen($row['text']);$start=$focused?0:max(0,$offset-$row['offset']);if($start>=$length)continue;
        $part=mb_substr($row['text'],$start,$limit-$used);if($part==='')break;
        $selected[]=['id'=>$row['id'],'page'=>$row['page'],'kind'=>$row['kind'],'speaker'=>$row['speaker'],'at'=>$row['at'],'character_offset'=>$start,'source_offset'=>$row['offset']+$start,'text'=>$part];$used+=mb_strlen($part);
        if($used>=$limit)break;
    }
    usort($selected,static fn($a,$b)=>$a['source_offset']<=>$b['source_offset']);
    return ['segments'=>$selected,'source_hash'=>$map['source_hash'],'total_characters'=>$total,'covered_characters'=>$used,'offset'=>$focused?null:$offset,'complete'=>$used===$total&&$offset===0,'next_offset'=>!$focused&&$offset+$used<$total?$offset+$used:null,'selection'=>$focused?'relevant passages':'sequential passages','page_count'=>$map['page_count']];
}

function vp3_transcript_conversation_v1(PDO $pdo,array $user,array $principal,array $plan,int $id,string $query,array $context,?array $attached,?callable $model,?callable $authority): array
{
    $op=$plan['operation'];
    if($op==='detach')return vp3_transcript_attach_result_v1(vp3_transcript_result_v1('I have stopped using the transcript as a source in this conversation.','detach'),vp3_transcript_attachment_v1(0,'',null,'detached'));
    $session=vp3_transcript_session_v1($pdo,$user,$principal,$id);
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$id));
    $changed=($attached['session_id']??0)===$id&&!empty($attached['source_hash'])&&$attached['source_hash']!==$map['source_hash'];
    $offset=(int)($plan['offset']??0);
    if($op==='continue'){
        if(!$attached||($attached['session_id']??0)!==$id)return vp3_transcript_result_v1('Which transcript should I continue?','clarify','clarification_required');
        if(!$changed&&!empty($attached['summary_complete']))return vp3_transcript_attach_result_v1(vp3_transcript_result_v1('The previous summary covered the complete transcript. What would you like to explore next?','continue','read_complete',$id,1,['title'=>$session['title']]),$attached);
        $offset=$changed?0:(int)$attached['next_offset'];
    }
    if($changed)$offset=0;
    $pin=vp3_transcript_attachment_v1($id,$map['source_hash'],null,'active',$op);
    if($op==='attach')return vp3_transcript_attach_result_v1(vp3_transcript_result_v1('I’ll use “'.$session['title'].'” as a source for transcript questions in this conversation. What would you like to know?','attach','read_complete',$id,1,['title'=>$session['title']]),$pin);
    $packet=vp3_transcript_evidence_v1($map,$op,(string)($plan['query']??$query),$offset);
    if(!$packet['segments'])return vp3_transcript_result_v1('This transcript has no saved text at that position.','read','no_matches',$id,0,['title'=>$session['title']]);
    if(function_exists('video_meeting_transcription_assert_cloud_ai_v1801')){
        try{video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$session);}catch(RuntimeException $e){if(!str_starts_with($e->getMessage(),'This meeting '))throw $e;return ['handled'=>true,'answer'=>'This meeting transcript is restricted from Cloud AI processing. Use its authorized private processing route.','actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('transcripts.read',$op,'blocked','permission_denied',$id,0)];}
    }
    $prompt="VP3_TRANSCRIPT_ANSWER_V1\n".json_encode(['request'=>$query,'task'=>$op,'title'=>$session['title'],'evidence'=>$packet],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $out=$model?$model($prompt):ai_generate_chat_response($prompt,[],[],$user,'main_ai',['surface'=>'transcript_answer']);
    if(empty($out['ok']))return vp3_transcript_attach_result_v1(['handled'=>true,'answer'=>'I found “'.$session['title'].'”, but the AI service could not finish the answer. You can ask again.','actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('transcripts.read',$op,'failed','provider_unavailable',$id,1)],$pin);
    try{
        $answer=vp3_tool_json_object_v1((string)($out['answer']??''),['answer','citations'],16000);
        if(!is_string($answer['answer']??null)||trim($answer['answer'])===''||mb_strlen($answer['answer'])>8000||!is_array($answer['citations']??null)||!array_is_list($answer['citations'])||!$answer['citations']||count($answer['citations'])>12)throw new InvalidArgumentException('answer');
        $ids=array_column($packet['segments'],'id');foreach($answer['citations'] as $cite)if(!is_int($cite)||!in_array($cite,$ids,true))throw new InvalidArgumentException('citation');
    }catch(Throwable $e){return vp3_transcript_result_v1('I read the source, but could not validate the AI answer and its references. Please ask again.','interpret','failed',$id,0,['title'=>$session['title']]);}
    // Do not return stale or newly unauthorized source content after inference.
    if(function_exists('vp3_capability_live_user_v1'))$user=vp3_capability_live_user_v1($pdo,$user,$principal,$context,$authority);
    else{if($authority)$authority();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)$user['id']]);$user=$q->fetch(PDO::FETCH_ASSOC)?:[];if(empty($user['is_active']))throw new RuntimeException('Account access changed.',403);}
    vp3_transcript_session_v1($pdo,$user,$principal,$id);
    $current=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$id));
    if($current['source_hash']!==$map['source_hash'])return vp3_transcript_result_v1('The transcript changed while I was reading it. Ask again so I can use the updated text.','interpret','failed',$id,0,['title'=>$session['title']]);
    if($op==='discuss'){
        $pin['next_offset']=0;
        if(($attached['session_id']??0)===$id&&!$changed){$pin['next_offset']=$attached['next_offset'];$pin['summary_complete']=!empty($attached['summary_complete']);}
    }else{$pin['next_offset']=$packet['next_offset'];$pin['summary_complete']=$packet['next_offset']===null;}
    $text=trim($answer['answer']);
    if(!$packet['complete'])$text="This answer uses ".$packet['selection'].' ('.$packet['covered_characters'].' of '.$packet['total_characters']." characters), not the complete recording.\n\n".$text.($packet['next_offset']!==null?"\n\nSay “continue the transcript summary” for the next section.":'');
    if($changed)$text="The transcript has changed; I’m using its current text.\n\n".$text;
    $locators=[];foreach($packet['segments'] as $segment)if(in_array($segment['id'],$answer['citations'],true))$locators[]='p. '.$segment['page'].' · '.$segment['at'].' · '.$segment['speaker'].' · segment #'.$segment['id'];
    $text.="\n\nSource: ".$session['title'].' — '.implode('; ',$locators).'.';
    return vp3_transcript_attach_result_v1(vp3_transcript_result_v1($text,$op,'read_complete',$id,count($answer['citations']),['title'=>$session['title'],'source_hash'=>$map['source_hash'],'coverage'=>array_diff_key($packet,['segments'=>true])]),$pin);
}
