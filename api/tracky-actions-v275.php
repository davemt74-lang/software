<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$user=current_user();
$userId=(int)($user['id']??0);
if($userId<1){
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Authentication required.']);
    exit;
}

function tracky_v275_api_error(Throwable $e,int $status=400): never
{
    $message=trim($e->getMessage());
    if($message===''||preg_match('/(?:credential|decrypt|database|sql|openssl|curl|token)/i',$message)){
        $message='Tracky physical action could not be completed. Refresh the connection and try again.';
    }
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>mb_substr($message,0,500)],JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'POST required.']);
        exit;
    }
    if(!verify_csrf()){
        http_response_code(419);
        echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);
        exit;
    }
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    $action=strtolower(trim((string)($input['action']??'propose')));
    if($action==='propose'){
        echo json_encode(tracky_v275_propose($pdo??db(),$user,$input),JSON_UNESCAPED_SLASHES);
        exit;
    }
    if($action==='status'){
        echo json_encode(tracky_v275_status($pdo??db(),$user,(string)($input['intent_id']??'')),JSON_UNESCAPED_SLASHES);
        exit;
    }
    throw new RuntimeException('Unsupported Tracky action request.');
}catch(Throwable $e){
    tracky_v275_api_error($e);
}
