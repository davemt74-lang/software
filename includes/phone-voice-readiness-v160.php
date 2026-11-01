<?php
declare(strict_types=1);

/** PH3H: read-only, non-billable SIP readiness checks; never a live-call certificate. */
function vp3_phone_stream_readiness_evaluate_v160(array $number,array $context,?array $carrier): array
{
    $checks=[];
    $add=static function(string $code,string $label,bool $ok) use (&$checks): void {
        $checks[]=['code'=>$code,'label'=>$label,'status'=>$ok?'pass':'fail'];
    };
    $expected=(string)($context['callback_url']??'');
    $add('schema','PH3G tables installed',!empty($context['schema_ready']));
    $add('twilio','Twilio credentials readable',!empty($context['twilio_ready']));
    $add('number','Registered Twilio number with valid SID',($number['provider']??'')==='twilio'
        && preg_match('/^PN[a-f0-9]{32}$/iD',(string)($number['provider_sid']??''))===1);
    $add('voice','Incoming calls enabled for this number',!empty($number['voice_enabled']));
    $add('owner','Assigned member has voice entitlement and owns the Cloud Agent',!empty($context['owner_authorized']));
    $add('callback','Fixed HTTPS Cloud voice callback is configured',!empty($context['callback_valid']));
    $add('bridge','TLS LiveKit SIP address and separate signing secret configured',!empty($context['bridge_configured']));
    $add('route','This number opts into a separate HomeServer secondary Agent',
        (int)($context['homeserver_agent_id']??0)>0);
    if ($carrier===null) {
        $checks[]=['code'=>'carrier_webhook','label'=>'Twilio number webhook matches Cloud POST callback',
            'status'=>'unverified'];
    } else {
        $add('carrier_webhook','Twilio number webhook matches Cloud POST callback',
            (string)($carrier['sid']??'')===(string)($number['provider_sid']??'')
            && (string)($carrier['phone_number']??'')===(string)($number['phone_e164']??'')
            && $expected!=='' && hash_equals($expected,(string)($carrier['voice_url']??''))
            && strtoupper((string)($carrier['voice_method']??''))==='POST');
    }
    $pending=['LiveKit ingress and SIP header mapping','HomeServer worker and separate Agent grant',
        'Real Twilio carrier audio, STT/TTS and spoken disclosure',
        'Barge-in, revocation, failed SIP and voicemail fallback'];
    return ['checks'=>$checks,'ready_for_staging'=>count(array_filter($checks,
        static fn(array $item):bool=>$item['status']==='pass'))===count($checks),
        'live_certified'=>false,'live_tests_pending'=>$pending];
}

/** Carrier inspection makes one GET to Twilio's fixed API; no test call is placed. */
function vp3_phone_stream_readiness_number_v160(PDO $pdo,int $numberId): array
{
    if ($numberId<1) throw new InvalidArgumentException('Select an existing phone number.');
    $query=$pdo->prepare('SELECT * FROM phone_cloud_numbers_v100 WHERE id=? LIMIT 1');
    $query->execute([$numberId]);$number=$query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($number)) throw new RuntimeException('Phone number not found.');
    $owner=(int)($number['owner_user_id']??0);$agent=(int)($number['agent_id']??0);
    $validCallback=false;$callback=vp3_phone_voice_callback_url_v130();
    try {$validCallback=vp3_phone_voice_validate_url_v130($callback)===$callback;}
    catch(Throwable) {}
    $authorized=false;
    if ($owner>0 && $agent>0) {
        $authorized=!empty(vp3_phone_package_v100($pdo,$owner)['voice'])
            && vp3_phone_agent_owned_v100($pdo,$owner,$agent);
    }
    $route=vp3_phone_stream_route_v150($pdo,['phone_number_id'=>$numberId,
        'owner_user_id'=>$owner,'agent_id'=>$agent]);
    $carrier=null;$carrierError='';
    $state=vp3_phone_twilio_state_v100();
    if ($state['configured'] && preg_match('/^PN[a-f0-9]{32}$/iD',(string)$number['provider_sid'])) {
        try {
            $sid=vp3_phone_twilio_credentials_v100()[0];
            $carrier=vp3_phone_twilio_http_v100('GET','/2010-04-01/Accounts/'.$sid.
                '/IncomingPhoneNumbers/'.$number['provider_sid'].'.json');
        } catch (Throwable) {
            // Do not expose account credentials, raw carrier errors or response bodies.
            $carrierError='Carrier webhook could not be verified. Check Twilio connectivity and try again.';
        }
    }
    $result=vp3_phone_stream_readiness_evaluate_v160($number,[
        'schema_ready'=>vp3_phone_stream_ready_v150() && vp3_phone_voice_ready_v140(),
        'twilio_ready'=>$state['configured'],
        'owner_authorized'=>$authorized,
        'callback_url'=>$callback,'callback_valid'=>$validCallback,
        'bridge_configured'=>vp3_phone_stream_configured_v150(),
        'homeserver_agent_id'=>(int)($route['homeserver_agent_id']??0),
    ],$carrier);
    $result['carrier_warning']=$carrierError;
    return $result;
}
