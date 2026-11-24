<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';

vp3_phone_http_headers_v1();
// Holder credentials and owner web sessions deliberately have different authority.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    vp3_phone_http_transport_v1();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Method not allowed.', 405);
    }
    // A custom header and no CORS grants also protect same-site sibling origins.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $expected = (vp3_phone_http_secure_v1() ? 'https://' : 'http://').($_SERVER['HTTP_HOST'] ?? '');
    if (($_SERVER['HTTP_X_VP3_PHONE'] ?? '') !== '1' ||
        ($origin !== '' && strcasecmp($expected, $origin) !== 0) ||
        !in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin', ['same-origin','none'], true)) {
        throw new RuntimeException('Invalid origin.', 403);
    }
    $multipart=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'',2)[0]))==='multipart/form-data';
    if ($multipart && (int)($_SERVER['CONTENT_LENGTH']??0)>VP3_PHONE_RECORD_CHUNK_V1+16384) throw new RuntimeException('Recording chunk too large.',413);
    $input = $multipart ? ['action'=>'camera_record_chunk'] : (($_GET['information_file']??null)==='1'?vp3_phone_file_http_json_v1(['information_attach_file']):vp3_phone_http_json_v1(['camera_offer','camera_map_offer','camera_chat_offer']));
    $action = $input['action'] ?? null;
    unset($input['action']);
    $cameraAction = in_array($action, ['camera_status','camera_prepare','camera_start','camera_offer','camera_stop','camera_map_offer','camera_chat_offer'],true);
    $chatAction = in_array($action,['chat_status','chat_send'],true);
    $recordAction=in_array($action,['camera_record_accept','camera_record_review','camera_record_decline'],true);
    $handoffTargetAction=in_array($action,['handoff_claim','handoff_check','handoff_finish'],true);
    if (!$handoffTargetAction && !$multipart && !$recordAction && !$chatAction && !$cameraAction && !in_array($action, ['claim','status','start','fix','stop','denied','disconnect',
        'accept_history','decline_history','stop_history','set_place_permission',
        'accept_arrival','decline_arrival','stop_arrival','holder_save_place','save_communication_profile','information_list','information_accept','information_decline','information_ack','information_reply','information_attach_file','information_download','dialer_preferences','dialer_claim','dialer_handled','dialer_decline','managed_respond','native_dialthrough_issue','managed_recording_withdraw','handoff_offer','handoff_cancel','push_register','push_disable','push_test','push_resolve'], true)) {
        throw new RuntimeException('Unknown action.', 422);
    }
    $pdo = db();
    if (!$pdo || !vp3_phone_schema_ready_v1($pdo)) throw new RuntimeException('Upgrade required.', 503);
    $name = 'vp3_phone_device_v1';
    $token = (string)($_COOKIE[$name] ?? '');
    if ($action !== 'claim' && !$handoffTargetAction && $token === '') throw new RuntimeException('Pair a phone first.', 401);
    if ($handoffTargetAction) {
        $result=vp3_phone_handoff_target_v1($pdo,$token,$action,$input,time());
    } elseif ($multipart) {
        $result=vp3_phone_record_upload_v1($pdo,$token,$_POST,$_FILES);
    } elseif ($recordAction) {
        $result=vp3_phone_record_holder_v1($pdo,$token,substr($action,14),$input);
    } elseif ($chatAction) {
        require_once dirname(__DIR__).'/includes/paired-phone-agent-v1.php';
        $result = vp3_paired_phone_chat_v1($pdo,$token,$action,$input);
    } elseif ($cameraAction) {
        require_once dirname(__DIR__).'/includes/phone-camera-v1.php';
        require_once dirname(__DIR__).'/includes/phone-camera-http-v1.php';
        $result = vp3_phone_camera_holder_v1($pdo,$token,substr($action,7),$input);
        $result['ice_servers'] = vp3_phone_camera_ice_v1($result['camera'] ?? null);
    } else {
        $result = vp3_phone_device_v1($pdo, $token, $action, $input);
    }
    if ($action === 'claim' || ($action==='handoff_finish'&&isset($result['token']))) {
        // Keep the credential out of script-readable storage. Server-side expiry
        // and revocation remain authoritative throughout the cookie lifetime.
        setcookie($name, $result['token'], ['expires'=>time()+365*86400,
            'path'=>url('/api/phone-location-v1.php'), 'secure'=>vp3_phone_http_secure_v1(),
            'httponly'=>true, 'samesite'=>'Strict']);
    }
    unset($result['token']);
    vp3_phone_http_send_v1($result);
} catch (Throwable $error) {
    vp3_phone_http_error_v1($error);
}
