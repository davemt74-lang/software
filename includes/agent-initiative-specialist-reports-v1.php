<?php
declare(strict_types=1);

/** Gmail monitoring is owner-wide. Reuse its verified destination to limit assessment to the linked Chat. */
function vp3_initiative_specialist_gmail_scope_v1(PDO $pdo,array $user,array $binding): ?array
{
    if(!table_exists('agent_connection_gmail_inbox_v1'))return null;
    require_once __DIR__.'/agent-connection-gmail-inbox-status-v1.php';
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    $scope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if($scope['state']!=='ready'||!$scope['provider_ready']||$scope['generation']===null
        ||!agent_connection_gmail_inbox_batches_ready_v1($pdo))return null;
    $destination=agent_connection_gmail_inbox_update_destination_v1($pdo,$scope);
    return ($destination['agent_id']??null)===vp3_initiative_binding_agent_v1($binding)
        &&($destination['conversation_id']??null)===$binding['conversation_id']?$scope:null;
}

function vp3_initiative_specialist_gmail_authority_v1(array $scope): string
{
    return agent_connection_gmail_inbox_key_v1($scope['policy'],'initiative-report-v1:'.$scope['generation']);
}

function vp3_initiative_specialist_research_sources_v1(PDO $pdo,array $user,array $binding,array $refs,int $now,
    ?array &$coverage=null): array
{
    $coverage=[];
    if(!$refs)return [];
    $scope=vp3_initiative_tools_scope_v1($pdo,$user,$binding);
    $at=new DateTimeImmutable('@'.$now);
    return vp3_initiative_research_evidence_v1($pdo,$scope,$at,$refs,$coverage)
        +vp3_initiative_task_research_evidence_v1($pdo,$scope,$refs,$coverage)
        +vp3_initiative_transcript_research_evidence_v1($pdo,$scope,$binding,$at,$refs,$coverage);
}

/** Only current authorized research sources can carry a saved worker report into the assessment. */
function vp3_initiative_specialist_research_reports_v1(PDO $pdo,array $user,array $binding,array $evidence,int $now,array &$extraSources,
    array &$reportKeys,bool $completedOnly): array
{
    require_once __DIR__.'/agent-initiative-research-status-v1.php';
    $owner=(int)$user['id'];
    $agentId=vp3_initiative_binding_agent_v1($binding);
    $rows=vp3_initiative_research_status_candidates_v1($pdo,$owner,$agentId,$binding,$now,1800,$completedOnly);
    $candidates=[];$missingRefs=[];
    foreach($rows as $row){
        $goal=json_decode((string)$row['goal'],true);
        $primary=$goal['primary']??null;
        $kind=match($goal['tool']??null){'topic.research'=>'topic','artist.research'=>'artist',default=>null};
        if(($goal['binding']??null)!==$binding||($goal['report']??null)!==true||$kind===null||!is_string($primary)
            ||!vp3_public_research_source_ref_valid_v1($kind,$primary)
            ||!is_array($goal['sources']??null)||array_keys($goal['sources'])!==[$primary]
            ||!is_string($goal['sources'][$primary])||!preg_match('/\A[a-f0-9]{64}\z/D',$goal['sources'][$primary]))continue;
        $candidates[]=['row'=>$row,'goal'=>$goal,'primary'=>$primary];
        if(!isset($evidence[$primary]))$missingRefs[$primary]=true;
    }
    $order=static fn(array $row):array=>[match($row['run_status']){
        'executing'=>0,'queued','approved'=>1,default=>2,
    },-(int)$row['run_id']];
    usort($candidates,static fn(array $left,array $right):int=>$order($left['row'])<=>$order($right['row']));
    // Scheduled assessments exclude already assigned transcript questions from dispatchable evidence.
    $sources=$evidence+vp3_initiative_specialist_research_sources_v1($pdo,$user,$binding,array_keys($missingRefs),$now);
    $reports=[];
    foreach($candidates as ['row'=>$row,'goal'=>$goal,'primary'=>$primary]){
        if(isset($reports[$primary])||($sources[$primary]['revision']??null)!==$goal['sources'][$primary])continue;
        $state=match($row['run_status']){'executing'=>'running','failed'=>'failed','completed'=>'ready',default=>'queued'};
        if($state==='running'&&vp3_top_of_mind_lease_time_v1($row['lease_expires_at'])<=time())continue;
        $report=['specialist'=>'research','state'=>$state,'source_kind'=>$sources[$primary]['kind']];
        if(isset($evidence[$primary]))$report['source_ref']=$primary;
        if($state==='failed'&&!vp3_initiative_research_status_receipt_v1($pdo,$owner,$row))continue;
        if($state==='ready'){
            $completion=vp3_initiative_research_status_completion_v1($pdo,$owner,$agentId,$binding['conversation_id'],$row,$goal,$now,true);
            if($completion===null)continue;
            $result=json_decode((string)$row['result_json'],true);
            $research=vp3_initiative_research_tool_result_v1($result['initiative_tool_result']);
            $report['state']=$completion['state'];
            $report['outcome']=$research['output']['status'];
            $report['checked_at']=$research['output']['checked_at'];
            $report['page_excerpts']=count(array_filter($research['sources'],static fn(array $source):bool=>$source['coverage']==='page_excerpt'));
            $report['search_leads']=count($research['sources'])-$report['page_excerpts'];
            $report['summary']=vp3_top_of_mind_text_v1($research['draft'],750);
            if($report['outcome']==='read_complete'){
                $key=hash('sha256',json_encode(['research',(int)$row['run_id'],$research],JSON_THROW_ON_ERROR));
                $reportKeys[$key]=$report;
            }
        }
        $reports[$primary]=$report;
        if(!isset($evidence[$primary]))$extraSources[$primary]=$sources[$primary]['revision'];
        if(!$completedOnly&&count($reports)>=3)break;
    }
    return array_values($reports);
}

