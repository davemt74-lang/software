import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const runtime=read('includes/campaigns-rewards-v119.php');
const v118=read('includes/campaigns-rewards-v118.php');
const domain=read('includes/campaigns-rewards-domain-v100.php');
const platform=read('includes/campaigns-rewards-platform-v100.php');
const commerce=read('includes/agent-commerce-v800-part4.php');
const campaigns=read('campaigns.php');
const cron=read('cron/campaigns-rewards-v119.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const release=read('includes/campaigns-rewards-release-v119.php');
const docs=read('docs/CAMPAIGNS_REWARDS_V119.md');
const bootstrap=read('includes/bootstrap.php');

const checks=[
 ['V1.19 reuses existing durable automation authorities',platform.includes('campaign_automation_rules')&&platform.includes('campaign_rule_executions')&&!runtime.includes('CREATE TABLE')],
 ['V1.19 runtime loads after V1.18 and release gate is bootstrapped',bootstrap.indexOf("campaigns-rewards-v118.php")<bootstrap.indexOf("campaigns-rewards-v119.php")&&bootstrap.includes("campaigns-rewards-release-v119.php")],
 ['Trigger catalog covers scheduled event and governed manual lifecycle triggers',[
   'birthday_trigger','crm_lapse','purchase_completed','referral_qualified','winner_selected','attendance_confirmed','proof_approved','loyalty_milestone','product_available','allocation_approved','agent_action','manual'
 ].every(k=>runtime.includes("'"+k+"'"))],
 ['Automation audiences reuse canonical CRM and saved CRM segments',runtime.includes("'saved_segment'=>'Saved CRM segment'")&&runtime.includes('crm_segment_members')&&runtime.includes('crm_merchant_relationships')],
 ['Active automation requires publish and Reward issue authority',runtime.includes("'campaigns.publish'")&&runtime.includes("'rewards.issue'")&&runtime.includes('Activate the Campaign before activating its automation.')],
 ['Automated Reward issuance is explicitly validated by rule before canonical issue engine runs',domain.includes("$automationFlow=$actorType==='automation'")&&domain.includes('campaigns_rewards_automation_validate_issue_v119')&&runtime.includes('campaigns_rewards_issue_reward_v100')],
 ['Event-driven automation is constrained to the event contact before audience eligibility',runtime.includes("($triggerMeta['mode']??'')==='event'&&$eventContact>0")&&runtime.includes('campaigns_rewards_automation_contact_matches_v119')],
 ['Broad scheduled audience evaluation remains available for birthday and win-back',runtime.includes("campaigns_rewards_automation_run_trigger_v119($pdo,'birthday_trigger'")&&runtime.includes("campaigns_rewards_automation_run_trigger_v119($pdo,'crm_lapse'")],
 ['Commerce paid orders bridge into purchase_completed without moving payment authority',commerce.includes("campaigns_rewards_automation_external_event_v119($pdo,'purchase_completed'")&&commerce.includes("payment_status']??'')==='paid'")],
 ['Loyalty positive adjustments bridge into milestone automation with balance evidence',domain.includes("campaigns_rewards_automation_run_trigger_v119($pdo,'loyalty_milestone'")&&domain.includes("'balance'=>$balance")],
 ['Referral references resolve to canonical CRM and persist referrer lineage',runtime.includes('function campaigns_rewards_referral_referrer_v119')&&v118.includes("'referrer_contact_id'=>$referrerContactId?:null")],
 ['Referral action supports participant referrer or both recipients',runtime.includes("['event_contact','referrer','both']")&&runtime.includes("return array_values(array_unique(array_merge($base,[$referrer])))")],
 ['Rule execution is durable idempotent concurrent-safe and failed runs can retry',runtime.includes('INSERT IGNORE INTO campaign_rule_executions')&&runtime.includes("if($existing&&$existing['status']==='failed')")&&runtime.includes("'automation:'.$key")],
 ['Cooldown avoids dynamic INTERVAL SQL and birthday windows are year-boundary safe',runtime.includes("$cutoff=gmdate('Y-m-d H:i:s'")&&runtime.includes('foreach([$year-1,$year,$year+1] as $candidateYear)')],
 ['CLI due runner is CLI-only and uses existing cron pattern',cron.includes("PHP_SAPI!=='cli'")&&cron.includes('campaigns_rewards_automation_run_due_v119')&&!runtime.includes('sleep(')],
 ['Campaign funnel covers viewed participated qualified issued viewed sent and claimed',[
   "'views'=>$views","'participated'=>$participated","'qualified'=>$qualified","'issued'=>$issued","'reward_viewed'=>$rewardViewed","'sent'=>$sent","'claimed'=>$claimed"
 ].every(x=>runtime.includes(x))],
 ['Lifecycle intelligence persists human-review recommendations without auto activation',runtime.includes('campaign_agent_recommendations')&&runtime.includes("'requires_human_decision'=>true")&&!runtime.includes("UPDATE campaign_automation_rules SET status='active'")],
 ['Campaign workspace exposes automation editor CRM segment funnel insights and due evaluation',campaigns.includes('id="campaign-automation"')&&campaigns.includes('Saved CRM segment')&&campaigns.includes('cr-funnel')&&campaigns.includes('Evaluate due rules')],
 ['Merchant verification queue can emit governed lifecycle events into active automation',campaigns.includes('name="action" value="automation_event"')&&campaigns.includes('Verify + trigger')],
 ['V1.19 cognitive domain declares automation execution and recommendation events',(registry.includes("'phase'=>'campaigns-rewards-v1.19'")||(registry.includes("'phase'=>'campaigns-rewards-v1.20'")||(registry.includes("'phase'=>'campaigns-rewards-v1.21'")||(registry.includes("'phase'=>'campaigns-rewards-v1.22'")||(registry.includes("'phase'=>'campaigns-rewards-v1.23'")||(registry.includes("'phase'=>'campaigns-rewards-v1.24'")||(registry.includes("'phase'=>'campaigns-rewards-v1.25'")||registry.includes("'phase'=>'campaigns-rewards-v1.26'"))))))))&&(registry.includes("'implementation_status'=>'integrated-v1.19'")||(registry.includes("'implementation_status'=>'integrated-v1.20'")||(registry.includes("'implementation_status'=>'integrated-v1.21'")||(registry.includes("'implementation_status'=>'integrated-v1.22'")||(registry.includes("'implementation_status'=>'integrated-v1.23'")||(registry.includes("'implementation_status'=>'integrated-v1.24'")||(registry.includes("'implementation_status'=>'integrated-v1.25'")||registry.includes("'implementation_status'=>'integrated-v1.26'"))))))))&&registry.includes("'campaign.automation_executed'")&&registry.includes("'campaign.recommendation_proposed'")],
 ['Release manifest preserves no second scheduler CRM wallet or payment authority',release.includes("'no_second_scheduler'=>true")&&release.includes("'parallel_crm_added'=>false")&&release.includes("'parallel_wallet_added'=>false")&&release.includes("'payment_authority_added'=>false")],
 ['V1.19 documentation captures trigger safety CRM audiences referral and human decision boundary',docs.includes('Event safety')&&docs.includes('CRM audiences')&&docs.includes('Referral lifecycle')&&docs.includes('requires_human_decision=true')],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Campaigns & Rewards V1.19 contract: '+checks.length+'/'+checks.length+' passed');
