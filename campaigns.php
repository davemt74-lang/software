<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

$user=current_user();$pdo=db();
if(!$user||!$pdo){http_response_code(503);exit('Campaigns is unavailable.');}
if(!function_exists('campaigns_rewards_platform_schema_ready_v100')||!campaigns_rewards_platform_schema_ready_v100($pdo)){
    http_response_code(503);exit('Campaigns needs the latest VP3 database upgrade.');
}
if(!campaigns_rewards_user_has_access_v100($pdo,$user)){flash('error','Enable Campaigns & Rewards or ask a Merchant Owner for access.');redirect(url('/plugins.php'));}

$uid=(int)$user['id'];$merchantId=max(0,(int)($_REQUEST['merchant']??$_REQUEST['merchant_id']??0));
$redirectMerchant=static function(int $id,string $suffix=''): never{redirect(url('/campaigns.php'.($id>0?'?merchant='.$id:'').$suffix));};

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');$redirectMerchant($merchantId);}
    $action=(string)($_POST['action']??'');
    try{
        if($action==='merchant_create'){
            $merchant=campaigns_rewards_create_merchant_v100($pdo,$user,$_POST);
            flash('notice','Merchant created.');$redirectMerchant((int)$merchant['id']);
        }
        if($merchantId<1)throw new RuntimeException('Choose a Merchant.');
        if($action==='merchant_update'){
            campaigns_rewards_update_merchant_v100($pdo,$merchantId,$uid,$_POST);flash('notice','Merchant updated.');
        }elseif($action==='merchant_status'){
            campaigns_rewards_set_platform_merchant_status_v100($pdo,$merchantId,$uid,(string)($_POST['status']??'active'),(string)($_POST['reason']??''));flash('notice','Merchant lifecycle updated.');
        }elseif($action==='location_save'){
            campaigns_rewards_save_location_v100($pdo,$merchantId,$uid,$_POST,max(0,(int)($_POST['location_id']??0)));flash('notice','Merchant Location saved.');
        }elseif($action==='campaign_save'){
            $campaign=campaigns_rewards_save_campaign_v100($pdo,$merchantId,$uid,$_POST,max(0,(int)($_POST['campaign_id']??0)));
            flash('notice','Campaign saved.');$redirectMerchant($merchantId,'&edit_campaign='.(int)$campaign['id'].'#campaign-editor');
        }elseif($action==='campaign_status'){
            campaigns_rewards_set_campaign_status_v100($pdo,max(0,(int)($_POST['campaign_id']??0)),$uid,(string)($_POST['status']??'draft'));flash('notice','Campaign lifecycle updated.');
        }elseif($action==='campaign_fulfill'){
            $result=campaigns_rewards_issue_enrollment_reward_v118(
                $pdo,$merchantId,max(0,(int)($_POST['campaign_id']??0)),max(0,(int)($_POST['enrollment_id']??0)),
                max(0,(int)($_POST['reward_product_id']??0)),$uid
            );
            if(session_status()===PHP_SESSION_ACTIVE){
                $_SESSION['campaign_fulfillment_once']=[
                    'credential'=>(string)($result['issuance']['credential']??''),
                    'credential_last4'=>(string)($result['issuance']['credential_last4']??''),
                    'campaign'=>(string)($result['campaign']['name']??'Campaign'),
                ];
            }
            flash('notice','Campaign participant fulfilled and Reward issued.');
        }elseif($action==='merchant_member_save'){
            if(!campaigns_rewards_can_own_merchant_v100($pdo,$merchantId,$uid))throw new RuntimeException('Merchant Owner access is required.');
            $email=strtolower(trim((string)($_POST['member_email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter an active VP3 user email.');
            $stmt=$pdo->prepare('SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1');$stmt->execute([$email]);$memberUserId=(int)$stmt->fetchColumn();
            if($memberUserId<1)throw new RuntimeException('That email does not belong to an active VP3 user.');
            campaigns_rewards_set_member_role_v100($pdo,$merchantId,$uid,$memberUserId,(string)($_POST['member_role']??'customer_service'));flash('notice','Merchant role updated.');
        }elseif($action==='merchant_member_remove'){
            if(!campaigns_rewards_can_own_merchant_v100($pdo,$merchantId,$uid))throw new RuntimeException('Merchant Owner access is required.');
            campaigns_rewards_remove_platform_member_v100($pdo,$merchantId,$uid,max(0,(int)($_POST['member_user_id']??0)),(string)($_POST['reason']??''));
            flash('notice','Direct Merchant access removed. Any independent Merchant Team grant remains bounded.');
        }else{
            throw new RuntimeException('Unknown Campaigns action.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    $redirectMerchant($merchantId);
}

$merchants=campaigns_rewards_accessible_merchants_v100($pdo,$user);$merchant=null;
if($merchantId>0)foreach($merchants as $candidate)if((int)$candidate['id']===$merchantId){$merchant=$candidate;break;}
if(!$merchant&&$merchants){$merchant=$merchants[0];$merchantId=(int)$merchant['id'];}
$canCreate=campaigns_rewards_enabled_v100($user,$pdo);
$canManage=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'merchant.manage'):false;
$canOwn=$merchant?campaigns_rewards_can_own_merchant_v100($pdo,$merchantId,$uid):false;
$canLocations=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'locations.manage'):false;
$canCampaignEdit=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'campaigns.edit'):false;
$canCampaignEnrollment=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'campaigns.enrollment.manage'):false;
$canRewardIssue=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'rewards.issue'):false;
$canAnalytics=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'analytics.view'):false;

