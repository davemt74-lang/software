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
        }elseif($action==='automation_save'){
            $campaignId=max(0,(int)($_POST['campaign_id']??0));
            $rule=campaigns_rewards_automation_save_rule_v119($pdo,$merchantId,$campaignId,$uid,$_POST,max(0,(int)($_POST['rule_id']??0)));
            flash('notice','Campaign automation saved.');
            $redirectMerchant($merchantId,'&edit_campaign='.$campaignId.'&edit_rule='.(int)$rule['id'].'#campaign-automation');
        }elseif($action==='automation_status'){
            $rule=campaigns_rewards_automation_set_status_v119($pdo,max(0,(int)($_POST['rule_id']??0)),$uid,(string)($_POST['status']??'paused'));
            flash('notice','Campaign automation status updated.');
            $redirectMerchant($merchantId,'&edit_campaign='.(int)$rule['campaign_id'].'&edit_rule='.(int)$rule['id'].'#campaign-automation');
        }elseif($action==='automation_evaluate_due'){
            campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$uid,'campaigns.publish');
            campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$uid,'rewards.issue');
            $summary=campaigns_rewards_automation_run_due_v119($pdo,$merchantId);
            $executed=(int)($summary['birthday_trigger']['executed']??0)+(int)($summary['crm_lapse']['executed']??0);
            flash('notice','Due Campaign automations evaluated. '.$executed.' Reward'.($executed===1?'':'s').' issued.');
        }elseif($action==='message_save'){
            $campaignId=max(0,(int)($_POST['campaign_id']??0));
            $message=campaigns_rewards_journey_node_save_v121($pdo,$merchantId,$campaignId,$uid,$_POST,max(0,(int)($_POST['message_id']??0)));
            flash('notice','Campaign journey node saved.');
            $redirectMerchant($merchantId,'&edit_campaign='.$campaignId.'&edit_message='.(int)$message['id'].'#campaign-messaging');
        }elseif($action==='message_status'){
            $message=campaigns_rewards_message_set_status_v120($pdo,max(0,(int)($_POST['message_id']??0)),$uid,(string)($_POST['status']??'paused'));
            flash('notice','Campaign journey node status updated.');
            $redirectMerchant($merchantId,'&edit_campaign='.(int)$message['campaign_id'].'&edit_message='.(int)$message['id'].'#campaign-messaging');
        }elseif($action==='message_dispatch_due'){
            campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$uid,'campaigns.publish');
            $summary=campaigns_rewards_dispatch_due_v121($pdo,$merchantId,200);
            flash('notice','Due journey nodes processed. '.((int)$summary['sent']+(int)$summary['delivered']).' sent/delivered, '.(int)$summary['retry_wait'].' retrying, '.(int)$summary['dead_letter'].' dead-lettered.');
        }elseif($action==='message_retry_dead_letters'){
            $campaignId=max(0,(int)($_POST['campaign_id']??0));
            $requeued=campaigns_rewards_retry_dead_letters_v121($pdo,$merchantId,$uid,$campaignId,100);
            flash('notice',$requeued.' dead-letter deliver'.($requeued===1?'y':'ies').' requeued.');
        }elseif($action==='automation_event'){
            campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$uid,'campaigns.enrollment.manage');
            campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$uid,'rewards.issue');
            $trigger=(string)($_POST['trigger_event']??'manual');
            $allowed=['referral_qualified','winner_selected','attendance_confirmed','proof_approved','loyalty_milestone','product_available','allocation_approved','agent_action','manual'];
            if(!in_array($trigger,$allowed,true))throw new RuntimeException('Choose a supported governed Campaign event.');
            $contactId=max(0,(int)($_POST['contact_id']??0));$referrerId=max(0,(int)($_POST['referrer_contact_id']??0));
            $enrollmentId=max(0,(int)($_POST['enrollment_id']??0));
            if($referrerId<1&&$enrollmentId>0){
                $q=$pdo->prepare("SELECT metadata_json FROM campaign_enrollments e INNER JOIN campaigns c ON c.id=e.campaign_id WHERE e.id=? AND c.merchant_id=? LIMIT 1");
                $q->execute([$enrollmentId,$merchantId]);$meta=json_decode((string)($q->fetchColumn()?:''),true);if(is_array($meta))$referrerId=max(0,(int)($meta['referrer_contact_id']??0));
            }
            $eventId='merchant-event:'.$trigger.':'.$merchantId.':'.($contactId?:0).':'.bin2hex(random_bytes(8));
            $summary=campaigns_rewards_automation_run_trigger_v119($pdo,$trigger,[
                'contact_id'=>$contactId,'referrer_contact_id'=>$referrerId,
                'balance'=>max(0,(int)($_POST['balance']??0)),
                'amount_paid_cents'=>max(0,(int)($_POST['amount_paid_cents']??0)),
            ],$eventId,$merchantId);
            flash('notice','Campaign event processed. '.(int)$summary['executed'].' Reward'.((int)$summary['executed']===1?'':'s').' issued.');
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
$canCampaignPublish=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'campaigns.publish'):false;
$canAnalytics=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'analytics.view'):false;

