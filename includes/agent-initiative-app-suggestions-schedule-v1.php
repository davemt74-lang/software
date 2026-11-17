<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-followthrough-v1.php';
require_once __DIR__.'/agent-initiative-canvas-v1.php';
require_once __DIR__.'/agent-initiative-email-canvas-v1.php';
require_once __DIR__.'/agent-initiative-curiosity-v1.php';
require_once __DIR__.'/agent-initiative-tools-v1.php';
require_once __DIR__.'/agent-initiative-runtime-v1.php';

/** A confirmed goal can be useful context without itself calling for an app. */
function vp3_initiative_curiosity_app_opportunity_v1(array $source): bool
{
    if(($source['kind']??null)!=='curiosity_finding'||!is_string($source['detail']??null))return false;
    return preg_match('/\AThe owner confirmed this in a Curiosity reply\. (?:i (?:want|need|would like|plan|intend) to |my goal is (?:to )?|please )?(?:build|make|create|design) (?:an? |my own )(?:[\p{L}-]+ ){0,3}(?:canvas(?: app)?|app|dashboard|tracker|calculator|interactive checklist)\b/iu',
        $source['detail'])===1;
}

/** The shared assessment may have proposed the same primary using extra supporting sources. */
function vp3_initiative_canvas_proposal_exists_v1(PDO $pdo,int $owner,array $binding,string $ref,string $revision): bool
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $primary=$json('$.primary');$tool=$json('$.tool');
    $query=$pdo->prepare("SELECT r.id,r.goal,r.status,a.result_json,p.result_json AS receipt_json FROM agent_workflow_runs r
        LEFT JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
            AND a.status='completed' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
        LEFT JOIN agent_workflow_receipts p ON p.run_id=r.id AND p.owner_user_id=r.owner_user_id AND p.action_id=a.id
            AND p.attempt_no=a.attempt_count AND p.status='completed' AND p.executor='cloud'
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
            AND r.status IN ('approved','queued','executing','paused','completed')
            AND $primary=? AND $tool='canvas.prepare' ORDER BY r.id DESC");
    $agent=(string)vp3_initiative_binding_agent_v1($binding);
    $query->execute([$owner,$agent,$ref]);
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true);
        if(($goal['sources'][$ref]??null)!==$revision)continue;
        if($row['status']!=='completed'){
            if(($goal['binding']??null)===$binding)return true;
            continue;
        }
        if(!is_string($row['receipt_json'])||$row['receipt_json']!==$row['result_json'])continue;
        $result=json_decode($row['receipt_json'],true)['initiative_tool_result']??[];
        if(!is_array($result))continue;
        $output=vp3_initiative_canvas_tool_result_v1($result)['output']??[];
        if(($output['parent_run_id']??null)!==(int)$row['id']
            ||($output['source_ref']??null)!==$ref||($output['source_revision']??null)!==$revision)continue;
        // An unaccepted proposal stops being actionable when its policy changes.
        // A previously admitted draft, including after an explicit reply, keeps its identity.
        if(($goal['binding']??null)===$binding)return true;
        if($output['status']!=='draft_queued')continue;
        $child=$pdo->prepare("SELECT status FROM agent_workflow_runs WHERE owner_user_id=? AND id=?
            AND CAST(COALESCE(agent_id,0) AS CHAR)=? AND source_kind='coding_canvas' AND capability_key='coding.build'");
        $child->execute([$owner,$output['coding_run_id'],$agent]);
        if(in_array($child->fetchColumn(),['approved','queued','executing','paused','completed'],true))return true;
    }
    return false;
}

/** Recheck this slot under the owner lock before admitting another assessment or proposal. */
function vp3_initiative_canvas_discovery_pending_v1(PDO $pdo,int $owner,array $binding): bool
{
    return vp3_initiative_runtime_runs_v1($pdo,$owner,vp3_initiative_binding_agent_v1($binding),$binding,
        pending:true,opportunityLane:'app_suggestions')!==[];
}