$locations=$merchant?campaigns_rewards_locations_v100($pdo,$merchantId):[];
$campaigns=$merchant?campaigns_rewards_campaigns_v100($pdo,$merchantId):[];
$members=$merchant?campaigns_rewards_merchant_members_v100($pdo,$merchantId):[];
$report=$merchant&&$canAnalytics?campaigns_rewards_reporting_v100($pdo,$merchantId,$uid):['active_campaigns'=>0,'landing_views'=>0,'customers'=>0,'claims_redeemed'=>0];
$recentEnrollments=$merchant&&function_exists('campaigns_rewards_recent_enrollments_v118')?campaigns_rewards_recent_enrollments_v118($pdo,$merchantId,$uid,40):[];
$campaignTypes=[];$campaignTypesByCategory=[];$rewardProducts=[];$editCampaignRewardIds=[];$campaignRewardOptions=[];
if($merchant){
    $s=$pdo->prepare("SELECT type_key,name,description,field_schema_json,reward_rules_schema_json,landing_schema_json FROM campaign_types WHERE is_active=1 AND (merchant_id=? OR merchant_id IS NULL) ORDER BY is_system DESC,name");
    $s->execute([$merchantId]);$campaignTypes=$s->fetchAll()?:[];
    foreach($campaignTypes as &$typeRow){
        $field=json_decode((string)($typeRow['field_schema_json']??''),true);if(!is_array($field))$field=[];
        $rewardRules=json_decode((string)($typeRow['reward_rules_schema_json']??''),true);if(!is_array($rewardRules))$rewardRules=[];
        $landing=json_decode((string)($typeRow['landing_schema_json']??''),true);if(!is_array($landing))$landing=[];
        $typeRow['category']=(string)($field['category']??'Other');
        $typeRow['reward_timing']=(string)($rewardRules['timing']??'manual');
        $typeRow['requires_reward']=!empty($rewardRules['requires_reward']);
        $typeRow['default_cta']=(string)($landing['cta']??'Continue');
        $campaignTypesByCategory[$typeRow['category']][]=$typeRow;
    }unset($typeRow);
    if(function_exists('campaigns_rewards_reward_products_v110'))$rewardProducts=campaigns_rewards_reward_products_v110($pdo,$merchantId,$uid);
    if(function_exists('campaigns_rewards_campaign_reward_ids_v118')){
        foreach($campaigns as $campaignRow){
            $ids=campaigns_rewards_campaign_reward_ids_v118($pdo,(int)$campaignRow['id']);
            $campaignRewardOptions[(int)$campaignRow['id']]=array_values(array_filter($rewardProducts,static fn(array $rp):bool=>in_array((int)$rp['id'],$ids,true)&&!empty($rp['is_active'])));
        }
    }
}