$locations=$merchant?campaigns_rewards_locations_v100($pdo,$merchantId):[];
$campaigns=$merchant?campaigns_rewards_campaigns_v100($pdo,$merchantId):[];
$members=$merchant?campaigns_rewards_merchant_members_v100($pdo,$merchantId):[];
$report=$merchant&&$canAnalytics?campaigns_rewards_reporting_v100($pdo,$merchantId,$uid):['active_campaigns'=>0,'landing_views'=>0,'customers'=>0,'claims_redeemed'=>0];
$recentEnrollments=$merchant&&function_exists('campaigns_rewards_recent_enrollments_v118')?campaigns_rewards_recent_enrollments_v118($pdo,$merchantId,$uid,40):[];
$automationRules=$merchant?campaigns_rewards_automation_rules_v119($pdo,$merchantId):[];
$campaignMessages=$merchant&&function_exists('campaigns_rewards_messages_v120')?campaigns_rewards_messages_v120($pdo,$merchantId):[];
$campaignMessagesByCampaign=[];foreach($campaignMessages as $messageRow)$campaignMessagesByCampaign[(int)$messageRow['campaign_id']][]=$messageRow;
$automationRulesByCampaign=[];$activeAutomationCount=0;
foreach($automationRules as $automationRow){$automationRulesByCampaign[(int)$automationRow['campaign_id']][]=$automationRow;if((string)$automationRow['status']==='active')$activeAutomationCount++;}
$crmSegments=$merchant?campaigns_rewards_automation_crm_segments_v119($pdo,$merchantId):[];
$automationTriggerCatalog=campaigns_rewards_automation_triggers_v119();$automationAudienceCatalog=campaigns_rewards_automation_audiences_v119();
$campaignFunnels=[];$campaignInsights=[];
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
            if($canAnalytics){
                $campaignFunnels[(int)$campaignRow['id']]=campaigns_rewards_campaign_funnel_v119($pdo,(int)$campaignRow['id'],$uid);
                $campaignInsights[(int)$campaignRow['id']]=campaigns_rewards_lifecycle_insights_v119($pdo,(int)$campaignRow['id'],$uid);
            }
        }
    }
}

$editCampaignId=max(0,(int)($_GET['edit_campaign']??0));$editCampaign=null;foreach($campaigns as $row)if((int)$row['id']===$editCampaignId)$editCampaign=$row;
if($editCampaign&&function_exists('campaigns_rewards_campaign_reward_ids_v118'))$editCampaignRewardIds=campaigns_rewards_campaign_reward_ids_v118($pdo,(int)$editCampaign['id']);
$editRuleId=max(0,(int)($_GET['edit_rule']??0));$editRule=null;
foreach($automationRules as $ruleRow)if((int)$ruleRow['id']===$editRuleId&&(!$editCampaign||(int)$ruleRow['campaign_id']===(int)$editCampaign['id'])){$editRule=$ruleRow;break;}
$editCampaignRules=$editCampaign?($automationRulesByCampaign[(int)$editCampaign['id']]??[]):[];
$automationConditions=is_array($editRule['conditions']??null)?$editRule['conditions']:[];
$automationActions=is_array($editRule['actions']??null)?$editRule['actions']:[];
$automationDefaultTrigger=$editCampaign?campaigns_rewards_automation_default_trigger_v119((string)$editCampaign['campaign_type_key']):'manual';
$automationDefaultAudience=$editCampaign?campaigns_rewards_automation_default_audience_v119((string)$editCampaign['campaign_type_key']):'all_contacts';
$editCampaignMessages=$editCampaign?($campaignMessagesByCampaign[(int)$editCampaign['id']]??[]):[];
$editMessageId=max(0,(int)($_GET['edit_message']??0));$editMessage=null;
foreach($editCampaignMessages as $messageRow)if((int)$messageRow['id']===$editMessageId){$editMessage=$messageRow;break;}
$editMessageTemplate=is_array($editMessage['template']??null)?$editMessage['template']:[];
$messagePerformance=$editCampaign&&$canAnalytics&&function_exists('campaigns_rewards_journey_performance_v121')
    ?campaigns_rewards_journey_performance_v121($pdo,(int)$editCampaign['id'],$uid)
    :($editCampaign&&$canAnalytics?campaigns_rewards_message_performance_v120($pdo,(int)$editCampaign['id'],$uid):null);
$messageChannels=function_exists('campaigns_rewards_message_channels_v120')?campaigns_rewards_message_channels_v120():[];
$messageTriggers=function_exists('campaigns_rewards_journey_triggers_v120')?campaigns_rewards_journey_triggers_v120():[];
$messagePurposes=function_exists('campaigns_rewards_message_purposes_v120')?campaigns_rewards_message_purposes_v120():[];
$journeyNodeTypes=function_exists('campaigns_rewards_journey_node_types_v121')?campaigns_rewards_journey_node_types_v121():['message'=>'Message'];
$journeyConditionFields=function_exists('campaigns_rewards_journey_condition_fields_v121')?campaigns_rewards_journey_condition_fields_v121():[];
$journeyConditionOperators=function_exists('campaigns_rewards_journey_condition_operators_v121')?campaigns_rewards_journey_condition_operators_v121():[];
$journeyWaitModes=function_exists('campaigns_rewards_journey_wait_modes_v121')?campaigns_rewards_journey_wait_modes_v121():[];
$editLocationId=max(0,(int)($_GET['edit_location']??0));$editLocation=null;foreach($locations as $row)if((int)$row['id']===$editLocationId)$editLocation=$row;
$notice=(string)(flash('notice')??'');$error=(string)(flash('error')??'');
$fulfillmentOnce=session_status()===PHP_SESSION_ACTIVE?($_SESSION['campaign_fulfillment_once']??null):null;
if(session_status()===PHP_SESSION_ACTIVE)unset($_SESSION['campaign_fulfillment_once']);

