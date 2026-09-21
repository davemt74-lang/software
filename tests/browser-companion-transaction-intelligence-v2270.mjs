import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const intelligence=read('includes/browser-transaction-intelligence-v2270.php');
const api=read('api/extension-transaction-intelligence-v2270.php');
const continuity=read('includes/browser-transaction-continuity-v2260.php');
const safety=read('includes/browser-transaction-safety-v2240.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

must(manifest.version==='22.7.0','v22.70 extension version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '22.7.0';"),'v22.70 background version missing');
must(manifest.description.includes('Transaction Intelligence & Exception Management'),'v22.70 manifest description missing');

for(const table of [
  'browser_transaction_intelligence_facts_v2270',
  'browser_transaction_intelligence_cases_v2270',
  'browser_transaction_recovery_proposals_v2270',
  'browser_transaction_intelligence_receipts_v2270'
]) must(intelligence.includes(table),'missing v22.70 schema '+table);

must(intelligence.includes("require_once __DIR__.'/browser-transaction-continuity-v2260.php';"),'v22.70 must build on v22.60 continuity');
must(api.includes("'authority_source'=>'v22.60_reference_only_continuity'"),'v22.60 authority declaration missing');
must(api.includes("'external_write_requires_fresh_v2240'=>true"),'fresh v22.40 authorization boundary missing');
must(api.includes("'automatic_external_writes'=>false"),'automatic external write prohibition missing');
must(api.includes("'raw_page_text_persisted'=>false"),'raw page text privacy declaration missing');
must(api.includes("'raw_url_persisted'=>false"),'raw URL privacy declaration missing');
must(api.includes("'raw_reference_values_persisted'=>false"),'raw reference privacy declaration missing');
must(api.includes("'cross_transaction_signals_are_advisory'=>true"),'advisory cross-transaction declaration missing');

must(background.includes("browserTransactionIntelligenceApiV2270('observe_facts'"),'matched v22.60 observation must feed v22.70');
must(background.includes('schedule_candidates:Array.isArray(local.schedule_candidates)'), 'normalized schedule facts handoff missing');
must(background.includes('exposure_candidates:Array.isArray(local.exposure_candidates)'), 'normalized financial facts handoff missing');
must(background.includes("event_id:String(response.event&&response.event.event_id||'')"),'v22.70 facts must bind to the exact matched v22.60 event');
must(intelligence.includes("hash_equals((string)$event['public_id'],$eventId)")&&intelligence.includes("empty($event['reference_match'])"),'v22.70 server event authority check missing');
must(!api.includes("$input['body_text']")&&!api.includes("$input['page_text']"),'v22.70 API must not accept raw page text fields');

for(const type of [
  'action_required','deadline_due','deadline_soon','fulfillment_exception','cancellation_change',
  'application_rejected','schedule_change','financial_change','potential_schedule_conflict',
  'potential_duplicate_transaction','response_received','stale_order','application_stale',
  'response_overdue','account_pending'
]) must(intelligence.includes("'"+type+"'"),'missing exception type '+type);

for(const band of ['urgent','high','medium','low'])must(intelligence.includes("'"+band+"'"),'missing priority band '+band);
must(intelligence.includes('priority_score'),'priority score storage missing');
must(intelligence.includes('consequence_level'),'consequence integration missing');
must(intelligence.includes('deadline_within_24h')&&intelligence.includes('deadline_within_72h'),'deadline reasoning missing');

must(intelligence.includes("'potential_schedule_conflict'"),'schedule conflict advisory missing');
must(intelligence.includes("'potential_duplicate_transaction'"),'duplicate advisory missing');
must(intelligence.includes("'same_domain_family_amount_within_6h'"),'duplicate review evidence boundary missing');
must(intelligence.includes("'booking_times_within_2h'"),'schedule conflict review boundary missing');

for(const recovery of [
  'prepare_reschedule','prepare_support_request','prepare_followup','prepare_required_followup',
  'prepare_reply','prepare_status_request','review_duplicate','review_transaction'
]) must(intelligence.includes("'"+recovery+"'"),'missing recovery proposal '+recovery);
must(intelligence.includes("authorization_path VARCHAR(20) NOT NULL DEFAULT 'v22.40'"),'proposal v22.40 handoff missing');
must(intelligence.includes("in_array($action,['acknowledge','dismiss'],true)"),'case/proposal action must remain acknowledge/dismiss only');
must(!intelligence.includes('apply_external')&&!intelligence.includes('execute_external'),'v22.70 must not execute external writes');

must(intelligence.includes("create_notification("),'Agent notification integration missing');
must(intelligence.includes("'browser_transaction_needs_attention'"),'Agent Voice compatible notification category missing');
must(intelligence.includes("vp3_browser_runtime_event_v2200"),'Cognitive Runtime event integration missing');
must(intelligence.includes("'transaction_exception_detected'"),'transaction exception runtime event missing');

must(intelligence.includes("vp3_browser_intelligence_resolve_closed_v2270"),'explicit closure cleanup missing');
must(intelligence.includes("status='resolved'"),'resolved exception history missing');
must(intelligence.includes('active attention was removed while history was retained'),'closure receipt missing');
must(intelligence.includes('vp3_browser_intelligence_resolve_absent_v2270'),'stale exception cleanup missing');
must(intelligence.includes('exception_signal_cleared'),'signal-cleared receipt missing');
must(intelligence.includes("SET status='dismissed',resolved_at=UTC_TIMESTAMP()")||intelligence.includes("status='dismissed'"),'stale proposal cleanup missing');

for(const id of [
  'runtimeIntelligencePanel','runtimeIntelligenceCount','runtimeIntelligenceSummary',
  'runtimeIntelligenceExposure','runtimeIntelligenceCases','runtimeIntelligenceRefreshBtn'
]) must(html.includes('id="'+id+'"'),'missing v22.70 UI '+id);
must(css.includes('.runtime-intelligence-panel')&&css.includes('.runtime-intelligence-case'),'v22.70 styling missing');
must(panel.includes('function renderRuntimeIntelligenceV2270'),'v22.70 inbox renderer missing');
must(panel.includes('async function loadRuntimeIntelligenceV2270'),'v22.70 inbox loader missing');
must(panel.includes('intelligenceCaseActionV2270'),'v22.70 case action missing');
must(panel.includes('intelligenceProposalActionV2270'),'v22.70 proposal action missing');
must(panel.includes('fresh v22.40 authorization required'),'v22.40 UI handoff disclosure missing');

must(workflows.includes("browser-transaction-intelligence-v2270.php"),'Agent Workflows v22.70 include missing');
must(workflows.includes('Transaction Intelligence & Exception Management'),'Agent Workflows v22.70 panel missing');
must(workflows.includes('browser_intelligence_ack')&&workflows.includes('browser_intelligence_dismiss'),'durable exception actions missing');
must(workflows.includes('browser_intelligence_proposal_ack')&&workflows.includes('browser_intelligence_proposal_dismiss'),'durable recovery proposal actions missing');

must(upgrade.includes("require_once __DIR__ . '/includes/browser-transaction-intelligence-v2270.php';"),'upgrade v22.70 include missing');
must(upgrade.includes('vp3_browser_intelligence_schema_ready_v2270()'),'upgrade v22.70 readiness missing');
must(upgrade.includes('vp3_browser_intelligence_ensure_schema_v2270();'),'upgrade v22.70 install missing');

must(background.includes("authorizedFetch('/api/extension-transaction-intelligence-v2270.php'"),'v22.70 API adapter missing');
must(background.includes("case 'transaction_intelligence_inbox'"),'v22.70 inbox route missing');
must(background.includes("case 'transaction_intelligence_action'"),'v22.70 action route missing');

// Retained architectural chain.
must(safety.includes('exact locally-reviewed form state by hash'),'v22.40 safety chain changed unexpectedly');
must(continuity.includes('Any new')||continuity.includes('consequential external write'),'v22.60 write boundary missing');

console.log('VP3 Browser Companion Transaction Intelligence & Exception Management v22.70 contract passed.');
