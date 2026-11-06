<?php
declare(strict_types=1);

require_once __DIR__.'/transcript-agent-v1.php';

const VP3_INITIATIVE_TRANSCRIPTS_V1='vp3.initiative-transcripts.v1';

/** Selection never grants access: reconstruct the current canonical Initiative scope. */
function vp3_initiative_transcript_capabilities_scope_v1(PDO $pdo,array $scope,array $binding): array
{
    if(!function_exists('vp3_initiative_capability_scope_v1'))require_once __DIR__.'/agent-initiative-capabilities-v1.php';
    $current=vp3_initiative_capability_scope_v1($pdo,$scope,$binding);
    vp3_transcript_scope_v1($current['user'],$current['principal']);
    return $current;
}

function vp3_initiative_transcript_capabilities_id_v1(string $ref): int
{
    if(!preg_match('/\Atranscript:([1-9][0-9]{0,17})\z/D',$ref,$match)
        ||(string)(int)$match[1]!==$match[1])throw new InvalidArgumentException('Invalid transcript source.');
    return (int)$match[1];
}

/** JSON-byte bounds also cover multibyte text when the common receipt uses escaped JSON. */
function vp3_initiative_transcript_capabilities_text_v1(string $text,int $characters,int $jsonBytes): string
{
    $text=mb_substr($text,0,$characters);
    if(strlen(json_encode($text,JSON_THROW_ON_ERROR))<=$jsonBytes)return $text;
    $low=0;$high=mb_strlen($text);
    while($low<$high){
        $middle=(int)ceil(($low+$high)/2);
        if(strlen(json_encode(mb_substr($text,0,$middle),JSON_THROW_ON_ERROR))<=$jsonBytes)$low=$middle;
        else $high=$middle-1;
    }
    return mb_substr($text,0,$low);
}

/** Fingerprint stored compute policy without the legacy preference reader's provisioning. */
function vp3_initiative_transcript_capabilities_preference_v1(PDO $pdo,int $owner,int $agent): array
{
    if(!function_exists('vp3_agent_runtime_preference_readonly_v420'))require_once __DIR__.'/agent-runtime-routing-v420.php';
    return vp3_agent_runtime_preference_readonly_v420($pdo,$owner,$agent);
}

/** Remove advertisement bookkeeping, retaining policy expirations and decisions. */
function vp3_initiative_transcript_capabilities_authority_value_v1(mixed $value): mixed
{
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('vp3_initiative_transcript_capabilities_authority_value_v1',$value);
    $result=[];
    foreach($value as $key=>$item){
        if(in_array($key,['last_seen_at','last_checked_at','updated_at','reported_at','advertised_at','observed_at',
            'generated_at','heartbeat_at','received_at','last_error','error'],true))continue;
        $result[$key]=vp3_initiative_transcript_capabilities_authority_value_v1($item);
    }
    ksort($result,SORT_STRING);return $result;
}