$editCampaignId=max(0,(int)($_GET['edit_campaign']??0));$editCampaign=null;foreach($campaigns as $row)if((int)$row['id']===$editCampaignId)$editCampaign=$row;
if($editCampaign&&function_exists('campaigns_rewards_campaign_reward_ids_v118'))$editCampaignRewardIds=campaigns_rewards_campaign_reward_ids_v118($pdo,(int)$editCampaign['id']);
$editLocationId=max(0,(int)($_GET['edit_location']??0));$editLocation=null;foreach($locations as $row)if((int)$row['id']===$editLocationId)$editLocation=$row;
$notice=(string)(flash('notice')??'');$error=(string)(flash('error')??'');
$fulfillmentOnce=session_status()===PHP_SESSION_ACTIVE?($_SESSION['campaign_fulfillment_once']??null):null;
if(session_status()===PHP_SESSION_ACTIVE)unset($_SESSION['campaign_fulfillment_once']);

$memberHeaderUser=$user;$memberHeaderTitle='Campaigns';$memberHeaderSubtitle='Merchant identity, locations, Campaign lifecycle, landing pages and Team access';
$actions=['<a class="cr-btn primary" href="'.e(url('/rewards.php'.($merchantId?'?merchant='.$merchantId:''))).'">Rewards</a>'];
if($canCreate)$actions[]='<a class="cr-btn" href="'.e(url('/campaigns.php?new_merchant=1#new-merchant')).'">+ Merchant</a>';
$memberHeaderActions=implode(' ',$actions);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Campaigns</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=118')) ?>"></head>
<body class="cr-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='campaigns';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main cr-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="cr-wrap">
<?php if($notice!==''):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error!==''):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if(is_array($fulfillmentOnce)&&!empty($fulfillmentOnce['credential'])):?><div class="cr-notice success"><strong>Reward Credential:</strong> <code><?= e((string)$fulfillmentOnce['credential']) ?></code> · <?= e((string)$fulfillmentOnce['campaign']) ?>. Copy it now; only its hash is stored.</div><?php endif;?>

<section class="cr-toolbar"><div><strong>Merchant workspace</strong><span>Business identity is separate from your VP3 login; Team scope and direct Merchant roles remain independent.</span></div><?php if($merchants):?><form method="get"><select name="merchant" onchange="this.form.submit()"><?php foreach($merchants as $m):?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id']===$merchantId?' selected':'' ?>><?= e((string)$m['name']) ?> · <?= e(ucwords(str_replace('_',' ',(string)$m['access_role']))) ?></option><?php endforeach;?></select></form><?php endif;?></section>

<?php if(!$merchant):?>
<section class="cr-empty" id="new-merchant"><h2>Create your first Merchant</h2><p>Merchants own business state; your VP3 login remains your personal identity.</p><?php if($canCreate):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_create"><label>Name<input name="name" required maxlength="190"></label><label>Public slug<input name="slug" maxlength="100"></label><label>Description<textarea name="description"></textarea></label><div class="cr-form-grid"><label>Website<input name="website_url"></label><label>Timezone<input name="timezone" value="America/Phoenix"></label><label>Currency<input name="currency" value="USD" maxlength="3"></label></div><label class="cr-check"><input type="checkbox" name="sandbox_mode" value="1"> Sandbox Merchant</label><button class="cr-btn primary">Create Merchant</button></form><?php else:?><a class="cr-btn primary" href="<?= e(url('/plugins.php')) ?>">Enable plugin</a><?php endif;?></section>
<?php else:?>

