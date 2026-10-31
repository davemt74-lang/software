<?php
declare(strict_types=1);
require_once __DIR__.'/artist-listening.php';
require_once __DIR__.'/artist-listening-transcript.php';
require_once __DIR__.'/meeting-speakers-section9.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/agent-brain-v122.php';

function vp3_transcript_brain_scope_v1(PDO $pdo,array $user,array $session,callable $save,?array $principal=null): mixed
{
    $old=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;$uid=(int)$user['id'];$aid=0;
    if($principal!==null)$aid=($principal['kind']??'')==='user_agent'?(int)$principal['agent_id']:0;
    elseif(!empty($session['conversation_id'])){$aid=vp3_agent_memory_scope_conversation_id_v410($pdo,$uid,(int)$session['conversation_id']);if($aid<0)throw new RuntimeException('The transcript conversation is unavailable.');}
    elseif(function_exists('user_agents_list_v236'))foreach(user_agents_list_v236($pdo,$uid,true) as $agent)if(!empty($agent['is_default'])){$aid=(int)$agent['id'];break;}
    vp3_agent_memory_scope_set_current_v410($uid,$aid);
    try{return $save($aid);}finally{if($old===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$old;}
}
function vp3_transcript_highlight_v1(PDO $pdo,array $user,int $id,string $selection,?array $principal=null): array
{
    if(!has_permission('account.access',$user)||!has_permission('artist_listening.access',$user)||!agent_brain_schema_ready()||(function_exists('personal_capability_has_v242')&&!personal_capability_has_v242('agent_brain.access',$user)))throw new RuntimeException('Agent Brain storage is unavailable for this account.',403);
    $s=artist_listening_v172_session($pdo,$user,$id);if($s['status']!=='draft')throw new RuntimeException('Stop and save the transcript before highlighting it.');
    $segments=artist_listening_v172_segments($pdo,$id);$map=artist_listening_transcript_page_map($segments);$normalize=static fn($v)=>trim(preg_replace('/\s+/u',' ',(string)$v)??'');
    $plain=$normalize(implode(' ',array_column($segments,'transcript_text')));$selected=$normalize($selection);$whole=$selected==='';
    if(!$plain)throw new RuntimeException('This transcript contains no saved text.');
    if(mb_strlen($selected)>20000)throw new RuntimeException('Highlight up to 20,000 characters at a time. The complete transcript remains available through its source link.');
    $at=$whole?0:mb_strpos($plain,$selected);if(!$whole&&$at===false)throw new RuntimeException('That highlight does not match the saved transcript. Save your edits, then select the text again.');
    $refs=[];$cursor=0;$end=$whole?mb_strlen($plain):(int)$at+mb_strlen($selected);
    foreach($segments as $g){$len=mb_strlen($normalize($g['transcript_text']));if($cursor+$len>(int)$at&&$cursor<$end){$page=1;foreach($map['pages'] as $pg)if((int)$g['segment_index']>=(int)$pg['start_segment_index']&&(int)$g['segment_index']<=(int)$pg['end_segment_index']){$page=(int)$pg['page_number'];break;}$refs[]=['segment_id'=>(int)$g['id'],'page'=>$page,'started_ms'=>(int)$g['started_ms'],'speaker_label'=>(string)$g['speaker_label']];}$cursor+=$len+1;if(count($refs)>=100)break;}
    $text=$whole?'Transcript reference: '.mb_substr($plain,0,1800):$selected;
    $meta=['source'=>'transcript_highlight_v1','session_id'=>$id,'title'=>$s['title'],'conversation_id'=>(int)($s['conversation_id']??0),'source_url'=>url('/artist-listening.php?session='.$id),'source_hash'=>$map['source_hash'],'highlight_kind'=>$whole?'transcript_reference':'selected_text','highlight_text'=>$whole?'':$selected,'evidence_refs'=>$refs,'explicit_user_action'=>true,'saved_at'=>gmdate('c')];
    $subject='transcript-highlight:'.$id.':'.hash('sha256',$whole?'whole':$selected);
    return vp3_transcript_brain_scope_v1($pdo,$user,$s,function($aid)use($pdo,$user,$id,$subject,$text,$meta){
        $existing=agent_brain_v122_memory($user,'transcript_highlight',$subject);
        $memoryId=agent_brain_v122_upsert_system_memory($user,'transcript_highlight',$subject,mb_substr($text,0,5500)."\nSource: ".$meta['source_url'],$meta,0.98);
        if($memoryId<1)throw new RuntimeException('The highlight could not be saved.');
        $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET agent_memory_id=?,last_activity_at=? WHERE id=? AND created_by_user_id=?')->execute([$memoryId,gmdate('Y-m-d H:i:s'),$id,(int)$user['id']]);
        return ['memory_id'=>$memoryId,'existing'=>$existing!==null,'session_id'=>$id,'agent_id'=>$aid,'highlight_kind'=>$meta['highlight_kind'],'profile_access'=>'existing_knowledge_policy','saved_at'=>$meta['saved_at']];
    },$principal);
}
/** Expand explicitly retained highlights for owner Chat; never grant Profile sharing here. */
function vp3_transcript_highlight_context_v1(PDO $pdo,array $user,array $principal,string $query): array
{
    if(!personal_capability_has_v242('agent_brain.access',$user)||!agent_brain_schema_ready())return [];
    $aid=($principal['kind']??'')==='user_agent'?(int)$principal['agent_id']:0;[$scope,$args]=vp3_agent_memory_scope_sql_v410($aid,'m');
    $q=$pdo->prepare("SELECT m.id,m.subject,m.memory_text,m.metadata_json FROM agent_memory_items m WHERE m.user_id=? AND $scope AND m.memory_type IN ('transcript_highlight','transcript_analysis','transcript_intelligence') AND m.is_active=1 ORDER BY m.last_seen_at DESC,m.id DESC LIMIT 60");$q->execute([(int)$user['id'],...$args]);$terms=function_exists('chat_policy_terms_v236')?chat_policy_terms_v236($query):preg_split('/\s+/u',mb_strtolower($query));$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$meta=json_decode((string)$r['metadata_json'],true);if(!is_array($meta))continue;$full=(string)(($meta['highlight_text']??'')?:($meta['retained_report_text']??'')?:$r['memory_text']);$hay=mb_strtolower(($meta['title']??'').' highlight '.$full);$matched=!$terms;$offset=0;foreach($terms as $t)if(mb_strlen((string)$t)>2&&mb_strpos($hay,mb_strtolower((string)$t))!==false){$matched=true;$pos=mb_stripos($full,(string)$t);if($pos!==false)$offset=max(0,$pos-1000);break;}if(!$matched)continue;
        if(($principal['kind']??'')==='user_agent'&&!user_data_policy_can_use_v236($pdo,$principal,(int)$user['id'],'knowledge','brain:'.$r['id'],false))continue;
        $out[]=['source'=>'agent-brain:transcript-highlight:'.$r['id'],'title'=>'Retained transcript highlight · '.($meta['title']??'Transcript'),'text'=>'User-highlighted recorded content, not independently verified facts. Source: '.($meta['source_url']??'')."\n".vp3_transcript_retained_excerpt_v1($pdo,(int)$user['id'],$meta,$full,$terms)."\nEvidence: ".json_encode($meta['evidence_refs']??[])];if(count($out)>=4)break;
    }
    return $out;
}
/** Read retained text only. Source freshness is metadata, never a permission grant. */
function vp3_transcript_retained_excerpt_v1(PDO $pdo,int $owner,array $metadata,string $fallback,array $terms=[]): string
{
    $text=(string)(($metadata['highlight_text']??'')?:($metadata['retained_report_text']??'')?:$fallback);$offset=0;
    foreach($terms as $term)if(mb_strlen((string)$term)>2&&($pos=mb_stripos($text,(string)$term))!==false){$offset=max(0,$pos-1000);break;}
    $status='Source not checked';$sid=(int)($metadata['session_id']??0);
    try{$q=$pdo->prepare('SELECT id,status FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=?');$q->execute([$sid,$owner]);$s=$q->fetch(PDO::FETCH_ASSOC);if(!$s||$s['status']==='discarded')$status='Source transcript unavailable; this is an explicitly retained note';elseif(!empty($metadata['source_hash'])){$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$sid));$status=hash_equals((string)$metadata['source_hash'],$map['source_hash'])?'Source transcript unchanged since saving':'Source transcript changed since saving; retained content may be outdated';}}catch(Throwable $e){$status='Source freshness could not be checked';}
    return $status.'. '.(($metadata['highlight_kind']??'')==='analysis_report'?'Saved AI interpretation; review labels apply.':'User-retained content; not independently verified facts.')."\n".mb_substr($text,$offset,6000);
}
