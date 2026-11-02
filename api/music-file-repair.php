<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-file-repair.php';
header('Cache-Control: no-store');
function music_file_repair_response(array $data,int $status): never
{
    if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){http_response_code($status);header('Content-Type: application/json; charset=UTF-8');echo json_encode($data,JSON_UNESCAPED_SLASHES);exit;}
    flash($status===200?'notice':'error',(string)($data['message']??$data['error']??'Audio replacement failed.'));
    redirect(url('/music-file-repair.php?workspace='.max(0,(int)($_POST['workspace_id']??0))));
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');music_file_repair_response(['ok'=>false,'error'=>'Use the replacement upload form.'],405);}
$user=current_user();if(!$user)music_file_repair_response(['ok'=>false,'error'=>'Sign in before replacing song audio.'],401);
if($_POST===[]&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)music_file_repair_response(['ok'=>false,'error'=>'The server rejected this upload size. Choose a smaller export or increase the server upload limit.'],413);
if(!verify_csrf())music_file_repair_response(['ok'=>false,'error'=>'Session expired. Reload before uploading.'],419);
$pdo=db();if(!$pdo)music_file_repair_response(['ok'=>false,'error'=>'Database unavailable.'],503);
try{
    $result=music_file_repair_replace($pdo,max(0,(int)($_POST['workspace_id']??0)),$user,(string)($_POST['kind']??''),max(0,(int)($_POST['id']??0)),(string)($_POST['revision']??''),$_FILES['audio_file']??[]);
    music_file_repair_response(['ok'=>true,'message'=>'Replacement audio saved. The song and its related records have been kept.','track'=>$result],200);
}catch(DomainException $e){music_file_repair_response(['ok'=>false,'error'=>$e->getMessage()],403);}
catch(UnexpectedValueException $e){music_file_repair_response(['ok'=>false,'error'=>$e->getMessage()],409);}
catch(PDOException $e){error_log('Audio replacement database write failed.');music_file_repair_response(['ok'=>false,'error'=>'Audio could not be saved. The existing song has been kept.'],500);}
catch(Throwable $e){music_file_repair_response(['ok'=>false,'error'=>$e->getMessage()],422);}
