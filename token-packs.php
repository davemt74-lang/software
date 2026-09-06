<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

$user=current_user();$pdo=db();
if(!$user||!$pdo){flash('error','Account unavailable.');redirect(url('/login.php'));}
if(!token_pack_schema_ready($pdo)){
    if(has_permission('users.manage',$user))redirect(url('/upgrade.php'));
    flash('error','AI token purchases are unavailable until an administrator completes the database upgrade.');
    redirect(url('/subscription.php'));
}

function token_pack_ui_money(int $cents,string $currency='usd'): string
{
    $symbol=strtolower($currency)==='usd'?'$':strtoupper($currency).' ';
    return $symbol.number_format(max(0,$cents)/100,2);
}
function token_pack_ui_redirect(string $target): never
{
    if(!preg_match('#^https://#i',$target))throw new RuntimeException('Billing provider returned an invalid Checkout URL.');
    header('Location: '.$target);exit;
}

$billingReady=billing_stripe_configured()&&billing_stripe_webhook_secret()!=='';
if(isset($_GET['checkout'])&&$_GET['checkout']==='success'){
    try{
        $sessionId=trim((string)($_GET['session_id']??''));
        $result=$sessionId!==''?token_pack_reconcile_return($user,$sessionId):null;
        if($result&&($result['state']??'')==='credited')flash('notice',number_format((int)($result['tokens']??0)).' AI tokens were added to your account.');
        else flash('notice','Payment was received. Your token credit will appear as soon as Stripe confirms the payment.');
    }catch(Throwable $e){flash('error','Payment returned successfully, but token synchronization needs attention: '.$e->getMessage());}
    redirect(url('/token-packs.php'));
}
if(isset($_GET['checkout'])&&$_GET['checkout']==='cancelled'){
    flash('notice','Checkout was not completed. No tokens were added and your package was not changed.');
    redirect(url('/token-packs.php'));
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())flash('error','Your session expired. Please try again.');
    else{
        try{
            $action=(string)($_POST['action']??'');
            if($action!=='buy_pack')throw new RuntimeException('Unknown token purchase action.');
            if(!$billingReady)throw new RuntimeException('AI token checkout is not configured yet.');
            $flow=token_pack_begin_purchase($user,max(0,(int)($_POST['pack_id']??0)));
            token_pack_ui_redirect((string)$flow['url']);
        }catch(Throwable $e){flash('error',$e->getMessage());}
    }
    redirect(url('/token-packs.php'));
}

