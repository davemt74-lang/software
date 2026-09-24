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
require_once __DIR__.'/../includes/campaigns-rewards-v121.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v120.php';
require_once __DIR__.'/../includes/campaigns-rewards-release-v121.php';

function cr_v121_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

cr_v121_assert(array_keys(campaigns_rewards_journey_node_types_v121())===['message','decision','wait_until','exit'],'V1.21 exposes four governed node types');
cr_v121_assert(campaigns_rewards_message_key_v121('Welcome','Email 1','B')==='welcome--email-1--b','variant message key is deterministic');
$a=campaigns_rewards_select_variant_v121([
 ['id'=>1,'template'=>['variant_key'=>'a','variant_weight'=>50]],
 ['id'=>2,'template'=>['variant_key'=>'b','variant_weight'=>50]],
],7,9,'instance','offer');
$b=campaigns_rewards_select_variant_v121([
 ['id'=>2,'template'=>['variant_key'=>'b','variant_weight'=>50]],
 ['id'=>1,'template'=>['variant_key'=>'a','variant_weight'=>50]],
],7,9,'instance','offer');
cr_v121_assert(($a['id']??0)===($b['id']??-1),'A/B assignment is stable independent of input order');
cr_v121_assert(campaigns_rewards_condition_compare_v121(120,'gte','100')===true,'numeric greater-than branch comparison works');
cr_v121_assert(campaigns_rewards_condition_compare_v121('VIP Customer','contains','vip')===true,'case-insensitive contains branch comparison works');

$base=strtotime('2026-09-23 05:00:00 UTC');
$allowed=campaigns_rewards_next_allowed_send_v121($base,'America/Phoenix',['enabled'=>true,'start'=>'21:00','end'=>'08:00','timezone'=>'America/Phoenix']);
cr_v121_assert($allowed>$base,'cross-midnight quiet hours defer a delivery');
$clock=campaigns_rewards_local_clock_utc_v121(strtotime('2026-09-23 12:00:00 UTC'),'America/Phoenix','09:30',0);
cr_v121_assert(gmdate('H:i',$clock)==='16:30','local-time scheduling converts to UTC deterministically');

$params=['MessageSid'=>'SM123','MessageStatus'=>'delivered'];
$sig=campaigns_rewards_twilio_signature_v121('https://example.com/hook',$params,'secret');
cr_v121_assert($sig===base64_encode(hash_hmac('sha1','https://example.com/hookMessageSidSM123MessageStatusdelivered','secret',true)),'Twilio signature helper follows URL plus sorted parameter HMAC-SHA1 contract');

$manifest=campaigns_rewards_release_manifest_v121();$inv=$manifest['invariants']??[];
cr_v121_assert(($manifest['release']??'')==='Campaigns & Rewards V1.21','release manifest identifies V1.21');
cr_v121_assert(($inv['no_new_tables']??false)===true&&($inv['no_second_scheduler']??false)===true&&($inv['no_second_queue']??false)===true,'V1.21 preserves one schema scheduler and queue authority');
cr_v121_assert(($inv['deterministic_weighted_ab_variants']??false)===true&&($inv['conversion_exits']??false)===true,'A/B and conversion-exit invariants are explicit');
cr_v121_assert(($inv['sendgrid_email_provider']??false)===true&&($inv['twilio_sms_provider']??false)===true,'SendGrid and Twilio provider adapters are declared');
cr_v121_assert(($inv['provider_webhooks_cannot_issue_rewards']??false)===true,'provider webhooks cannot gain Reward authority');

$domain=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
cr_v121_assert(in_array(($domain['implementation_status']??''),['integrated-v1.21','integrated-v1.22'],true),'cognitive domain declares integrated V1.21');
foreach(['campaign.journey_node_saved','campaign.journey_started','campaign.journey_branch_selected','campaign.message_retry_scheduled','campaign.message_dead_lettered','campaign.provider_event_received'] as $event)
    cr_v121_assert(in_array($event,$domain['events']??[],true),"cognitive domain registers {$event}");

cr_v121_assert(VP3_CAMPAIGNS_REWARDS_V121==='vp3-campaigns-rewards-v121-20260923','V1.21 runtime build is pinned');
cr_v121_assert(VP3_CAMPAIGNS_REWARDS_RELEASE_V121==='vp3-campaigns-rewards-release-v121-20260923','V1.21 release build is pinned');
echo "CAMPAIGNS_REWARDS_V121=PASS\n";
