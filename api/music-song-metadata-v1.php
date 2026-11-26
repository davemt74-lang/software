<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function music_song_metadata_api_v1_reply(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}

try{
    $user=current_user();
    if(!$user)music_song_metadata_api_v1_reply(['ok'=>false,'message'=>'Please sign in to manage song metadata.'],401);
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if(!in_array($method,['GET','POST'],true)){
        header('Allow: GET, POST');
        music_song_metadata_api_v1_reply(['ok'=>false,'message'=>'Use GET to read or POST to save song metadata.'],405);
    }
    if($method==='POST'&&str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){
        $body=file_get_contents('php://input',false,null,0,196609);
        if($body===false||strlen($body)>196608)throw new RuntimeException('The song metadata request is too large.',422);
        try{$decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('The song metadata request is invalid.',422);}
        if(!is_array($decoded)||array_is_list($decoded))throw new RuntimeException('The song metadata request is invalid.',422);
        $_POST=$decoded;
    }
    if($method==='POST'&&!verify_csrf())music_song_metadata_api_v1_reply(['ok'=>false,'message'=>'Your session expired. Refresh the page and try again.'],419);
    $input=$method==='POST'?$_POST:$_GET;
    $workspaceValue=$input['workspace_id']??'';
    $idValue=$input['id']??'0';
    if(!is_scalar($workspaceValue)||!preg_match('/^[1-9]\d{0,9}$/',(string)$workspaceValue))throw new RuntimeException('Choose a Music Workspace.',422);
    if(!is_scalar($idValue)||!preg_match('/^\d{1,10}$/',(string)$idValue))throw new RuntimeException('Choose a valid song.',422);
    $workspaceId=(int)$workspaceValue;$id=(int)$idValue;
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database is unavailable.',503);
    if(!music_workspace_resources_v330_schema_ready($pdo)||!music_song_metadata_v1_schema_ready($pdo)){
        music_song_metadata_api_v1_reply(['ok'=>false,'message'=>'Install the Music Metadata database upgrade before managing song metadata.','upgrade_required'=>true],503);
    }
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user))throw new RuntimeException('Song metadata is not available to your workspace role.',403);
    if($method==='POST'){
        $action=$input['action']??'save';
        if(!is_string($action))throw new RuntimeException('Choose a valid song action.',422);
        if($id===0&&$action==='save'&&empty($input['request_key']))throw new RuntimeException('The save request is missing. Reload the form and try again.',422);
        $id=music_catalog_write($pdo,$workspaceId,$user,'track',$action,$input,$_FILES);
    }
    $catalog=$id>0?artist_music_v185_track($pdo,$workspaceId,$id):null;
    if($id>0&&!$catalog)throw new RuntimeException('Song was not found in this Music Workspace.',404);
    $recording=$id>0?music_song_metadata_v1_get($pdo,$workspaceId,$id,$user):null;
    $response=[
        'ok'=>true,'id'=>$id,'catalog_track_id'=>$id,
        'player_track_id'=>(int)($catalog['source_track_id']??0),
        'catalog'=>$catalog,'revision'=>$catalog?music_catalog_revision($catalog):null,
        'recording'=>$recording,
    ];
    if($method==='GET'){
        $response['parties']=music_song_metadata_v1_parties($pdo,$workspaceId,$user);
        $response['works']=music_song_metadata_v1_works($pdo,$workspaceId,$user);
    }
    music_song_metadata_api_v1_reply($response);
}catch(Throwable $e){
    $status=(int)$e->getCode();
    $expected=$e instanceof RuntimeException&&!($e instanceof PDOException)&&in_array($status,[403,404,409,422,503],true);
    if(!$expected)error_log('Music metadata request failed: '.$e->getMessage());
    music_song_metadata_api_v1_reply(['ok'=>false,'message'=>$expected?$e->getMessage():'The song could not be saved. Please try again.','conflict'=>$status===409],$expected?$status:500);
}
