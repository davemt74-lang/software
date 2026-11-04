<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
header('Cache-Control: no-store');

$user=current_user();$pdo=db();
if(!$user||!$pdo||!function_exists('campaigns_rewards_platform_schema_ready_v100')||!campaigns_rewards_platform_schema_ready_v100($pdo)){
    http_response_code(503);exit('Claim Terminal is unavailable.');
}
$uid=(int)$user['id'];$merchants=[];
foreach(campaigns_rewards_accessible_merchants_v100($pdo,$user) as $merchant){
    if(($merchant['status']??'')==='active'&&campaigns_rewards_platform_can_v100($pdo,(int)$merchant['id'],$uid,'claims.process'))$merchants[]=$merchant;
}
$isPost=$_SERVER['REQUEST_METHOD']==='POST';
$merchantId=max(0,(int)($isPost?($_POST['merchant_id']??0):($_GET['merchant']??$_GET['merchant_id']??0)));
if(!$isPost&&$merchantId<1&&$merchants)$merchantId=(int)$merchants[0]['id'];
$merchant=null;foreach($merchants as $candidate)if((int)$candidate['id']===$merchantId){$merchant=$candidate;break;}
$error=!$isPost&&$merchantId>0&&!$merchant?'Choose a Merchant where you can process Claims.':'';
$notice='';$claim=null;
$claimKey=$isPost?trim((string)($_POST['idempotency_key']??'')):'';
$locationId=$isPost?max(0,(int)($_POST['location_id']??0)):0;
$orderRef=$isPost?trim((string)($_POST['order_ref']??'')):'';

if($isPost){
    if(!verify_csrf())$error='Session expired. Try again.';
    elseif(!$merchant)$error='Choose a Merchant where you can process Claims.';
    else{
        try{
            $credential=trim((string)($_POST['reward_credential']??''));
            if(str_starts_with($credential,'VP3R:'))$credential=substr($credential,5);
            $claim=campaigns_rewards_process_claim_v100(
                $pdo,
                $credential,
                trim((string)($_POST['merchant_claim_code']??'')),
                $uid,
                [
                    'online'=>true,
                    'expected_merchant_id'=>$merchantId,
                    'location_id'=>$locationId,
                    'order_ref'=>$orderRef,
                    'idempotency_key'=>$claimKey,
                    'actor_type'=>'user',
                    'request_fingerprint'=>(string)($_SERVER['REMOTE_ADDR']??'').'|'.(string)($_SERVER['HTTP_USER_AGENT']??''),
                ]
            );
            $notice=!empty($claim['duplicate'])?'Existing redemption receipt recovered.':'Reward claimed successfully.';
        }catch(PDOException $e){
            error_log('VP3 Claim Terminal database error: '.$e->getMessage());
            $error='Claim service is temporarily unavailable. Retry the same request.';
        }catch(RuntimeException|InvalidArgumentException|DomainException $e){
            $error=$e->getMessage();
        }catch(Throwable $e){
            error_log('VP3 Claim Terminal error: '.$e->getMessage());
            $error='Claim service is temporarily unavailable. Retry the same request.';
        }
    }
}
$locations=$merchant?campaigns_rewards_claim_locations_v100($pdo,$merchantId,$uid):[];
if($claim||!preg_match('/\A[A-Za-z0-9._:-]{1,190}\z/',$claimKey))$claimKey=bin2hex(random_bytes(16));
if($claim){$orderRef='';$locationId=0;}
if($locationId===0&&count($locations)===1)$locationId=(int)$locations[0]['id'];
$memberHeaderUser=$user;$memberHeaderTitle='Claim Terminal';$memberHeaderSubtitle='Three-factor Reward redemption: credential, Merchant Claim Code and authorized operator';
$memberHeaderActions='<a class="cr-btn" href="'.e(url('/rewards.php'.($merchantId?'?merchant='.$merchantId:''))).'">Rewards</a>';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Claim Terminal</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=101')) ?>"></head>
<body class="cr-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='claim_terminal';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main cr-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="cr-wrap">
<?php if($notice):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if(!$merchants):?><section class="cr-empty"><h2>No Claim Terminal access</h2><p>You need an active Merchant role with <code>claims.process</code> capability.</p></section><?php else:?>
<section class="cr-toolbar"><div><strong>Merchant Claim Terminal</strong><span>Authoritative V1 redemption is online-only.</span></div><form method="get"><select name="merchant" onchange="this.form.submit()"><?php foreach($merchants as $m):?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id']===$merchantId?' selected':'' ?>><?= e((string)$m['name']) ?></option><?php endforeach;?></select></form></section>
<?php if($merchant&&!$locations):?><section class="cr-empty"><h2>No eligible redemption locations</h2><p>An active in-person Merchant location is required.</p></section><?php endif;?>
<?php if($merchant&&$locations):?>
<section class="cr-card"><header><div><span>Redeem</span><h2><?= e((string)$merchant['name']) ?></h2></div></header>
<form method="post" class="cr-form"><?= csrf_field() ?>
<input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<input type="hidden" name="idempotency_key" value="<?= e($claimKey) ?>">
<label>Reward Credential<input id="claimRewardCredential" name="reward_credential" required autocomplete="off" spellcheck="false" placeholder="Customer Reward Credential"></label>
<label>Merchant Claim Code<input name="merchant_claim_code" required autocomplete="off" spellcheck="false" placeholder="Merchant Claim Code"></label>
<div class="cr-form-grid">
<label>Location<select name="location_id" required><option value="">Choose a location</option><?php foreach($locations as $location):?><option value="<?= (int)$location['id'] ?>"<?= (int)$location['id']===$locationId?' selected':'' ?>><?= e((string)$location['name']) ?></option><?php endforeach;?></select></label>
<label>Order / receipt reference<input name="order_ref" maxlength="190" value="<?= e($orderRef) ?>"></label>
</div><button class="cr-btn primary" type="submit">Claim Reward</button>
</form></section>
<?php endif;?>
<?php if($claim):?>
<section class="cr-claim-card"><span>Claim accepted</span><h2><?= e((string)$claim['reward_product_public_id']) ?></h2><div class="cr-status active">Claimed</div>
<p><strong>Merchant:</strong> <?= e((string)$merchant['name']) ?></p>
<p><strong>Location:</strong> <?= e((string)($claim['location_name']??'')) ?></p>
<p><strong>Time:</strong> <?= e((string)$claim['claimed_at']) ?> UTC</p>
<p><strong>Claim:</strong> <?= e((string)$claim['public_id']) ?></p>
<p><strong>Reward Issuance:</strong> <?= e((string)$claim['reward_issuance_public_id']) ?></p>
<p><strong>Campaign:</strong> <?= e((string)$claim['campaign_public_id']) ?></p></section>
<?php endif;?>
<?php endif;?>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script>
<script>
(function(){
  var input=document.getElementById('claimRewardCredential');if(!input)return;
  input.addEventListener('input',function(){if(input.value.indexOf('VP3R:')===0)input.value=input.value.slice(5);});
  var raw=String(location.hash||'').replace(/^#/,'');if(!raw)return;
  var params=new URLSearchParams(raw);var credential=params.get('reward')||'';
  if(credential){input.value=credential;input.focus();history.replaceState(null,'',location.pathname+location.search);}
})();
</script></body></html>
