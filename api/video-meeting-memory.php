<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/video-meetings-memory-v18120.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();
if(!$user){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Sign in to search meeting memory.']);exit;}
$pdo=db();
if(!$pdo||!video_meeting_schema_ready_v1800($pdo)||!video_meeting_intelligence_schema_ready_v1820($pdo)){
    http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Meeting Search & Memory is not ready. Run the current database upgrade first.']);exit;
}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');
if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
$action=strtolower(trim((string)($input['action']??'search')));$userId=(int)$user['id'];

try{
    if($action==='search'){
        $query=(string)($input['query']??'');$limit=(int)($input['limit']??VP3_VIDEO_MEETINGS_MEMORY_RESULT_LIMIT_V18120);$cursor=(int)($input['cursor']??0);
        $search=video_meeting_memory_search_v18120($pdo,$userId,$query,$limit,$cursor);
        echo json_encode(['ok'=>true,'search'=>$search],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action==='refresh'){
        $publicId=strtolower(trim((string)($input['meeting']??'')));$meeting=video_meeting_by_public_id_v1800($pdo,$publicId);
        if(!$meeting||(int)($meeting['owner_user_id']??0)!==$userId){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Meeting not found.']);exit;}
        $index=video_meeting_memory_ensure_index_v18120($pdo,$meeting,true);
        echo json_encode(['ok'=>true,'memory'=>[
            'version'=>'v18.12','meeting_id'=>(int)$meeting['id'],'source_hash'=>(string)$index['source_hash'],'index_hash'=>(string)$index['index_hash'],
            'artifact_id'=>(int)($index['artifact_id']??0),'generated_at'=>(string)($index['artifact_generated_at']??$index['generated_at']??''),'entry_count'=>count((array)($index['entries']??[])),
        ]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unsupported Meeting Search & Memory action.']);
}catch(RuntimeException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('VP3 meeting memory: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Meeting Search & Memory could not complete this request.']);}
