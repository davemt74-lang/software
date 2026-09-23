<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

$user=current_user();$pdo=db();
if(!$user||!$pdo||!function_exists('campaigns_rewards_v110_schema_ready')||!campaigns_rewards_v110_schema_ready($pdo)){
    http_response_code(503);exit('Rewards needs the latest VP3 database upgrade.');
}
if(!campaigns_rewards_user_has_access_v100($pdo,$user)){
    flash('error','Enable Campaigns & Rewards or ask a Merchant Owner for access.');
    redirect(url('/plugins.php'));
}

$uid=(int)$user['id'];
$merchantId=max(0,(int)($_REQUEST['merchant']??$_REQUEST['merchant_id']??0));
$redirectRewards=static function(int $merchantId,string $suffix=''): never{
    redirect(url('/rewards.php'.($merchantId>0?'?merchant='.$merchantId:'').$suffix));
};

$merchants=campaigns_rewards_accessible_merchants_v100($pdo,$user);$merchant=null;
if($merchantId>0)foreach($merchants as $candidate)if((int)$candidate['id']===$merchantId){$merchant=$candidate;break;}
if(!$merchant&&$merchants){$merchant=$merchants[0];$merchantId=(int)$merchant['id'];}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');$redirectRewards($merchantId);}
    $action=(string)($_POST['action']??'');
    try{
        if(!$merchant||$merchantId<1)throw new RuntimeException('Choose a Merchant.');
        if($action==='reward_save'){
            $reward=campaigns_rewards_save_reward_workspace_v110($pdo,$merchantId,$uid,$_POST,max(0,(int)($_POST['reward_id']??0)));
            flash('notice','Reward Product saved.');
            $redirectRewards($merchantId,'&edit_reward='.(int)$reward['id'].'#reward-editor');
        }elseif($action==='reward_issue'){
            $campaignId=max(0,(int)($_POST['campaign_id']??0));$rewardProductId=max(0,(int)($_POST['reward_product_id']??0));$contactId=max(0,(int)($_POST['contact_id']??0));
            if($campaignId<1||$rewardProductId<1||$contactId<1)throw new RuntimeException('Choose a Campaign, Reward Product and CRM Contact.');
            $contact=crm_v180_contact_for_owner($pdo,(int)$merchant['owner_user_id'],$contactId);
            if(!$contact)throw new RuntimeException('CRM Contact is outside this Merchant owner CRM.');
            $issuance=campaigns_rewards_issue_reward_v100($pdo,$campaignId,$rewardProductId,$contactId,$uid,[
                'source'=>'reward_workspace',
                'recipient_user_id'=>max(0,(int)($contact['vp3_user_id']??0)),
                'idempotency_key'=>'reward-workspace:'.hash('sha256',$merchantId.'|'.$campaignId.'|'.$rewardProductId.'|'.$contactId.'|'.(string)($_POST['request_id']??bin2hex(random_bytes(8)))),
            ]);
            if(session_status()===PHP_SESSION_ACTIVE&&is_string($issuance['credential']??null)&&$issuance['credential']!==''){
                $_SESSION['reward_workspace_secret_once']=['credential'=>$issuance['credential'],'public_id'=>$issuance['public_id'],'contact_name'=>$contact['name'],'contact_email'=>$contact['email']];
            }
            flash('notice','Reward issued to CRM Contact.');
        }elseif($action==='claim_code_create'){
            $code=campaigns_rewards_create_claim_code_v100($pdo,$merchantId,$uid,[
                'display_name'=>$_POST['display_name']??'Claim Code',
                'location_id'=>$_POST['location_id']??0,
                'device_label'=>$_POST['device_label']??'',
                'daily_claim_limit'=>$_POST['daily_claim_limit']??null,
                'total_claim_limit'=>$_POST['total_claim_limit']??null,
                'max_value_minor'=>$_POST['max_value_minor']??null,
                'campaign_ids'=>array_filter(array_map('intval',(array)($_POST['campaign_ids']??[]))),
            ]);
            if(session_status()===PHP_SESSION_ACTIVE)$_SESSION['reward_workspace_claim_code_once']=['merchant_id'=>$merchantId,'code'=>$code['code'],'display_name'=>$code['display_name']];
            flash('notice','Merchant Claim Code created. Copy it now; VP3 stores only its hash.');
        }elseif($action==='make_good'){
            $contactId=max(0,(int)($_POST['contact_id']??0));$rewardProductId=max(0,(int)($_POST['reward_product_id']??0));
            $contact=crm_v180_contact_for_owner($pdo,(int)$merchant['owner_user_id'],$contactId);
            if(!$contact)throw new RuntimeException('Choose a CRM Contact.');
            $result=campaigns_rewards_make_good_v100($pdo,$merchantId,$contactId,$uid,[$rewardProductId],[
                'reason_code'=>$_POST['reason_code']??'service_recovery',
                'summary'=>$_POST['summary']??'Make Good',
                'internal_notes'=>$_POST['internal_notes']??'',
            ]);
            $first=$result['issuances'][0]??null;
            if($first&&session_status()===PHP_SESSION_ACTIVE)$_SESSION['reward_workspace_secret_once']=['credential'=>$first['credential']??'','public_id'=>$first['public_id']??'','contact_name'=>$contact['name'],'contact_email'=>$contact['email']];
            flash('notice','Make Good case opened and Reward issued.');
        }elseif($action==='reconcile'){
            $run=campaigns_rewards_reconcile_v100($pdo,$merchantId,$uid);
            flash('notice','Reward reconciliation complete: '.(int)$run['summary']['findings'].' finding(s), no side effects replayed.');
        }else{
            throw new RuntimeException('Unknown Rewards action.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    $redirectRewards($merchantId);
}

$canManage=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'rewards.manage'):false;
$canIssue=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'rewards.issue'):false;
$canClaimCodes=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'claim_codes.manage'):false;
$canClaim=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'claims.process'):false;
$canAnalytics=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'analytics.view'):false;
$canCampaignEdit=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'campaigns.edit'):false;

