<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/agent-tool-json-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-initiative-errors-v1.php';
require_once __DIR__.'/agent-initiative-capabilities-v1.php';
require_once __DIR__.'/agent-initiative-brainstorm-v1.php';
require_once __DIR__.'/agent-initiative-eyes-v1.php';
require_once __DIR__.'/agent-initiative-specialist-evidence-v1.php';
require_once __DIR__.'/agent-initiative-result-evidence-v1.php';

/** Compatibility: callers that only dispatch work retain the original jobs array. */
function vp3_initiative_plan_v1(string $answer,array $evidence,array $allowedTools=['work.prepare_private']): array
{
    return vp3_initiative_assessment_v1($answer,$evidence,$allowedTools)['jobs'];
}

/** Normalize the bounded assessment without granting execution to its ideas. */
function vp3_initiative_assessment_v1(string $answer,array $evidence,array $allowedTools=['work.prepare_private'],array $tools=[],array $reports=[]): array
{
    $limits=vp3_initiative_limits_v1();
    if(strlen($answer)>$limits['max_plan_bytes'])throw new Vp3InitiativePlanValidationV1('output_too_large','Initiative plan too large.');
    $answer=trim($answer);
    // Match the canonical tool JSON reader: a whole-response formatting fence
    // is not schema content. Prose outside the object remains invalid.
    if(preg_match('/\A```(?:json)?\s*(\{.*\})\s*```\z/isD',$answer,$match))$answer=trim($match[1]);
    $plan=json_decode($answer,false,16,JSON_THROW_ON_ERROR);
    if(!$plan instanceof stdClass||!property_exists($plan,'jobs')
        ||array_diff(array_keys((array)$plan),['jobs','opportunities','finding']))throw new Vp3InitiativePlanValidationV1('invalid_envelope','Invalid initiative plan.');
    $jobs=vp3_initiative_jobs_v1(json_encode(['jobs'=>$plan->jobs],JSON_THROW_ON_ERROR),$evidence,$allowedTools);
    foreach($jobs as $job)vp3_initiative_brainstorm_tool_scope_v1($job,$tools);
    $discarded=0;
    $opportunities=vp3_initiative_brainstorm_opportunities_v1(property_exists($plan,'opportunities')?$plan->opportunities:[],
        $evidence,$allowedTools,$tools,$discarded);
    $assessment=['jobs'=>$jobs,'opportunities'=>$opportunities];
    if($discarded>0)$assessment['opportunities_discarded']=$discarded;
    $finding=$reports?vp3_initiative_brainstorm_text_v1($plan->finding??null,500):null;
    if($finding!==null)$assessment['finding']=['text'=>$finding,'report_keys'=>vp3_initiative_brainstorm_report_keys_v1($reports)];
    return $assessment;
}

/** Accept only private preparation assignments grounded in the supplied snapshot. */
function vp3_initiative_jobs_v1(string $answer,array $evidence,array $allowedTools=['work.prepare_private']): array
{
    $limits=vp3_initiative_limits_v1();
    if(strlen($answer)>$limits['max_plan_bytes'])throw new Vp3InitiativePlanValidationV1('output_too_large','Initiative plan too large.');
    $plan=json_decode($answer,false,16,JSON_THROW_ON_ERROR);
    if(!$plan instanceof stdClass||array_keys((array)$plan)!==['jobs'])throw new Vp3InitiativePlanValidationV1('invalid_envelope','Invalid initiative plan.');
    $jobs=$plan->jobs;
    if(!is_array($jobs)||!array_is_list($jobs)||count($jobs)>$limits['max_jobs'])throw new Vp3InitiativePlanValidationV1('invalid_job','Invalid initiative jobs.');
    $primaries=[];$taskPrimaries=[];$hasCheckIn=false;
    foreach($jobs as &$job){
        $fields=['tool','primary','refs','focus','report'];
        if(!$job instanceof stdClass)throw new Vp3InitiativePlanValidationV1('invalid_job','Invalid initiative assignment.');
        $job=(array)$job;
        if(count($job)!==count($fields)||array_diff(array_keys($job),$fields)
            ||!is_string($job['tool']??null)||!in_array($job['tool'],$allowedTools,true)||!is_string($job['primary']??null)
            ||!is_array($job['refs']??null)||!array_is_list($job['refs'])||count($job['refs'])>$limits['max_refs']
            ||!is_string($job['focus']??null)||!is_bool($job['report']??null)){
            throw new Vp3InitiativePlanValidationV1('invalid_job','Invalid initiative assignment.');
        }
        if($job['tool']==='chat.check_in'){
            if($hasCheckIn||$job['report']!==true)throw new Vp3InitiativePlanValidationV1('invalid_job','Invalid initiative check-in.');
            $hasCheckIn=true;
        }
        $primary=$job['primary'];
        foreach($job['refs'] as $sourceRef){
            if(($evidence[$sourceRef]['kind']??null)==='brainstorm_context'
                &&($job['tool']!=='brainstorm.explore'||$job['report']!==false||$job['refs']!==[$primary])){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','Specialist briefs are private Brainstorming input only.');
            }
        }
        $assignment=$job['tool'].'|'.$primary;$refs=[];
        $primaryKind=$evidence[$primary]['kind']??null;
        if(isset($evidence[$primary]['meeting_prep'])&&($job['tool']!=='calendar.read'||count($job['refs'])!==1)){
            throw new Vp3InitiativePlanValidationV1('invalid_job_sources','Meeting prep requires the exact calendar source.');
        }
        if($job['tool']==='chat.check_in'&&in_array($primaryKind,['artist_interest','topic_interest'],true)){
            throw new Vp3InitiativePlanValidationV1('invalid_job_sources','YouTube check-ins require their dedicated conversation source.');
        }
        $interestRef=$evidence[$primary]['interest_ref']??null;
        if(is_string($interestRef)&&preg_match('/\A(?:artist|topic)-interest:[a-f0-9]{64}\z/D',$interestRef))$assignment='youtube-interest|'.$interestRef;
        if(preg_match('/\A(?:open-task|task|task-research):([1-9][0-9]*)\z/D',$primary,$taskMatch)){
            // Historical aliases may still verify old reports, but cannot
            // become competing new assignments for one canonical task.
            $id=$taskMatch[1];
            if(isset($taskPrimaries[$id]))throw new Vp3InitiativePlanValidationV1('invalid_job_sources','Duplicate canonical task assignment.');
            $taskPrimaries[$id]=$primary;
        }
        foreach($job['refs'] as $ref){
            if(!is_string($ref)||!isset($evidence[$ref])||isset($refs[$ref]))throw new Vp3InitiativePlanValidationV1('invalid_job_sources','Unknown or duplicate initiative source.');
            if(($evidence[$ref]['kind']??null)==='transcript_research'&&($job['tool']!=='topic.research'||$ref!==$primary)){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','Transcript research requires the exact public research adapter.');
            }
            if(($evidence[$ref]['kind']??'')==='youtube_interest_checkin'&&($job['tool']!=='chat.check_in'||$ref!==$primary)){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','A tentative YouTube interest can only ground its own conversational check-in.');
            }
            if(($evidence[$ref]['kind']??'')==='eyes_visit'
                &&($job['tool']!=='chat.check_in'||$ref!==$primary||count($job['refs'])!==1)){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','A current Eyes visit can only ground its exact Chat check-in.');
            }
            if(($evidence[$ref]['kind']??null)==='email_canvas_opportunity'
                &&($job['tool']!=='canvas.prepare'||$ref!==$primary||count($job['refs'])!==1)){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','Email Canvas preparation requires its exact workflow source.');
            }
            if(($evidence[$ref]['kind']??null)==='curiosity_finding'
                &&!in_array($job['tool'],['work.prepare_private','canvas.prepare','image.prepare'],true)){
                throw new Vp3InitiativePlanValidationV1('invalid_job_sources','This Curiosity finding supports only private preparation or creative proposals.');
            }
            $refs[$ref]=true;
        }
        $job['focus']=trim($job['focus']);
        if(!isset($refs[$primary])||isset($primaries[$assignment])){
            throw new Vp3InitiativePlanValidationV1('invalid_job_sources','Invalid initiative assignment scope.');
        }
        if($job['focus']===''||mb_strlen($job['focus'])>300){
            throw new Vp3InitiativePlanValidationV1('invalid_job','Invalid initiative assignment scope.');
        }
        vp3_initiative_result_job_v1($job,$evidence);
        if(in_array($job['tool'],['artist.research','topic.research'],true)){
            $job['refs']=[$primary];
            if(in_array($primaryKind,['task_research','transcript_research'],true))$job['report']=true;
        }
        if($primaryKind==='email_canvas_opportunity')$job['report']=true;
        $job=vp3_initiative_specialist_job_v1($job,$evidence);
        $primaries[$assignment]=true;
    }
    unset($job);
    return $jobs;
}

function vp3_initiative_evidence_v1(PDO $pdo,array $user,DateTimeImmutable $now,bool $includeScreen=false,array $screenSessions=[],?array &$coverage=null,?array $selectedRefs=null,?int $agentId=null,bool $includeBrainstorm=true): array
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$now,$includeScreen,$screenSessions,&$coverage,$selectedRefs,$agentId,$includeBrainstorm):array{
        return vp3_initiative_evidence_scoped_v1($pdo,$user,$now,$includeScreen,$screenSessions,$coverage,$selectedRefs,$agentId,$includeBrainstorm);
    });
}

function vp3_initiative_evidence_scoped_v1(PDO $pdo,array $user,DateTimeImmutable $now,bool $includeScreen,array $screenSessions,?array &$coverage,?array $selectedRefs,int $agentId,bool $includeBrainstorm=true): array
{
    $principal=vp3_initiative_principal_v1($pdo,$user,$agentId);
    $current=vp3_catch_up_candidates_v1($pdo,$user,'all',$agentId,true,$now,true);
    $coverage=$current['coverage']+['transcripts'=>'ready','screen_followup'=>$includeScreen?'ready':'forbidden'];
    $selected=$selectedRefs===null?null:array_fill_keys($selectedRefs,true);
    $kinds=[
        'calendar'=>'calendar','reminders'=>'reminder','tasks'=>'task','missions'=>'mission',
        'profile_calls'=>'profile_call','meeting_prep'=>'meeting_prep','orders'=>'order',
        'campaigns'=>'campaign','rewards'=>'reward',
    ];
    $evidence=[];$counts=[];
    foreach($current['items'] as $item){
        $ref=(string)$item['id'];
        if($selected!==null&&!isset($selected[$ref]))continue;
        $family=$current['source_kinds'][$ref];
        $counts[$family]=($counts[$family]??0)+1;
        if($selected===null&&$counts[$family]>4)continue;
        $evidence[$ref]=['ref'=>$ref,'kind'=>$kinds[$family],'title'=>$item['title'],'detail'=>$item['reason'],
            'url'=>$item['url'],'revision'=>$current['source_revisions'][$ref]];
    }
    if($includeScreen&&$agentId===0){
        require_once __DIR__.'/agent-initiative-screen-v1.php';
        try{$evidence+=vp3_initiative_screen_evidence_v1($pdo,$user,$now,$screenSessions);}
        catch(Throwable $e){
            $coverage['screen_followup']=$e->getCode()===403?'forbidden':'unavailable';
            if($screenSessions)throw $e;
        }
    }
    require_once __DIR__.'/agent-activity-situations-v1.php';
    $evidence=vp3_activity_situations_support_v1($pdo,$user,$evidence,$now,$coverage,$agentId>0?'agent:'.$agentId:'system');
    if($includeScreen&&$agentId>0){
        $coverage['screen_followup']='forbidden';
        if($screenSessions)throw new RuntimeException('Screen follow-up scope is unavailable.',403);
    }
    $binding=vp3_initiative_policy_v1($user,$agentId);
    $readiness=__DIR__.'/agent-initiative-readiness-v1.php';
    if(is_file($readiness)){
        require_once $readiness;
        $evidence+=vp3_initiative_readiness_evidence_v1($pdo,$user,$principal,$now,$coverage,$selectedRefs,$binding);
    }
    $context=__DIR__.'/agent-initiative-context-v1.php';
    if(is_file($context)){
        require_once $context;
        $evidence+=vp3_initiative_context_evidence_v1($pdo,$user,$principal,$now,$coverage,$selectedRefs);
    }
    $opportunities=__DIR__.'/agent-initiative-opportunity-sources-v1.php';
    if(is_file($opportunities)){
        require_once $opportunities;
        $evidence+=vp3_initiative_opportunity_sources_v1($pdo,$user,$principal,$now,$selectedRefs,$coverage);
    }
    $tools=__DIR__.'/agent-initiative-tools-v1.php';
    if(is_file($tools)){
        require_once $tools;
        try{
            if(!empty($binding['enabled'])&&(int)($binding['conversation_id']??0)>0){
                $evidence=array_replace($evidence,vp3_initiative_tools_evidence_v1($pdo,$user,$binding,$selectedRefs,$coverage,$now));
                if($selectedRefs===null||array_filter($selectedRefs,static fn($ref):bool=>is_string($ref)&&str_starts_with($ref,'email-canvas:'))){
                    require_once __DIR__.'/agent-initiative-email-canvas-v1.php';
                    try{
                        $email=vp3_initiative_email_canvas_evidence_v1($pdo,$user,$binding,$selectedRefs);
                        if($selectedRefs===null&&$email){
                            foreach(array_keys($email) as $ref){
                                if(vp3_initiative_email_canvas_attempt_key_v1($pdo,(int)$user['id'],$binding,$ref)===null)unset($email[$ref]);
                            }
                        }
                        $evidence+=$email;
                        $coverage['email_canvas']='ready';
                    }catch(PDOException $error){$coverage['email_canvas']='unavailable';}
                    catch(RuntimeException $error){$coverage['email_canvas']=$error->getCode()===503?'unavailable':'forbidden';}
                }
            }
        }catch(Throwable $e){
            foreach(['workbench_product','workbench_screen','workbench_mission','workbench_marketing'] as $family){
                $coverage[$family]=in_array($e->getCode(),[403,404],true)?'forbidden':'unavailable';
            }
        }
    }
    if(!empty($binding['enabled'])&&(int)($binding['conversation_id']??0)>0){
        try{$evidence+=vp3_initiative_specialist_evidence_v1($pdo,$user,$binding,$now,$selectedRefs,$coverage);}
        catch(RuntimeException $error){$coverage['specialist_reports']=$error->getCode()===403?'forbidden':'unavailable';}
        try{$evidence+=vp3_initiative_result_evidence_v1($pdo,$user,$binding,$now,$selectedRefs,$coverage);}
        catch(RuntimeException $error){$coverage['specialist_results']=$error->getCode()===403?'forbidden':'unavailable';}
    }
    if($selectedRefs===null){
        $meetingUrls=[];
        foreach($evidence as $ref=>$row){
            if(!isset($row['meeting_prep']))continue;
            $meetingUrls[$row['url']]=true;
            if(!vp3_initiative_meeting_prep_eligible_v1($pdo,(int)$user['id'],$agentId,$row['meeting_prep'],$now))unset($evidence[$ref]);
        }
        foreach(array_keys($evidence) as $ref){
            if($evidence[$ref]['kind']==='meeting_prep'&&isset($meetingUrls[$evidence[$ref]['url']])){
                unset($evidence[$ref]);continue;
            }
            if(preg_match('/\Aopen-task:([1-9][0-9]*)\z/D',$ref,$match)
                &&($evidence['task:'.$match[1]]['capability_tool']??null)==='tasks.read')unset($evidence[$ref]);
        }
    }
    if($includeBrainstorm&&$selectedRefs!==null
        &&array_filter($selectedRefs,static fn(string $ref):bool=>str_starts_with($ref,'brainstorm-context:'))){
        require_once __DIR__.'/agent-initiative-brainstorm-specialist-v1.php';
        $evidence+=vp3_brainstorm_evidence_v1($pdo,$user,$binding,$selectedRefs,$evidence,$coverage);
    }
    ksort($evidence);
    return $evidence;
}

function vp3_initiative_policy_v1(array $user,?int $agentId=null): array
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($user):array{
        $memory=agent_brain_v122_memory($user,'initiative_policy','owner-opt-in');
        return vp3_initiative_policy_metadata_v1($memory['metadata']??[]);
    });
}

/** Internal control metadata is not an authorization to read Brain contents. */
function vp3_initiative_policy_metadata_v1(array $metadata): array
{
    return array_intersect_key($metadata,array_flip(['enabled','conversation_id','revision','screen_enabled','owner_user_id','agent_id','knowledge_scope','allow_auto_drafts']));
}

