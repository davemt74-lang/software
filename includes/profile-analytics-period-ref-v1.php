<?php
declare(strict_types=1);

/** Fixed Profile periods are pure identifiers: no storage, responder or provider dependency. */
const VP3_PROFILE_ANALYTICS_PERIOD_V1='vp3.profile-analytics-period.v1';

function vp3_profile_analytics_period_bad_ref_v1(): never
{
    throw new RuntimeException('Choose a valid completed Profile reporting period.',422);
}

function vp3_profile_analytics_period_encode_v1(int $days,int $cutoff,string $zone,string $source): string
{
    return 'profile:analytics:v1:'.$days.':'.$cutoff.':'.$source.':'.rtrim(strtr(base64_encode($zone),'+/','-_'),'=');
}

/** The first real instant of a local date, including a skipped or repeated midnight. */
function vp3_profile_analytics_day_start_v1(string $date,DateTimeZone $zone): DateTimeImmutable
{
    // Construct from the date itself, rather than carrying a later fold's UTC
    // offset through setTime(). A wholly skipped date normalizes forward.
    $normalized=new DateTimeImmutable($date.' 00:00:00',$zone);
    $date=$normalized->format('Y-m-d');
    $anchor=(new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('UTC')))->getTimestamp();
    $candidates=[$normalized->getTimestamp()];
    foreach($zone->getTransitions($anchor-172800,$anchor+172800)?:[] as $transition){
        $candidates[]=$anchor-(int)$transition['offset'];
        $candidates[]=(int)$transition['ts'];
    }
    sort($candidates,SORT_NUMERIC);
    foreach(array_unique($candidates) as $stamp){
        $at=(new DateTimeImmutable('@'.$stamp))->setTimezone($zone);
        $before=(new DateTimeImmutable('@'.($stamp-1)))->setTimezone($zone);
        if($at->format('Y-m-d')===$date&&$before->format('Y-m-d')!==$date)return $at;
    }
    vp3_profile_analytics_period_bad_ref_v1();
}

/** Compatibility entry point with the same first-real-instant boundary. */
function vp3_profile_analytics_local_day_start_v1(DateTimeImmutable $local): DateTimeImmutable
{
    return vp3_profile_analytics_day_start_v1($local->format('Y-m-d'),$local->getTimezone());
}

function vp3_profile_analytics_period_ref_v1(int $days,int $cutoff,string $zone,string $source): string
{
    $code=match($source){'explicit','e'=>'e','schedule','s'=>'s','utc_fallback','u'=>'u',default=>''};
    $ref=vp3_profile_analytics_period_encode_v1($days,$cutoff,$zone,$code);
    vp3_profile_analytics_period_decode_v1($ref);
    return $ref;
}

function vp3_profile_analytics_period_decode_v1(string $ref,?DateTimeImmutable $now=null): array
{
    if(strlen($ref)>180||!preg_match('/\Aprofile:analytics:v1:(1|7|30):([1-9][0-9]{8,10}):(e|s|u):([A-Za-z0-9_-]{1,100})\z/D',$ref,$m))
        vp3_profile_analytics_period_bad_ref_v1();
    $days=(int)$m[1];$cutoff=(int)$m[2];$code=$m[3];
    $zone=base64_decode(strtr($m[4],'-_','+/'),true);
    if(!is_string($zone)||strlen($zone)>75||rtrim(strtr(base64_encode($zone),'+/','-_'),'=')!==$m[4]
        ||!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)||($code==='u'&&$zone!=='UTC'))
        vp3_profile_analytics_period_bad_ref_v1();
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    if($cutoff<946684800||$cutoff>=4133980800||$cutoff>$now->getTimestamp())vp3_profile_analytics_period_bad_ref_v1();
    $timezone=new DateTimeZone($zone);
    $end=(new DateTimeImmutable('@'.$cutoff))->setTimezone($timezone);
    if(vp3_profile_analytics_day_start_v1($end->format('Y-m-d'),$timezone)->getTimestamp()!==$cutoff)vp3_profile_analytics_period_bad_ref_v1();
    // Date-only calendar arithmetic avoids carrying a normalized 01:00 or a
    // repeated-midnight offset into the preceding boundaries.
    $calendar=new DateTimeImmutable($end->format('Y-m-d'),new DateTimeZone('UTC'));
    $start=vp3_profile_analytics_day_start_v1($calendar->modify('-'.$days.' days')->format('Y-m-d'),$timezone);
    $previous=vp3_profile_analytics_day_start_v1($calendar->modify('-'.($days*2).' days')->format('Y-m-d'),$timezone);
    if($previous->getTimestamp()>=$start->getTimestamp()||$start->getTimestamp()>=$cutoff)vp3_profile_analytics_period_bad_ref_v1();
    $source=match($code){'e'=>'explicit','s'=>'schedule',default=>'utc_fallback'};
    $window=['period'=>$days,'timezone'=>$zone,'timezone_source'=>$source,
        'from_local'=>$start->format('Y-m-d\TH:i:sP'),'to_local'=>$end->format('Y-m-d\TH:i:sP'),
        'from_utc_ms'=>$start->getTimestamp()*1000,'to_utc_ms'=>$cutoff*1000,'as_of_utc_ms'=>$cutoff*1000,
        'previous'=>['from_local'=>$previous->format('Y-m-d\TH:i:sP'),'to_local'=>$start->format('Y-m-d\TH:i:sP'),
            'from_utc_ms'=>$previous->getTimestamp()*1000,'to_utc_ms'=>$start->getTimestamp()*1000]];
    $options=[];
    foreach([1,7,30] as $period)$options[]=['period'=>$period,'label'=>$period===1?'1 day':$period.' days',
        'ref'=>vp3_profile_analytics_period_encode_v1($period,$cutoff,$zone,$code)];
    return ['ref'=>$ref,'period'=>$days,'cutoff'=>$cutoff,'timezone'=>$zone,'timezone_source'=>$source,
        'window'=>$window,'period_options'=>$options];
}
