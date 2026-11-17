<?php
declare(strict_types=1);

// Render the production meeting template with controlled organizer/attendee access.
parse_str($argv[2]??'',$_GET);
$GLOBALS['fixtureOrganizer']=($argv[1]??'')==='organizer';
$GLOBALS['fixtureStatus']=($argv[3]??'')==='ended'?'ended':'scheduled';
function db(): PDO { return new PDO('sqlite::memory:'); }
function current_user(): array { return ['id'=>$GLOBALS['fixtureOrganizer']?1:2,'is_active'=>1,'display_name'=>'Fixture viewer']; }
function video_meeting_schema_ready_v1800(PDO $pdo): bool { return true; }
function video_meeting_secure_access_v1800(PDO $pdo,?array $user,string $publicId,string $invite): array
{
    $organizer=$GLOBALS['fixtureOrganizer'];
    return ['is_organizer'=>$organizer,'participant'=>['role'=>$organizer?'organizer':'attendee','invitation_status'=>'accepted'],
        'meeting'=>['owner_user_id'=>1,'public_id'=>$publicId,'status'=>$GLOBALS['fixtureStatus'],'title'=>'Supplier meeting',
            'start_at_utc'=>'2030-11-06 23:55:00','timezone'=>'UTC','agent_mode'=>'notes','transcription_enabled'=>1]];
}
function video_meeting_agent_name_v1800(PDO $pdo,array $meeting): string { return 'Fixture Agent'; }
function video_meeting_intelligence_schema_ready_v1820(PDO $pdo): bool { return true; }
function video_meeting_livekit_ready_v1800(): bool { return false; }
function vp3_participant_scope_v1(): ?array { return null; }
function has_permission(string $permission,array $user): bool { return true; }
function csrf_token(): string { return 'fixture'; }
function url(string $path): string { return $path; }
function e(string $text): string { return htmlspecialchars($text,ENT_QUOTES,'UTF-8'); }

$source=file_get_contents(dirname(__DIR__,2).'/meeting.php');
$start=strpos($source,'$pdo=db();');
if($start===false)throw new RuntimeException('Meeting page entry point is missing.');
eval(substr($source,$start));
