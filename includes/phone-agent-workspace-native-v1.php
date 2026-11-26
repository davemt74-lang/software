<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-workspace-v1.php';
require_once __DIR__.'/phone-tracking-http-v1.php';

/** Real owner session plus the exact paired device; query IDs are not grants. */
function vp3_phone_agent_native_context_v1(PDO $pdo,?array $user,array $query,?int $now=null): array
{
    vp3_phone_agent_workspace_need_v1($user!==null,401);
    $device=vp3_phone_agent_workspace_id_v1($query['device_id']??null);
    $ref=vp3_phone_agent_workspace_id_v1($query['item_ref']??null);
    $revision=$query['scope_revision']??null;
    vp3_phone_agent_workspace_need_v1(is_string($revision)&&preg_match('/^[a-f0-9]{64}$/D',$revision)===1,422);
    $scope=vp3_phone_agent_workspace_scope_v1($pdo,$user,(string)($_COOKIE['vp3_phone_device_v1']??''),$device,false,$now);
    vp3_phone_agent_workspace_need_v1(hash_equals($scope['scope_revision'],$revision),409);
    $resolved=vp3_phone_agent_workspace_item_v1($pdo,$scope,$ref);
    if (in_array($resolved['item']['source_kind'],['image','canvas','gmail_attention'],true)) {
        $semantic=$query['source_revision']??null;
        vp3_phone_agent_workspace_need_v1(is_string($semantic)&&preg_match('/^[a-f0-9]{64}$/D',$semantic)===1
            &&hash_equals($resolved['item']['source_revision'],$semantic),409);
    }
    return ['scope'=>$scope,'resolved'=>$resolved];
}

