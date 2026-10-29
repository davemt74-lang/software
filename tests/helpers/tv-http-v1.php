<?php
require dirname(__DIR__,2).'/includes/tv-watching-v1.php';
$pdo=new PDO('sqlite:'.getenv('VP3_TV_TEST_DB'));$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);tv_schema_v1($pdo);header('Content-Type: application/json');
try{
 $user=['id'=>(int)($_SERVER['HTTP_X_TEST_OWNER']??7)];$input=json_decode(file_get_contents('php://input'),true,24,JSON_THROW_ON_ERROR);
 $vision=fn()=>['ok'=>true,'answer'=>json_encode(['title'=>'Cheers','kind'=>'show','episode'=>'','visible_text'=>'Cheers','search_query'=>'Cheers television show'])];
 $search=fn()=>[['title'=>'Cheers TV Series','url'=>'https://www.imdb.com/title/tt0083399/']];
 if(($input['action']??'')==='chat'){echo json_encode(['ok'=>true]+tv_chat_query_v1($pdo,$user,(string)($input['query']??'')));exit;}
 echo json_encode(['ok'=>true,'contract'=>VP3_TV_CONTRACT,'account_binding'=>'fixture:'.$user['id']]+tv_dispatch_v1($pdo,$user,$input,$vision,$search));
}catch(Throwable $e){$code=(int)$e->getCode();http_response_code(in_array($code,[403,404,409,410,429],true)?$code:422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
