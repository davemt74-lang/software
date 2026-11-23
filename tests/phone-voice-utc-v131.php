<?php
declare(strict_types=1);
// Start with the production signed-ingress fixture and its actual call receipts.
require __DIR__.'/phone-voice-ph3c-runtime.php';
$callSid='CA'.str_repeat('e',32);
inbound($pdo,callForm(['CallSid'=>$callSid]));
$set=$pdo->prepare("UPDATE phone_voice_calls_v130 SET state='voicemail_offered',created_at=? WHERE provider_call_sid=?");
$oldTimezone=date_default_timezone_get();
try {
    foreach (['UTC','America/Phoenix','Pacific/Honolulu','Asia/Tokyo'] as $timezone) {
        date_default_timezone_set($timezone);
        foreach ([[-30,true],[120,false],[-1801,false]] as [$offset,$accepted]) {
            $set->execute([gmdate('Y-m-d H:i:s',time()+$offset),$callSid]);
            $route=vp3_phone_voice_current_route_v131($pdo,$callSid,1800);
            check($route['accepted']===$accepted,'UTC receipt age must not depend on PHP timezone '.$timezone.' offset '.$offset);
        }
        foreach (['2026-02-30 10:00:00','not-a-time','2026-11-23T10:00:00Z','2026-11-23 10:00:00 extra'] as $invalid) {
            $set->execute([$invalid,$callSid]);
            check(vp3_phone_voice_current_route_v131($pdo,$callSid)['reason']==='call_expired','Malformed DATETIME receipts cannot become fresh calls');
        }
    }
} finally {date_default_timezone_set($oldTimezone);}
echo "PHONE_VOICE_UTC_V131=PASS\n";