$campaigns=$merchant?campaigns_rewards_campaigns_v100($pdo,$merchantId):[];
$locations=$merchant?campaigns_rewards_locations_v100($pdo,$merchantId):[];
$rewardProducts=$merchant?campaigns_rewards_reward_products_v110($pdo,$merchantId,$uid):[];
$rewardTypes=[];$contacts=[];$claimCodes=[];$recentClaims=[];$metrics=['products'=>0,'issued'=>0,'active'=>0,'claimed'=>0,'sent'=>0];
if($merchant){
    $q=$pdo->prepare("SELECT type_key,name FROM reward_types WHERE is_active=1 AND (merchant_id=? OR merchant_id IS NULL) ORDER BY is_system DESC,name,id");
    $q->execute([$merchantId]);$rewardTypes=$q->fetchAll()?:[];
    if(function_exists('crm_v180_contacts_for_owner'))$contacts=crm_v180_contacts_for_owner($pdo,(int)$merchant['owner_user_id'],500);
    $q=$pdo->prepare("SELECT * FROM merchant_claim_codes WHERE merchant_id=? ORDER BY updated_at DESC,id DESC LIMIT 50");$q->execute([$merchantId]);$claimCodes=$q->fetchAll()?:[];
    $q=$pdo->prepare("SELECT rc.*,rp.name reward_name,c.name campaign_name,cc.name customer_name
      FROM reward_claims rc INNER JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id
      INNER JOIN reward_products rp ON rp.id=ri.reward_product_id INNER JOIN campaigns c ON c.id=rc.campaign_id
      INNER JOIN crm_contacts cc ON cc.id=ri.recipient_contact_id WHERE rc.merchant_id=? ORDER BY rc.id DESC LIMIT 50");
    $q->execute([$merchantId]);$recentClaims=$q->fetchAll()?:[];
    if($canAnalytics)$metrics=campaigns_rewards_reward_metrics_v110($pdo,$merchantId,$uid);
}

$editRewardId=max(0,(int)($_GET['edit_reward']??0));$editReward=null;$editRewardCampaignIds=[];
foreach($rewardProducts as $row)if((int)$row['id']===$editRewardId){$editReward=$row;break;}
if($editReward)$editRewardCampaignIds=campaigns_rewards_reward_campaign_ids_v110($pdo,(int)$editReward['id']);
$editSettings=$editReward?json_decode((string)($editReward['settings_json']??''),true):[];if(!is_array($editSettings))$editSettings=[];

$onceSecret=null;$onceClaimCode=null;
if(session_status()===PHP_SESSION_ACTIVE){
    $onceSecret=$_SESSION['reward_workspace_secret_once']??null;unset($_SESSION['reward_workspace_secret_once']);
    $onceClaimCode=$_SESSION['reward_workspace_claim_code_once']??null;unset($_SESSION['reward_workspace_claim_code_once']);
}
$notice=(string)(flash('notice')??'');$error=(string)(flash('error')??'');

$memberHeaderUser=$user;
$memberHeaderTitle='Rewards';
$memberHeaderSubtitle='Reward Products, inventory, issuance, claiming and redemption operations';
$headerActions=['<a class="cr-btn" href="'.e(url('/campaigns.php'.($merchantId?'?merchant='.$merchantId:''))).'">Campaigns</a>'];
if($canClaim)$headerActions[]='<a class="cr-btn primary" href="'.e(url('/campaign-claim.php?merchant='.$merchantId)).'">Claim Terminal</a>';
$memberHeaderActions=implode(' ',$headerActions);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8">
<title>VP3 | Rewards</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=110')) ?>"></head>
<body class="cr-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='rewards';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main cr-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="cr-wrap">
<?php if($notice!==''):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error!==''):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if($onceSecret&&!empty($onceSecret['credential'])):?><div class="cr-notice success"><strong>Reward Credential:</strong> <code><?= e((string)$onceSecret['credential']) ?></code> · <?= e((string)$onceSecret['contact_name']) ?>. Copy it now; the certificate holder can also open it from Agent Chat.</div><?php endif;?>
<?php if($onceClaimCode&&((int)($onceClaimCode['merchant_id']??0)===$merchantId)):?><div class="cr-notice success"><strong><?= e((string)$onceClaimCode['display_name']) ?>:</strong> <code><?= e((string)$onceClaimCode['code']) ?></code> — copy this now. Only its hash is stored.</div><?php endif;?>

