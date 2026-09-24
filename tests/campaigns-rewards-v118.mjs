import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const types=read('includes/campaigns-rewards-types-v118.php');
const runtime=read('includes/campaigns-rewards-v118.php');
const schema=read('includes/campaigns-rewards-platform-v100.php');
const compat=read('includes/campaigns-rewards-v100.php');
const domain=read('includes/campaigns-rewards-domain-v100.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const campaigns=read('campaigns.php');
const publicPage=read('campaign.php');
const release=read('includes/campaigns-rewards-release-v118.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V118.md');
const bootstrap=read('includes/bootstrap.php');

const expectedTypes=[
  'signup','contest_giveaway','qr_reward_drop','referral','birthday_vip','agent_offer','social_engagement',
  'flash_drop','pre_purchase','win_back','local_event','ugc_story','loyalty','partner_offer','post_purchase',
  'make_good','customer_refund','promotional_goods','discount_voucher','training_education','public_donation'
];

const checks=[
 ['V1.18 loads before platform seeding and runtime loads after domain authority',
  bootstrap.indexOf("campaigns-rewards-types-v118.php")<bootstrap.indexOf("campaigns-rewards-platform-v100.php")
  &&bootstrap.indexOf("campaigns-rewards-domain-v100.php")<bootstrap.indexOf("campaigns-rewards-v118.php")],
 ['Microgifter-derived Campaign registry includes the expanded modular type matrix',
  expectedTypes.every(k=>types.includes("'"+k+"'"))],
 ['Signup Reward is newsletter acquisition with required consent and immediate Reward',
  types.includes("'signup'=>$t('Signup Reward','Acquisition'")
  &&types.includes("'newsletter_signup','immediate','required','Sign up & get reward'")],
 ['Contest winner, Event RSVP and proof campaigns gate Rewards until verification',
  types.includes("'contest_giveaway'=>$t('Contest / Giveaway'")
  &&types.includes("'contest_entry','after_verification'")
  &&types.includes("'local_event'=>$t('Local Event / RSVP'")
  &&types.includes("'event_rsvp','after_verification'")
  &&types.includes("'social_engagement'=>$t('Social Engagement'")
  &&types.includes("'proof_submit','after_verification'")],
 ['Birthday VIP and Pre-Purchase enroll now and issue on later triggers',
  types.includes("'birthday_signup','triggered'")&&types.includes("'interest_signup','triggered'")],
 ['System Campaign seed upserts behavior metadata for existing installations',
  schema.includes("campaigns_rewards_campaign_type_catalog_v118")
  &&schema.includes("UPDATE campaign_types SET name=?,description=?,base_handler_key=?")
  &&schema.includes("field_schema_json")&&schema.includes("reward_rules_schema_json")],
 ['Campaign editor groups Campaign Types by behavior category',
  campaigns.includes('$campaignTypesByCategory')&&campaigns.includes('<optgroup label="<?= e($category) ?>">')
  &&campaigns.includes('data-reward-timing')&&campaigns.includes('data-requires-reward')],
 ['Campaign editor attaches reusable Reward Products in the same save workflow',
  campaigns.includes('name="reward_selection_present"')&&campaigns.includes('name="reward_ids[]"')
  &&compat.includes("campaigns_rewards_sync_campaign_rewards_v118")],
 ['Campaign Reward sync is Merchant-scoped and active Campaign changes snapshot once',
  runtime.includes("SELECT id FROM reward_products WHERE id=? AND merchant_id=? AND is_active=1")
  &&runtime.includes("campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish')")
  &&runtime.includes('$snapshotActive')],
 ['Required-Reward Campaign Types cannot activate without an attached Reward',
  runtime.includes("Attach at least one active Reward before activating this Campaign Type.")
  &&domain.includes("campaigns_rewards_validate_campaign_activation_v118")],
 ['Merchant fulfillment queue completes triggered or verified participants through canonical issuance',
  runtime.includes('function campaigns_rewards_recent_enrollments_v118')
  &&runtime.includes('function campaigns_rewards_issue_enrollment_reward_v118')
  &&runtime.includes("'campaign_fulfillment'")
  &&campaigns.includes('Campaign fulfillment')
  &&campaigns.includes('Fulfill + Issue')],
 ['Fulfillment UI is gated by enrollment-management plus Reward-issuance authority',
  campaigns.includes("$canCampaignEnrollment=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'campaigns.enrollment.manage')")
  &&campaigns.includes("$canRewardIssue=$merchant?campaigns_rewards_platform_can_v100($pdo,$merchantId,$uid,'rewards.issue')")
  &&campaigns.includes('$canRewardIssue&&$canCampaignEnrollment')],
 ['Public landing delegates participation to V1.18 behavior runtime',
  publicPage.includes('campaigns_rewards_public_participate_v118')&&publicPage.includes('$behavior')
  &&publicPage.includes('$publicCopy')],
 ['Signup/public form supports consent birthday referral social and proof fields',
  publicPage.includes('name="marketing_consent"')&&publicPage.includes('name="birthday"')
  &&publicPage.includes('name="referral_ref"')&&publicPage.includes('name="social_handle"')
  &&publicPage.includes('name="proof_url"')],
 ['Newsletter consent is persisted after canonical public enrollment so it cannot be downgraded',
  runtime.indexOf('campaigns_rewards_public_enroll_v100')<runtime.indexOf("'marketing_status'=>$marketingStatus")],
 ['Public participation writes Core CRM and Merchant relationship rather than a parallel contact store',
  runtime.includes('campaigns_rewards_resolve_contact_v100')&&runtime.includes('campaigns_rewards_ensure_merchant_relationship_v100')
  &&runtime.includes('crm_contact_events_v1')],
 ['Immediate public behaviors issue canonical Reward Issuance and open Reward Inbox',
  runtime.includes('campaigns_rewards_issue_reward_v100')&&publicPage.includes('/reward-inbox.php')],
 ['Triggered and verification-gated public behaviors enroll without forcing immediate issuance',
  runtime.includes("'after_verification'=>'Your participation was submitted.")
  &&runtime.includes("'triggered'=>'You are enrolled.")],
 ['Landing telemetry is type-neutral and no longer labels every visit signup_started',
  compat.includes("'campaign.landing_viewed'")&&!compat.includes("'campaign.signup_started',['campaign_id'=>$campaignId]")],
 ['V1.18 Campaign behavior events are registered in the existing cognitive domain',
  /phase'=>'campaigns-rewards-v1\.(?:18|19|20)'/.test(registry)&&/implementation_status'=>'integrated-v1\.(?:18|19|20)'/.test(registry)
  &&registry.includes("'campaign.newsletter_signup'")&&registry.includes("'campaign.rewards_updated'")
  &&registry.includes("'campaign.event_rsvp'")],
 ['Release manifest preserves authority boundaries',
  release.includes("'signup_reward_is_newsletter_acquisition'=>true")
  &&release.includes("'payment_authority_added'=>false")
  &&release.includes("'parallel_crm_added'=>false")
  &&release.includes("'parallel_agent_brain_added'=>false")],
 ['Documentation captures Microgifter-derived type families and newsletter semantics',
  docs.includes('Microgifter')&&docs.includes('Signup Reward is specifically a newsletter/email-list acquisition flow')
  &&docs.includes('Local Event / RSVP')&&docs.includes('Training / Education')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.18 contract: '+checks.length+'/'+checks.length+' passed');
