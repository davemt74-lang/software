<?php
declare(strict_types=1);

/** Saved Profile call evidence only. No schema repair, provider, capture or delivery work. */
const VP3_PROFILE_CALL_OUTCOME_V1='vp3.profile-call-outcome.v1';
const VP3_PROFILE_OUTCOME_TRANSCRIPT_ROWS_V1=4096;
const VP3_PROFILE_OUTCOME_TRANSCRIPT_BYTES_V1=8388608;

function vp3_profile_call_outcome_hash_v1(mixed $value): string
{return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}

function vp3_profile_call_outcome_encode_v1(array $value): string
{return rtrim(strtr(base64_encode(json_encode($value,JSON_THROW_ON_ERROR)),'+/','-_'),'=');}

function vp3_profile_call_outcome_decode_v1(string $cursor): array
{
    if(strlen($cursor)>1600||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$cursor))throw new RuntimeException('Refresh this saved call view.',422);
    $raw=base64_decode(strtr($cursor,'-_','+/'),true);$value=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($value)||array_is_list($value))throw new RuntimeException('Refresh this saved call view.',422);
    return $value;
}

function vp3_profile_call_outcome_text_v1(mixed $value,int $limit=2000): string
{
    if(!is_string($value))return '';
    return mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u','',$value)??''),0,$limit);
}

function vp3_profile_call_outcome_section_v1(string $availability,string $reason='',string $coverage='ready'): array
{
    return ['availability'=>$availability,'coverage'=>$coverage,'source_revision'=>null,'reason'=>$reason,
        'items'=>[],'has_more'=>false,'next_cursor'=>null];
}

function vp3_profile_call_outcome_source_link_v1(array $context): array
{
    $id=(int)($context['recording']['session']['id']??0);
    return $id>0?['session_id'=>$id,'url'=>url('/artist-listening.php?session='.$id)]:[];
}

function vp3_profile_call_outcome_schema_v1(PDO $pdo,string $table,string $columns): bool
{
    try{$pdo->query('SELECT '.$columns.' FROM '.$table.' WHERE 1=0');return true;}catch(PDOException){return false;}
}

function vp3_profile_call_outcome_bytes_v1(PDO $pdo,string $column): string
{return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'LENGTH(CAST(COALESCE('.$column.",'') AS BLOB))":'OCTET_LENGTH(COALESCE('.$column.",''))";}