function vp3_initiative_access_v1(PDO $pdo,array $user,array $binding): array
{
    $uid=(int)$user['id'];$cid=(int)($binding['conversation_id']??0);$agentId=vp3_initiative_binding_agent_v1($binding);
    if(!is_int($binding['conversation_id']??null)||$cid<1||!is_string($binding['revision']??null)
        ||!preg_match('/\A[a-f0-9]{32}\z/D',$binding['revision']))throw new RuntimeException('Initiative binding is invalid.',403);
    if(($agentId>0&&($binding['owner_user_id']??null)!==$uid)
        ||(isset($binding['owner_user_id'])&&$binding['owner_user_id']!==$uid))throw new RuntimeException('Initiative owner changed.',403);
    $principal=vp3_initiative_principal_v1($pdo,$user,$agentId);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
    $policy=vp3_initiative_policy_v1($live,$agentId);
    if($cid<1||!has_permission('account.access',$live)||($policy['enabled']??false)!==true
        ||$policy!==$binding||vp3_initiative_binding_agent_v1($policy)!==$agentId){
        throw new RuntimeException('Initiative scope changed.',403);
    }
    return $live;
}

function vp3_initiative_model_v1(PDO $pdo,array $user,string $prompt,string $protocol,?int $agentId=null,bool $transientContext=false,?callable $beforeInference=null,array $job=[]): string
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    require_once __DIR__.'/agent-initiative-model-v1.php';
    return vp3_initiative_infer_v1($pdo,$user,$agentId,$prompt,$protocol,$transientContext,$beforeInference,$job);
}

function vp3_initiative_transcript_research_queue_key_v1(array $binding,array $source,string $grant): string
{
    $topic=$source['research']['topic_key']??null;
    if($grant===''||!is_string($topic)||!preg_match('/\A[a-f0-9]{64}\z/D',$topic)){
        throw new RuntimeException('Transcript research authorization changed.',403);
    }
    return 'transcript-research:'.hash('sha256',json_encode([$binding,$grant,$topic],JSON_THROW_ON_ERROR));
}

/** Coalesce current topic assignments and recently checked equivalents in this Agent scope. */
function vp3_initiative_transcript_research_recent_v1(PDO $pdo,int $owner,array $keys): array
{
    if(!$keys)return [];
    $slots=implode(',',array_fill(0,count($keys),'?'));
    $q=$pdo->prepare("SELECT DISTINCT source_key FROM agent_workflow_runs WHERE owner_user_id=?
        AND source_kind='agent_initiative' AND capability_key='initiative.prepare'
        AND source_key IN ($slots) AND (status IN ('queued','approved','executing')
            OR (status='completed' AND updated_at>=?))");
    $q->execute([$owner,...$keys,gmdate('Y-m-d H:i:s',time()-7*86400)]);
    return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
}

function vp3_initiative_unassigned_transcript_research_v1(PDO $pdo,int $owner,array $binding,array $evidence): array
{
    $candidates=array_filter($evidence,static fn(array $row):bool=>($row['kind']??null)==='transcript_research');
    if(!$candidates)return $evidence;
    require_once __DIR__.'/agent-transcript-research-policy-v1.php';
    $grant=vp3_transcript_research_grant_v1($pdo,$owner);
    if($grant==='')return array_diff_key($evidence,$candidates);
    $keys=array_map(static fn(array $row):string=>vp3_initiative_transcript_research_queue_key_v1($binding,$row,$grant),$candidates);
    $recent=vp3_initiative_transcript_research_recent_v1($pdo,$owner,array_values(array_unique($keys)));
    $dedupes=[];
    foreach($candidates as $ref=>$source){
        if(isset($recent[$keys[$ref]])){unset($evidence[$ref]);continue;}
        $hash=hash('sha256',json_encode([$binding,'topic.research',$ref,[$ref=>$source['revision']]],JSON_THROW_ON_ERROR));
        $dedupes[hash('sha256','initiative.prepare|'.$hash)]=$ref;
    }
    if($dedupes){
        $slots=implode(',',array_fill(0,count($dedupes),'?'));
        $q=$pdo->prepare("SELECT dedupe_key FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key IN ($slots)");
        $q->execute([$owner,...array_keys($dedupes)]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $key)unset($evidence[$dedupes[$key]]);
    }
    return $evidence;
}

/** A refreshed inbox changes the data shown in the same Canvas. */
function vp3_initiative_email_canvas_work_key_v1(string $ref): string
{
    return hash('sha256','initiative-email-canvas|'.$ref);
}

/** An old proposal can be reassessed after a permission change. A saved or in-flight app keeps its identity. */
function vp3_initiative_email_canvas_attempt_key_v1(PDO $pdo,int $owner,array $binding,string $ref): ?string
{
    $key=vp3_initiative_email_canvas_work_key_v1($ref);
    $q=$pdo->prepare("SELECT r.goal,r.status,a.result_json FROM agent_workflow_runs r
        LEFT JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
        WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
            AND r.source_key=? ORDER BY r.id DESC LIMIT 12");
    $q->execute([$owner,$key]);
    $runs=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($runs as $run){
        $goal=json_decode((string)$run['goal'],true);
        if(($goal['primary']??null)!==$ref)continue;
        if(($goal['binding']??null)===$binding)return null;
        if($run['status']!=='completed')continue;
        $output=json_decode((string)($run['result_json']??''),true)['initiative_tool_result']['output']??[];
        if(($output['status']??null)!=='draft_queued'||!is_int($output['coding_run_id']??null))continue;
        $child=$pdo->prepare("SELECT status FROM agent_workflow_runs WHERE owner_user_id=? AND id=? AND source_kind='coding_canvas'");
        $child->execute([$owner,$output['coding_run_id']]);
        if(in_array($child->fetchColumn(),['approved','queued','executing','paused','completed'],true))return null;
    }
    return $runs?hash('sha256',$key.'|'.$binding['revision']):$key;
}

/** Give one opted-in transcript question a worker slot if assessment left room. */
function vp3_initiative_transcript_research_fallback_v1(PDO $pdo,array $user,array $binding,array $jobs,array $evidence,array $topicRefs): ?array
{
    if(!$topicRefs||count($jobs)>=vp3_initiative_limits_v1()['max_jobs'])return null;
    foreach($jobs as $job){
        if(($evidence[$job['primary']]['kind']??null)==='transcript_research')return null;
    }
    $candidates=array_intersect_key(array_filter($evidence,
        static fn(array $row):bool=>($row['kind']??null)==='transcript_research'),array_fill_keys($topicRefs,true));
    $candidates=vp3_initiative_unassigned_transcript_research_v1($pdo,(int)$user['id'],$binding,$candidates);
    $primary=array_key_first($candidates);
    if($primary===null)return null;
    return ['tool'=>'topic.research','primary'=>$primary,'refs'=>[$primary],
        'focus'=>'Check whether public sources can answer this saved transcript question.','report'=>true];
}

function vp3_initiative_enqueue_v1(PDO $pdo,array $user,string $capability,array $payload,string $hash): bool
{
    if(!is_array($payload['binding']??null))throw new RuntimeException('Initiative binding is invalid.',403);
    $live=vp3_initiative_access_v1($pdo,$user,$payload['binding']);
    $uid=(int)$user['id'];
    if($capability==='initiative.prepare'&&($payload['tool']??null)==='artist.research'&&is_string($payload['primary']??null)){
        require_once __DIR__.'/agent-initiative-artist-v1.php';
        if(vp3_initiative_artist_duplicate_v1($pdo,$live,$payload['binding'],$payload['primary']))return false;
    }
    $primary=$payload['primary']??null;
    if($capability==='initiative.prepare'&&($payload['tool']??null)==='canvas.prepare'
        &&!isset($payload['followthrough'])&&!isset($payload['owner_canvas_start'])
        &&is_string($primary)&&!str_starts_with($primary,'email-canvas:')
        &&is_string($payload['sources'][$primary]??null)){
        require_once __DIR__.'/agent-initiative-app-suggestions-schedule-v1.php';
        if(vp3_initiative_canvas_proposal_exists_v1($pdo,$uid,$payload['binding'],$primary,$payload['sources'][$primary]))return false;
    }
    $dedupe=hash('sha256',$capability.'|'.$hash);
    $existing=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');
    $existing->execute([$uid,$dedupe]);
    if($existing->fetchColumn())return false;
    $emailRef=$payload['primary']??null;
    $emailWorkKey=$capability==='initiative.prepare'&&($payload['tool']??null)==='canvas.prepare'&&is_string($emailRef)
        &&preg_match('/\Aemail-canvas:[a-f0-9]{64}\z/D',$emailRef)?vp3_initiative_email_canvas_work_key_v1($emailRef):null;
    $sourceKey=$payload['transcript_research_queue_key']??$emailWorkKey??$hash;
    if($capability==='initiative.plan'&&isset($payload['eyes_visit_ref'])){
        $sourceKey='assessment:'.$payload['eyes_visit_ref'];
    }
    $transcriptResearch=isset($payload['transcript_research_queue_key'])&&$sourceKey!==$hash;
    if($transcriptResearch&&vp3_initiative_transcript_research_recent_v1($pdo,$uid,[$sourceKey]))return false;
    $label=$capability==='initiative.plan'?'Review priorities across specialists':(isset($payload['followup'])?'Reassess a scheduled follow-up':'Prepare a private next step');
    if($capability==='initiative.plan'&&($payload['opportunity_lane']??null)==='app_suggestions'){
        $label='Review opportunities for Canvas apps';
    }
    if($capability==='initiative.prepare'&&($payload['tool']??null)==='chat.check_in'
        &&vp3_live_context_eyes_visit_ref_v1($payload['primary']??null)){
        $sourceKey=$payload['primary'];
        $label='Consider an Agent Eyes arrival';
    }
    if($capability==='initiative.prepare'&&($payload['tool']??null)==='youtube.playback_offer'){
        $sourceKey='youtube-offer:'.$hash;
        $label='Prepare a YouTube playback offer';
    }
    if($capability==='initiative.prepare'&&($payload['tool']??null)==='connected-sites.scan'){
        require_once __DIR__.'/agent-initiative-connected-sites-v1.php';
        $sourceKey=vp3_initiative_connected_sites_key_v1($payload['connected_site_target']);
        $target=$payload['connected_site_target'];
        $label=match(true){
            ($target['provider']??null)==='stoneward'=>'Check recent Stonefellow listening',
            ($target['dataset']??null)==='sales'=>'Check FoodMonster daily sales',
            default=>'Check a connected POS location',
        };
    }
    if(($payload['tool']??null)==='brainstorm.explore')$label='Explore ideas across specialist briefs';
    if($transcriptResearch)$label='Research a saved transcript question';
    if($capability==='initiative.prepare'&&($payload['tool']??null)==='canvas.prepare'
        &&is_string($payload['primary']??null)&&str_starts_with($payload['primary'],'canvas-feedback:')){
        $label=isset($payload['followthrough'])?'Prepare the approved private Canvas draft':'Propose an improvement to a Canvas app';
    }
    if($capability==='initiative.prepare'&&($payload['tool']??null)==='coordination.review'){
        $id=$payload['coordination_id']??null;
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{64}\z/D',$id))throw new RuntimeException('Top of Mind assignment changed.',409);
        $sourceKey='coordination:'.$id;
        $label=isset($payload['coordination_work'])||isset($payload['followthrough'])?'Advance a Top of Mind finding':'Connect specialist findings';
        if(($payload['coordination_route']['specialist']??null)==='casual')$label='Prepare a casual check-in';
        if(($payload['coordination_route']['specialist']??null)==='curiosity')$label='Prepare a Curiosity question';
    }
    if($capability==='initiative.prepare'&&($payload['office_entry']??null)===true&&!isset($payload['followthrough'])){
        require_once __DIR__.'/agent-initiative-office-entry-work-v1.php';
        $task=$payload['office_agent_task']??null;
        $sourceKey=$task===null?vp3_initiative_office_entry_key_v1($payload['binding'],$payload['primary'])
            :vp3_initiative_office_agent_task_key_v1($task['report_run_id']);
        $label=$task===null?'Review a saved task, list or reminder':'Agent Task · '.$task['title'];
    }
    $pdo->prepare("INSERT INTO agent_workflow_runs
        (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,retry_backoff_seconds,next_attempt_at)
        VALUES (?,?,'initiative','brain','agent_initiative',?,?,?,?,?,'approved','low',0,'not_required','cloud',?,3,60,UTC_TIMESTAMP())")
        ->execute([$uid,($agent=vp3_initiative_binding_agent_v1($payload['binding']))>0?$agent:null,$sourceKey,substr($hash,0,40),$dedupe,$label,json_encode($payload,JSON_THROW_ON_ERROR),$capability]);
    $run=(int)$pdo->lastInsertId();
    if(agent_job_control_ready_v1900($pdo)){
        $priority=($payload['office_entry']??null)===true&&!isset($payload['followthrough'])?5:10;
        if(($payload['tool']??null)==='coordination.review')$priority=($payload['coordination_priority']??null)==='high'||isset($payload['followthrough'])?20:10;
        if(($payload['coordination_route']['specialist']??null)==='transcripts'&&!isset($payload['followthrough']))$priority=8;
        if(in_array($payload['coordination_route']['specialist']??null,['casual','curiosity'],true))$priority=1;
        if(($payload['tool']??null)==='connected-sites.scan')$priority=6;
        if(($payload['tool']??null)==='brainstorm.explore')$priority=0;
        $pdo->prepare('UPDATE agent_workflow_runs SET work_priority=? WHERE id=? AND owner_user_id=?')->execute([$priority,$run,$uid]);
    }
    $pdo->prepare("INSERT INTO agent_workflow_actions
        (run_id,owner_user_id,sequence_no,action_key,action_type,label,status,requires_approval,execution_target,capability_key,max_attempts)
        VALUES (?,?,1,?,'execute',?,'queued',0,'cloud',?,3)")
        ->execute([$run,$uid,$capability,$label,$capability]);
    agent_workflow_event_v1400($pdo,$uid,$run,'initiative_queued','','approved','system',$label);
    if ($transcriptResearch && table_exists('video_meeting_transcription_links')) {
        require_once __DIR__.'/agent-meeting-research-v1.php';
        vp3_meeting_research_link_new_run_v1($pdo,$live,$run,$payload);
    }
    return true;
}

function vp3_initiative_owner_lock_v1(PDO $pdo,array $user): void
{
    $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $lock->execute([(int)$user['id']]);
    if(!$lock->fetchColumn())throw new RuntimeException('Initiative owner is unavailable.',403);
}

function vp3_initiative_schedule_v1(PDO $pdo,array $user,?int $agentId=null,bool $discoverSpecialists=true): void
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,null,$discoverSpecialists));
}

function vp3_initiative_sql_timestamp_v1(mixed $value): int
{
    if(!is_string($value)||$value==='')return 0;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $date&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():0;
}

/** Compare SQL timestamps against the same database clock, never PHP's local timezone. */
function vp3_initiative_review_recent_v1(PDO $pdo,?array $last,array $policy,?string $liveRevision=null,
    ?string $engagementRevision=null,?string $availability=null): bool
{
    $previous=$last['metadata']??[];
    if(!$last||($previous['conversation_id']??null)!==($policy['conversation_id']??null)
        ||($previous['revision']??null)!==($policy['revision']??null))return false;
    $databaseNow=vp3_initiative_sql_timestamp_v1($pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn());
    $seen=vp3_initiative_sql_timestamp_v1($last['last_seen_at']??null);
    if($databaseNow<1||$seen<1||$databaseNow-$seen<0||$databaseNow-$seen>=300)return false;
    // A return to quiet Chat, live context change or owner decision can wake
    // the assessment after 30 seconds. Heartbeats alone keep the normal cadence.
    $ownerReturned=$availability==='quiet_chat'&&in_array($previous['owner_availability']??null,['busy','away'],true);
    if($databaseNow-$seen>=30&&($ownerReturned||!empty($previous['specialist_source_changed'])
        ||($liveRevision!==null&&($previous['live_context_revision']??'')!==$liveRevision)
        ||($engagementRevision!==null&&($previous['engagement_revision']??'')!==$engagementRevision)))return false;
    if(isset($previous['checked_at'])){
        try{$checked=new DateTimeImmutable((string)$previous['checked_at'],new DateTimeZone('UTC'));}
        catch(Throwable $error){return false;}
        $age=time()-$checked->getTimestamp();if($age<0||$age>=300)return false;
    }
    return true;
}

function vp3_initiative_live_revision_v1(PDO $pdo,array $user,array $policy): string
{
    if(!table_exists('agent_activity_state'))return '';
    require_once __DIR__.'/agent-live-context-v1.php';
    $principal=vp3_initiative_principal_v1($pdo,$user,vp3_initiative_binding_agent_v1($policy));
    $conversation=(int)($policy['conversation_id']??0);
    $snapshot=vp3_live_context_snapshot_v1($pdo,$user,$principal,$conversation);
    require_once __DIR__.'/agent-transcript-focus-v1.php';
    $focus=vp3_transcript_focus_id_v1($pdo,(int)$user['id'],(int)$principal['agent_id'],$conversation);
    $revision=$snapshot['revision']??'';
    if(is_string($snapshot['eyes_visit_ref']??null))$revision=hash('sha256',$revision.'|'.$snapshot['eyes_visit_ref']);
    return $focus>0?hash('sha256',$revision.'|transcript:'.$focus):$revision;
}

function vp3_initiative_schedule_coordination_v1(PDO $pdo,array $live,array $policy,bool $executionReady=true,
    ?string &$outcome=null,?array &$failure=null,bool $observeOnly=false,bool $allowDelivery=true): bool
{
    $phase='scan_setup';
    $failure=null;
    try{
        require_once __DIR__.'/agent-coordination-schedule-v1.php';
        $outcome='not_due';
        if(!vp3_coordination_scan_due_v1($live,$policy))return true;
        $phase='owner_lock';
        $pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$live);
        $phase='chat_access';
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $phase='specialist_scan';
        $outcome=vp3_coordination_schedule_v1($pdo,$live,$policy,$executionReady,$phase,$observeOnly,$allowDelivery);
        $phase='commit';
        $pdo->commit();
        return true;
    }catch(Throwable $error){
        if($pdo->inTransaction()){
            try{$pdo->rollBack();}
            catch(PDOException $ignored){} // The database may have already rolled back a deadlock.
        }
        $outcome='failed';
        $failure=vp3_initiative_coordination_failure_v1($error,$phase);
        if(function_exists('agent_runtime_v125_trace')){
            try{agent_runtime_v125_trace('initiative.coordination_scan_failed',
                ['user_id'=>(int)$live['id'],'error_class'=>get_class($error)]+$failure);}
            catch(Throwable $ignored){}
        }
        return false;
    }
}