<?php if($canAnalytics):?><section class="cr-metrics"><article><span>Active Campaigns</span><strong><?= number_format((int)$report['active_campaigns']) ?></strong></article><article><span>Landing views</span><strong><?= number_format((int)$report['landing_views']) ?></strong></article><article><span>Merchant relationships</span><strong><?= number_format((int)$report['customers']) ?></strong></article><article><span>Reward conversions</span><strong><?= number_format((int)$report['claims_redeemed']) ?></strong><small>Claimed certificates attributed to Campaigns</small></article></section><?php endif;?>

<div class="cr-grid">
<section class="cr-card"><header><div><span>Merchant</span><h2><?= e((string)$merchant['name']) ?></h2></div><strong><?= e(ucfirst((string)$merchant['status'])) ?></strong></header><p><?= e((string)($merchant['description']??'')) ?></p>
<?php if($canManage):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_update"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><label>Name<input name="name" required value="<?= e((string)$merchant['name']) ?>"></label><label>Slug<input name="slug" required value="<?= e((string)$merchant['slug']) ?>"></label><label>Description<textarea name="description"><?= e((string)($merchant['description']??'')) ?></textarea></label><div class="cr-form-grid"><label>Website<input name="website_url" value="<?= e((string)($merchant['website_url']??'')) ?>"></label><label>Email<input name="contact_email" type="email" value="<?= e((string)($merchant['contact_email']??'')) ?>"></label><label>Phone<input name="contact_phone" value="<?= e((string)($merchant['contact_phone']??'')) ?>"></label><label>Timezone<input name="timezone" value="<?= e((string)$merchant['timezone']) ?>"></label><label>Currency<input name="currency" maxlength="3" value="<?= e((string)$merchant['currency']) ?>"></label></div><label class="cr-check"><input type="checkbox" name="sandbox_mode" value="1"<?= !empty($merchant['sandbox_mode'])?' checked':'' ?>> Sandbox mode</label><button class="cr-btn">Save Merchant</button></form>
<form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_status"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><select name="status"><?php foreach(['active','suspended','archived','closed'] as $st):?><option value="<?= e($st) ?>"<?= $merchant['status']===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select><input name="reason" placeholder="Reason"><button>Update lifecycle</button></form><?php endif;?></section>

<section class="cr-card"><header><div><span>Locations</span><h2>Merchant Locations</h2></div></header><div class="cr-list"><?php foreach($locations as $location):?><article><div><strong><?= e((string)$location['name']) ?><?= !empty($location['is_primary'])?' · Primary':'' ?></strong><small><?= e(trim((string)$location['city'].', '.(string)$location['region'],', ')) ?></small></div><?php if($canLocations):?><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_location='.(int)$location['id'].'#location-editor')) ?>">Edit</a><?php endif;?></article><?php endforeach;?><?php if(!$locations):?><p>No Locations yet.</p><?php endif;?></div>
<?php if($canLocations):?><form method="post" class="cr-form cr-subform" id="location-editor"><?= csrf_field() ?><input type="hidden" name="action" value="location_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="location_id" value="<?= (int)($editLocation['id']??0) ?>"><h3><?= $editLocation?'Edit':'Add' ?> Location</h3><label>Name<input name="name" required value="<?= e((string)($editLocation['name']??'')) ?>"></label><div class="cr-form-grid"><label>Address<input name="address1" value="<?= e((string)($editLocation['address1']??'')) ?>"></label><label>City<input name="city" value="<?= e((string)($editLocation['city']??'')) ?>"></label><label>Region<input name="region" value="<?= e((string)($editLocation['region']??'')) ?>"></label><label>Postal<input name="postal_code" value="<?= e((string)($editLocation['postal_code']??'')) ?>"></label></div><label class="cr-check"><input type="checkbox" name="is_primary" value="1"<?= !empty($editLocation['is_primary'])?' checked':'' ?>> Primary Location</label><label class="cr-check"><input type="checkbox" name="is_active" value="1"<?= !isset($editLocation['is_active'])||!empty($editLocation['is_active'])?' checked':'' ?>> Active</label><button class="cr-btn">Save Location</button></form><?php endif;?></section>
</div>