/** Read the exact recording under current selected private Agent policy. */
function vp3_profile_call_outcome_recording_v1(PDO $pdo,array $scope,array $call): array
{
    if(empty($call['assisted'])||$call['mode']!=='audio')return ['availability'=>'not_applicable','reason'=>'unassisted_call','session'=>null,'proof'=>null];
    if(!has_permission('artist_listening.access',$scope['user']))return ['availability'=>'forbidden','reason'=>'recording_access','session'=>null,'proof'=>null];
    if(!vp3_profile_call_outcome_schema_v1($pdo,'profile_call_assistance_pa3c','call_id,transcript_session_id'))
        return ['availability'=>'unavailable','reason'=>'assistance_storage','session'=>null,'proof'=>null];
    $q=$pdo->prepare('SELECT transcript_session_id FROM profile_call_assistance_pa3c WHERE call_id=?');$q->execute([$call['public_id']]);$id=$q->fetchColumn();
    if($id===false||(string)$id==='0')return ['availability'=>'empty','reason'=>'no_saved_recording','session'=>null,'proof'=>0];
    if(!is_scalar($id)||!preg_match('/\A[1-9][0-9]*\z/D',(string)$id))return ['availability'=>'unavailable','reason'=>'recording_link','session'=>null,'proof'=>null];
    if(!vp3_profile_call_outcome_schema_v1($pdo,'artist_transcript_sessions_v172','id,created_by_user_id,conversation_id,status,discarded_at,metadata_json'))
        return ['availability'=>'unavailable','reason'=>'recording_storage','session'=>null,'proof'=>null];
    $q=$pdo->prepare('SELECT id,created_by_user_id,discarded_at FROM artist_transcript_sessions_v172 WHERE id=?');$q->execute([(int)$id]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    if(!$saved||(int)$saved['created_by_user_id']!==(int)$scope['user']['id']||$saved['discarded_at']!==null)
        return ['availability'=>'unavailable','reason'=>'recording_unavailable','session'=>null,'proof'=>null];
    require_once __DIR__.'/transcript-agent-v1.php';
    try{$session=vp3_transcript_session_v1($pdo,$scope['user'],$scope['principal'],(int)$id);}
    catch(PDOException $error){throw $error;}
    catch(RuntimeException $error){if(!in_array($error->getCode(),[403,404],true))throw $error;return ['availability'=>'forbidden','reason'=>'recording_access','session'=>null,'proof'=>null];}
    $raw=$session['metadata_json']??null;$meta=is_string($raw)&&strlen($raw)<=32768?json_decode($raw,true):null;
    if(!is_array($meta)||array_is_list($meta)||($meta['source']??null)!=='profile_call_pa3c'||($meta['profile_call_id']??null)!==$call['public_id']
        ||isset($meta['capture_mode'])||isset($meta['video_meeting_id'])||isset($meta['video_meeting_public_id']))
        return ['availability'=>'unavailable','reason'=>'recording_origin','session'=>null,'proof'=>null];
    return ['availability'=>'ready','reason'=>'','session'=>$session,'proof'=>[(int)$id,(int)$session['created_by_user_id'],
        (int)($session['conversation_id']??0),(string)$session['status'],$raw]];
}

function vp3_profile_workspace_call_outcome_context_v1(PDO $pdo,array $scope,int $callId,int $conversation): array
{
    $scope=vp3_profile_workspace_call_scope_v1($pdo,$scope);$call=vp3_profile_workspace_call_row_v1($pdo,$scope,$callId);
    if($conversation<0||($conversation>0&&(int)$call['conversation_id']!==$conversation))throw new RuntimeException('The selected call belongs to another visitor conversation.',404);
    if(!in_array($call['state'],['ended','declined','cancelled','expired','failed'],true))throw new RuntimeException('This call is current. Refresh its call view.',409);
    if((int)($call['ended_at_ms']??0)<(int)$call['created_at_ms'])throw new RuntimeException('This call outcome could not be verified.',409);
    $recording=vp3_profile_call_outcome_recording_v1($pdo,$scope,$call);
    $binding=['owner'=>(int)$scope['user']['id'],'agent'=>$scope['agent_id'],'chat'=>$scope['conversation_id'],'call'=>$callId];
    $revision=vp3_profile_call_outcome_hash_v1([$binding,$call['binding_hash'],(int)$call['revision'],$call['state'],
        $call['accepted_at_ms'],$call['ended_at_ms'],$recording['availability'],$recording['reason'],$recording['proof']]);
    return ['scope'=>$scope,'call'=>$call,'recording'=>$recording,'binding'=>$binding,'revision'=>$revision];
}

/** Pages are tied to the complete bounded canonical source, not a timestamp shortcut. */
function vp3_profile_call_outcome_page_v1(array $context,string $name,array $items,mixed $proof,bool $complete,string $cursor,int $limit,array $extra=[],bool $limited=false): array
{
    $revision=$complete?vp3_profile_call_outcome_hash_v1([$name,$proof,$items,$extra]):null;
    $binding=$context['binding']+['context'=>$context['revision'],'section'=>$name];$after=0;
    if($cursor!==''){
        $saved=vp3_profile_call_outcome_decode_v1($cursor);
        if(array_keys($saved)!==['binding','source','after']||$saved['binding']!==$binding||!is_string($saved['after'])||$saved['after']===''||strlen($saved['after'])>80)
            throw new RuntimeException('Refresh this saved call section.',422);
        if($revision===null||!is_string($saved['source'])||!hash_equals($revision,$saved['source']))throw new RuntimeException('This saved call section changed. Refresh it before continuing.',409);
        $position=array_search($saved['after'],array_map(static fn(array $item):string=>(string)($item['id']??''),$items),true);
        if($position===false||$position+1>=count($items))throw new RuntimeException('Refresh this saved call section.',422);
        $after=$position+1;
    }
    // The last exact item key and the complete source hash must both still match.
    $page=[];$bytes=0;
    foreach(array_slice($items,$after,$limit) as $item){
        $size=strlen(json_encode($item,JSON_THROW_ON_ERROR));
        if($page&&$bytes+$size>131072)break;
        $page[]=$item;$bytes+=$size;
    }
    $next=$after+count($page);$more=$next<count($items)||!$complete;
    return ['availability'=>$items?'ready':'empty','coverage'=>$limited||!$complete||$more?'limited':'ready',
        'source_revision'=>$revision,'reason'=>!$complete?'source_limit':($limited?'partial_evidence':''),
        'items'=>$page,'has_more'=>$more,'next_cursor'=>$complete&&$next<count($items)?vp3_profile_call_outcome_encode_v1(['binding'=>$binding,'source'=>$revision,'after'=>(string)($page[count($page)-1]['id']??'')]):null]+$extra;
}

/** Both the row and byte guards are inside the same SELECT as the content read. */
function vp3_profile_call_outcome_bounded_v1(PDO $pdo,string $table,string $columns,string $where,array $args,string $order,string $bytes,int $rowLimit,int $byteLimit): array
{
    $q=$pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM('.$bytes.'),0) bytes FROM '.$table.' WHERE '.$where);$q->execute($args);$stats=$q->fetch(PDO::FETCH_ASSOC);
    $complete=(int)$stats['n']<=$rowLimit&&(int)$stats['bytes']<=$byteLimit;
    if(!$complete)return ['complete'=>false,'rows'=>[],'stats'=>[(int)$stats['n'],(int)$stats['bytes']]];
    $guard='(SELECT COUNT(*) FROM '.$table.' WHERE '.$where.')<='.$rowLimit.' AND (SELECT COALESCE(SUM('.$bytes.'),0) FROM '.$table.' WHERE '.$where.')<='.$byteLimit;
    $q=$pdo->prepare('SELECT '.$columns.' FROM '.$table.' WHERE '.$where.' AND '.$guard.' ORDER BY '.$order.' LIMIT '.($rowLimit+1));
    $q->execute([...$args,...$args,...$args]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==(int)$stats['n'])throw new RuntimeException('This saved call evidence changed. Refresh its view.',409);
    return ['complete'=>true,'rows'=>$rows,'stats'=>[(int)$stats['n'],(int)$stats['bytes']]];
}

