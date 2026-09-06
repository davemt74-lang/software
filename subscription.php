<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$user=current_user();$pdo=db();
if(!$user||!$pdo){flash('error','Account unavailable.');redirect(url('/login.php'));}
if(!subscription_schema_ready($pdo)||!subscription_self_service_schema_ready($pdo)){
    if(has_permission('users.manage',$user))redirect(url('/upgrade.php'));
    flash('error','Plan management is unavailable until an administrator completes the database upgrade.');
    redirect(url('/account.php'));
}

function subscription_ui_money(int $cents,string $suffix=''): string
{
    if($cents<=0)return 'Free';
    $amount=$cents/100;
    $formatted=fmod($amount,1.0)===0.0?number_format($amount,0):number_format($amount,2);
    return '$'.$formatted.$suffix;
}

function subscription_ui_plan_features(array $package,int $limit=6): array
{
    static $catalog=null;
    $catalog??=subscription_capability_catalog();
    $features=[];
    foreach(($package['entitlements']??[]) as $row){
        $key=(string)($row['capability_key']??'');
        if((int)($row['is_enabled']??0)!==1||str_starts_with($key,'permission.')||in_array($key,['legacy.permissions','ai.unlimited'],true))continue;
        $meta=$catalog[$key]??null;if(!$meta)continue;
        $label=(string)$meta['label'];
        if(($meta['type']??'boolean')==='limit'&&$row['limit_value']!==null)$label.=' · '.number_format((int)$row['limit_value']);
        $features[]=$label;
        if(count($features)>=$limit)break;
    }
    return $features;
}

$billingInterval=subscription_self_service_interval((string)($_GET['billing']??$_POST['billing_interval']??'monthly'));
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())flash('error','Your session expired. Please try again.');
    else{
        try{
            $action=(string)($_POST['action']??'');
            if($action==='select_plan'){
                $result=subscription_self_service_select_plan($user,max(0,(int)($_POST['package_id']??0)),$billingInterval);
                $name=(string)($result['package']['name']??'plan');
                if($result['status']==='pending_billing')flash('notice',$name.' selected. Payment is required before your package changes; your current access remains active.');
                elseif($result['status']==='scheduled')flash('notice',$name.' is scheduled to begin when your current access ends.');
                elseif($result['status']==='applied')flash('notice',$name.' is now your active package.');
                else flash('notice',$name.' is already your current package.');
            }elseif($action==='cancel_request'){
                subscription_self_service_cancel_request($user,max(0,(int)($_POST['request_id']??0)));
                flash('notice','Your pending plan change was cancelled.');
            }elseif($action==='schedule_cancel'){
                $result=subscription_self_service_schedule_cancel($user);
                flash('notice','Your plan is scheduled to end on '.date('M j, Y',strtotime((string)$result['effective_at'])).'.');
            }else throw new RuntimeException('Unknown plan-management action.');
        }catch(Throwable $e){flash('error',$e->getMessage());}
    }
    redirect(url('/subscription.php?billing='.$billingInterval));
}

$subscription=subscription_current($user);
$balance=subscription_ai_balance($user,$pdo);
$usage=subscription_usage_by_scope((int)$user['id'],30);
$credits=subscription_recent_credits((int)$user['id'],30);
$openRequest=subscription_self_service_open_request((int)$user['id'],$pdo);
$planHistory=subscription_self_service_history((int)$user['id'],20);
$packages=[];
foreach(subscription_packages(true) as $candidate){
    $candidate=subscription_package((int)$candidate['id'])?:$candidate;
    if((int)($candidate['is_trial']??0)===1&&(int)($subscription['package_id']??0)!==(int)$candidate['id'])continue;
    $packages[]=$candidate;
}

