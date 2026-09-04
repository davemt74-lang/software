<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function stem_region_note_v101_json(bool $ok,array $extra=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok]+$extra,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user=current_user();
if(!$user||!has_permission('admin.access',$user))stem_region_note_v101_json(false,['error'=>'Stem Studio access is unavailable.'],403);
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))stem_region_note_v101_json(false,['error'=>'Session expired. Refresh and try again.'],419);

try{
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!table_exists('track_notes')||!column_exists('track_notes','region_start_seconds')||!column_exists('track_notes','region_end_seconds'))throw new RuntimeException('REGION notes are not ready. Run the v101 database upgrade.');
    $trackId=max(0,(int)($input['track_id']??0));$stmt=$pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');$stmt->execute([$trackId]);$track=$stmt->fetch();
    if(!$track||!can_manage_track_production($track,$user))stem_region_note_v101_json(false,['error'=>'This track has not been shared with your account.'],403);
    $note=trim((string)($input['note']??''));if($note==='')throw new RuntimeException('Enter a REGION note.');if(mb_strlen($note)>5000)throw new RuntimeException('REGION note is too long.');
    $start=max(0.0,min(86400.0,(float)($input['start']??0)));$end=max($start+.05,min(86400.0,(float)($input['end']??$start+.05)));
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('INSERT INTO track_notes (track_id,user_id,note,region_start_seconds,region_end_seconds,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([$trackId,(int)$user['id'],$note,$start,$end]);$noteId=(int)$pdo->lastInsertId();
    $author=trim((string)($user['display_name']??''))?:'A teammate';$range=agent_chat_v101_format_time($start).'–'.agent_chat_v101_format_time($end);
    $message=$author.' shared a REGION note on “'.(string)$track['title'].'” at '.$range.":\n\n".$note;
    $target=url('/admin/stems.php?track='.$trackId.'&region_note='.$noteId);
    $context=['sources'=>[['source'=>'database:track_notes','title'=>(string)$track['title'].' · '.$range]],'media'=>[],'stem_media'=>[],'actions'=>[['label'=>'Open REGION note','url'=>$target]],'playlist_title'=>'','region_note'=>['id'=>$noteId,'track_id'=>$trackId,'start'=>$start,'end'=>$end,'author'=>$author]];
    $conversationIds=[];
    foreach(agent_chat_v101_note_recipients($track,$user) as $recipient){
        $recipientId=(int)$recipient['id'];$conversationIds[$recipientId]=agent_chat_v101_append_ecosystem_message($recipient,$message,$context);
        if($recipientId!==(int)$user['id'])create_notification($recipientId,'stem_region_note',(string)$track['title'].' · REGION note',$author.' left a note at '.$range.': '.mb_strimwidth($note,0,300,'…'),$target,'track_note',$noteId);
    }
    $pdo->commit();
    stem_region_note_v101_json(true,['note'=>['id'=>$noteId,'track_id'=>$trackId,'start'=>$start,'end'=>$end,'label'=>mb_strimwidth($note,0,80,'…'),'note'=>$note,'author'=>$author,'created_at'=>date('Y-m-d H:i:s'),'shared'=>true],'conversation_ids'=>$conversationIds,'message'=>'REGION note shared in Agent Chat.']);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('REGION note share failed: '.$e->getMessage());stem_region_note_v101_json(false,['error'=>$e instanceof RuntimeException?$e->getMessage():'Could not share the REGION note.'],400);}
