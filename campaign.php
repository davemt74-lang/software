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
    elseif(trim((string)($_POST['website']??''))!=='')$error='Campaign request could not be submitted.';
    elseif(!hash_equals($requestToken,(string)($_POST['request_key']??'')))$error='This Campaign request has expired. Reload the page and try again.';
    else{
        try{
            campaigns_rewards_public_claim_rate_limit_v100((string)$campaign['public_id']);
            if(empty($behavior['public']))throw new RuntimeException('This Campaign is not accepting public participation.');
            $rewardPublic=trim((string)($_POST['reward_public_id']??''));$selected=null;
            if($rewardPublic!=='')foreach($rewards as $candidate)if(hash_equals((string)$candidate['public_id'],$rewardPublic)){$selected=$candidate;break;}
            if(!$selected&&count($rewards)===1)$selected=$rewards[0];
            $participation=campaigns_rewards_public_participate_v118($pdo,$campaign,$_POST,$requestToken,$selected);
            $issued=is_array($participation['issued']??null)?$participation['issued']:null;
            $issuedReward=is_array($participation['reward']??null)?$participation['reward']:null;
            $completionMessage=(string)($participation['message']??'Campaign participation completed.');
            if($issued&&empty($issued['credential']))$completionMessage='Your Reward was already issued. Sign in to VP3 to view its current status.';
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

<?php
$required=array_fill_keys(array_map('strval',(array)($publicCopy['required_fields']??[])),true);
$marketing=(string)($publicCopy['marketing']??'optional');
$rewardTiming=(string)($publicCopy['reward_timing']??'immediate');
$renderForm=static function(?array $reward=null)use($requestToken,$campaign,$required,$marketing):string{
    ob_start();?>
    <form method="post" class="cr-public-form">
      <?= csrf_field() ?>
      <?php if($reward):?><input type="hidden" name="reward_public_id" value="<?= e((string)$reward['public_id']) ?>"><?php endif;?>
      <input type="hidden" name="request_key" value="<?= e($requestToken) ?>">
      <label>Name<input name="name" maxlength="190"<?= isset($required['name'])?' required':'' ?>></label>
      <label>Email<input name="email" type="email" maxlength="190"<?= isset($required['email'])?' required':'' ?>></label>
      <label>Phone <small>optional</small><input name="phone" maxlength="80"></label>
      <?php if(isset($required['birthday'])):?><label>Birthday<input name="birthday" type="date" required></label><?php endif;?>
      <?php if(isset($required['social_handle'])):?><label>Social handle<input name="social_handle" maxlength="190" required></label><?php endif;?>
      <?php if(isset($required['proof_url'])):?><label>Proof / content link<input name="proof_url" type="url" maxlength="500" required placeholder="https://"></label><?php endif;?>
      <?php if(isset($required['referral_ref'])):?><label>Referral code<input name="referral_ref" maxlength="190" required value="<?= e((string)($_GET['ref']??'')) ?>"></label><?php endif;?>
      <?php if($marketing!=='none'):?><label class="cr-check"><input type="checkbox" name="marketing_consent" value="1"<?= $marketing==='required'?' required':'' ?>> Email me news, offers and Campaign updates from <?= e((string)$campaign['merchant_name']) ?><?= $marketing==='required'?' (required)':' (optional)' ?></label><?php endif;?>
      <label class="cr-hp">Website<input name="website" tabindex="-1" autocomplete="off"></label>
      <button class="cr-btn primary" type="submit"><?= e((string)$campaign['cta_label']) ?></button>
    </form>
    <?php return (string)ob_get_clean();
};
?>
<?php if($error):?><div class="cr-notice error"><?= e($error) ?></div><?php endif;?>
<?php if($issued&&$issuedReward&&empty($error)):?>
<section class="cr-claim-card"><span>Reward issued</span><h2><?= e((string)$issuedReward['title']) ?></h2><p><?= e($completionMessage?:'Your Reward is ready.') ?></p><div class="cr-code"><?= e((string)$issued['credential']) ?></div><p><strong>Credential ending:</strong> <?= e((string)$issued['credential_last4']) ?></p><?php if(!empty($issued['expires_at'])):?><p>Expires <?= e(date('M j, Y',strtotime((string)$issued['expires_at']))) ?></p><?php endif;?><div class="cr-actions"><a class="cr-btn primary" href="<?= e(url('/reward-inbox.php')) ?>">Open Reward Inbox</a><a class="cr-btn" href="<?= e(campaigns_rewards_campaign_url_v100((string)$campaign['slug'])) ?>">Back to campaign</a></div></section>
<?php elseif($participation&&empty($error)):?>
<section class="cr-claim-card"><span>You're in</span><h2><?= e((string)($behavior['name']??$campaign['campaign_type_name']??'Campaign')) ?></h2><p><?= e($completionMessage?:'Your participation has been recorded.') ?></p><div class="cr-actions"><a class="cr-btn" href="<?= e(campaigns_rewards_campaign_url_v100((string)$campaign['slug'])) ?>">Back to campaign</a></div></section>
<?php elseif(empty($behavior['public'])):?>
<section class="cr-empty"><h2>This Campaign is managed directly by the merchant.</h2><p>Rewards for this Campaign are issued through CRM, Agent, commerce, loyalty or service workflows.</p></section>
<?php elseif(in_array($rewardTiming,['immediate','immediate_if_attached'],true)&&$rewards):?>
<section class="cr-public-rewards"><?php foreach($rewards as $reward):?><article><span><?= e(ucwords(str_replace('_',' ',(string)$reward['reward_type']))) ?></span><h2><?= e((string)$reward['title']) ?></h2><?php if(trim((string)$reward['value_label'])!==''):?><strong><?= e((string)$reward['value_label']) ?></strong><?php endif;?><p><?= nl2br(e((string)$reward['description'])) ?></p><?= $renderForm($reward) ?></article><?php endforeach;?></section>
<?php elseif(!empty($publicCopy['requires_reward'])&&$rewardTiming==='immediate'):?>
<section class="cr-empty"><h2>Rewards are being prepared.</h2><p>Check back soon.</p></section>
<?php else:?>
<section class="cr-public-rewards">
<article>
<span><?= e((string)($behavior['category']??'Campaign')) ?></span>
<h2><?= e((string)($behavior['name']??$campaign['campaign_type_name']??'Join campaign')) ?></h2>
<p><?= e((string)($behavior['description']??'Submit your information to participate.')) ?></p>
<?= $renderForm(null) ?>
</article>
<?php if($rewards):?><?php foreach($rewards as $reward):?><article><span>Campaign Reward</span><h2><?= e((string)$reward['title']) ?></h2><?php if(trim((string)$reward['value_label'])!==''):?><strong><?= e((string)$reward['value_label']) ?></strong><?php endif;?><p><?= e($rewardTiming==='after_verification'?'Issued after verification.':'Issued when the Campaign trigger is reached.') ?></p></article><?php endforeach;?><?php endif;?>
</section>
<?php endif;?>
<?php if(trim((string)$campaign['terms'])!==''):?><section class="cr-public-terms"><h3>Terms</h3><p><?= nl2br(e((string)$campaign['terms'])) ?></p></section><?php endif;?>
<footer>Powered by VP3 Campaigns &amp; Rewards</footer></main></body></html>