/** Authorization changes invalidate a proof; heartbeat and display metadata do not. */
function vp3_initiative_transcript_capabilities_authority_v1(PDO $pdo,int $owner): string
{
    if(!function_exists('homeserver_vp3_connection'))require_once __DIR__.'/homeserver-vp3.php';
    $row=homeserver_vp3_connection($owner,true,$pdo);$connection=null;$capabilities=null;
    if($row!==null){
        $connection=[];
        foreach(['user_id','status','device_id','transport','relay_url','relay_endpoint','home_url','base_url','endpoint_url'] as $key){
            if(array_key_exists($key,$row))$connection[$key]=$row[$key];
        }
        foreach(['relay_token_enc','homeserver_token_enc','pending_claim_token_enc','pending_code'] as $key){
            if(array_key_exists($key,$row))$connection[$key.'_digest']=hash('sha256',(string)($row[$key]??''));
        }
        if(isset($row['pending_request_id']))$connection['pending_request_id']=$row['pending_request_id'];
        if(!(($row['status']??null)==='unpaired'&&empty($row['homeserver_token_enc'])&&empty($row['relay_token_enc']))){
            if(!function_exists('homeserver_capability_v024_raw'))require_once __DIR__.'/homeserver-capabilities-v024.php';
            $raw=homeserver_capability_v024_raw($owner,true,$pdo);$capabilities=[];
            foreach(['features','permissions','operations','allowed_operations','app_scope','scope','app','access',
                'access_profile','policies','gateway','plugins','plugin_keys','tool_names','knowledge_kinds','memory_key_prefixes'] as $key){
                if(!array_key_exists($key,$raw))continue;
                $value=$raw[$key];
                if(is_array($value)&&array_is_list($value)&&!array_filter($value,static fn($item):bool=>!is_string($item))){
                    $value=array_values(array_unique($value));sort($value,SORT_STRING);
                }
                $capabilities[$key]=vp3_initiative_transcript_capabilities_authority_value_v1($value);
            }
        }
    }
    $https=null;
    try{
        $q=$pdo->prepare('SELECT * FROM homeserver_https_sessions WHERE user_id=? LIMIT 1');$q->execute([$owner]);
        $session=$q->fetch(PDO::FETCH_ASSOC);
        if($session){
            $https=[];
            foreach(['user_id','status','device_id'] as $key)if(array_key_exists($key,$session))$https[$key]=$session[$key];
            if(array_key_exists('session_token_hash',$session))$https['session_token_digest']=hash('sha256',(string)$session['session_token_hash']);
        }
    }catch(PDOException $error){
        $info=$error->errorInfo??[];$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $missing=$driver==='mysql'&&($info[0]??null)==='42S02'&&(int)($info[1]??0)===1146;
        if($driver==='sqlite'&&(int)($info[1]??0)===1&&($info[2]??'')==='no such table: homeserver_https_sessions')$missing=true;
        if(!$missing)throw new RuntimeException('HomeServer authorization metadata is unavailable.',503,$error);
    }
    // These digests remain only in the request-local cache key; neither encoded
    // credentials nor their individual digests become model/receipt content.
    return hash('sha256',json_encode([$connection,$capabilities,$https],JSON_THROW_ON_ERROR));
}

/** Existing meeting processing policy is separate from permission to display a transcript. */
function vp3_initiative_transcript_capabilities_cloud_v1(PDO $pdo,array $scope,array $session): void
{
    // This memory belongs to the current PHP request; it is neither a session
    // permission nor a durable grant. Every outside-transaction check replaces
    // it only after the canonical live policy has succeeded.
    static $proofs=[];
    $owner=(int)$scope['user']['id'];
    $prefix=$owner.'|'.$scope['agent_id'].'|'.$scope['conversation_id'].'|'.(int)$session['id'].'|';
    $clear=static function()use(&$proofs,$prefix):void{
        foreach(array_keys($proofs) as $key)if(str_starts_with($key,$prefix))unset($proofs[$key]);
    };
    if(!$pdo->inTransaction())$clear();
    try{
        $scope=video_meeting_processing_scope_readonly_v1801($pdo,$scope['user'],$scope);
        $session=vp3_transcript_session_v1($pdo,$scope['user'],$scope['principal'],(int)$session['id']);
        $meeting=video_meeting_transcription_readonly_binding_v1801($pdo,$scope['user'],$session);
        if($meeting===null){
            video_meeting_transcription_assert_cloud_ai_v1801($pdo,$scope['user'],$session,true,$scope);
            return;
        }
        $preference=vp3_initiative_transcript_capabilities_preference_v1($pdo,$owner,max(0,(int)($meeting['organizer_agent_id']??0)));
        $key=$prefix.hash('sha256',json_encode([$session,$meeting,$preference,
            vp3_initiative_transcript_capabilities_authority_v1($pdo,$owner)],JSON_THROW_ON_ERROR));
        $clock=hrtime(true);
        foreach($proofs as $savedKey=>$expires)if($expires<=$clock)unset($proofs[$savedKey]);
        if($pdo->inTransaction()){
            // The legacy policy can provision compute storage and automatic
            // routing can contact a paired server. Neither belongs inside a
            // source/receipt transaction, even for an explicit Cloud setting.
            if(($proofs[$key]??0)<=$clock)throw new RuntimeException('A fresh meeting processing proof is required.',503);
            return;
        }
        video_meeting_transcription_assert_cloud_ai_v1801($pdo,$scope['user'],$session,true,$scope);
        if(count($proofs)>=20)array_shift($proofs);
        $proofs[$key]=hrtime(true)+15_000_000_000;
    }catch(Throwable $error){
        $clear();
        if($error instanceof RuntimeException&&in_array($error->getCode(),[404,409,503],true))throw $error;
        if($error instanceof RuntimeException&&$error->getCode()===403){
            throw new RuntimeException('This transcript is unavailable for Cloud preparation.',403,$error);
        }
        throw new RuntimeException('Transcript processing policy is temporarily unavailable.',503,$error);
    }
}

