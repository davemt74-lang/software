<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/agent-initiative-tools-v1.php';
require_once __DIR__.'/agent-initiative-office-entry-source-v1.php';
require_once __DIR__.'/agent-initiative-office-entry-work-v1.php';
require_once __DIR__.'/agent-initiative-model-recovery-v1.php';

function vp3_initiative_office_entry_scan_state_v1(array $live,array $binding): array
{
    return vp3_initiative_in_scope_v1($live,vp3_initiative_binding_agent_v1($binding),static function()use($live):array{
        return agent_brain_v122_memory($live,'initiative_sweep','office-entry-scan')['metadata']??[];
    });
}

function vp3_initiative_office_entry_scan_recent_v1(array $state,int $now): bool
{
    $scanned=$state['scanned_at']??null;
    return is_int($scanned)&&$scanned<=$now&&$now-$scanned<30;
}

/** A cheap hint for the owner-locked scheduler, which checks this again before work. */
function vp3_initiative_office_entry_scan_due_v1(array $live,array $binding): bool
{
    return ($binding['enabled']??false)===true
        &&!vp3_initiative_office_entry_scan_recent_v1(vp3_initiative_office_entry_scan_state_v1($live,$binding),time());
}

/** The shared Initiative scheduler holds the canonical owner lock. */
function vp3_initiative_office_entry_schedule_v1(PDO $pdo,array $live,array $binding): void
{
    if(!$pdo->inTransaction()||($binding['enabled']??false)!==true)return;
    try{
        agent_commerce_atomic_pa2($pdo,static function()use($pdo,$live,$binding):void{
            $state=vp3_initiative_office_entry_scan_state_v1($live,$binding);
            $now=time();
            if(vp3_initiative_office_entry_scan_recent_v1($state,$now))return;
            $scope=vp3_initiative_tools_scope_v1($pdo,$live,$binding);
            $owner=(int)$scope['user']['id'];$agent=$scope['agent_id'];
            $agentWhere=$agent>0?'r.agent_id=?':'r.agent_id IS NULL';
            $agentParams=$agent>0?[$agent]:[];
            $sqlite=agent_commerce_sqlite_pa2($pdo);
            $json=static function(string $path)use($sqlite):string{
                $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,$path)";
                return $sqlite?$value:"JSON_UNQUOTE($value)";
            };
            $cid=$json("'$.binding.conversation_id'");$revision=$json("'$.binding.revision'");
            $office=$json("'$.office_entry'");$true=$sqlite?'1':"'true'";
            $pending=$pdo->prepare("SELECT 1 FROM agent_workflow_runs r WHERE r.owner_user_id=? AND $agentWhere
                AND r.source_kind='agent_initiative' AND r.source_key LIKE 'office-entry:%'
                AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
                AND r.status IN ('approved','queued','executing') AND $office=$true AND CAST($cid AS CHAR)=? AND $revision=? LIMIT 1");
            $pending->execute([$owner,...$agentParams,(string)$scope['conversation_id'],$binding['revision']]);

            $kinds=['task','task-list','reminder'];
            $rotation=$state['rotation']??0;
            if(!is_int($rotation)||$rotation<0||$rotation>=count($kinds))$rotation=0;
            $nextRotation=$rotation;
            $before=is_array($state['before']??null)?array_intersect_key($state['before'],array_fill_keys($kinds,true)):[];
            if($pending->fetchColumn()===false){
                $nextRotation=($rotation+1)%count($kinds);
                $sourceRevision=$json('?');
                $completed=$pdo->prepare("SELECT r.id,r.goal,a.result_json,c.result_json AS receipt_json FROM agent_workflow_runs r
                    JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
                        AND a.sequence_no=1 AND a.capability_key='initiative.prepare' AND a.status='completed'
                    JOIN agent_workflow_receipts c ON c.run_id=r.id AND c.action_id=a.id AND c.owner_user_id=r.owner_user_id
                        AND c.attempt_no=a.attempt_count AND c.executor='cloud' AND c.status='completed'
                    WHERE r.owner_user_id=? AND $agentWhere AND r.source_kind='agent_initiative' AND r.source_key=?
                        AND r.capability_key='initiative.prepare' AND r.execution_target='cloud' AND r.status='completed'
                        AND $office=$true AND $sourceRevision=? ORDER BY r.id DESC,c.id DESC LIMIT 4");
                $childQuery=$pdo->prepare("SELECT r.id,r.goal,r.status,r.last_error_class FROM agent_workflow_runs r
                    WHERE r.owner_user_id=? AND $agentWhere
                    AND r.source_kind='agent_initiative' AND r.source_key=? AND r.capability_key='initiative.prepare'
                    AND r.execution_target='cloud' ORDER BY r.id DESC LIMIT 1");
                for($offset=0;$offset<count($kinds);$offset++){
                    $index=($rotation+$offset)%count($kinds);$kind=$kinds[$index];
                    $cursor=$before[$kind]??null;
                    $cursor=is_int($cursor)&&$cursor>0?$cursor:null;
                    try{$ids=vp3_initiative_office_entry_scan_ids_v1($pdo,$scope,$kind,$cursor);}
                    catch(PDOException $error){continue;}
                    if(!$ids){unset($before[$kind]);continue;}
                    foreach($ids as $id){
                        $before[$kind]=(int)$id;
                        $ref=$kind.':'.$id;
                        try{$source=vp3_initiative_office_entry_source_v1($pdo,$scope,$binding,$ref);}
                        catch(RuntimeException|JsonException $error){continue;}
                        if($source===null)continue;
                        $sourceKey=vp3_initiative_office_entry_key_v1($binding,$ref);
                        $selection=[$owner,...$agentParams,$sourceKey,'$.sources.'.json_encode($ref,JSON_THROW_ON_ERROR)];
                        $completed->execute([...$selection,$source['revision']]);
                        $reviewed=null;
                        foreach($completed->fetchAll(PDO::FETCH_ASSOC) as $row){
                            $reviewed=vp3_initiative_office_entry_scan_completed_v1($pdo,$childQuery,$scope,$binding,$row,$ref,$source);
                            if($reviewed!==null)break;
                        }
                        if($reviewed==='current')continue;
                        $reassessment=$reviewed==='unclassified';
                        if($reviewed===null&&isset($source['pre_list_context_revision'])){
                            $priorSource=['revision'=>$source['pre_list_context_revision']]+$source;
                            $completed->execute([...$selection,$priorSource['revision']]);
                            foreach($completed->fetchAll(PDO::FETCH_ASSOC) as $row){
                                if(vp3_initiative_office_entry_scan_completed_v1($pdo,$childQuery,$scope,$binding,$row,$ref,$priorSource)!=='unclassified')continue;
                                $reassessment=true;
                                break;
                            }
                        }
                        $work=['binding'=>$binding,'sources'=>[$ref=>$source['revision']], 'primary'=>$ref,'tool'=>$source['tool'],
                            'focus'=>'Review this saved record and identify any useful next step.','report'=>true,'office_entry'=>true];
                        $hash=vp3_initiative_office_entry_hash_v1($binding,$ref,$source['revision'],$reviewed==='unclassified');
                        $classifiedWork=$work+['office_review_version'=>VP3_INITIATIVE_OFFICE_REVIEW_VERSION_V1];
                        if($reassessment)$classifiedWork['office_reassessment']=true;
                        if(vp3_initiative_enqueue_v1($pdo,$scope['user'],'initiative.prepare',$classifiedWork,$hash)
                            ||vp3_initiative_recover_rejected_model_run_v1($pdo,$scope['user'],$classifiedWork,'initiative.prepare',$sourceKey,$hash)
                            ||vp3_initiative_recover_rejected_model_run_v1($pdo,$scope['user'],$work,'initiative.prepare',$sourceKey,$hash)
                            ||vp3_initiative_recover_office_report_v1($pdo,$scope['user'],$work,$sourceKey,$hash)){
                            $nextRotation=($index+1)%count($kinds);
                            break 2;
                        }
                    }
                }
            }
            vp3_initiative_in_scope_v1($scope['user'],$agent,static function()use($scope,$now,$nextRotation,$before):void{
                if(agent_brain_v122_upsert_system_memory($scope['user'],'initiative_sweep','office-entry-scan','Office entry scan checkpoint.',[
                    'conversation_id'=>$scope['conversation_id'],'scanned_at'=>$now,'rotation'=>$nextRotation,'before'=>$before,
                ])<1)throw new RuntimeException('Office entry scan checkpoint could not be saved.',503);
            });
        });
    }catch(Throwable $error){
        if(!$pdo->inTransaction())throw $error;
        if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('initiative.office_entry_scan_failed',['error_class'=>get_class($error)]);
    }
}

/** Inventory order remains stable while an individual record changes status. */
function vp3_initiative_office_entry_scan_ids_v1(PDO $pdo,array $scope,string $kind,?int $before): array
{
    $owner=(int)$scope['user']['id'];$agent=$scope['agent_id'];
    [$agentWhere,$agentParams]=vp3_agent_memory_scope_sql_v410($agent);
    [$sql,$args]=match($kind){
        'task'=>["SELECT id FROM agent_memory_items WHERE user_id=? AND $agentWhere
            AND is_active=1 AND memory_type IN ('task','commitment')",[$owner,...$agentParams]],
        'task-list'=>['SELECT id FROM agent_task_lists_v1 WHERE owner_user_id=? AND agent_scope_id=?',[$owner,$agent]],
        'reminder'=>['SELECT id FROM agent_reminders_v1 WHERE owner_user_id=?',[$owner]],
    };
    if($before!==null){$sql.=' AND id<?';$args[]=$before;}
    $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 4');
    $q->execute($args);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/** A verified prior completion may need reclassification or a new Chat binding. */
function vp3_initiative_office_entry_scan_completed_v1(PDO $pdo,PDOStatement $childQuery,array $scope,array $binding,
    array $row,string $ref,array $source): ?string
{
    if(!is_string($row['result_json'])||$row['result_json']!==$row['receipt_json'])return null;
    $result=json_decode($row['result_json'],true);
    $work=$result['initiative_tool_result']??null;$output=$work['output']??null;
    $goal=json_decode((string)$row['goal'],true);
    $previous=$goal['binding']??null;$priorChat=$previous['conversation_id']??null;
    $owner=(int)$scope['user']['id'];$agent=$scope['agent_id'];$sources=[$ref=>$source['revision']];
    if(($result['effect_state']??null)!=='private_draft_ready'||!is_string($work['draft']??null)||$work['draft']===''
        ||($result['initiative_draft']??null)!==$work['draft']||($output['type']??null)!=='initiative_capability_read'
        ||($output['status']??null)!=='read_complete'||($output['tool']??null)!==$source['tool']
        ||($output['primary']??null)!==$ref||($output['source_revisions']??null)!==$sources
        ||($goal['office_entry']??null)!==true||($goal['report']??null)!==true
        ||isset($goal['office_agent_task'])||isset($goal['followthrough'])||isset($goal['followup'])
        ||($goal['primary']??null)!==$ref||($goal['tool']??null)!==$source['tool']
        ||($goal['sources']??null)!==$sources||!is_array($previous)||!is_int($priorChat)||$priorChat<1
        ||vp3_initiative_binding_agent_v1($previous)!==$agent
        ||($previous['owner_user_id']??($agent===0?$owner:null))!==$owner)return null;
    if($priorChat!==$scope['conversation_id']
        &&!vp3_agent_chat_conversation_v380($pdo,$priorChat,$owner,$agent>0?['id'=>$agent]:null))return 'rebind';
    if(!$source['closed']&&!isset($output['office_disposition']))return 'unclassified';
    if($previous===$binding)return 'current';

    $title=$output['agent_task_title']??null;
    if(!is_string($title)||$title==='')return 'current';
    $runId=(int)$row['id'];
    $childQuery->execute([$owner,...($agent>0?[$agent]:[]),vp3_initiative_office_agent_task_key_v1($runId)]);
    $childRun=$childQuery->fetch(PDO::FETCH_ASSOC);
    $childQuery->closeCursor();
    $child=json_decode((string)($childRun['goal']??''),true);
    $relation=$child['office_agent_task']??null;
    if(($child['office_entry']??null)!==true||($child['report']??null)!==false
        ||isset($child['followthrough'])||isset($child['followup'])
        ||($child['binding']??null)!==$previous||($child['primary']??null)!==$ref
        ||($child['sources']??null)!==$sources||($child['tool']??null)!==$source['tool']
        ||($relation['report_run_id']??null)!==$runId||($relation['title']??null)!==$title)return 'current';
    // A child still waiting under an obsolete policy cannot use the new binding.
    if(in_array($childRun['status'],['approved','queued','executing'],true))return 'rebind';
    if($childRun['status']!=='failed'||$childRun['last_error_class']!=='initiative_unconfirmed')return 'current';

    // A worker may have already rejected the obsolete binding before this scan.
    $failure=$pdo->prepare("SELECT a.result_json,c.result_json AS receipt_json FROM agent_workflow_actions a
        JOIN agent_workflow_receipts c ON c.run_id=a.run_id AND c.action_id=a.id AND c.owner_user_id=a.owner_user_id
            AND c.attempt_no=a.attempt_count AND c.executor='cloud' AND c.status='failed' AND c.error_class=a.error_class
        WHERE a.run_id=? AND a.owner_user_id=? AND a.sequence_no=1 AND a.capability_key='initiative.prepare'
            AND a.status='failed' AND a.attempt_count>0 AND a.error_class='initiative_unconfirmed' ORDER BY c.id DESC LIMIT 1");
    $failure->execute([(int)$childRun['id'],$owner]);
    $proof=$failure->fetch(PDO::FETCH_ASSOC);
    $diagnostic=json_decode((string)($proof['result_json']??''),true);
    return is_array($diagnostic)&&count($diagnostic)===2&&$proof['result_json']===$proof['receipt_json']
        &&($diagnostic['failure_phase']??null)==='binding'&&($diagnostic['failure_cause']??null)==='access_changed'
        ?'rebind':'current';
}
