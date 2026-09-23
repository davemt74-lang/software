<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$pdo=db();try{
 $auth=vp3_connected_site_auth_v100($pdo,'transcriptions.read');$uid=(int)$auth['user_id'];$id=strtolower(trim((string)($_GET['id']??'')));
 if($id===''){
   $rows=[];foreach(vp3_connected_site_meeting_rows_v100($pdo,$uid,80) as $m)$rows[]=vp3_connected_site_meeting_descriptor_v100($pdo,$m,$uid,$auth['scopes']);
   foreach(vp3_connected_site_transcription_rows_v100($pdo,$uid,80) as $s)$rows[]=vp3_connected_site_transcription_descriptor_v100($pdo,$s,$auth['scopes']);
   usort($rows,static fn($a,$b)=>strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??'')));$rows=array_slice($rows,0,100);
   echo json_encode(['ok'=>true,'data'=>['artifacts'=>$rows]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
 }
 if(str_starts_with($id,'transcription-')){
   $s=vp3_connected_site_transcription_access_v100($pdo,$uid,$id);if(!$s){http_response_code(404);throw new RuntimeException('VP3 transcription is unavailable.');}$descriptor=vp3_connected_site_transcription_descriptor_v100($pdo,$s,$auth['scopes']);$transcript=vp3_connected_site_transcription_text_v100($pdo,$s);$summary=in_array('transcriptions.intelligence.read',$auth['scopes'],true)?vp3_connected_site_transcription_summary_v100($pdo,$s):null;
 }else{
   $m=vp3_connected_site_meeting_access_v100($pdo,$uid,$id);if(!$m){http_response_code(404);throw new RuntimeException('VP3 meeting is unavailable.');}$descriptor=vp3_connected_site_meeting_descriptor_v100($pdo,$m,$uid,$auth['scopes']);$transcript=vp3_connected_site_meeting_transcript_v100($pdo,$m);$summary=in_array('transcriptions.intelligence.read',$auth['scopes'],true)?vp3_connected_site_meeting_summary_v100($pdo,$m,$uid):null;
 }
 echo json_encode(['ok'=>true,'data'=>['artifact'=>$descriptor,'transcript'=>$transcript,'ai_summary'=>$summary]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(401);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);}
