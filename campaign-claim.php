<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

$user=current_user();$pdo=db();
if(!$user||!$pdo||!function_exists('campaigns_rewards_platform_schema_ready_v100')||!campaigns_rewards_platform_schema_ready_v100($pdo)){
    http_response_code(503);exit('Claim Terminal is unavailable.');
}
$uid=(int)$user['id'];$merchants=[];
foreach(campaigns_rewards_platform_merchants_v100($pdo,$uid) as $merchant){
    if(campaigns_rewards_platform_can_v100($pdo,(int)$merchant['id'],$uid,'claims.process'))$merchants[]=$merchant;
}
$merchantId=max(0,(int)($_REQUEST['merchant']??$_REQUEST['merchant_id']??0));
if($merchantId<1&&$merchants)$merchantId=(int)$merchants[0]['id'];
$merchant=null;foreach($merchants as $candidate)if((int)$candidate['id']===$merchantId){$merchant=$candidate;break;}
if(!$merchant&&$merchants){$merchant=$merchants[0];$merchantId=(int)$merchant['id'];}
$error='';$notice='';$claim=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Try again.';
    elseif(!$merchant)$error='Choose a Merchant where you can process Claims.';
    else{
        try{
            $claim=campaigns_rewards_process_claim_v100(
                $pdo,
                trim((string)($_POST['reward_credential']??'')),
                trim((string)($_POST['merchant_claim_code']??'')),
                $uid,
                [
                    'online'=>true,
                    'location_id'=>max(0,(int)($_POST['location_id']??0)),
                    'order_ref'=>trim((string)($_POST['order_ref']??'')),
                    'actor_type'=>'user',
                    'request_fingerprint'=>(string)($_SERVER['REMOTE_ADDR']??'').'|'.(string)($_SERVER['HTTP_USER_AGENT']??''),
                ]
            );
            if((int)$claim['merchant_id']!==$merchantId)throw new RuntimeException('Claim belongs to a different Merchant.');
            $notice='Reward claimed successfully.';
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
$locations=[];
if($merchant){
    $stmt=$pdo->prepare("SELECT * FROM merchant_locations WHERE merchant_id=? AND is_active=1 ORDER BY is_primary DESC,name,id");
    $stmt->execute([$merchantId]);$locations=$stmt->fetchAll()?:[];
}
$memberHeaderUser=$user;$memberHeaderTitle='Claim Terminal';$memberHeaderSubtitle='Three-factor Reward redemption: credential, Merchant Claim Code and authorized operator';
$memberHeaderActions='<a class="cr-btn" href="'.e(url('/campaigns.php'.($merchantId?'?merchant='.$merchantId:''))).'">Campaigns & Rewards</a>';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Claim Terminal</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=101')) ?>"></head>
<body class="cr-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='claim_terminal';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main cr-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="cr-wrap">
<?php if($notice):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if(!$merchants):?><section class="cr-empty"><h2>No Claim Terminal access</h2><p>You need an active Merchant role with <code>claims.process</code> capability.</p></section><?php else:?>
<section class="cr-toolbar"><div><strong>Merchant Claim Terminal</strong><span>Authoritative V1 redemption is online-only.</span></div><form method="get"><select name="merchant" onchange="this.form.submit()"><?php foreach($merchants as $m):?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id']===$merchantId?' selected':'' ?>><?= e((string)$m['name']) ?></option><?php endforeach;?></select></form></section>
<section class="cr-card"><header><div><span>Redeem</span><h2><?= e((string)$merchant['name']) ?></h2></div></header><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><label>Reward Credential<input name="reward_credential" required autocomplete="off" spellcheck="false" placeholder="Customer Reward Credential"></label><label>Merchant Claim Code<input name="merchant_claim_code" required autocomplete="off" spellcheck="false" placeholder="Merchant Claim Code"></label><div class="cr-form-grid"><label>Location<select name="location_id"><option value="">No location</option><?php foreach($locations as $location):?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach;?></select></label><label>Order / receipt reference<input name="order_ref" maxlength="190"></label></div><button class="cr-btn primary" type="submit">Claim Reward</button></form></section>
<?php if($claim):?><section class="cr-claim-card"><span>Claim accepted</span><h2><?= e((string)$claim['reward_product_public_id']) ?></h2><div class="cr-status active">Claimed</div><p><strong>Claim:</strong> <?= e((string)$claim['public_id']) ?></p><p><strong>Reward Issuance:</strong> <?= e((string)$claim['reward_issuance_public_id']) ?></p><p><strong>Campaign:</strong> <?= e((string)$claim['campaign_public_id']) ?></p></section><?php endif;?>
<?php endif;?>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
