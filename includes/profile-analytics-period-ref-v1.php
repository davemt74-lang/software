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

function vp3_profile_analytics_period_ref_v1(int $days,int $cutoff,string $zone,string $source): string
{
    $code=match($source){'explicit','e'=>'e','schedule','s'=>'s','utc_fallback','u'=>'u',default=>''};
    $ref=vp3_profile_analytics_period_encode_v1($days,$cutoff,$zone,$code);
    vp3_profile_analytics_period_decode_v1($ref);
    return $ref;
}

/** Construct afresh so an ambiguous midnight cannot inherit the caller's later offset. */
function vp3_profile_analytics_local_day_start_v1(DateTimeImmutable $local): DateTimeImmutable
{
    return new DateTimeImmutable($local->format('Y-m-d').' 00:00:00',$local->getTimezone());
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
    $end=(new DateTimeImmutable('@'.$cutoff))->setTimezone(new DateTimeZone($zone));
    // A local day can start at 01:00 when midnight is skipped, or have two
    // midnights when clocks move back. Only its canonical first boundary is a
    // valid cutoff; it cannot change as the owner returns later that same day.
    if(vp3_profile_analytics_local_day_start_v1($end)->getTimestamp()!==$cutoff)vp3_profile_analytics_period_bad_ref_v1();
    // Calendar arithmetic preserves the owner's day boundaries across DST.
    $start=vp3_profile_analytics_local_day_start_v1($end->modify('-'.$days.' days'));
    $previous=vp3_profile_analytics_local_day_start_v1($start->modify('-'.$days.' days'));
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
