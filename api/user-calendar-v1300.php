<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/user-calendar-v1300.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user=current_user();$pdo=db();
if(!$user||!has_permission('account.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Calendar access is not available for this account.']);exit;}
if(!$pdo||!user_calendar_schema_ready_v1300($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'User Calendar is not ready. An administrator needs to run the database upgrade.']);exit;}

function user_calendar_api_range_v1300(PDO $pdo,array $user,array $input): array
{
    $timezone=user_calendar_default_timezone_v1300($pdo,$user);$tz=new DateTimeZone($timezone);
    $fromText=trim((string)($input['from']??''));$toText=trim((string)($input['to']??''));
    if($fromText==='')$fromText=(new DateTimeImmutable('first day of this month',$tz))->format('Y-m-d');
    if($toText==='')$toText=(new DateTimeImmutable($fromText,$tz))->modify('+42 days')->format('Y-m-d');
    $from=DateTimeImmutable::createFromFormat('!Y-m-d',$fromText,$tz);$to=DateTimeImmutable::createFromFormat('!Y-m-d',$toText,$tz);
    if(!$from||!$to||$from->format('Y-m-d')!==$fromText||$to->format('Y-m-d')!==$toText||$to<=$from)throw new RuntimeException('Calendar range is invalid.');
    if(($to->getTimestamp()-$from->getTimestamp())>370*86400)throw new RuntimeException('Calendar range is too large.');
    return [$timezone,$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        [$timezone,$fromUtc,$toUtc]=user_calendar_api_range_v1300($pdo,$user,$_GET);
        echo json_encode(['ok'=>true,'timezone'=>$timezone,'events'=>user_calendar_events_v1300($pdo,$user,$fromUtc,$toUtc)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
    $csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}
    $action=trim((string)($input['action']??''));
    if($action==='create'){
        $event=user_calendar_create_local_event_v1300($pdo,$user,$input,'user');
        agent_tool_log($user,'calendar.event.create','Calendar page event','success',['event_id'=>(int)$event['id'],'source'=>'user']);
        echo json_encode(['ok'=>true,'event'=>$event],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action==='update'){
        $event=user_calendar_update_event_v1300($pdo,$user,(int)($input['event_id']??0),$input);
        agent_tool_log($user,'calendar.event.update','Calendar page event','success',['event_id'=>(int)$event['id']]);
        echo json_encode(['ok'=>true,'event'=>$event],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action==='cancel'){
        $eventId=(int)($input['event_id']??0);if(!user_calendar_cancel_event_v1300($pdo,$user,$eventId))throw new RuntimeException('Calendar event could not be removed.');
        agent_tool_log($user,'calendar.event.cancel','Calendar page event','success',['event_id'=>$eventId]);
        echo json_encode(['ok'=>true,'event_id'=>$eventId]);exit;
    }
    throw new RuntimeException('Unknown calendar action.');
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
