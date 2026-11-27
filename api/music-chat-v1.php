<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-chat-library-v1.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function vp3_music_chat_api_reply_v1(int $status,array $value): never
{
    http_response_code($status);
    echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');vp3_music_chat_api_reply_v1(405,['ok'=>false,'error'=>'Use POST.']);
}
$user=current_user();
if(!$user)vp3_music_chat_api_reply_v1(401,['ok'=>false,'error'=>'Sign in to your account.']);

try{
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)throw new RuntimeException('Music request is too large.',413);
    $raw=file_get_contents('php://input',false,null,0,65537);
    if($raw===false||strlen($raw)>65536)throw new RuntimeException('Music request is too large.',413);
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new RuntimeException('Send a JSON music request.',415);
    try{$input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Invalid music request.',422);}
    if(!is_array($input)||array_is_list($input))throw new RuntimeException('Invalid music request.',422);
    if(!is_string($input['csrf_token']??null)||$input['csrf_token']===''||!is_string($_SESSION['csrf_token']??null)
        ||!hash_equals($_SESSION['csrf_token'],$input['csrf_token']))throw new RuntimeException('Refresh the page before using music controls.',419);
    $integer=static function(string $key,int $min=0,int $max=9007199254740991)use($input):int{
        $value=$input[$key]??null;
        if(!is_int($value)||$value<$min||$value>$max)throw new RuntimeException('Invalid '.$key.'.',422);
        return $value;
    };
    $string=static function(string $key)use($input):string{
        if(!is_string($input[$key]??null))throw new RuntimeException('Invalid '.$key.'.',422);
        return $input[$key];
    };
    $agent=$integer('agent_id');$cid=$integer('conversation_id',1);$action=$string('action');
    $fields=match($action){
        'result'=>['result_id'], 'playlists'=>['limit','offset'], 'playlist'=>['playlist_id'],
        'resolve'=>['result_id','playlist_id','index','revision','auto_action_id'],
        'playlist_create'=>['result_id','title','request_key'],
        'playlist_update'=>['playlist_id','revision','title','order'],
        'playlist_add'=>['playlist_id','revision','result_id','index'],
        'playlist_remove'=>['playlist_id','revision'],
        default=>throw new RuntimeException('Unknown music action.',422),
    };
    if(array_diff(array_keys($input),['csrf_token','agent_id','conversation_id','action',...$fields]))throw new RuntimeException('Unexpected music request fields.',422);
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Music is temporarily unavailable.',503);
    $value=match($action){
        'result'=>['music_chat_v1'=>vp3_music_chat_result_read_v1($pdo,$user,$agent,$cid,$string('result_id'))],
        'playlists'=>vp3_music_chat_playlist_list_v1($pdo,$user,$agent,$cid,isset($input['limit'])?$integer('limit',1,100):25,isset($input['offset'])?$integer('offset',0,1000000):0),
        'playlist'=>['music_chat_v1'=>vp3_music_chat_playlist_get_v1($pdo,$user,$agent,$cid,$string('playlist_id'))],
        'resolve'=>vp3_music_chat_resolve_v1($pdo,$user,$agent,$cid,array_intersect_key($input,array_flip($fields))),
        'playlist_create'=>['music_chat_v1'=>vp3_music_chat_playlist_create_v1($pdo,$user,$agent,$cid,$string('result_id'),$string('title'),$string('request_key'))],
        'playlist_update'=>['music_chat_v1'=>vp3_music_chat_playlist_update_v1($pdo,$user,$agent,$cid,$string('playlist_id'),$integer('revision',1),
            array_key_exists('title',$input)?$string('title'):null,
            array_key_exists('order',$input)?(is_array($input['order'])?$input['order']:throw new RuntimeException('Invalid playlist order.',422)):null)],
        'playlist_add'=>['music_chat_v1'=>vp3_music_chat_playlist_add_v1($pdo,$user,$agent,$cid,$string('playlist_id'),$integer('revision',1),$string('result_id'),$integer('index',0,24))],
        'playlist_remove'=>(static function()use($pdo,$user,$agent,$cid,$string,$integer):array{
            vp3_music_chat_playlist_remove_v1($pdo,$user,$agent,$cid,$string('playlist_id'),$integer('revision',1));return ['removed'=>true];
        })(),
    };
    vp3_music_chat_api_reply_v1(200,['ok'=>true]+$value);
}catch(RuntimeException $error){
    if($error instanceof MusicChatUpgradeRequiredV1)vp3_music_chat_api_reply_v1(503,['ok'=>false,'code'=>'upgrade_required','error'=>$error->getMessage()]);
    $status=(int)$error->getCode();
    if(!in_array($status,[401,403,404,409,410,413,415,419,422,503],true))$status=503;
    vp3_music_chat_api_reply_v1($status,['ok'=>false,'error'=>$status===503?'Music is temporarily unavailable. Try again shortly.':$error->getMessage()]);
}catch(Throwable){
    vp3_music_chat_api_reply_v1(503,['ok'=>false,'error'=>'Music is temporarily unavailable. Try again shortly.']);
}