/** Record bounded execution facts, never exception text, queries, or source data. */
function vp3_initiative_coordination_failure_v1(Throwable $error,string $phase): array
{
    $failure=['phase'=>$phase,'code'=>match(true){
        $error instanceof PDOException=>'storage_error',
        $error instanceof TypeError=>'invalid_internal_type',
        $error instanceof JsonException,$error instanceof InvalidArgumentException=>'invalid_data',
        $error instanceof RuntimeException&&in_array($error->getCode(),[403,404],true)=>'access_changed',
        $error instanceof RuntimeException&&$error->getCode()===409=>'state_changed',
        $error instanceof RuntimeException&&$error->getCode()===503=>'verification_unavailable',
        $error instanceof Error=>'code_error',
        default=>'unconfirmed',
    }];
    if($error instanceof PDOException){
        $number=filter_var($error->errorInfo[1]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $state=$error->errorInfo[0]??(string)$error->getCode();
        if(is_string($state)&&preg_match('/\A[A-Z0-9]{5}\z/D',$state))$failure['sqlstate']=$state;
        if($number!==false)$failure['database_code']=$number;
        $failure['code']=match($number){
            1054,1146=>'storage_schema',
            1205,1213=>'storage_contention',
            1062=>'storage_duplicate',
            default=>'storage_error',
        };
    }
    $file=basename($error->getFile());
    if(str_starts_with($error->getFile(),__DIR__.'/')&&preg_match('/\A[a-z0-9-]+\.php\z/D',$file)){
        $failure['file']=$file;
        $failure['line']=$error->getLine();
    }
    return $failure;
}

/** Discover native task work independently from the specialist handoff lane. */
function vp3_initiative_schedule_office_entries_v1(PDO $pdo,array $live,array $policy): void
{
    if($pdo->inTransaction())return;
    try{
        require_once __DIR__.'/agent-initiative-office-entry-scan-v1.php';
        if(!vp3_initiative_office_entry_scan_due_v1($live,$policy))return;
        $pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        vp3_initiative_office_entry_schedule_v1($pdo,$live,$policy);
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction()){
            try{$pdo->rollBack();}
            catch(PDOException $ignored){}
        }
        if(function_exists('agent_runtime_v125_trace')){
            try{agent_runtime_v125_trace('initiative.office_entry_scan_failed',
                ['user_id'=>(int)$live['id'],'error_class'=>get_class($error)]);}
            catch(Throwable $ignored){}
        }
    }
}

function vp3_initiative_schedule_scoped_v1(PDO $pdo,array $user,?array $selectedRefs=null,bool $discoverSpecialists=true): void
{
    if($pdo->inTransaction())return;
    require_once __DIR__.'/agent-live-context-v1.php';
    try{vp3_live_context_expire_v1($pdo,$user);}
    catch(Throwable $error){if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('live_context.cleanup_unavailable',['user_id'=>(int)$user['id'],'error_class'=>get_class($error)]);}
    if(function_exists('homeserver_https_v1300_scrub_transient')){
        try{homeserver_https_v1300_scrub_transient($pdo,20,(int)$user['id']);}
        catch(Throwable $error){if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('transient_inference.cleanup_unavailable',['user_id'=>(int)$user['id'],'error_class'=>get_class($error)]);}
    }
    if(!agent_job_engine_schema_ready_v1900($pdo))return;
    $policy=vp3_initiative_policy_v1($user);
    if(empty($policy['enabled']))return;
    $live=vp3_initiative_access_v1($pdo,$user,$policy);
    if($selectedRefs===null&&$discoverSpecialists){
        vp3_initiative_schedule_coordination_v1($pdo,$live,$policy);
        vp3_initiative_schedule_office_entries_v1($pdo,$live,$policy);
        require_once __DIR__.'/agent-initiative-connected-sites-v1.php';
        vp3_initiative_connected_sites_schedule_v1($pdo,$live,$policy);
        require_once __DIR__.'/agent-initiative-opportunity-schedule-v1.php';
        vp3_initiative_schedule_opportunities_v1($pdo,$live,$policy);
    }
    require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';
    $liveRevision=vp3_initiative_live_revision_v1($pdo,$live,$policy);
    $engagementRevision=vp3_initiative_engagement_revision_v1($pdo,$live,$policy);
    $last=agent_brain_v122_memory($live,'initiative_sweep','last-review');
    if($selectedRefs===null){
        $availability=vp3_initiative_availability_v1(vp3_initiative_owner_context_v1($pdo,$live,$policy,false));
        if(vp3_initiative_review_recent_v1($pdo,$last,$policy,$liveRevision,$engagementRevision,$availability))return;
    }
    // A due assessment primes bounded transcript privacy proofs before owner
    // locks. The locked assessment repeats the actual source/identity reads.
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $reportKeys=[];
    if($selectedRefs===null){
        require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
        $reportAuthority=[];
        vp3_initiative_specialist_reports_v1($pdo,$live,$policy,[],$reportAuthority,true);
        $reportKeys=array_keys($reportAuthority['report_keys']);
    }
    if(table_exists('artist_transcript_sessions_v172')&&table_exists('artist_transcript_segments_v172')){
        require_once __DIR__.'/agent-initiative-tools-v1.php';
        require_once __DIR__.'/agent-initiative-transcript-research-v1.php';
        vp3_initiative_capability_load_v1('transcript');$coverage=[];
        try{
            $scope=vp3_initiative_tools_scope_v1($pdo,$live,$policy);
            vp3_initiative_transcript_capabilities_evidence_v1($pdo,$scope,$policy,$now,$selectedRefs,$coverage);
            vp3_initiative_transcript_research_evidence_v1($pdo,$scope,$policy,$now,$selectedRefs,$coverage);
        }catch(RuntimeException $error){if(!in_array($error->getCode(),[403,404,409,503],true))throw $error;}
    }
    $pdo->beginTransaction();
    try{
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $last=agent_brain_v122_memory($live,'initiative_sweep','last-review');
        $previous=$last['metadata']??[];
        $liveRevision=vp3_initiative_live_revision_v1($pdo,$live,$policy);
        $engagementRevision=vp3_initiative_engagement_revision_v1($pdo,$live,$policy);
        $availability=vp3_initiative_availability_v1(vp3_initiative_owner_context_v1($pdo,$live,$policy,false));
        if($selectedRefs!==null||!vp3_initiative_review_recent_v1($pdo,$last,$policy,$liveRevision,$engagementRevision,$availability)){
            $attentionRevision=$previous['attention_revision']??null;
            if(($previous['conversation_id']??null)!==($policy['conversation_id']??null)
                ||($previous['revision']??null)!==($policy['revision']??null)||($previous['owner_availability']??null)!==$availability
                ||!is_string($attentionRevision)||!preg_match('/\A[a-f0-9]{32}\z/D',$attentionRevision)){
                $attentionRevision=bin2hex(random_bytes(16));
            }
            $coverage=[];
            $evidence=vp3_initiative_evidence_v1($pdo,$live,$now,
                $selectedRefs===null&&!empty($policy['screen_enabled']),[],$coverage,$selectedRefs);
            if($selectedRefs!==null&&array_diff($selectedRefs,array_keys($evidence))){
                $pdo->commit();
                return;
            }
            $evidence=vp3_initiative_unassigned_transcript_research_v1($pdo,(int)$live['id'],$policy,$evidence);
            $reportOnlyEligible=$evidence===[];
            if($evidence){
                $canonical=array_map(static fn(array $row):string=>$row['canonical_source_revision']??$row['revision'],$evidence);
                $payload=['binding'=>$policy,'sources'=>array_column($evidence,'revision','ref'),'canonical_sources'=>$canonical,
                    'capability_sources'=>vp3_initiative_capability_source_map_v1($evidence,array_keys($evidence)),
                    'owner_availability'=>$availability,'attention_revision'=>$attentionRevision,
                    'reassessment_slot'=>vp3_initiative_brainstorm_slot_v1()];
                foreach($evidence as $ref=>$row){
                    if($row['kind']==='eyes_visit'){$payload['eyes_visit_ref']=$ref;break;}
                }
                if($engagementRevision!=='')$payload['engagement_revision']=$engagementRevision;
                $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
                $setupOnly=!array_diff(array_column($evidence,'kind'),['account_readiness','scheduling_readiness',
                    'calendar_readiness','product_readiness','business_readiness']);
                if(!vp3_initiative_brainstorm_pending_v1($pdo,$live,$payload,$setupOnly)){
                    vp3_initiative_enqueue_v1($pdo,$live,'initiative.plan',$payload,$hash);
                }elseif($setupOnly&&$reportKeys){
                    $reportOnlyEligible=!vp3_initiative_brainstorm_pending_v1($pdo,$live,$payload);
                }
            }
            if($reportOnlyEligible&&$reportKeys){
                // The saved result, rather than a scan tick or delivery state, deduplicates the assessment.
                require_once __DIR__.'/agent-initiative-model-recovery-v1.php';
                foreach($reportKeys as $reportKey){
                    $payload=['binding'=>$policy,'sources'=>[],'report_only'=>$reportKey];
                    $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
                    if(vp3_initiative_enqueue_v1($pdo,$live,'initiative.plan',$payload,$hash)
                        ||vp3_initiative_recover_rejected_assessment_v1($pdo,$live,$payload,$hash))break;
                }
            }
            $review=[
                'conversation_id'=>$policy['conversation_id'],
                'revision'=>$policy['revision'],
                'owner_availability'=>$availability,'attention_revision'=>$attentionRevision,
                'live_context_revision'=>$liveRevision,
                'engagement_revision'=>$engagementRevision,
                'checked_at'=>gmdate(DATE_ATOM),
            ];
            if($selectedRefs===null&&agent_brain_v122_upsert_system_memory($live,'initiative_sweep','last-review','Initiative source review checked.',$review)<1){
                throw new RuntimeException('Initiative review could not be recorded.');
            }
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function vp3_initiative_sweep_v1(PDO $pdo,int $limit): void
{
    // Cache cleanup is independent of an owner's enabled Initiative policy.
    // A crashed client or disabled policy must not keep a sensory packet.
    require_once __DIR__.'/agent-live-context-v1.php';
    try{vp3_live_context_expire_all_v1($pdo,max(1,min(100,$limit)));}
    catch(Throwable $error){if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('live_context.cleanup_unavailable',['error_class'=>get_class($error)]);}
    if(function_exists('homeserver_https_v1300_scrub_transient')){
        try{homeserver_https_v1300_scrub_transient($pdo,max(1,min(100,$limit)));}
        catch(Throwable $error){if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('transient_inference.cleanup_unavailable',['error_class'=>get_class($error)]);}
    }
    if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo))return;
    $limit=max(1,min(25,$limit));
    vp3_initiative_delivery_sweep_v1($pdo,$limit);
    $stmt=$pdo->prepare("SELECT p.user_id,COALESCE(p.user_agent_id,0) AS agent_id FROM agent_memory_items p
        JOIN users u ON u.id=p.user_id AND u.is_active=1
        LEFT JOIN agent_memory_items s ON s.user_id=p.user_id AND COALESCE(s.user_agent_id,0)=COALESCE(p.user_agent_id,0)
            AND s.memory_type='initiative_sweep' AND s.subject='last-review' AND s.is_active=1
        WHERE p.memory_type='initiative_policy' AND p.subject='owner-opt-in'
            AND p.is_active=1 AND JSON_VALID(p.metadata_json)
            AND JSON_UNQUOTE(JSON_EXTRACT(p.metadata_json,'$.enabled'))='true'
            AND (s.id IS NULL OR s.last_seen_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))
        ORDER BY COALESCE(s.last_seen_at,p.last_seen_at),p.user_id LIMIT ".$limit);
    $stmt->execute();
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $owner=(int)$row['user_id'];$agentId=(int)$row['agent_id'];
        $user=agent_cognitive_loop_v310_user($owner);
        if(!$user)continue;
        try{vp3_initiative_schedule_v1($pdo,$user,$agentId);}
        catch(Throwable $e){agent_runtime_v125_trace('initiative.schedule_failed',['user_id'=>(int)$owner,'error_class'=>get_class($e)]);}
    }
}

/** Both Agent Eyes and Chat commands use the same owner policy and revision. */
function vp3_initiative_screen_setting_v1(PDO $pdo,array $user,int $cid,?bool $enabled=null,?string $expectedRevision=null,?callable $authority=null): array
{
    $principal=vp3_capability_control_principal_v1($pdo,$user,$cid);
    $agentId=(int)($principal['agent_id']??0);
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_screen_setting_scoped_v1($pdo,$user,$cid,$enabled,$expectedRevision,$authority));
}