/** Reuse the two workflows whose complete Canvas use is already known. */
function vp3_initiative_schedule_direct_canvas_v1(PDO $pdo,array $user,array $binding): string
{
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$binding): string{
        vp3_initiative_owner_lock_v1($pdo,$user);
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        $owner=(int)$live['id'];$agent=vp3_initiative_binding_agent_v1($binding);
        if(vp3_initiative_canvas_discovery_pending_v1($pdo,$owner,$binding))return 'already_handled';
        $mail=[];$mailFailed=false;
        try{$mail=vp3_initiative_email_canvas_evidence_v1($pdo,$live,$binding);}
        catch(RuntimeException|JsonException $error){
            $mailFailed=true;
            if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('initiative.canvas_email_discovery_failed',
                ['user_id'=>$owner,'error_class'=>get_class($error)]);
        }
        $scope=vp3_initiative_tools_scope_v1($pdo,$live,$binding);$coverage=[];
        $goals=array_filter(vp3_initiative_curiosity_evidence_v1($pdo,$scope,null,$coverage),'vp3_initiative_curiosity_app_opportunity_v1');
        $candidates=$mail+$goals;
        if(!$candidates)return $mailFailed||($coverage['curiosity']??null)==='unavailable'?'failed':'no_opportunity';
        $feedback=vp3_initiative_canvas_feedback_v1($pdo,$live,$agent,$candidates);
        foreach($candidates as $ref=>$source){
            if(($feedback[$ref]['disposition']??null)==='suppress')continue;
            $revision=$source['revision'];$sources=[$ref=>$revision];
            $email=isset($mail[$ref]);
            if($email){
                $hash=vp3_initiative_email_canvas_attempt_key_v1($pdo,$owner,$binding,$ref);
                if($hash===null)continue;
            }else{
                if(vp3_initiative_canvas_proposal_exists_v1($pdo,$owner,$binding,$ref,$revision))continue;
                $hash=hash('sha256',json_encode([$binding,'canvas.prepare',$ref,$sources],JSON_THROW_ON_ERROR));
            }
            if(!vp3_initiative_canvas_readiness_v1($pdo,$live,$binding)['ready'])return 'not_ready';
            $work=['binding'=>$binding,'sources'=>$sources,'canonical_sources'=>$sources,'capability_sources'=>[],
                'tool'=>'canvas.prepare','primary'=>$ref,'opportunity_lane'=>'app_suggestions','report'=>true,
                'focus'=>$email?'Propose a private board for the verified recurring email deadlines.'
                    :'Propose one small private app for the exact workflow the owner confirmed in Curiosity.'];
            if($email)$work['email_canvas_work_key']=vp3_initiative_email_canvas_work_key_v1($ref);
            return vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',$work,$hash)?'queued':'already_handled';
        }
        return 'already_handled';
    });
}

/** Identify unresolved, source-backed work worth assessing, without deciding that it needs an app. */
function vp3_initiative_canvas_discovery_family_v1(array $source): ?string
{
    if(vp3_initiative_canvas_media_labels_v1([$source])!==[])return 'media';
    if(($source['kind']??null)==='specialist_finding'){
        $specialist=$source['specialist']??null;
        if(!is_string($specialist)||$specialist===''||!in_array($source['status']??null,
            ['ready','open','pending','confirmed','rescheduled'],true)||!empty($source['private_summary_only'])
            ||!in_array($source['routing']??null,['ask','report'],true))return null;
        return ($source['priority']??null)==='high'||!empty($source['next_step'])||!empty($source['question'])?$specialist:null;
    }
    if(($source['kind']??null)==='specialist_result'
        &&in_array($source['artifact']['artifact_type']??null,vp3_initiative_result_inputs_v1('canvas.prepare'),true))return 'research';
    return null;
}

/** Limit each specialist's share of the decision. Existing Canvas metadata is supporting context only. */
function vp3_initiative_canvas_discovery_sources_v1(PDO $pdo,array $user,array $binding,array $evidence): array
{
    $candidates=[];$families=[];
    uasort($evidence,static fn(array $a,array $b):int=>(($a['priority']??null)!=='high')<=>(($b['priority']??null)!=='high'));
    $eligible=array_filter($evidence,static fn(array $source):bool=>vp3_initiative_canvas_discovery_family_v1($source)!==null);
    if(!$eligible)return [];
    $owner=(int)$user['id'];$agent=vp3_initiative_binding_agent_v1($binding);
    $feedback=vp3_initiative_canvas_feedback_v1($pdo,$user,$agent,$eligible);
    foreach($eligible as $ref=>$source){
        $family=vp3_initiative_canvas_discovery_family_v1($source);
        if(($families[$family]??0)>=2||($feedback[$ref]['disposition']??null)==='suppress'
            ||vp3_initiative_canvas_proposal_exists_v1($pdo,$owner,$binding,$ref,$source['revision']))continue;
        $candidates[$ref]=$source;
        $families[$family]=($families[$family]??0)+1;
        if(count($candidates)>=8)break;
    }
    if(!$candidates)return ['candidates'=>[]];
    $inventory=array_filter($evidence,static fn(array $row):bool=>($row['kind']??null)==='canvas_work');
    $context=array_slice($inventory,0,2,true);
    return ['candidates'=>array_keys($candidates),'evidence'=>$candidates+$context];
}

