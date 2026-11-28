<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-preferences-v1.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
function vp3_music_preferences_api_reply_v1(int $status,array $value): never
{
    http_response_code($status);echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');vp3_music_preferences_api_reply_v1(405,['ok'=>false,'error'=>'Use POST.']);
}
$user=current_user();
if(!$user)vp3_music_preferences_api_reply_v1(401,['ok'=>false,'error'=>'Sign in to your account.']);
try{
    if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('Music preference request is too large.',413);
    $raw=file_get_contents('php://input',false,null,0,16385);
    if($raw===false||strlen($raw)>16384)throw new RuntimeException('Music preference request is too large.',413);
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new RuntimeException('Send a JSON music preference request.',415);
    try{$input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Invalid music preference request.',422);}
    if(!is_array($input)||array_is_list($input))throw new RuntimeException('Invalid music preference request.',422);
    if(!is_string($input['csrf_token']??null)||$input['csrf_token']===''||!is_string($_SESSION['csrf_token']??null)
        ||!hash_equals($_SESSION['csrf_token'],$input['csrf_token']))throw new RuntimeException('Refresh the page before using music preferences.',419);
    $integer=static function(string $name,int $minimum=0)use($input):int{
        $value=$input[$name]??null;
        if(!is_int($value)||$value<$minimum||$value>9007199254740991)throw new RuntimeException('Invalid '.$name.'.',422);
        return $value;
    };
    $string=static function(string $name)use($input):string{
        if(!is_string($input[$name]??null))throw new RuntimeException('Invalid '.$name.'.',422);return $input[$name];
    };
    $agent=$integer('agent_id');$cid=$integer('conversation_id',1);$action=$string('action');
    $fields=match($action){
        'read'=>['selection'],'set'=>['selection','preference','expected_revision','request_id'],
        'skip'=>['selection','receipt','request_id'],'list'=>['limit','cursor','source'],
        default=>throw new RuntimeException('Unknown music preference action.',422),
    };
    vp3_music_preferences_fields_v1($input,['csrf_token','agent_id','conversation_id','action',...$fields]);
    if($action!=='list'&&(!is_array($input['selection']??null)||array_is_list($input['selection'])))throw new RuntimeException('Choose an exact music selection.',422);
    $pdo=db();if(!$pdo)throw new RuntimeException('Music preferences are temporarily unavailable.',503);
    if($action==='read')$value=['state'=>vp3_music_preferences_read_v1($pdo,$user,$agent,$cid,$input['selection'])];
    elseif($action==='set')$value=vp3_music_preferences_set_v1($pdo,$user,$agent,$cid,$input['selection'],$string('preference'),$integer('expected_revision'),$string('request_id'));
    elseif($action==='skip'){
        if(!is_array($input['receipt']??null)||array_is_list($input['receipt']))throw new RuntimeException('Choose an acknowledged music play.',422);
        $value=vp3_music_preferences_skip_v1($pdo,$user,$agent,$cid,$input['selection'],$input['receipt'],$string('request_id'));
    }else{
        vp3_media_playback_secret_v1();
        $value=['preferences'=>vp3_music_preferences_list_v1($pdo,$user,$agent,$cid,array_intersect_key($input,array_flip($fields)))];
    }
    vp3_music_preferences_api_reply_v1(200,['ok'=>true]+$value);
}catch(RuntimeException $error){
    $status=(int)$error->getCode();if(!in_array($status,[401,403,404,409,410,413,415,419,422,503],true))$status=503;
    $upgrade=$status===503&&str_contains($error->getMessage(),'Upgrade');
    vp3_music_preferences_api_reply_v1($status,['ok'=>false,'code'=>$upgrade?'upgrade_required':'preference_unavailable',
        'error'=>$upgrade?$error->getMessage():($status===503?'Music preferences are temporarily unavailable. Try again shortly.':$error->getMessage())]);
}catch(Throwable){
    vp3_music_preferences_api_reply_v1(503,['ok'=>false,'error'=>'Music preferences are temporarily unavailable. Try again shortly.']);
}
