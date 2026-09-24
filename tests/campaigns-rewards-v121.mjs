import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const runtime=read('includes/campaigns-rewards-v121.php');
const release=read('includes/campaigns-rewards-release-v121.php');
const v118=read('includes/campaigns-rewards-v118.php');
const v119=read('includes/campaigns-rewards-v119.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const bootstrap=read('includes/bootstrap.php');
const campaigns=read('campaigns.php');
const config=read('config-example.php');
const webhook=read('api/campaign-delivery-webhook-v121.php');
const cron=read('cron/campaigns-rewards-v121.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V121.md');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageFlow=read('.github/workflows/production-deploy-package.yml');

const checks=[
 ['V1.21 creates no tables and reuses canonical delivery/message authority',!runtime.includes('CREATE TABLE')&&release.includes("'no_new_tables'=>true")&&runtime.includes('INSERT INTO campaign_deliveries')&&runtime.includes('INSERT INTO campaign_messages')],
 ['versioned journey nodes support message decision wait and exit',runtime.includes("'message'=>'Message'")&&runtime.includes("'decision'=>'Decision / branch'")&&runtime.includes("'wait_until'=>'Wait until'")&&runtime.includes("'exit'=>'Exit journey'")],
 ['decision conditions are allowlisted rather than executable expressions',runtime.includes('campaigns_rewards_journey_condition_fields_v121')&&!runtime.includes('eval(')&&!runtime.includes('condition_sql')],
 ['A/B assignment is deterministic and weighted',runtime.includes('campaigns_rewards_select_variant_v121')&&runtime.includes('variant_weight')&&runtime.includes("hash('sha256'")],
 ['journey instances use canonical delivery rows with idempotent node enqueue',runtime.includes("'journey.node.enqueue'")&&runtime.includes("'journey_instance_key'")&&runtime.includes('campaigns_rewards_idempotency_complete_v100')],
 ['conversion exits consult claim attribution before due-node execution',runtime.includes('campaigns_rewards_journey_instance_converted_v121')&&runtime.includes("'conversion_recorded'")&&runtime.includes('exit_on_conversion')],
 ['local time and quiet hours defer rather than drop deliveries',runtime.includes('campaigns_rewards_local_clock_utc_v121')&&runtime.includes('campaigns_rewards_next_allowed_send_v121')&&runtime.includes("'quiet_hours_deferred'")],
 ['bounded exponential retry and dead letter state are durable metadata',runtime.includes("'retry_wait'")&&runtime.includes("'dead_letter'")&&runtime.includes('2**max(0,$attempt-1)')&&runtime.includes('retry_max_attempts')],
 ['dead-letter replay is human capability gated',runtime.includes("campaigns_rewards_platform_assert_can_v100($pdo,$merchantId,$actorUserId,'campaigns.publish')")&&runtime.includes('campaigns_rewards_retry_dead_letters_v121')],
 ['SendGrid Mail Send adapter is configuration gated and bearer authenticated',runtime.includes("campaign_email_provider")&&runtime.includes("'/v3/mail/send'")&&runtime.includes("'Authorization: Bearer '.$apiKey")],
 ['Twilio SMS adapter uses canonical Messages endpoint and Basic auth',runtime.includes("api.twilio.com/2010-04-01/Accounts/")&&runtime.includes("'/Messages.json'")&&runtime.includes('$sid,$token')],
 ['Twilio callback verification is HMAC-SHA1 over exact callback URL and sorted form params',runtime.includes("hash_hmac('sha1'")&&runtime.includes('x-twilio-signature')&&webhook.includes('campaign_webhook_base_url')],
 ['SendGrid signed Event Webhook verifies timestamp plus raw bytes with ECDSA/SHA256',runtime.includes('x-twilio-email-event-webhook-signature')&&runtime.includes('openssl_verify($timestamp.$rawBody')&&webhook.includes("file_get_contents('php://input')")],
 ['provider webhook only updates Campaign delivery events and never issues Rewards',runtime.includes('campaigns_rewards_provider_event_v121')&&!webhook.includes('campaigns_rewards_issue_reward_v100')&&release.includes("'provider_webhooks_cannot_issue_rewards'=>true")],
 ['V1.18 public and V1.19 automation bridges prefer V1.21 while retaining V1.20 fallback',v118.includes('campaigns_rewards_journey_enqueue_v121')&&v119.includes('campaigns_rewards_journey_enqueue_v121')&&v118.includes('campaigns_rewards_journey_enqueue_v120')&&v119.includes('campaigns_rewards_journey_enqueue_v120')],
 ['Campaign workspace exposes orchestration node fields and dead-letter replay',campaigns.includes('node_type')&&campaigns.includes('condition_field')&&campaigns.includes('variant_weight')&&campaigns.includes('message_retry_dead_letters')],
 ['provider configuration is documented without shipping credentials',config.includes("'campaign_email_provider' => 'php_mail'")&&config.includes("'campaign_sms_provider' => ''")&&config.includes("'campaign_twilio_auth_token' => ''")&&config.includes("'campaign_sendgrid_api_key' => ''")],
 ['V1.21 cron is CLI-only and owns automation expiration and orchestration dispatch',cron.includes("PHP_SAPI!=='cli'")&&runtime.includes('campaigns_rewards_automation_run_due_v119')&&runtime.includes('campaigns_rewards_dispatch_due_v121')],
 ['Cognitive domain recognizes branch retry dead-letter and provider lifecycle events',(registry.includes("'implementation_status'=>'integrated-v1.21'")||(registry.includes("'implementation_status'=>'integrated-v1.22'")||(registry.includes("'implementation_status'=>'integrated-v1.23'")||(registry.includes("'implementation_status'=>'integrated-v1.24'")||(registry.includes("'implementation_status'=>'integrated-v1.25'")||registry.includes("'implementation_status'=>'integrated-v1.26'"))))))&&registry.includes("'campaign.journey_branch_selected'")&&registry.includes("'campaign.message_dead_lettered'")&&registry.includes("'campaign.provider_event_received'")],
 ['bootstrap loads V1.21 after V1.20 and release gate follows runtime',bootstrap.indexOf("campaigns-rewards-v120.php")<bootstrap.indexOf("campaigns-rewards-v121.php")&&bootstrap.indexOf("campaigns-rewards-v121.php")<bootstrap.indexOf("campaigns-rewards-release-v121.php")],
 ['existing consolidated CI runs V1.21 contracts and syntax',workflow.includes('Campaigns & Rewards V1.21 contract')&&workflow.includes('tests/campaigns-rewards-v121.mjs')&&workflow.includes('api/campaign-delivery-webhook-v121.php')],
 ['production package retains V1.21 runtime webhook and cron under the global VP3 release label',packageFlow.includes('"VP3 Cloud Production"')&&packageFlow.includes('campaigns-rewards-v121.php')&&packageFlow.includes('campaign-delivery-webhook-v121.php')&&packageFlow.includes('cron/campaigns-rewards-v121.php')],
 ['documentation preserves no-migration one-scheduler authority and provider security boundaries',docs.includes('adds **no database tables or columns**')&&docs.includes('no daemon or second scheduler')&&docs.includes('X-Twilio-Signature')&&docs.includes('timestamp + raw request bytes')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.21 contract: '+checks.length+'/'+checks.length+' passed');