$memberHeaderUser=$user;$memberHeaderTitle='Campaigns';$memberHeaderSubtitle='Merchant identity, locations, Campaign lifecycle, landing pages and Team access';
$actions=['<a class="cr-btn primary" href="'.e(url('/rewards.php'.($merchantId?'?merchant='.$merchantId:''))).'">Rewards</a>'];
if($canCreate)$actions[]='<a class="cr-btn" href="'.e(url('/campaigns.php?new_merchant=1#new-merchant')).'">+ Merchant</a>';
$memberHeaderActions=implode(' ',$actions);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Campaigns</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=121')) ?>"></head>
<body class="cr-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='campaigns';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main cr-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="cr-wrap">
<?php if($notice!==''):?><div class="cr-notice success"><?= e($notice) ?></div><?php endif;?><?php if($error!==''):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if(is_array($fulfillmentOnce)&&!empty($fulfillmentOnce['credential'])):?><div class="cr-notice success"><strong>Reward Credential:</strong> <code><?= e((string)$fulfillmentOnce['credential']) ?></code> · <?= e((string)$fulfillmentOnce['campaign']) ?>. Copy it now; only its hash is stored.</div><?php endif;?>

<section class="cr-toolbar"><div><strong>Merchant workspace</strong><span>Business identity is separate from your VP3 login; Team scope and direct Merchant roles remain independent.</span></div><?php if($merchants):?><form method="get"><select name="merchant" onchange="this.form.submit()"><?php foreach($merchants as $m):?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id']===$merchantId?' selected':'' ?>><?= e((string)$m['name']) ?> · <?= e(ucwords(str_replace('_',' ',(string)$m['access_role']))) ?></option><?php endforeach;?></select></form><?php endif;?></section>

<?php if(!$merchant):?>
<section class="cr-empty" id="new-merchant"><h2>Create your first Merchant</h2><p>Merchants own business state; your VP3 login remains your personal identity.</p><?php if($canCreate):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_create"><label>Name<input name="name" required maxlength="190"></label><label>Public slug<input name="slug" maxlength="100"></label><label>Description<textarea name="description"></textarea></label><div class="cr-form-grid"><label>Website<input name="website_url"></label><label>Timezone<input name="timezone" value="America/Phoenix"></label><label>Currency<input name="currency" value="USD" maxlength="3"></label></div><label class="cr-check"><input type="checkbox" name="sandbox_mode" value="1"> Sandbox Merchant</label><button class="cr-btn primary">Create Merchant</button></form><?php else:?><a class="cr-btn primary" href="<?= e(url('/plugins.php')) ?>">Enable plugin</a><?php endif;?></section>
<?php else:?>

<?php if($canAnalytics):?><section class="cr-metrics"><article><span>Active Campaigns</span><strong><?= number_format((int)$report['active_campaigns']) ?></strong></article><article><span>Active Automations</span><strong><?= number_format($activeAutomationCount) ?></strong></article><article><span>Landing views</span><strong><?= number_format((int)$report['landing_views']) ?></strong></article><article><span>Reward conversions</span><strong><?= number_format((int)$report['claims_redeemed']) ?></strong><small>Claimed certificates attributed to Campaigns</small></article></section><?php endif;?>

<div class="cr-grid">
<section class="cr-card"><header><div><span>Merchant</span><h2><?= e((string)$merchant['name']) ?></h2></div><strong><?= e(ucfirst((string)$merchant['status'])) ?></strong></header><p><?= e((string)($merchant['description']??'')) ?></p>
<?php if($canManage):?><form method="post" class="cr-form"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_update"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><label>Name<input name="name" required value="<?= e((string)$merchant['name']) ?>"></label><label>Slug<input name="slug" required value="<?= e((string)$merchant['slug']) ?>"></label><label>Description<textarea name="description"><?= e((string)($merchant['description']??'')) ?></textarea></label><div class="cr-form-grid"><label>Website<input name="website_url" value="<?= e((string)($merchant['website_url']??'')) ?>"></label><label>Email<input name="contact_email" type="email" value="<?= e((string)($merchant['contact_email']??'')) ?>"></label><label>Phone<input name="contact_phone" value="<?= e((string)($merchant['contact_phone']??'')) ?>"></label><label>Timezone<input name="timezone" value="<?= e((string)$merchant['timezone']) ?>"></label><label>Currency<input name="currency" maxlength="3" value="<?= e((string)$merchant['currency']) ?>"></label></div><label class="cr-check"><input type="checkbox" name="sandbox_mode" value="1"<?= !empty($merchant['sandbox_mode'])?' checked':'' ?>> Sandbox mode</label><button class="cr-btn">Save Merchant</button></form>
<form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="merchant_status"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><select name="status"><?php foreach(['active','suspended','archived','closed'] as $st):?><option value="<?= e($st) ?>"<?= $merchant['status']===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select><input name="reason" placeholder="Reason"><button>Update lifecycle</button></form><?php endif;?></section>