/** Only content used by this read is semantic; audio-library labels are not new work. */
function vp3_initiative_transcript_capabilities_semantic_session_v1(PDO $pdo,array $scope,array $session): array
{
    $meeting=video_meeting_transcription_readonly_binding_v1801($pdo,$scope['user'],$session);
    return ['id'=>(int)$session['id'],'created_by_user_id'=>(int)$session['created_by_user_id'],
        'conversation_id'=>$session['conversation_id']===null?null:(int)$session['conversation_id'],
        'title'=>(string)$session['title'],'status'=>(string)$session['status'],
        'origin'=>$meeting===null?['kind'=>'recording']:['kind'=>'video_meeting','meeting_id'=>(int)$meeting['id'],
            'public_id'=>(string)$meeting['public_id']]];
}

/** Full source/analysis hashes bind edits outside the bounded passages returned to the model. */
function vp3_initiative_transcript_capabilities_snapshot_v1(PDO $pdo,array $scope,int $id): array
{
    $session=vp3_transcript_session_v1($pdo,$scope['user'],$scope['principal'],$id);
    vp3_initiative_transcript_capabilities_cloud_v1($pdo,$scope,$session);
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$id));
    $analysis=['generated'=>false,'fresh'=>false,'text'=>'','review_counts'=>[]];
    $saved=[];$actions=[];$state='unavailable';
    if(artist_listening_v237_schema_ready()){
        $analysis=vp3_transcript_intelligence_v1($pdo,$scope['user'],$scope['principal'],$id,'analysis');
        $saved=artist_listening_v237_analysis_status($pdo,$id,$map);
        if(($analysis['source_hash']??null)!==$map['source_hash'])throw new RuntimeException('Transcript changed during source inspection.',409);
        $state=empty($analysis['generated'])?'missing':(empty($analysis['fresh'])?'stale':'fresh');
        if($state==='fresh'){
            $reviewed=vp3_transcript_intelligence_v1($pdo,$scope['user'],$scope['principal'],$id,'initiative','actions');
            if(($reviewed['source_hash']??null)!==$map['source_hash'])throw new RuntimeException('Transcript changed during action inspection.',409);
            if(!empty($reviewed['fresh']))$actions=$reviewed['accepted_actions']??[];
        }
    }
    $counts=[];
    foreach(['accepted','unreviewed','rejected'] as $key)$counts[$key]=max(0,(int)($analysis['review_counts'][$key]??0));
    $metadata=['analysis_state'=>$state,'review_counts'=>$counts,'reviewed_actions'=>$actions,
        'page_count'=>(int)$map['page_count'],'source_hash'=>(string)$map['source_hash']];
    $revision=hash('sha256',json_encode([VP3_INITIATIVE_TRANSCRIPTS_V1,(int)$scope['user']['id'],
        $scope['agent_id'],$scope['conversation_id'],vp3_initiative_transcript_capabilities_semantic_session_v1($pdo,$scope,$session),$map['source_hash'],$saved,$metadata,
        (string)($analysis['text']??'')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    return ['session'=>$session,'map'=>$map,'analysis'=>$analysis,'metadata'=>$metadata,'revision'=>$revision];
}

/** Missing/stale analysis and recordings without accepted actions are useful situations too. */
function vp3_initiative_transcript_capabilities_evidence_v1(PDO $pdo,array $scope,array $binding,
    DateTimeImmutable $now,?array $selectedRefs,array &$coverage): array
{
    $scope=vp3_initiative_transcript_capabilities_scope_v1($pdo,$scope,$binding);
    $coverage['transcripts']='ready';$evidence=[];
    if(!table_exists('artist_transcript_sessions_v172')||!table_exists('artist_transcript_segments_v172')){
        $coverage['transcripts']='unavailable';return [];
    }
    $ids=[];
    if($selectedRefs===null){
        $catalog=vp3_transcript_catalog_v1($pdo,$scope['user'],$scope['principal']);
        foreach($catalog['sessions'] as $row){
            $stamp=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)($row['last_activity_at']??''),new DateTimeZone('UTC'));
            if(!$stamp||$stamp->format('Y-m-d H:i:s')!==(string)$row['last_activity_at']
                ||$stamp>$now||$stamp->getTimestamp()<$now->getTimestamp()-7*86400)continue;
            $ids[]=(int)$row['id'];
        }
        if(count($ids)>4||($catalog['next_before']??null)!==null)$coverage['transcripts']='limited';
        $ids=array_slice($ids,0,4);
    }else{
        foreach($selectedRefs as $ref){
            if(!is_string($ref))throw new InvalidArgumentException('Invalid transcript source selection.');
            if(!str_starts_with($ref,'transcript:'))continue;
            $id=vp3_initiative_transcript_capabilities_id_v1($ref);
            if(isset($ids[$id]))throw new InvalidArgumentException('Duplicate transcript source.');
            $ids[$id]=$id;
        }
        if(count($ids)>5)throw new InvalidArgumentException('Too many transcript sources.');
        $ids=array_values($ids);
    }
    foreach($ids as $id){
        try{$snapshot=vp3_initiative_transcript_capabilities_snapshot_v1($pdo,$scope,$id);}
        catch(Throwable $error){
            if($error instanceof RuntimeException&&in_array($error->getCode(),[403,404,409],true)){
                if($error->getCode()===403)$coverage['transcripts']='forbidden';
                elseif($selectedRefs===null&&$coverage['transcripts']!=='forbidden')$coverage['transcripts']='limited';
                // Exact selected absence/change is known, not an incomplete
                // discovery scan. Its missing ref lets the canonical source
                // validator invalidate old work rather than retry forever.
                continue;
            }
            $coverage['transcripts']='unavailable';
            if($error instanceof RuntimeException&&$error->getCode()===503)throw $error;
            throw new RuntimeException('Transcript source is temporarily unavailable.',503,$error);
        }
        $session=$snapshot['session'];$metadata=$snapshot['metadata'];$state=$metadata['analysis_state'];
        $detail=match($state){
            'missing'=>'A saved recording has no generated analysis. Its actual transcript can be read to prepare a private follow-up; new analysis requires an explicit request.',
            'stale'=>'A saved recording changed after its analysis. Read current transcript text; do not reuse stale findings or automatically refresh analysis.',
            'fresh'=>'A saved recording has current analysis and can be read for a source-grounded private follow-up. Interpretations and reviewed decisions remain distinct.',
            default=>'A saved recording can be read, but saved analysis storage is unavailable. Analysis absence and completion are unverified.',
        };
        $ref='transcript:'.$id;
        $evidence[$ref]=['ref'=>$ref,'kind'=>'transcript','title'=>mb_substr((string)$session['title'],0,170),
            'detail'=>$detail,'url'=>url('/artist-listening.php?session='.$id),'revision'=>$snapshot['revision'],
            'analysis_state'=>$state,'review_counts'=>$metadata['review_counts'],'reviewed_actions'=>$metadata['reviewed_actions']];
    }
    return $evidence;
}