/** Discover broadly, then persist only bounded exact source references for the dedicated assessment. */
function vp3_initiative_schedule_app_suggestions_v1(PDO $pdo,array $user,array $binding): string
{
    if($pdo->inTransaction()||($binding['enabled']??false)!==true||vp3_participant_scope_v1()!==null)return 'blocked';
    $agent=vp3_initiative_binding_agent_v1($binding);
    return vp3_initiative_in_scope_v1($user,$agent,static function()use($pdo,$user,$binding,$agent):string{
        $live=vp3_initiative_access_v1($pdo,$user,$binding);$owner=(int)$live['id'];
        if(vp3_initiative_canvas_discovery_pending_v1($pdo,$owner,$binding))return 'already_handled';
        $direct=vp3_initiative_schedule_direct_canvas_v1($pdo,$live,$binding);
        if(in_array($direct,['queued','not_ready'],true))return $direct;
        if(vp3_initiative_canvas_discovery_pending_v1($pdo,$owner,$binding))return 'already_handled';
        if(!vp3_initiative_canvas_readiness_v1($pdo,$live,$binding)['ready'])return 'not_ready';

        $coverage=[];$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $available=[];
        try{$available+=vp3_initiative_specialist_evidence_v1($pdo,$live,$binding,$now,null,$coverage,perSpecialist:4);}
        catch(RuntimeException $error){$coverage['specialist_reports']=$error->getCode()===403?'forbidden':'unavailable';}
        try{$available+=vp3_initiative_result_evidence_v1($pdo,$live,$binding,$now,null,$coverage);}
        catch(RuntimeException $error){$coverage['specialist_results']=$error->getCode()===403?'forbidden':'unavailable';}
        require_once __DIR__.'/agent-initiative-opportunity-sources-v1.php';
        try{
            $principal=vp3_initiative_principal_v1($pdo,$live,$agent);
            [, $inventory]=vp3_initiative_opportunity_canvases_v1($pdo,$live,$principal,null);
            $available+=$inventory;
        }catch(RuntimeException $error){}
        require_once __DIR__.'/agent-media-youtube-play-analysis-v1.php';
        require_once __DIR__.'/agent-initiative-native-music-v1.php';
        try{$available+=vp3_initiative_media_play_evidence_v1($pdo,$live,$binding,$now,null);}
        catch(RuntimeException $error){$coverage['media']=$error->getCode()===403?'forbidden':'unavailable';}
        try{
            $scope=vp3_initiative_tools_scope_v1($pdo,$live,$binding);
            $native=vp3_initiative_native_music_evidence_v1($pdo,$scope,$now,null,$coverage);
            $available+=array_filter($native,static fn(array $row):bool=>vp3_initiative_canvas_media_allowed_v1($pdo,$owner,$row));
        }catch(RuntimeException $error){$coverage['native_artist_research']=$error->getCode()===403?'forbidden':'unavailable';}
        $discovery=vp3_initiative_canvas_discovery_sources_v1($pdo,$live,$binding,$available);
        if(!$discovery)return in_array('unavailable',array_intersect_key($coverage,
            ['specialist_reports'=>true,'specialist_results'=>true,'media'=>true]),true)?'failed':$direct;
        if(!$discovery['candidates'])return 'already_handled';
        $evidence=$discovery['evidence'];ksort($evidence);
        $sources=array_column($evidence,'revision','ref');
        $canonical=array_map(static fn(array $row):string=>$row['canonical_source_revision']??$row['revision'],$evidence);
        $capabilitySources=vp3_initiative_capability_source_map_v1($evidence,array_keys($sources));
        $work=['binding'=>$binding,'sources'=>$sources,'canonical_sources'=>$canonical,'capability_sources'=>$capabilitySources,
            'opportunity_lane'=>'app_suggestions','canvas_candidate_sources'=>$discovery['candidates']];
        // The report verifies exactly this snapshot, including any app versions used as context.
        $hash=hash('sha256',json_encode([$binding,'canvas-discovery',$sources],JSON_THROW_ON_ERROR));
        return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$live,$binding,$owner,$work,$hash):string{
            vp3_initiative_owner_lock_v1($pdo,$live);
            $current=vp3_initiative_access_v1($pdo,$live,$binding);
            if(vp3_initiative_canvas_discovery_pending_v1($pdo,$owner,$binding))return 'already_handled';
            vp3_initiative_work_sources_v1($pdo,$current,$binding,$work);
            return vp3_initiative_enqueue_v1($pdo,$current,'initiative.plan',$work,$hash)?'queued':'already_handled';
        });
    });
}
