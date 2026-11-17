<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-followthrough-v1.php';
require_once __DIR__.'/agent-initiative-tools-v1.php';
require_once __DIR__.'/agent-initiative-artist-v1.php';
require_once __DIR__.'/agent-initiative-task-research-v1.php';
require_once __DIR__.'/agent-initiative-transcript-research-v1.php';

/** Use the same scoped evidence readers that protect ordinary research assignments. */
function vp3_initiative_research_candidates_v1(PDO $pdo,array $scope,array $binding,DateTimeImmutable $now,
    ?array $refs,array &$coverage): array
{
    $evidence=vp3_initiative_task_research_evidence_v1($pdo,$scope,$refs,$coverage)
        +vp3_initiative_transcript_research_evidence_v1($pdo,$scope,$binding,$now,$refs,$coverage)
        +vp3_initiative_research_evidence_v1($pdo,$scope,$now,$refs,$coverage);
    return array_filter($evidence,static fn(array $row):bool=>in_array($row['kind'],
        ['task_research','transcript_research','topic_interest','artist_interest'],true));
}

/** Read any active assignment first, otherwise the last admitted family for rotation. */
function vp3_initiative_research_last_assignment_v1(PDO $pdo,int $owner,array $binding): ?array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(goal) THEN goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $lane=$json('$.opportunity_lane');$cid=$json('$.binding.conversation_id');$revision=$json('$.binding.revision');
    $agent=vp3_initiative_binding_agent_v1($binding);
    $agentScope=$agent===0?'(agent_id IS NULL OR agent_id=?)':'agent_id=?';
    $query=$pdo->prepare("SELECT goal,CASE WHEN status IN ('approved','queued','executing') THEN 1 ELSE 0 END AS slot_held
        FROM agent_workflow_runs WHERE owner_user_id=? AND $agentScope
        AND source_kind='agent_initiative' AND capability_key='initiative.prepare'
        AND execution_target='cloud' AND $lane='research' AND CAST($cid AS CHAR)=? AND $revision=?
        ORDER BY slot_held DESC,id DESC LIMIT 1");
    $query->execute([$owner,$agent,(string)$binding['conversation_id'],$binding['revision']]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $goal=json_decode((string)$row['goal'],true);
    if(($goal['binding']??null)!==$binding)return null;
    return ['active'=>(int)$row['slot_held']===1,
        'kind'=>$goal['research_source_kind']??null];
}

/** Give Research a bounded slot independent of an assessment model's choice of assignments. */
function vp3_initiative_schedule_research_v1(PDO $pdo,array $user,array $binding,?DateTimeImmutable $now=null): string
{
    if($pdo->inTransaction()||($binding['enabled']??false)!==true||vp3_participant_scope_v1()!==null)return 'blocked';
    if(!agent_job_engine_schema_ready_v1900($pdo))return 'not_ready';
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    try{
        return vp3_initiative_in_scope_v1($user,vp3_initiative_binding_agent_v1($binding),static function()use($pdo,$user,$binding,$now):string{
            $scope=vp3_initiative_tools_scope_v1($pdo,$user,$binding);
            $owner=(int)$scope['user']['id'];
            if(!empty(vp3_initiative_research_last_assignment_v1($pdo,$owner,$binding)['active']))return 'already_handled';
            $coverage=[];
            // Meeting-origin transcripts may need live policy proof. Prime it before SQL locks.
            $evidence=vp3_initiative_research_candidates_v1($pdo,$scope,$binding,$now,null,$coverage);
            if(!$evidence)return in_array('unavailable',$coverage,true)?'failed':'no_opportunity';
            return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$binding,$now,$owner,$evidence):string{
                vp3_initiative_owner_lock_v1($pdo,$user);
                $scope=vp3_initiative_tools_scope_v1($pdo,$user,$binding);
                $last=vp3_initiative_research_last_assignment_v1($pdo,$owner,$binding);
                if(!empty($last['active']))return 'already_handled';
                $families=['task_research','transcript_research','topic_interest','artist_interest'];
                $previous=array_search($last['kind']??null,$families,true);
                if($previous!==false){
                    $offset=$previous+1;
                    $families=[...array_slice($families,$offset),...array_slice($families,0,$offset)];
                }
                $candidates=vp3_initiative_unassigned_transcript_research_v1($pdo,$owner,$binding,$evidence);
                $handled=count($candidates)<count($evidence);
                $coverage=[];
                foreach($families as $kind){
                    foreach($candidates as $ref=>$candidate){
                        if($candidate['kind']!==$kind)continue;
                        $current=vp3_initiative_research_candidates_v1($pdo,$scope,$binding,$now,[$ref],$coverage)[$ref]??null;
                        if(($current['revision']??null)!==$candidate['revision'])continue;
                        $tool=$kind==='artist_interest'?'artist.research':'topic.research';
                        $revision=$current['revision'];$sources=[$ref=>$revision];
                        $hash=hash('sha256',json_encode([$binding,$tool,$ref,$sources],JSON_THROW_ON_ERROR));
                        $work=['binding'=>$binding,'sources'=>$sources,'canonical_sources'=>[$ref=>$current['canonical_source_revision']??$revision],
                            'capability_sources'=>vp3_initiative_capability_source_map_v1([$ref=>$current],[$ref]),
                            'tool'=>$tool,'primary'=>$ref,'report'=>true,'opportunity_lane'=>'research','research_source_kind'=>$kind,
                            'focus'=>match($kind){
                                'artist_interest'=>'overview',
                                'task_research'=>'Find a verified public next step for this owner-approved topic.',
                                'transcript_research'=>'Check whether public sources can answer this saved transcript question.',
                                default=>'Find one verified public explanation for this topic.',
                            }];
                        if($kind==='transcript_research'){
                            $work['transcript_research_queue_key']=vp3_initiative_transcript_research_queue_key_v1($binding,
                                $current,vp3_transcript_research_grant_v1($pdo,$owner));
                        }
                        if(vp3_initiative_enqueue_v1($pdo,$scope['user'],'initiative.prepare',$work,$hash))return 'queued';
                        $handled=true;
                    }
                }
                if(in_array('unavailable',$coverage,true))return 'failed';
                return $handled?'already_handled':'no_opportunity';
            });
        });
    }catch(Throwable $error){
        if(function_exists('agent_runtime_v125_trace')){
            agent_runtime_v125_trace('initiative.research_schedule_failed',
                ['user_id'=>(int)$user['id'],'error_class'=>get_class($error)]);
        }
        return match($error->getCode()){403,404=>'blocked',503=>'not_ready',default=>'failed'};
    }
}