<section class="cr-card"><header><div><span>Campaigns</span><h2>Campaign lifecycle & landing pages</h2></div><a class="cr-btn" href="<?= e(url('/rewards.php?merchant='.$merchantId)) ?>">Manage Rewards</a></header>
<div class="cr-campaign-grid"><?php foreach($campaigns as $campaign):?><article class="cr-campaign"><div class="cr-status <?= e((string)$campaign['status']) ?>"><?= e(ucfirst((string)$campaign['status'])) ?></div><h3><?= e((string)$campaign['title']) ?></h3><p><?= e((string)$campaign['subtitle']) ?></p><small><?= e(ucwords(str_replace('_',' ',(string)($campaign['campaign_type_key']??'campaign')))) ?> · v<?= (int)$campaign['current_version_no'] ?></small><div class="cr-actions"><a href="<?= e(campaigns_rewards_campaign_url_v100((string)$campaign['slug'])) ?>" target="_blank">Landing page ↗</a><?php if($canCampaignEdit):?><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_campaign='.(int)$campaign['id'].'#campaign-editor')) ?>">Edit</a><a href="<?= e(url('/rewards.php?merchant='.$merchantId.'#reward-editor')) ?>">Rewards</a><?php endif;?></div><?php if($canCampaignEdit):?><form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_status"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><select name="status"><?php foreach(['draft','scheduled','active','paused','completed','archived'] as $st):?><option value="<?= e($st) ?>"<?= $campaign['status']===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select><button>Update</button></form><?php endif;?></article><?php endforeach;?><?php if(!$campaigns):?><div class="cr-empty"><h3>No Campaigns yet</h3><p>Create a Campaign and then attach reusable Reward Products from Rewards.</p></div><?php endif;?></div>
<?php if($canCampaignEdit):?>
<form method="post" class="cr-form cr-editor" id="campaign-editor">
<?= csrf_field() ?>
<input type="hidden" name="action" value="campaign_save">
<input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<input type="hidden" name="campaign_id" value="<?= (int)($editCampaign['id']??0) ?>">
<input type="hidden" name="reward_selection_present" value="1">
<h3><?= $editCampaign?'Edit Campaign':'Create Campaign' ?></h3>

<div class="cr-form-grid">
<label>Campaign Type
<select name="campaign_type" id="campaignTypeSelect">
<?php foreach($campaignTypesByCategory as $category=>$types):?>
<optgroup label="<?= e($category) ?>">
<?php foreach($types as $type):?>
<option value="<?= e((string)$type['type_key']) ?>"
  data-description="<?= e((string)$type['description']) ?>"
  data-reward-timing="<?= e((string)$type['reward_timing']) ?>"
  data-requires-reward="<?= !empty($type['requires_reward'])?'1':'0' ?>"
  data-default-cta="<?= e((string)$type['default_cta']) ?>"
  <?= ($editCampaign['campaign_type_key']??'signup')===$type['type_key']?' selected':'' ?>><?= e((string)$type['name']) ?></option>
