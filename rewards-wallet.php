<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

$user=current_user();$pdo=db();
if(!$user||!$pdo||!function_exists('campaigns_rewards_platform_schema_ready_v100')||!campaigns_rewards_platform_schema_ready_v100($pdo)){
    http_response_code(503);exit('Reward Wallet is unavailable.');
}
$uid=(int)$user['id'];$notice='';$error='';$revealed=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Try again.';
    else{
        try{
            $action=(string)($_POST['action']??'');
            if($action!=='reveal_credential')throw new RuntimeException('Unknown Wallet action.');
            $revealed=campaigns_rewards_rotate_reward_credential_v100($pdo,max(0,(int)($_POST['issuance_id']??0)),$uid);
            $notice='A fresh Reward Credential was generated. The previous credential no longer works.';
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
$wallet=campaigns_rewards_wallet_v100($pdo,0,$uid);
$notice=$notice?:((string)(flash('notice')??''));$error=$error?:((string)(flash('error')??''));
$memberHeaderUser=$user;$memberHeaderTitle='Reward Wallet';$memberHeaderSubtitle='Your issued, active and claimed VP3 Rewards';
$memberHeaderActions='<a class="cr-btn" href="'.e(url('/campaigns.php')).'">Campaigns & Rewards</a>';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Reward Wallet</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=101')) ?>"></head>
<body class="cr-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='reward_wallet';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main cr-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="cr-wrap">
<?php if($notice):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if($revealed):?><section class="cr-claim-card"><span>Fresh credential</span><h2><?= e((string)($revealed['public_id']??'Reward')) ?></h2><div class="cr-code"><?= e((string)$revealed['credential']) ?></div><p>Show this credential to the merchant. Their authorized operator must also enter the Merchant Claim Code.</p></section><?php endif;?>

<section class="cr-card"><header><div><span>Inbox</span><h2>Active Rewards</h2></div></header>
<div class="cr-campaign-grid"><?php foreach($wallet['inbox'] as $row):?><article class="cr-campaign"><div class="cr-status active"><?= e(ucfirst((string)$row['status'])) ?></div><h3><?= e((string)$row['reward_name']) ?></h3><p><?= e((string)$row['merchant_name']) ?> · <?= e((string)$row['campaign_name']) ?></p><small>Credential ending <?= e((string)$row['credential_last4']) ?><?php if(!empty($row['expires_at'])):?> · Expires <?= e(date('M j, Y',strtotime((string)$row['expires_at']))) ?><?php endif;?></small><form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="reveal_credential"><input type="hidden" name="issuance_id" value="<?= (int)$row['id'] ?>"><button class="cr-btn primary" type="submit">Generate redemption credential</button></form></article><?php endforeach;?><?php if(!$wallet['inbox']):?><div class="cr-empty"><h3>No active Rewards</h3><p>Rewards issued to your VP3-linked contact identity will appear here.</p></div><?php endif;?></div></section>

<section class="cr-card"><header><div><span>History</span><h2>Claimed Rewards</h2></div></header><div class="cr-list"><?php foreach($wallet['claimed'] as $row):?><article><div><strong><?= e((string)$row['reward_name']) ?></strong><small><?= e((string)$row['merchant_name']) ?> · <?= e((string)$row['campaign_name']) ?><?php if(!empty($row['claimed_at'])):?> · Claimed <?= e(date('M j, Y',strtotime((string)$row['claimed_at']))) ?><?php endif;?></small></div></article><?php endforeach;?><?php if(!$wallet['claimed']):?><p>No claimed Rewards yet.</p><?php endif;?></div></section>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
