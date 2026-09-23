<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();
if(!$pdo||!function_exists('campaigns_rewards_platform_schema_ready_v100')||!campaigns_rewards_platform_schema_ready_v100($pdo)){
    http_response_code(503);exit('Campaign temporarily unavailable.');
}
$slug=(string)($_GET['slug']??'');$campaign=campaigns_rewards_campaign_by_slug_v100($pdo,$slug,true);
if(!$campaign){http_response_code(404);exit('Campaign not found.');}
$rewards=campaigns_rewards_rewards_v100($pdo,(int)$campaign['id'],true);
$behavior=function_exists('campaigns_rewards_campaign_type_behavior_v118')
    ?campaigns_rewards_campaign_type_behavior_v118($pdo,(int)$campaign['merchant_id'],(string)$campaign['campaign_type_key']):[];
$publicCopy=function_exists('campaigns_rewards_campaign_type_public_copy_v118')
    ?campaigns_rewards_campaign_type_public_copy_v118((string)$campaign['campaign_type_key'])
    :['action'=>'signup','cta'=>(string)$campaign['cta_label'],'marketing'=>'optional','required_fields'=>['name','email'],'reward_timing'=>'immediate','requires_reward'=>true];
$sessionKey=session_id()?:hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'').(string)($_SERVER['HTTP_USER_AGENT']??''));
try{campaigns_rewards_record_landing_view_v100($pdo,$campaign,$sessionKey);}catch(Throwable $e){error_log('Campaign landing telemetry failed: '.$e->getMessage());}

