<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$user=current_user();$pdo=db();
if(!$user||!$pdo||!agent_paid_appointments_schema_ready_v800($pdo)){http_response_code(503);exit('Appointment payments are not available.');}
if(!headers_sent()){header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow, noarchive');}
try{
    $state=trim((string)($_GET['state']??''));$code=trim((string)($_GET['code']??''));
    if($state===''||$code==='')throw new RuntimeException('Payment provider authorization was not completed.');
    $saved=agent_paid_appointments_oauth_state_take_v800($user,$state);$provider=(string)$saved['provider'];
    agent_paid_appointments_oauth_exchange_v800($pdo,$user,$provider,$code);
    flash('notice',agent_paid_appointments_provider_label_v800($provider).' is connected for appointment payments.');
}catch(Throwable $e){flash('error',$e->getMessage());}
redirect(url('/appointment-payments.php'));
