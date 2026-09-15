<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/user-calendar-v1300.php';
require_permission('account.access');
$pdo=db();$user=current_user();if(!$pdo||!$user)redirect(url('/login.php'));
if(!user_calendar_schema_ready_v1300($pdo))redirect(url('/upgrade.php'));
if(function_exists('video_meeting_schema_ready_v1800')&&video_meeting_schema_ready_v1800($pdo)){try{video_meeting_reconcile_recent_bookings_v1800($pdo,40);}catch(Throwable $ignored){}}

$timezone=user_calendar_default_timezone_v1300($pdo,$user);$tz=new DateTimeZone($timezone);
$monthInput=trim((string)($_GET['month']??''));
if(!preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/',$monthInput))$monthInput=(new DateTimeImmutable('now',$tz))->format('Y-m');
$month=DateTimeImmutable::createFromFormat('!Y-m',$monthInput,$tz)?:new DateTimeImmutable('first day of this month',$tz);
$month=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),1);
$gridStart=$month->modify('-'.(int)$month->format('w').' days');$gridEnd=$gridStart->modify('+42 days');
$fromUtc=$gridStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$toUtc=$gridEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$events=user_calendar_events_v1300($pdo,$user,$fromUtc,$toUtc);$byDay=[];
foreach($events as $event){
    $local=(new DateTimeImmutable((string)$event['start_at_utc'],new DateTimeZone('UTC')))->setTimezone($tz);
    $byDay[$local->format('Y-m-d')][]=$event;
}
$today=(new DateTimeImmutable('today',$tz))->format('Y-m-d');
$prev=$month->modify('-1 month')->format('Y-m');$next=$month->modify('+1 month')->format('Y-m');
$agendaFrom=(new DateTimeImmutable('now',$tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$agendaTo=(new DateTimeImmutable('+60 days',$tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$agenda=array_slice(user_calendar_events_v1300($pdo,$user,$agendaFrom,$agendaTo),0,24);
$saved=trim((string)($_GET['saved']??''));
function calendar_v1300_source(array $event): string{return (string)$event['kind']==='booking'?'booking':((string)$event['source']==='agent'?'agent':((string)$event['source']==='automation'?'automation':'personal'));}
function calendar_v1300_meeting(array $event): ?array{
    static $cache=[];$key=(string)$event['kind'].':'.(int)$event['id'];if(array_key_exists($key,$cache))return $cache[$key];$pdo=db();
    if(!$pdo||!function_exists('video_meeting_schema_ready_v1800')||!video_meeting_schema_ready_v1800($pdo))return $cache[$key]=null;
    return $cache[$key]=(string)$event['kind']==='booking'?video_meeting_for_booking_v1800($pdo,(int)$event['id']):video_meeting_for_calendar_event_v1800($pdo,(int)$event['id']);
}
function calendar_v1300_source_label(array $event): string{if(calendar_v1300_meeting($event))return 'Video meeting';return match(calendar_v1300_source($event)){'booking'=>'Booking','agent'=>'Agent','automation'=>'Automated',default=>'Personal'};}
function calendar_v1300_time(array $event,DateTimeZone $tz): string{if(!empty($event['all_day']))return 'All day';$start=(new DateTimeImmutable((string)$event['start_at_utc'],new DateTimeZone('UTC')))->setTimezone($tz);return $start->format('g:i A');}
function calendar_v1300_agenda_time(array $event,DateTimeZone $tz): string{$start=(new DateTimeImmutable((string)$event['start_at_utc'],new DateTimeZone('UTC')))->setTimezone($tz);$end=(new DateTimeImmutable((string)$event['end_at_utc'],new DateTimeZone('UTC')))->setTimezone($tz);return !empty($event['all_day'])?$start->format('D, M j').' · All day':$start->format('D, M j · g:i A').'–'.$end->format('g:i A');}
function calendar_v1300_event_url(array $event): string{$meeting=calendar_v1300_meeting($event);if($meeting)return url('/meeting.php?meeting='.(string)$meeting['public_id']);return (string)$event['kind']==='booking'?url('/scheduling.php?schedule='.(int)($event['schedule_id']??0).'&tab=bookings'):url('/calendar-event.php?id='.(int)$event['id']);}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f5"><title><?= e(system_agent_name()) ?> | Calendar</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/calendar-v1300.css?v=1321')) ?>"></head>
<body class="calendar-page"><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='calendar';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main calendar-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Calendar';$memberHeaderSubtitle='Bookings, personal events + Agent-managed time';$memberHeaderActions='';require __DIR__.'/includes/member-header.php'; ?>
<section class="calendar-canvas"><div class="calendar-inner">
<?php if($saved!==''): ?><div class="calendar-notice success" role="status"><?= e($saved) ?></div><?php endif; ?>
<section class="calendar-toolbar"><div class="calendar-title"><h1>User Calendar</h1></div><div class="calendar-actions"><?php if(function_exists('video_meeting_schema_ready_v1800')&&video_meeting_schema_ready_v1800($pdo)): ?><a class="calendar-button" href="<?= e(url('/meetings.php')) ?>">Meetings</a><?php endif; ?><a class="calendar-button" href="<?= e(url('/scheduling.php')) ?>">Scheduling</a><a class="calendar-button" href="<?= e(url('/agent-workflows.php')) ?>">Workflows</a><a class="calendar-button" href="<?= e(url('/chat.php')) ?>">Ask Agent</a><a class="calendar-button primary" href="<?= e(url('/calendar-event.php')) ?>">+ New event</a></div></section>
<div class="calendar-monthbar"><div class="calendar-nav"><a class="calendar-button" href="<?= e(url('/calendar.php?month='.$prev)) ?>" aria-label="Previous month">←</a><a class="calendar-button" href="<?= e(url('/calendar.php')) ?>">Today</a><a class="calendar-button" href="<?= e(url('/calendar.php?month='.$next)) ?>" aria-label="Next month">→</a><h2><?= e($month->format('F Y')) ?></h2></div><div class="calendar-legend"><span><i class="calendar-dot booking"></i>Booking</span><span><i class="calendar-dot"></i>Personal</span><span><i class="calendar-dot agent"></i>Agent</span><span><i class="calendar-dot automation"></i>Automated</span></div></div>
<div class="calendar-grid" role="grid" aria-label="<?= e($month->format('F Y')) ?> calendar">
<?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $weekday): ?><div class="calendar-weekday" role="columnheader"><?= e($weekday) ?></div><?php endforeach; ?>
<?php for($i=0;$i<42;$i++): $day=$gridStart->modify('+'.$i.' days');$date=$day->format('Y-m-d');$items=$byDay[$date]??[];$outside=$day->format('Y-m')!==$month->format('Y-m'); ?>
<div class="calendar-day<?= $outside?' outside':'' ?><?= $date===$today?' today':'' ?>" role="gridcell" aria-label="<?= e($day->format('l, F j, Y')) ?>"><div class="calendar-day-number"><span><?= (int)$day->format('j') ?></span><?php if($date===$today): ?><small>Today</small><?php endif; ?></div><div class="calendar-events">
<?php foreach(array_slice($items,0,4) as $event): $source=calendar_v1300_source($event); ?><a class="calendar-event <?= e($source) ?>" href="<?= e(calendar_v1300_event_url($event)) ?>"><strong><?= e((string)$event['title']) ?></strong><small><?= e(calendar_v1300_time($event,$tz)) ?> · <?= e(calendar_v1300_source_label($event)) ?></small></a><?php endforeach; ?>
<?php if(count($items)>4): ?><span class="calendar-overflow">+<?= count($items)-4 ?> more</span><?php endif; ?>
</div></div><?php endfor; ?>
</div>
<section class="calendar-agenda"><div class="calendar-agenda-head"><h2>Upcoming</h2><span><?= e($timezone) ?></span></div>
<?php foreach($agenda as $event): $source=calendar_v1300_source($event);$meeting=calendar_v1300_meeting($event); ?><a class="calendar-agenda-row" href="<?= e(calendar_v1300_event_url($event)) ?>" style="color:inherit;text-decoration:none"><div><strong><?= e(calendar_v1300_agenda_time($event,$tz)) ?></strong><small><?= e($timezone) ?></small></div><div><strong><?= e((string)($event['title']??'')) ?></strong><small><?= e((string)($event['description']??'')) ?></small></div><div><strong><?= $meeting?'Join VP3 Meeting':e((string)($event['location']??'')) ?></strong><small><?= (string)$event['kind']==='booking'&&trim((string)($event['guest_name']??''))!==''?'With '.e((string)$event['guest_name']):($meeting?'Video meeting':'VP3 Calendar') ?></small></div><span class="calendar-source <?= e($source) ?>"><?= e(calendar_v1300_source_label($event)) ?></span></a><?php endforeach; ?>
<?php if(!$agenda): ?><div class="calendar-empty"><strong>No upcoming events.</strong><p>Your calendar is open. Add an event manually or ask your Agent to create one.</p></div><?php endif; ?>
</section>
</div></section></main></div><script src="<?= e(url('/member-shell-v77.js?v=20260911')) ?>"></script></body></html>