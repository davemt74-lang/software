<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/music-provider-bootstrap-v1.php';

try {
    music_provider_http_v1_headers();
    $method=music_provider_http_v1_method(['GET','POST','OPTIONS']);
    $pdo=music_provider_http_v1_db();
    if ($method==='GET') {
        if (array_diff(array_keys($_GET),['action'])||!in_array($_GET['action']??'discovery',['discovery'],true))
            music_provider_v1_error('unsupported_action',422,'Use provider discovery or a documented POST operation.');
        header('Access-Control-Allow-Origin: *');
        music_provider_http_v1_reply(['ok'=>true,'installation_id'=>music_provider_v1_installation($pdo)['installation_id'],
            'api_url'=>music_provider_v1_base_url().'/api/music-provider-v1.php','supported_protocols'=>[MUSIC_PROVIDER_V1_PROTOCOL],
            'capabilities'=>['catalog.read','selection.update','import.manifest','playback.begin','playback.renew','playback.events','media.read','artwork.read'],
            'visitor_access'=>'selected_public_recordings','uses'=>['public_streaming'],'cookies_required'=>false,
            'source_page_profile'=>'public_music_pages_v2',
            'site_verification'=>['protocol'=>'vp3_music_site_v1','path'=>'/api/vp3-music-site.php'],
            'limits'=>['media_ticket_seconds'=>300,'session_seconds'=>86400,'selection_recordings'=>500,'catalog_page'=>50],
            'unsupported'=>['private_or_paid_entitlements','downloads','royalty_accrual','listener_identity_import','automatic_catalog_expansion']]);
    }
    if ($method==='OPTIONS') {
        if (array_diff(array_keys($_GET),['site_id'])) music_provider_v1_error('invalid_request',422,'Use the registered site identifier for preflight.');
        $site=music_provider_v1_authorize_site($pdo,music_provider_v1_uuid($_GET['site_id']??null),music_provider_v1_now());
        music_provider_http_v1_cors($site['origin'],['POST'],['content-type','authorization']);
    }
    $input=music_provider_http_v1_input();$token=music_provider_http_v1_bearer();
    if (in_array($input['action'],['catalog.read','selection.update','import.manifest'],true)) {
        // This is a server credential. Do not expose a cross-origin browser route
        // that encourages embedding it in public player code.
        if ((string)($_SERVER['HTTP_ORIGIN']??'')!=='') music_provider_v1_error('backend_required',403,'Use the site backend for music catalog management.');
        if ($_GET) music_provider_v1_error('invalid_request',422,'Send management selectors in the JSON request.');
        $site=music_provider_v1_authenticate($pdo,$token,music_provider_v1_now());
        music_provider_http_v1_reply(music_provider_v1_backend_action($pdo,$site,$input));
    }
    if (!in_array($input['action'],['playback.begin','playback.renew','playback.events'],true))
        music_provider_v1_error('unsupported_action',422,'Choose a supported music provider action.');
    if (array_diff(array_keys($_GET),['site_id'])) music_provider_v1_error('invalid_request',422,'This playback URL contains unsupported selectors.');
    $siteId=music_provider_v1_uuid($input['site_id']??null);
    if (isset($_GET['site_id'])&&$_GET['site_id']!==$siteId) music_provider_v1_error('unavailable',404,'This music session is unavailable.');
    $site=music_provider_v1_authorize_site($pdo,$siteId,music_provider_v1_now());
    music_provider_http_v1_cors($site['origin'],['POST'],['content-type','authorization']);
    if ($input['action']==='playback.begin') {
        if ($token!=='') music_provider_v1_error('invalid_credential',401,'Begin a visitor session without a management credential.');
    } elseif (!preg_match('/^mps1_[a-f0-9]{64}$/D',$token)) {
        music_provider_v1_error('invalid_credential',401,'Use this playback session’s visitor credential.');
    }
    music_provider_http_v1_reply(music_provider_v1_playback_action($pdo,$input,[
        'site_id'=>$siteId,'origin'=>(string)($_SERVER['HTTP_ORIGIN']??''),'playback_key'=>$token,
    ]));
} catch (Throwable $error) {music_provider_http_v1_failure($error);}