<?php endforeach;?>
</optgroup>
<?php endforeach;?>
</select>
<small class="cr-type-help" id="campaignTypeHelp"></small>
</label>
<label>Title<input name="title" required value="<?= e((string)($editCampaign['title']??'')) ?>"></label>
<label>Public slug<input name="slug" value="<?= e((string)($editCampaign['slug']??'')) ?>"></label>
<label>Subtitle<input name="subtitle" value="<?= e((string)($editCampaign['subtitle']??'')) ?>"></label>
<label>CTA label<input name="cta_label" id="campaignCtaInput" value="<?= e((string)($editCampaign['cta_label']??'')) ?>"></label>
<label>Location<select name="location_id"><option value="">All Locations</option><?php foreach($locations as $location):?><option value="<?= (int)$location['id'] ?>"<?= (int)($editCampaign['location_id']??0)===(int)$location['id']?' selected':'' ?>><?= e((string)$location['name']) ?></option><?php endforeach;?></select></label>
<label>Starts<input name="starts_at" type="datetime-local" value="<?= !empty($editCampaign['starts_at'])?e(date('Y-m-d\TH:i',strtotime((string)$editCampaign['starts_at']))):'' ?>"></label>
<label>Ends<input name="ends_at" type="datetime-local" value="<?= !empty($editCampaign['ends_at'])?e(date('Y-m-d\TH:i',strtotime((string)$editCampaign['ends_at']))):'' ?>"></label>
</div>

<label>Objective<input name="objective" value="<?= e((string)($editCampaign['objective']??'')) ?>"></label>
<label>Description<textarea name="description"><?= e((string)($editCampaign['description']??'')) ?></textarea></label>
<label>Terms<textarea name="terms"><?= e((string)($editCampaign['terms']??'')) ?></textarea></label>

<fieldset class="cr-fieldset">
<legend>Campaign Rewards</legend>
<p class="cr-help">Attach the reusable Reward Products this Campaign is allowed to issue. Campaign types that require a Reward cannot be activated until at least one is selected.</p>
<?php if($rewardProducts):?>
<div class="cr-check-grid">
<?php foreach($rewardProducts as $rp): if(empty($rp['is_active']))continue; ?>
<label class="cr-check"><input type="checkbox" name="reward_ids[]" value="<?= (int)$rp['id'] ?>"<?= in_array((int)$rp['id'],$editCampaignRewardIds,true)?' checked':'' ?>> <?= e((string)$rp['name']) ?><?= !empty($rp['reward_type_name'])?' · '.e((string)$rp['reward_type_name']):'' ?></label>
<?php endforeach;?>
</div>
<?php else:?>
<div class="cr-empty"><h4>No active Reward Products yet</h4><p>Create a reusable Reward first, then return here to attach it.</p><a class="cr-btn" href="<?= e(url('/rewards.php?merchant='.$merchantId.'#reward-editor')) ?>">Create Reward Product</a></div>
<?php endif;?>
</fieldset>

<label class="cr-check"><input type="checkbox" name="profile_visible" value="1"<?= !isset($editCampaign['profile_visible'])||!empty($editCampaign['profile_visible'])?' checked':'' ?>> Publish active Campaign on Owner Profile</label>
<button class="cr-btn primary">Save Campaign</button>
</form>
<script>
(function(){
 const select=document.getElementById('campaignTypeSelect'),help=document.getElementById('campaignTypeHelp'),cta=document.getElementById('campaignCtaInput');
 if(!select||!help||!cta)return;
 let autoCta=cta.value.trim()==='';
 const render=()=>{
   const option=select.selectedOptions[0];if(!option)return;
   const timing=String(option.dataset.rewardTiming||'manual').replaceAll('_',' ');
   const requires=option.dataset.requiresReward==='1'?' · Reward required':'';
   help.textContent=(option.dataset.description||'')+' · Reward timing: '+timing+requires;
   if(autoCta)cta.value=option.dataset.defaultCta||'Continue';
 };
 cta.addEventListener('input',()=>{autoCta=false;});
 select.addEventListener('change',()=>{autoCta=true;render();});
 render();
})();
</script>
<?php endif;?></section>