function vp3_initiative_screen_setting_scoped_v1(PDO $pdo,array $user,int $cid,?bool $enabled=null,?string $expectedRevision=null,?callable $authority=null): array
{
    if($cid<1||vp3_participant_scope_v1()!==null||vp3_agent_memory_scope_current_v410($user)!==0){
        throw new RuntimeException('Open your private System Agent Chat to change this setting.',403);
    }
    $unavailable=['available'=>false,'enabled'=>false,'revision'=>''];
    $principal=vp3_agent_chat_principal_v380($user,null);
    try{
        if($enabled!==null){
            $pdo->beginTransaction();
            vp3_initiative_owner_lock_v1($pdo,$user);
        }
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
        if(!has_permission('account.access',$live)||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],null,$enabled!==null)){
            throw new RuntimeException('Conversation access changed.',403);
        }
        if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
            if($enabled!==null)throw new RuntimeException('Initiative storage needs its database upgrades.',503);
            return $unavailable+['reason'=>'storage_unavailable'];
        }
        $policy=vp3_initiative_policy_v1($live);
        if(empty($policy['enabled'])||$policy['conversation_id']!==$cid){
            if($enabled!==null)$pdo->rollBack();
            return $unavailable+['reason'=>empty($policy['enabled'])?'initiative_off':'linked_elsewhere'];
        }
        $screenEnabled=!empty($policy['screen_enabled']);
        $canEnable=personal_capability_has_v242('agent_brain.access',$live)&&personal_knowledge_available($live);
        if($enabled!==null){
            if($expectedRevision!==null&&!hash_equals((string)$policy['revision'],$expectedRevision)){
                throw new RuntimeException('Screen follow-up settings changed. Refresh and try again.',409);
            }
            if($enabled&&!$canEnable)throw new RuntimeException('Agent Brain and My Knowledge access are required.',403);
            if($enabled!==$screenEnabled){
                $policy['screen_enabled']=$enabled;
                $policy['revision']=bin2hex(random_bytes(16));
                if(agent_brain_v122_upsert_system_memory($live,'initiative_policy','owner-opt-in','Owner initiative preference.',$policy)<1){
                    throw new RuntimeException('Initiative screen setting could not be saved.',503);
                }
                $screenEnabled=$enabled;
            }
            $pdo->commit();
        }
        return ['available'=>$canEnable||$screenEnabled,'enabled'=>$screenEnabled,
            'revision'=>(string)$policy['revision'],'reason'=>$canEnable?'':'access_unavailable'];
    }catch(Throwable $e){
        if($enabled!==null&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_initiative_chat_v1(PDO $pdo,array $user,array $principal,array $context,string $command,?callable $authority): array
{
    $kind=$principal['kind']??'';$agentId=$kind==='user_agent'?(int)($principal['agent_id']??0):0;
    if(!in_array($kind,['system','user_agent'],true)||($kind==='user_agent'&&($agentId<1
        ||(int)($principal['owner_user_id']??0)!==(int)($user['id']??0)))){
        return ['handled'=>true,'ok'=>false,'answer'=>'Use your private owner Agent Chat to control initiative.','actions'=>[],'sources'=>[]];
    }
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_chat_scoped_v1($pdo,$user,$principal,$context,$command,$authority));
}

function vp3_initiative_chat_scoped_v1(PDO $pdo,array $user,array $principal,array $context,string $command,?callable $authority): array
{
    $result=['handled'=>true,'actions'=>[],'sources'=>[]];
    $cid=(int)($context['conversation_id']??0);
    if($cid<1||vp3_participant_scope_v1()!==null){
        return $result+['ok'=>false,'answer'=>'Use your private owner Agent Chat to control initiative.'];
    }
    try{
        if(in_array($command,['screen on','screen off'],true)){
            $enableScreen=$command==='screen on';
            $setting=vp3_initiative_screen_setting_v1($pdo,$user,$cid,$enableScreen,null,$authority);
            if(in_array($setting['reason'],['initiative_off','linked_elsewhere'],true)){
                return $result+['answer'=>'Enable Initiative here first, or open the private Chat where it is already enabled.'];
            }
            return $result+['answer'=>$enableScreen?'Screen-share follow-ups are on. Initiative can use saved note text and session titles from finished shares with your configured Cloud AI model. Use /initiative screen off to stop.':'Screen-share follow-ups are off.'];
        }
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,$context,$authority);
        if(!has_permission('account.access',$live))throw new RuntimeException('Initiative access denied.',403);
        if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
            return $result+['answer'=>'Initiative needs the current Agent Brain and job database upgrades.'];
        }
        if(in_array($command,['status','findings'],true)){
            $policy=vp3_initiative_policy_v1($live);
            if($policy===[])return $result+['answer'=>'Your Agent’s working context starts when this saved Chat is active.'];
            if(empty($policy['enabled']))return $result+['answer'=>'Initiative is paused. Use /initiative on to resume.'];
            if($policy['conversation_id']!==$cid)return $result+['answer'=>'Initiative is linked to another private conversation.'];
            return vp3_initiative_status_v1($pdo,$live,$policy,$result,$command==='findings');
        }
        $pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_capability_live_user_v1($pdo,$live,$principal,$context,$authority);
        if(!has_permission('account.access',$live)){
            throw new RuntimeException('Initiative access denied.',403);
        }
        $policy=vp3_initiative_policy_v1($live);
        if(array_key_exists('initiative_expected_revision',$context)){
            $expected=$context['initiative_expected_revision'];
            if(!is_string($expected)||($expected!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$expected))
                ||!hash_equals((string)($policy['revision']??''),$expected)){
                throw new RuntimeException('initiative_settings_changed',409);
            }
        }
        $enable=$command==='on';
        $knowledge=array_key_exists('knowledge_scope',$context)?vp3_initiative_knowledge_scope_v1($context['knowledge_scope']):($policy['knowledge_scope']??['mode'=>'off','folder_id'=>0]);
        if($policy===[]||$enable!==!empty($policy['enabled'])||($enable&&(($policy['conversation_id']??null)!==$cid
            ||(array_key_exists('knowledge_scope',$context)&&($policy['knowledge_scope']??null)!==$knowledge)))){
            $screenEnabled=($policy['screen_enabled']??false)===true;
            $autoDrafts=($policy['allow_auto_drafts']??false)===true;
            $policy=['enabled'=>$enable,'conversation_id'=>$cid,'revision'=>bin2hex(random_bytes(16))];
            if(($agentId=vp3_agent_memory_scope_current_v410($live))>0)$policy+=['owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId];
            $policy['knowledge_scope']=$knowledge;
            $policy['screen_enabled']=$screenEnabled;
            if($autoDrafts)$policy['allow_auto_drafts']=true;
            if(agent_brain_v122_upsert_system_memory($live,'initiative_policy','owner-opt-in','Owner initiative preference.',$policy)<1){
                throw new RuntimeException('Initiative setting could not be saved.');
            }
        }
        $pdo->commit();
        return $result+['ok'=>true,'initiative_policy'=>$policy,'answer'=>$enable?'Initiative is on for this Agent conversation. I can assess its authorized work, prepare useful next steps with your configured AI, and bring verified results here. Changes that need your review still wait for approval. Use /initiative off to stop.':'Initiative is off.'];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        return $result+['ok'=>false,'reason'=>$e->getMessage()==='initiative_settings_changed'?'settings_changed':'initiative_unconfirmed',
            'answer'=>$e->getMessage()==='initiative_settings_changed'?'Initiative settings changed in another tab. Check the current setting before trying again.':'I couldn’t verify or update initiative for this conversation.'];
    }
}

/** Project only a verified Initiative state for the exact private owner Agent Chat. */
function vp3_initiative_foreground_status_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    if($agentId<0||(int)($user['id']??0)<1)return ['state'=>'unavailable'];
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_foreground_status_scoped_v1($pdo,$user,$agentId,$cid));
}

function vp3_initiative_foreground_status_scoped_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    $unavailable=['state'=>'unavailable'];
    if($cid<0||(int)($user['id']??0)<1
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        return $unavailable;
    }
    try{
        $principal=vp3_initiative_principal_v1($pdo,$user,$agentId);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
        if(!has_permission('account.access',$live)
            ||!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
            return $unavailable;
        }
        $policy=vp3_initiative_policy_v1($live);
        if(empty($policy))return ['state'=>'starting'];
        if(($policy['enabled']??null)===false)return ['state'=>'paused'];
        if(($policy['enabled']??null)!==true)return $unavailable;
        if($cid<1||($policy['conversation_id']??null)!==$cid)return ['state'=>'linked_elsewhere'];
        $revision=$policy['revision']??null;
        if(!is_string($revision)||!preg_match('/\A[a-f0-9]{32}\z/',$revision))return $unavailable;

        $status=['state'=>'waiting_for_review'];
        $review=agent_brain_v122_memory($live,'initiative_sweep','last-review')['metadata']??[];
        $utc=new DateTimeZone('UTC');
        if(($review['conversation_id']??null)===$cid&&($review['revision']??null)===$revision){
            $checkedAt=vp3_catch_up_timestamp_v1($review['checked_at']??null,$utc);
            if($checkedAt!==null&&$checkedAt<=time()){
                $status=['state'=>'checked','last_checked_at'=>gmdate(DATE_ATOM,$checkedAt)];
            }
        }
        $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        $jsonValue=static function(string $column,string $path)use($sqlite): string {
            $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
            return $sqlite?$value:"JSON_UNQUOTE($value)";
        };
        $conversation=$jsonValue('r.goal','$.binding.conversation_id');
        $runRevision=$jsonValue('r.goal','$.binding.revision');
        $effect=$jsonValue('a.result_json','$.effect_state');
        $delivery=$jsonValue('a.result_json','$.initiative_delivery');
        [$agentSql,$agentParams]=vp3_agent_chat_scope_sql_v380($agentId>0?['id'=>$agentId]:null,'r');
        $agentSql=str_replace('r.user_agent_id','r.agent_id',$agentSql);
        $stmt=$pdo->prepare("SELECT r.goal,r.status,r.lease_expires_at,a.result_json,$effect AS effect_state,$delivery AS initiative_delivery FROM agent_workflow_runs r
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND $agentSql
                AND CAST($conversation AS CHAR)=? AND $runRevision=?
            ORDER BY r.id DESC LIMIT 15");
        $stmt->execute(array_merge([(int)$live['id']],$agentParams,[(string)$cid,$revision]));
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $goal=json_decode((string)$row['goal'],true);
            if(($goal['binding']??null)!==$policy)continue;
            if($row['status']==='failed')return ['state'=>'failed']+$status;
            if(in_array($row['status'],['approved','queued'],true))return ['state'=>'waiting_for_review']+$status;
            if($row['status']==='executing'){
                $lease=vp3_catch_up_timestamp_v1($row['lease_expires_at'],$utc);
                return ['state'=>$lease!==null&&$lease>=time()?'reviewing':'waiting_for_review']+$status;
            }
            if($row['status']!=='completed')continue;
            $status['state']='checked';
            if($row['effect_state']!=='private_draft_ready'||in_array($row['initiative_delivery'],['delivered','withheld'],true)
                ||!is_array($goal['sources']??null)||!$goal['sources'])continue;
            try{
                vp3_initiative_current_sources_v1($pdo,$live,$goal['sources'],!empty($policy['screen_enabled']),$goal['canonical_sources']??[],$agentId,$goal['capability_sources']??[]);
                $result=json_decode((string)$row['result_json'],true);
                vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
                return ['state'=>'ready']+$status;
            }catch(RuntimeException $e){
                if($e->getCode()!==409)throw $e;
                return $status;
            }
        }
        return $status;
    }catch(Throwable $e){
        return $unavailable;
    }
}

function vp3_initiative_status_v1(PDO $pdo,array $user,array $policy,array $result,bool $findingsOnly=false): array
{
    return vp3_initiative_in_scope_v1($user,vp3_initiative_binding_agent_v1($policy),
        static fn()=>vp3_initiative_status_scoped_v1($pdo,$user,$policy,$result,$findingsOnly));
}

function vp3_initiative_status_scoped_v1(PDO $pdo,array $user,array $policy,array $result,bool $findingsOnly=false): array
{
    $user=vp3_initiative_access_v1($pdo,$user,$policy);
    $agentId=vp3_initiative_binding_agent_v1($policy);
    [$agentSql,$agentParams]=vp3_agent_chat_scope_sql_v380($agentId>0?['id'=>$agentId]:null,'r');
    $agentSql=str_replace('r.user_agent_id','r.agent_id',$agentSql);
    $stmt=$pdo->prepare("SELECT r.goal,r.status,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND $agentSql
        ORDER BY r.id DESC LIMIT 15");
    $stmt->execute(array_merge([(int)$user['id']],$agentParams));
    $includeScreen=!empty($policy['screen_enabled']);
    $lines=$findingsOnly?[]:['Initiative is on. Screen-share follow-ups are '.($includeScreen?'on.':'off.')];$sources=[];
    $waiting=false;$failed=false;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true);
        if(($goal['binding']??null)!==$policy)continue;
        if($row['status']!=='completed'){
            if(in_array($row['status'],['approved','executing'],true)){
                $waiting=true;
                if(!$findingsOnly)$lines[]='A private review is in progress.';
            }
            if($row['status']==='failed'){
                $failed=true;
                if(!$findingsOnly)$lines[]='A previous review could not be verified.';
            }
            continue;
        }
        $out=json_decode((string)$row['result_json'],true);
        if(($out['effect_state']??'')!=='private_draft_ready')continue;
        if(($out['initiative_tool_result']['output']['public_topic_unavailable']??false)===true)continue;
        if(!is_array($goal['sources']??null)||!$goal['sources'])continue;
        try{
            $matching=vp3_initiative_work_sources_v1($pdo,$user,$policy,$goal);
            vp3_initiative_result_current_v1($pdo,$user,$policy,$goal,$out['initiative_tool_result']??[]);
        }
        catch(RuntimeException $e){
            if(!in_array($e->getCode(),[403,409,503],true))throw $e;
            if($e->getCode()===503){
                $failed=true;
                if(!$findingsOnly)$lines[]='A previous preparation could not be verified right now.';
            }
            continue;
        }
        $lines[]=(string)$out['initiative_draft'];
        foreach($matching as $ref=>$item)$sources[$ref]=['source'=>$ref,'title'=>$item['title'],'url'=>$item['url']];
        if(in_array($goal['tool']??null,['artist.research','topic.research'],true)
            &&is_array($out['initiative_tool_result']['output']??null)){
            $research=vp3_initiative_research_tool_result_v1($out['initiative_tool_result']);
            foreach($research['sources']??[] as $source){
                $sources[$source['source']]=array_intersect_key($source,['source'=>true,'title'=>true,'url'=>true,'coverage'=>true]);
            }
        }
        if(count($lines)>=($findingsOnly?3:4))break;
    }
    if($findingsOnly&&!$lines){
        $lines[]=match(true){
            $waiting=>'I’m still reviewing your workspace. I’ll have a result once the review finishes.',
            $failed=>'I couldn’t verify a saved background finding right now.',
            default=>'I don’t have a verified background finding ready right now.',
        };
    }
    $result['sources']=array_values($sources);
    return $result+['answer'=>implode("\n\n",array_values(array_unique($lines)))];
}

function vp3_initiative_current_sources_v1(PDO $pdo,array $user,array $sources,bool $includeScreen=false,array $canonicalSources=[],?int $agentId=null,array $capabilitySources=[],bool $omitStaleActivity=false): array
{
    $sessions=[];
    foreach(array_keys($sources) as $ref)if(str_starts_with($ref,'screen-followup:'))$sessions[]=substr($ref,16);
    $coverage=[];
    $current=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),$includeScreen&&$sessions!==[],$sessions,$coverage,array_keys($sources),$agentId);
    foreach($sources as $ref=>$revision){
        if(($current[$ref]['revision']??null)===$revision)continue;
        $prefix=explode(':',$ref,2)[0];
        $family=Vp3InitiativeSourceProofUnavailableV1::FAMILIES_BY_REF[$prefix]??'';
        if(preg_match('/\Ayoutube-interest-checkin:(artist|topic):[a-f0-9]{64}\z/D',$ref,$matches))$family=$matches[1].'_research';
        $office=['task'=>'office_tasks','reminder'=>'office_reminders','calendar'=>'office_calendar'][$prefix]??null;
        if(isset($capabilitySources[$ref])&&$office!==null&&isset($coverage[$office]))$family=$office;
        $state=$coverage[$family]??'';
        if($family==='specialist_reports'){
            $native=explode(':',$ref,3)[1]??'';
            $specialist=vp3_coordination_source_registry_v1()[$native]['specialist']??'';
            $state=$coverage['specialist_'.$specialist]??$state;
            // Other verified families can still inform a fresh assessment.
            // Assignments and delivery always require their exact revisions.
            if($omitStaleActivity){
                unset($sources[$ref]);
                continue;
            }
        }
        if($family==='specialist_results'&&$omitStaleActivity){
            unset($sources[$ref]);
            continue;
        }
        if($omitStaleActivity&&$family==='eyes_visit'){
            unset($sources[$ref]);
            continue;
        }
        if($family==='activity_situations'&&$state==='limited'){
            // The bounded advisory feed can lose or revise a saved item while
            // all of its readers are healthy. Only a new Assessment can omit it.
            if(!$omitStaleActivity)throw new Vp3InitiativeSourcesChangedV1();
            unset($sources[$ref]);
            continue;
        }
        if(in_array($state,['unavailable','limited'],true)){
            throw new Vp3InitiativeSourceProofUnavailableV1($family,$state);
        }
        $base=$current[$ref]['canonical_source_revision']??$current[$ref]['revision']??null;
        if(isset($canonicalSources[$ref])&&$canonicalSources[$ref]===$base&&($coverage['activity_context']??'')==='unavailable'){
            throw new Vp3InitiativeSourceProofUnavailableV1('activity_context','unavailable');
        }
        throw new Vp3InitiativeSourcesChangedV1();
    }
    return array_intersect_key($current,$sources);
}

/** A completed tool's retrieved records/artifact must still be current at delivery. */
function vp3_initiative_result_current_v1(PDO $pdo,array $user,array $binding,array $goal,array $toolResult,?array $evidence=null): void
{
    if(($goal['tool']??null)==='youtube.playback_offer'){
        $evidence=vp3_initiative_work_sources_v1($pdo,$user,$binding,$goal);
        if(vp3_initiative_youtube_offer_result_v1($evidence[$goal['primary']])!==$toolResult)throw new Vp3InitiativeSourcesChangedV1();
        return;
    }
    if(isset($goal['followup'])){require_once __DIR__.'/agent-initiative-followups-v1.php';vp3_initiative_followup_job_current_v1($pdo,$user,$goal);}
    if(isset($goal['followthrough'])){
        require_once __DIR__.'/agent-initiative-followthrough-v1.php';
        vp3_initiative_followthrough_job_current_v1($pdo,$user,$goal);
    }
    if(($goal['tool']??null)==='coordination.review'){
        require_once __DIR__.'/agent-coordination-work-v1.php';
        vp3_coordination_result_evidence_v1($pdo,$user,$binding,$goal,$toolResult);
        return;
    }
    if(($goal['tool']??null)==='work.prepare_private'&&isset($toolResult['preparation_history_revision'])){
        require_once __DIR__.'/agent-initiative-engagement-memory-v1.php';
        $evidence??=vp3_initiative_work_sources_v1($pdo,$user,$binding,$goal);
        $history=vp3_initiative_preparation_history_v1($pdo,$user,$binding,$evidence);
        if(!is_string($toolResult['preparation_history_revision'])
            ||!hash_equals($toolResult['preparation_history_revision'],vp3_initiative_preparation_history_revision_v1($history))){
            throw new Vp3InitiativeSourcesChangedV1();
        }
    }
    if(($goal['tool']??'work.prepare_private')!=='chat.check_in'&&!isset($toolResult['output'])&&!isset($toolResult['tool_receipt']))return;
    require_once __DIR__.'/agent-initiative-tools-v1.php';
    if(!function_exists('vp3_initiative_tools_result_current_v1'))throw new RuntimeException('Initiative result verification is unavailable.',503);
    vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$goal,$toolResult);
}

