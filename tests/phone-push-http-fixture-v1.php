<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

// CLI-only setup/transport for the isolated HTTP suite. This file is never
// copied into its document root and never loads a real push transport.
if (PHP_SAPI !== 'cli' || $argc !== 4) exit(1);
$root=realpath($argv[1]);$action=$argv[2];
if (!$root || !str_starts_with($root,rtrim(sys_get_temp_dir(),'/').'/vp3-phone-push-http-')
    || !is_file($root.'/config.php') || !is_file(dirname($root).'/data.sqlite')
    || !in_array($action,['setup','dispatch','snapshot','expire'],true)) exit(1);
$input=json_decode($argv[3],true,8,JSON_THROW_ON_ERROR);
if (!is_array($input)) exit(1);
if ($action==='setup') {
    $keyFile=$root.'/private/ai-key.php';
    if (file_exists($keyFile)) throw new RuntimeException('Fixture was already configured.');
    file_put_contents($keyFile,'<?php return '.var_export(base64_encode(random_bytes(32)),true).';');
    chmod($keyFile,0600);
}
require $root.'/includes/bootstrap.php';
require_once $root.'/includes/phone-tracking-v1.php';
require_once $root.'/includes/paired-phone-agent-v1.php';
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
$pdo=db();
if (!$pdo || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite') throw new RuntimeException('Isolated SQLite fixture required.');
$now=time();$result=[];
if ($action==='setup') {
    vp3_phone_schema_v1($pdo);
    vp3_paired_phone_schema_v1($pdo,'');
    $pdo->prepare("INSERT INTO users(id,display_name,email,role,password_hash,is_active) VALUES(3,'Fixture administrator','admin@example.test','admin',?,1)")
        ->execute([password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
    $admin=['id'=>3,'role'=>'admin','is_active'=>1];
    vp3_phone_push_configure_v1($pdo,$admin,true,$now);
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:fixture@example.test','revision'=>1],$now);
    vp3_phone_push_delivery_heartbeat_v1($pdo,$now,['runtime'=>PHP_VERSION,'transport_ready'=>true,'code'=>'ready']);
    $config=vp3_phone_push_config_v1($pdo);
    $result=['public_key'=>$config['public_key'],'key_id'=>hash('sha256',$config['public_key'])];
} elseif ($action==='dispatch') {
    $payloads=[];
    $transport=static function(array $subscription,array $vapid,string $payload,array $options)use(&$payloads):array {
        if (!str_starts_with($subscription['endpoint']??'','https://web.push.apple.com/Qhttp_fixture_')) {
            throw new RuntimeException('Only synthetic fixture subscriptions are permitted.');
        }
        $decoded=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
        if (array_keys($decoded)!==['v','event_ref','receipt_token']) throw new RuntimeException('Unexpected push payload.');
        $payloads[]=$decoded;
        return ['state'=>'accepted','code'=>'provider_accepted','http_status'=>201,'retry_after'=>0,'dispatched'=>true];
    };
    $result=['run'=>vp3_phone_push_delivery_run_v1($pdo,25,'vp3_phone_push_source_current_v1',$transport,$now),'payloads'=>$payloads];
} else {
    $ref=$input['event_ref']??null;
    if (!is_string($ref)||preg_match('/^[a-f0-9]{32}$/D',$ref)!==1) throw new RuntimeException('Invalid fixture reference.');
    if ($action==='expire') vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_outbox_v1 SET expires_epoch=? WHERE event_ref=?',[$now-1,$ref]);
    $event=vp3_phone_push_delivery_event_v1($pdo,$ref);
    if (!$event) throw new RuntimeException('Missing fixture event.');
    $result=['event'=>vp3_phone_push_delivery_public_v1($event),'count'=>(int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()];
}
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
