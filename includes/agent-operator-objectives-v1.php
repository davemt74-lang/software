<?php
declare(strict_types=1);
require_once __DIR__.'/transcription-review-proof-v1.php';

/** Call agenda references only. Questions and answers remain in their canonical stores. */
function vp3_operator_objectives_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT call_id,call_public_id,owner_user_id,agent_id,chat_agent_id,conversation_id,contact_id,agenda_revision,agenda_hash,call_binding,source_state,created_epoch FROM phone_operator_call_agendas_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
function vp3_operator_objectives_install_v1(PDO $pdo):void
{
    if(vp3_phone_transaction_active_v1($pdo))throw new LogicException('Install call agenda references outside a transaction.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$tail=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $index=$sqlite?'':',KEY idx_operator_call_agenda_owner_v1(owner_user_id,contact_id,created_epoch)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_call_agendas_v1(
        call_id BIGINT PRIMARY KEY,call_public_id CHAR(32) NOT NULL UNIQUE,owner_user_id BIGINT NOT NULL,
        agent_id BIGINT NOT NULL,chat_agent_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,
        contact_id BIGINT NOT NULL,agenda_revision BIGINT NOT NULL,agenda_hash CHAR(64) NOT NULL,
        call_binding CHAR(64) NOT NULL,source_state VARCHAR(16) NOT NULL,created_epoch BIGINT NOT NULL$index
    )".$tail);
    if($sqlite)$pdo->exec('CREATE INDEX IF NOT EXISTS idx_operator_call_agenda_owner_v1 ON phone_operator_call_agendas_v1(owner_user_id,contact_id,created_epoch)');
    if(!vp3_operator_objectives_ready_v1($pdo))throw new RuntimeException('Call agenda reference installation failed.');
}
function vp3_operator_objectives_hash_v1(array $agenda):string
{
    return hash('sha256',json_encode([(int)$agenda['owner_user_id'],(int)$agenda['contact_id'],(int)$agenda['conversation_id'],
        (int)$agenda['chat_agent_id'],(int)$agenda['number_id'],(int)$agenda['revision'],(string)$agenda['destination_hash'],
        (string)$agenda['topics'],(string)$agenda['questions']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function vp3_operator_objectives_call_hash_v1(array $call):string
{
    return hash('sha256',json_encode([(int)$call['id'],(string)$call['public_id'],(int)$call['owner_user_id'],(int)$call['agent_id'],
        (int)$call['chat_agent_id'],(int)$call['conversation_id'],(int)$call['phone_number_id'],(string)$call['peer_e164'],
        (string)$call['provider_call_sid']],JSON_THROW_ON_ERROR));
}
function vp3_operator_objectives_row_v1(PDO $pdo,int $id):?array
{
    if(!vp3_operator_objectives_ready_v1($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM phone_operator_call_agendas_v1 WHERE call_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r?:null;
}
/** First accepted outbound call freezes even a missing/ambiguous agenda. Never backfill an ended call. */
function vp3_operator_objectives_bind_v1(PDO $pdo,array $announced,int $now):void
{
    $public=(string)($announced['public_id']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$public)||!vp3_operator_objectives_ready_v1($pdo))return;
    if(!vp3_phone_transaction_active_v1($pdo))throw new LogicException('Bind call agenda references under the existing call owner lock.');
    $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=?'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([$public,(int)$announced['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$call||$call['direction']!=='outbound'||vp3_operator_objectives_row_v1($pdo,(int)$call['id'])||(int)$call['conversation_id']<1
        ||!in_array($call['state'],['dialing','ringing','answered'],true)||(int)$call['created_epoch']>$now||(int)$call['created_epoch']<$now-300
        ||!preg_match('/^CA[a-f0-9]{32}$/iD',(string)$call['provider_call_sid'])
        ||(string)($call['paired_profile_id']??'')!==''||(int)($call['owner_phone_revision']??0)>0)return;
    $q=$pdo->prepare('SELECT 1 FROM chat_conversations WHERE id=? AND user_id=? AND COALESCE(user_agent_id,0)=?');
    $q->execute([(int)$call['conversation_id'],(int)$call['owner_user_id'],(int)$call['chat_agent_id']]);if(!$q->fetchColumn())return;$q->closeCursor();
    $rows=[];$overflow=false;
    try{
        $q=$pdo->prepare("SELECT a.*,c.phone contact_phone FROM phone_operator_agendas_v1 a JOIN crm_contacts c ON c.id=a.contact_id AND c.owner_user_id=a.owner_user_id
            WHERE a.owner_user_id=? AND a.conversation_id=? AND a.chat_agent_id=? AND a.number_id=? AND a.destination_hash=? AND c.status<>'archived' ORDER BY a.contact_id LIMIT 101");
        $q->execute([(int)$call['owner_user_id'],(int)$call['conversation_id'],(int)$call['chat_agent_id'],(int)$call['phone_number_id'],hash('sha256',(string)$call['peer_e164'])]);
        $candidates=$q->fetchAll(PDO::FETCH_ASSOC);$overflow=count($candidates)>100;
        foreach($candidates as $r){
            try{$peer=vp3_phone_caller_contacts_e164_v1((string)$r['contact_phone']);}catch(RuntimeException){continue;}
            if($peer===$call['peer_e164'])$rows[]=$r;
        }$q->closeCursor();
    }catch(PDOException $e){
        // Optional agenda or CRM source migrations may not yet be installed. Do not run DDL or infer a source.
        try{
            $pdo->query('SELECT contact_id,questions FROM phone_operator_agendas_v1 WHERE 1=0');
            $pdo->query('SELECT id,owner_user_id,phone,status FROM crm_contacts WHERE 1=0');
        }catch(PDOException){return;}
        throw $e;
    }
    $a=!$overflow&&count($rows)===1?$rows[0]:null;$state=$a?'bound':($overflow||$rows?'ambiguous':'none');
    $pdo->prepare('INSERT INTO phone_operator_call_agendas_v1(call_id,call_public_id,owner_user_id,agent_id,chat_agent_id,conversation_id,contact_id,agenda_revision,agenda_hash,call_binding,source_state,created_epoch) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$call['id'],$public,(int)$call['owner_user_id'],(int)$call['agent_id'],(int)$call['chat_agent_id'],(int)$call['conversation_id'],
            (int)($a['contact_id']??0),(int)($a['revision']??0),$a?vp3_operator_objectives_hash_v1($a):'',vp3_operator_objectives_call_hash_v1($call),$state,$now]);
}
function vp3_operator_objectives_resolve_v1(PDO $pdo,array $call):array
{
    if(!preg_match('/^[a-f0-9]{32}$/D',(string)($call['public_id']??'')))return ['state'=>'not_captured'];
    $ref=vp3_operator_objectives_row_v1($pdo,(int)$call['id']);
    if(!$ref||$ref['source_state']!=='bound')return ['state'=>$ref['source_state']??'not_captured'];
    if(!hash_equals($ref['call_binding'],vp3_operator_objectives_call_hash_v1($call)))return ['state'=>'call_changed'];
    $q=$pdo->prepare("SELECT a.*,c.phone contact_phone FROM phone_operator_agendas_v1 a JOIN crm_contacts c ON c.id=a.contact_id AND c.owner_user_id=a.owner_user_id
        WHERE a.owner_user_id=? AND a.contact_id=? AND a.conversation_id=? AND a.chat_agent_id=? AND c.status<>'archived'");
    $q->execute([(int)$ref['owner_user_id'],(int)$ref['contact_id'],(int)$ref['conversation_id'],(int)$ref['chat_agent_id']]);$a=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$a||!hash_equals($ref['agenda_hash'],vp3_operator_objectives_hash_v1($a)))return ['state'=>'agenda_changed'];
    try{if(vp3_phone_caller_contacts_e164_v1((string)$a['contact_phone'])!==$call['peer_e164'])return ['state'=>'contact_changed'];}
    catch(RuntimeException){return ['state'=>'contact_changed'];}
    return ['state'=>'bound','ref'=>$ref,'agenda'=>$a];
}
function vp3_operator_objectives_key_v1(string $q):string
{
    $q=preg_replace('/[?？\s]+$/u','',trim($q))??'';
    return trim(preg_replace('/\s+/u',' ',mb_strtolower($q))??'');
}
function vp3_operator_objectives_questions_v1(string $text):array
{
    $out=[];
    foreach(preg_split('/\R/u',$text)?:[] as $question){
        $question=trim($question);$key=vp3_operator_objectives_key_v1($question);
        if($key!==''&&!isset($out[$key]))$out[$key]=['key'=>hash('sha256',$key),'question'=>mb_substr($question,0,700)];
        if(count($out)>=12)break;
    }return array_values($out);
}
/** Same canonical analysis/item reader as Transcription, without another inference. Called only after call/report authority. */
function vp3_operator_objectives_analysis_v1(PDO $pdo,array $user,array $job):array
{
    require_once __DIR__.'/transcript-agent-v1.php';$id=(int)$job['session_id'];
    $q=$pdo->prepare('SELECT user_agent_id FROM chat_conversations WHERE id=? AND user_id=?');$q->execute([(int)$job['conversation_id'],(int)$user['id']]);$conversation=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$conversation)throw new RuntimeException('Transcript unavailable.',404);
    $agent=(int)($conversation['user_agent_id']??0);
    $principal=$agent>0?['kind'=>'user_agent','agent_id'=>$agent,'owner_user_id'=>(int)$user['id'],'viewer_user_id'=>(int)$user['id']]:['kind'=>'system','viewer_user_id'=>(int)$user['id']];
    $s=vp3_transcript_session_v1($pdo,$user,$principal,$id);
    if((int)$s['conversation_id']!==(int)$job['conversation_id'])throw new RuntimeException('Transcript unavailable.',404);
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$id));$saved=artist_listening_v237_analysis_status($pdo,$id,$map);$m=$saved['master'];
    $out=['session_id'=>$id,'source_hash'=>$map['source_hash'],'fresh'=>false,'complete'=>false,'page_count'=>(int)$map['page_count'],'qa_items'=>[]];
    if(!$m)return $out;
    $out['analysis_revision']=hash('sha256',json_encode($m['analysis'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $modules=transcription_app_modules_v306($m['analysis'],$m);$status=transcription_deeper_advanced_status_v307($pdo,$user,$s,$m,$map);
    $out['fresh']=!empty($m['fresh'])&&!empty($status['app_status']['qa']['fresh']);
    $out['complete']=$out['page_count']>0&&(int)$saved['analyzed_pages']===$out['page_count']&&(int)$m['analyzed_page_count']===$out['page_count'];
    if(!$out['fresh'])return $out;
    $normalized=transcription_deeper_normalize_modules_v307($modules);
    foreach(['answered','unanswered'] as $section)foreach(array_slice((array)($normalized['qa']['result'][$section]??[]),0,40) as $item){
        if(is_array($item))$out['qa_items'][]=array_replace($item,['section'=>$section]);
    }return $out;
}
function vp3_operator_objectives_progress_v1(array $questions,array $saved,int $session):array
{
    $out=[];$pages=(int)($saved['page_count']??0);
    foreach($questions as $q){
        $matches=[];
        if(!empty($saved['fresh'])&&!empty($saved['complete']))foreach((array)($saved['qa_items']??[]) as $item){
            if(!is_array($item)||!is_string($item['question']??null)||vp3_operator_objectives_key_v1($item['question'])!==vp3_operator_objectives_key_v1($q['question'])
                ||!in_array($item['section']??null,['answered','unanswered'],true)||!in_array($item['review_state']??null,['accepted','unreviewed'],true)
                ||!is_string($item['item_id']??null)||!preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$item['item_id']))continue;
            $refs=array_values(array_filter((array)($item['evidence_refs']??[]),static fn($r):bool=>is_array($r)&&is_int($r['page']??null)&&$r['page']>0&&$r['page']<=$pages));
            if(!$refs||($item['section']==='answered'&&(!is_string($item['answer']??null)||trim($item['answer'])==='')))continue;
            $matches[$item['section'].':'.$item['item_id']]=[$item,$refs[0]['page']];
        }
        $row=$q+['state'=>'unverified','review_state'=>'unreviewed','answer'=>'','source_url'=>''];
        if(count($matches)===1){
            [$item,$page]=array_values($matches)[0];
            $proven=transcription_review_proven_v1($session,(string)($saved['source_hash']??''),'qa',$item['section'],$item);
            $row['state']=$item['section']==='unanswered'?'open':($proven?'answer_reviewed':'answer_suggested');
            $row['review_state']=$proven?'accepted':'unreviewed';$row['answer']=$item['section']==='answered'?mb_substr($item['answer'],0,700):'';
            $path=parse_url(url('/artist-listening-evidence.php'),PHP_URL_PATH);
            if(is_string($path)&&str_starts_with($path,'/')&&!str_starts_with($path,'//'))$row['source_url']=$path.'?'.http_build_query(['session'=>$session,'plugin'=>'qa','section'=>$item['section'],'item'=>$item['item_id'],'page'=>$page],'','&',PHP_QUERY_RFC3986);
        }elseif(count($matches)>1)$row['state']='conflicting';
        $row['revision']=hash('sha256',json_encode([$saved['source_hash']??'',$row],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$out[]=$row;
    }return $out;
}
function vp3_operator_objectives_scope_v1(PDO $pdo,array $scope,array $call):void
{
    if((function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)||(int)$scope['conversation_id']<1
        ||(int)$call['owner_user_id']!==(int)$scope['owner_user_id']||(int)$call['conversation_id']!==(int)$scope['conversation_id']
        ||(int)$call['chat_agent_id']!==(int)$scope['agent_id']||((int)$scope['agent_id']>0&&(int)$call['agent_id']!==(int)$scope['agent_id']))
        vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call agenda is unavailable.');
    if(!has_permission('artist_listening.access',$scope['user']))vp3_phone_workspace_error_v1('phone_call_unavailable',403,'Transcript access is required.');
}
function vp3_operator_objectives_for_job_v1(PDO $pdo,array $scope,array $job,?callable $reader=null):array
{
    $out=['view'=>'call_agenda','call_id'=>(string)$job['call_public_id'],'execution_enabled'=>false,'coverage'=>'unavailable','topics'=>'','questions'=>[],'transcript_url'=>'','agenda_fingerprint'=>''];
    if($job['origin_kind']!=='browser')return $out;
    $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE id=? AND public_id=? AND owner_user_id=?');$q->execute([(int)$job['call_id'],$job['call_public_id'],(int)$scope['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$call)return $out;vp3_operator_objectives_scope_v1($pdo,$scope,$call);
    $q=$pdo->prepare("SELECT COUNT(*) FROM phone_recording_jobs_v1 WHERE owner_user_id=? AND call_id=? AND call_public_id=? AND origin_kind='browser'");
    $q->execute([(int)$scope['owner_user_id'],(int)$call['id'],$call['public_id']]);$jobs=(int)$q->fetchColumn();$q->closeCursor();if($jobs!==1)return $out;
    if($call['state']!=='completed'||(int)$call['recording_enabled']!==1||(int)$call['ended_epoch']<1||(int)$job['owner_user_id']!==(int)$call['owner_user_id']
        ||(int)$job['conversation_id']!==(int)$call['conversation_id']||(int)$job['agent_id']!==(int)$call['agent_id']
        ||(int)$job['phone_number_id']!==(int)$call['phone_number_id']||$job['provider_call_sid']!==$call['provider_call_sid'])return $out;
    $source=vp3_operator_objectives_resolve_v1($pdo,$call);
    if($source['state']!=='bound'){$out['coverage']=$source['state'];return $out;}
    $policy=vp3_phone_recording_decode_v1($job['policy_json']);
    if(empty($policy['enabled'])||empty($policy['transcribe'])||empty($policy['analyze'])||!in_array('qa',(array)($policy['modules']??[]),true)){$out['coverage']='analysis_off';return $out;}
    require_once __DIR__.'/agent-operator-history-v1.php';
    $call['source_kind']='browser';$e=vp3_operator_recording_evidence_v1($pdo,$scope['user'],(int)$scope['agent_id'],$job,$call);
    if($job['state']!=='ready'||!$e||!$e['transcript_saved']||!$e['report_saved']||(int)$job['session_id']<1
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)$job['analysis_hash']))return $out;
    try{$saved=$reader?$reader((int)$job['session_id']):vp3_operator_objectives_analysis_v1($pdo,$scope['user'],$job);}
    catch(Throwable){return $out;}
    // Re-resolve after reading: agenda edits, removed delivery or reassigned scope cannot release cached findings.
    $freshUser=vp3_phone_recording_owner_v1($pdo,(int)$scope['owner_user_id']);
    $freshScope=vp3_phone_workspace_scope_v1($pdo,$freshUser,['agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id']]);
    $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE id=? AND public_id=? AND owner_user_id=?');$q->execute([(int)$call['id'],$call['public_id'],(int)$scope['owner_user_id']]);$freshCall=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$freshCall||$freshCall['state']!=='completed'||(int)$freshCall['recording_enabled']!==1||(int)$freshCall['ended_epoch']<1)return $out;
    $freshCall['source_kind']='browser';vp3_operator_objectives_scope_v1($pdo,$freshScope,$freshCall);
    $fresh=vp3_operator_objectives_resolve_v1($pdo,$freshCall);
    $again=vp3_phone_recording_row_v1($pdo,(int)$job['id']);
    if($fresh['state']!=='bound'||$fresh['ref']['agenda_hash']!==$source['ref']['agenda_hash']){$out['coverage']='agenda_changed';return $out;}
    $againEvidence=$again?vp3_operator_recording_evidence_v1($pdo,$freshScope['user'],(int)$scope['agent_id'],$again,$freshCall):null;
    if(!$again||$again['state']!=='ready'||$again['session_id']!=$job['session_id']||$again['transcript_hash']!==$job['transcript_hash']
        ||$again['policy_json']!==$job['policy_json']||$again['analysis_hash']!==$job['analysis_hash']
        ||$again['report_message_id']!=$job['report_message_id']||empty($againEvidence['report_saved'])||empty($againEvidence['transcript_saved']))return $out;
    if(!is_array($saved)||($saved['session_id']??null)!==(int)$job['session_id']||($saved['source_hash']??null)!==$job['transcript_hash']||($saved['fresh']??null)!==true){$out['coverage']='stale';return $out;}
    if(array_key_exists('analysis_revision',$saved)){
        $q=$pdo->prepare('SELECT analysis_json,source_hash FROM artist_transcript_master_analysis_v237 WHERE session_id=?');$q->execute([(int)$job['session_id']]);$master=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $revision=$master?hash('sha256',json_encode(vp3_phone_recording_decode_v1($master['analysis_json']),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)):'';
        if(!$master||$master['source_hash']!==$job['transcript_hash']||$revision!==$saved['analysis_revision']){$out['coverage']='stale';return $out;}
    }
    $out['topics']=(string)$source['agenda']['topics'];$out['agenda_fingerprint']=$source['ref']['agenda_hash'];
    $out['questions']=vp3_operator_objectives_progress_v1(vp3_operator_objectives_questions_v1((string)$source['agenda']['questions']),$saved,(int)$job['session_id']);
    $out['transcript_url']=url('/artist-listening.php?session='.(int)$job['session_id']);$out['coverage']=!empty($saved['complete'])?'ready':'partial';
    return $out;
}
function vp3_operator_objectives_review_v1(PDO $pdo,array $scope,string $public,int $now,?callable $reader=null):array
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$public))vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call agenda is unavailable.');
    $q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=? AND owner_user_id=?');$q->execute([$public,(int)$scope['owner_user_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$call)vp3_phone_workspace_error_v1('phone_call_unavailable',404,'This call agenda is unavailable.');vp3_operator_objectives_scope_v1($pdo,$scope,$call);
    $out=['view'=>'call_agenda','call_id'=>$public,'execution_enabled'=>false,'coverage'=>'no_report','topics'=>'','questions'=>[],'transcript_url'=>'','continuity'=>['state'=>'permission_required','questions'=>[]]];
    if(!vp3_operator_objectives_ready_v1($pdo)){$out['coverage']='upgrade_required';return $out;}
    if(!vp3_phone_recording_schema_ready_v1($pdo))return $out;
    $q=$pdo->prepare("SELECT * FROM phone_recording_jobs_v1 WHERE owner_user_id=? AND call_id=? AND call_public_id=? AND origin_kind='browser' ORDER BY id DESC LIMIT 2");
    $q->execute([(int)$scope['owner_user_id'],(int)$call['id'],$public]);$jobs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    if(count($jobs)!==1)return $out;
    $out=array_replace($out,vp3_operator_objectives_for_job_v1($pdo,$scope,$jobs[0],$reader));
    if($out['coverage']!=='ready')return $out;
    $source=vp3_operator_objectives_resolve_v1($pdo,$call);
    if($source['state']!=='bound')return array_replace($out,['coverage'=>'agenda_changed','topics'=>'','questions'=>[]]);
    $out['continuity']=vp3_operator_objectives_prior_v1($pdo,$scope,$call,$source['ref'],$now,$reader,$out['questions']);
    // Prior-call reads may take time. Recheck the current source and authority last as well.
    $out=array_replace($out,vp3_operator_objectives_for_job_v1($pdo,$scope,$jobs[0],$reader));
    if($out['coverage']!=='ready')$out['continuity']=['state'=>'permission_required','questions'=>[]];
    else{
        $answered=[];foreach($out['questions'] as $question)if($question['state']==='answer_reviewed')$answered[$question['key']]=true;
        $out['continuity']['questions']=array_values(array_filter($out['continuity']['questions'],static fn($q):bool=>!isset($answered[$q['key']])));
    }
    return $out;
}
/** A current, matching connected recipient grants cross-call memory independently. */
function vp3_operator_objectives_memory_v1(PDO $pdo,array $call,array $ref,int $now):string
{
    try{
        $q=$pdo->prepare("SELECT p.profile_id,p.revision,p.phone_revision,d.id device_id FROM crm_contacts c
            JOIN vp3_phone_devices_v1 d ON d.owner_user_id=c.owner_user_id AND d.id=c.connected_phone_id
            JOIN vp3_phone_communication_profiles_v1 p ON p.owner_user_id=d.owner_user_id AND p.device_id=d.id
            WHERE c.owner_user_id=? AND c.id=? AND c.status<>'archived' AND d.device_status='approved' AND d.expires_at>?
              AND p.phone_e164=? AND p.phone_revision>0 AND p.owner_calls=1 AND p.holder_calls=1 AND p.holder_memory=1");
        $q->execute([(int)$call['owner_user_id'],(int)$ref['contact_id'],$now,$call['peer_e164']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        return $row?hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)):'';
    }catch(PDOException){return '';}
}
function vp3_operator_objectives_prior_v1(PDO $pdo,array $scope,array $call,array $ref,int $now,?callable $reader=null,array $currentQuestions=[]):array
{
    $empty=['state'=>'permission_required','questions'=>[]];$token=vp3_operator_objectives_memory_v1($pdo,$call,$ref,$now);if($token==='')return $empty;
    $q=$pdo->prepare("SELECT j.* FROM phone_operator_call_agendas_v1 r JOIN phone_browser_calls_v1 c ON c.id=r.call_id AND c.public_id=r.call_public_id
        JOIN phone_recording_jobs_v1 j ON j.call_id=c.id AND j.call_public_id=c.public_id AND j.owner_user_id=c.owner_user_id AND j.origin_kind='browser'
        WHERE r.owner_user_id=? AND r.contact_id=? AND r.agenda_hash=? AND r.agent_id=? AND r.chat_agent_id=? AND r.conversation_id=?
          AND (r.created_epoch<? OR (r.created_epoch=? AND r.call_id<?)) AND r.created_epoch>=? AND c.state='completed' AND c.ended_epoch IS NOT NULL AND j.state='ready'
        ORDER BY r.created_epoch DESC,r.call_id DESC,j.id DESC LIMIT 6");
    $q->execute([(int)$ref['owner_user_id'],(int)$ref['contact_id'],$ref['agenda_hash'],(int)$ref['agent_id'],(int)$ref['chat_agent_id'],(int)$ref['conversation_id'],
        min($now,(int)$ref['created_epoch']),min($now,(int)$ref['created_epoch']),(int)$ref['call_id'],$now-90*86400]);$jobs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$seen=[];$out=[];$calls=0;
    foreach($currentQuestions as $question)if(($question['state']??'')==='answer_reviewed')$seen[$question['key']]=true;
    foreach($jobs as $job){
        if($calls>=3)break;
        $review=vp3_operator_objectives_for_job_v1($pdo,$scope,$job,$reader);if($review['coverage']!=='ready')continue;
        $calls++;
        foreach($review['questions'] as $question){
            if(isset($seen[$question['key']])||$question['state']==='unverified')continue;$seen[$question['key']]=true;
            if($question['state']==='answer_reviewed')continue;
            $out[]=['key'=>$question['key'],'question'=>$question['question'],'state'=>$question['state'],
                'source_url'=>$question['source_url']?:$review['transcript_url'],'call_id'=>$job['call_public_id']];
        }
    }
    if(!hash_equals($token,vp3_operator_objectives_memory_v1($pdo,$call,$ref,$now)))return $empty;
    return ['state'=>'available','questions'=>array_slice($out,0,12)];
}