function vp3_initiative_work_sources_v1(PDO $pdo,array $user,array $binding,array $goal,bool $omitStaleActivity=false): array
{
    if(($goal['tool']??null)==='youtube.playback_offer'){
        require_once __DIR__.'/agent-initiative-youtube-offer-v1.php';
        return vp3_initiative_youtube_offer_evidence_v1($pdo,$user,$binding,$goal);
    }
    if(($goal['tool']??null)==='coordination.review'){
        require_once __DIR__.'/agent-coordination-work-v1.php';
        $items=vp3_coordination_read_v1($pdo,$user,$binding,$goal['sources']);
        vp3_coordination_task_context_current_v1($pdo,$user,$goal,$items);
        return $items;
    }
    if(($goal['office_entry']??null)===true){
        require_once __DIR__.'/agent-initiative-tools-v1.php';
        require_once __DIR__.'/agent-initiative-office-entry-work-v1.php';
        return vp3_initiative_office_entry_evidence_v1($pdo,vp3_initiative_tools_scope_v1($pdo,$user,$binding),$binding,$goal);
    }
    $current=vp3_initiative_current_sources_v1($pdo,$user,$goal['sources'],!empty($binding['screen_enabled']),
        $goal['canonical_sources']??[],vp3_initiative_binding_agent_v1($binding),$goal['capability_sources']??[],$omitStaleActivity);
    if(is_string($goal['tool']??null))vp3_initiative_result_job_v1($goal+['refs'=>array_keys($current)],$current);
    return $current;
}

function vp3_initiative_heartbeat_current_v1(array $row): bool
{
    // Both wall-clock values use the SQL session clock. PHP and SQL may use
    // different time zones on the same host.
    $heartbeat=vp3_initiative_sql_timestamp_v1($row['last_heartbeat_at']??null);
    $checked=vp3_initiative_sql_timestamp_v1($row['checked_at']??null);
    return $heartbeat>0&&$checked>0&&$heartbeat>=$checked-90&&$heartbeat<=$checked+5;
}

function vp3_initiative_owner_present_v1(PDO $pdo,array $user,int $cid,bool $requireQuietHeartbeat=false): bool
{
    return vp3_initiative_presence_v1($pdo,$user,$cid,$requireQuietHeartbeat)==='quiet';
}

function vp3_initiative_presence_v1(PDO $pdo,array $user,int $cid,bool $requireQuietHeartbeat=false,?array &$observation=null): string
{
    $observation=null;
    require_once __DIR__.'/agent-activity-v94.php';
    if(!agent_activity_v94_schema_ready())return 'unavailable';
    $stmt=$pdo->prepare('SELECT surface,context_key,activity_state,last_heartbeat_at,details_json,
        CURRENT_TIMESTAMP AS checked_at FROM agent_activity_state WHERE user_id=?');
    $stmt->execute([(int)$user['id']]);
    $activity=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$activity)return 'no_heartbeat';
    if(($activity['surface']??null)!=='chat'||($activity['context_key']??null)!=='chat:'.$cid)return 'other_chat';
    if(!vp3_initiative_heartbeat_current_v1($activity))return 'stale';
    if(!in_array($activity['activity_state']??null,['working','paused','idle'],true))return 'not_focused';
    $details=json_decode((string)$activity['details_json'],true);
    if(!is_array($details)||(int)($details['conversation_id']??0)!==$cid)return 'other_chat';
    if(func_num_args()>=5){
        $age=vp3_initiative_sql_timestamp_v1($activity['checked_at'])-vp3_initiative_sql_timestamp_v1($activity['last_heartbeat_at']);
        $observation=['age_seconds'=>max(0,$age),'chat_focused'=>($details['chat_focused']??null)===true,
            'busy_reason'=>($details['chat_busy']??null)===true
                ?agent_activity_v94_busy_reason($details['chat_busy_reason']??null):null];
    }
    if(empty($details['visible']))return 'not_visible';
    if(array_key_exists('chat_focused',$details)||array_key_exists('chat_busy',$details)){
        // Silent text can be useful while the owner is reading. Actual pending
        // Chat/Voice work and focus remain separate from general activity.
        if(($details['chat_focused']??null)!==true)return 'not_focused';
        return ($details['chat_busy']??null)===false?'quiet':'busy';
    }
    return !$requireQuietHeartbeat&&!empty(agent_activity_v94_snapshot($user,'chat')['interruptible'])?'quiet':'busy';
}

/** Keep the private preparation receipt and its eventual Chat delivery atomic. */
function vp3_initiative_delivery_result_v1(PDO $pdo,int $owner,array $row,array $result): void
{
    $json=agent_workflow_json_v1400($result);
    $pdo->prepare("UPDATE agent_workflow_actions SET result_json=?,updated_at=UTC_TIMESTAMP()
        WHERE id=? AND run_id=? AND owner_user_id=? AND status='completed'")
        ->execute([$json,(int)$row['action_id'],(int)$row['run_id'],$owner]);
    $pdo->prepare("UPDATE agent_workflow_receipts SET result_json=?
        WHERE id=? AND action_id=? AND run_id=? AND owner_user_id=? AND status='completed'")
        ->execute([$json,(int)$row['receipt_id'],(int)$row['action_id'],(int)$row['run_id'],$owner]);
}

function vp3_initiative_report_v1(PDO $pdo,array $user,int $cid,string $draft,array $evidence,int $runId,array $toolResult=[],?array $meetingPrep=null): int
{
    $links=[];
    if($meetingPrep===null){
        foreach($evidence as $row){
            if(($row['specialist']??null)==='casual')continue;
            $links[]=['source'=>$row['ref'],'title'=>$row['title'],'url'=>$row['url']];
        }
    }
    $research=$evidence[$toolResult['output']['source_ref']??'']['research']??null;
    foreach($research['knowledge']??[] as $alias){
        $links[]=['source'=>'knowledge:'.$alias['knowledge_id'],'title'=>'Knowledge · '.$alias['folder_name'],'url'=>$alias['url']];
    }
    foreach(is_array($toolResult['sources']??null)?array_slice($toolResult['sources'],0,16):[] as $source){
        if(!is_array($source)||!is_string($source['source']??null)||!is_string($source['title']??null))continue;
        $link=['source'=>mb_substr($source['source'],0,190),'title'=>mb_substr($source['title'],0,190)];
        if(is_string($source['url']??null)&&str_starts_with($source['url'],'/')&&!str_starts_with($source['url'],'//')
            &&!str_contains($source['url'],'\\'))$link['url']=mb_substr($source['url'],0,1900);
        if(in_array($toolResult['output']['type']??null,['initiative_artist_research','initiative_topic_research'],true)
            &&is_string($source['url']??null)
            &&strlen($source['url'])<=1900&&str_starts_with($source['url'],'https://')
            &&in_array($source['coverage']??null,['page_excerpt','search_match'],true)){
            $link['url']=$source['url'];
            $link['coverage']=$source['coverage'];
        }
        $links[]=$link;
    }
    $context=['source'=>'agent_initiative','skip_brain_archive'=>true,'sources'=>$links,'initiative_run_id'=>$runId];
    if($meetingPrep!==null)$context['initiative_meeting_prep']=$meetingPrep['key'];
    $agentId=vp3_agent_memory_scope_current_v410($user);
    if(!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$user['id'],$agentId>0?['id'=>$agentId]:null,true)){
        throw new RuntimeException('Initiative conversation changed.',403);
    }
    $actions=$toolResult['output']['review_actions']??(isset($toolResult['output']['review_action'])?[$toolResult['output']['review_action']]:[]);
    $reviews=[];
    foreach(is_array($actions)?array_slice($actions,0,8):[] as $action){
        if(!is_array($action)||!in_array($action['tool']??'', ['product','screen','mission','marketing'],true)
            ||!is_string($action['source_id']??null)||$action['source_id']===''||strlen($action['source_id'])>190
            ||!is_string($action['source_revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$action['source_revision']))continue;
        $reviews[]=['tool'=>$action['tool'],'source_id'=>$action['source_id'],'source_revision'=>$action['source_revision']];
    }
    $context['initiative_work']=['owner_user_id'=>(int)$user['id'],'agent_id'=>$agentId,
        'conversation_id'=>$cid,'run_id'=>$runId,'review_actions'=>$reviews];
    if(is_array($toolResult['tool_receipt']??null))$context['tool_receipt']=$toolResult['tool_receipt'];
    if(is_array($toolResult['output']??null))$context['initiative_output']=$toolResult['output'];
    if(isset($toolResult['output']['gmail_reply_draft_id'])){
        require_once __DIR__.'/agent-coordination-gmail-draft-v1.php';
        $context['actions']=[vp3_coordination_gmail_draft_action_v1($pdo,$user,$agentId,$cid,$toolResult['output']['gmail_reply_draft_id'])];
    }
    $playlist=null;
    if(($toolResult['output']['playlist_run_id']??null)===$runId){
        require_once __DIR__.'/agent-initiative-youtube-playlist-v1.php';
        require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
        if(agent_connection_youtube_results_ready_v1($pdo)){
            $playlist=vp3_initiative_youtube_playlist_load_v1($pdo,(int)$user['id'],$runId);
            if($playlist!==null)$context['youtube_search_results_v1']=true;
        }
    }
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,NULL,'assistant',?,?,NOW())")->execute([$cid,$draft,json_encode($context,JSON_THROW_ON_ERROR)]);
    $messageId=(int)$pdo->lastInsertId();
    if($playlist!==null){
        agent_connection_youtube_results_save_v1($pdo,(int)$user['id'],$cid,$messageId,$playlist);
        vp3_initiative_youtube_playlist_remove_v1($pdo,(int)$user['id'],$runId);
    }
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    vp3_initiative_followthrough_attach_v1($pdo,$user,$agentId,$cid,$runId,$messageId,$draft,$context);
    $q=$pdo->prepare('SELECT goal FROM agent_workflow_runs WHERE id=? AND owner_user_id=?');$q->execute([$runId,(int)$user['id']]);$reportGoal=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    if(isset($reportGoal['followup'])){require_once __DIR__.'/agent-initiative-followups-v1.php';vp3_initiative_followup_delivered_v1($pdo,$user,$reportGoal,$runId,$messageId);}
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,(int)$user['id']]);
    return $messageId;
}

/** Deliver verified results to the linked Chat without another inference request. */
function vp3_initiative_deliver_pending_v1(PDO $pdo,array $user,?int $agentId=null): bool
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_deliver_pending_scoped_v1($pdo,$user));
}