<?php if(!$merchant):?>
<section class="cr-empty"><h2>No Merchant workspace yet</h2><p>Create a Merchant from Campaigns before managing Reward Products.</p><a class="cr-btn primary" href="<?= e(url('/campaigns.php?new_merchant=1#new-merchant')) ?>">Create Merchant</a></section>
<?php else:?>
<section class="cr-toolbar"><div><strong>Rewards workspace</strong><span>Reusable Reward Products are separate from Campaigns and can be attached to one or more Campaigns.</span></div><form method="get"><select name="merchant" onchange="this.form.submit()"><?php foreach($merchants as $m):?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id']===$merchantId?' selected':'' ?>><?= e((string)$m['name']) ?> · <?= e(ucwords(str_replace('_',' ',(string)$m['access_role']))) ?></option><?php endforeach;?></select></form></section>

<?php if($canAnalytics):?><section class="cr-metrics cr-metrics-five">
<article><span>Reward Products</span><strong><?= number_format((int)$metrics['products']) ?></strong></article>
<article><span>Active Certificates</span><strong><?= number_format((int)$metrics['active']) ?></strong></article>
<article><span>Issued</span><strong><?= number_format((int)$metrics['issued']) ?></strong></article>
<article><span>Sent</span><strong><?= number_format((int)$metrics['sent']) ?></strong></article>
<article><span>Claimed</span><strong><?= number_format((int)$metrics['claimed']) ?></strong></article>
</section><?php endif;?>

