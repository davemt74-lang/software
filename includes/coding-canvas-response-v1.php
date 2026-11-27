<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-source-v1.php';

/** A received response is private job evidence, not an activated app or model belief. */
function vp3_coding_canvas_response_save_v1(PDO $pdo,array $user,array $run,array $claim,?string $sourceHash,array $snapshot): bool
{
    $active=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);
    if(!$active||(int)$active['id']!==(int)$run['id']||$active['source_kind']!=='coding_canvas'
        ||$active['source_key']!==$run['source_key']||!vp3_coding_canvas_response_ready_v1($pdo))return false;
    $text=$snapshot['text']??null;
    if(!is_string($text)||strlen($text)>200000||!preg_match('//u',$text))return false;
    $safeId=static fn(mixed $v):string=>is_string($v)&&preg_match('/\A[a-zA-Z0-9_.:-]{1,160}\z/D',$v)?$v:'';
    $report=['protocol'=>'vp3.coding-provider-result.v1','run_id'=>(int)$run['id'],'request_key'=>$run['source_key'],
        'attempt'=>(int)$claim['action']['attempt_count'],'source_hash'=>$sourceHash,'text'=>$text,'text_hash'=>hash('sha256',$text),
        'provider'=>in_array($snapshot['provider']??null,['openai','anthropic'],true)?$snapshot['provider']:'anthropic',
        'terminal'=>($snapshot['terminal']??false)===true,'stop_reason'=>in_array($snapshot['stop_reason']??null,['end_turn','stop_sequence','max_tokens','tool_use','pause_turn','refusal','completed','incomplete','failed'],true)?$snapshot['stop_reason']:'',
        'request_id'=>$safeId($snapshot['request_id']??null),'message_id'=>$safeId($snapshot['message_id']??null),
        'received_bytes'=>strlen($text),'duration_ms'=>max(0,min(300000,(int)($snapshot['duration_ms']??0))),
        'updated_at'=>time(),'failure'=>in_array($snapshot['failure']??null,['provider_timeout','provider_connection','provider_server','provider_rejected','provider_response_incomplete','coding_cancelled','provider_response_invalid','provider_response_too_large','provider_overloaded','provider_rate_limited','provider_stream_error'],true)?$snapshot['failure']:''];
    $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($json)>524288)return false;
    $q=$pdo->prepare("UPDATE agent_workflow_runs SET coding_provider_result_json=? WHERE id=? AND owner_user_id=? AND source_kind='coding_canvas' AND source_key=? AND status='executing' AND lease_token=? AND current_action_id=?");
    $q->execute([$json,$run['id'],$user['id'],$run['source_key'],$claim['lease_token'],$claim['action']['id']]);
    return $q->rowCount()===1||agent_worker_cloud_active_row_v1910($pdo,$user,$claim)!==null;
}

function vp3_coding_canvas_response_decode_v1(array $run): ?array
{
    $json=$run['coding_provider_result_json']??null;if($json===null)return null;
    if(!is_string($json)||strlen($json)>524288)throw new RuntimeException('The saved provider response could not be verified.',409);
    $report=json_decode($json,true,12,JSON_THROW_ON_ERROR);$text=$report['text']??null;
    if(!is_array($report)||($report['protocol']??null)!=='vp3.coding-provider-result.v1'||($report['run_id']??null)!==(int)$run['id']
        ||($report['request_key']??null)!==$run['source_key']||!is_string($text)||strlen($text)>200000
        ||!is_string($report['text_hash']??null)||!hash_equals($report['text_hash'],hash('sha256',$text)))throw new RuntimeException('The saved provider response could not be verified.',409);
    return $report;
}