/** Bounded canonical passages retain segment/page/time locators and truthful coverage. */
function vp3_initiative_transcript_capabilities_passages_v1(array $map,string $focus): array
{
    $packet=vp3_transcript_evidence_v1($map,'discuss',$focus,0,4000);
    $text='';$covered=0;$segments=0;
    foreach(array_slice($packet['segments'],0,24) as $segment){
        $label='[segment #'.$segment['id'].'; page '.$segment['page'].'; '.$segment['at'].'; '
            .mb_substr((string)$segment['speaker'],0,60).'; offset '.$segment['character_offset']."] ";
        $remaining=4000-mb_strlen($text)-mb_strlen($label)-2;
        if($remaining<1)break;
        $prefix=$text.($text===''?'':"\n\n").$label;
        $budget=6000-strlen(json_encode($prefix,JSON_THROW_ON_ERROR))+2;
        if($budget<3)break;
        $part=vp3_initiative_transcript_capabilities_text_v1((string)$segment['text'],$remaining,$budget);
        if($part==='')break;
        $text=$prefix.$part;$covered+=mb_strlen($part);++$segments;
        if(mb_strlen($part)<mb_strlen((string)$segment['text']))break;
    }
    return ['text'=>$text,'coverage'=>['total_characters'=>(int)$packet['total_characters'],
        'covered_characters'=>$covered,'complete'=>$covered===(int)$packet['total_characters'],
        'selection'=>$packet['selection'],'page_count'=>(int)$packet['page_count'],'segments'=>$segments]];
}

