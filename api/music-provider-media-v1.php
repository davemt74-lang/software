<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/music-provider-bootstrap-v1.php';

$media=null;$sent=0;$status=0;
try {
    music_provider_http_v1_headers();
    $method=music_provider_http_v1_method(['GET','HEAD','OPTIONS']);
    $pdo=music_provider_http_v1_db();
    if (array_diff(array_keys($_GET),['ticket'])) music_provider_v1_error('invalid_request',422,'This media URL contains unsupported selectors.');
    $ticket=$_GET['ticket']??null;
    if (!is_string($ticket)||strlen($ticket)>4096) music_provider_v1_error('unavailable',404,'This music stream is unavailable.');
    if ($method==='OPTIONS') {
        $context=music_provider_v1_ticket_context($pdo,$ticket,['now_ms'=>music_provider_v1_now()]);
        music_provider_http_v1_cors($context['origin'],['GET','HEAD'],['range','if-range']);
    }
    $media=music_provider_v1_media($pdo,$ticket,[
        'method'=>$method,'range'=>(string)($_SERVER['HTTP_RANGE']??''),'if_range'=>(string)($_SERVER['HTTP_IF_RANGE']??''),
        'origin'=>(string)($_SERVER['HTTP_ORIGIN']??''),
    ]);
    music_provider_http_v1_cors($media['origin'],['GET','HEAD'],['range','if-range']);
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    @ini_set('zlib.output_compression','0');
    while (ob_get_level()>0) @ob_end_clean();
    header_remove('Content-Encoding');
    $range=$media['range'];$status=(int)$range['status'];
    http_response_code($status);
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, ETag');
    header('Accept-Ranges: bytes');
    header('ETag: '.$media['etag']);
    if ($status===416) {
        header('Content-Range: bytes */'.$media['size']);header('Content-Length: 0');
    } else {
        header('Content-Type: '.$media['mime']);
        header('Content-Length: '.$range['length']);
        header('Content-Disposition: inline; filename="music-stream"');
        header('X-Accel-Buffering: no');
        if ($status===206) header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$media['size']);
        if ($method==='GET') {
            if (fseek($media['handle'],(int)$range['start'])!==0) throw new RuntimeException('Stream position unavailable.');
            $remaining=(int)$range['length'];
            while ($remaining>0&&!feof($media['handle'])) {
                $chunk=fread($media['handle'],min(65536,$remaining));
                if ($chunk===false||$chunk==='') break;
                echo $chunk;$bytes=strlen($chunk);$sent+=$bytes;$remaining-=$bytes;
                if (connection_status()!==CONNECTION_NORMAL) break;
            }
        }
    }
} catch (Throwable $error) {
    if (!headers_sent()) {
        if (is_array($media)&&is_resource($media['handle']??null)) fclose($media['handle']);
        music_provider_http_v1_failure($error);
    }
    error_log('Music provider response interrupted; delivery remains partial.');
} finally {
    if (is_array($media)) {
        if (is_resource($media['handle']??null)) fclose($media['handle']);
        try {music_provider_v1_delivery_record($pdo,$media,$method,$status,$sent,music_provider_v1_now());}
        catch (Throwable) {error_log('Music provider delivery acknowledgement unavailable; reserved usage retained.');}
    }
}