<section class="cr-card"><header><div><span>Catalog</span><h2>Reward Products</h2></div><?php if($canManage):?><a class="cr-btn primary" href="<?= e(url('/rewards.php?merchant='.$merchantId.'&edit_reward=0#reward-editor')) ?>">+ Reward Product</a><?php endif;?></header>
<div class="cr-campaign-grid"><?php foreach($rewardProducts as $rp):?>
<article class="cr-campaign"><div class="cr-status <?= !empty($rp['is_active'])?'active':'ended' ?>"><?= !empty($rp['is_active'])?'Active':'Inactive' ?></div>
<h3><?= e((string)$rp['name']) ?></h3><p><?= e((string)$rp['reward_type_name']) ?><?= trim((string)$rp['description'])!==''?' · '.e(mb_strimwidth((string)$rp['description'],0,90,'…')):'' ?></p>
<small><?= (string)$rp['inventory_mode']==='tracked'?'Inventory '.number_format((int)$rp['inventory_on_hand']).' · Reserved '.number_format((int)$rp['inventory_reserved']):'Inventory untracked' ?> · <?= number_format((int)$rp['campaign_count']) ?> Campaign<?= (int)$rp['campaign_count']===1?'':'s' ?><?= !empty($rp['transferable'])||!empty($rp['regiftable'])?' · Sendable':'' ?></small>
<div class="cr-actions"><?php if($canManage):?><a href="<?= e(url('/rewards.php?merchant='.$merchantId.'&edit_reward='.(int)$rp['id'].'#reward-editor')) ?>">Edit</a><?php endif;?></div></article>
<?php endforeach;?><?php if(!$rewardProducts):?><div class="cr-empty"><h3>No Reward Products</h3><p>Create reusable certificates, discounts, products, credits or access rewards here.</p></div><?php endif;?></div>

