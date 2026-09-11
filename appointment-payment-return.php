<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$pdo=db();if(!$pdo||!agent_paid_appointments_schema_ready_v800($pdo)){http_response_code(503);exit('Appointment payments are not available.');}
if(!headers_sent()){header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow, noarchive');header('Cache-Control: no-store, private');}
$paidId=max(0,(int)($_GET['paid']??0));$provider=strtolower(trim((string)($_GET['provider']??'')));$manage=strtolower(trim((string)($_GET['manage']??'')));
$paid=$manage!==''?agent_paid_appointments_public_by_token_v800($pdo,$manage):null;
if(!$paid||(int)$paid['id']!==$paidId||!in_array($provider,['stripe','square','paypal'],true)){http_response_code(404);exit('Appointment payment return not found.');}
$message='We are confirming your payment with the provider.';$ok=false;
try{
    $paid=agent_paid_appointments_return_verify_v800($pdo,$paid,$provider,$_GET);
    $ok=in_array((string)$paid['payment_status'],['paid','partially_paid','partially_refunded','refunded'],true);
    $message=$ok?'Payment received. Your appointment is confirmed.':'The provider has not confirmed payment yet. Use your private appointment link to check again.';
}catch(Throwable $e){
    error_log('VP3 appointment payment return failed: '.$e->getMessage());
    $message='We could not confirm the payment from this return page. If you completed checkout, use your private appointment link to check status.';
}
$manageUrl=(int)($paid['team_booking_id']??0)>0?url('/team-book.php?manage='.rawurlencode($manage)):'';
if($manageUrl===''){
    $booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$paid['booking_id']);
    if($booking&&table_exists('user_profiles')){
        $stmt=$pdo->prepare('SELECT username FROM user_profiles WHERE user_id=? LIMIT 1');
        $stmt->execute([(int)$booking['owner_user_id']]);
        $username=(string)$stmt->fetchColumn();
        if($username!=='')$manageUrl=agent_scheduling_public_manage_url_v450($username,$manage);
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Payment status — VP3</title><style>body{margin:0;background:#f4f5f7;color:#151515;font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.shell{max-width:620px;margin:0 auto;padding:64px 20px}.card{background:#fff;border:1px solid #e1e3e8;border-radius:24px;padding:30px;box-shadow:0 16px 50px rgba(20,25,35,.08)}h1{font-size:30px;margin:0 0 12px}.state{font-size:15px;color:#5f6570}.button{display:inline-block;margin-top:16px;padding:10px 14px;border-radius:10px;background:#111;color:#fff;text-decoration:none}</style></head><body><main class="shell"><section class="card"><div><?= $ok?'✓':'•' ?></div><h1><?= $ok?'Appointment confirmed':'Payment verification' ?></h1><p class="state"><?=e($message)?></p><?php if($manageUrl!==''):?><a class="button" href="<?=e($manageUrl)?>">Manage appointment</a><?php endif;?></section></main></body></html>
