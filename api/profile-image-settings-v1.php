<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-image-catalog-v1.php';
require_once dirname(__DIR__).'/includes/profile-image-edit-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??''));
    if (!in_array($method,['GET','POST'],true)) throw new RuntimeException('Use GET or POST for image settings.',405);
    $user=current_user();
    if (!$user || !has_permission('chat.access',$user) || !personal_capability_has_v242('profile_agent.access',$user)) {
        throw new RuntimeException('Sign in to manage your Profile images.',403);
    }
    $owner=(int)$user['id'];
    if ((string)($_SERVER['HTTP_X_VP3_MEDIA_OWNER']??'')!==(string)$owner) throw new RuntimeException('Your account changed. Refresh before saving.',403);
    $pdo=db();
    if (!$pdo instanceof PDO) throw new RuntimeException('Profile storage is unavailable.',503);
    if ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>16384) throw new RuntimeException('Image settings request is too large.',413);
        $raw=(string)file_get_contents('php://input',false,null,0,16385);
        if (strlen($raw)>16384) throw new RuntimeException('Image settings request is too large.',413);
        try { $decoded=json_decode($raw,false,12,JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new InvalidArgumentException('Choose valid image settings.',422); }
        if (!$decoded instanceof stdClass) throw new InvalidArgumentException('Choose valid image settings.',422);
        $input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
        if (!is_string($input['csrf_token']??null) || $input['csrf_token']==='' || !hash_equals(csrf_token(),$input['csrf_token'])) {
            throw new RuntimeException('Your session expired. Refresh before saving.',419);
        }
        unset($input['csrf_token']);
        $state=vp3_profile_image_catalog_save_v1($pdo,$user,$input);
    } else {
        $collection=0;
        if (isset($_GET['collection_id'])) {
            $value=$_GET['collection_id'];
            if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/D',$value)
                || ($collection=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))===false) {
                throw new InvalidArgumentException('Choose one of your Media collections.',422);
            }
        }
        $state=vp3_profile_image_catalog_owner_state_v1($pdo,$user,$collection);
    }
    $state['history']=personal_capability_has_v242('profile_chat.access',$user)
        ? vp3_profile_image_edit_owner_history_v1($pdo,$user)
        : ['available'=>false,'records'=>[],'total_completed'=>0];
    echo json_encode(['ok'=>true]+$state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    $status=(int)$error->getCode();
    if (!in_array($status,[403,404,405,409,413,419,422,503],true)) $status=503;
    if ($status===405) header('Allow: GET, POST');
    http_response_code($status);
    $message=$status===503 ? 'Image settings are unavailable. Run Database Upgrade if this feature was just installed.' : $error->getMessage();
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
