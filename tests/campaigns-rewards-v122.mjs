import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const runtime=read('includes/campaigns-rewards-v122.php');
const release=read('includes/campaigns-rewards-release-v122.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const bootstrap=read('includes/bootstrap.php');
const campaigns=read('campaigns.php');
const cron=read('cron/campaigns-rewards-v122.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V122.md');
const workflow=read('.github/workflows/team-workspaces-v350.yml');
const packageFlow=read('.github/workflows/production-deploy-package.yml');

const checks=[
 ['V1.22 adds no tables or parallel analytics ledger',!runtime.includes('CREATE TABLE')&&release.includes("'no_new_tables'=>true")&&release.includes("'no_second_analytics_ledger'=>true")],
 ['templates cover signup purchase win-back expiration and referral',runtime.includes("'signup_welcome'")&&runtime.includes("'post_purchase'")&&runtime.includes("'win_back'")&&runtime.includes("'reward_expiration'")&&runtime.includes("'referral_followup'")],
 ['template application creates draft nodes and unique journey keys',runtime.includes("'status'=>'draft'")&&runtime.includes('campaigns_rewards_unique_template_journey_key_v122')&&release.includes("'templates_create_drafts_only'=>true")],
 ['path analytics derive from canonical campaign_deliveries',runtime.includes('campaigns_rewards_analytics_rows_v122')&&runtime.includes('FROM campaign_deliveries')&&runtime.includes("'dropoff_rate'")],
 ['drop-off uses journey instance continuation rather than raw click counts',runtime.includes("'journey_instance_key'")&&runtime.includes('array_intersect($source,array_keys($continued))')],
 ['A/B comparison is outcome-derived and does not rewrite weights',runtime.includes('campaigns_rewards_ab_comparison_v122')&&runtime.includes("'conversion_rate'")&&!runtime.includes('UPDATE campaign_messages SET template_json=JSON_SET')],
 ['send-time signal requires verified sent state and minimum samples',runtime.includes("status IN ('sent','delivered','viewed')")&&runtime.includes('optimization_min_samples')&&release.includes("'send_time_requires_verified_samples'=>true")],
 ['send-time optimization is opt-in and defers a canonical delivery',runtime.includes("'optimize_send_time'")&&runtime.includes('campaigns_rewards_apply_send_time_optimization_v122')&&runtime.includes("'send_time_optimization_applied'")],
 ['frequency and fatigue controls count successful Merchant contact/channel sends',runtime.includes('campaigns_rewards_frequency_gate_v122')&&runtime.includes("'frequency_cap_24h'")&&runtime.includes("'fatigue_window_days'")&&runtime.includes('c.merchant_id=? AND d.contact_id=? AND d.channel=?')],
 ['simulation is a bounded dry run with loop detection',runtime.includes('campaigns_rewards_simulate_journey_v122')&&runtime.includes('$i<50')&&runtime.includes("'loop_detected'")&&runtime.includes("'dry_run'=>true")],
 ['Agent recommendations use existing campaign_agent_recommendations authority',runtime.includes('INSERT INTO campaign_agent_recommendations')&&runtime.includes("'auto_apply'=>false")&&runtime.includes('campaigns_rewards_review_recommendation_v122')],
 ['human recommendation review does not mutate journey nodes',runtime.includes("['accepted','dismissed']")&&release.includes("'recommendations_never_auto_apply'=>true")],
 ['Campaign workspace exposes template simulation intelligence and optimization controls',campaigns.includes('journey_template_apply')&&campaigns.includes('journey_simulate')&&campaigns.includes('optimize_send_time')&&campaigns.includes('Journey intelligence')],
 ['Cognitive domain advances to V1.22 optimization lifecycle',(registry.includes("'implementation_status'=>'integrated-v1.22'")||(registry.includes("'implementation_status'=>'integrated-v1.23'")||(registry.includes("'implementation_status'=>'integrated-v1.24'")||registry.includes("'implementation_status'=>'integrated-v1.25'"))))&&registry.includes("'campaign.journey_send_time_optimized'")&&registry.includes("'campaign.journey_recommendation_reviewed'")],
 ['bootstrap loads V1.22 after V1.21',bootstrap.indexOf("campaigns-rewards-v121.php")<bootstrap.indexOf("campaigns-rewards-v122.php")&&bootstrap.indexOf("campaigns-rewards-v122.php")<bootstrap.indexOf("campaigns-rewards-release-v122.php")],
 ['V1.22 cron is CLI-only and runs optimized dispatch plus recommendation refresh',cron.includes("PHP_SAPI!=='cli'")&&runtime.includes('campaigns_rewards_dispatch_due_v122')&&runtime.includes('campaigns_rewards_refresh_optimization_recommendations_v122')],
 ['existing consolidated CI runs V1.22 contracts',workflow.includes('Campaigns & Rewards V1.22 contract')&&workflow.includes('tests/campaigns-rewards-v122.mjs')],
 ['production package advances to V1.22 and includes runtime release and cron',(packageFlow.includes('"Campaigns & Rewards V1.22"')||(packageFlow.includes('"Campaigns & Rewards V1.23"')||(packageFlow.includes('"Campaigns & Rewards V1.24"')||packageFlow.includes('"Campaigns & Rewards V1.25"'))))&&packageFlow.includes('campaigns-rewards-v122.php')&&packageFlow.includes('cron/campaigns-rewards-v122.php')],
 ['documentation explicitly preserves dry-run and human-review boundaries',docs.includes('**no database tables or columns**')&&docs.includes('dry-run')&&docs.includes('does **not** mutate journey configuration')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.22 contract: '+checks.length+'/'+checks.length+' passed');