<section class="cr-card"><header><div><span>Locations</span><h2>Merchant Locations</h2></div></header><div class="cr-list"><?php foreach($locations as $location):?><article><div><strong><?= e((string)$location['name']) ?><?= !empty($location['is_primary'])?' · Primary':'' ?></strong><small><?= e(trim((string)$location['city'].', '.(string)$location['region'],', ')) ?></small></div><?php if($canLocations):?><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_location='.(int)$location['id'].'#location-editor')) ?>">Edit</a><?php endif;?></article><?php endforeach;?><?php if(!$locations):?><p>No Locations yet.</p><?php endif;?></div>
<?php if($canLocations):?><form method="post" class="cr-form cr-subform" id="location-editor"><?= csrf_field() ?><input type="hidden" name="action" value="location_save"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="location_id" value="<?= (int)($editLocation['id']??0) ?>"><h3><?= $editLocation?'Edit':'Add' ?> Location</h3><label>Name<input name="name" required value="<?= e((string)($editLocation['name']??'')) ?>"></label><div class="cr-form-grid"><label>Address<input name="address1" value="<?= e((string)($editLocation['address1']??'')) ?>"></label><label>City<input name="city" value="<?= e((string)($editLocation['city']??'')) ?>"></label><label>Region<input name="region" value="<?= e((string)($editLocation['region']??'')) ?>"></label><label>Postal<input name="postal_code" value="<?= e((string)($editLocation['postal_code']??'')) ?>"></label></div><label class="cr-check"><input type="checkbox" name="is_primary" value="1"<?= !empty($editLocation['is_primary'])?' checked':'' ?>> Primary Location</label><label class="cr-check"><input type="checkbox" name="is_active" value="1"<?= !isset($editLocation['is_active'])||!empty($editLocation['is_active'])?' checked':'' ?>> Active</label><button class="cr-btn">Save Location</button></form><?php endif;?></section>
</div>

<section class="cr-card"><header><div><span>Campaigns</span><h2>Campaign lifecycle & landing pages</h2></div><a class="cr-btn" href="<?= e(url('/rewards.php?merchant='.$merchantId)) ?>">Manage Rewards</a></header>
<div class="cr-campaign-grid"><?php foreach($campaigns as $campaign): $funnel=$campaignFunnels[(int)$campaign['id']]??null;$ruleCount=count($automationRulesByCampaign[(int)$campaign['id']]??[]);?><article class="cr-campaign"><div class="cr-status <?= e((string)$campaign['status']) ?>"><?= e(ucfirst((string)$campaign['status'])) ?></div><h3><?= e((string)$campaign['title']) ?></h3><p><?= e((string)$campaign['subtitle']) ?></p><small><?= e(ucwords(str_replace('_',' ',(string)($campaign['campaign_type_key']??'campaign')))) ?> · v<?= (int)$campaign['current_version_no'] ?> · <?= $ruleCount ?> automation<?= $ruleCount===1?'':'s' ?></small>
<?php if($funnel):?><div class="cr-mini-funnel"><span>Views <strong><?= number_format((int)$funnel['views']) ?></strong></span><span>Joined <strong><?= number_format((int)$funnel['participated']) ?></strong></span><span>Issued <strong><?= number_format((int)$funnel['issued']) ?></strong></span><span>Claimed <strong><?= number_format((int)$funnel['claimed']) ?></strong></span></div><?php endif;?>
<div class="cr-actions"><a href="<?= e(campaigns_rewards_campaign_url_v100((string)$campaign['slug'])) ?>" target="_blank">Landing page ↗</a><?php if($canCampaignEdit):?><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_campaign='.(int)$campaign['id'].'#campaign-editor')) ?>">Edit</a><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_campaign='.(int)$campaign['id'].'#campaign-automation')) ?>">Automation</a><a href="<?= e(url('/rewards.php?merchant='.$merchantId.'#reward-editor')) ?>">Rewards</a><?php endif;?></div><?php if($canCampaignEdit):?><form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_status"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><select name="status"><?php foreach(['draft','scheduled','active','paused','completed','archived'] as $st):?><option value="<?= e($st) ?>"<?= $campaign['status']===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select><button>Update</button></form><?php endif;?></article><?php endforeach;?><?php if(!$campaigns):?><div class="cr-empty"><h3>No Campaigns yet</h3><p>Create a Campaign and then attach reusable Reward Products from Rewards.</p></div><?php endif;?></div>
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

<?php if($editCampaign): $editFunnel=$campaignFunnels[(int)$editCampaign['id']]??null;$editInsights=$campaignInsights[(int)$editCampaign['id']]??[];$editRewards=$campaignRewardOptions[(int)$editCampaign['id']]??[]; ?>
<section class="cr-card" id="campaign-automation">
<header>
<div><span>Automation + intelligence</span><h2><?= e((string)$editCampaign['title']) ?></h2></div>
<?php if($canCampaignPublish&&$canRewardIssue):?><form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="automation_evaluate_due"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><button type="submit">Evaluate due rules</button></form><?php endif;?>
</header>
<p class="cr-help">Campaign automations reuse canonical CRM, Campaign Versions, Reward Issuance, inventory, budgets and VP3 lifecycle events. Active automation never creates a second scheduler, CRM, Wallet or Agent Brain.</p>