function vp3_profile_call_outcome_transcript_source_v1(PDO $pdo,array $context): array
{
    $session=$context['recording']['session'];$id=(int)$session['id'];
    $columns='id,session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence,created_at,updated_at';
    if(!vp3_profile_call_outcome_schema_v1($pdo,'artist_transcript_segments_v172',$columns))return ['availability'=>'unavailable','reason'=>'transcript_storage'];
    $read=vp3_profile_call_outcome_bounded_v1($pdo,'artist_transcript_segments_v172',$columns,'session_id=?',[$id],'segment_index,id',
        vp3_profile_call_outcome_bytes_v1($pdo,'transcript_text'),VP3_PROFILE_OUTCOME_TRANSCRIPT_ROWS_V1,VP3_PROFILE_OUTCOME_TRANSCRIPT_BYTES_V1);
    if(!$read['complete']){
        $q=$pdo->prepare('SELECT id,segment_type,speaker_label,SUBSTR(transcript_text,1,8000) transcript_text,started_ms FROM artist_transcript_segments_v172 WHERE session_id=? ORDER BY segment_index,id LIMIT 50');
        $q->execute([$id]);$read['rows']=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['availability'=>'ready','reason'=>'']+$read;
}

function vp3_profile_workspace_call_outcome_transcript_v1(PDO $pdo,array $context,string $cursor,array &$cache): array
{
    $source=$cache['transcript']??=vp3_profile_call_outcome_transcript_source_v1($pdo,$context);
    if($source['availability']!=='ready')return vp3_profile_call_outcome_section_v1($source['availability'],$source['reason']);
    $items=[];$limited=false;
    foreach($source['rows'] as $row){
        if(($row['segment_type']??null)!=='transcript')continue;
        if(!is_string($row['transcript_text']??null)||!is_string($row['speaker_label']??null)||(int)$row['id']<1){$limited=true;continue;}
        $truncated=!$source['complete']||mb_strlen($row['transcript_text'])>8000;$limited=$limited||$truncated;
        $items[]=['id'=>(int)$row['id'],'speaker'=>vp3_profile_call_outcome_text_v1($row['speaker_label'],100),
            'text'=>vp3_profile_call_outcome_text_v1($row['transcript_text'],8000),'start_ms'=>max(0,(int)$row['started_ms']),'text_truncated'=>$truncated];
    }
    $id=(int)$context['recording']['session']['id'];
    return vp3_profile_call_outcome_page_v1($context,'transcript',$items,$source['rows'],$source['complete'],$cursor,50,
        ['session_id'=>$id,'url'=>url('/artist-listening.php?session='.$id)],$limited);
}

function vp3_profile_workspace_call_outcome_analysis_v1(PDO $pdo,array $context,string $cursor,array &$cache): array
{
    if($cursor!=='')throw new RuntimeException('Refresh the saved call analysis.',422);
    $id=(int)$context['recording']['session']['id'];$extra=['generated'=>false,'fresh'=>false,'generated_at'=>'','session_id'=>$id,'url'=>url('/artist-listening.php?session='.$id)];
    if(!vp3_profile_call_outcome_schema_v1($pdo,'artist_transcript_master_analysis_v237','session_id,source_hash,source_word_count,analysis_json,research_json,generated_at,page_count,analyzed_page_count'))
        return vp3_profile_call_outcome_section_v1('unavailable','analysis_storage')+$extra;
    $bytes=vp3_profile_call_outcome_bytes_v1($pdo,'analysis_json').'+'.vp3_profile_call_outcome_bytes_v1($pdo,'research_json');
    $read=vp3_profile_call_outcome_bounded_v1($pdo,'artist_transcript_master_analysis_v237','session_id,source_hash,source_word_count,analysis_json,research_json,generated_at,page_count,analyzed_page_count','session_id=?',[$id],'session_id',$bytes,1,524288);
    if(!$read['complete'])return vp3_profile_call_outcome_section_v1('unavailable','source_limit','limited')+array_replace($extra,['generated'=>true]);
    if(!$read['rows'])return vp3_profile_call_outcome_section_v1('empty','no_saved_analysis')+$extra;
    $saved=$read['rows'][0];$extra['generated']=true;$extra['generated_at']=(string)$saved['generated_at'];
    $analysis=json_decode((string)$saved['analysis_json'],true);$research=json_decode((string)($saved['research_json']??''),true);
    if(!is_array($analysis)||array_is_list($analysis))return vp3_profile_call_outcome_section_v1('unavailable','analysis_malformed')+$extra;
    $source=$cache['transcript']??=vp3_profile_call_outcome_transcript_source_v1($pdo,$context);
    if($source['availability']!=='ready'||!$source['complete'])return vp3_profile_call_outcome_section_v1('unavailable','transcript_proof','limited')+$extra;
    $map=artist_listening_transcript_page_map($source['rows']);$hash=(string)$map['source_hash'];
    $extra['source_pages']=(int)$map['page_count'];$extra['analyzed_pages']=max(0,min($extra['source_pages'],(int)$saved['analyzed_page_count']));
    $partial=(int)$saved['page_count']!==$extra['source_pages']||$extra['analyzed_pages']<$extra['source_pages'];
    $master=['source_hash'=>(string)$saved['source_hash'],'source_word_count'=>(int)$saved['source_word_count'],
        'word_count'=>(int)$saved['source_word_count'],'generated_at'=>(string)$saved['generated_at'],'analysis'=>$analysis,'research'=>is_array($research)?$research:[]];
    $mods=transcription_deeper_normalize_modules_v307(transcription_app_modules_v306($analysis,$master));$items=[];$fresh=hash_equals((string)$saved['source_hash'],$hash);$kind='';
    $summary=$mods['summary_output']??null;
    if($fresh&&is_array($summary)){
        $input=transcription_output_reviewed_input_v306($mods,$hash);$inputHash=transcription_output_input_hash_v306($input,$hash);
        if($inputHash!==''&&hash_equals($hash,(string)($summary['source_hash']??''))&&hash_equals($inputHash,(string)($summary['context_hash']??''))){
            $kind='reviewed';foreach(['overview'=>['summary','summary'],'key_points'=>['finding','point'],'open_questions'=>['question','question'],'next_steps'=>['next_step','next_step']] as $key=>[$type,$field])
                foreach((array)($summary['result'][$key]??[]) as $row)if(is_array($row)&&($row['review_state']??'')!=='rejected'&&($text=vp3_profile_call_outcome_text_v1($row[$field]??null,2000))!=='')
                    $items[]=['kind'=>$type,'text'=>$text,'reviewed'=>false,'based_on_reviewed_sources'=>true];
        }
    }
    if($fresh&&$kind===''){
        $basic=$mods['basic']??null;
        if(is_array($basic)&&hash_equals($hash,(string)($basic['source_hash']??''))){
            $kind='basic';$value=$basic['result']??[];
            if(($text=vp3_profile_call_outcome_text_v1($value['summary']??null,2000))!=='')$items[]=['kind'=>'summary','text'=>$text,'reviewed'=>false,'based_on_reviewed_sources'=>false];
            foreach(['key_findings'=>'finding','open_questions'=>'question'] as $key=>$type)foreach((array)($value[$key]??[]) as $row)
                if(is_array($row)&&($row['review_state']??'')!=='rejected'&&($text=vp3_profile_call_outcome_text_v1($row['text']??null,2000))!=='')
                    $items[]=['kind'=>$type,'text'=>$text,'reviewed'=>($row['review_state']??'')==='accepted','based_on_reviewed_sources'=>false];
        }
    }
    $extra['fresh']=$fresh&&$kind!=='';$limited=count($items)>24;$items=array_slice($items,0,24);
    $out=vp3_profile_call_outcome_page_v1($context,'analysis',$items,[$saved,$hash],true,'',24,$extra,$limited||$partial||!$extra['fresh']);
    if(!$extra['fresh']){$out['availability']='ready';$out['reason']=$fresh?'analysis_not_supported':'transcript_changed';}
    elseif($partial)$out['reason']='partial_analysis';
    return $out;
}

function vp3_profile_workspace_call_outcome_research_v1(PDO $pdo,array $context,string $cursor): array
{
    $extra=vp3_profile_call_outcome_source_link_v1($context);
    $columns='call_id,request_id,query,status,started_at_ms,result_json';
    if(!vp3_profile_call_outcome_schema_v1($pdo,'profile_call_research_pa3c',$columns))return vp3_profile_call_outcome_section_v1('unavailable','research_storage')+$extra;
    $bytes=vp3_profile_call_outcome_bytes_v1($pdo,'query').'+'.vp3_profile_call_outcome_bytes_v1($pdo,'result_json');
    $source=vp3_profile_call_outcome_bounded_v1($pdo,'profile_call_research_pa3c',$columns,'call_id=?',[$context['call']['public_id']],'started_at_ms,request_id',$bytes,128,1048576);
    if(!$source['complete']){
        $q=$pdo->prepare('SELECT call_id,request_id,query,status,started_at_ms,CASE WHEN '.vp3_profile_call_outcome_bytes_v1($pdo,'result_json').'<=65536 THEN result_json ELSE NULL END result_json FROM profile_call_research_pa3c WHERE call_id=? ORDER BY started_at_ms,request_id LIMIT 6');
        $q->execute([$context['call']['public_id']]);$source['rows']=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $items=[];$limited=false;
    foreach($source['rows'] as $row){
        $payload=json_decode((string)($row['result_json']??''),true);$status=(string)$row['status'];
        if(!preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D',(string)$row['request_id'])||!in_array($status,['running','complete','failed'],true)
            ||!is_string($row['query'])||trim($row['query'])===''||(int)$row['started_at_ms']<1
            ||($status==='complete'&&(!is_array($payload)||array_is_list($payload)||!is_string($payload['summary']??null)||!is_array($payload['sources']??null)))){$limited=true;continue;}
        $links=[];
        foreach(array_slice((array)($payload['sources']??[]),0,12) as $link){
            $url=is_array($link)?($link['url']??null):null;$parts=is_string($url)?parse_url($url):false;
            if(!is_string($url)||strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])){$limited=true;continue;}
            $links[]=['title'=>vp3_profile_call_outcome_text_v1($link['title']??$url,220),'url'=>$url];
        }
        $items[]=['id'=>(string)$row['request_id'],'query'=>vp3_profile_call_outcome_text_v1($row['query'],500),
            'stored_status'=>$status,'display_status'=>$status==='running'?'incomplete':$status,
            'summary'=>$status==='complete'?vp3_profile_call_outcome_text_v1($payload['summary']??'',4000):'','sources'=>$status==='complete'?$links:[],
            'started_at_ms'=>(int)$row['started_at_ms']];
    }
    $out=vp3_profile_call_outcome_page_v1($context,'research',$items,$source['rows'],$source['complete'],$cursor,6,$extra,$limited);
    if(!$items&&$limited){$out['availability']='unavailable';$out['reason']='research_malformed';}
    return $out;
}