function vp3_phone_agent_native_origin_v1(): void
{
    vp3_phone_http_transport_v1();
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    $expected=(vp3_phone_http_secure_v1()?'https://':'http://').($_SERVER['HTTP_HOST']??'');
    vp3_phone_agent_workspace_need_v1(($origin===''||strcasecmp($origin,$expected)===0)
        &&in_array($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin',['same-origin','none'],true),403);
}

function vp3_phone_agent_native_image_bindings_v1(int $now): array
{
    $saved=$_SESSION['phone_agent_native_images_v1']??[];$out=[];
    if (is_array($saved)) foreach ($saved as $key=>$binding) {
        if (is_string($key)&&is_array($binding)&&is_int($binding['expires']??null)&&$binding['expires']>$now
            &&$binding['expires']<=$now+86400&&count($out)<24) $out[$key]=$binding;
    }
    return $out;
}

/** An image approval made in one email composer is not authority over another image job. */
function vp3_phone_agent_native_image_binding_v1(array $context,array $input,?int $now=null): void
{
    $now??=time();$action=$input['action']??null;
    if ($action==='preview') return;
    $id=$action==='submit'?($input['request_key']??null):($input['generation_id']??null);
    vp3_phone_agent_workspace_need_v1(is_string($id),422);
    $key=($action==='submit'?'request:':'generation:').$id;
    $binding=vp3_phone_agent_native_image_bindings_v1($now)[$key]??null;
    vp3_phone_agent_workspace_need_v1(is_array($binding)
        &&($binding['item_ref']??null)===$context['resolved']['item']['item_ref']
        &&($binding['scope_revision']??null)===$context['scope']['scope_revision'],403);
}

function vp3_phone_agent_native_upload_binding_v1(array $context,array $input,?int $now=null): void
{
    $token=vp3_phone_agent_workspace_id_v1($input['token']??null);
    $binding=vp3_phone_agent_native_image_bindings_v1($now??time())['upload:'.$token]??null;
    vp3_phone_agent_workspace_need_v1(is_array($binding)
        &&($binding['item_ref']??null)===$context['resolved']['item']['item_ref']
        &&($binding['scope_revision']??null)===$context['scope']['scope_revision'],403);
}

function vp3_phone_agent_native_upload_response_v1(array $context,array $input,array $response,?int $now=null): void
{
    if (($response['ok']??null)!==true) return;
    $now??=time();$bindings=vp3_phone_agent_native_image_bindings_v1($now);
    if (($input['action']??null)==='begin') {
        $key='upload:'.vp3_phone_agent_workspace_id_v1($response['token']??null);
        unset($bindings[$key]);while(count($bindings)>=24) array_shift($bindings);
        $bindings[$key]=['item_ref'=>$context['resolved']['item']['item_ref'],
            'scope_revision'=>$context['scope']['scope_revision'],'expires'=>$now+86400];
        $_SESSION['phone_agent_native_images_v1']=$bindings;
    } elseif (($input['action']??null)==='finish') {
        vp3_phone_agent_native_upload_binding_v1($context,$input,$now);
        unset($bindings['upload:'.$input['token']]);$_SESSION['phone_agent_native_images_v1']=$bindings;
    }
}

/** Save only opaque review/job identity after a successful canonical response, never image content or a bearer. */
function vp3_phone_agent_native_image_response_v1(array $context,array $input,array $response,?int $now=null): void
{
    if (($response['ok']??null)!==true) return;
    $now??=time();$action=$input['action']??null;$key=null;
    if ($action==='preview') {
        $id=vp3_phone_agent_workspace_id_v1($response['request_key']??null);$key='request:'.$id;$ttl=600;
    } elseif ($action==='submit') {
        vp3_phone_agent_native_image_binding_v1($context,$input,$now);
        $id=$response['generation']['id']??null;
        $hash=hash('sha256','image:media-library:'.$context['scope']['user']['id'].':'.$input['request_key']);
        $expected=substr($hash,0,8).'-'.substr($hash,8,4).'-'.substr($hash,12,4).'-'.substr($hash,16,4).'-'.substr($hash,20,12);
        vp3_phone_agent_workspace_need_v1(is_string($id)&&hash_equals($expected,$id),409);
        $key='generation:'.$id;$ttl=86400;
    }
    if ($key===null) return;
    $bindings=vp3_phone_agent_native_image_bindings_v1($now);
    // The bounded session registry only retains the most recent explicit work.
    unset($bindings[$key]);
    while(count($bindings)>=24) array_shift($bindings);
    $bindings[$key]=['item_ref'=>$context['resolved']['item']['item_ref'],
        'scope_revision'=>$context['scope']['scope_revision'],'expires'=>$now+$ttl];
    $_SESSION['phone_agent_native_images_v1']=$bindings;
}

/** Narrow native actions, with canonical content/approval checks still downstream. */
function vp3_phone_agent_native_action_v1(PDO $pdo,array $context,string $kind,array $input): void
{
    $item=$context['resolved']['item'];$target=$context['resolved']['source']['target'];
    $action=$input['action']??null;
    if ($kind==='email' || $kind==='canvas') {
        vp3_phone_agent_workspace_need_v1(($input['agent_id']??null)===$target['agent_id']
            &&($input['conversation_id']??null)===$target['conversation_id'],403);
    }
    if ($kind==='email') {
        if ($item['source_kind']==='gmail_attention') {
            vp3_phone_agent_workspace_need_v1($action==='attention_open'
                &&($input['item_ref']??null)===$target['item_ref']&&($input['generation']??null)===$target['generation'],403);
            return;
        }
        vp3_phone_agent_workspace_need_v1($item['source_kind']==='gmail_draft',403);
        if (in_array($action,['followthrough_get','followthrough_update','followthrough_check'],true)) {
            $ref=$input['followthrough_ref']??null;
            vp3_phone_agent_workspace_need_v1(is_string($ref)&&preg_match('/^[a-f0-9]{32}$/D',$ref)===1,422);
            $q=$pdo->prepare('SELECT origin_draft_id,active_draft_id,generated_draft_id,followup_draft_id
                FROM agent_email_followthrough_v1 WHERE id=? AND owner_user_id=? AND agent_id=? AND conversation_id=?');
            $q->execute([$ref,(int)$context['scope']['user']['id'],$target['agent_id'],$target['conversation_id']]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            vp3_phone_agent_workspace_need_v1($row!==false&&in_array($target['draft_id'],array_values($row),true),403);
            return;
        }
        vp3_phone_agent_workspace_need_v1(in_array($action,['review','review_latest','compose_source','compose_save',
            'compose_generate','advance_write','retry_write','confirm','discard',
            'preference_status','preference_prepare','preference_accept','preference_dismiss',
            'preferences_get','preferences_save','preferences_restore','preferences_reset'],true)
            &&($input['draft_id']??null)===$target['draft_id'],403);
    } elseif ($kind==='canvas') {
        vp3_phone_agent_workspace_need_v1($item['source_kind']==='canvas'
            &&($input['canvas_id']??null)===$target['canvas_id']&&($input['revision']??null)===$target['revision']
            &&in_array($action,['open','save_state','email_data','command_data'],true),403);
    } elseif ($kind==='image') {
        if ($item['source_kind']==='image') {
            vp3_phone_agent_workspace_need_v1($action==='get'&&($input['generation_id']??null)===$target['generation_id'],403);
        } else {
            vp3_phone_agent_workspace_need_v1($item['source_kind']==='gmail_draft'&&$item['state']==='needs_review'
                &&in_array($action,['preview','submit','get','tick','cancel'],true),403);
            vp3_phone_agent_native_image_binding_v1($context,$input);
        }
    } elseif ($kind==='media') {
        vp3_phone_agent_workspace_need_v1($item['source_kind']==='gmail_draft'
            &&in_array($action,['browse','begin','chunk','finish'],true),403);
        if ($action==='browse') vp3_phone_agent_workspace_need_v1(($input['type']??null)==='photo',422);
        if ($action==='begin') vp3_phone_agent_workspace_need_v1(($input['media_type']??null)==='photo',422);
        if (in_array($action,['chunk','finish'],true)) vp3_phone_agent_native_upload_binding_v1($context,$input);
    } else throw new DomainException('Choose a Phone Agent item action.',422);
}

/** Chat-origin generated images use their native exact reader, not the separate Media Library job namespace. */
function vp3_phone_agent_native_image_v1(PDO $pdo,array $context,array $input): array
{
    vp3_phone_agent_workspace_need_v1(!array_diff(array_keys($input),['action','owner_key','csrf_token','generation_id'])
        &&($input['owner_key']??null)===(string)$context['scope']['user']['id'],403);
    vp3_phone_agent_workspace_need_v1(is_string($input['csrf_token']??null)&&is_string($_SESSION['csrf_token']??null)
        &&$_SESSION['csrf_token']!==''&&hash_equals($_SESSION['csrf_token'],$input['csrf_token']),419);
    vp3_phone_agent_native_action_v1($pdo,$context,'image',$input);
    vp3_phone_agent_workspace_need_v1($context['resolved']['item']['source_kind']==='image',403);
    vp3_phone_agent_workspace_scope_current_v1($pdo,$context['scope']);
    require_once __DIR__.'/agent-image-generation-v1.php';
    $target=$context['resolved']['source']['target'];
    $row=vp3_image_row_v1($pdo,$context['scope']['user'],$target['generation_id']);
    vp3_phone_agent_workspace_need_v1((int)$row['agent_id']===$target['agent_id']
        &&(int)$row['conversation_id']===$target['conversation_id'],403);
    $view=vp3_image_view_v1($pdo,$context['scope']['user'],$row);
    $scope=$context['scope'];$scope['now']=max($scope['now'],time());
    $last=vp3_phone_agent_workspace_item_v1($pdo,$scope,$context['resolved']['item']['item_ref']);
    vp3_phone_agent_workspace_need_v1(hash_equals($context['resolved']['item']['source_revision'],$last['item']['source_revision']),409);
    return ['generation'=>$view];
}

/** Reuse the exact Gmail triage reader and its isolated HTML renderer; no reply/send action is implied. */
function vp3_phone_agent_native_attention_v1(PDO $pdo,array $context,array $input,?callable $http=null): array
{
    vp3_phone_agent_workspace_need_v1(!array_diff(array_keys($input),['action','owner_key','csrf_token','agent_id','conversation_id','item_ref','generation'])
        &&($input['owner_key']??null)===(string)$context['scope']['user']['id'],403);
    vp3_phone_agent_workspace_need_v1(is_string($input['csrf_token']??null)&&is_string($_SESSION['csrf_token']??null)
        &&$_SESSION['csrf_token']!==''&&hash_equals($_SESSION['csrf_token'],$input['csrf_token']),419);
    vp3_phone_agent_workspace_need_v1($context['resolved']['item']['source_kind']==='gmail_attention',403);
    vp3_phone_agent_native_action_v1($pdo,$context,'email',$input);
    vp3_phone_agent_native_response_current_v1($pdo,$context,'email');
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    $message=agent_connection_gmail_inbox_open_item_v1($pdo,$context['scope']['user'],$input['item_ref'],$http,true);
    vp3_phone_agent_native_response_current_v1($pdo,$context,'email');
    return ['message'=>array_intersect_key($message,array_flip(['subject','from','body','display','answered_in_gmail','links']))];
}

/** Native mutations may change their own email revision; access loss never releases earlier private output. */
function vp3_phone_agent_native_response_current_v1(PDO $pdo,array $context,string $kind): void
{
    $scope=$context['scope'];$scope['now']=max($scope['now'],time());
    vp3_phone_agent_workspace_scope_current_v1($pdo,$scope);
    $last=vp3_phone_agent_workspace_item_v1($pdo,$scope,$context['resolved']['item']['item_ref']);
    vp3_phone_agent_workspace_need_v1($last['item']['state']!=='unavailable',403);
    if (in_array($context['resolved']['item']['source_kind'],['image','canvas','gmail_attention'],true)) {
        vp3_phone_agent_workspace_need_v1(hash_equals($context['resolved']['item']['source_revision'],$last['item']['source_revision']),409);
    }
}

/** Final synchronous execution fence. Only the trusted wrapper captures this request-local context.
 * Outside a database transaction, reopen and release the real session. Inside the final native
 * transaction, use the already authenticated request and current locking reads: acquiring a PHP
 * session there would invert the session -> database lock order used by owner controls.
 */
function vp3_phone_agent_native_execution_current_v1(PDO $pdo): void
{
    $saved=$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']??null;
    vp3_phone_agent_workspace_need_v1(is_array($saved)&&is_array($saved['context']??null)
        &&in_array($saved['kind']??null,['email','image','canvas','media'],true),403);
    $transaction=vp3_phone_transaction_active_v1($pdo);
    try {
        if (!$transaction&&session_status()!==PHP_SESSION_ACTIVE) {
            vp3_phone_agent_workspace_need_v1(session_id()!==''&&session_start(),401);
        }
        if (function_exists('reset_current_user_cache')) reset_current_user_cache();
        $context=$saved['context'];$context['scope']['now']=max($context['scope']['now'],time());
        vp3_phone_agent_workspace_scope_current_v1($pdo,$context['scope'],$transaction);
        vp3_phone_agent_native_response_current_v1($pdo,$context,$saved['kind']);
        if(isset($saved['voice_review'])){
            require_once __DIR__.'/phone-agent-email-call-review-v1.php';
            vp3_phone_agent_email_call_review_execution_v1($pdo,$context,$saved['voice_review'],$saved['voice_input'],time());
        }
    } catch (PDOException $error) {
        // MariaDB snapshot isolation can reject a current locking read after
        // a peer changes the enrollment. Abort this action; never retry it.
        if ($transaction&&(int)($error->errorInfo[1]??0)===1020) {
            throw new RuntimeException('The Phone Agent workspace changed. Refresh before continuing.',409,$error);
        }
        throw $error;
    } finally {
        if (!$transaction&&session_status()===PHP_SESSION_ACTIVE) session_write_close();
    }
}

function vp3_phone_agent_native_error_v1(Throwable $error): string
{
    $status=(int)$error->getCode();
    if ($error instanceof JsonException || $error instanceof InvalidArgumentException) $status=422;
    if ($error instanceof PDOException || !in_array($status,[400,401,403,404,405,409,413,419,422,503],true)) $status=503;
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    return json_encode(['ok'=>false,'error'=>$status===401?'Sign in again to open your private Agent workspace.'
        :'This Phone Agent item or its access changed. Return to Agent and reopen it.'],JSON_THROW_ON_ERROR);
}

/** Does not invent an owner session or widen the path of the holder cookie. */
function vp3_phone_agent_native_dispatch_v1(string $kind): never
{
    require_once __DIR__.'/bootstrap.php';
    try {
        vp3_phone_agent_native_origin_v1();
        $method=$_SERVER['REQUEST_METHOD']??'';
        vp3_phone_agent_workspace_need_v1($method==='POST'||($kind==='media'&&$method==='GET'),405);
        $pdo=db();vp3_phone_agent_workspace_need_v1($pdo instanceof PDO,503);
        $context=vp3_phone_agent_native_context_v1($pdo,current_user(),$_GET);
        if ($kind==='media') $input=$method==='GET'?$_GET:$_POST;
        else {
            $raw=file_get_contents('php://input',false,null,0,98305);
            vp3_phone_agent_workspace_need_v1(is_string($raw)&&strlen($raw)<=98304,413);
            $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            vp3_phone_agent_workspace_need_v1(is_array($input)&&!array_is_list($input),422);
        }
        vp3_phone_agent_native_action_v1($pdo,$context,$kind,$input);
        $GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']=['context'=>$context,'kind'=>$kind];
        if(array_key_exists('voice_review',$_GET)){
            vp3_phone_agent_workspace_need_v1($kind==='email'&&($input['action']??null)==='confirm'
                &&is_string($_GET['voice_review'])&&preg_match('/^[a-f0-9]{32}$/D',$_GET['voice_review'])===1,422);
            require_once __DIR__.'/phone-agent-email-call-review-v1.php';
            vp3_phone_agent_email_call_review_execution_v1($pdo,$context,$_GET['voice_review'],$input,time());
            $GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']+=['voice_review'=>$_GET['voice_review'],'voice_input'=>$input];
        }
        if ($kind==='email'&&$context['resolved']['item']['source_kind']==='gmail_attention') {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo json_encode(['ok'=>true]+vp3_phone_agent_native_attention_v1($pdo,$context,$input),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($kind==='image'&&$context['resolved']['item']['source_kind']==='image') {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo json_encode(['ok'=>true]+vp3_phone_agent_native_image_v1($pdo,$context,$input),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            exit;
        }
        // Recheck the real session and live enrollment after a potentially long
        // native request, before any private response leaves this wrapper.
        ob_start(static function(string $output)use($pdo,$context,$kind,$input):string {
            try {
                if(session_status()!==PHP_SESSION_ACTIVE)session_start();
                if(function_exists('reset_current_user_cache'))reset_current_user_cache();
                vp3_phone_agent_native_response_current_v1($pdo,$context,$kind);
                if ($kind==='image'&&$context['resolved']['item']['source_kind']==='gmail_draft') {
                    $response=json_decode($output,true,32,JSON_THROW_ON_ERROR);
                    vp3_phone_agent_workspace_need_v1(is_array($response),503);
                    vp3_phone_agent_native_image_response_v1($context,$input,$response);
                }
                if ($kind==='media'&&in_array($input['action']??null,['begin','finish'],true)) {
                    $response=json_decode($output,true,32,JSON_THROW_ON_ERROR);
                    vp3_phone_agent_workspace_need_v1(is_array($response),503);
                    vp3_phone_agent_native_upload_response_v1($context,$input,$response);
                }
                if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
                header('Cache-Control: private, no-store, max-age=0');
                return $output;
            } catch(Throwable $error) {return vp3_phone_agent_native_error_v1($error);}
        });
        require dirname(__DIR__).'/api/'.match($kind){
            'email'=>'chat-email-v1.php','canvas'=>'coding-canvas-v1.php',
            'image'=>'media-library-image-v1.php','media'=>'media-library-v86.php',
            default=>throw new DomainException('Choose a Phone Agent view.',422),
        };
    } catch(Throwable $error) {echo vp3_phone_agent_native_error_v1($error);}
    exit;
}