<section class="cr-card" id="campaign-participants">
<header><div><span>Participation</span><h2>Campaign fulfillment</h2></div><small><?= number_format(count($recentEnrollments)) ?> recent</small></header>
<p class="cr-help">Triggered and verification-gated Campaign Types land here. Verify the condition, then issue one of that Campaign's attached Rewards. Immediate Campaigns are completed automatically when their Reward is issued.</p>
<div class="cr-list">
<?php foreach($recentEnrollments as $enrollment): $options=$campaignRewardOptions[(int)$enrollment['campaign_id']]??[]; ?>
<article>
<div>
<strong><?= e((string)($enrollment['contact_name']?:$enrollment['contact_email']?:'Campaign participant')) ?></strong>
<small><?= e((string)$enrollment['campaign_name']) ?> · <?= e((string)$enrollment['campaign_type_name']) ?> · <?= e(ucwords(str_replace('_',' ',(string)$enrollment['status']))) ?> · <?= e(date('M j, Y g:i A',strtotime((string)$enrollment['enrolled_at']))) ?> UTC</small>
</div>
<?php if($canRewardIssue&&$canCampaignEnrollment&&(string)$enrollment['status']!=='completed'&&$options):?>
<form method="post" class="cr-inline"><?= csrf_field() ?>
<input type="hidden" name="action" value="campaign_fulfill">
<input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<input type="hidden" name="campaign_id" value="<?= (int)$enrollment['campaign_id'] ?>">
<input type="hidden" name="enrollment_id" value="<?= (int)$enrollment['id'] ?>">
<select name="reward_product_id" required><?php foreach($options as $rewardOption):?><option value="<?= (int)$rewardOption['id'] ?>"><?= e((string)$rewardOption['name']) ?></option><?php endforeach;?></select>
<button>Fulfill + Issue</button>
</form>
<?php elseif((string)$enrollment['status']!=='completed'&&!$options):?><small>No active Reward is attached to this Campaign.</small><?php endif;?>
</article>
<?php endforeach;?>
<?php if(!$recentEnrollments):?><p>No Campaign participation yet.</p><?php endif;?>
</div>
</section>

<section class="cr-card"><header><div><span>Merchant access</span><h2>Owners & Team</h2></div></header><div class="cr-list"><?php foreach($members as $member):?><article><div><strong><?= e((string)$member['display_name']) ?></strong><small><?= e((string)$member['email']) ?> · <?= e(ucwords(str_replace('_',' ',(string)($member['effective_role']??$member['member_role'])))) ?><?= !empty($member['team_scope_active'])?' · Merchant Team':'' ?></small></div><?php if($canOwn&&(int)$member['user_id']!==$uid):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_member_remove"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="member_user_id" value="<?= (int)$member['user_id'] ?>"><button>Remove direct access</button></form><?php endif;?></article><?php endforeach;?></div><?php if($canOwn):?><form method="post" class="cr-form cr-subform"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_member_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><h3>Add or update direct Merchant role</h3><label>VP3 email<input name="member_email" type="email" required></label><label>Merchant role<select name="member_role"><?php foreach(campaigns_rewards_merchant_roles_v100() as $role=>$label):?><option value="<?= e($role) ?>"><?= e($label) ?></option><?php endforeach;?></select></label><button class="cr-btn">Save role</button></form><?php endif;?><p class="cr-help">Direct Merchant roles and Merchant Team scope are independent. Removing a direct Administrator never restores admin authority through Team scope.</p></section>
<?php endif;?>

<?php if(isset($_GET['new_merchant'])&&$canCreate&&$merchant):?><section class="cr-card" id="new-merchant"><header><div><span>New Merchant</span><h2>Create another business entity</h2></div></header><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_create"><label>Name<input name="name" required></label><label>Slug<input name="slug"></label><label>Description<textarea name="description"></textarea></label><div class="cr-form-grid"><label>Website<input name="website_url"></label><label>Timezone<input name="timezone" value="<?= e((string)$merchant['timezone']) ?>"></label><label>Currency<input name="currency" value="<?= e((string)$merchant['currency']) ?>"></label></div><button class="cr-btn primary">Create Merchant</button></form></section><?php endif;?>
</div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>
