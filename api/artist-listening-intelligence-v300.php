<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/artist-listening.php';
require_once dirname(__DIR__) . '/includes/artist-listening-transcript.php';
require_once dirname(__DIR__) . '/includes/transcription-app-registry.php';
require_once dirname(__DIR__) . '/includes/transcription-apps-wave2.php';
require_once dirname(__DIR__) . '/includes/transcription-apps-wave2-save.php';
require_once dirname(__DIR__) . '/includes/transcription-intelligence-items.php';
require_once dirname(__DIR__) . '/includes/transcription-intelligence-relations.php';
require_once dirname(__DIR__) . '/includes/transcription-intelligence-actions.php';
require_once dirname(__DIR__) . '/includes/transcription-workflow-config.php';

const VP3_TRANSCRIPTION_INTELLIGENCE_V300 = 'vp3-transcription-intelligence-v305-20260906';

function transcription_intelligence_json_v300(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok'=>$ok,'build'=>VP3_TRANSCRIPTION_INTELLIGENCE_V300] + $data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) transcription_intelligence_json_v300(false,['error'=>'Sign in to use transcription intelligence.'],401);
if (!has_permission('artist_listening.access',$user)) transcription_intelligence_json_v300(false,['error'=>'Transcription access is required.'],403);
if (!artist_listening_v172_schema_ready() || !artist_listening_v237_schema_ready()) transcription_intelligence_json_v300(false,['error'=>'Transcription intelligence is not ready. Run the transcript upgrades.'],503);
$pdo = db();
if (!$pdo) transcription_intelligence_json_v300(false,['error'=>'Database unavailable.'],503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = [];
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'),true);
    if (!is_array($input)) $input = $_POST;
    if (!hash_equals(csrf_token(),(string)($input['csrf_token'] ?? ''))) transcription_intelligence_json_v300(false,['error'=>'Session expired. Refresh and try again.'],419);
}
$action = trim((string)($input['action'] ?? $_GET['action'] ?? 'status'));
$sessionId = max(0,(int)($input['session_id'] ?? $_GET['session_id'] ?? 0));
$workflowConfig = transcription_workflow_public_v304();

try {
    $session = $sessionId ? artist_listening_v172_session($pdo,$user,$sessionId) : null;

    if ($method === 'GET' && $action === 'registry') {
        transcription_intelligence_json_v300(true,[
            'registry'=>transcription_app_registry_public_v301(),
            'workflow_config'=>$workflowConfig,
        ]);
    }

    if ($method === 'GET' && $action === 'workflow') {
        transcription_intelligence_json_v300(true,['workflow_config'=>$workflowConfig]);
    }

    if ($method === 'GET' && $action === 'status') {
        if (!$session) transcription_intelligence_json_v300(true,[
            'master'=>null,'app_status'=>[],'registry'=>transcription_app_registry_public_v301(),
            'tags'=>[],'permissions'=>transcription_app_permissions_v300($user),'operations'=>[],
            'workflow_config'=>$workflowConfig,'relations_summary'=>transcription_intelligence_relations_summary_v305(null),
        ]);
        $segments = artist_listening_v172_segments($pdo,$sessionId);
        $map = artist_listening_transcript_page_map($segments);
        $status = artist_listening_v237_analysis_status($pdo,$sessionId,$map);
        $master = is_array($status['master'] ?? null) ? $status['master'] : null;
        if ($master) $master = transcription_intelligence_normalize_master_v302($pdo,$sessionId,$master,[],true);
        transcription_intelligence_json_v300(true,
            transcription_app_status_v301($pdo,$user,$session,$master,$map) + [
                'tags'=>transcription_app_tags_v300($session),
                'permissions'=>transcription_app_permissions_v300($user),
                'operations'=>transcription_intelligence_operational_context_v303($pdo,$user,$session),
                'workflow_config'=>$workflowConfig,
                'relations_summary'=>transcription_intelligence_relations_summary_v305($master),
            ]
        );
    }

    if ($method === 'GET') transcription_intelligence_json_v300(false,['error'=>'Unknown transcription intelligence request.'],404);
    if ($method !== 'POST') transcription_intelligence_json_v300(false,['error'=>'POST is required.'],405);
    if (!$session) transcription_intelligence_json_v300(false,['error'=>'Choose a transcript first.'],422);

    if ($action === 'analyze') {
        $mode = strtolower((string)($input['mode'] ?? 'manual'));
        if (!in_array($mode,['manual','live'],true)) $mode = 'manual';
        $workflowInput = is_array($input['workflow'] ?? null) ? $input['workflow'] : [];
        // Compatibility for the v303 browser while the v304 UI is deployed in this same branch.
        if (!array_key_exists('web_research',$workflowInput) && array_key_exists('research',$input)) {
            $workflowInput['web_research'] = !empty($input['research']);
        }
        if (!array_key_exists('live_analysis',$workflowInput) && $mode === 'live') {
            $workflowInput['live_analysis'] = true;
        }
        $workflow = transcription_workflow_normalize_v304($workflowInput);
        $segments = artist_listening_v172_segments($pdo,$sessionId);
        $map = artist_listening_transcript_page_map($segments);
        $beforeStatus = artist_listening_v237_analysis_status($pdo,$sessionId,$map);
        $beforeMaster = is_array($beforeStatus['master'] ?? null) ? $beforeStatus['master'] : null;
        $reviewIndex = transcription_intelligence_review_index_v302($beforeMaster);

        $result = transcription_app_analyze_v304(
            $pdo,$user,$sessionId,$mode,$input['apps'] ?? ['basic'],$workflow
        );
        $master = is_array($result['master'] ?? null) ? $result['master'] : null;
        if ($master) {
            $master = transcription_intelligence_normalize_master_v302($pdo,$sessionId,$master,$reviewIndex,true);
            $view = transcription_app_status_v301($pdo,$user,$session,$master,$map);
            $result['master'] = $view['master'];
            $result['app_status'] = $view['app_status'];
            $result['registry'] = $view['registry'];
        }
        $result['workflow'] = $workflow;
        $result['workflow_config'] = $workflowConfig;
        $result['operations'] = transcription_intelligence_operational_context_v303($pdo,$user,$session);
        $result['relations_summary'] = transcription_intelligence_relations_summary_v305($master);
        transcription_intelligence_json_v300(true,$result);
    }

    $segments = artist_listening_v172_segments($pdo,$sessionId);
    $map = artist_listening_transcript_page_map($segments);
    $status = artist_listening_v237_analysis_status($pdo,$sessionId,$map);
    $master = is_array($status['master'] ?? null) ? $status['master'] : null;
    if ($master) $master = transcription_intelligence_normalize_master_v302($pdo,$sessionId,$master,[],true);

    if ($action === 'build_relations') {
        if (!$master) throw new RuntimeException('Analyze this transcript before building intelligence connections.');
        $built=transcription_intelligence_build_relations_v305($pdo,$user,$session,$master,(string)$map['source_hash']);
        $master=is_array($built['master']??null)?$built['master']:$master;
        $view=transcription_app_status_v301($pdo,$user,$session,$master,$map);
        transcription_intelligence_json_v300(true,$view + [
            'relations_summary'=>$built['relations_summary']??transcription_intelligence_relations_summary_v305($master),
            'relation_provider'=>(string)($built['provider']??''),'relation_model'=>(string)($built['model']??''),
            'relation_catalog_items'=>max(0,(int)($built['catalog_items']??0)),
            'operations'=>transcription_intelligence_operational_context_v303($pdo,$user,$session),
            'workflow_config'=>$workflowConfig,
        ]);
    }

    if ($action === 'review_relation') {
        if (!$master) throw new RuntimeException('Analyze this transcript before reviewing intelligence connections.');
        $relationId=trim((string)($input['relation_id']??''));
        if ($relationId==='') throw new RuntimeException('Choose an intelligence connection.');
        $master=transcription_intelligence_review_relation_v305(
            $pdo,$sessionId,$master,$relationId,(string)($input['review_state']??'unreviewed')
        );
        $view=transcription_app_status_v301($pdo,$user,$session,$master,$map);
        transcription_intelligence_json_v300(true,$view + [
            'relations_summary'=>transcription_intelligence_relations_summary_v305($master),
            'operations'=>transcription_intelligence_operational_context_v303($pdo,$user,$session),
            'workflow_config'=>$workflowConfig,
        ]);
    }

    if (in_array($action,['review_item','edit_item'],true)) {
        if (!$master) throw new RuntimeException('Analyze this transcript before reviewing intelligence items.');
        $appId = strtolower(trim((string)($input['app_id'] ?? '')));
        $itemId = trim((string)($input['item_id'] ?? ''));
        if ($appId === '' || $itemId === '') throw new RuntimeException('Choose a transcription intelligence item.');

        if ($action === 'review_item') {
            $master = transcription_intelligence_review_item_v302(
                $pdo,$sessionId,$master,$appId,$itemId,(string)($input['review_state'] ?? 'unreviewed')
            );
        } else {
            $master = transcription_intelligence_edit_item_v302(
                $pdo,$sessionId,$master,$appId,$itemId,(string)($input['text'] ?? '')
            );
        }
        $view = transcription_app_status_v301($pdo,$user,$session,$master,$map);
        transcription_intelligence_json_v300(true,$view + [
            'tags'=>transcription_app_tags_v300($session),
            'permissions'=>transcription_app_permissions_v300($user),
            'operations'=>transcription_intelligence_operational_context_v303($pdo,$user,$session),
            'workflow_config'=>$workflowConfig,
            'relations_summary'=>transcription_intelligence_relations_summary_v305($master),
        ]);
    }

    if ($action === 'item_action') {
        if (!$master) throw new RuntimeException('Analyze this transcript before promoting intelligence items.');
        $appId=strtolower(trim((string)($input['app_id'] ?? '')));
        $itemId=trim((string)($input['item_id'] ?? ''));
        $actionId=strtolower(trim((string)($input['item_action'] ?? '')));
        $targetId=max(0,(int)($input['target_id'] ?? 0));
        if ($appId === '' || $itemId === '' || $actionId === '') throw new RuntimeException('Choose an accepted intelligence item and action.');
        $operation=transcription_intelligence_execute_action_v303($pdo,$user,$session,$master,$appId,$itemId,$actionId,$targetId);
        $master=is_array($operation['master'] ?? null) ? $operation['master'] : $master;
        $view=transcription_app_status_v301($pdo,$user,$session,$master,$map);
        transcription_intelligence_json_v300(true,$view + [
            'receipt'=>$operation['receipt'] ?? [],'existing'=>!empty($operation['existing']),
            'operations'=>$operation['operations'] ?? transcription_intelligence_operational_context_v303($pdo,$user,$session),
            'tags'=>transcription_app_tags_v300($session),'permissions'=>transcription_app_permissions_v300($user),
            'workflow_config'=>$workflowConfig,'relations_summary'=>transcription_intelligence_relations_summary_v305($master),
        ]);
    }

    if (in_array($action,['save_brain','save_knowledge'],true) && !$master) throw new RuntimeException('Analyze this transcript before saving the report.');
    $tags = transcription_app_tags_v300($session);
    $view = $master ? transcription_app_status_v301($pdo,$user,$session,$master,$map) : ['app_status'=>[]];
    $text = $master ? transcription_intelligence_report_text_v302($master,$session,$tags,(string)$map['source_hash'],$view['app_status'] ?? []) : '';
    if ($text === '') throw new RuntimeException('No current transcription plugin results are available to save. Refresh any stale plugins and try again.');

    if ($action === 'save_brain') {
        $permissions = transcription_app_permissions_v300($user);
        if (!$permissions['agent_brain_write']) throw new RuntimeException('Agent Brain storage is not available for this account.');
        $id = agent_brain_v122_upsert_system_memory(
            $user,'transcript_analysis','artist-listening:'.$sessionId,mb_strimwidth($text,0,18000,'…'),
            ['source'=>'transcription-intelligence-v304','session_id'=>$sessionId,'title'=>(string)($session['title']??''),'tags'=>$tags,'source_hash'=>(string)$map['source_hash'],'saved_at'=>gmdate('c')],0.98
        );
        if ($id < 1) throw new RuntimeException('Could not save the transcription intelligence to Agent Brain.');
        transcription_intelligence_json_v300(true,['saved'=>true,'memory_id'=>$id,'saved_at'=>gmdate('c')]);
    }

    if ($action === 'save_knowledge') {
        $permissions = transcription_app_permissions_v300($user);
        if (!$permissions['personal_knowledge_write']) throw new RuntimeException('Personal Knowledge Base storage is not available for this account.');
        $title = mb_strimwidth('Transcript Intelligence · '.((string)($session['title']??'')?:('Session '.$sessionId)),0,190,'…');
        $id = personal_knowledge_store($user,'artist-listening-analysis:'.$sessionId,$title,$text,'Personal transcription intelligence · session #'.$sessionId);
        transcription_intelligence_json_v300(true,['saved'=>true,'knowledge_id'=>$id,'scope'=>'personal','published'=>false,'saved_at'=>gmdate('c')]);
    }

    transcription_intelligence_json_v300(false,['error'=>'Unsupported transcription intelligence action.'],422);
} catch (Throwable $e) {
    transcription_intelligence_json_v300(false,['error'=>ai_v100_safe_exception($e,'Transcription intelligence request failed.')],$e instanceof RuntimeException ? 422 : 500);
}