<?php if($canManage):?><form method="post" class="cr-form cr-editor" id="reward-editor"><?= csrf_field() ?><input type="hidden" name="action" value="reward_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="reward_id" value="<?= (int)($editReward['id']??0) ?>">
<h3><?= $editReward?'Edit':'Create' ?> Reward Product</h3>
<div class="cr-form-grid">
<label>Name<input name="name" required maxlength="190" value="<?= e((string)($editReward['name']??'')) ?>"></label>
<label>Reward Type<select name="reward_type"><?php foreach($rewardTypes as $type):?><option value="<?= e((string)$type['type_key']) ?>"<?= ($editReward['reward_type_key']??'free_product')===$type['type_key']?' selected':'' ?>><?= e((string)$type['name']) ?></option><?php endforeach;?></select></label>
<label>Value label<input name="value_label" placeholder="Free pizza, 25% off, $20 credit" value="<?= e((string)($editSettings['value_label']??'')) ?>"></label>
<label>SKU<input name="sku" value="<?= e((string)($editReward['sku']??'')) ?>"></label>
<label>Retail value (cents)<input name="retail_value_minor" type="number" min="0" value="<?= $editReward['retail_value_minor']!==null?e((string)$editReward['retail_value_minor']):'' ?>"></label>
<label>Internal cost (cents)<input name="internal_cost_minor" type="number" min="0" value="<?= $editReward['internal_cost_minor']!==null?e((string)$editReward['internal_cost_minor']):'' ?>"></label>
<label>Currency<input name="currency" maxlength="3" value="<?= e((string)($editReward['currency']??$merchant['currency'])) ?>"></label>
<label>Claim limit per contact<input name="claim_limit" type="number" min="1" value="<?= (int)($editReward['claim_limit']??1) ?>"></label>
<label>Expiration days<input name="expiration_days" type="number" min="1" value="<?= e((string)($editReward['expiration_days']??'')) ?>"></label>
<label>Fulfillment<select name="fulfillment_type"><?php foreach(['merchant'=>'Merchant','digital'=>'Digital','shipping'=>'Shipping','pickup'=>'Pickup','custom'=>'Custom'] as $key=>$label):?><option value="<?= e($key) ?>"<?= ($editReward['fulfillment_type']??'merchant')===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Inventory<select name="inventory_mode"><option value="none"<?= ($editReward['inventory_mode']??'none')==='none'?' selected':'' ?>>Untracked</option><option value="tracked"<?= ($editReward['inventory_mode']??'')==='tracked'?' selected':'' ?>>Tracked</option></select></label>
<label>On hand<input name="inventory_on_hand" type="number" min="0" value="<?= (int)($editReward['inventory_on_hand']??0) ?>"></label>
</div>
<label>Description<textarea name="description"><?= e((string)($editReward['description']??'')) ?></textarea></label>
<label>Terms<textarea name="terms"><?= e((string)($editReward['terms']??'')) ?></textarea></label>
<div class="cr-check-grid">
<label class="cr-check"><input type="checkbox" name="transferable" value="1"<?= !isset($editReward['transferable'])||!empty($editReward['transferable'])?' checked':'' ?>> Sendable to another CRM Contact</label>
<label class="cr-check"><input type="checkbox" name="regiftable" value="1"<?= !empty($editReward['regiftable'])?' checked':'' ?>> Regiftable</label>
<label class="cr-check"><input type="checkbox" name="pickup_enabled" value="1"<?= !isset($editReward['pickup_enabled'])||!empty($editReward['pickup_enabled'])?' checked':'' ?>> Pickup</label>
<label class="cr-check"><input type="checkbox" name="shipping_enabled" value="1"<?= !empty($editReward['shipping_enabled'])?' checked':'' ?>> Shipping</label>
<label class="cr-check"><input type="checkbox" name="digital_enabled" value="1"<?= !empty($editReward['digital_enabled'])?' checked':'' ?>> Digital</label>
<label class="cr-check"><input type="checkbox" name="is_active" value="1"<?= !isset($editReward['is_active'])||!empty($editReward['is_active'])?' checked':'' ?>> Active</label>
</div>
<?php if($canCampaignEdit):?><fieldset class="cr-fieldset"><legend>Campaign attachments</legend><div class="cr-check-grid"><?php foreach($campaigns as $campaign):?><label class="cr-check"><input type="checkbox" name="campaign_ids[]" value="<?= (int)$campaign['id'] ?>"<?= in_array((int)$campaign['id'],$editRewardCampaignIds,true)?' checked':'' ?>> <?= e((string)$campaign['name']) ?> · <?= e(ucfirst((string)$campaign['status'])) ?></label><?php endforeach;?></div></fieldset><?php endif;?>
<button class="cr-btn primary">Save Reward Product</button></form><?php endif;?>
</section>

<div class="cr-grid">
<section class="cr-card"><header><div><span>Issue</span><h2>Send a new certificate</h2></div></header>
<?php if($canIssue):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="reward_issue"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="request_id" value="<?= e(bin2hex(random_bytes(12))) ?>">
<label>Campaign<select name="campaign_id" required><option value="">Choose Campaign</option><?php foreach($campaigns as $campaign):?><option value="<?= (int)$campaign['id'] ?>"><?= e((string)$campaign['name']) ?> · <?= e(ucfirst((string)$campaign['status'])) ?></option><?php endforeach;?></select></label>
<label>Reward Product<select name="reward_product_id" required><option value="">Choose Reward</option><?php foreach($rewardProducts as $rp):if(empty($rp['is_active']))continue;?><option value="<?= (int)$rp['id'] ?>"><?= e((string)$rp['name']) ?></option><?php endforeach;?></select></label>
<label>CRM Contact<select name="contact_id" required><option value="">Choose Contact</option><?php foreach($contacts as $contact):?><option value="<?= (int)$contact['id'] ?>"><?= e((string)$contact['name']) ?> · <?= e((string)$contact['email']) ?></option><?php endforeach;?></select></label>
<button class="cr-btn primary">Issue Reward</button></form><?php else:?><p>You do not have Reward issuance access.</p><?php endif;?></section>