$packs=token_pack_public($pdo);$history=token_pack_purchase_history((int)$user['id'],25,$pdo);$balance=subscription_ai_balance($user,$pdo);
$package=(string)(subscription_current($user)['package_name']??'Current package');
$remaining=(int)($balance['remaining']??0);$creditsRemaining=(int)($balance['credits_remaining']??0);$allowance=(int)($balance['package_allowance']??0);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Buy AI Tokens | VP3</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/account.css?v=account-light-20260904')) ?>"><style>
.token-wrap{display:grid;gap:16px}.token-card,.token-pack,.token-stat{border:1px solid #e4e7ec;border-radius:16px;background:#fff}.token-card{padding:20px}.token-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.token-stat{padding:16px}.token-stat span,.token-muted{display:block;color:#667085;font-size:12px}.token-stat strong{display:block;margin-top:5px;font-size:24px}.token-packs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.token-pack{padding:20px;display:flex;flex-direction:column;gap:10px}.token-pack h3{margin:0;font-size:21px}.token-amount{font-size:28px;font-weight:760}.token-price{font-size:20px;font-weight:720}.token-pack p{color:#475467;line-height:1.55;min-height:48px}.token-actions{margin-top:auto}.token-btn{appearance:none;border:1px solid #101828;border-radius:9px;background:#101828;color:#fff;padding:10px 14px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}.token-btn.secondary{background:#fff;color:#101828;border-color:#d0d5dd}.token-btn[disabled]{opacity:.45;cursor:not-allowed}.token-table{width:100%;border-collapse:collapse}.token-table th,.token-table td{padding:11px 8px;border-bottom:1px solid #eaecf0;text-align:left}.token-table th{font-size:12px;color:#667085}.token-section-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.token-section-head h2{margin:0}.token-empty{color:#667085;padding:12px 0}@media(max-width:900px){.token-packs{grid-template-columns:1fr 1fr}}@media(max-width:680px){.token-packs,.token-stats{grid-template-columns:1fr}.token-section-head{align-items:flex-start;flex-direction:column}}
</style></head><body><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='token-packs';require __DIR__.'/includes/workspace-sidebar-v82.php';?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main account-chat-main"><header class="chat-topbar"><button class="chat-icon-button mobile-only" id="openChatSidebar" type="button">☰</button><div class="chat-topbar-title"><strong>Buy AI Tokens</strong><span>One-time token credits without changing your package</span></div></header><section class="account-canvas"><div class="account-canvas-inner token-wrap">
<section class="account-canvas-hero"><div><span>AI Capacity</span><h1>Add tokens when you need them.</h1><div class="account-canvas-meta"><span><?= e($package) ?></span><span>Token packs do not extend trials or change package entitlements.</span></div></div><div class="account-canvas-actions"><a class="account-shell-button" href="<?= e(url('/subscription.php')) ?>">Plan & Usage</a><?php if(has_permission('users.manage',$user)):?><a class="account-shell-button" href="<?= e(url('/admin/token-packs.php')) ?>">Manage Token Packs</a><?php endif;?></div></section>
<?php if(!$billingReady):?><section class="token-card"><strong>Token checkout is not active yet.</strong><p class="token-muted">An administrator must configure both the Stripe API key and webhook secret before token packs can be purchased.</p></section><?php endif;?>
<div class="token-stats"><div class="token-stat"><span>Package allowance</span><strong><?= !empty($balance['unlimited'])?'Unlimited':number_format($allowance) ?></strong></div><div class="token-stat"><span>Top-up tokens remaining</span><strong><?= number_format($creditsRemaining) ?></strong></div><div class="token-stat"><span>Total available now</span><strong><?= !empty($balance['unlimited'])?'Unlimited':number_format(max(0,$remaining)) ?></strong></div></div>
<section class="token-card"><div class="token-section-head"><div><h2>Token Packs</h2><span class="token-muted">One-time purchases are added to your existing AI token balance after verified payment.</span></div></div><?php if($packs):?><div class="token-packs"><?php foreach($packs as $pack):?><article class="token-pack"><span class="token-muted">AI TOKEN PACK</span><h3><?= e((string)$pack['name']) ?></h3><div class="token-amount"><?= number_format((int)$pack['token_amount']) ?></div><div class="token-price"><?= e(token_pack_ui_money((int)$pack['price_cents'])) ?></div><p><?= e((string)$pack['description']) ?></p><div class="token-muted"><?= (int)($pack['expires_days']??0)>0?'Unused purchased tokens expire '.(int)$pack['expires_days'].' days after purchase.':'Purchased tokens do not have a pack-level expiration.' ?></div><div class="token-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="buy_pack"><input type="hidden" name="pack_id" value="<?= (int)$pack['id'] ?>"><button class="token-btn" type="submit" <?= !$billingReady?'disabled':'' ?>>Buy <?= number_format((int)$pack['token_amount']) ?> Tokens</button></form></div></article><?php endforeach;?></div><?php else:?><div class="token-empty">No public token packs are available yet.</div><?php endif;?></section>
<section class="token-card"><div class="token-section-head"><div><h2>Purchase History</h2><span class="token-muted">Completed purchases remain historically accurate even if an Admin changes a pack later.</span></div></div><?php if($history):?><div style="overflow:auto"><table class="token-table"><thead><tr><th>Date</th><th>Pack</th><th>Tokens</th><th>Price</th><th>Status</th></tr></thead><tbody><?php foreach($history as $row):?><tr><td><?= e(date('M j, Y',strtotime((string)$row['created_at']))) ?></td><td><?= e((string)$row['pack_name_snapshot']) ?></td><td><?= number_format((int)$row['token_amount']) ?></td><td><?= e(token_pack_ui_money((int)$row['price_cents'],(string)$row['currency'])) ?></td><td><?= e(ucfirst((string)$row['status'])) ?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="token-empty">You have not purchased any AI token packs yet.</div><?php endif;?></section>
</div></section></main></div><script src="<?= e(url('/chat-shell.js?v=82')) ?>"></script></body></html>