function vp3_initiative_pending_results_v1(PDO $pdo,int $owner,?array $agent,bool $deliveryOnly=false,bool $deferMedia=false): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $column,string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $pending=$json('a.result_json','$.initiative_delivery');
    $reason=$json('a.result_json','$.initiative_delivery_reason');
    $specialist=$json('r.goal','$.coordination_route.specialist');
    $tool=$json('r.goal','$.tool');
    $action=$json('r.goal','$.coordination_route.action');
    $priority=$json('r.goal','$.coordination_priority');
    $followthrough=$json('r.goal','$.followthrough');
    $followup=$json('r.goal','$.followup');
    $coordinationWork=$json('r.goal','$.coordination_work');
    $transcript="$tool='coordination.review' AND $specialist='transcripts'
        AND $followthrough IS NULL AND $followup IS NULL AND $coordinationWork IS NULL";
    $explicit="$tool='coordination.review' AND $specialist IN ('gmail','planning','tasks','canvas','connected-sites')
        AND ($action='ask' OR ($action='report' AND $priority='high'))";
    $mediaHold=$deferMedia?"CASE WHEN $specialist='media' THEN 1 ELSE 0 END,":'';
    $held="COALESCE($reason,'') IN ('result_unavailable','transcript_pacing','delivery_held')";
    $checkedAt='COALESCE(a.updated_at,a.completed_at)';
    $ready='';$dueOrder='';$params=[];
    if($deliveryOnly){
        $ready="AND NOT ($held AND $checkedAt>?)
            AND HEX(p.result_json)=HEX(a.result_json)";
        // Give fresh, usable work priority without permanently parking old retries.
        $dueOrder="CASE WHEN $checkedAt<=? THEN 0 WHEN $held THEN 2 ELSE 1 END,
            CASE WHEN $checkedAt<=? THEN $checkedAt END ASC,";
        $agedCutoff=gmdate('Y-m-d H:i:s',time()-120);
        $params=[gmdate('Y-m-d H:i:s',time()-30),$agedCutoff,$agedCutoff];
    }
    [$agentSql,$agentParams]=vp3_agent_chat_scope_sql_v380($agent,'r');
    $agentSql=str_replace('r.user_agent_id','r.agent_id',$agentSql);
    $stmt=$pdo->prepare("SELECT r.id AS run_id,a.id AS action_id,p.id AS receipt_id,r.goal,r.source_key,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        LEFT JOIN agent_workflow_receipts p ON p.id=(SELECT receipt.id FROM agent_workflow_receipts receipt
            WHERE receipt.run_id=r.id AND receipt.action_id=a.id AND receipt.owner_user_id=r.owner_user_id
            AND receipt.attempt_no=a.attempt_count AND receipt.executor='cloud' AND receipt.status='completed'
            ORDER BY receipt.id DESC LIMIT 1)
        WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND $agentSql
            AND r.status='completed' AND a.status='completed' AND a.capability_key='initiative.prepare'
            AND a.sequence_no=1 AND a.action_key='initiative.prepare' AND a.execution_target='cloud'
        AND $pending='pending' $ready
        ORDER BY $mediaHold $dueOrder
            CASE WHEN $specialist IN ('casual','curiosity') THEN 4 WHEN $specialist='media' THEN 3
            WHEN $explicit THEN 0
            WHEN $transcript THEN 3
            WHEN $tool='coordination.review' THEN 1 ELSE 2 END,a.updated_at ASC,a.id ASC LIMIT 25");
    $stmt->execute([$owner,...$agentParams,...$params]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_initiative_proactive_specialist_v1(array $goal): bool
{
    if(isset($goal['followthrough'])||isset($goal['followup']))return false;
    $tool=$goal['tool']??null;
    if(in_array($tool,['chat.check_in','work.prepare_private'],true)&&is_string($goal['primary']??null)
        &&str_starts_with($goal['primary'],'specialist:profile:'))return true;
    if(in_array($tool,['chat.check_in','work.prepare_private'],true)&&is_string($goal['primary']??null)
        &&str_starts_with($goal['primary'],'specialist:marketing:')
        &&vp3_product_marketing_ref_kind_v1(substr($goal['primary'],11))!==null)return true;
    if($tool==='chat.check_in'&&is_array($goal['sources']??null)&&array_filter(array_keys($goal['sources']),
        static fn(string $ref):bool=>str_starts_with($ref,'specialist:transcript:')))return true;
    if($tool==='work.prepare_private'&&is_string($goal['primary']??null)
        &&str_starts_with($goal['primary'],'specialist:transcript:'))return true;
    if($tool==='work.prepare_private')$tool=$goal['capability_sources'][$goal['primary']??'']??$tool;
    return in_array($tool,['tasks.read','tasks.lists.read','reminders.read','calendar.read'],true);
}

function vp3_initiative_delivery_error_v1(Throwable $error,int $owner,int $runId): int
{
    if($error instanceof RuntimeException&&in_array($error->getCode(),[403,409,503],true))return $error->getCode();
    if(function_exists('agent_runtime_v125_trace')){
        try{agent_runtime_v125_trace('initiative.saved_delivery_candidate_failed',
            ['user_id'=>$owner,'run_id'=>$runId,'error_class'=>get_class($error)]
            +vp3_initiative_coordination_failure_v1($error,'saved_delivery'));}
        catch(Throwable $ignored){}
    }
    return 503;
}

function vp3_initiative_deliver_pending_scoped_v1(PDO $pdo,array $user): bool
{
    if($pdo->inTransaction())return false;
    $agentId=vp3_agent_memory_scope_current_v410($user);
    $agent=$agentId>0?['id'=>$agentId]:null;
    $policy=vp3_initiative_policy_v1($user);
    if(empty($policy['enabled']))return false;
    $live=$user;$owner=(int)$user['id'];$cid=(int)$policy['conversation_id'];
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-initiative-capabilities-v1.php';
    require_once __DIR__.'/agent-media-entertainment-v1.php';
    // Bound source reads before locking. One unavailable specialist must not
    // prevent a ready report from another specialist reaching the linked Chat.
    // Media shares an account cooldown across offers. Put that lane behind
    // ready work before either query limit cuts off the pending results.
    try{$deferMedia=!vp3_media_cooldown_ready_v1($pdo,$owner);}
    catch(PDOException){
        // Keep other deliveries independent of a Media settings read. Its
        // authoritative cooldown and proof checks still govern any Media post.
        $deferMedia=true;
    }
    $candidates=vp3_initiative_pending_results_v1($pdo,$owner,$agent,true,$deferMedia);
    if(!$candidates)return false;
    $preflights=[];
    foreach(array_slice($candidates,0,4) as $row){
        $goal=json_decode((string)$row['goal'],true);$result=json_decode((string)$row['result_json'],true);
        $coordination=($goal['tool']??null)==='coordination.review';
        $preflight=['receipt_id'=>(int)$row['receipt_id'],'hash'=>hash('sha256',$row['goal']."\0".$row['result_json']),'reason'=>0];
        if(is_array($result)&&is_array($goal['sources']??null)&&!vp3_initiative_proactive_specialist_v1($goal)
            &&($coordination||vp3_initiative_capability_sources_present_v1($goal['sources']))&&($goal['binding']??null)===$policy){
            try{
                $live=vp3_initiative_access_v1($pdo,$live,$policy);
                if(!$coordination)vp3_initiative_work_sources_v1($pdo,$live,$policy,$goal);
                vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
            }catch(Throwable $error){
                $preflight['reason']=vp3_initiative_delivery_error_v1($error,$owner,(int)$row['run_id']);
            }
        }
        $preflights[(int)$row['action_id']]=$preflight;
    }
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$live,$owner,$cid,$agent,$agentId,$policy,$preflights,$deferMedia):bool{
        vp3_initiative_owner_lock_v1($pdo,$live);
        $revoked=false;
        try{$live=vp3_initiative_access_v1($pdo,$live,$policy);}
        catch(RuntimeException $e){if($e->getCode()!==403)throw $e;$revoked=true;}
        if(!$revoked&&!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agent,true))$revoked=true;
        $rows=vp3_initiative_pending_results_v1($pdo,$owner,$agent,true,$deferMedia);
        $delivered=false;$transcriptPaced=null;
        foreach($rows as $row){
            $preflight=$preflights[(int)$row['action_id']]??null;
            if(!$preflight||(int)$row['receipt_id']!==$preflight['receipt_id']
                ||!hash_equals($preflight['hash'],hash('sha256',$row['goal']."\0".$row['result_json'])))continue;
            $goal=json_decode((string)$row['goal'],true);
            $result=json_decode((string)$row['result_json'],true);
            $coordination=($goal['tool']??null)==='coordination.review';
            if(!is_array($result))$result=[];
            $original=$result;
            if(is_array($goal)&&vp3_initiative_proactive_specialist_v1($goal)){
                $result['initiative_delivery']='withheld';
                $result['initiative_delivery_reason']='specialist_report';
                vp3_initiative_delivery_result_v1($pdo,$owner,$row,$result);
                continue;
            }
            if(!$revoked&&is_array($goal['sources']??null)&&($coordination||vp3_initiative_capability_sources_present_v1($goal['sources']))
                &&($goal['binding']??null)===$policy){
                if($preflight['reason']===503){
                    // Defer the next source read without repeating inference.
                    $result['initiative_delivery_reason']='result_unavailable';
                    vp3_initiative_delivery_result_v1($pdo,$owner,$row,$result);
                    continue;
                }
                if(in_array($preflight['reason'],[403,409],true)){
                    $result['initiative_delivery']='withheld';
                    $result['initiative_delivery_reason']=$preflight['reason']===403?'policy_changed':'source_changed';
                    vp3_initiative_delivery_result_v1($pdo,$owner,$row,$result);continue;
                }
            }
            $draft=$result['initiative_draft']??null;
            if($revoked||($goal['binding']??null)!==$policy||empty($goal['report'])||!is_array($goal['sources']??null)
                ||!$goal['sources']||!is_string($draft)||$draft===''||mb_strlen($draft)>vp3_initiative_limits_v1()['max_draft_chars']){
                $result['initiative_delivery']='withheld';
                $result['initiative_delivery_reason']=$revoked||($goal['binding']??null)!==$policy?'policy_changed':'result_unavailable';
            }else{
                $failureReason='source_changed';
                $pdo->exec('SAVEPOINT initiative_saved_candidate');
                try{
                    if($coordination){
                        require_once __DIR__.'/agent-coordination-work-v1.php';
                        if(vp3_coordination_verified_run_v1($pdo,$live,['id'=>$row['run_id'],'goal'=>$row['goal'],
                            'source_key'=>$row['source_key'],'status'=>'completed'])!==$result){
                            $failureReason='result_unavailable';
                            throw new RuntimeException('Top of Mind result could not be verified.',409);
                        }
                        $evidence=vp3_coordination_result_evidence_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
                        $failureReason='rule_changed';
                        vp3_coordination_route_current_v1($pdo,$live,$goal,$evidence);
                        $failureReason=vp3_coordination_native_presentation_owned_v1($goal,$evidence)?'native_presentation':'duplicate';
                        if(!vp3_coordination_should_report_v1($pdo,$live,$goal,(int)$row['run_id'],$result['initiative_tool_result'],$evidence)){
                            throw new RuntimeException('Top of Mind delivery changed.',409);
                        }
                    }else{
                        $evidence=vp3_initiative_work_sources_v1($pdo,$live,$policy,$goal);
                        vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[],$evidence);
                    }
                    unset($result['initiative_delivery_reason']);
                    $failureReason='source_changed';
                    $transcriptHold=null;
                    if($coordination&&($goal['coordination_route']['specialist']??null)==='transcripts'
                        &&vp3_coordination_transcript_automatic_v1($goal)){
                        $transcriptHold=vp3_coordination_transcript_handoff_hold_v1($pdo,$live,$policy,$evidence[$goal['primary']],$transcriptPaced);
                    }
                    $meetingPrep=empty($goal['followthrough'])&&($goal['tool']??null)==='calendar.read'
                        ?($evidence[$goal['primary']]['meeting_prep']??null):null;
                    if($transcriptHold==='transcript_history'){
                        $result['initiative_delivery']='private';
                        $result['initiative_delivery_reason']='transcript_history';
                    }elseif($transcriptHold!==null){
                        $result['initiative_delivery_reason']=$transcriptHold;
                    }elseif($meetingPrep!==null&&!vp3_initiative_meeting_prep_eligible_v1($pdo,$owner,$agentId,$meetingPrep)){
                        $result['initiative_delivery']='withheld';
                        $result['initiative_delivery_reason']='meeting_changed';
                    }elseif(vp3_initiative_suggestion_delivery_ready_v1($pdo,$live,$goal)){
                        if(($goal['coordination_route']['specialist']??null)==='media')vp3_media_chat_record_v1($pdo,$live,$goal);
                        $result['initiative_message_id']=vp3_initiative_report_v1($pdo,$live,$cid,$draft,$evidence,(int)$row['run_id'],$result['initiative_tool_result']??[],$meetingPrep);
                        vp3_initiative_suggestion_record_v1($pdo,$live,$goal,$result['initiative_message_id'],$evidence);
                        $result['initiative_delivery']='delivered';
                        agent_workflow_event_v1400($pdo,$owner,(int)$row['run_id'],'initiative_reported','completed','completed','system',
                            'Private preparation delivered to the linked Chat.',['message_id'=>$result['initiative_message_id']]);
                        $delivered=true;
                    }else $result['initiative_delivery_reason']='delivery_held';
                }catch(Throwable $error){
                    // A candidate can fail after inserting a message. Roll it back
                    // before postponing that row or trying another specialist.
                    if(!$pdo->inTransaction()||($error instanceof PDOException
                        &&(in_array((int)($error->errorInfo[1]??0),[1020,1213],true)||(string)$error->getCode()==='40001')))throw $error;
                    $pdo->exec('ROLLBACK TO SAVEPOINT initiative_saved_candidate');
                    $result=$original;
                    $code=vp3_initiative_delivery_error_v1($error,$owner,(int)$row['run_id']);
                    if($code===503){
                        $result['initiative_delivery_reason']='result_unavailable';
                    }else{
                        $result['initiative_delivery']='withheld';
                        $result['initiative_delivery_reason']=$code===403?'policy_changed':$failureReason;
                    }
                }
                $pdo->exec('RELEASE SAVEPOINT initiative_saved_candidate');
            }
            // Refresh bounded retry timing even when a gate remains unchanged.
            if($result!==$original||($result['initiative_delivery']??null)==='pending'){
                vp3_initiative_delivery_result_v1($pdo,$owner,$row,$result);
            }
            if($delivered)break;
        }
        return $delivered;
    });
}

function vp3_initiative_delivery_sweep_v1(PDO $pdo,int $limit): int
{
    $pending="JSON_EXTRACT(CASE WHEN JSON_VALID(a.result_json) THEN a.result_json ELSE '{}' END,'$.initiative_delivery')";
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$pending="JSON_UNQUOTE($pending)";
    $stmt=$pdo->prepare("SELECT r.owner_user_id,COALESCE(r.agent_id,0) AS agent_id FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        JOIN users u ON u.id=r.owner_user_id AND u.is_active=1
        JOIN agent_memory_items p ON p.user_id=r.owner_user_id AND COALESCE(p.user_agent_id,0)=COALESCE(r.agent_id,0)
            AND p.memory_type='initiative_policy' AND p.subject='owner-opt-in' AND p.is_active=1
        WHERE r.source_kind='agent_initiative' AND r.status='completed'
            AND a.status='completed' AND a.capability_key='initiative.prepare' AND $pending='pending'
            AND CAST(JSON_EXTRACT(CASE WHEN JSON_VALID(p.metadata_json) THEN p.metadata_json ELSE '{}' END,'$.enabled') AS CHAR) IN ('true','1')
        GROUP BY r.owner_user_id,COALESCE(r.agent_id,0) ORDER BY MIN(COALESCE(a.updated_at,a.completed_at)),r.owner_user_id LIMIT ".max(1,min(25,$limit)));
    $stmt->execute();$delivered=0;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $owner=(int)$row['owner_user_id'];$agentId=(int)$row['agent_id'];
        $user=agent_cognitive_loop_v310_user($owner);
        if(!$user)continue;
        try{if(vp3_initiative_deliver_pending_v1($pdo,$user,$agentId))$delivered++;}
        catch(Throwable $e){agent_runtime_v125_trace('initiative.delivery_failed',['user_id'=>(int)$owner,'error_class'=>get_class($e)]);}
    }
    return $delivered;
}

function vp3_initiative_can_report_v1(PDO $pdo,array $user,int $cid): bool
{
    return vp3_initiative_report_gate_v1($pdo,$user,$cid)==='ready';
}

/** Timestamped text uses the saved owner Chat binding. Browser activity governs voice separately. */
function vp3_initiative_report_gate_v1(PDO $pdo,array $user,int $cid,?int $agentId=null): string
{
    $owner=(int)($user['id']??0);
    if($owner<1||$cid<1||vp3_participant_scope_v1()!==null)return 'unavailable';
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    $policy=vp3_initiative_policy_v1($user,$agentId);
    if(!$policy)return 'unavailable';
    if(($policy['enabled']??false)!==true)return 'paused';
    if(($policy['conversation_id']??null)!==$cid)return 'linked_elsewhere';
    if(!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agentId>0?['id'=>$agentId]:null))return 'unavailable';
    return 'ready';
}

function vp3_initiative_suggestion_delivery_ready_v1(PDO $pdo,array $user,array $goal): bool
{
    $specialist=$goal['coordination_route']['specialist']??null;
    if($specialist==='media'){
        require_once __DIR__.'/agent-media-entertainment-v1.php';
        return vp3_media_chat_ready_v1($pdo,$user,$goal);
    }
    if($specialist==='casual'){
        require_once __DIR__.'/agent-casual-checkin-v1.php';
        require_once __DIR__.'/agent-coordination-schedule-v1.php';
        $trigger=$goal['coordination_route']['trigger']??'';
        $independentOfPrivateWork=in_array($trigger,VP3_CASUAL_MEDIA_KINDS_V1,true)||isset(VP3_SPECIALIST_STARTERS_V1[$trigger]);
        return vp3_casual_checkin_delivery_ready_v1($pdo,$user,$goal['binding']['conversation_id'],$trigger)
            &&!vp3_coordination_pending_v1($pdo,$user,$goal['binding'],savedOnly:true,chatOnly:$independentOfPrivateWork);
    }
    if($specialist==='curiosity'){
        require_once __DIR__.'/agent-coordination-sources-v1.php';
        $binding=$goal['binding'];
        if(vp3_coordination_curiosity_gate_v1($pdo,$user,$binding)!=='ready')return false;
        require_once __DIR__.'/agent-curiosity-v1.php';
        $next=vp3_curiosity_report_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding),$binding['conversation_id'])['next_question']??null;
        return is_array($next)&&$goal['primary']==='curiosity:'.$next['thread_id'].':'.$next['id'];
    }
    return true;
}

/** Persist the invitation in the same transaction that posts it. */
function vp3_initiative_suggestion_record_v1(PDO $pdo,array $user,array $goal,int $messageId,array $evidence): void
{
    $specialist=$goal['coordination_route']['specialist']??null;
    $binding=$goal['binding'];
    if($specialist==='casual'){
        vp3_casual_checkin_record_v1($pdo,(int)$user['id'],$goal['coordination_route']['trigger'],
            $binding['conversation_id'],$messageId,$evidence[$goal['primary']]);
    }elseif($specialist==='curiosity'){
        require_once __DIR__.'/agent-curiosity-v1.php';
        [, $thread,$question]=explode(':',$goal['primary'],3);
        vp3_curiosity_record_offer_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding),$binding['conversation_id'],
            $thread,$question,$messageId);
    }
}

/** Classify the failure without storing an exception message or provider response. */
function vp3_initiative_failure_cause_v1(Throwable $error): string
{
    return match(true){
        $error instanceof Vp3InitiativeSourcesChangedV1=>'source_changed',
        $error instanceof Vp3InitiativeSourceProofUnavailableV1=>'source_unavailable',
        $error instanceof Vp3InitiativePublicSearchUnavailableV1=>'public_search_unavailable',
        $error instanceof PDOException=>'storage_error',
        $error instanceof JsonException,$error instanceof InvalidArgumentException=>'invalid_data',
        $error instanceof RuntimeException&&in_array((int)$error->getCode(),[403,404],true)=>'access_changed',
        $error instanceof RuntimeException&&(int)$error->getCode()===409=>'conflict',
        default=>'unconfirmed',
    };
}

/** Use the existing leased worker and commit the result with its workflow receipt. */
function vp3_initiative_execute_v1(PDO $pdo,array $user,array $claim,?callable $model=null,?array $researchTransport=null,bool $allowDelivery=true): array
{
    $run=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);
    if(!$run||$run['source_kind']!=='agent_initiative'||!in_array($run['action_capability_key'],['initiative.plan','initiative.prepare'],true)){
        return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
    }
    try{
        $payload=json_decode((string)$run['goal'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($payload)||!is_array($payload['binding']??null)||!is_array($payload['sources']??null))throw new RuntimeException('Initiative binding changed.',403);
        $agentId=vp3_initiative_binding_agent_v1($payload['binding']);
        if(($run['agent_id']===null?0:(int)$run['agent_id'])!==$agentId)throw new RuntimeException('Initiative Agent changed.',403);
        return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$claim,$model,$run,$payload,$researchTransport,$allowDelivery):array{
            if(($payload['tool']??null)==='connected-sites.scan'){
                require_once __DIR__.'/agent-initiative-connected-sites-v1.php';
                return vp3_initiative_connected_sites_execute_v1($pdo,$user,$claim,$run,$payload);
            }
            return vp3_initiative_execute_scoped_v1($pdo,$user,$claim,$model,$run,$payload,$researchTransport,$allowDelivery);
        });
    }catch(Throwable $e){
        if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
        $reason=vp3_initiative_failure_reason_v1($e);
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Initiative work could not be verified.',
            ['failure_phase'=>'binding','failure_cause'=>vp3_initiative_failure_cause_v1($e)],$reason,false);
        return ['ok'=>false,'reason'=>$reason,'receipt'=>$receipt];
    }
}