<section class="cr-card"><header><div><span>Customer service</span><h2>Make Good</h2></div></header>
<?php if($canIssue):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="make_good"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<label>CRM Contact<select name="contact_id" required><option value="">Choose Contact</option><?php foreach($contacts as $contact):?><option value="<?= (int)$contact['id'] ?>"><?= e((string)$contact['name']) ?> · <?= e((string)$contact['email']) ?></option><?php endforeach;?></select></label>
<label>Reward Product<select name="reward_product_id" required><option value="">Choose Reward</option><?php foreach($rewardProducts as $rp):if(empty($rp['is_active']))continue;?><option value="<?= (int)$rp['id'] ?>"><?= e((string)$rp['name']) ?></option><?php endforeach;?></select></label>
<label>Reason code<input name="reason_code" value="service_recovery"></label><label>Summary<input name="summary" value="Make Good"></label><label>Internal notes<textarea name="internal_notes"></textarea></label>
<button class="cr-btn primary">Issue Make Good Reward</button></form><?php else:?><p>You do not have Reward issuance access.</p><?php endif;?></section>
</div>

<div class="cr-grid">
<section class="cr-card"><header><div><span>Claim security</span><h2>Merchant Claim Codes</h2></div><?php if($canClaim):?><a class="cr-btn primary" href="<?= e(url('/campaign-claim.php?merchant='.$merchantId)) ?>">Claim Terminal</a><?php endif;?></header>
<div class="cr-list"><?php foreach($claimCodes as $code):?><article><div><strong><?= e((string)$code['display_name']) ?></strong><small>••••<?= e((string)$code['code_last4']) ?> · <?= e(ucfirst((string)$code['status'])) ?><?= !empty($code['last_used_at'])?' · Last used '.e(date('M j',strtotime((string)$code['last_used_at']))):'' ?></small></div></article><?php endforeach;?><?php if(!$claimCodes):?><p>No Merchant Claim Codes yet.</p><?php endif;?></div>
<?php if($canClaimCodes):?><form method="post" class="cr-form cr-subform"><?= csrf_field() ?><input type="hidden" name="action" value="claim_code_create"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<h3>Create Claim Code</h3><label>Name<input name="display_name" required placeholder="Front register"></label>
<div class="cr-form-grid"><label>Location<select name="location_id"><option value="">All Locations</option><?php foreach($locations as $location):?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach;?></select></label><label>Device label<input name="device_label" placeholder="POS 1"></label><label>Daily claim limit<input name="daily_claim_limit" type="number" min="1"></label><label>Total claim limit<input name="total_claim_limit" type="number" min="1"></label><label>Maximum value (cents)<input name="max_value_minor" type="number" min="0"></label></div>
<fieldset class="cr-fieldset"><legend>Campaign restrictions</legend><div class="cr-check-grid"><?php foreach($campaigns as $campaign):?><label class="cr-check"><input type="checkbox" name="campaign_ids[]" value="<?= (int)$campaign['id'] ?>"> <?= e((string)$campaign['name']) ?></label><?php endforeach;?></div></fieldset>
<button class="cr-btn">Generate Claim Code</button></form><?php endif;?></section>

<section class="cr-card"><header><div><span>Audit</span><h2>Recent Claims</h2></div><?php if($canAnalytics):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reconcile"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><button class="cr-btn">Run reconciliation</button></form><?php endif;?></header>
<div class="cr-list"><?php foreach($recentClaims as $claim):?><article><div><strong><?= e((string)$claim['reward_name']) ?></strong><small><?= e((string)$claim['campaign_name']) ?> · <?= e((string)$claim['customer_name']) ?> · <?= e(ucfirst((string)$claim['status'])) ?><?php if(!empty($claim['claimed_at'])):?> · <?= e(date('M j, Y g:i A',strtotime((string)$claim['claimed_at']))) ?> UTC<?php endif;?></small></div></article><?php endforeach;?><?php if(!$recentClaims):?><p>No Claims yet.</p><?php endif;?></div></section>
</div>
<?php endif;?>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
