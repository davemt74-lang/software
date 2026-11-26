<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/music-provider-bootstrap-v1.php';

try {
    music_provider_http_v1_headers();
    $method=music_provider_http_v1_method(['GET','HEAD','OPTIONS']);
    $pdo=music_provider_http_v1_db();
    if (array_diff(array_keys($_GET),['site_id','recording_id'])) music_provider_v1_error('invalid_request',422,'This artwork URL contains unsupported selectors.');
    $site=music_provider_v1_authorize_site($pdo,music_provider_v1_uuid($_GET['site_id']??null),music_provider_v1_now());
    music_provider_http_v1_cors($site['origin'],['GET','HEAD']);
    $art=music_provider_v1_artwork($pdo,$site,music_provider_v1_uuid($_GET['recording_id']??null));
    if (!$art) music_provider_v1_error('unavailable',404,'This artwork is unavailable.');
    $handle=fopen($art['path'],'rb');
    if (!$handle) music_provider_v1_error('unavailable',404,'This artwork is unavailable.');
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    @ini_set('zlib.output_compression','0');
    while (ob_get_level()>0) @ob_end_clean();
    header_remove('Content-Encoding');
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Content-Type: '.$art['mime']);header('Content-Length: '.$art['size']);
    if ($method==='GET') fpassthru($handle);
    fclose($handle);
} catch (Throwable $error) {if (!headers_sent()) music_provider_http_v1_failure($error);}