/** Private planning context only. This reads bounded facts and never schedules, delivers, or grants a tool. */
function vp3_initiative_specialist_reports_v1(PDO $pdo,array $user,array $binding,array $evidence,array &$authority,bool $completedOnly=false,bool $includeBrainstorm=true): array
{
    $reports=[];$authority=['research'=>[],'gmail'=>null,'tasks'=>[],'report_keys'=>[],'brainstorm'=>null];$now=time();
    try{$reports=vp3_initiative_specialist_research_reports_v1($pdo,$user,$binding,$evidence,$now,$authority['research'],$authority['report_keys'],$completedOnly);}
    catch(RuntimeException|JsonException $error){$authority['research']=[];$authority['report_keys']=[];}
    try{
        $scope=vp3_initiative_specialist_gmail_scope_v1($pdo,$user,$binding);
        if($scope!==null){
            $monitor=agent_connection_gmail_inbox_background_view_v1($pdo,$scope,null,new DateTimeImmutable('@'.$now),false);
            $report=['specialist'=>'email','state'=>$monitor['state'],'last_checked_at'=>$monitor['last_checked_at']];
            // Read scan totals directly. Do not load subject, body, sender or thread records for Initiative.
            $scan=agent_email_matters_scan_status_v1($pdo,$scope);
            if(($scan['at']??null)!==null){
                $report['latest_completed_scan']=['at'=>$scan['at'],'from'=>$scan['from'],'new'=>$scan['total'],
                    'important'=>$scan['important'],'replies'=>$scan['replies']];
            }
            $authority['gmail']=vp3_initiative_specialist_gmail_authority_v1($scope);
            $scannedAt=strtotime($report['latest_completed_scan']['at']??'');
            if(($scan['total']??0)>0&&$scannedAt!==false&&$scannedAt>=$now-1800&&$scannedAt<=$now){
                $key=hash('sha256',json_encode(['email',$authority['gmail'],$report['latest_completed_scan']],JSON_THROW_ON_ERROR));
                $authority['report_keys'][$key]=$report;
            }
            $reports[]=$report;
        }
    }catch(RuntimeException|JsonException $error){}
    require_once __DIR__.'/agent-curiosity-gmail-draft-v1.php';
    $drafts=vp3_curiosity_gmail_progress_v1($pdo,$user,$binding);
    if($drafts){
        $authority['curiosity_reply_drafts']=$drafts;
        $reports=array_merge($reports,array_values($drafts));
        foreach($drafts as $report)$authority['report_keys'][hash('sha256',json_encode($report,JSON_THROW_ON_ERROR))]=$report;
    }
    if(!$completedOnly){
        require_once __DIR__.'/agent-initiative-task-reports-v1.php';
        try{$reports=array_merge($reports,vp3_initiative_task_reports_v1($pdo,$user,$binding,$now,$authority['tasks']));}
        catch(RuntimeException|JsonException $error){$authority['tasks']=[];}
        // These are already among the current assessment sources. The same
        // saved revisions guard their inclusion and any resulting finding.
        $confirmed=array_filter($evidence,static fn(array $source):bool=>($source['kind']??null)==='curiosity_finding');
        foreach(array_slice($confirmed,0,2,true) as $ref=>$source){
            $reports[]=['specialist'=>'curiosity','state'=>'ready','source_ref'=>$ref,'source_kind'=>'curiosity_finding',
                'summary'=>vp3_top_of_mind_text_v1($source['detail'],750)];
        }
    }
    if($includeBrainstorm){
        require_once __DIR__.'/agent-initiative-curiosity-analysis-v1.php';
        $analysis=vp3_curiosity_analysis_report_v1($pdo,$user,$binding);
        if($analysis!==null){
            $reports[]=$analysis['report'];
            $authority['curiosity_analysis']=array_intersect_key($analysis,array_flip(['run_id','key']));
            $authority['report_keys'][$analysis['key']]=$analysis['report'];
        }
        require_once __DIR__.'/agent-initiative-brainstorm-specialist-v1.php';
        $creative=vp3_brainstorm_report_v1($pdo,$user,$binding);
        if($creative!==null){
            $reports[]=$creative['report'];
            $authority['brainstorm']=array_intersect_key($creative,array_flip(['run_id','key']));
            $authority['report_keys'][$creative['key']]=$creative['report'];
        }
    }
    return $reports;
}

