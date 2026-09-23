<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.18 system Campaign Type registry.
 *
 * The registry carries forward the modular Microgifter campaign model while
 * keeping VP3 Core authoritative for CRM, Team, messaging and cognition.
 */
const VP3_CAMPAIGNS_REWARDS_TYPES_V118='vp3-campaigns-rewards-types-v118-20260923';

function campaigns_rewards_campaign_type_catalog_v118(): array
{
    $t=static function(
        string $name,string $category,string $description,string $handler,bool $public,
        string $publicAction,string $rewardTiming,string $marketing,string $cta,
        array $requiredFields=[],bool $requiresReward=true,bool $cases=false,bool $automation=true,bool $agent=true,
        string $trigger='manual'
    ): array {
        return [
            'name'=>$name,'category'=>$category,'description'=>$description,'handler'=>$handler,
            'public'=>$public,'existing_contacts'=>true,'cases'=>$cases,'automation'=>$automation,'agent'=>$agent,
            'public_action'=>$publicAction,'reward_timing'=>$rewardTiming,'marketing'=>$marketing,
            'default_cta'=>$cta,'required_fields'=>$requiredFields,'requires_reward'=>$requiresReward,'trigger'=>$trigger,
        ];
    };

    return [
        'signup'=>$t('Signup Reward','Acquisition','Newsletter/email-list signup that creates or updates the CRM contact, records marketing consent and immediately issues the attached welcome Reward.','public_signup',true,'newsletter_signup','immediate','required','Sign up & get reward',['name','email'],true,false,true,true,'public_submit'),
        'contest_giveaway'=>$t('Contest / Giveaway','Acquisition','Public contest entry with CRM acquisition and an optional participation Reward. Winner selection remains a separate governed outcome.','contest',true,'contest_entry','immediate_if_attached','optional','Enter giveaway',['name','email'],false,false,true,true,'public_submit'),
        'qr_reward_drop'=>$t('QR Reward Drop','Acquisition','QR/link-driven public acquisition that issues an attached Reward after the visitor identifies themselves.','qr_drop',true,'qr_claim','immediate','optional','Claim reward',['name','email'],true,false,true,true,'qr_scan'),
        'referral'=>$t('Referral Reward','Growth','Referral landing flow that preserves the referral reference, acquires the referred contact and issues the configured Reward.','referral',true,'referral_signup','immediate','optional','Accept referral reward',['name','email','referral_ref'],true,false,true,true,'referral_link'),
        'birthday_vip'=>$t('Birthday / VIP Club','Lifecycle','Birthday or VIP club enrollment. Contact joins the CRM program now; Reward issuance is intended for the birthday/VIP trigger.','birthday_vip',true,'birthday_signup','triggered','required','Join the club',['name','email','birthday'],false,false,true,true,'birthday_trigger'),
        'agent_offer'=>$t('Agent Offer','Growth','Agent-discoverable offer for an existing CRM contact. The Agent may propose or issue through governed Campaign authority.','agent_offer',false,'none','manual','none','View offer',[],true,false,true,true,'agent_action'),
        'social_engagement'=>$t('Social Engagement','Engagement','Social follow/share/engagement campaign. Public participation is recorded for verification before the Reward is issued.','social_engagement',true,'proof_submit','after_verification','optional','Submit activity',['name','email','social_handle','proof_url'],true,true,true,true,'proof_approved'),
        'flash_drop'=>$t('Flash / Limited-Time Drop','Acquisition','Short-window public Reward drop with normal Campaign caps, inventory limits and per-contact limits.','flash_drop',true,'instant_claim','immediate','optional','Claim drop',['name','email'],true,false,true,true,'public_submit'),
        'pre_purchase'=>$t('Pre-Purchase / Interest','Commerce','Captures future purchase interest into CRM. Reward is issued by the configured commerce/approval trigger rather than at signup.','pre_purchase',true,'interest_signup','triggered','required','Join early access',['name','email'],false,false,true,true,'commerce_event'),
        'win_back'=>$t('Win Back','Lifecycle','Targets existing inactive/lapsed CRM contacts and issues a governed return incentive.','win_back',false,'none','triggered','none','View offer',[],true,false,true,true,'crm_lapse'),
        'local_event'=>$t('Local Event / RSVP','Engagement','Public RSVP campaign. Attendance is confirmed before the configured Reward is issued.','event_rsvp',true,'event_rsvp','after_verification','optional','RSVP',['name','email'],false,true,true,true,'attendance_confirmed'),
        'ugc_story'=>$t('UGC / Story','Engagement','Collects a story, review, photo/video link or other user-generated proof for approval before rewarding.','ugc_story',true,'proof_submit','after_verification','optional','Submit story',['name','email','proof_url'],true,true,true,true,'proof_approved'),
        'loyalty'=>$t('Loyalty / Milestone','Loyalty','Existing-customer loyalty, tier or milestone campaign tied to the canonical Loyalty ledger.','loyalty',false,'none','triggered','none','View reward',[],true,false,true,true,'loyalty_milestone'),
        'partner_offer'=>$t('Partner / Cross-Merchant','Growth','Public partner offer that attributes the acquisition source while issuing the configured Reward.','partner_offer',true,'partner_signup','immediate','optional','Unlock partner reward',['name','email'],true,false,true,true,'public_submit'),
        'post_purchase'=>$t('Post Purchase','Commerce','Issues or schedules a Reward after a verified commerce purchase event.','post_purchase',false,'none','triggered','none','View reward',[],true,false,true,true,'purchase_completed'),
        'make_good'=>$t('Make Good','Service','Customer-service recovery campaign/case for issuing a governed make-good Reward to an existing CRM contact.','make_good',false,'none','manual','none','View reward',[],true,true,false,true,'case_opened'),
        'customer_refund'=>$t('Customer Refund / Recovery','Service','Campaign-backed refund/recovery voucher flow for an existing CRM contact; no payment mutation is performed by Campaigns.','customer_refund',false,'none','manual','none','View recovery reward',[],true,true,false,true,'case_opened'),
        'promotional_goods'=>$t('Promotional Goods','Acquisition','Public promotional-product distribution with inventory and Campaign limits enforced before issuance.','promotional_goods',true,'instant_claim','immediate','optional','Get promotional reward',['name','email'],true,false,true,true,'public_submit'),
        'discount_voucher'=>$t('Discount Voucher','Acquisition','Public acquisition campaign that issues an attached discount voucher/certificate.','discount_voucher',true,'instant_claim','immediate','optional','Get voucher',['name','email'],true,false,true,true,'public_submit'),
        'training_education'=>$t('Training / Education','Engagement','Action-based education/training campaign. Completion or approved proof gates Reward issuance.','training_education',false,'none','after_verification','none','Start',[],true,true,true,true,'completion_verified'),
        'public_donation'=>$t('Public Donation / Community Reward','Community','Public/community allocation campaign using governed Reward inventory. Allocation is tracked separately from a normal purchase.','public_donation',true,'community_signup','triggered','optional','Join campaign',['name','email'],false,true,true,true,'allocation_approved'),
    ];
}

function campaigns_rewards_campaign_type_definition_v118(string $typeKey): ?array
{
    $typeKey=strtolower(trim($typeKey));
    $catalog=campaigns_rewards_campaign_type_catalog_v118();
    return isset($catalog[$typeKey])?$catalog[$typeKey]+['type_key'=>$typeKey]:null;
}

function campaigns_rewards_campaign_type_public_copy_v118(string $typeKey): array
{
    $d=campaigns_rewards_campaign_type_definition_v118($typeKey)??[];
    return [
        'action'=>(string)($d['public_action']??'signup'),
        'cta'=>(string)($d['default_cta']??'Continue'),
        'marketing'=>(string)($d['marketing']??'optional'),
        'required_fields'=>(array)($d['required_fields']??['name','email']),
        'reward_timing'=>(string)($d['reward_timing']??'immediate'),
        'requires_reward'=>!empty($d['requires_reward']),
    ];
}
