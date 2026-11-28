<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/coding-canvas-jobs-v1.php';
require_once dirname(__DIR__).'/includes/coding-canvas-command-grants-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Use POST.', 405);
    }
    $user = defined('VP3_PHONE_AGENT_NATIVE_V1')&&function_exists('vp3_phone_agent_native_user_v1')?vp3_phone_agent_native_user_v1():current_user();
    if (!$user) throw new RuntimeException('Sign in to use private Canvases.', 401);
    $raw = file_get_contents('php://input', false, null, 0, 393217);
    if ($raw === false || strlen($raw) > 393216) throw new RuntimeException('This Canvas request is too large.', 413);
    try {$input = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);}
    catch (JsonException $error) {if(strlen($raw)>98304)throw new RuntimeException('This Canvas request is too large.',413);throw $error;}
    if (!$input instanceof stdClass) throw new InvalidArgumentException('Enter a valid Canvas request.');
    if (!is_string($input->csrf_token ?? null) || !hash_equals(csrf_token(), $input->csrf_token)) {
        throw new RuntimeException('Refresh the page to restore this session.', 419);
    }

    $action = $input->action ?? '';
    if(strlen($raw)>98304&&$action!=='save_source')throw new RuntimeException('This Canvas request is too large.',413);
    $aid = $input->agent_id ?? null;
    $cid = $input->conversation_id ?? null;
    if (!is_int($aid) || $aid < 0 || !is_int($cid) || $cid < 0) {
        throw new InvalidArgumentException('Choose the current Agent and conversation.');
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Canvas storage is unavailable.', 503);
    $user = vp3_coding_canvas_scope_v1($pdo, $user, $aid, $cid);
    vp3_coding_canvas_authority_v1($pdo, $user, $aid, in_array($action, ['build', 'repair', 'retry','save_source','save_checks','begin_check','finish_check','check_repair','recover_source'], true));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $canvasId = $input->canvas_id ?? null;
    $revision = $input->revision ?? null;
    if (in_array($action, ['open', 'repair', 'test', 'apply', 'save_state', 'save_source','save_checks','begin_check','finish_check','check_repair', 'delete', 'email_data',
        'phone_targets','phone_send','command_grants','save_command_grants','command_data','publication_status','publish','unpublish','set_hidden','save_library_image'], true)
        && !is_string($canvasId)) throw new InvalidArgumentException('Choose a saved Canvas.');
    if (($revision !== null || in_array($action, ['repair', 'test', 'apply', 'save_state', 'save_source','save_checks','begin_check','finish_check','check_repair', 'email_data','command_data','publish','save_library_image'], true))
        && (!is_int($revision) || $revision < 1)) throw new InvalidArgumentException('Choose an exact Canvas revision.');

    if(in_array($action,['save_checks','begin_check','finish_check','check_repair'],true)){
        require_once dirname(__DIR__).'/includes/coding-canvas-checks-v1.php';
        if(defined('VP3_PHONE_AGENT_NATIVE_V1'))throw new RuntimeException('Open the owner Canvas editor to manage workflow checks.',403);
        if(!is_string($input->request_id??null))throw new InvalidArgumentException('Choose an exact workflow check request.',422);
        if($action==='save_checks'){
            if(!is_array($input->workflow_checks??null)||!is_string($input->source_hash??null)||property_exists($input,'artifact')||property_exists($input,'commands'))throw new InvalidArgumentException('Choose saved source and declarative workflow checks.',422);
            foreach(['expected_active_revision','expected_draft_revision'] as $key){
                if(!property_exists($input,$key)||($input->$key!==null&&(!is_int($input->$key)||$input->$key<1)))throw new InvalidArgumentException('Open the current Canvas before saving checks.',422);
            }
            $checks=json_decode(json_encode($input->workflow_checks,JSON_THROW_ON_ERROR),true,12,JSON_THROW_ON_ERROR);
            $saved=vp3_coding_canvas_read_v1($pdo,$user,$aid,$canvasId,$revision);
            $result=vp3_coding_canvas_source_save_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$input->source_hash,$input->request_id,
                $saved['revision']['artifact'],$input->expected_draft_revision,$input->expected_active_revision,$checks);
        }elseif($action==='begin_check'){
            if(!is_string($input->source_hash??null)||!is_string($input->state_revision??null)||(($input->expected_image??null)!==null&&!is_string($input->expected_image)))throw new InvalidArgumentException('Open the saved Canvas and its current data before checking it.',422);
            $result=vp3_coding_canvas_check_begin_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$input->request_id,$input->source_hash,$input->state_revision,$input->expected_image??null);
        }elseif($action==='finish_check'){
            if(!($input->result??null) instanceof stdClass)throw new InvalidArgumentException('Choose a complete workflow check result.',422);
            $result=vp3_coding_canvas_check_finish_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$input->request_id,(array)$input->result);
        }else{
            if(!is_string($input->repair_request_id??null))throw new InvalidArgumentException('Choose an exact repair request.',422);
            $result=vp3_coding_canvas_check_repair_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$input->request_id,$input->repair_request_id);
        }
    } elseif(in_array($action,['received_source','recover_source'],true)){
        if(defined('VP3_PHONE_AGENT_NATIVE_V1'))throw new RuntimeException('Open the owner Canvas editor to view received code.',403);
        if($cid<1||!is_int($input->run_id??null)||$input->run_id<1)throw new InvalidArgumentException('Choose an exact Coding Agent run.');
        require_once dirname(__DIR__).'/includes/coding-canvas-response-v1.php';
        if($action==='received_source')$result=vp3_coding_canvas_response_view_v1($pdo,$user,$aid,$cid,$input->run_id);
        else{
            if(!is_string($input->request_id??null))throw new InvalidArgumentException('Choose an exact code recovery request.');
            $result=vp3_coding_canvas_response_recover_v1($pdo,$user,$aid,$cid,$input->run_id,$input->request_id);
            $canvasId=$result['canvas']['id'];
        }
    } elseif(in_array($action,['phone_targets','phone_send'],true)){
        if(!is_string($canvasId)||!is_int($revision)||$revision<1)throw new InvalidArgumentException('Choose a saved Canvas version.');
        require_once dirname(__DIR__).'/includes/coding-canvas-phone-v1.php';
        $result=vp3_coding_canvas_phone_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$action,(array)$input);
    } elseif ($action === 'start_email') {
        $request = $input->request_id ?? null;
        if (!is_string($request)) throw new InvalidArgumentException('Choose an exact email Canvas request.');
        require_once dirname(__DIR__).'/includes/agent-initiative-email-canvas-start-v1.php';
        $result = vp3_initiative_email_canvas_start_v1($pdo, $user, $aid, $cid, $request);
    } elseif ($action === 'build' || $action === 'repair') {
        $request = $input->request_id ?? null;
        $prompt = $input->prompt ?? null;
        $diagnostic = $action === 'repair' ? ($input->diagnostic ?? null) : null;
        if (!is_string($request) || !is_string($prompt) || trim($prompt) === '' || strlen($prompt) > 8000
            || str_contains($prompt, "\0") || ($diagnostic !== null
            && (!is_string($diagnostic) || strlen($diagnostic) > 1600 || str_contains($diagnostic, "\0")))) {
            throw new InvalidArgumentException('Enter a valid Canvas instruction within the size limit.');
        }
        $commands = property_exists($input,'commands') ? vp3_coding_canvas_command_list_v1($input->commands) : null;
        $context=$input->edit_context??null;
        if ($context!==null&&!$context instanceof stdClass)throw new InvalidArgumentException('Select a saved source range.',422);
        $job = vp3_coding_canvas_queue_v1($pdo, $user, $aid, $cid, vp3_coding_canvas_id_v1($request), trim($prompt),
            $action === 'repair' ? vp3_coding_canvas_id_v1($canvasId) : null,
            $action === 'repair' ? $revision : null, $diagnostic, commands:$commands,editContext:$context===null?null:(array)$context);
        $result = ['job' => $job];
    } elseif ($action === 'save_source') {
        if (defined('VP3_PHONE_AGENT_NATIVE_V1')) throw new RuntimeException('Open the owner Canvas editor to edit source.',403);
        foreach (['expected_active_revision','expected_draft_revision'] as $key) {
            if (!property_exists($input,$key)||($input->$key!==null&&(!is_int($input->$key)||$input->$key<1))) throw new InvalidArgumentException('Open the current saved app before editing source.',422);
        }
        if (!is_string($input->request_id??null)||!is_string($input->source_hash??null)||!($input->artifact??null) instanceof stdClass
            || property_exists($input,'commands'))throw new InvalidArgumentException('Choose the saved source and its unchanged data commands.',422);
        $result=vp3_coding_canvas_source_save_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$input->source_hash,$input->request_id,
            (array)$input->artifact,$input->expected_draft_revision,$input->expected_active_revision);
    } elseif ($action === 'command_grants') {
        $result=['commands'=>vp3_coding_canvas_commands_v1($pdo,$user,$aid,$cid,$canvasId)];
    } elseif ($action === 'save_command_grants') {
        $expected=$input->expected_revision??null;
        $settings=$input->settings??null;
        if(!is_string($expected)||!$settings instanceof stdClass)throw new InvalidArgumentException('Choose valid Canvas data permissions.');
        $result=['commands'=>vp3_coding_canvas_commands_save_v1($pdo,$user,$aid,$cid,$canvasId,$settings,$expected)];
    } elseif ($action === 'command_data') {
        $command=$input->command??null;
        if(!is_string($command))throw new InvalidArgumentException('Choose a Canvas data command.');
        if($command==='gmail'){
            require_once dirname(__DIR__).'/includes/coding-canvas-gmail-command-v1.php';
            $result=['source'=>vp3_coding_canvas_gmail_command_data_v1($pdo,$user,$aid,$cid,$canvasId,$revision)];
        }else{
            $result=['source'=>vp3_coding_canvas_command_source_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$command)];
        }
    } elseif (in_array($action,['publication_status','publish','unpublish'],true)) {
        require_once dirname(__DIR__).'/includes/coding-canvas-publishing-v1.php';
        $expected=$input->expected_revision??null;
        $slug=$input->slug??null;
        if(($action!=='publication_status'&&!is_string($expected))||($action==='publish'&&!is_string($slug))){
            throw new InvalidArgumentException('Open the current Canvas publication settings again.');
        }
        $result=match($action){
            'publication_status'=>vp3_coding_canvas_publication_status_v1($pdo,$user,$aid,$canvasId,$revision),
            'publish'=>vp3_coding_canvas_publish_v1($pdo,$user,$aid,$canvasId,$revision,$slug,$expected),
            'unpublish'=>vp3_coding_canvas_unpublish_v1($pdo,$user,$aid,$canvasId,$expected),
        };
    } elseif ($action === 'set_hidden') {
        if(!is_bool($input->hidden??null))throw new InvalidArgumentException('Choose whether to hide this app from Chat.');
        $result=['canvas'=>vp3_coding_canvas_set_hidden_v1($pdo,$user,$aid,$canvasId,$input->hidden)];
    } elseif ($action === 'recover') {
        if ($cid < 1 || !is_string($input->request_id ?? null)) throw new InvalidArgumentException('Choose the original Coding Agent request.');
        $result = ['job'=>vp3_coding_canvas_recover_request_v1($pdo,$user,$aid,$cid,$input->request_id)];
    } elseif ($action === 'resume') {
        if ($cid < 1) throw new InvalidArgumentException('Choose a current Chat.');
        $result = ['job' => vp3_coding_canvas_resume_v1($pdo, $user, $aid, $cid)];
    } elseif (in_array($action, ['job', 'tick', 'stop', 'retry', 'saved_request'], true)) {
        $runId = $input->run_id ?? null;
        if ($cid < 1 || !is_int($runId) || $runId < 1) throw new InvalidArgumentException('Choose an exact Coding Agent run.');
        if ($action === 'tick') {
            ignore_user_abort(true);
            @set_time_limit(330);
        }
        if ($action === 'retry') {
            if (!is_string($input->request_id ?? null)) throw new InvalidArgumentException('Choose a new Coding Agent retry request.');
            $result = ['job'=>vp3_coding_canvas_retry_v1($pdo,$user,$aid,$cid,$runId,$input->request_id)];
        } elseif ($action === 'saved_request') {
            $result = ['request'=>vp3_coding_canvas_saved_request_v1($pdo,$user,$aid,$cid,$runId)];
        } else {
            $result = ['job' => vp3_coding_canvas_job_v1($pdo, $user, $aid, $cid, $runId, $action)];
        }
    } else {
        $stateRevision = $input->state_revision ?? null;
        if (in_array($action, ['test', 'save_state'], true) && (!is_string($stateRevision)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $stateRevision))) {
            throw new InvalidArgumentException('Open the current Canvas data again.');
        }
        $active = $input->expected_active_revision ?? null;
        if ($action === 'apply' && (!property_exists($input, 'expected_active_revision')
            || ($active !== null && (!is_int($active) || $active < 1)))) {
            throw new InvalidArgumentException('Open the current Canvas revision again.');
        }
        $state = $input->state ?? null;
        if ($action === 'save_state' && !$state instanceof stdClass) {
            throw new InvalidArgumentException('Canvas data must be a JSON object.');
        }
        $deleteFolder = $input->delete_folder ?? null;
        if ($action === 'delete' && !is_bool($deleteFolder)) {
            throw new InvalidArgumentException('Choose whether to keep the Knowledge Base folder.');
        }
        $imageId = $input->asset_id ?? null;
        $expectedImage = $input->expected_image ?? null;
        if ($action === 'save_library_image' && (!property_exists($input,'asset_id') || ($imageId !== null && !is_int($imageId))
            || !is_string($expectedImage))) {
            throw new InvalidArgumentException('Choose the current Canvas version and its image.');
        }
        if (in_array($action,['test','apply'],true) && $expectedImage !== null && !is_string($expectedImage)) {
            throw new InvalidArgumentException('Reopen the current Canvas version and its image.');
        }
        if (in_array($action,['open','test','apply','save_state','email_data','save_library_image'],true)) {
            require_once dirname(__DIR__).'/includes/coding-canvas-email-v1.php';
        }
        if ($action === 'save_state' && vp3_coding_canvas_email_binding_v1($pdo,$user,$aid,$canvasId,$revision) !== null) {
            throw new RuntimeException('This email-connected Canvas uses read-only app data.',403);
        }
        $result = match ($action) {
            'list' => ['canvases'=>vp3_coding_canvas_list_v1($pdo,$user,$aid),'command_catalog'=>array_values(vp3_coding_canvas_command_library_v1())],
            'open' => vp3_coding_canvas_read_v1($pdo, $user, $aid, $canvasId, $revision),
            'save_library_image' => vp3_coding_canvas_library_image_save_v1($pdo,$user,$aid,$cid,$canvasId,$revision,$expectedImage,$imageId),
            'test' => vp3_coding_canvas_email_test_v1($pdo,$user,$aid,$cid,vp3_coding_canvas_id_v1($canvasId),$revision,$stateRevision,
                ($input->email_fixture_checked ?? false) === true,$expectedImage),
            'apply' => vp3_coding_canvas_activate_v1($pdo, $user, $aid, $canvasId, $revision, $active, $expectedImage),
            'save_state' => vp3_coding_canvas_save_state_v1($pdo, $user, $aid, $canvasId, $revision, $stateRevision, $state),
            'email_data' => ['email' => vp3_coding_canvas_email_data_v1($pdo,$user,$aid,$cid,vp3_coding_canvas_id_v1($canvasId),$revision)],
            'delete' => vp3_coding_canvas_delete_v1($pdo, $user, $aid, $canvasId, $deleteFolder),
            default => throw new InvalidArgumentException('Choose a Canvas operation.'),
        };
        if ($action === 'list' && $cid > 0) {
            require_once dirname(__DIR__).'/includes/agent-initiative-email-canvas-start-v1.php';
            try {
                $result['email_start_available'] = vp3_initiative_email_canvas_start_available_v1($pdo, $user, $aid, $cid);
            } catch (RuntimeException $error) {
                $result['email_start_available'] = false;
            }
        }
    }
        if (in_array($action,['open','test','apply','save_library_image','save_source','save_checks','begin_check','finish_check','recover_source'],true)) {
            require_once dirname(__DIR__).'/includes/coding-canvas-email-v1.php';
            $result['email_source'] = vp3_coding_canvas_email_binding_v1($pdo,$user,$aid,$canvasId,$result['revision']['id']) !== null;
            $canvas=vp3_coding_canvas_require_row_v1($pdo,(int)$user['id'],$aid,vp3_coding_canvas_id_v1($canvasId));
            $result['library_image']=vp3_coding_canvas_library_image_view_v1($pdo,$user,$canvas,$cid,$result['revision']['id']);
            $result['command_grants']=vp3_coding_canvas_command_settings_v1($pdo,$canvas);
            if(empty($result['command_grants']['app_data']['app_access'])){
                $result['state']['value']=(object)[];
            }
            $result['source_editor']=['ready'=>vp3_coding_canvas_source_ready_v1($pdo),
                'can_edit'=>!defined('VP3_PHONE_AGENT_NATIVE_V1')&&personal_capability_has_v242('personal_knowledge.manage',$user)];
            $result['workflow_testing']=['ready'=>vp3_coding_canvas_checks_ready_v1($pdo),
                'can_edit'=>!defined('VP3_PHONE_AGENT_NATIVE_V1')&&personal_capability_has_v242('personal_knowledge.manage',$user)];
        }
    if(defined('VP3_PHONE_AGENT_NATIVE_V1')&&isset($result['revision'])){
        unset($result['revision']['workflow_checks'],$result['revision']['workflow_result']);
    }
    echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $code = $error->getCode();
    $status = match (true) {
        $error instanceof InvalidArgumentException, $error instanceof JsonException => 422,
        $error instanceof PDOException => 503,
        in_array($code, [401, 402, 403, 404, 405, 409, 413, 419, 422, 503], true) => $code,
        default => 503,
    };
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $status === 503
        ? 'Canvas storage or the Coding Agent is unavailable. Check Database Upgrade and try again.'
        : ($error instanceof JsonException ? 'Enter a valid Canvas request.' : $error->getMessage())],
        JSON_INVALID_UTF8_SUBSTITUTE);
}