/** Only the exact fresh, meaningful report can back an assessment with no dispatchable evidence. */
function vp3_initiative_specialist_report_only_v1(PDO $pdo,array $user,array $binding,string $reportKey): array
{
    $authority=[];
    vp3_initiative_specialist_reports_v1($pdo,$user,$binding,[],$authority,true);
    if(!isset($authority['report_keys'][$reportKey]))throw new Vp3InitiativeSourcesChangedV1();
    return [$authority['report_keys'][$reportKey]];
}

/** Recheck a saved report's source/consent before an external inference, including a provider fallback. */
function vp3_initiative_specialist_reports_current_v1(PDO $pdo,array $user,array $binding,array $authority): void
{
    if(!empty($authority['curiosity_reply_drafts'])){
        require_once __DIR__.'/agent-curiosity-gmail-draft-v1.php';
        if(vp3_curiosity_gmail_progress_v1($pdo,$user,$binding,array_keys($authority['curiosity_reply_drafts']))!==$authority['curiosity_reply_drafts'])throw new Vp3InitiativeSourcesChangedV1();
    }
    if($authority['gmail']!==null){
        $scope=vp3_initiative_specialist_gmail_scope_v1($pdo,$user,$binding);
        if($scope===null||!hash_equals($authority['gmail'],vp3_initiative_specialist_gmail_authority_v1($scope))){
            throw new Vp3InitiativeSourcesChangedV1();
        }
    }
    $sources=vp3_initiative_specialist_research_sources_v1($pdo,$user,$binding,array_keys($authority['research']),time());
    foreach($authority['research'] as $ref=>$revision){
        if(($sources[$ref]['revision']??null)!==$revision)throw new Vp3InitiativeSourcesChangedV1();
    }
    if(!empty($authority['brainstorm'])){
        require_once __DIR__.'/agent-initiative-brainstorm-specialist-v1.php';
        $current=vp3_brainstorm_report_v1($pdo,$user,$binding);
        if($current===null||array_intersect_key($current,array_flip(['run_id','key']))!==$authority['brainstorm']){
            throw new Vp3InitiativeSourcesChangedV1();
        }
    }
    if(!empty($authority['curiosity_analysis'])){
        require_once __DIR__.'/agent-initiative-curiosity-analysis-v1.php';
        $current=vp3_curiosity_analysis_report_v1($pdo,$user,$binding);
        if($current===null||array_intersect_key($current,array_flip(['run_id','key']))!==$authority['curiosity_analysis']){
            throw new Vp3InitiativeSourcesChangedV1();
        }
    }
    if(!empty($authority['tasks'])){
        require_once __DIR__.'/agent-initiative-task-reports-v1.php';
        vp3_initiative_task_reports_current_v1($pdo,$user,$binding,$authority['tasks'],time());
    }
}
