<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-source-v1.php';

function vp3_coding_canvas_check_scope_v1(PDO $pdo,array $user,int $aid,int $cid,string $id,int $revision): array
{
    $user=vp3_coding_canvas_scope_v1($pdo,$user,$aid,$cid);
    $agent=vp3_coding_canvas_authority_v1($pdo,$user,$aid,true);
    if(!vp3_coding_canvas_checks_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade to enable workflow checks.',503);
    if($cid<1||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$user['id'],$agent))throw new RuntimeException('Conversation not found for this Agent.',404);
    $row=vp3_coding_canvas_require_row_v1($pdo,(int)$user['id'],$aid,vp3_coding_canvas_id_v1($id),true);
    $data=vp3_coding_canvas_details_v1($pdo,$row,$revision);
    require_once __DIR__.'/coding-canvas-email-v1.php';
    if((int)$row['conversation_id']!==$cid&&vp3_coding_canvas_email_binding_v1($pdo,$user,$aid,$id,$revision)!==null)
        throw new RuntimeException('Open the original Chat to check this email-connected Canvas.',409);
    return [$row,$data,$user];
}

function vp3_coding_canvas_check_current_v1(array $data,string $source,string $state): void
{
    if($data['canvas']['draft_revision']!==$data['revision']['id']||!hash_equals($data['revision']['source_hash'],$source)
        ||!hash_equals($data['state']['revision'],$state))throw new RuntimeException('This draft or its app data changed. Open the latest version and check it again.',409);
}

function vp3_coding_canvas_check_store_v1(PDO $pdo,string $id,int $revision,array $report,bool $clearProof=false): void
{
    $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($json)>8000)throw new RuntimeException('The workflow check report exceeds its limit.',422);
    $pdo->prepare('UPDATE vp3_coding_canvas_versions_v1 SET workflow_result_json=?'.($clearProof?',tested_state_revision=NULL':'').' WHERE canvas_id=? AND revision=?')->execute([$json,$id,$revision]);
}

function vp3_coding_canvas_check_begin_v1(PDO $pdo,array $user,int $aid,int $cid,string $id,int $revision,string $request,string $source,string $state,?string $image): array
{
    $request=vp3_coding_canvas_id_v1($request);
    return vp3_coding_canvas_atomic_v1($pdo,(int)$user['id'],static function()use($pdo,$user,$aid,$cid,$id,$revision,$request,$source,$state,$image):array{
        [$row,$data,$user]=vp3_coding_canvas_check_scope_v1($pdo,$user,$aid,$cid,$id,$revision);
        vp3_coding_canvas_check_current_v1($data,$source,$state);
        vp3_coding_canvas_library_image_assert_version_v1($pdo,$row,$revision,$image);
        $checks=$data['revision']['workflow_checks'];
        if(!$checks)throw new RuntimeException('Add a workflow check before testing app actions.',422);
        $previous=$data['revision']['workflow_result'];
        if(($previous['request_id']??null)===$request){
            if(($previous['conversation_id']??null)!==$cid||!hash_equals($previous['source_hash'],$source)||!hash_equals($previous['state_revision'],$state)
                ||($previous['image_token']??null)!==$image)throw new RuntimeException('This check request already belongs to different work.',409);
            return $data;
        }
        $report=['protocol'=>'vp3.canvas-workflow-check.v1','request_id'=>$request,'status'=>'running','conversation_id'=>$cid,
            'source_hash'=>$source,'state_revision'=>$state,'checks_hash'=>vp3_coding_canvas_checks_hash_v1($checks),
            'image_token'=>$image,'active_revision'=>$data['canvas']['active_revision'],'draft_revision'=>$data['canvas']['draft_revision'],
            'started_at'=>time(),'result'=>null,'repair_run_id'=>null];
        vp3_coding_canvas_check_store_v1($pdo,$id,$revision,$report,true);
        return vp3_coding_canvas_details_v1($pdo,$row,$revision);
    });
}

function vp3_coding_canvas_check_result_v1(mixed $input,array $checks): array
{
    $reasons=['none','target_missing','wrong_control','handler_missing','expectation_failed','save_missing','source_syntax','source_startup','source_runtime','worker_start','render','timeout','unresponsive','email_workflow'];
    if(!is_array($input)||count($input)!==5||!is_int($input['passed']??null)||$input['passed']<0||$input['passed']>count($checks)
        ||!array_key_exists('failed_case',$input)||!is_int($input['step']??null)||$input['step']<0
        ||!in_array($input['reason']??null,$reasons,true)||!is_bool($input['email_fixture_checked']??null))throw new InvalidArgumentException('Use a complete bounded workflow check result.',422);
    $passed=$input['passed'];$failed=$input['failed_case'];
    if($passed===count($checks)){
        if($failed!==null||$input['reason']!=='none'||$input['step']!==0)throw new InvalidArgumentException('The workflow check result is inconsistent.',422);
    }elseif($failed!==$passed||$input['reason']==='none'||$input['step']>count($checks[$passed]['steps'])+1)
        throw new InvalidArgumentException('The failed workflow check result is inconsistent.',422);
    return ['passed'=>$passed,'failed_case'=>$failed,'step'=>$input['step'],'reason'=>$input['reason'],'email_fixture_checked'=>$input['email_fixture_checked']];
}

