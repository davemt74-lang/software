<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require __DIR__.'/meeting-prep-calendar-fixture.php';
header('Content-Type: application/json');
$pdo=db();
$action=(string)($_GET['action']??'state');
if($action==='seed'){
    echo json_encode(vp3_test_meeting_prep_seed_v1($pdo,new DateTimeImmutable('now',new DateTimeZone('UTC')),7));
    exit;
}
if($action==='edit'){
    $pdo->exec("UPDATE video_meeting_agenda_items SET item_text='Updated follow-up after review',updated_at='changed' WHERE id=7942");
}elseif($action==='revoke'){
    $pdo->exec("INSERT INTO user_agent_data_rules(agent_id,resource_type,resource_id,access_mode) VALUES(7,'recording','*','deny')");
}elseif($action==='restore'){
    $pdo->exec("DELETE FROM user_agent_data_rules WHERE agent_id=7 AND resource_type='recording'");
}elseif($action==='mutate_approval'){
    $pdo->prepare("UPDATE chat_messages SET message='Changed confirmation after the fact' WHERE id=? AND role='user'")
        ->execute([(int)($_GET['message']??0)]);
}elseif($action==='compute_local'){
    $pdo->exec("UPDATE agent_compute_preferences SET preference='homeserver_only' WHERE user_id=1");
}elseif($action==='compute_cloud'){
    $pdo->exec("UPDATE agent_compute_preferences SET preference='vp3_cloud' WHERE user_id=1");
}
$tasks=$pdo->query("SELECT id,user_id,user_agent_id,subject,metadata_json FROM agent_memory_items WHERE memory_type='task' ORDER BY id")->fetchAll();
$runs=$pdo->query("SELECT id,owner_user_id,agent_id,status,source_kind,goal FROM agent_workflow_runs ORDER BY id")->fetchAll();
$receipts=$pdo->query('SELECT id,run_id,action_id,status,result_json FROM agent_workflow_receipts ORDER BY id')->fetchAll();
$reminders=$pdo->query('SELECT id,owner_user_id,title,conversation_id,next_at,status FROM agent_reminders_v1 ORDER BY id')->fetchAll();
$meeting=table_exists('video_meetings')?$pdo->query('SELECT id,calendar_event_id,start_at_utc,end_at_utc,status FROM video_meetings WHERE id=7912')->fetch():null;
$calendar=$pdo->query('SELECT id,start_at_utc,end_at_utc,status FROM user_calendar_events WHERE id=7901')->fetch();
$agenda=table_exists('video_meeting_agenda_items')?$pdo->query('SELECT id,item_text,item_type,status FROM video_meeting_agenda_items WHERE meeting_id=7912 ORDER BY id')->fetchAll():[];
echo json_encode(['tasks'=>$tasks,'runs'=>$runs,'receipts'=>$receipts,'reminders'=>$reminders,'meeting'=>$meeting,'calendar'=>$calendar,'agenda'=>$agenda]);