<?php if($editFunnel):?>
<div class="cr-funnel">
<article><span>Viewed</span><strong><?= number_format((int)$editFunnel['views']) ?></strong></article>
<article><span>Participated</span><strong><?= number_format((int)$editFunnel['participated']) ?></strong><small><?= e((string)$editFunnel['participation_rate']) ?>%</small></article>
<article><span>Qualified</span><strong><?= number_format((int)$editFunnel['qualified']) ?></strong></article>
<article><span>Issued</span><strong><?= number_format((int)$editFunnel['issued']) ?></strong><small><?= e((string)$editFunnel['issue_rate']) ?>%</small></article>
<article><span>Reward viewed</span><strong><?= number_format((int)$editFunnel['reward_viewed']) ?></strong></article>
<article><span>Sent / regifted</span><strong><?= number_format((int)$editFunnel['sent']) ?></strong></article>
<article><span>Claimed</span><strong><?= number_format((int)$editFunnel['claimed']) ?></strong><small><?= e((string)$editFunnel['claim_rate']) ?>%</small></article>
</div>
<div class="cr-ops-strip"><span>Outstanding liability <strong><?= e(number_format(((int)$editFunnel['outstanding_liability_minor'])/100,2)) ?></strong></span><span>Tracked inventory remaining <strong><?= number_format((int)$editFunnel['inventory_remaining']) ?></strong></span></div>
<?php endif;?>

<?php if($editInsights):?><div class="cr-insights"><?php foreach($editInsights as $insight):?><article><strong><?= e((string)$insight['title']) ?></strong><span><?= e((string)$insight['detail']) ?></span></article><?php endforeach;?></div><?php endif;?>

<div class="cr-list cr-automation-list">
<?php foreach($editCampaignRules as $rule): $cond=(array)$rule['conditions'];$act=(array)$rule['actions']; ?>
<article>
<div><strong><?= e((string)$rule['name']) ?></strong><small><?= e((string)($automationTriggerCatalog[$rule['trigger_event']]['label']??$rule['trigger_event'])) ?> → <?= e((string)($automationAudienceCatalog[$cond['audience_mode']??'all_contacts']??($cond['audience_mode']??'all_contacts'))) ?> → Issue Reward #<?= (int)($act['reward_product_id']??0) ?> · <?= e(ucfirst((string)$rule['status'])) ?></small></div>
<div class="cr-actions"><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_campaign='.(int)$editCampaign['id'].'&edit_rule='.(int)$rule['id'].'#campaign-automation')) ?>">Edit</a>
<?php if($canCampaignEdit):?><form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="automation_status"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><select name="status"><?php foreach(['draft','active','paused'] as $st):?><option value="<?= e($st) ?>"<?= $rule['status']===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select><button>Set</button></form><?php endif;?>
</div>
</article>
<?php endforeach;?>
<?php if(!$editCampaignRules):?><p>No automation rules yet. The form below is prefilled from this Campaign Type.</p><?php endif;?>
</div>

