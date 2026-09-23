<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();if(!$pdo||!campaigns_rewards_schema_ready_v100($pdo)){http_response_code(503);exit('Claim verification temporarily unavailable.');}
$code=(string)($_GET['code']??$_POST['code']??'');$claim=campaigns_rewards_claim_by_code_v100($pdo,$code);
if(!$claim){http_response_code(404);exit('Claim code not found.');}
$user=current_user();$canManage=$user&&campaigns_rewards_can_manage_merchant_v100($pdo,(int)$claim['merchant_account_id'],(int)$user['id']);
$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$user||!$canManage){http_response_code(403);exit('Merchant admin access is required.');}
    if(!verify_csrf())$error='Session expired. Try again.';
    else{
        try{
            $action=(string)($_POST['action']??'');
            if($action==='validate'){$claim=campaigns_rewards_validate_claim_v100($pdo,$code,(int)$user['id']);$notice='Claim validated.';}
            elseif($action==='redeem'){$claim=campaigns_rewards_redeem_claim_v100($pdo,$code,(int)$user['id']);$notice='Claim redeemed and conversion recorded.';}
            else throw new RuntimeException('Unknown claim action.');
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
$status=(string)$claim['status'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f6f7f8"><title>Reward Claim · <?= e((string)$claim['merchant_name']) ?></title><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=100')) ?>"></head><body class="cr-public"><main class="cr-claim-shell">
<a class="cr-back" href="<?= e(campaigns_rewards_campaign_url_v100((string)$claim['campaign_slug'])) ?>">← <?= e((string)$claim['campaign_title']) ?></a>
<section class="cr-claim-card"><span>Reward claim</span><h1><?= e((string)$claim['reward_title']) ?></h1><?php if(trim((string)$claim['value_label'])!==''):?><p class="cr-claim-value"><?= e((string)$claim['value_label']) ?></p><?php endif;?><div class="cr-code"><?= e((string)$claim['claim_code']) ?></div><div class="cr-status <?= e($status) ?>"><?= e(ucfirst($status)) ?></div><p>Issued by <strong><?= e((string)$claim['merchant_name']) ?></strong>. Show this code to the merchant when redeeming the reward.</p><?php if($status==='redeemed'&&!empty($claim['redeemed_at'])):?><p>Redeemed <?= e(date('M j, Y g:i A',strtotime((string)$claim['redeemed_at']))) ?> UTC</p><?php endif;?></section>
<?php if($notice):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if($canManage&&in_array($status,['issued','validated'],true)):?><section class="cr-claim-admin"><h2>Merchant controls</h2><div class="cr-actions"><?php if($status==='issued'):?><form method="post"><?= csrf_field() ?><input type="hidden" name="code" value="<?= e((string)$claim['claim_code']) ?>"><input type="hidden" name="action" value="validate"><button class="cr-btn">Validate claim</button></form><?php endif;?><form method="post" onsubmit="return confirm('Redeem this claim now?')"><?= csrf_field() ?><input type="hidden" name="code" value="<?= e((string)$claim['claim_code']) ?>"><input type="hidden" name="action" value="redeem"><button class="cr-btn primary">Redeem reward</button></form></div></section><?php endif;?>
<footer>VP3 Campaigns &amp; Rewards verification</footer></main></body></html>