function vp3_coding_canvas_check_finish_v1(PDO $pdo,array $user,int $aid,int $cid,string $id,int $revision,string $request,mixed $input): array
{
    $request=vp3_coding_canvas_id_v1($request);
    return vp3_coding_canvas_atomic_v1($pdo,(int)$user['id'],static function()use($pdo,$user,$aid,$cid,$id,$revision,$request,$input):array{
        [$row,$data,$user]=vp3_coding_canvas_check_scope_v1($pdo,$user,$aid,$cid,$id,$revision);
        $report=$data['revision']['workflow_result'];$checks=$data['revision']['workflow_checks'];
        if(!$checks||($report['request_id']??null)!==$request||($report['conversation_id']??null)!==$cid)throw new RuntimeException('This workflow check is no longer current.',409);
        $result=vp3_coding_canvas_check_result_v1($input,$checks);
        if($report['status']!=='running'){
            if(($report['result']??null)!==$result)throw new RuntimeException('This check request already has a different result.',409);
            return $data;
        }
        vp3_coding_canvas_check_current_v1($data,$report['source_hash'],$report['state_revision']);
        if(time()-(int)$report['started_at']>180||$report['active_revision']!==$data['canvas']['active_revision']
            ||!hash_equals($report['checks_hash'],vp3_coding_canvas_checks_hash_v1($checks)))throw new RuntimeException('This workflow check expired or changed. Run it again.',409);
        vp3_coding_canvas_library_image_assert_version_v1($pdo,$row,$revision,$report['image_token']);
        $report['result']=$result;$report['status']=$result['failed_case']===null?'passed':'failed';$report['finished_at']=time();
        vp3_coding_canvas_check_store_v1($pdo,$id,$revision,$report,true);
        if($report['status']==='passed')return vp3_coding_canvas_email_test_v1($pdo,$user,$aid,$cid,$id,$revision,$report['state_revision'],$result['email_fixture_checked'],$report['image_token']);
        return vp3_coding_canvas_details_v1($pdo,$row,$revision);
    });
}

/** One failed check can admit one existing Coding job, even across lost responses. */
function vp3_coding_canvas_check_repair_v1(PDO $pdo,array $user,int $aid,int $cid,string $id,int $revision,string $request,string $repairRequest): array
{
    $request=vp3_coding_canvas_id_v1($request);$repairRequest=vp3_coding_canvas_id_v1($repairRequest);
    return vp3_coding_canvas_atomic_v1($pdo,(int)$user['id'],static function()use($pdo,$user,$aid,$cid,$id,$revision,$request,$repairRequest):array{
        [$row,$data,$user]=vp3_coding_canvas_check_scope_v1($pdo,$user,$aid,$cid,$id,$revision);
        $report=$data['revision']['workflow_result'];
        if(($report['request_id']??null)!==$request||($report['conversation_id']??null)!==$cid||$report['status']!=='failed')throw new RuntimeException('Run the saved workflow checks before requesting a repair.',409);
        if(($report['repair_run_id']??null)!==null){
            $job=vp3_coding_canvas_job_row_v1($pdo,$user,(int)$report['repair_run_id']);
            if((int)$job['agent_id']!==$aid)throw new RuntimeException('The workflow repair context changed.',409);
            return ['job'=>vp3_coding_canvas_job_view_v1($pdo,$job)];
        }
        vp3_coding_canvas_check_current_v1($data,$report['source_hash'],$report['state_revision']);
        vp3_coding_canvas_library_image_assert_version_v1($pdo,$row,$revision,$report['image_token']);
        if(time()-(int)($report['finished_at']??0)>900)throw new RuntimeException('Check this draft again before requesting a repair.',409);
        $failure=$report['result'];$index=$failure['failed_case'];
        $diagnostic='Workflow check '.($index+1).' failed at step '.$failure['step'].': '.$failure['reason'].'. Keep the saved acceptance checks unchanged.';
        $job=vp3_coding_canvas_queue_v1($pdo,$user,$aid,$cid,$repairRequest,
            'Repair this Canvas so it passes its saved workflow checks. Preserve the intended behavior, other functionality, data keys and permissions.',
            $id,$revision,$diagnostic);
        $report['repair_run_id']=$job['run_id'];vp3_coding_canvas_check_store_v1($pdo,$id,$revision,$report);
        return ['job'=>$job];
    });
}