function vp3_coding_canvas_response_artifact_v1(array $report): ?array
{
    try{
        // Manual recovery checks the received source shape; it never invents a provider success receipt.
        return vp3_coding_canvas_provider_output_v1('anthropic',['stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>$report['text']]]])['artifact'];
    }catch(Vp3CodingCanvasFailureV1|InvalidArgumentException|JsonException){return null;}
}

function vp3_coding_canvas_response_view_v1(PDO $pdo,array $user,int $aid,int $cid,int $runId): array
{
    $user=vp3_coding_canvas_scope_v1($pdo,$user,$aid,$cid);vp3_coding_canvas_authority_v1($pdo,$user,$aid);
    $run=vp3_coding_canvas_job_row_v1($pdo,$user,$runId);$goal=json_decode($run['goal'],true,16,JSON_THROW_ON_ERROR);
    if((int)$run['agent_id']!==$aid||$goal['conversation_id']!==$cid)throw new RuntimeException('This Coding response is unavailable in this Chat.',404);
    vp3_coding_canvas_sources_current_v1($pdo,$user,$aid,$goal,$runId);
    $report=vp3_coding_canvas_response_decode_v1($run);
    if(!$report)return ['received_source'=>null];
    $artifact=vp3_coding_canvas_response_artifact_v1($report);$canRecover=false;
    if($artifact!==null&&$run['capability_key']==='coding.repair'&&in_array($run['status'],['failed','cancelled'],true)
        &&personal_capability_has_v242('personal_knowledge.manage',$user)){
        $source=vp3_coding_canvas_read_v1($pdo,$user,$aid,$goal['canvas_id'],$goal['source_revision']);
        $canRecover=hash_equals($source['revision']['source_hash'],(string)$report['source_hash'])
            &&$source['canvas']['draft_revision']===$goal['expected_draft']&&$source['canvas']['active_revision']===$goal['expected_active'];
    }
    return ['received_source'=>['run_id'=>$runId,'text'=>$report['text'],'received_bytes'=>$report['received_bytes'],
        'terminal'=>$report['terminal'],'request_id'=>$report['request_id'],'duration_ms'=>$report['duration_ms'],
        'source_valid'=>$artifact!==null,'can_recover'=>$canRecover,'provider_attempt'=>vp3_coding_canvas_provider_attempt_v1($pdo,$run)]];
}

/** Explicit owner import, using the original source fences and the canonical manual-save path. */
function vp3_coding_canvas_response_recover_v1(PDO $pdo,array $user,int $aid,int $cid,int $runId,string $request): array
{
    vp3_coding_canvas_id_v1($request);
    $user=vp3_coding_canvas_scope_v1($pdo,$user,$aid,$cid);vp3_coding_canvas_authority_v1($pdo,$user,$aid,true);
    $run=vp3_coding_canvas_job_row_v1($pdo,$user,$runId);$goal=json_decode($run['goal'],true,16,JSON_THROW_ON_ERROR);
    if((int)$run['agent_id']!==$aid||$goal['conversation_id']!==$cid||$run['capability_key']!=='coding.repair'||!in_array($run['status'],['failed','cancelled'],true))throw new RuntimeException('Open a failed rebuild in its original Chat to recover received code.',409);
    vp3_coding_canvas_sources_current_v1($pdo,$user,$aid,$goal,$runId);
    $report=vp3_coding_canvas_response_decode_v1($run);$artifact=$report===null?null:vp3_coding_canvas_response_artifact_v1($report);
    if($artifact===null)throw new RuntimeException('The received code is incomplete or outside the Canvas source contract. Inspect it before editing manually.',422);
    // A second click or a reload recovers the same import even if its first HTTP body was lost.
    $key=substr(hash('sha256','coding-received:'.$run['source_key'].':'.$report['text_hash']),0,32);
    $request=substr($key,0,8).'-'.substr($key,8,4).'-'.substr($key,12,4).'-'.substr($key,16,4).'-'.substr($key,20,12);
    return vp3_coding_canvas_source_save_v1($pdo,$user,$aid,$cid,$goal['canvas_id'],$goal['source_revision'],(string)$report['source_hash'],
        $request,$artifact,$goal['expected_draft'],$goal['expected_active']);
}
