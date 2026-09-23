<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';

function v2600_assert(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$registry=vp3_cognitive_domain_registry_v2600();
v2600_assert(($registry['contract']??'')===VP3_COGNITIVE_DOMAIN_CONTRACT_V2600,'registry contract is versioned');
v2600_assert(($registry['authority']??'')==='validation_projection_only','registry is non-authoritative projection');
v2600_assert(isset($registry['domains']['campaigns_rewards']),'Campaigns & Rewards reference domain is present');

$campaign=$registry['domains']['campaigns_rewards'];
v2600_assert(str_starts_with((string)($campaign['implementation_status']??''),'integrated'),'Campaigns & Rewards reference contract is fulfilled by V1');
v2600_assert(!empty($campaign['plugin_catalog_registered']),'Campaigns & Rewards plugin catalog exposure follows implementation');
v2600_assert(in_array('campaigns_v100',$campaign['authority']??[],true),'Campaigns & Rewards declares its V1 business authority');
v2600_assert(in_array('campaign.conversion',$campaign['events'],true),'Campaign conversion is a declared canonical event');
v2600_assert(in_array('reward.claimed',$campaign['events'],true),'Reward claim outcome is a declared canonical event');

v2600_assert(vp3_cognitive_domain_for_event_v2600('campaigns_rewards','campaign.conversion')==='campaigns_rewards','campaign event resolves to reference domain');
v2600_assert(vp3_cognitive_current_state_domain_v2600('browser_operations','browser.transaction_changed')==='browser_operations','legacy browser source resolves to existing current-state domain');
v2600_assert(vp3_cognitive_current_state_domain_v2600('agent_chat','chat.message_sent')==='session','Agent Chat remains grouped into canonical session current state');
v2600_assert(vp3_cognitive_domain_event_class_v2600('campaigns_rewards','claim.failed')==='failure_recovery','failed claim is recovery-class');
v2600_assert(vp3_cognitive_domain_event_class_v2600('campaigns_rewards','campaign.conversion')==='outcome','campaign conversion is outcome-class');

$ref=vp3_cognitive_domain_entity_ref_v2600('campaigns_rewards','campaign','cmp_123','workspace');
v2600_assert(($ref['type']??'')==='campaign'&&($ref['id']??'')==='cmp_123','normalized entity reference preserves campaign identity');
v2600_assert(($ref['domain']??'')==='campaigns_rewards','normalized entity reference carries domain');

$prepared=vp3_cognitive_domain_prepare_event_v2600('campaigns_rewards','reward.claimed',[
    vp3_cognitive_domain_entity_ref_v2600('campaigns_rewards','reward','rw_1','workspace'),
    vp3_cognitive_domain_entity_ref_v2600('campaigns_rewards','reward_claim','cl_1','workspace'),
],['status'=>'claimed','secret_token'=>'must-not-survive']);
v2600_assert(!empty($prepared['accepted']),'known Campaigns & Rewards event is accepted for canonical ingress');
v2600_assert(($prepared['payload']['domain_contract']??'')===VP3_COGNITIVE_DOMAIN_CONTRACT_V2600,'prepared event carries v26.00 contract');
v2600_assert(($prepared['payload']['event_class']??'')==='outcome','prepared event carries classification');
v2600_assert(!isset($prepared['payload']['secret_token']),'forbidden payload key is removed before ingress');

$unknown=vp3_cognitive_domain_prepare_event_v2600('campaigns_rewards','campaign.magic_unregistered',[],[]);
v2600_assert(empty($unknown['accepted'])&&($unknown['disposition']??'')==='quarantined_before_ingress','unknown event is quarantined before canonical inbox');

$badRef=vp3_cognitive_domain_prepare_event_v2600('campaigns_rewards','campaign.created',[
    ['type'=>'commerce_order','id'=>'1','scope'=>'personal']
],[]);
v2600_assert(empty($badRef['accepted'])&&($badRef['reason']??'')==='malformed_domain_event','undeclared object reference is quarantined');

$integrity=vp3_cognitive_domain_registry_integrity_v2600();
v2600_assert(!empty($integrity['ok']),'domain registry integrity passes');
v2600_assert(($integrity['domain_count']??0)>=18,'existing v23.70 domains are retained');

echo "COGNITIVE_DOMAIN_REGISTRY_V2600=PASS\n";