$allowance=max(0,(int)($balance['package_allowance']??0));
$remaining=(int)($balance['remaining']??0);
$used=(int)($balance['used']??0);
$creditsRemaining=(int)($balance['credits_remaining']??0);
$percent=$allowance>0?min(100,(int)round(($used/$allowance)*100)):0;
$warning='';
if(empty($balance['unlimited'])){
    if($remaining<=0)$warning='Your AI token balance is exhausted. Non-AI features remain available.';
    elseif($percent>=95)$warning='You have used at least 95% of your package AI allowance.';
    elseif($percent>=80)$warning='You have used at least 80% of your package AI allowance.';
    elseif($percent>=50)$warning='You have used at least 50% of your package AI allowance.';
}
$currentPackage=$subscription?subscription_package((int)$subscription['package_id']):null;
$entitlements=[];
foreach(($currentPackage['entitlements']??[]) as $row)$entitlements[(string)$row['capability_key']]=$row;
$periodEnd=(string)($subscription['current_period_end']??$subscription['ends_at']??'');
$currentManaged=$subscription&&(int)($subscription['billing_required']??0)!==1&&(int)($subscription['is_trial']??0)!==1;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Plan & Usage | VP3</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/account.css?v=account-light-20260904')) ?>"><style>
.plan-wrap{display:grid;gap:16px}.plan-card,.plan-stat,.plan-option{border:1px solid #e4e7ec;border-radius:16px;background:#fff}.plan-card{padding:20px}.plan-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.plan-stat{padding:16px}.plan-stat span,.plan-option small,.plan-muted{color:#667085}.plan-stat span,.plan-option small{display:block;font-size:12px}.plan-stat strong{display:block;font-size:24px;margin-top:5px}.plan-status-banner{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:16px 18px;border:1px solid #d0d5dd;border-radius:14px;background:#f9fafb}.plan-status-banner.pending{border-color:#b2ccff;background:#f5f8ff}.plan-status-banner.scheduled{border-color:#a6f4c5;background:#f6fef9}.plan-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.plan-btn{appearance:none;border:1px solid #d0d5dd;border-radius:9px;background:#fff;padding:9px 13px;font:inherit;font-weight:650;color:#101828;cursor:pointer;text-decoration:none}.plan-btn.primary{background:#101828;color:#fff;border-color:#101828}.plan-btn.danger{color:#b42318}.plan-btn[disabled]{opacity:.45;cursor:not-allowed}.billing-toggle{display:inline-flex;padding:4px;border:1px solid #e4e7ec;border-radius:11px;background:#f9fafb}.billing-toggle a{padding:7px 12px;border-radius:8px;text-decoration:none;color:#475467;font-weight:650}.billing-toggle a.active{background:#fff;color:#101828;box-shadow:0 1px 2px rgba(16,24,40,.08)}.plan-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.plan-option{padding:20px;display:flex;flex-direction:column;min-height:300px}.plan-option.current{outline:2px solid #101828}.plan-option.selected{outline:2px solid #84adff}.plan-option h3{font-size:22px;margin:6px 0}.plan-price{font-size:28px;font-weight:750;margin:8px 0}.plan-price span{font-size:13px;font-weight:500;color:#667085}.plan-option ul{padding-left:18px;color:#475467;line-height:1.6;flex:1}.plan-feature-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.plan-feature{border:1px solid #eaecf0;border-radius:10px;padding:12px}.plan-feature b{display:block}.plan-progress{height:8px;background:#eaecf0;border-radius:999px;overflow:hidden;margin-top:9px}.plan-progress i{display:block;height:100%;background:#101828}.plan-table{width:100%;border-collapse:collapse}.plan-table th,.plan-table td{padding:11px 8px;border-bottom:1px solid #eaecf0;text-align:left;vertical-align:top}.plan-table th{font-size:12px;color:#667085}.plan-section-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:14px}.plan-section-head h2{margin:0}.plan-note{font-size:13px;color:#667085}.plan-empty{padding:18px 0;color:#667085}.plan-danger-zone{border-color:#fecdca}.plan-danger-zone h2{color:#b42318}@media(max-width:1000px){.plan-options{grid-template-columns:1fr 1fr}.plan-stats,.plan-feature-grid{grid-template-columns:1fr 1fr}}@media(max-width:680px){.plan-options,.plan-stats,.plan-feature-grid{grid-template-columns:1fr}.plan-status-banner,.plan-section-head{flex-direction:column;align-items:stretch}}
</style></head><body><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='subscription';require __DIR__.'/includes/workspace-sidebar-v82.php';?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main account-chat-main"><header class="chat-topbar"><button class="chat-icon-button mobile-only" id="openChatSidebar" type="button">☰</button><div class="chat-topbar-title"><strong>Plan & Usage</strong><span>Manage your package and AI capacity</span></div></header><section class="account-canvas"><div class="account-canvas-inner plan-wrap">

<section class="account-canvas-hero"><div><span>Current Package</span><h1><?= e((string)($subscription['package_name']??'No Package')) ?></h1><div class="account-canvas-meta"><span><?= e(ucfirst((string)($subscription['status']??'unassigned'))) ?></span><?php if($periodEnd):?><span><?= (int)($subscription['is_trial']??0)===1?'Trial ends':'Current period ends' ?> <?= e(date('M j, Y',strtotime($periodEnd))) ?></span><?php endif;?><?php if($currentManaged):?><span>Admin managed</span><?php endif;?></div></div><div class="account-canvas-actions"><a class="account-shell-button" href="<?= e(url('/account.php')) ?>">My Account</a></div></section>

<?php if($openRequest): $requestStatus=(string)$openRequest['status'];?>
<section class="plan-status-banner <?= e($requestStatus==='pending_billing'?'pending':'scheduled') ?>"><div><?php if($requestStatus==='pending_billing'):?><strong><?= e((string)$openRequest['target_package_name']) ?> selected</strong><div class="plan-note">Payment is required before this package becomes active. Your current package and entitlements have not changed.</div><?php elseif((string)$openRequest['action']==='cancel'):?><strong>Cancellation scheduled</strong><div class="plan-note">Your current package remains active through <?= e(date('M j, Y',strtotime((string)$openRequest['effective_at']))) ?>.</div><?php else:?><strong><?= e((string)$openRequest['target_package_name']) ?> scheduled</strong><div class="plan-note">This package will begin <?= e(date('M j, Y',strtotime((string)$openRequest['effective_at']))) ?> after your current access ends.</div><?php endif;?></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_request"><input type="hidden" name="request_id" value="<?= (int)$openRequest['id'] ?>"><input type="hidden" name="billing_interval" value="<?= e($billingInterval) ?>"><button class="plan-btn" type="submit"><?= (string)$openRequest['action']==='cancel'?'Keep Current Plan':'Cancel Change' ?></button></form></section>
<?php endif;?>

<?php if($warning):?><section class="plan-status-banner"><div><strong>AI usage notice</strong><div class="plan-note"><?= e($warning) ?></div></div></section><?php endif;?>

<div class="plan-stats"><div class="plan-stat"><span>Included AI tokens</span><strong><?= !empty($balance['unlimited'])?'Unlimited':number_format($allowance) ?></strong></div><div class="plan-stat"><span>Token top-ups</span><strong><?= number_format($creditsRemaining) ?></strong></div><div class="plan-stat"><span>Used this period</span><strong><?= number_format($used) ?></strong></div><div class="plan-stat"><span>Available now</span><strong><?= !empty($balance['unlimited'])?'Unlimited':number_format($remaining) ?></strong></div></div>
<?php if(empty($balance['unlimited'])&&$allowance>0):?><section class="plan-card"><strong><?= $percent ?>% of included AI allowance used</strong><div class="plan-progress" aria-label="AI usage <?= $percent ?> percent"><i style="width:<?= $percent ?>%"></i></div></section><?php endif;?>

<section class="plan-card"><div class="plan-section-head"><div><h2>Choose Your Plan</h2><div class="plan-note">Plans, pricing and included features come directly from Admin → Packages.</div></div><div class="billing-toggle"><a class="<?= $billingInterval==='monthly'?'active':'' ?>" href="<?= e(url('/subscription.php?billing=monthly')) ?>">Monthly</a><a class="<?= $billingInterval==='annual'?'active':'' ?>" href="<?= e(url('/subscription.php?billing=annual')) ?>">Annual</a></div></div>
<div class="plan-options"><?php foreach($packages as $p):$packageId=(int)$p['id'];$isCurrent=$subscription&&$packageId===(int)$subscription['package_id'];$isSelected=$openRequest&&(int)($openRequest['target_package_id']??0)===$packageId;$price=subscription_self_service_price_cents($p,$billingInterval);$features=subscription_ui_plan_features($p);?><article class="plan-option <?= $isCurrent?'current':'' ?> <?= $isSelected?'selected':'' ?>"><small><?= (int)$p['is_trial']===1?'Trial':'Package' ?><?= $isCurrent?' · Current':'' ?><?= $isSelected?' · Selected':'' ?></small><h3><?= e((string)$p['name']) ?></h3><div class="plan-price"><?php if((int)$p['is_trial']===1):?>Free Trial<?php elseif($price===null):?>—<?php elseif($price===0):?>Free<?php else:?><?= e(subscription_ui_money($price)) ?><span>/<?= $billingInterval==='annual'?'year':'month' ?></span><?php endif;?></div><p class="plan-muted"><?= e((string)$p['description']) ?></p><div class="plan-note"><?= (int)$p['is_trial']===1?number_format((int)$p['trial_tokens']).' trial AI tokens':number_format((int)$p['ai_tokens_monthly']).' AI tokens / month' ?></div><?php if($features):?><ul><?php foreach($features as $feature):?><li><?= e($feature) ?></li><?php endforeach;?></ul><?php else:?><div class="plan-empty">No public feature entitlements configured yet.</div><?php endif;?><div class="plan-actions"><?php if($isCurrent):?><button class="plan-btn" type="button" disabled>Current Plan</button><?php elseif((int)$p['is_trial']===1):?><button class="plan-btn" type="button" disabled>Trial only</button><?php elseif($price===null):?><button class="plan-btn" type="button" disabled>Annual unavailable</button><?php else:?><form method="post" <?= $price===0?'onsubmit="return confirm(\'Change to this free plan? Features not included in the new package may become unavailable.\')"':'' ?>><?= csrf_field() ?><input type="hidden" name="action" value="select_plan"><input type="hidden" name="package_id" value="<?= $packageId ?>"><input type="hidden" name="billing_interval" value="<?= e($billingInterval) ?>"><button class="plan-btn primary" type="submit"><?= $price>0?'Select Plan':'Choose Plan' ?></button></form><?php endif;?></div></article><?php endforeach;?><?php if(!$packages):?><div class="plan-empty">No public packages are currently available.</div><?php endif;?></div></section>

<section class="plan-card"><h2>Included Features</h2><p class="plan-muted">Your package controls commercial access. Artist ownership and Team roles remain separate authorization relationships.</p><div class="plan-feature-grid"><?php foreach(subscription_capability_catalog() as $key=>$meta):if(str_starts_with($key,'permission.')||in_array($key,['legacy.permissions','ai.unlimited'],true))continue;$row=$entitlements[$key]??null;$enabled=$row&&(int)$row['is_enabled']===1;?><div class="plan-feature"><small><?= e((string)($meta['category']??'Feature')) ?></small><b><?= e((string)$meta['label']) ?></b><span><?= $enabled?(($meta['type']??'boolean')==='limit'&&$row['limit_value']!==null?number_format((int)$row['limit_value']).' included':'Included'):'Not included' ?></span></div><?php endforeach;?></div></section>

<section class="plan-card"><h2>AI Usage by Feature</h2><div style="overflow:auto"><table class="plan-table"><thead><tr><th>Feature</th><th>Requests</th><th>Input</th><th>Output</th><th>Total</th></tr></thead><tbody><?php foreach($usage as $row):?><tr><td><?= e((string)$row['scope']) ?></td><td><?= number_format((int)$row['requests']) ?></td><td><?= number_format((int)$row['input_tokens']) ?></td><td><?= number_format((int)$row['output_tokens']) ?></td><td><?= number_format((int)$row['total_tokens']) ?></td></tr><?php endforeach;?><?php if(!$usage):?><tr><td colspan="5">No metered AI usage yet.</td></tr><?php endif;?></tbody></table></div></section>

<section class="plan-card"><h2>Token Credits</h2><p class="plan-muted">Top-ups remain separate from your included package allowance and carry their own expiration rules.</p><div style="overflow:auto"><table class="plan-table"><thead><tr><th>Amount</th><th>Remaining</th><th>Source</th><th>Expires</th></tr></thead><tbody><?php foreach($credits as $row):?><tr><td><?= number_format((int)$row['amount']) ?></td><td><?= number_format((int)$row['remaining_amount']) ?></td><td><?= e((string)$row['source']) ?></td><td><?= $row['expires_at']?e(date('M j, Y',strtotime((string)$row['expires_at']))):'No expiration' ?></td></tr><?php endforeach;?><?php if(!$credits):?><tr><td colspan="4">No token credits yet.</td></tr><?php endif;?></tbody></table></div></section>

<section class="plan-card"><h2>Plan Activity</h2><div style="overflow:auto"><table class="plan-table"><thead><tr><th>Date</th><th>Action</th><th>Plan</th><th>Status</th><th>Effective</th></tr></thead><tbody><?php foreach($planHistory as $row):$label=(string)$row['action']==='cancel'?'Cancel plan':((string)($row['target_package_name']??'')!==''?'Change to '.(string)$row['target_package_name']:'Plan change');?><tr><td><?= e(date('M j, Y',strtotime((string)$row['created_at']))) ?></td><td><?= e($label) ?></td><td><?= e(ucfirst((string)$row['billing_interval'])) ?><?= (int)$row['amount_cents']>0?' · '.e(subscription_ui_money((int)$row['amount_cents'])):'' ?></td><td><?= e(str_replace('_',' ',ucfirst((string)$row['status']))) ?></td><td><?= $row['effective_at']?e(date('M j, Y',strtotime((string)$row['effective_at']))):'—' ?></td></tr><?php endforeach;?><?php if(!$planHistory):?><tr><td colspan="5">No self-service plan activity yet.</td></tr><?php endif;?></tbody></table></div></section>

<?php if($subscription):?><section class="plan-card plan-danger-zone"><h2>Plan Cancellation</h2><?php if((int)($subscription['is_trial']??0)===1):?><p class="plan-muted">Your Free Trial ends automatically on <?= $periodEnd?e(date('M j, Y',strtotime($periodEnd))):'its configured expiration date' ?>. There is no recurring charge to cancel.</p><?php elseif($currentManaged):?><p class="plan-muted">This package was assigned outside self-service billing. An administrator manages its expiration or replacement.</p><?php elseif($openRequest&&(string)$openRequest['action']==='cancel'):?><p class="plan-muted">Cancellation is already scheduled. Use <strong>Keep Current Plan</strong> above to undo it.</p><?php else:?><p class="plan-muted">Canceling keeps your current package active through the end of the paid period. The cancellation can be undone before that date.</p><form method="post" onsubmit="return confirm('Schedule this plan to end at the close of the current billing period?')"><?= csrf_field() ?><input type="hidden" name="action" value="schedule_cancel"><input type="hidden" name="billing_interval" value="<?= e($billingInterval) ?>"><button class="plan-btn danger" type="submit">Cancel at Period End</button></form><?php endif;?></section><?php endif;?>

</div></section></main></div><script src="<?= e(url('/member-shell-v77.js')) ?>"></script></body></html>
