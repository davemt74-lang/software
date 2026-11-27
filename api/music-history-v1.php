<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-history-chat-v1.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
function vp3_music_history_api_reply_v1(int $status,array $value): never
{
    http_response_code($status);echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');vp3_music_history_api_reply_v1(405,['ok'=>false,'error'=>'Use POST.']);
}
$user=current_user();
if(!$user)vp3_music_history_api_reply_v1(401,['ok'=>false,'error'=>'Sign in to your account.']);
try{
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)throw new RuntimeException('History request is too large.',413);
    $raw=file_get_contents('php://input',false,null,0,65537);
    if($raw===false||strlen($raw)>65536)throw new RuntimeException('History request is too large.',413);
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new RuntimeException('Send a JSON history request.',415);
    try{$input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Invalid history request.',422);}
    if(!is_array($input)||array_is_list($input))throw new RuntimeException('Invalid history request.',422);
    if(!is_string($input['csrf_token']??null)||$input['csrf_token']===''||!is_string($_SESSION['csrf_token']??null)
        ||!hash_equals($_SESSION['csrf_token'],$input['csrf_token']))throw new RuntimeException('Refresh the page before using history controls.',419);
    $integer=static function(string $name,int $min=0)use($input):int{
        $value=$input[$name]??null;
        if(!is_int($value)||$value<$min||$value>9007199254740991)throw new RuntimeException('Invalid '.$name.'.',422);
        return $value;
    };
    $string=static function(string $name)use($input):string{
        if(!is_string($input[$name]??null))throw new RuntimeException('Invalid '.$name.'.',422);return $input[$name];
    };
    $agent=$integer('agent_id');$cid=$integer('conversation_id',1);$action=$string('action');
    $filters=['source','query','from','to','from_at','until_at','timezone','limit','cursor'];
    $fields=match($action){
        'query'=>[...$filters,'source_message_id','revision'],
        'read'=>['source_message_id','revision'],
        'selection'=>['source_message_id','revision','entry_ref','operation','request_id','playlist_id','playlist_revision'],
        default=>throw new RuntimeException('Unknown history action.',422),
    };
    if(array_diff(array_keys($input),['csrf_token','agent_id','conversation_id','action',...$fields]))throw new RuntimeException('Unexpected history request fields.',422);
    $pdo=db();if(!$pdo)throw new RuntimeException('History is temporarily unavailable.',503);
    vp3_music_history_chat_dependencies_v1();
    if($action==='query'){
        $value=vp3_music_history_chat_query_v1($pdo,$user,$agent,$cid,array_intersect_key($input,array_flip($filters)),
            isset($input['source_message_id'])?$integer('source_message_id',1):0,isset($input['revision'])?$string('revision'):null);
    }elseif($action==='read'){
        $id=$integer('source_message_id',1);$page=vp3_music_history_chat_read_v1($pdo,$user,$agent,$cid,$id,$string('revision'));
        $value=['assistant_message_id'=>$id,'answer'=>vp3_music_history_chat_answer_v1($page),'music_history_v1'=>$page];
    }else{
        $operation=$string('operation');
        if(!in_array($operation,['play','resume','add_to_playlist'],true))throw new RuntimeException('Choose Play, Resume or Add to playlist.',422);
        if((isset($input['playlist_id']))!==(isset($input['playlist_revision'])))throw new RuntimeException('Choose the current target playlist and revision together.',422);
        if($operation!=='add_to_playlist'&&(isset($input['playlist_id'])||isset($input['playlist_revision'])))throw new RuntimeException('A playback selection cannot edit a playlist.',422);
        vp3_media_playback_secret_v1();
        $value=vp3_music_history_chat_selection_api_v1($pdo,$user,$agent,$cid,$integer('source_message_id',1),$string('revision'),
            $string('entry_ref'),$operation,$string('request_id'),isset($input['playlist_id'])?$string('playlist_id'):null,
            isset($input['playlist_revision'])?$integer('playlist_revision',1):null);
    }
    vp3_music_history_api_reply_v1(200,['ok'=>true]+$value);
}catch(RuntimeException $error){
    if($error instanceof MusicChatUpgradeRequiredV1)vp3_music_history_api_reply_v1(503,['ok'=>false,'code'=>'upgrade_required','error'=>$error->getMessage()]);
    $status=(int)$error->getCode();if(!in_array($status,[401,403,404,409,410,413,415,419,422,503],true))$status=503;
    vp3_music_history_api_reply_v1($status,['ok'=>false,'error'=>$status===503?'History is temporarily unavailable. Try again shortly.':$error->getMessage()]);
}catch(Throwable){
    vp3_music_history_api_reply_v1(503,['ok'=>false,'error'=>'History is temporarily unavailable. Try again shortly.']);
}
