import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const runtime=read('includes/campaigns-rewards-v120.php');
const v119=read('includes/campaigns-rewards-v119.php');
const domain=read('includes/campaigns-rewards-domain-v100.php');
const platform=read('includes/campaigns-rewards-platform-v100.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const campaigns=read('campaigns.php');
const cron=read('cron/campaigns-rewards-v120.php');
const release=read('includes/campaigns-rewards-release-v120.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V120.md');
const bootstrap=read('includes/bootstrap.php');
const config=read('config-example.php');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageFlow=read('.github/workflows/production-deploy-package.yml');

const checks=[
 ['V1.20 reuses canonical message/delivery/idempotency tables and creates no runtime tables',
  platform.includes('campaign_messages')&&platform.includes('campaign_deliveries')&&platform.includes('campaign_idempotency_keys')&&!runtime.includes('CREATE TABLE')],
 ['V1.20 runtime and release load after V1.19',
  bootstrap.indexOf("campaigns-rewards-v119.php")<bootstrap.indexOf("campaigns-rewards-v120.php")
  &&bootstrap.indexOf("campaigns-rewards-release-v119.php")<bootstrap.indexOf("campaigns-rewards-release-v120.php")],
 ['Journey catalog covers lifecycle and expiration messaging',
  ['birthday_trigger','crm_lapse','purchase_completed','referral_qualified','reward_expiring','manual'].every(k=>runtime.includes("'"+k+"'"))],
 ['Journey step edits create a new version and supersede the old definition',
  runtime.includes("status='superseded'")&&runtime.includes('MAX(version_no)')&&runtime.includes('$version=(int)$q->fetchColumn()+1')],
 ['Journey scheduling persists scheduled_for in campaign_deliveries metadata',
  runtime.includes("'scheduled_for'=>$scheduled")&&runtime.includes('INSERT INTO campaign_deliveries')],
 ['Journey enqueue uses canonical campaign idempotency authority',
  runtime.includes("campaigns_rewards_idempotency_begin_v100($pdo,$merchantId,'journey.enqueue'")&&runtime.includes('campaigns_rewards_idempotency_complete_v100')],
 ['Marketing consent is checked at enqueue and again during dispatch',
  runtime.includes('campaigns_rewards_message_consent_v120($contact')&&runtime.includes('campaigns_rewards_delivery_suppression_v120')],
 ['SMS is provider-neutral and requires an adapter rather than embedded credentials',
  runtime.includes("'sms'=>['status'=>'provider_unconfigured'")&&runtime.includes('campaigns_rewards_register_message_sender_v120')],
 ['Email is explicitly configuration-gated',
  runtime.includes("site_config('send_campaign_email',false)")&&config.includes("'send_campaign_email' => false")],
 ['Agent delivery uses VP3 notification presentation instead of autonomous Campaign mutation',
  runtime.includes("create_notification(")&&release.includes("'agent_channel_is_notification_not_autonomous_action'=>true")],
 ['V1.19 successful automation bridges to matching V1.20 journey trigger',
  v119.includes('campaigns_rewards_journey_enqueue_v120')&&v119.includes("(string)$rule['trigger_event']")],
 ['Reward expiration scan is canonical and idempotent',
  runtime.includes('function campaigns_rewards_queue_expiration_reminders_v120')&&runtime.includes("ri.expires_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL")],
 ['Claim flow attributes conversion to eligible Campaign delivery',
  domain.includes('campaigns_rewards_attribute_claim_v120')&&runtime.includes('campaign.message_converted')],
 ['Message performance reports delivery and conversion rates',
  runtime.includes('function campaigns_rewards_message_performance_v120')&&runtime.includes("'conversion_rate'")],
 ['Campaign workspace exposes Messaging & Journeys editor and performance',
  campaigns.includes('id="campaign-messaging"')&&campaigns.includes('message_save')&&campaigns.includes('Message performance')],
 ['V1.20 cron is CLI-only and runs V1.19 due automation plus reminders and dispatch',
  cron.includes("PHP_SAPI!=='cli'")&&runtime.includes('campaigns_rewards_automation_run_due_v119')&&runtime.includes('campaigns_rewards_dispatch_due_v120')],
 ['Cognitive domain recognizes V1.20 messaging lifecycle',
  (registry.includes("'implementation_status'=>'integrated-v1.20'")||(registry.includes("'implementation_status'=>'integrated-v1.21'")||(registry.includes("'implementation_status'=>'integrated-v1.22'")||(registry.includes("'implementation_status'=>'integrated-v1.23'")||(registry.includes("'implementation_status'=>'integrated-v1.24'")||registry.includes("'implementation_status'=>'integrated-v1.25','integrated-v1.26'"))))))&&registry.includes("'campaign.message_sent'")&&registry.includes("'campaign.message_converted'")],
 ['Release manifest preserves authority boundaries',
  release.includes("'no_new_tables'=>true")&&release.includes("'parallel_human_messaging_added'=>false")&&release.includes("'payment_authority_added'=>false")],
 ['Existing CI workflow runs V1.20 contracts without adding a workflow',
  workflow.includes('Campaigns & Rewards V1.20 contract')&&workflow.includes('tests/campaigns-rewards-v120.mjs')],
 ['Production package retains V1.20 inside V1.20+ releases and includes runtime/release/cron',
  (packageFlow.includes('"Campaigns & Rewards V1.20"')||(packageFlow.includes('"Campaigns & Rewards V1.21"')||(packageFlow.includes('"Campaigns & Rewards V1.22"')||(packageFlow.includes('"Campaigns & Rewards V1.23"')||(packageFlow.includes('"Campaigns & Rewards V1.24"')||packageFlow.includes('"Campaigns & Rewards V1.25"'))))))&&packageFlow.includes('campaigns-rewards-v120.php')&&packageFlow.includes('cron/campaigns-rewards-v120.php')],
 ['Documentation states no migration and V1.20 cron supersedes V1.19 campaign cron',
  docs.includes('No V1.20 database migration is required')&&docs.includes('supersedes the V1.19 Campaign cron entry')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.20 contract: '+checks.length+'/'+checks.length+' passed');