if(session_status()===PHP_SESSION_ACTIVE){
    $_SESSION['campaign_public_request_tokens']??=[];
    if(empty($_SESSION['campaign_public_request_tokens'][$campaign['public_id']])){
        $_SESSION['campaign_public_request_tokens'][$campaign['public_id']]=bin2hex(random_bytes(20));
    }
}
$requestToken=(string)($_SESSION['campaign_public_request_tokens'][$campaign['public_id']]??bin2hex(random_bytes(20)));
$error='';$issued=null;$issuedReward=null;$participation=null;$completionMessage='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Reload the page and try again.';
    elseif(trim((string)($_POST['website']??''))!=='')$error='Reward request could not be submitted.';
    elseif(!hash_equals($requestToken,(string)($_POST['request_key']??'')))$error='This Reward request has expired. Reload the page and try again.';
    else{
        try{
            campaigns_rewards_public_claim_rate_limit_v100((string)$campaign['public_id']);
            $rewardPublic=trim((string)($_POST['reward_public_id']??''));$selected=null;
            foreach($rewards as $candidate)if(hash_equals((string)$candidate['public_id'],$rewardPublic)){$selected=$candidate;break;}
            if(!$selected)throw new RuntimeException('That Reward is not currently available.');
            $contact=campaigns_rewards_customer_upsert_v100($pdo,$campaign,(string)($_POST['name']??''),(string)($_POST['email']??''),(string)($_POST['phone']??''));
            $contactId=(int)$contact['crm_contact_id'];
            $enrollment=campaigns_rewards_public_enroll_v100($pdo,(int)$campaign['id'],$contactId,'public-enroll:'.$campaign['public_id'].':'.$contactId);
            $issued=campaigns_rewards_issue_reward_v100($pdo,(int)$campaign['id'],(int)$selected['id'],$contactId,0,[
                'actor_type'=>'public','source'=>'public_signup','campaign_enrollment_id'=>(int)$enrollment['id'],
                'recipient_user_id'=>(int)($contact['vp3_user_id']??0),
                'idempotency_key'=>'public-reward:'.hash('sha256',$requestToken),
            ]);
            $issuedReward=$selected;
            if(empty($issued['credential']))throw new RuntimeException('This Reward was already issued. Sign in to your VP3 Reward Wallet to view its status.');
            if(session_status()===PHP_SESSION_ACTIVE)$_SESSION['campaign_public_request_tokens'][$campaign['public_id']]=bin2hex(random_bytes(20));
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}

$avatar='';
if(!empty($campaign['profile_avatar_path'])&&function_exists('user_avatar_url'))$avatar=user_avatar_url(['id'=>(int)$campaign['profile_user_id'],'avatar_path'=>(string)$campaign['profile_avatar_path']]);
$profileUrl=!empty($campaign['profile_username'])&&function_exists('profile_public_url')?profile_public_url((string)$campaign['profile_username']):'';
$location=trim(implode(', ',array_filter([(string)($campaign['location_name']??''),(string)($campaign['city']??''),(string)($campaign['region']??'')])));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f6f7f8"><title><?= e((string)$campaign['title']) ?> · <?= e((string)$campaign['merchant_name']) ?></title><link rel="stylesheet" href="<?= e(url('/campaigns-v100.css?v=101')) ?>"></head><body class="cr-public"><main class="cr-public-shell">
<header class="cr-public-brand"><?php if($avatar):?><img src="<?= e($avatar) ?>" alt=""><?php endif;?><div><span><?= e((string)$campaign['merchant_name']) ?></span><?php if($profileUrl):?><a href="<?= e($profileUrl) ?>">@<?= e((string)$campaign['profile_username']) ?> on VP3</a><?php endif;?></div></header>
<section class="cr-public-hero"><span><?= e((string)($campaign['campaign_type_name']??'Campaign')) ?></span><h1><?= e((string)$campaign['title']) ?></h1><?php if(trim((string)$campaign['subtitle'])!==''):?><p class="lead"><?= e((string)$campaign['subtitle']) ?></p><?php endif;?><?php if(trim((string)$campaign['description'])!==''):?><p><?= nl2br(e((string)$campaign['description'])) ?></p><?php endif;?><?php if($location!==''):?><div class="cr-public-meta">📍 <?= e($location) ?></div><?php endif;?><?php if(!empty($campaign['ends_at'])):?><div class="cr-public-meta">Ends <?= e(date('M j, Y',strtotime((string)$campaign['ends_at']))) ?></div><?php endif;?></section>

<?php if($error):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if($issued&&$issuedReward&&empty($error)):?>
<section class="cr-claim-card"><span>Reward issued</span><h2><?= e((string)$issuedReward['title']) ?></h2><p>Save this Reward Credential. The merchant will also use its own Merchant Claim Code and an authorized signed-in operator when you redeem it.</p><div class="cr-code"><?= e((string)$issued['credential']) ?></div><p><strong>Credential ending:</strong> <?= e((string)$issued['credential_last4']) ?></p><?php if(!empty($issued['expires_at'])):?><p>Expires <?= e(date('M j, Y',strtotime((string)$issued['expires_at']))) ?></p><?php endif;?><div class="cr-actions"><a class="cr-btn primary" href="<?= e(url('/rewards-wallet.php')) ?>">Open Reward Wallet</a><a class="cr-btn" href="<?= e(campaigns_rewards_campaign_url_v100((string)$campaign['slug'])) ?>">Back to campaign</a></div></section>
<?php else:?>
<section class="cr-public-rewards"><?php foreach($rewards as $reward):?><article><span><?= e(ucwords(str_replace('_',' ',(string)$reward['reward_type']))) ?></span><h2><?= e((string)$reward['title']) ?></h2><?php if(trim((string)$reward['value_label'])!==''):?><strong><?= e((string)$reward['value_label']) ?></strong><?php endif;?><p><?= nl2br(e((string)$reward['description'])) ?></p><form method="post" class="cr-public-form"><?= csrf_field() ?><input type="hidden" name="reward_public_id" value="<?= e((string)$reward['public_id']) ?>"><input type="hidden" name="request_key" value="<?= e($requestToken) ?>"><label>Name<input name="name" maxlength="190" required></label><label>Email<input name="email" type="email" maxlength="190" required></label><label>Phone <small>optional</small><input name="phone" maxlength="80"></label><label class="cr-hp">Website<input name="website" tabindex="-1" autocomplete="off"></label><button class="cr-btn primary" type="submit"><?= e((string)$campaign['cta_label']) ?></button></form></article><?php endforeach;?><?php if(!$rewards):?><div class="cr-empty"><h2>Rewards are being prepared.</h2><p>Check back soon.</p></div><?php endif;?></section>
<?php endif;?>
<?php if(trim((string)$campaign['terms'])!==''):?><section class="cr-public-terms"><h3>Terms</h3><p><?= nl2br(e((string)$campaign['terms'])) ?></p></section><?php endif;?>
<footer>Powered by VP3 Campaigns &amp; Rewards</footer></main></body></html>
