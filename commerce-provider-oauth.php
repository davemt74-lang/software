<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');
$pdo=db();$user=current_user();if(!$pdo||!$user)redirect(url('/login.php'));
if(!agent_commerce_schema_ready_v800($pdo))redirect(url('/upgrade.php'));
$state=trim((string)($_GET['state']??''));$code=trim((string)($_GET['code']??''));
try{
    if($state==='')throw new RuntimeException('Payment provider connection state is missing.');
    $saved=agent_commerce_oauth_state_take_v800($user,$state);$provider=(string)$saved['provider'];
    if(isset($_GET['error']))throw new RuntimeException(agent_commerce_provider_label_v800($provider).' authorization was not completed.');
    agent_commerce_oauth_exchange_v800($pdo,$user,$provider,$code);
    flash('notice',agent_commerce_provider_label_v800($provider).' is connected for VP3 Commerce.');
}catch(Throwable $e){flash('error',$e->getMessage());}
redirect(url('/commerce.php'));
