<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/human-messaging-block-v370.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function vp3_messages_json_v320(array $payload,int $status=200): never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function vp3_messages_require_post_v320(): void{if($_SERVER['REQUEST_METHOD']!=='POST')vp3_messages_json_v320(['ok'=>false,'error'=>'method_not_allowed'],405);if(!verify_csrf())vp3_messages_json_v320(['ok'=>false,'error'=>'csrf'],419);}
$user=current_user();if(!$user)vp3_messages_json_v320(['ok'=>false,'error'=>'login_required'],401);$uid=(int)$user['id'];$pdo=db();if(!$pdo)vp3_messages_json_v320(['ok'=>false,'error'=>'database_unavailable'],503);
try{vp3_human_messaging_v370_ensure_schema($pdo);if(!vp3_human_messaging_v370_ready($pdo))vp3_messages_json_v320(['ok'=>false,'error'=>'upgrade_required'],503);}catch(Throwable $e){vp3_messages_json_v320(['ok'=>false,'error'=>'upgrade_required'],503);}
$action=(string)($_POST['action']??$_GET['action']??'inbox');
try{
 if($action==='inbox')vp3_messages_json_v320(['ok'=>true,'inbox'=>vp3_human_inbox_v370($pdo,$uid),'settings'=>vp3_social_settings_v320($pdo,$uid)]);
 if($action==='conversation'){$cid=max(0,(int)($_GET['conversation_id']??0));$after=max(0,(int)($_GET['after']??0));$c=vp3_human_conversation_v370($pdo,$cid);if(!$c||!vp3_human_can_access_v370($pdo,$c,$uid))vp3_messages_json_v320(['ok'=>false,'error'=>'not_found'],404);vp3_messages_json_v320(['ok'=>true,'conversation'=>$c,'request'=>vp3_human_request_v370($pdo,$cid),'messages'=>vp3_human_messages_v370($pdo,$cid,$uid,$after),'last_read_message_id'=>vp3_human_read_cursor_v370($pdo,$cid,$uid)]);}
 if($action==='relationship'){$target=max(0,(int)($_GET['user_id']??0));if($target<1||$target===$uid)vp3_messages_json_v320(['ok'=>false,'error'=>'invalid_user'],422);vp3_messages_json_v320(['ok'=>true,'relationship'=>vp3_social_relationship_state_v320($pdo,$uid,$target)]);}
 if($action==='start'){vp3_messages_require_post_v320();$result=vp3_human_start_direct_v370($pdo,$uid,max(0,(int)($_POST['user_id']??0)),(string)($_POST['message']??''));vp3_messages_json_v320(['ok'=>true]+$result);}
 if($action==='send'){vp3_messages_require_post_v320();vp3_messages_json_v320(['ok'=>true,'message'=>vp3_human_send_message_v370($pdo,max(0,(int)($_POST['conversation_id']??0)),$uid,(string)($_POST['message']??''))]);}
 if($action==='read'){vp3_messages_require_post_v320();$cid=max(0,(int)($_POST['conversation_id']??0));$through=max(0,(int)($_POST['through_message_id']??0));vp3_messages_json_v320(['ok'=>true,'last_read_message_id'=>vp3_human_mark_read_v370($pdo,$cid,$uid,$through)]);}
 if($action==='team_general'){vp3_messages_require_post_v320();vp3_messages_json_v320(['ok'=>true,'conversation'=>vp3_human_team_general_v370($pdo,max(0,(int)($_POST['workspace_owner_user_id']??0)),$uid)]);}
 if($action==='request_accept'||$action==='request_decline'){vp3_messages_require_post_v320();$cid=max(0,(int)($_POST['conversation_id']??0));vp3_human_resolve_request_v370($pdo,$cid,$uid,$action==='request_accept');vp3_messages_json_v320(['ok'=>true,'status'=>$action==='request_accept'?'accepted':'declined']);}
 if($action==='follow'||$action==='unfollow'){vp3_messages_require_post_v320();$target=max(0,(int)($_POST['user_id']??0));vp3_social_follow_v320($pdo,$uid,$target,$action==='follow');vp3_messages_json_v320(['ok'=>true,'following'=>$action==='follow']);}
 if($action==='friend_request'){vp3_messages_require_post_v320();vp3_social_friend_request_v320($pdo,$uid,max(0,(int)($_POST['user_id']??0)));vp3_messages_json_v320(['ok'=>true,'status'=>'pending']);}
 if($action==='friend_accept'){vp3_messages_require_post_v320();vp3_social_friend_accept_v320($pdo,$uid,max(0,(int)($_POST['user_id']??0)));vp3_messages_json_v320(['ok'=>true,'status'=>'accepted']);}
 if($action==='block'||$action==='unblock'){vp3_messages_require_post_v320();$target=max(0,(int)($_POST['user_id']??0));vp3_human_set_block_v370($pdo,$uid,$target,$action==='block');vp3_messages_json_v320(['ok'=>true,'blocked'=>$action==='block']);}
 if($action==='settings'){vp3_messages_require_post_v320();vp3_messages_json_v320(['ok'=>true,'settings'=>vp3_social_save_settings_v320($pdo,$uid,$_POST)]);}
 vp3_messages_json_v320(['ok'=>false,'error'=>'unknown_action'],404);
}catch(RuntimeException $e){vp3_messages_json_v320(['ok'=>false,'error'=>'validation','message'=>$e->getMessage()],422);}catch(Throwable $e){error_log('VP3 messages v370: '.$e->getMessage());vp3_messages_json_v320(['ok'=>false,'error'=>'server_error'],500);}