function vp3_initiative_execute_scoped_v1(PDO $pdo,array $user,array $claim,?callable $model,array $run,array $payload,?array $researchTransport=null,bool $allowDelivery=true): array
{
    $toolResult=[];$draft='';$jobs=[];$opportunities=[];$discarded=0;$youtubePlaylist=null;$finding=null;
    $gmailReplyDraft=null;
    $failurePhase='binding';
    $sourcePreflightPassed=false;
    $sourcePreflightBlocked=false;
    $planning=$run['action_capability_key']==='initiative.plan';
    $canvasDiscovery=$planning&&($payload['opportunity_lane']??null)==='app_suggestions';
    try{
        $binding=$payload['binding'];$sources=$payload['sources'];
        $playbackOffer=($payload['tool']??null)==='youtube.playback_offer';
        $coordination=($payload['tool']??null)==='coordination.review';
        $reportOnly=$payload['report_only']??null;
        $canvasCandidates=$payload['canvas_candidate_sources']??null;
        if(($sources===[]||array_key_exists('report_only',$payload))
            &&(!$planning||$sources!==[]||!is_string($reportOnly)||!preg_match('/\A[a-f0-9]{64}\z/D',$reportOnly))){
            throw new RuntimeException('Initiative report binding is invalid.',403);
        }
        if($canvasDiscovery&&(!is_array($canvasCandidates)||!array_is_list($canvasCandidates)||!$canvasCandidates
            ||count($canvasCandidates)>8||array_filter($canvasCandidates,static fn(mixed $ref):bool=>!is_string($ref)||!isset($sources[$ref])))){
            throw new RuntimeException('Canvas discovery sources changed.',409);
        }
        $agentId=vp3_initiative_binding_agent_v1($binding);
        $canonicalSources=is_array($payload['canonical_sources']??null)?$payload['canonical_sources']:[];
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        $officeEntry=($payload['office_entry']??null)===true;
        $officeReview=in_array($payload['tool']??null,['tasks.read','tasks.lists.read','reminders.read'],true);
        $officeTask=null;
        if(isset($payload['office_agent_task'])){
            require_once __DIR__.'/agent-initiative-office-entry-work-v1.php';
            $officeTask=vp3_initiative_office_agent_task_parent_v1($pdo,$live,$payload);
        }
        $failurePhase='sources';
        $lastStage=[];
        $stage=static function(string $message)use($pdo,&$live,$claim,&$lastStage):void{
            $now=time();
            if($lastStage===[$message,$now])return;
            if(!agent_job_heartbeat_v1900($pdo,$live,(int)$claim['run_id'],(int)$claim['action']['id'],
                (string)$claim['lease_token'],0,$message,240)){
                throw new RuntimeException('Initiative lease changed.',409);
            }
            $lastStage=[$message,$now];
        };
        $stage($coordination||$planning?'Reviewing specialist findings.':'Advancing an assigned private next step.');
        $continuation=null;$revisit=null;
        if(isset($payload['followup'])){require_once __DIR__.'/agent-initiative-followups-v1.php';$revisit=vp3_initiative_followup_job_current_v1($pdo,$live,$payload,(int)$run['id']);}
        if(isset($payload['followthrough'])){
            require_once __DIR__.'/agent-initiative-followthrough-v1.php';
            $continuation=vp3_initiative_followthrough_job_current_v1($pdo,$live,$payload,(int)$run['id']);
        }
        $evidence=$reportOnly===null
            ?vp3_initiative_work_sources_v1($pdo,$live,$binding,$payload,omitStaleActivity:$planning&&!$canvasDiscovery):[];
        $activityOmitted=$planning?count($sources)-count($evidence):0;
        if($activityOmitted>0){
            if(!$evidence)throw new Vp3InitiativeSourcesChangedV1();
            $sources=$payload['sources']=array_intersect_key($sources,$evidence);
            $canonicalSources=array_intersect_key($canonicalSources,$sources);
            foreach(['canonical_sources','capability_sources'] as $field){
                if(isset($payload[$field]))$payload[$field]=array_intersect_key($payload[$field],$sources);
            }
        }
        $specialistReports=[];$specialistAuthority=[];
        if($planning){
            $evidence=array_filter($evidence,static fn(array $source):bool=>($source['kind']??null)!=='email_canvas_opportunity'
                ||($source['email_canvas']['discovery_qualified']??null)===true);
            if(!$canvasDiscovery){
                require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
                $specialistReports=$reportOnly===null
                    ?vp3_initiative_specialist_reports_v1($pdo,$live,$binding,$evidence,$specialistAuthority)
                    :vp3_initiative_specialist_report_only_v1($pdo,$live,$binding,$reportOnly);
            }
        }
        $modelEvidence=array_map(static function(array $row):array{
            if(($row['kind']??null)==='transcript_research'){
                $folders=array_unique(array_column($row['research']['knowledge']??[],'folder_name'));
                $row['research']=array_intersect_key($row['research'],array_flip(['question','next_step','kind']));
                if($folders)$row['research']['knowledge_folders']=array_values($folders);
            }
            return array_diff_key($row,['url'=>true,'revision'=>true,'canonical_source_revision'=>true,'brainstorm_context'=>true]);
        },array_values($evidence));
        $checkLease=static function()use($pdo,&$live,$claim):void{
            if(!agent_worker_cloud_active_row_v1910($pdo,$live,$claim))throw new RuntimeException('Initiative lease changed.',409);
        };
        $transientContext=(bool)array_filter($evidence,static fn(array $row):bool=>in_array($row['kind']??null,['live_context','eyes_visit'],true));
        $verifyBeforeInference=($payload['tool']??null)==='brainstorm.explore'||$coordination||$officeReview||$transientContext||$activityOmitted>0||(bool)array_filter($evidence,
            static fn(array $row):bool=>in_array($row['kind']??'',['transcript_research','curiosity_finding','specialist_finding','specialist_result'],true));
        $verifySources=static function()use($pdo,&$live,$binding,$sources,$payload,$checkLease):void{
            $currentUser=vp3_initiative_access_v1($pdo,$live,$binding);
            if($sources)vp3_initiative_work_sources_v1($pdo,$currentUser,$binding,$payload);
            $checkLease();
        };
        $preparationHistory=[];
        if(!$planning&&($payload['tool']??null)==='work.prepare_private'&&$continuation===null&&$revisit===null){
            require_once __DIR__.'/agent-initiative-engagement-memory-v1.php';
            $preparationHistory=vp3_initiative_preparation_history_v1($pdo,$live,$binding,$evidence);
        }
        $beforeInference=$verifyBeforeInference||$specialistReports||$preparationHistory
            ?static function()use($verifySources,$specialistReports,$pdo,&$live,$binding,$specialistAuthority,$reportOnly,$checkLease,
                $preparationHistory,$evidence,&$sourcePreflightPassed,&$sourcePreflightBlocked):void{
                try{
                    $verifySources();
                    if($preparationHistory&&vp3_initiative_preparation_history_v1($pdo,$live,$binding,$evidence)!==$preparationHistory){
                        throw new Vp3InitiativeSourcesChangedV1();
                    }
                    if($reportOnly!==null)vp3_initiative_specialist_report_only_v1($pdo,$live,$binding,$reportOnly);
                    elseif($specialistReports)vp3_initiative_specialist_reports_current_v1($pdo,$live,$binding,$specialistAuthority);
                    $checkLease();
                }catch(Vp3InitiativeSourceProofUnavailableV1 $error){
                    // A later repair or Cloud fallback can follow an already attempted request.
                    $sourcePreflightBlocked=!$sourcePreflightPassed;
                    throw $error;
                }
                $sourcePreflightPassed=true;
            }:null;
        $job=['background'=>true,'run_id'=>(int)$run['id'],'conversation_id'=>(int)$binding['conversation_id']];
        $provider=$model??static function(string $prompt,string $protocol)use($pdo,&$live,$agentId,$transientContext,$beforeInference,$job):string{
            return vp3_initiative_model_v1($pdo,$live,$prompt,$protocol,$agentId,$transientContext,$beforeInference,$job);
        };
        $workStage=match($payload['tool']??null){
            'artist.research','topic.research'=>'Researching the selected source.',
            'canvas.prepare'=>'Brainstorming a private Canvas concept.',
            'brainstorm.explore'=>'Exploring ideas across specialist briefs.',
            'image.prepare'=>'Preparing an image concept.',
            'coordination.review'=>'Coordinating specialist findings.',
            default=>'Preparing a verified private next step.',
        };
        $inferenceStage=$planning
            ?($canvasDiscovery?'Brainstorming a private Canvas concept.':'Assessing priorities across specialists.')
            :$workStage;
        $infer=static function(string $prompt,string $protocol)use($pdo,&$live,$binding,$payload,$run,$provider,$checkLease,$stage,$inferenceStage,$model,$beforeInference):string{
            if($pdo->inTransaction())throw new RuntimeException('Initiative inference cannot hold a database transaction.');
            $live=vp3_initiative_access_v1($pdo,$live,$binding);
            if(isset($payload['followthrough']))vp3_initiative_followthrough_job_current_v1($pdo,$live,$payload,(int)$run['id']);
            if(isset($payload['followup']))vp3_initiative_followup_job_current_v1($pdo,$live,$payload,(int)$run['id']);
            $checkLease();
            $stage($inferenceStage);
            if($model!==null&&$beforeInference!==null)$beforeInference();
            return $provider($prompt,$protocol);
        };
        $limits=vp3_initiative_limits_v1();
        $tools=$reportOnly!==null?[]:['work.prepare_private'=>['id'=>'work.prepare_private','ready'=>true,'mode'=>'prepare',
            'description'=>'Prepare a private source-backed next step without changing the source.']];
        $toolFile=__DIR__.'/agent-initiative-tools-v1.php';
        if($reportOnly===null&&!$coordination&&!$playbackOffer&&is_file($toolFile)){
            require_once $toolFile;
            $tools=vp3_initiative_tools_catalog_v1($pdo,$live,$binding,$evidence)+$tools;
        }
        if($canvasDiscovery){
            $tools=array_intersect_key($tools,['canvas.prepare'=>true]);
            if(empty($tools['canvas.prepare']['ready']))throw new RuntimeException('Canvas preparation is unavailable.',503);
            $tools['canvas.prepare']['source_refs']=array_values(array_intersect($tools['canvas.prepare']['source_refs'],$canvasCandidates));
            $tools['canvas.prepare']['ready']=$tools['canvas.prepare']['source_refs']!==[];
        }
        $topicRefs=!empty($tools['topic.research']['ready'])?$tools['topic.research']['source_refs']:[];
        $ob1Inputs=[];
        if($planning){
            if(!$canvasDiscovery){
                $stage('Coordinating specialist findings.');
                $ob1Inputs=vp3_initiative_brainstorm_inputs_v1($evidence,$specialistReports);
            }
            $failurePhase='assessment';
            require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';
            require_once __DIR__.'/agent-inference-budget-v1.php';
            $budget=$reportOnly===null?$limits+['max_opportunities'=>8]:array_replace($limits,['max_jobs'=>0,'max_opportunities'=>0]);
            $request=['evidence'=>$modelEvidence,'tools'=>$tools,'budget'=>$budget,
                'canvas_feedback'=>vp3_initiative_canvas_feedback_v1($pdo,$live,$agentId,$evidence),
                'owner_context'=>vp3_initiative_owner_context_v1($pdo,$live,$binding)];
            $request['specialists']=vp3_initiative_specialist_plan_context_v1($pdo,$live,$binding,$tools,$evidence);
            if($specialistReports)$request['specialist_reports']=$specialistReports;
            if($canvasDiscovery){
                $request['budget']=array_replace($budget,['max_jobs'=>1,'max_opportunities'=>3]);
                $request['canvas_review']='Review the available specialist findings, saved Canvas inventory and owner feedback. '
                    .'Return up to three distinct, practical workflows that would benefit from an interactive app or an improvement to an existing app. '
                    .'For each, identify the interaction, the verified need and the exact sources that support it. '
                    .'Use Canvas inventory to avoid duplicating an app. An exact update to an existing Canvas starts from verified owner feedback. '
                    .'Do not assume existing app data was read or that a source grants live access from a future app. '
                    .'Return no ideas if the sources do not establish a useful workflow. '
                    .'Include at most one job, for the strongest idea worth preparing for owner review now, with the exact same primary and refs and report set to true.';
            }
            $allowed=array_keys(array_filter($tools,static fn(array $entry):bool=>!empty($entry['ready'])));
            $assessment=vp3_ai_with_budget_v1(55,static function()use($request,$infer,$verifySources,$evidence,$allowed,$tools,$canvasDiscovery):array{
                $assess=static function(array $input)use($infer,$evidence,$allowed,$tools,$canvasDiscovery):array{
                    $answer=$infer(json_encode($input,JSON_THROW_ON_ERROR),'vp3.initiative-plan.v1');
                    try{
                        $plan=vp3_initiative_assessment_v1($answer,$evidence,$allowed,$tools,$input['specialist_reports']??[]);
                        return $canvasDiscovery?vp3_initiative_canvas_assessment_v1($plan):$plan;
                    }catch(InvalidArgumentException|JsonException $error){
                        throw new Vp3InitiativeInvalidAssessmentV1($error,strlen($answer));
                    }
                };
                try{
                    return $assess($request);
                }catch(Vp3InitiativeInvalidAssessmentV1 $error){
                    if($error->diagnostic!=='invalid_job_sources'||vp3_ai_budget_remaining_v1()<1)throw $error;
                    $verifySources();
                    // A fresh plan may use only verified input, with no rejected model output.
                    $request['source_repair']=$canvasDiscovery
                        ?'Use exact available refs and only the supplied Canvas tool. Match the job to one verified opportunity, or leave jobs empty.'
                        :'Use exactly one eligible primary reference per job and opportunity, with refs equal to [primary]. Skip entries without an eligible source.';
                    return $assess($request);
                }
            });
            $jobs=$assessment['jobs'];$opportunities=$assessment['opportunities'];
            $discarded=$assessment['opportunities_discarded']??0;
            $finding=$assessment['finding']??null;
            $stage('Checking the plan and assigning eligible next steps.');
        }else{
            $failurePhase='tool';
            $tool=$payload['tool']??'work.prepare_private';
            if(!$coordination&&!$playbackOffer&&(!is_string($tool)||!array_key_exists($tool,$tools)||($tool!=='work.prepare_private'&&empty($tools[$tool]['ready'])))){
                throw new RuntimeException('Initiative tool is unavailable.',403);
            }
            $stage($workStage);
            if($playbackOffer){
                $toolResult=vp3_initiative_youtube_offer_result_v1($evidence[$payload['primary']]);
            }elseif($coordination){
                if($continuation!==null&&($payload['gmail_reply_draft']??null)===true){
                    require_once __DIR__.'/agent-coordination-gmail-draft-v1.php';
                    $prepared=vp3_coordination_gmail_draft_prepare_v1($pdo,$live,$payload,$evidence,$continuation,$infer);
                    $toolResult=$prepared['result'];
                    $gmailReplyDraft=$prepared['draft'];
                }else{
                    $toolResult=vp3_coordination_prepare_v1($pdo,$live,$payload,(int)$run['id'],$evidence,$continuation,$infer);
                }
            }elseif($tool==='work.prepare_private'){
                $request=['response_kind'=>'private_preparation','focus'=>$payload['focus'],'primary'=>$payload['primary']??array_key_first($sources),
                    'evidence'=>$modelEvidence,'budget'=>$limits];
                if($preparationHistory)$request['related_owner_decisions']=$preparationHistory;
                if($revisit!==null)$request['owner_followup']=['kind'=>'scheduled_proposal_reassessment','focus'=>$revisit['focus'],'current_context'=>$revisit['current_context'],'permission'=>'Fresh private reassessment only. Scheduling did not approve any original action.'];
                if($continuation!==null)$request['owner_continuation']=['kind'=>'owner_requested_private_continuation',
                    'proposal_text'=>mb_substr((string)$continuation['message']['message'],0,2400),
                    'owner_reply'=>mb_substr((string)$continuation['continuation_owner_reply'],0,4000),
                    'permission'=>'Private preparation with the original current sources only; no domain action or approval.'];
                $answer=$infer(json_encode($request,JSON_THROW_ON_ERROR),'vp3.initiative-draft.v1');
                $toolResult=vp3_tool_json_object_v1($answer,['draft'],$limits['max_draft_bytes']);
                if($preparationHistory)$toolResult['preparation_history_revision']=vp3_initiative_preparation_history_revision_v1($preparationHistory);
            }else{
                $job=['tool'=>$tool,'primary'=>$payload['primary']??array_key_first($sources),'refs'=>array_keys($sources),
                    'focus'=>$payload['focus'],'report'=>$payload['report']];
                if($officeReview&&($claim['action']['error_class']??null)==='model_unavailable'
                    &&in_array($claim['action']['result']['model_diagnostic']??null,['response_empty','response_too_large','invalid_response'],true)){
                    $job['office_format_retry']=true;
                }
                if($continuation!==null)$job['owner_continuation']=['kind'=>'owner_requested_private_continuation',
                    'proposal_text'=>mb_substr((string)$continuation['message']['message'],0,2400),
                    'owner_reply'=>mb_substr((string)$continuation['continuation_owner_reply'],0,4000),
                    'permission'=>$tool==='canvas.prepare'
                        ?'Build only the exact owner-requested private Canvas draft with the selected coding model and tokens. No activation, publication, external purchase, or permission grant.'
                        :'The original readonly adapter and current sources only; private preparation, no domain action or approval.'];
                if($officeEntry){
                    $job['office_entry']=true;
                    if($officeTask!==null)$job['office_agent_task']=$officeTask;
                    $scope=vp3_initiative_tools_scope_v1($pdo,$live,$binding);
                    $toolResult=vp3_initiative_capability_execute_v1($pdo,$scope,$binding,$job,$evidence,$infer);
                }else{
                    $toolResult=vp3_initiative_tool_execute_v1($pdo,$live,$binding,$job,$evidence,(string)$run['dedupe_key'],$infer,$checkLease,$researchTransport,
                        ['run_id'=>(int)$run['id'],'dedupe'=>(string)$run['dedupe_key']]);
                }
                $youtubePlaylist=$toolResult['youtube_playlist']??null;
                unset($toolResult['youtube_playlist']);
            }
            $failurePhase='validation';
            $draft=is_string($toolResult['draft']??null)?trim($toolResult['draft']):'';
            if($draft===''||mb_strlen($draft)>$limits['max_draft_chars']||preg_match('/https?:\/\/|\bwww\.|[<>]|[\x00-\x08\x0b-\x1f\x7f]/i',$draft)){
                throw new InvalidArgumentException('Invalid private draft.');
            }
        }

        $failurePhase='sources';
        require_once __DIR__.'/agent-initiative-capabilities-v1.php';
        $capabilityBound=$coordination||vp3_initiative_capability_sources_present_v1($sources);
        if($reportOnly!==null||$finding!==null)$beforeInference();
        if($capabilityBound){
            // Refresh any live remote privacy proof before opening SQL locks.
            // The transaction repeats exact source checks without provider/network work.
            vp3_initiative_work_sources_v1($pdo,$live,$binding,$payload);
        }
        if(!$planning&&$allowDelivery&&!empty($payload['report'])&&!vp3_initiative_proactive_specialist_v1($payload)
            &&!in_array($toolResult['output']['office_disposition']??null,['ignore','note'],true)
            &&($toolResult['output']['public_topic_unavailable']??false)!==true
            &&vp3_initiative_can_report_v1($pdo,$live,(int)$binding['conversation_id'])){
            $stage('Presenting the verified result.');
        }
        $commit=static function()use($pdo,$live,$binding,$sources,$canonicalSources,$planning,$canvasDiscovery,$jobs,$opportunities,$discarded,$activityOmitted,$finding,$ob1Inputs,$claim,$payload,$toolResult,$draft,$run,$agentId,$youtubePlaylist,$topicRefs,$reportOnly,$specialistAuthority,$officeEntry,$officeTask,$coordination,$gmailReplyDraft,$allowDelivery,&$failurePhase):array{
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$binding);
        $evidence=$reportOnly===null
            ?vp3_initiative_work_sources_v1($pdo,$live,$binding,$payload):[];
        if($reportOnly!==null)vp3_initiative_specialist_report_only_v1($pdo,$live,$binding,$reportOnly);
        elseif($finding!==null)vp3_initiative_specialist_reports_current_v1($pdo,$live,$binding,$specialistAuthority);
        $failurePhase='commit';
        if($planning){
            if($activityOmitted>0){
                $active=agent_worker_cloud_active_row_v1910($pdo,$live,$claim,true);
                if(!$active||$active['goal']!==$run['goal'])throw new RuntimeException('Initiative assessment changed.',409);
                // Save the exact evidence used so Top of Mind and later readers
                // verify the completed assessment against the same sources.
                $pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=? AND owner_user_id=?')
                    ->execute([json_encode($payload,JSON_THROW_ON_ERROR),(int)$run['id'],(int)$live['id']]);
            }
            $fallback=$canvasDiscovery?null:vp3_initiative_transcript_research_fallback_v1($pdo,$live,$binding,$jobs,$evidence,$topicRefs);
            if($fallback!==null)$jobs[]=$fallback;
            $admitted=0;$admissions=[];
            foreach($jobs as $job){
                $meetingPrep=$evidence[$job['primary']]['meeting_prep']??null;
                if($meetingPrep!==null&&!vp3_initiative_meeting_prep_eligible_v1($pdo,(int)$live['id'],$agentId,$meetingPrep))continue;
                $selected=array_intersect_key($sources,array_flip($job['refs']));
                $work=['binding'=>$binding,'sources'=>$selected,'canonical_sources'=>array_intersect_key($canonicalSources,$selected),
                    'capability_sources'=>vp3_initiative_capability_source_map_v1($evidence,$job['refs']),
                    'tool'=>$job['tool'],'primary'=>$job['primary'],'focus'=>$job['focus'],'report'=>$job['report']];
                if($canvasDiscovery)$work['opportunity_lane']='app_suggestions';
                if(($evidence[$job['primary']]['kind']??null)==='transcript_research'){
                    require_once __DIR__.'/agent-transcript-research-policy-v1.php';
                    $work['transcript_research_queue_key']=vp3_initiative_transcript_research_queue_key_v1($binding,
                        $evidence[$job['primary']],vp3_transcript_research_grant_v1($pdo,(int)$live['id']));
                }
                if(($evidence[$job['primary']]['kind']??null)==='email_canvas_opportunity'){
                    $hash=vp3_initiative_email_canvas_attempt_key_v1($pdo,(int)$live['id'],$binding,$job['primary']);
                    if($hash===null)continue;
                    $work['email_canvas_work_key']=vp3_initiative_email_canvas_work_key_v1($job['primary']);
                }elseif(($evidence[$job['primary']]['kind']??null)==='eyes_visit'){
                    $assessment=vp3_initiative_eyes_assignment_v1($pdo,(int)$live['id'],$job['primary'],true);
                    if(!empty($assessment['receipt_verified'])&&($assessment['result']['eyes_visit_ref']??null)===$job['primary'])continue;
                    $hash=hash('sha256','eyes-check-in|'.$job['primary']);
                }elseif(vp3_initiative_result_ref_v1($job['primary'])){
                    $hash=vp3_initiative_result_assignment_key_v1($binding,$job['tool'],$selected);
                }else{
                    $hash=hash('sha256',json_encode([$binding,$job['tool'],$job['primary'],$selected],JSON_THROW_ON_ERROR));
                }
                $queued=vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',$work,$hash);
                if($queued)$admitted++;
                $admissions[vp3_initiative_brainstorm_identity_v1($job['tool'],$job['primary'],$selected)]=$queued?'queued':'existing';
            }
            $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'OB1 assessment recorded.',
                ['effect_state'=>$admitted?'assignments_queued':($jobs?'existing_assignments':'idle'),'jobs'=>$admitted,
                    'initiative_tool_result'=>vp3_initiative_brainstorm_result_v1($opportunities,$evidence,$admissions,$finding,$ob1Inputs)]
                +(isset($evidence[$payload['eyes_visit_ref']??''])?['eyes_visit_ref'=>$payload['eyes_visit_ref']]:[])
                +($discarded>0?['opportunities_discarded'=>$discarded]:[])
                +($activityOmitted>0?['activity_sources_omitted'=>$activityOmitted]:[]));
        }else{
            $cid=(int)$binding['conversation_id'];$uid=(int)$live['id'];
            if(($payload['tool']??'')==='canvas.prepare'){
                require_once __DIR__.'/agent-initiative-canvas-v1.php';
                $toolResult=vp3_initiative_canvas_commit_v1($pdo,$live,$binding,$payload,$toolResult,
                    ['run_id'=>(int)$run['id'],'dedupe'=>(string)$run['dedupe_key']]);
                $draft=$toolResult['draft'];
                if(!is_string($draft)||$draft===''||mb_strlen($draft)>vp3_initiative_limits_v1()['max_draft_chars']
                    ||preg_match('/https?:\/\/|\bwww\.|[<>]|[\x00-\x08\x0b-\x1f\x7f]/i',$draft)){
                    throw new InvalidArgumentException('Invalid private Canvas report.');
                }
            }
            vp3_initiative_result_current_v1($pdo,$live,$binding,$payload,$toolResult,$evidence);
            if(!vp3_agent_chat_conversation_v380($pdo,$cid,$uid,$agentId>0?['id'=>$agentId]:null,true))throw new RuntimeException('Initiative conversation changed.',403);
            if($officeTask!==null)vp3_initiative_office_agent_task_parent_v1($pdo,$live,$payload);
            if($officeEntry)vp3_initiative_office_agent_task_enqueue_v1($pdo,$live,$payload,(int)$run['id'],$toolResult);
            if($youtubePlaylist!==null){
                $runId=(int)$run['id'];
                vp3_initiative_youtube_playlist_store_v1($pdo,$uid,$runId,$youtubePlaylist);
                $toolResult['output']['playlist_run_id']=$runId;
            }
            $delivery=empty($payload['report'])?'private':'pending';$messageId=0;
            $deliveryReason=$delivery==='private'?'private':null;
            $transcriptDeferred=false;
            if($coordination){
                $linked=vp3_coordination_linked_situation_v1($pdo,$live,$payload,(int)$run['id'],$toolResult);
                if($linked!==$payload['coordination_id']){
                    if(!agent_worker_cloud_active_row_v1910($pdo,$live,$claim,true))throw new RuntimeException('Initiative lease changed.',409);
                    $payload['coordination_id']=$linked;
                    $toolResult['output']['situation_id']=$linked;
                    $pdo->prepare('UPDATE agent_workflow_runs SET source_key=?,goal=? WHERE id=? AND owner_user_id=?')
                        ->execute(['coordination:'.$linked,json_encode($payload,JSON_THROW_ON_ERROR),(int)$run['id'],$uid]);
                }
                $evidence=vp3_coordination_result_evidence_v1($pdo,$live,$binding,$payload,$toolResult);
                vp3_coordination_route_current_v1($pdo,$live,$payload,$evidence);
                if($gmailReplyDraft!==null){
                    if(!agent_worker_cloud_active_row_v1910($pdo,$live,$claim,true))throw new RuntimeException('Initiative lease changed.',409);
                    vp3_initiative_followthrough_job_current_v1($pdo,$live,$payload,(int)$run['id']);
                    vp3_coordination_gmail_draft_store_v1($pdo,$live,$payload,$evidence,$gmailReplyDraft);
                }
                vp3_coordination_queue_work_v1($pdo,$live,$payload,(int)$run['id'],$toolResult);
                if(!vp3_coordination_should_report_v1($pdo,$live,$payload,(int)$run['id'],$toolResult,$evidence)){
                    $delivery='private';
                    $deliveryReason=vp3_coordination_native_presentation_owned_v1($payload,$evidence)?'native_presentation'
                        :(in_array($payload['coordination_route']['action']??null,['ask','report'],true)?'duplicate':'private');
                }
                if($delivery==='pending'&&($payload['coordination_route']['specialist']??null)==='transcripts'
                    &&vp3_coordination_transcript_automatic_v1($payload)){
                    // Sort through the same pending queue as other specialists.
                    $transcriptDeferred=true;
                    $deliveryReason=vp3_coordination_transcript_handoff_hold_v1($pdo,$live,$binding,$evidence[$payload['primary']]);
                    if($deliveryReason==='transcript_history')$delivery='private';
                }
            }
            if(vp3_initiative_proactive_specialist_v1($payload)){
                $delivery='private';
                $deliveryReason='specialist_report';
            }
            if(in_array($toolResult['output']['office_disposition']??null,['ignore','note'],true)){
                $delivery='private';
                $deliveryReason='private';
            }
            if(($toolResult['output']['public_topic_unavailable']??false)===true){
                $delivery='private';
                $deliveryReason='result_unavailable';
            }
            $meetingPrep=empty($payload['followthrough'])&&($payload['tool']??null)==='calendar.read'
                ?($evidence[$payload['primary']]['meeting_prep']??null):null;
            if($meetingPrep!==null&&!vp3_initiative_meeting_prep_eligible_v1($pdo,$uid,$agentId,$meetingPrep)){
                $delivery='withheld';
                $deliveryReason='meeting_changed';
            }
            if($delivery==='pending'&&!$transcriptDeferred&&$allowDelivery&&vp3_initiative_can_report_v1($pdo,$live,$cid)
                &&vp3_initiative_suggestion_delivery_ready_v1($pdo,$live,$payload)){
                $failurePhase='delivery';
                if(($payload['coordination_route']['specialist']??null)==='media')vp3_media_chat_record_v1($pdo,$live,$payload);
                $messageId=vp3_initiative_report_v1($pdo,$live,$cid,$draft,$evidence,(int)$run['id'],$toolResult,$meetingPrep);
                vp3_initiative_suggestion_record_v1($pdo,$live,$payload,$messageId,$evidence);
                $delivery='delivered';
                $failurePhase='commit';
            }
            $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'Private preparation completed.',
                ['effect_state'=>'private_draft_ready','initiative_draft'=>$draft,'initiative_tool_result'=>$toolResult,
                    'initiative_delivery'=>$delivery,'initiative_message_id'=>$messageId]
                    +($deliveryReason!==null?['initiative_delivery_reason'=>$deliveryReason]:[]));
            if(!isset($payload['followthrough'])&&!isset($payload['followup'])
                &&($officeEntry||!empty($toolResult['output']))){
                require_once __DIR__.'/agent-coordination-source-change-v1.php';
                vp3_coordination_source_changed_v1($pdo,$uid,$agentId);
            }
            if(isset($payload['followthrough']))vp3_initiative_followthrough_complete_v1($pdo,$live,$payload,(int)$run['id']);
        }
        return $receipt;
        };
        if(isset($payload['followthrough'])||$capabilityBound){
            require_once __DIR__.'/agent-initiative-followthrough-v1.php';
            $receipt=vp3_initiative_followthrough_transaction_v1($pdo,$commit);
        }
        else{$pdo->beginTransaction();$receipt=$commit();$pdo->commit();}
        return ['ok'=>true,'reason'=>'completed','receipt'=>$receipt];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
        $retained=array_diff_key($toolResult,['draft'=>true]);
        $withheld=$retained?['effect_state'=>'tool_result_withheld','initiative_tool_result'=>$retained,'initiative_delivery'=>'withheld']:[];
        // Don't spend a second model request when the first assessment already completed.
        $sourceUnavailable=$e instanceof Vp3InitiativeSourceProofUnavailableV1
            &&($planning||($coordination??false)||$e->family==='specialist_results')
            &&($failurePhase==='sources'||($planning&&$failurePhase==='assessment'&&$sourcePreflightBlocked))
            &&$toolResult===[]&&!isset($assessment);
        $officeResponseRejected=$failurePhase==='tool'&&$e instanceof Vp3InitiativeModelDiagnosticV1
            &&in_array($e->diagnostic,['response_empty','response_too_large','invalid_response'],true)
            &&!isset($payload['followthrough'])&&!isset($payload['followup'])
            &&in_array($payload['tool']??null,['tasks.read','tasks.lists.read','reminders.read'],true);
        $publicSearchUnavailable=$failurePhase==='tool'&&$e instanceof Vp3InitiativePublicSearchUnavailableV1;
        $retryable=(($e instanceof Vp3InitiativeTransientInferenceV1||$officeResponseRejected||$publicSearchUnavailable)
            &&$toolResult===[])||$sourceUnavailable;
        if($retryable){
            // A transport outage cannot turn a concurrently revoked policy or
            // changed source into permission to queue another inference attempt.
            try{
                $retryUser=vp3_initiative_access_v1($pdo,$user,$binding);
                if(isset($payload['followup']))vp3_initiative_followup_job_current_v1($pdo,$retryUser,$payload,(int)$run['id']);
                if($publicSearchUnavailable&&isset($payload['followthrough'])){
                    vp3_initiative_followthrough_job_current_v1($pdo,$retryUser,$payload,(int)$run['id']);
                }
                if(($reportOnly??null)!==null)vp3_initiative_specialist_report_only_v1($pdo,$retryUser,$binding,$reportOnly);
                elseif(!$sourceUnavailable)vp3_initiative_work_sources_v1($pdo,$retryUser,$binding,$payload);
            }catch(Throwable $scopeError){$retryable=false;$e=$scopeError;}
        }
        $reason=vp3_initiative_failure_reason_v1($e,$planning);
        $withheld['failure_phase']=$failurePhase;
        $withheld['failure_cause']=vp3_initiative_failure_cause_v1($e);
        if($e instanceof Vp3InitiativeSourceProofUnavailableV1){
            $withheld+=Vp3InitiativeSourceProofUnavailableV1::diagnostic($e->family,$e->coverage);
        }
        if($e instanceof Vp3InitiativeModelDiagnosticV1){
            $withheld['model_diagnostic']=$e->diagnostic;
            if($e->httpStatus!==null)$withheld['model_http_status']=$e->httpStatus;
        }
        if($e instanceof Vp3InitiativeOfficeReportFormatV1)$withheld['office_report_error']=$e->format;
        if($reason==='assessment_unverified'&&$e instanceof Vp3InitiativeInvalidAssessmentV1){
            $withheld['assessment_error']=$e->diagnostic;
            if($e->outputBytes<=65536)$withheld['assessment_output_bytes']=$e->outputBytes;
        }
        $summary=$reason==='assessment_sources_changed'?'The sources changed during assessment.':'Initiative work could not be verified.';
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,$summary,$withheld,$reason,$retryable);
        if($planning&&!$canvasDiscovery&&$reason==='assessment_sources_changed'&&empty($receipt['duplicate'])){
            try{vp3_initiative_brainstorm_wake_changed_v1($pdo,(int)$user['id'],$binding);}
            catch(PDOException $error){
                if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('initiative.ob1_refresh_wake_unavailable',
                    ['user_id'=>(int)$user['id'],'error_class'=>get_class($error)]);
            }
        }
        if(isset($payload['followthrough'])){
            try{vp3_initiative_followthrough_failed_v1($pdo,$user,$payload,(int)$run['id']);}
            catch(Throwable $ignored){}
        }
        return ['ok'=>false,'reason'=>$reason,'receipt'=>$receipt];
    }
}