function vp3_profile_workspace_call_outcome_followups_v1(PDO $pdo,array $context,string $cursor): array
{
    $scope=$context['scope'];$id=(int)$context['recording']['session']['id'];
    $extra=vp3_profile_call_outcome_source_link_v1($context);
    if(!personal_capability_has_v242('agent_brain.access',$scope['user']))return vp3_profile_call_outcome_section_v1('forbidden','task_access');
    require_once __DIR__.'/agent-task-lists-v1.php';require_once __DIR__.'/agent-memory-scope-v410.php';
    if(!vp3_agent_memory_scope_schema_ready_v410($pdo)||!vp3_profile_call_outcome_schema_v1($pdo,'agent_memory_items','id,user_id,user_agent_id,is_active,memory_type,subject,metadata_json'))
        return vp3_profile_call_outcome_section_v1('unavailable','task_storage')+$extra;
    vp3_task_list_authorize_v1($pdo,$scope['user'],$scope['principal'],$scope['conversation_id']);
    [$where,$args]=vp3_agent_memory_scope_sql_v410($scope['agent_id']);
    $where='user_id=? AND '.$where." AND is_active=1 AND memory_type IN ('task','commitment') AND
        CASE WHEN JSON_VALID(metadata_json) THEN CAST(JSON_EXTRACT(metadata_json,'$.source_session_id') AS CHAR) ELSE NULL END=?";
    $args=[(int)$scope['user']['id'],...$args,(string)$id];
    $source=vp3_profile_call_outcome_bounded_v1($pdo,'agent_memory_items','id,subject,metadata_json',$where,$args,'id',vp3_profile_call_outcome_bytes_v1($pdo,'metadata_json'),200,1048576);
    if(!$source['complete']){
        $q=$pdo->prepare('SELECT id,subject,CASE WHEN '.vp3_profile_call_outcome_bytes_v1($pdo,'metadata_json').'<=65536 THEN metadata_json ELSE NULL END metadata_json FROM agent_memory_items WHERE '.$where.' ORDER BY id LIMIT 20');
        $q->execute($args);$source['rows']=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $items=[];$limited=false;$proof=[];
    foreach($source['rows'] as $row){
        $meta=json_decode((string)$row['metadata_json'],true);$task=(int)$row['id'];
        if(!is_array($meta)||($meta['source_kind']??null)!=='transcription_intelligence'||($meta['created_from_reviewed_item']??null)!==true
            ||($meta['source_session_id']??null)!==$id||!is_string($meta['item_id']??null)||$meta['item_id']===''
            ||!in_array($meta['task_status']??null,['open','in_progress','waiting','completed','cancelled'],true)
            ||(array_key_exists('assigned_user_id',$meta)&&(!is_int($meta['assigned_user_id'])||$meta['assigned_user_id']<0))
            ||!is_string($row['subject'])||trim($row['subject'])===''){$limited=true;continue;}
        if(!in_array((int)($meta['assigned_user_id']??0),[0,(int)$scope['user']['id']],true)||!vp3_task_list_task_allowed_v1($pdo,$scope['user'],$scope['principal'],$task))continue;
        $proof[]=$row;$items[]=['id'=>$task,'canonical_ref'=>'office:task:'.$task,'title'=>vp3_profile_call_outcome_text_v1($row['subject'],240),
            'task_status'=>$meta['task_status'],'source_url'=>url('/artist-listening.php?session='.$id)];
    }
    return vp3_profile_call_outcome_page_v1($context,'followups',$items,$proof,$source['complete'],$cursor,20,$extra,$limited);
}

function vp3_profile_call_outcome_collect_v1(PDO $pdo,array $context,array $names,string $cursor): array
{
    $out=[];$cache=[];$recording=$context['recording'];
    foreach($names as $name){
        if(in_array($recording['availability'],['forbidden','unavailable','not_applicable'],true)){$out[$name]=vp3_profile_call_outcome_section_v1($recording['availability'],$recording['reason']);continue;}
        if($recording['availability']==='empty'&&$name!=='research'){$out[$name]=vp3_profile_call_outcome_section_v1('empty','no_saved_recording');continue;}
        try{
            $out[$name]=match($name){
                'transcript'=>vp3_profile_workspace_call_outcome_transcript_v1($pdo,$context,$cursor,$cache),
                'analysis'=>vp3_profile_workspace_call_outcome_analysis_v1($pdo,$context,$cursor,$cache),
                'research'=>vp3_profile_workspace_call_outcome_research_v1($pdo,$context,$cursor),
                'followups'=>vp3_profile_workspace_call_outcome_followups_v1($pdo,$context,$cursor),
            };
        }catch(PDOException){$out[$name]=vp3_profile_call_outcome_section_v1('unavailable',$name.'_storage');}
    }
    return $out;
}

function vp3_profile_workspace_call_outcome_v1(PDO $pdo,array $scope,int $callId,int $publicConversationId=0,string $section='all',string $cursor=''): array
{
    if(!in_array($section,['all','transcript','analysis','research','followups'],true)||($section==='all'&&$cursor!==''))throw new RuntimeException('Choose a valid saved call section.',422);
    $names=$section==='all'?['transcript','analysis','research','followups']:[$section];
    $context=vp3_profile_workspace_call_outcome_context_v1($pdo,$scope,$callId,$publicConversationId);
    if($cursor!==''&&$context['recording']['availability']!=='forbidden'){
        $saved=vp3_profile_call_outcome_decode_v1($cursor);$expected=$context['binding']+['section'=>$section];
        if(!is_array($saved['binding']??null)||array_diff_key($saved['binding'],['context'=>true])!==$expected)
            throw new RuntimeException('Refresh this saved call section.',422);
        if(($saved['binding']['context']??null)!==$context['revision'])throw new RuntimeException('This saved call outcome changed. Refresh its view.',409);
    }
    $card=vp3_profile_workspace_call_card_v1($pdo,$context['scope'],$context['call']);
    $sections=vp3_profile_call_outcome_collect_v1($pdo,$context,$names,$cursor);
    $again=vp3_profile_workspace_call_outcome_context_v1($pdo,$scope,$callId,$publicConversationId);
    if($again['revision']!==$context['revision']){
        if($again['recording']['availability']==='forbidden'){
            $context=$again;$card=vp3_profile_workspace_call_card_v1($pdo,$again['scope'],$again['call']);$sections=vp3_profile_call_outcome_collect_v1($pdo,$again,$names,'');
        }else throw new RuntimeException('This saved call outcome changed. Refresh its view.',409);
    }else{
        $verified=vp3_profile_call_outcome_collect_v1($pdo,$again,$names,$cursor);
        if($sections!==$verified)throw new RuntimeException('This saved call evidence changed. Refresh its view.',409);
    }
    $final=vp3_profile_workspace_call_outcome_context_v1($pdo,$scope,$callId,$publicConversationId);
    if($final['revision']!==$context['revision'])throw new RuntimeException('This saved call outcome changed. Refresh its view.',409);
    if($card!==vp3_profile_workspace_call_card_v1($pdo,$final['scope'],$final['call']))throw new RuntimeException('This saved call view changed. Refresh it.',409);
    return ['contract'=>VP3_PROFILE_CALL_OUTCOME_V1,'call_id'=>$callId,'public_conversation_id'=>(int)$context['call']['conversation_id'],
        'context_revision'=>$context['revision'],'call'=>$card,'sections'=>$sections];
}
