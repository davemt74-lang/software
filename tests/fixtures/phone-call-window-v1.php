<?php
declare(strict_types=1);

/**
 * Real HTTP requests keep the real clock. Put their saved calling window near
 * local noon using one captured instant, so a UTC day boundary cannot close it.
 * These standard IANA identifiers are accepted by both owner and device policy
 * validators, and leave several hours of room for the complete test request.
 * This helper changes fixture settings only, never production admission rules.
 */
function vp3_phone_fixture_call_window_v1(int $now): array
{
    $instant=new DateTimeImmutable('@'.$now);$chosen='UTC';$nearest=PHP_INT_MAX;
    foreach(['UTC','Asia/Tokyo','America/Phoenix'] as $zone){
        $local=$instant->setTimezone(new DateTimeZone($zone));
        $seconds=(int)$local->format('G')*3600+(int)$local->format('i')*60+(int)$local->format('s');
        $distance=abs($seconds-12*3600);
        if($distance<$nearest){$chosen=$zone;$nearest=$distance;}
    }
    return ['timezone'=>$chosen,'window_start'=>'00:00','window_end'=>'23:59'];
}
