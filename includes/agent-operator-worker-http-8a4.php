<?php
declare(strict_types=1);
require_once __DIR__.'/agent-operator-paired-worker-8a4.php';

/** Small machine-only HTTP boundary, independent of browser bootstrap/session cookies. */
final class VP3OperatorWorkerHTTP8A4
{
    public static function preflight(array $server):int
    {
        if (!in_array($server['HTTPS']??null,['on','1'],true)) return 403;
        if (($server['REQUEST_METHOD']??null)!=='POST') return 405;
        if (!in_array($server['CONTENT_TYPE']??null,['application/json','application/json; charset=utf-8'],true)) return 415;
        $len=$server['CONTENT_LENGTH']??null;
        if ($len!==null && (!is_string($len) || preg_match('/\A[0-9]{1,4}\z/D',$len)!==1 || (int)$len>4096)) return 413;
        return 200;
    }
    private static function error(int $status):array
    {
        return ['status'=>$status,'body'=>'{"ok":false,"error":"Operator worker exchange unavailable."}','signature'=>null];
    }
    public static function dispatch(array $server,string $raw,VP3OperatorPairedWorker8A4 $service,int $now,bool $credential):array
    {
        $status=self::preflight($server);if($status!==200)return self::error($status);
        if (strlen($raw)>4096) return self::error(413);
        if (strlen($raw)<2 || (isset($server['CONTENT_LENGTH']) && (int)$server['CONTENT_LENGTH']!==strlen($raw))) return self::error(400);
        try {
            if($credential){
                $auth=$server['HTTP_AUTHORIZATION']??($server['REDIRECT_HTTP_AUTHORIZATION']??'');
                $device=$server['HTTP_X_HOMESERVER_DEVICE']??'';
                if($raw!=='{}' || !is_string($auth) || !is_string($device) || preg_match('/\ABearer ([A-Za-z0-9_-]{64})\z/D',$auth,$m)!==1)
                    return self::error(401);
                $c=$service->fetchCredential($device,$m[1],$now);
                return ['status'=>200,'body'=>json_encode($c,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'signature'=>null];
            }
            $mac=$server['HTTP_X_VP3_OPERATOR_SIGNATURE']??'';
            if(!is_string($mac))return self::error(401);
            $r=$service->receive($raw,$mac,$now);
            return ['status'=>200,'body'=>$r['body'],'signature'=>$r['signature']];
        }catch(DomainException $e){return self::error(401);}
        catch(Throwable $e){return self::error(503);}
    }
    public static function serve(bool $credential):void
    {
        // Do not trust a public X-Forwarded-Proto header. Configure TLS at the web server.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        $status=self::preflight($_SERVER);
        if($status!==200){if($status===405)header('Allow: POST');self::emit(self::error($status));return;}
        try {
            $path=dirname(__DIR__).'/config.php';
            global $config;
            $config=is_file($path)?require $path:[];
            $settings=$config['operator_worker_registration']??[];
            if(!is_array($settings) || ($settings['enabled']??false)!==true){self::emit(self::error(404));return;}
            require_once __DIR__.'/db.php';
            $pdo=db();if(!$pdo)throw new RuntimeException('Database unavailable.');
            $service=new VP3OperatorPairedWorker8A4($pdo,VP3OperatorPairedWorker8A4::masterKey($settings));
            $raw=file_get_contents('php://input',false,null,0,4097);
            if(!is_string($raw)){self::emit(self::error(400));return;}
            self::emit(self::dispatch($_SERVER,$raw,$service,time(),$credential));
        }catch(Throwable $e){self::emit(self::error(503));}
    }
    private static function emit(array $r):void
    {
        http_response_code($r['status']);
        if(is_string($r['signature']))header('X-VP3-Operator-Signature: '.$r['signature']);
        echo $r['body'];
    }
}
