<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

$user=current_user();$pdo=db();
if(!$user||!$pdo){http_response_code(503);exit('Campaigns & Rewards is unavailable.');}
if(!campaigns_rewards_schema_ready_v100($pdo)){http_response_code(503);exit('Campaigns & Rewards needs the latest VP3 database upgrade.');}
if(!campaigns_rewards_user_has_access_v100($pdo,$user)){flash('error','Enable Campaigns & Rewards or ask a merchant owner for access.');redirect(url('/plugins.php'));}

$uid=(int)$user['id'];
$merchantId=max(0,(int)($_REQUEST['merchant']??$_REQUEST['merchant_id']??0));
$redirectMerchant=static function(int $id,string $suffix=''): never{
    redirect(url('/campaigns.php'.($id>0?'?merchant='.$id:'').$suffix));
};

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');$redirectMerchant($merchantId);}
    $action=(string)($_POST['action']??'');
    try{
        if($action==='merchant_create'){
            $merchant=campaigns_rewards_create_merchant_v100($pdo,$user,$_POST);
            flash('notice','Merchant account created.');
            $redirectMerchant((int)$merchant['id']);
        }
        if($merchantId<1)throw new RuntimeException('Choose a merchant account.');
        if($action==='merchant_update'){
            campaigns_rewards_update_merchant_v100($pdo,$merchantId,$uid,$_POST);
            flash('notice','Merchant account updated.');
        }elseif($action==='location_save'){
            campaigns_rewards_save_location_v100($pdo,$merchantId,$uid,$_POST,max(0,(int)($_POST['location_id']??0)));
            flash('notice','Merchant location saved.');
        }elseif($action==='campaign_save'){
            $campaign=campaigns_rewards_save_campaign_v100($pdo,$merchantId,$uid,$_POST,max(0,(int)($_POST['campaign_id']??0)));
            flash('notice','Campaign saved.');
            $redirectMerchant($merchantId,'&edit_campaign='.(int)$campaign['id'].'#campaign-editor');
        }elseif($action==='campaign_status'){
            campaigns_rewards_set_campaign_status_v100($pdo,max(0,(int)($_POST['campaign_id']??0)),$uid,(string)($_POST['status']??'draft'));
            flash('notice','Campaign status updated.');
        }elseif($action==='reward_save'){
            campaigns_rewards_save_reward_v100($pdo,max(0,(int)($_POST['campaign_id']??0)),$uid,$_POST,max(0,(int)($_POST['reward_id']??0)));
            flash('notice','Reward saved.');
        }elseif($action==='claim_validate'){
            campaigns_rewards_validate_claim_v100($pdo,(string)($_POST['claim_code']??''),$uid);
            flash('notice','Claim validated.');
        }elseif($action==='claim_redeem'){
            campaigns_rewards_redeem_claim_v100($pdo,(string)($_POST['claim_code']??''),$uid);
            flash('notice','Claim redeemed and conversion recorded.');
        }elseif($action==='merchant_member_save'){
            if(!campaigns_rewards_can_own_merchant_v100($pdo,$merchantId,$uid))throw new RuntimeException('Merchant owner access is required.');
            $email=strtolower(trim((string)($_POST['member_email']??'')));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter the VP3 email address for the merchant member.');
            $stmt=$pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1');$stmt->execute([$email]);$memberUserId=(int)$stmt->fetchColumn();
            if($memberUserId<1)throw new RuntimeException('That email does not belong to an active VP3 user.');
            campaigns_rewards_set_member_role_v100($pdo,$merchantId,$uid,$memberUserId,(string)($_POST['member_role']??'member'));
            flash('notice','Merchant member access updated.');
        }elseif(!in_array($action,['merchant_update','location_save','campaign_save','campaign_status','reward_save','claim_validate','claim_redeem','merchant_member_save'],true)){
            throw new RuntimeException('Unknown Campaigns & Rewards action.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    $redirectMerchant($merchantId);
}

$merchants=campaigns_rewards_accessible_merchants_v100($pdo,$user);
$merchant=null;
if($merchantId>0)foreach($merchants as $candidate)if((int)$candidate['id']===$merchantId){$merchant=$candidate;break;}
if(!$merchant&&$merchants){$merchant=$merchants[0];$merchantId=(int)$merchant['id'];}
$canCreate=campaigns_rewards_enabled_v100($user,$pdo);
$canManage=$merchant?campaigns_rewards_can_manage_merchant_v100($pdo,$merchantId,$uid):false;
$canOwn=$merchant?campaigns_rewards_can_own_merchant_v100($pdo,$merchantId,$uid):false;

$locations=$merchant?campaigns_rewards_locations_v100($pdo,$merchantId):[];
$campaigns=$merchant?campaigns_rewards_campaigns_v100($pdo,$merchantId):[];
$members=$merchant?campaigns_rewards_merchant_members_v100($pdo,$merchantId):[];
$report=$merchant&&$canManage?campaigns_rewards_reporting_v100($pdo,$merchantId,$uid):['active_campaigns'=>0,'landing_views'=>0,'customers'=>0,'claims_issued'=>0,'claims_redeemed'=>0,'redemption_rate'=>0];

$editCampaignId=max(0,(int)($_GET['edit_campaign']??0));$editCampaign=null;
foreach($campaigns as $row)if((int)$row['id']===$editCampaignId)$editCampaign=$row;
$editLocationId=max(0,(int)($_GET['edit_location']??0));$editLocation=null;
foreach($locations as $row)if((int)$row['id']===$editLocationId)$editLocation=$row;
$rewardCampaignId=max(0,(int)($_GET['reward_campaign']??$editCampaignId));$rewardRows=$rewardCampaignId>0?campaigns_rewards_rewards_v100($pdo,$rewardCampaignId):[];
$editRewardId=max(0,(int)($_GET['edit_reward']??0));$editReward=null;
foreach($rewardRows as $row)if((int)$row['id']===$editRewardId)$editReward=$row;

$recentClaims=[];
if($merchant){
    $stmt=$pdo->prepare("SELECT cl.claim_code,cl.status,cl.issued_at,cl.validated_at,cl.redeemed_at,r.title reward_title,c.title campaign_title,cu.name customer_name
      FROM campaign_reward_claims_v100 cl
      INNER JOIN campaign_rewards_v100 r ON r.id=cl.reward_id
      INNER JOIN campaigns_v100 c ON c.id=cl.campaign_id
      INNER JOIN campaign_customers_v100 cu ON cu.id=cl.customer_id
      WHERE cl.merchant_account_id=? ORDER BY cl.id DESC LIMIT 30");
    $stmt->execute([$merchantId]);$recentClaims=$stmt->fetchAll()?:[];
}

$notice=flash('notice');$error=flash('error');
$memberHeaderUser=$user;$memberHeaderTitle='Campaigns & Rewards';$memberHeaderSubtitle='Merchant accounts, campaigns, rewards, claims and reporting';$memberHeaderActions=$canCreate?'<a class="cr-btn" href="'.e(url('/campaigns.php?new_merchant=1#merchant-editor')).'">+ Merchant</a>':'';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Campaigns & Rewards</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=100')) ?>"></head>
<body class="cr-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='campaigns';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main cr-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="cr-wrap">
<?php if($notice):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>

<section class="cr-toolbar"><div><strong>Merchant workspace</strong><span>A merchant is a business entity, separate from your VP3 login.</span></div><?php if($merchants):?><form method="get"><select name="merchant" onchange="this.form.submit()"><?php foreach($merchants as $m):?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id']===$merchantId?' selected':'' ?>><?= e((string)$m['name']) ?> · <?= e(ucfirst((string)$m['access_role'])) ?></option><?php endforeach;?></select></form><?php endif;?></section>

<?php if(!$merchant):?>
<section class="cr-empty"><h2>Create your first merchant account</h2><p>Merchant accounts own locations, campaigns, rewards, customers and reporting while your VP3 login remains your personal identity.</p><?php if($canCreate):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_create"><label>Name<input name="name" required maxlength="190"></label><label>Public slug<input name="slug" maxlength="100" placeholder="optional"></label><label>Contact email<input name="contact_email" type="email" value="<?= e((string)$user['email']) ?>"></label><button class="cr-btn primary">Create merchant</button></form><?php else:?><a class="cr-btn primary" href="<?= e(url('/plugins.php')) ?>">Enable plugin</a><?php endif;?></section>
<?php else:?>

<section class="cr-metrics"><article><span>Active campaigns</span><strong><?= number_format((int)$report['active_campaigns']) ?></strong></article><article><span>Landing views</span><strong><?= number_format((int)$report['landing_views']) ?></strong></article><article><span>Customers</span><strong><?= number_format((int)$report['customers']) ?></strong></article><article><span>Claims</span><strong><?= number_format((int)$report['claims_redeemed']) ?> / <?= number_format((int)$report['claims_issued']) ?></strong><small><?= e(number_format((float)$report['redemption_rate'],1)) ?>% redeemed</small></article></section>

<div class="cr-grid">
<section class="cr-card" id="merchant-editor"><header><div><span>Business entity</span><h2><?= e((string)$merchant['name']) ?></h2></div><strong><?= e(ucfirst((string)$merchant['access_role'])) ?></strong></header>
<p><?= e((string)($merchant['description']??'')) ?></p><?php if($canManage):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_update"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><label>Name<input name="name" value="<?= e((string)$merchant['name']) ?>" required></label><label>Public slug<input name="slug" value="<?= e((string)$merchant['slug']) ?>" required></label><label>Description<textarea name="description"><?= e((string)($merchant['description']??'')) ?></textarea></label><div class="cr-form-grid"><label>Website<input name="website_url" value="<?= e((string)$merchant['website_url']) ?>"></label><label>Email<input name="contact_email" type="email" value="<?= e((string)$merchant['contact_email']) ?>"></label><label>Phone<input name="contact_phone" value="<?= e((string)$merchant['contact_phone']) ?>"></label><label>Profile user ID<input name="profile_user_id" type="number" min="1" value="<?= (int)($merchant['profile_user_id']??0) ?>"></label></div><button class="cr-btn">Save merchant</button></form><?php endif;?></section>

<section class="cr-card"><header><div><span>Locations</span><h2>Merchant locations</h2></div><?php if($canManage):?><a class="cr-btn" href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_location=0#location-editor')) ?>">+ Add</a><?php endif;?></header>
<div class="cr-list"><?php foreach($locations as $location):?><article><div><strong><?= e((string)$location['name']) ?><?= !empty($location['is_primary'])?' · Primary':'' ?></strong><small><?= e(trim((string)$location['city'].', '.(string)$location['region'],', ')) ?></small></div><?php if($canManage):?><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_location='.(int)$location['id'].'#location-editor')) ?>">Edit</a><?php endif;?></article><?php endforeach;?><?php if(!$locations):?><p>No locations yet.</p><?php endif;?></div>
<?php if($canManage):?><form method="post" class="cr-form cr-subform" id="location-editor"><?= csrf_field() ?><input type="hidden" name="action" value="location_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="location_id" value="<?= (int)($editLocation['id']??0) ?>"><h3><?= $editLocation?'Edit':'Add' ?> location</h3><label>Name<input name="name" required value="<?= e((string)($editLocation['name']??'')) ?>"></label><div class="cr-form-grid"><label>Address<input name="address_line1" value="<?= e((string)($editLocation['address_line1']??'')) ?>"></label><label>City<input name="city" value="<?= e((string)($editLocation['city']??'')) ?>"></label><label>State / region<input name="region" value="<?= e((string)($editLocation['region']??'')) ?>"></label><label>Postal code<input name="postal_code" value="<?= e((string)($editLocation['postal_code']??'')) ?>"></label></div><label class="cr-check"><input type="checkbox" name="is_primary" value="1"<?= !empty($editLocation['is_primary'])?' checked':'' ?>> Primary location</label><button class="cr-btn">Save location</button></form><?php endif;?></section>
</div>

<section class="cr-card" id="campaign-editor"><header><div><span>Campaigns</span><h2>Campaign landing pages</h2></div><?php if($canManage):?><a class="cr-btn primary" href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_campaign=0#campaign-editor')) ?>">+ Campaign</a><?php endif;?></header>
<div class="cr-campaign-grid"><?php foreach($campaigns as $campaign):?><article class="cr-campaign"><div class="cr-status <?= e((string)$campaign['status']) ?>"><?= e(ucfirst((string)$campaign['status'])) ?></div><h3><?= e((string)$campaign['title']) ?></h3><p><?= e((string)$campaign['subtitle']) ?></p><small><?= e((string)($campaign['location_name']??'All locations')) ?></small><div class="cr-actions"><a href="<?= e(campaigns_rewards_campaign_url_v100((string)$campaign['slug'])) ?>" target="_blank">Landing page ↗</a><?php if($canManage):?><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_campaign='.(int)$campaign['id'].'#campaign-editor')) ?>">Edit</a><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&reward_campaign='.(int)$campaign['id'].'#rewards')) ?>">Rewards</a><?php endif;?></div><?php if($canManage):?><form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_status"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><select name="status"><option value="draft"<?= $campaign['status']==='draft'?' selected':'' ?>>Draft</option><option value="active"<?= $campaign['status']==='active'?' selected':'' ?>>Active</option><option value="paused"<?= $campaign['status']==='paused'?' selected':'' ?>>Paused</option><option value="ended"<?= $campaign['status']==='ended'?' selected':'' ?>>Ended</option></select><button>Update</button></form><?php endif;?></article><?php endforeach;?></div>
<?php if($canManage):?><form method="post" class="cr-form cr-editor"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="campaign_id" value="<?= (int)($editCampaign['id']??0) ?>"><h3><?= $editCampaign?'Edit campaign':'Create campaign' ?></h3><div class="cr-form-grid"><label>Title<input name="title" required value="<?= e((string)($editCampaign['title']??'')) ?>"></label><label>Public slug<input name="slug" value="<?= e((string)($editCampaign['slug']??'')) ?>"></label><label>Subtitle<input name="subtitle" value="<?= e((string)($editCampaign['subtitle']??'')) ?>"></label><label>CTA label<input name="cta_label" value="<?= e((string)($editCampaign['cta_label']??'Claim reward')) ?>"></label><label>Location<select name="location_id"><option value="">All locations</option><?php foreach($locations as $location):?><option value="<?= (int)$location['id'] ?>"<?= (int)($editCampaign['location_id']??0)===(int)$location['id']?' selected':'' ?>><?= e((string)$location['name']) ?></option><?php endforeach;?></select></label><label>Starts<input name="starts_at" type="datetime-local" value="<?= !empty($editCampaign['starts_at'])?e(date('Y-m-d\TH:i',strtotime((string)$editCampaign['starts_at']))):'' ?>"></label><label>Ends<input name="ends_at" type="datetime-local" value="<?= !empty($editCampaign['ends_at'])?e(date('Y-m-d\TH:i',strtotime((string)$editCampaign['ends_at']))):'' ?>"></label></div><label>Description<textarea name="description"><?= e((string)($editCampaign['description']??'')) ?></textarea></label><label>Terms<textarea name="terms"><?= e((string)($editCampaign['terms']??'')) ?></textarea></label><label class="cr-check"><input type="checkbox" name="profile_visible" value="1"<?= !isset($editCampaign['profile_visible'])||!empty($editCampaign['profile_visible'])?' checked':'' ?>> Show active campaign on linked VP3 Profile</label><button class="cr-btn primary">Save campaign</button></form><?php endif;?></section>

<?php if($rewardCampaignId>0&&$canManage): $rewardCampaign=campaigns_rewards_campaign_v100($pdo,$rewardCampaignId); if($rewardCampaign&&(int)$rewardCampaign['merchant_account_id']===$merchantId):?>
<section class="cr-card" id="rewards"><header><div><span>Rewards</span><h2><?= e((string)$rewardCampaign['title']) ?></h2></div></header><div class="cr-list"><?php foreach($rewardRows as $reward):?><article><div><strong><?= e((string)$reward['title']) ?></strong><small><?= e((string)$reward['value_label']) ?> · <?= e(ucfirst((string)$reward['status'])) ?> · Limit <?= (int)$reward['inventory_limit']>0?number_format((int)$reward['inventory_limit']):'Unlimited' ?></small></div><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&reward_campaign='.$rewardCampaignId.'&edit_reward='.(int)$reward['id'].'#rewards')) ?>">Edit</a></article><?php endforeach;?></div><form method="post" class="cr-form cr-subform"><?= csrf_field() ?><input type="hidden" name="action" value="reward_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="campaign_id" value="<?= $rewardCampaignId ?>"><input type="hidden" name="reward_id" value="<?= (int)($editReward['id']??0) ?>"><h3><?= $editReward?'Edit':'Add' ?> reward</h3><div class="cr-form-grid"><label>Title<input name="title" required value="<?= e((string)($editReward['title']??'')) ?>"></label><label>Value label<input name="value_label" placeholder="25% off, Free pizza, VIP access" value="<?= e((string)($editReward['value_label']??'')) ?>"></label><label>Type<select name="reward_type"><?php foreach(['offer','gift','discount','access','recognition'] as $type):?><option value="<?= e($type) ?>"<?= ($editReward['reward_type']??'offer')===$type?' selected':'' ?>><?= e(ucfirst($type)) ?></option><?php endforeach;?></select></label><label>Inventory limit<input name="inventory_limit" type="number" min="0" value="<?= (int)($editReward['inventory_limit']??0) ?>"></label><label>Per customer<input name="per_customer_limit" type="number" min="1" max="25" value="<?= (int)($editReward['per_customer_limit']??1) ?>"></label><label>Status<select name="status"><option value="active"<?= ($editReward['status']??'active')==='active'?' selected':'' ?>>Active</option><option value="inactive"<?= ($editReward['status']??'')==='inactive'?' selected':'' ?>>Inactive</option></select></label></div><label>Description<textarea name="description"><?= e((string)($editReward['description']??'')) ?></textarea></label><button class="cr-btn">Save reward</button></form></section>
<?php endif; endif; ?>

<div class="cr-grid">
<section class="cr-card"><header><div><span>Merchant access</span><h2>Owners &amp; team</h2></div></header><div class="cr-list"><?php foreach($members as $member):?><article><div><strong><?= e((string)$member['display_name']) ?></strong><small><?= e((string)$member['email']) ?> · <?= e(ucfirst((string)($member['effective_role']??$member['member_role']))) ?><?= !empty($member['team_scope_active'])?' · Merchant Team':'' ?></small></div></article><?php endforeach;?></div><?php if($canOwn):?><form method="post" class="cr-form cr-subform"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_member_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><h3>Add or update VP3 member</h3><label>Email<input name="member_email" type="email" required></label><label>Merchant role<select name="member_role"><?php foreach(campaigns_rewards_merchant_roles_v100() as $role=>$label):?><option value="<?= e($role) ?>"><?= e($label) ?></option><?php endforeach;?></select></label><button class="cr-btn">Save member</button></form><?php endif;?><p class="cr-help">Merchant roles are separate from the canonical VP3 Team role. Team members assigned as Merchant Team are linked here automatically.</p></section>

<section class="cr-card"><header><div><span>Claims</span><h2>Recent reward activity</h2></div></header><div class="cr-list claims"><?php foreach($recentClaims as $claim):?><article><div><strong><?= e((string)$claim['claim_code']) ?> · <?= e((string)$claim['reward_title']) ?></strong><small><?= e((string)$claim['campaign_title']) ?> · <?= e((string)$claim['customer_name']) ?> · <?= e(ucfirst((string)$claim['status'])) ?></small></div><div class="cr-actions"><a href="<?= e(campaigns_rewards_claim_url_v100((string)$claim['claim_code'])) ?>" target="_blank">Verify ↗</a><?php if($canManage&&$claim['status']==='issued'):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="claim_validate"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="claim_code" value="<?= e((string)$claim['claim_code']) ?>"><button>Validate</button></form><?php endif;?><?php if($canManage&&in_array($claim['status'],['issued','validated'],true)):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="claim_redeem"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="claim_code" value="<?= e((string)$claim['claim_code']) ?>"><button>Redeem</button></form><?php endif;?></div></article><?php endforeach;?><?php if(!$recentClaims):?><p>No claims yet.</p><?php endif;?></div></section>
</div>
<?php endif;?>

<?php if(isset($_GET['new_merchant'])&&$canCreate):?><section class="cr-card" id="merchant-editor"><header><div><span>New merchant</span><h2>Create business entity</h2></div></header><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_create"><label>Name<input name="name" required></label><label>Public slug<input name="slug"></label><label>Description<textarea name="description"></textarea></label><div class="cr-form-grid"><label>Website<input name="website_url"></label><label>Contact email<input name="contact_email" type="email" value="<?= e((string)$user['email']) ?>"></label><label>Phone<input name="contact_phone"></label></div><button class="cr-btn primary">Create merchant</button></form></section><?php endif;?>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
