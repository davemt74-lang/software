<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-types-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v110.php';
require_once __DIR__.'/../includes/campaigns-rewards-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-v119.php';
require_once __DIR__.'/../includes/campaigns-rewards-v120.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v118.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v119.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v120.php';

function cr_v120_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

$channels=campaigns_rewards_message_channels_v120();
foreach(['email','sms','agent'] as $channel)cr_v120_assert(isset($channels[$channel]),"channel {$channel} is registered");
$triggers=campaigns_rewards_journey_triggers_v120();
foreach(['birthday_trigger','crm_lapse','purchase_completed','referral_qualified','reward_expiring','manual'] as $trigger)
    cr_v120_assert(isset($triggers[$trigger]),"journey trigger {$trigger} is registered");

cr_v120_assert(campaigns_rewards_message_key_v120('Win Back','Reminder 1')==='win-back--reminder-1','journey message keys are deterministic');
$consent=campaigns_rewards_message_consent_v120(['status'=>'active','marketing_status'=>'subscribed','merchant_marketing_status'=>'subscribed','marketing_email'=>null],'email','marketing');
cr_v120_assert(($consent['allowed']??false)===true,'email marketing accepts canonical subscribed CRM state');
$consent=campaigns_rewards_message_consent_v120(['status'=>'active','marketing_status'=>'subscribed','merchant_marketing_status'=>'subscribed','marketing_sms'=>0],'sms','marketing');
cr_v120_assert(($consent['allowed']??true)===false,'SMS marketing requires explicit SMS opt-in');
$consent=campaigns_rewards_message_consent_v120(['status'=>'active','transactional_allowed'=>0],'email','transactional');
cr_v120_assert(($consent['allowed']??true)===false,'transactional opt-out is enforced');

$render=campaigns_rewards_render_message_v120('Hi {{name}} — {{campaign_name}}',['{{name}}'=>'Ada','{{campaign_name}}'=>'VIP']);
cr_v120_assert($render==='Hi Ada — VIP','message tokens render deterministically');

$manifest=campaigns_rewards_release_manifest_v120();$inv=$manifest['invariants']??[];
cr_v120_assert(($manifest['release']??'')==='Campaigns & Rewards V1.20','release manifest identifies V1.20');
cr_v120_assert(($inv['no_new_tables']??false)===true&&($inv['no_second_scheduler']??false)===true,'V1.20 reuses canonical tables and scheduler');
cr_v120_assert(($inv['marketing_consent_enforced_at_enqueue_and_send']??false)===true,'consent is enforced at enqueue and dispatch');
cr_v120_assert(($inv['agent_channel_is_notification_not_autonomous_action']??false)===true,'Agent channel is delivery only');
cr_v120_assert(($inv['payment_authority_added']??true)===false&&($inv['parallel_crm_added']??true)===false,'V1.20 adds neither payment authority nor parallel CRM');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v120_assert(in_array(($domain['implementation_status']??''),['integrated-v1.20','integrated-v1.21','integrated-v1.22','integrated-v1.23'],true),'cognitive domain declares integrated V1.20');
foreach(['campaign.message_saved','campaign.journey_queued','campaign.message_sent','campaign.message_converted'] as $event)
    cr_v120_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");

cr_v120_assert(VP3_CAMPAIGNS_REWARDS_V120==='vp3-campaigns-rewards-v120-20260923','V1.20 runtime build is pinned');
cr_v120_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V120==='vp3-campaigns-rewards-release-v120-20260923','V1.20 release build is pinned');
echo "CAMPAIGNS_REWARDS_V120=PASS\n";
