<?php
declare(strict_types=1);

function music_provider_http_v1_headers(): void
{
    header('Cache-Control: no-store, private, no-transform');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Vary: Origin');
}

function music_provider_http_v1_reply(array $data, int $status = 200): never
{
    // An error before the first media byte must not retain a binary range's
    // length or disposition while returning JSON instead.
    foreach (['Content-Length','Content-Range','Content-Disposition','Content-Encoding','ETag','Accept-Ranges','X-Accel-Buffering'] as $name) header_remove($name);
    music_provider_http_v1_headers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $data=['protocol'=>MUSIC_PROVIDER_V1_PROTOCOL,'correlation_id'=>$GLOBALS['musicProviderCorrelation']??=bin2hex(random_bytes(12))]+$data;
    echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function music_provider_http_v1_failure(Throwable $error): never
{
    if ($error instanceof MusicProviderV1Exception) {
        $retryable=(bool)($error->details['retryable']??in_array($error->httpStatus,[429,502,503],true));
        if ($error->httpStatus===429) header('Retry-After: 60');
        music_provider_http_v1_reply(['ok'=>false,'code'=>$error->publicCode,'message'=>$error->getMessage(),
            'retryable'=>$retryable,'details'=>$error->details],$error->httpStatus);
    }
    $reference=$GLOBALS['musicProviderCorrelation']??=bin2hex(random_bytes(12));
    // Never log request bodies, signed URLs, bearer values, paths or SQL errors.
    error_log('Music provider temporarily unavailable; reference '.$reference.'.');
    music_provider_http_v1_reply(['ok'=>false,'code'=>'temporarily_unavailable','message'=>'Music streaming is temporarily unavailable. Please retry.', 'retryable'=>true],503);
}

function music_provider_http_v1_input(): array
{
    if (!preg_match('#^application/json(?:\s*;|$)#i',(string)($_SERVER['CONTENT_TYPE']??'')))
        music_provider_v1_error('invalid_content_type',415,'Send a JSON request.');
    $length=$_SERVER['CONTENT_LENGTH']??'';
    if (!is_scalar($length)||($length!==''&&(!ctype_digit((string)$length)||(float)$length>32768)))
        music_provider_v1_error('request_too_large',413,'The music request is too large.');
    $body=file_get_contents('php://input',false,null,0,32769);
    if ($body===false||strlen($body)>32768) music_provider_v1_error('request_too_large',413,'The music request is too large.');
    try {$input=json_decode($body,true,24,JSON_THROW_ON_ERROR);} catch (JsonException) {music_provider_v1_error('invalid_request',422,'The JSON request is invalid.');}
    if (!is_array($input)||array_is_list($input)) music_provider_v1_error('invalid_request',422,'Send a JSON object.');
    if (($input['protocol']??null)!==MUSIC_PROVIDER_V1_PROTOCOL) music_provider_v1_error('unsupported_protocol',422,'This music API version is unsupported.');
    if (!is_string($input['action']??null)) music_provider_v1_error('invalid_request',422,'Choose a music operation.');
    return $input;
}

function music_provider_http_v1_bearer(): string
{
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if ($header==='') return '';
    if (!preg_match('/^Bearer ([a-z0-9_]{5,100})$/D',$header,$match)) music_provider_v1_error('invalid_credential',401,'The music credential is invalid.');
    return $match[1];
}

/** CORS is a browser boundary; selected public catalog grants supply authority. */
function music_provider_http_v1_cors(string $allowedOrigin, array $methods, array $headers = []): void
{
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    if ($origin!=='') {
        if ($origin!==$allowedOrigin) music_provider_v1_error('origin_not_allowed',403,'This site cannot use that music connection.');
        header('Access-Control-Allow-Origin: '.$allowedOrigin);
    }
    if (($_SERVER['REQUEST_METHOD']??'')!=='OPTIONS') return;
    if ($origin==='') music_provider_v1_error('origin_not_allowed',403,'An approved site origin is required.');
    $requested=(string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']??'');
    if (!in_array($requested,$methods,true)) music_provider_v1_error('method_not_allowed',405,'This music operation does not support that method.');
    $wanted=array_filter(array_map('trim',explode(',',strtolower((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']??'')))));
    if (array_diff($wanted,$headers)) music_provider_v1_error('header_not_allowed',403,'The music request contains unsupported headers.');
    header('Access-Control-Allow-Methods: '.implode(', ',$methods));
    if ($headers) header('Access-Control-Allow-Headers: '.implode(', ',$headers));
    header('Access-Control-Max-Age: 0');
    music_provider_http_v1_headers();
    http_response_code(204);header('Content-Length: 0');exit;
}

function music_provider_http_v1_db(): PDO
{
    $pdo=db();
    if (!$pdo) music_provider_v1_error('temporarily_unavailable',503,'Music storage is temporarily unavailable.');
    music_provider_v1_require_schema($pdo);
    return $pdo;
}

function music_provider_http_v1_method(array $allowed): string
{
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if (!in_array($method,$allowed,true)) {
        header('Allow: '.implode(', ',$allowed));
        music_provider_v1_error('method_not_allowed',405,'Use a supported music request method.');
    }
    return $method;
}