<?php if($canCampaignEdit):?>
<form method="post" class="cr-form cr-editor cr-automation-editor">
<?= csrf_field() ?>
<input type="hidden" name="action" value="automation_save">
<input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<input type="hidden" name="campaign_id" value="<?= (int)$editCampaign['id'] ?>">
<input type="hidden" name="rule_id" value="<?= (int)($editRule['id']??0) ?>">
<h3><?= $editRule?'Edit automation':'Add automation' ?></h3>
<div class="cr-automation-sentence">When <strong id="automationTriggerSummary"></strong>, for <strong id="automationAudienceSummary"></strong>, issue the selected Reward.</div>
<div class="cr-form-grid">
<label>Name<input name="name" maxlength="190" value="<?= e((string)($editRule['name']??($editCampaign['title'].' automation'))) ?>"></label>
<label>Trigger<select name="trigger_event" id="automationTrigger"><?php $selectedTrigger=(string)($editRule['trigger_event']??$automationDefaultTrigger);foreach($automationTriggerCatalog as $key=>$meta):?><option value="<?= e($key) ?>"<?= $selectedTrigger===$key?' selected':'' ?>><?= e((string)$meta['label']) ?></option><?php endforeach;?></select></label>
<label>CRM audience<select name="audience_mode" id="automationAudience"><?php $selectedAudience=(string)($automationConditions['audience_mode']??$automationDefaultAudience);foreach($automationAudienceCatalog as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedAudience===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Saved CRM segment<select name="crm_segment_id"><option value="">None</option><?php foreach($crmSegments as $segment):?><option value="<?= (int)$segment['id'] ?>"<?= (int)($automationConditions['crm_segment_id']??0)===(int)$segment['id']?' selected':'' ?>><?= e((string)$segment['name']) ?> · <?= number_format((int)$segment['member_count']) ?></option><?php endforeach;?></select></label>
<label>Reward<select name="reward_product_id" required><?php $selectedReward=(int)($automationActions['reward_product_id']??($editRewards[0]['id']??0));foreach($editRewards as $rewardOption):?><option value="<?= (int)$rewardOption['id'] ?>"<?= $selectedReward===(int)$rewardOption['id']?' selected':'' ?>><?= e((string)$rewardOption['name']) ?></option><?php endforeach;?></select></label>
<label>Referral recipient<select name="recipient_mode"><?php $recipientMode=(string)($automationActions['recipient_mode']??'event_contact');foreach(['event_contact'=>'Participant / event contact','referrer'=>'Referrer','both'=>'Both participant + referrer'] as $key=>$label):?><option value="<?= e($key) ?>"<?= $recipientMode===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Status<select name="status"><?php $ruleStatus=(string)($editRule['status']??'draft');foreach(['draft','active','paused'] as $st):?><option value="<?= e($st) ?>"<?= $ruleStatus===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select></label>
<label>Cooldown days<input type="number" min="0" max="3650" name="cooldown_days" value="<?= (int)($automationConditions['cooldown_days']??90) ?>"></label>
<label>Win-back inactivity days<input type="number" min="1" max="3650" name="inactive_days" value="<?= (int)($automationConditions['inactive_days']??30) ?>"></label>
<label>Birthday window ± days<input type="number" min="0" max="31" name="birthday_window_days" value="<?= (int)($automationConditions['birthday_window_days']??0) ?>"></label>
<label>Minimum purchase (cents)<input type="number" min="0" name="minimum_purchase_minor" value="<?= (int)($automationConditions['minimum_purchase_minor']??0) ?>"></label>
<label>Minimum loyalty points<input type="number" min="0" name="minimum_points" value="<?= (int)($automationConditions['minimum_points']??0) ?>"></label>
<label>Max actions / run<input type="number" min="1" max="1000" name="max_actions_per_run" value="<?= (int)($automationConditions['max_actions_per_run']??100) ?>"></label>
</div>
<label class="cr-check"><input type="checkbox" name="marketing_only" value="1"<?= !empty($automationConditions['marketing_only'])?' checked':'' ?>> Only contacts currently subscribed to Merchant marketing</label>
<p class="cr-help">Activating a rule requires Campaign publish + Reward issue authority and an active Campaign. Event rules act only on the verified event contact; scheduled birthday/win-back rules may evaluate a broader configured audience.</p>
<button class="cr-btn primary" type="submit">Save automation</button>
</form>
<script>
(function(){
 const t=document.getElementById('automationTrigger'),a=document.getElementById('automationAudience'),ts=document.getElementById('automationTriggerSummary'),as=document.getElementById('automationAudienceSummary');
 if(!t||!a||!ts||!as)return;
 const render=()=>{ts.textContent=t.selectedOptions[0]?.textContent||'the trigger fires';as.textContent=a.selectedOptions[0]?.textContent||'the audience';};
 t.addEventListener('change',render);a.addEventListener('change',render);render();
})();
</script>
<?php endif;?>
</section>

<section class="cr-card" id="campaign-messaging">
<header>
<div><span>Journey orchestration</span><h2>Messaging & journeys</h2></div>
<?php if($canCampaignPublish):?><div class="cr-actions">
<form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="message_dispatch_due"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><button type="submit">Run due nodes</button></form>
<form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="message_retry_dead_letters"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="campaign_id" value="<?= (int)$editCampaign['id'] ?>"><button type="submit">Retry dead letters</button></form>
</div><?php endif;?>
</header>
<p class="cr-help">V1.21 turns Campaign messaging into a durable journey graph. Message, decision, wait-until and exit nodes share the canonical Campaign delivery queue; A/B variants are deterministic, consent is rechecked at send time, and provider failures use bounded retry + dead-letter handling.</p>

<?php if($messagePerformance): $mp=$messagePerformance['totals']??[]; ?>
<div class="cr-funnel" aria-label="Message performance">
<article><span>Queued</span><strong><?= number_format((int)($mp['queued']??0)) ?></strong></article>
<article><span>Sent</span><strong><?= number_format((int)($mp['sent']??0)) ?></strong></article>
<article><span>Delivered</span><strong><?= number_format((int)($mp['delivered']??0)) ?></strong><small><?= e((string)($messagePerformance['delivery_rate']??0)) ?>%</small></article>
<article><span>Viewed</span><strong><?= number_format((int)($mp['viewed']??0)) ?></strong><small><?= e((string)($messagePerformance['view_rate']??0)) ?>%</small></article>
<article><span>Converted</span><strong><?= number_format((int)($mp['converted']??0)) ?></strong><small><?= e((string)($messagePerformance['conversion_rate']??0)) ?>%</small></article>
<article><span>Retrying</span><strong><?= number_format((int)($messagePerformance['retry_wait']??0)) ?></strong></article>
<article><span>Dead letter</span><strong><?= number_format((int)($messagePerformance['dead_letter']??0)) ?></strong></article>
</div>
<p class="cr-help"><strong>Message performance</strong> keeps delivery/view/conversion attribution while V1.21 adds orchestration, retry and variant-level state.</p>
<?php endif;?>

<div class="cr-list cr-journey-list">
<?php foreach($editCampaignMessages as $messageRow): $messageMeta=(array)($messageRow['template']??[]);$nodeType=(string)($messageMeta['node_type']??'message'); ?>
<article>
<div><strong><?= e((string)($messageMeta['journey_key']??'default')) ?> · <?= e((string)($messageMeta['step_key']??$messageRow['message_key'])) ?><?php if(($messageMeta['variant_key']??'default')!=='default'):?> · variant <?= e((string)$messageMeta['variant_key']) ?><?php endif;?></strong>
<small>Step <?= (int)($messageMeta['step_order']??1) ?> · <?= e((string)($journeyNodeTypes[$nodeType]??ucwords(str_replace('_',' ',$nodeType)))) ?> · <?= e((string)($messageTriggers[$messageMeta['trigger_event']??'manual']??($messageMeta['trigger_event']??'manual'))) ?><?php if($nodeType==='message'):?> · <?= e((string)($messageChannels[$messageRow['channel']]??$messageRow['channel'])) ?><?php endif;?> · v<?= (int)$messageRow['version_no'] ?> · <?= e(ucfirst((string)$messageRow['status'])) ?></small></div>
<div class="cr-actions"><a href="<?= e(url('/campaigns.php?merchant='.$merchantId.'&edit_campaign='.(int)$editCampaign['id'].'&edit_message='.(int)$messageRow['id'].'#campaign-messaging')) ?>">Edit</a>
<?php if($canCampaignEdit):?><form method="post" class="cr-inline"><?= csrf_field() ?><input type="hidden" name="action" value="message_status"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="message_id" value="<?= (int)$messageRow['id'] ?>"><select name="status"><?php foreach(['draft','active','paused'] as $st):?><option value="<?= e($st) ?>"<?= $messageRow['status']===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select><button>Set</button></form><?php endif;?>
</div>
</article>
<?php endforeach;?>
<?php if(!$editCampaignMessages):?><p>No journey nodes yet. Add an entry node below.</p><?php endif;?>
</div>

<?php if($canCampaignEdit):?>
<form method="post" class="cr-form cr-editor" id="journeyNodeEditor">
<?= csrf_field() ?>
<input type="hidden" name="action" value="message_save">
<input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<input type="hidden" name="campaign_id" value="<?= (int)$editCampaign['id'] ?>">
<input type="hidden" name="message_id" value="<?= (int)($editMessage['id']??0) ?>">
<h3><?= $editMessage?'Edit journey node':'Add journey node' ?></h3>
<div class="cr-form-grid">
<label>Journey key<input name="journey_key" maxlength="50" value="<?= e((string)($editMessageTemplate['journey_key']??'default')) ?>"<?= $editMessage?' readonly':'' ?>></label>
<label>Step key<input name="step_key" maxlength="50" value="<?= e((string)($editMessageTemplate['step_key']??'message-1')) ?>"<?= $editMessage?' readonly':'' ?>></label>
<label>Node type<select name="node_type" id="journeyNodeType"><?php $selectedNodeType=(string)($editMessageTemplate['node_type']??'message');foreach($journeyNodeTypes as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedNodeType===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Step order<input type="number" min="1" max="999" name="step_order" value="<?= (int)($editMessageTemplate['step_order']??1) ?>"></label>
<label>Trigger<select name="trigger_event"><?php $selectedMessageTrigger=(string)($editMessageTemplate['trigger_event']??$automationDefaultTrigger);foreach($messageTriggers as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedMessageTrigger===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Status<select name="status"><?php $messageStatus=(string)($editMessage['status']??'draft');foreach(['draft','active','paused'] as $st):?><option value="<?= e($st) ?>"<?= $messageStatus===$st?' selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach;?></select></label>
<label>Variant key<input name="variant_key" maxlength="30" value="<?= e((string)($editMessageTemplate['variant_key']??'default')) ?>"<?= $editMessage?' readonly':'' ?>></label>
<label>Variant weight<input type="number" min="1" max="10000" name="variant_weight" value="<?= (int)($editMessageTemplate['variant_weight']??100) ?>"></label>
<label>Next step<input name="next_step_key" maxlength="50" value="<?= e((string)($editMessageTemplate['next_step_key']??'')) ?>" placeholder="thank-you"></label>
<label>Delay (minutes)<input type="number" min="0" max="5256000" name="delay_minutes" value="<?= (int)($editMessageTemplate['delay_minutes']??0) ?>"></label>
</div>
<label class="cr-check"><input type="checkbox" name="entry_node" value="1"<?= !empty($editMessageTemplate['entry_node'])?' checked':'' ?>> Entry node for this trigger</label>

<fieldset><legend>Message delivery</legend><div class="cr-form-grid">
<label>Channel<select name="channel"><?php $selectedChannel=(string)($editMessage['channel']??'email');foreach($messageChannels as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedChannel===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Purpose<select name="purpose"><?php $selectedPurpose=(string)($editMessageTemplate['purpose']??'marketing');foreach($messagePurposes as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedPurpose===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Local send time<input name="local_send_time" type="time" value="<?= e((string)($editMessageTemplate['local_send_time']??'')) ?>"></label>
<label>Expiration lead (days)<input type="number" min="0" max="3650" name="expiration_lead_days" value="<?= (int)($editMessageTemplate['expiration_lead_days']??7) ?>"></label>
<label>Subject<input name="subject" maxlength="255" value="<?= e((string)($editMessage['subject']??'')) ?>"></label>
<label>Retry attempts<input type="number" min="1" max="10" name="retry_max_attempts" value="<?= (int)($editMessageTemplate['retry_max_attempts']??3) ?>"></label>
<label>Retry backoff (minutes)<input type="number" min="1" max="1440" name="retry_backoff_minutes" value="<?= (int)($editMessageTemplate['retry_backoff_minutes']??5) ?>"></label>
</div>
<label>Message / node notes<textarea name="body" rows="6"><?= e((string)($editMessage['body']??'')) ?></textarea></label>
<p class="cr-help">Message tokens: <code>{{name}}</code>, <code>{{email}}</code>, <code>{{campaign_name}}</code>, <code>{{merchant_name}}</code>, <code>{{reward_name}}</code>, <code>{{reward_expiration}}</code>, <code>{{campaign_url}}</code>, <code>{{reward_wallet_url}}</code>.</p>
<label class="cr-check"><input type="checkbox" name="respect_quiet_hours" value="1"<?= !array_key_exists('respect_quiet_hours',$editMessageTemplate)||!empty($editMessageTemplate['respect_quiet_hours'])?' checked':'' ?>> Respect CRM quiet hours and contact/Merchant timezone</label>
</fieldset>

<fieldset><legend>Decision branch</legend><div class="cr-form-grid">
<label>Condition field<select name="condition_field"><?php $selectedConditionField=(string)($editMessageTemplate['condition_field']??'contact.marketing_status');foreach($journeyConditionFields as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedConditionField===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Operator<select name="condition_operator"><?php $selectedConditionOperator=(string)($editMessageTemplate['condition_operator']??'equals');foreach($journeyConditionOperators as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedConditionOperator===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Condition value<input name="condition_value" maxlength="190" value="<?= e((string)($editMessageTemplate['condition_value']??'')) ?>"></label>
<label>True → step<input name="true_next_step_key" maxlength="50" value="<?= e((string)($editMessageTemplate['true_next_step_key']??'')) ?>"></label>
<label>False → step<input name="false_next_step_key" maxlength="50" value="<?= e((string)($editMessageTemplate['false_next_step_key']??'')) ?>"></label>
</div></fieldset>

<fieldset><legend>Wait-until scheduling</legend><div class="cr-form-grid">
<label>Wait mode<select name="wait_mode"><?php $selectedWaitMode=(string)($editMessageTemplate['wait_mode']??'delay');foreach($journeyWaitModes as $key=>$label):?><option value="<?= e($key) ?>"<?= $selectedWaitMode===$key?' selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
<label>Fixed UTC<input type="datetime-local" name="wait_until" value="<?= !empty($editMessageTemplate['wait_until'])?e(date('Y-m-d\TH:i',strtotime((string)$editMessageTemplate['wait_until']))):'' ?>"></label>
<label>Local clock time<input type="time" name="wait_local_time" value="<?= e((string)($editMessageTemplate['wait_local_time']??'')) ?>"></label>
<label>Additional days<input type="number" min="0" max="3650" name="wait_days" value="<?= (int)($editMessageTemplate['wait_days']??0) ?>"></label>
</div></fieldset>

<fieldset><legend>Journey exits</legend>
<label class="cr-check"><input type="checkbox" name="exit_on_conversion" value="1"<?= !empty($editMessageTemplate['exit_on_conversion'])?' checked':'' ?>> Exit this journey instance after a claim-attributed conversion</label>
<label class="cr-check"><input type="checkbox" name="stop_on_claim" value="1"<?= !empty($editMessageTemplate['stop_on_claim'])?' checked':'' ?>> Exit if the linked Reward is already claimed</label>
<label class="cr-check"><input type="checkbox" name="stop_on_expiration" value="1"<?= !empty($editMessageTemplate['stop_on_expiration'])?' checked':'' ?>> Exit if the linked Reward is expired or voided</label>
</fieldset>
<p class="cr-help">A/B variants share the same journey + step key and use different variant keys/weights. Selection is deterministic for the contact and journey instance. Activating nodes still requires publish authority; provider webhooks can only update delivery state.</p>
<button class="cr-btn primary" type="submit">Save journey node</button>
</form>
<?php endif;?>
</section>
<?php endif;?>

<section class="cr-card" id="campaign-participants">
<header><div><span>Participation</span><h2>Campaign fulfillment</h2></div><small><?= number_format(count($recentEnrollments)) ?> recent</small></header>
<p class="cr-help">Triggered and verification-gated Campaign Types land here. Verify the condition, then issue one of that Campaign's attached Rewards. Immediate Campaigns are completed automatically when their Reward is issued.</p>
<div class="cr-list">
<?php foreach($recentEnrollments as $enrollment): $options=$campaignRewardOptions[(int)$enrollment['campaign_id']]??[];$enrollmentMeta=json_decode((string)($enrollment['metadata_json']??''),true);if(!is_array($enrollmentMeta))$enrollmentMeta=[];$governedTrigger=campaigns_rewards_automation_default_trigger_v119((string)$enrollment['campaign_type_key']); ?>
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
<?php if(in_array($governedTrigger,['referral_qualified','winner_selected','attendance_confirmed','proof_approved','loyalty_milestone','product_available','allocation_approved','agent_action'],true)):?>
<form method="post" class="cr-inline cr-automation-event"><?= csrf_field() ?>
<input type="hidden" name="action" value="automation_event">
<input type="hidden" name="merchant_id" value="<?= $merchantId ?>">
<input type="hidden" name="campaign_id" value="<?= (int)$enrollment['campaign_id'] ?>">
<input type="hidden" name="enrollment_id" value="<?= (int)$enrollment['id'] ?>">
<input type="hidden" name="contact_id" value="<?= (int)$enrollment['contact_id'] ?>">
<input type="hidden" name="referrer_contact_id" value="<?= (int)($enrollmentMeta['referrer_contact_id']??0) ?>">
<input type="hidden" name="trigger_event" value="<?= e($governedTrigger) ?>">
<button type="submit">Verify + trigger <?= e((string)($automationTriggerCatalog[$governedTrigger]['label']??'automation')) ?></button>
</form>
<?php endif;?>
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