/** Deterministic read only: no interpreter, analysis refresh, highlight or destination action. */
function vp3_initiative_transcript_capabilities_read_v1(PDO $pdo,array $scope,array $binding,
    string $tool,array $job,array $evidence): array
{
    if($tool!=='transcripts.read'||($job['tool']??null)!==$tool||!is_string($job['primary']??null)
        ||!is_array($job['refs']??null)||!array_is_list($job['refs'])||!$job['refs']||count($job['refs'])>5
        ||!is_string($job['focus']??null)||!mb_check_encoding($job['focus'],'UTF-8')||trim($job['focus'])===''||mb_strlen($job['focus'])>300
        ||!in_array($job['primary'],$job['refs'],true))throw new InvalidArgumentException('Invalid transcript read assignment.');
    vp3_initiative_transcript_capabilities_id_v1($job['primary']);
    $scope=vp3_initiative_transcript_capabilities_scope_v1($pdo,$scope,$binding);
    $refs=[];
    foreach($job['refs'] as $ref){
        if(!is_string($ref)||isset($refs[$ref])||!isset($evidence[$ref]))throw new InvalidArgumentException('Invalid transcript read source.');
        $refs[$ref]=true;
    }
    $records=[];$sources=[];$coverage=['state'=>'ready','items'=>[]];
    foreach(array_keys($refs) as $ref){
        if(!str_starts_with($ref,'transcript:'))continue;
        $id=vp3_initiative_transcript_capabilities_id_v1($ref);$expected=$evidence[$ref];
        if(($expected['ref']??null)!==$ref||($expected['kind']??null)!=='transcript'
            ||!is_string($expected['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$expected['revision'])){
            throw new InvalidArgumentException('Invalid transcript source proof.');
        }
        $snapshot=vp3_initiative_transcript_capabilities_snapshot_v1($pdo,$scope,$id);
        if(!hash_equals($expected['revision'],$snapshot['revision']))throw new RuntimeException('Transcript source changed. Reassess before preparing a follow-up.',409);
        $title=vp3_initiative_transcript_capabilities_text_v1((string)$snapshot['session']['title'],170,500);
        $url=url('/artist-listening.php?session='.$id);
        $passages=vp3_initiative_transcript_capabilities_passages_v1($snapshot['map'],$job['focus']);
        $records[]=['source'=>$ref,'title'=>$title,'text'=>$passages['text'],'url'=>$url,
            'data'=>['kind'=>'saved_transcript','coverage'=>$passages['coverage'],'source_hash'=>$snapshot['map']['source_hash']]];
        $sources[]=['source'=>$ref,'title'=>$title,'url'=>$url];
        $analysis=$snapshot['analysis'];$state=$snapshot['metadata']['analysis_state'];
        $analysisText=$state==='fresh'?(string)($analysis['text']??''):'';
        $boundedAnalysis=vp3_initiative_transcript_capabilities_text_v1($analysisText,2000,3000);
        $analysisComplete=$state==='fresh'&&$boundedAnalysis===$analysisText;
        $records[]=['source'=>'transcript-analysis:'.$id,'title'=>$title.' — saved analysis',
            'text'=>$boundedAnalysis,'url'=>$url,
            'data'=>['kind'=>'saved_interpretation','analysis_state'=>$state,'generated'=>$state!=='missing'&&$state!=='unavailable',
                'fresh'=>$state==='fresh','review_counts'=>$snapshot['metadata']['review_counts'],
                'complete'=>$analysisComplete,
                'new_analysis_performed'=>false,'interpretation_is_not_verified_fact'=>true]];
        $coverage['items'][$ref]=['transcript'=>$passages['coverage'],'analysis_state'=>$state,'analysis_complete'=>$analysisComplete];
        if(!$passages['coverage']['complete']||!$analysisComplete)$coverage['state']='limited';
    }
    return ['records'=>$records,'sources'=>$sources,'coverage'=>$coverage];
}
