import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const schema=read('includes/browser-research-schema-v2230.php');
const runtime=read('includes/browser-research-runtime-v2230.php');
const save=read('includes/browser-research-save-v2230.php');
const api=read('api/extension-research-agent-v2230.php');
const delegation=read('includes/browser-delegation-v2190.php');
const browserRuntime=read('includes/browser-agent-runtime-v2200.php');
const multi=read('includes/browser-multisite-v2220.php');
const chat=read('includes/agent-chat-runtime-v2160.php');
const research=read('includes/research-projects-v2060.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

must(manifest.version==='22.3.0','v22.30 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '22.3.0';"),'v22.30 request version missing');
must(!manifest.permissions.includes('cookies'),'Browser Research must not request cookies permission');
must(background.includes("authorizedFetch('/api/extension-research-agent-v2230.php'"),'Browser Research API adapter missing');
must(background.includes("case 'research_action'"),'Browser Research message route missing');
must(background.includes("case 'research_analyze_current'"),'current-page research route missing');

// Explicit authority: v21.90 delegation -> v22.00 runtime -> v22.20 sources -> v22.30 mission.
must(delegation.includes("'browser_research'"),'Browser Research delegation action missing');
must(delegation.includes("'action_key'=>'browser_research'"),'canonical Browser Research checkpoint missing');
must(html.includes('data-delegation-action="browser_research"'),'explicit Browser Research authority checkbox missing');
must(browserRuntime.includes("'browser_research'=>["),'v22.00 Browser Research skill missing');
must(browserRuntime.includes("'label'=>'Run Browser Research mission'"),'Browser Research runtime skill label missing');
must(runtime.includes("!in_array('browser_research',$actions,true)"),'mission authority gate missing');
must(runtime.includes('vp3_browser_multisite_row_v2220'),'v22.20 source envelope dependency missing');
must(runtime.includes('vp3_browser_multisite_policies_v2220'),'approved domain policy derivation missing');
must(runtime.includes("policy_mode']??'')==='blocked'"),'blocked domains must be excluded from research source plan');
must(api.includes("'authority_source'=>'v21.90_delegation'"),'v21.90 authority declaration missing');
must(api.includes("'runtime_source'=>'v22.00_browser_agent_runtime'"),'v22.00 runtime declaration missing');
must(api.includes("'source_transport'=>'v22.20_multisite'"),'v22.20 source transport declaration missing');
must(api.includes("'research_source'=>'v22.30_browser_research_agent'"),'v22.30 source declaration missing');

// Mission budgets.
must(schema.includes('VP3_BROWSER_RESEARCH_MAX_SOURCES_V2230=5'),'source hard cap missing');
must(schema.includes('VP3_BROWSER_RESEARCH_MAX_PAGES_V2230=12'),'page hard cap missing');
must(schema.includes('VP3_BROWSER_RESEARCH_MAX_CLAIMS_V2230=60'),'claim hard cap missing');
must(schema.includes('VP3_BROWSER_RESEARCH_MAX_EVIDENCE_V2230=700'),'evidence excerpt hard cap missing');
must(schema.includes('VP3_BROWSER_RESEARCH_MAX_DURATION_V2230=120'),'duration hard cap missing');
must(runtime.includes('This Browser Runtime already has an active Research mission.'),'one active mission per runtime gate missing');
must(runtime.includes('This Research mission reached its bounded page limit.'),'page budget enforcement missing');
must(runtime.includes('This Research mission reached its bounded claim limit.'),'claim budget enforcement missing');

// Durable schema: structured evidence, not browser history/raw pages.
for(const table of ['browser_research_missions_v2230','browser_research_pages_v2230','browser_research_claims_v2230','browser_research_evidence_v2230']){
  must(schema.includes('CREATE TABLE IF NOT EXISTS '+table),'missing Browser Research table '+table);
}
const schemaLower=schema.toLowerCase();
for(const forbidden of ['page_url varchar','raw_url','page_text text','page_text mediumtext','browser_history','cookie_value','password_value','access_token','session_token','tab_title']){
  must(!schemaLower.includes(forbidden),'Browser Research schema must not persist '+forbidden);
}
must(schema.includes('page_fingerprint CHAR(64)'),'page fingerprint missing');
must(schema.includes('content_hash CHAR(64)'),'content hash missing');
must(schema.includes('duplicate_group_hash CHAR(64)'),'duplicate-group hash missing');
must(schema.includes('source_public_id CHAR(36)'),'canonical Source reference missing');
must(schema.includes('source_version_public_id CHAR(36)'),'canonical Source version reference missing');
must(schema.includes('source_kind VARCHAR(24)'),'source-kind evidence signal missing');
must(schema.includes('freshness_date DATE'),'page freshness date missing');
must(schema.includes('evidence_excerpt VARCHAR(700)'),'bounded evidence excerpt missing');
must(schema.includes('directness VARCHAR(20)'),'evidence directness missing');
must(schema.includes('as_of_date DATE'),'claim evidence date missing');

// Chrome captures text only for the extraction call and hashes URL/content.
must(background.includes('async function browserResearchCaptureV2230'),'ephemeral research capture missing');
must(background.includes("document.querySelector('main,article,[role=\"main\"]')||document.body"),'readable page capture root missing');
must(background.includes("querySelectorAll('script,style,noscript,svg,canvas,nav,footer,form,input,textarea,select,button')"),'non-content DOM filtering missing');
must(background.includes("clip(clone?clone.innerText||clone.textContent:'',12000)"),'page-text bound missing');
must(background.includes('page_fingerprint:await sha(url)'),'full URL fingerprint missing');
must(background.includes('content_hash:await sha(text)'),'content fingerprint missing');
must(background.includes("browserResearchApiV2230('analyze_page'"),'ephemeral analysis transport missing');
must(!panel.includes('page_text='),'sidepanel must not retain raw research page text');
must(!panel.includes('runtimeResearchPageText'),'sidepanel raw page text state is forbidden');

// Server validates transient URL/fingerprint but does not persist URL/page text.
must(api.includes("hash_equals(hash('sha256',$rawUrl),$pageFingerprint)"),'transient URL fingerprint verification missing');
must(api.includes("$pageText=mb_strimwidth(trim((string)($page['page_text']??'')),0,12000,''"),'server page text bound missing');
must(api.includes("'raw_page_text_persisted'=>false"),'raw page text privacy declaration missing');
must(api.includes("'raw_urls_persisted'=>false"),'raw URL privacy declaration missing');
must(!schema.includes('source_url')&&!schema.includes('canonical_url'),'Research mission schema must not persist page URLs');

// Agent extraction reuses ephemeral Browser Context and canonical Agent Chat.
must(api.includes('vp3_browser_context_validate_v2130'),'Browser Context validation missing');
must(api.includes('vp3_browser_context_relationships_v2130'),'canonical Source relationship resolution missing');
must(api.includes('vp3_agent_chat_send_v2160'),'canonical Agent Chat extraction missing');
must(api.includes("'browser_context'=>["),'ephemeral browser_context payload missing');
must(api.includes('Temporary Browser Research extraction context'),'ephemeral extraction prompt missing');
must(chat.includes("unset($persistedAgentContext['browser_context'])"),'Agent Chat must strip Browser Context before durable context persistence');
must(api.includes('Return ONLY valid JSON using exactly this structure'),'strict structured extraction contract missing');
must(api.includes('array_slice($payload[\'claims\'],0,12)'),'per-page claim extraction limit missing');
must(api.includes('Do not invent missing facts.'),'anti-fabrication extraction instruction missing');
must(api.includes('Treat opinions or disputed assertions as attributed claims.'),'attribution instruction missing');

// Evidence model and deterministic states.
must(runtime.includes("$state=$conflicted?'conflicted':(count($groups)>=2?'corroborated':'single_source')"),'deterministic evidence-state calculation missing');
must(runtime.includes("$group=(string)$e['duplicate_group_hash']"),'duplicate-group independence rule missing');
must(runtime.includes("if((string)$e['source_kind']==='primary')"),'primary-source signal missing');
must(runtime.includes("if((string)$e['directness']==='direct')"),'direct-evidence signal missing');
must(runtime.includes("if($d!==''&&($fresh===''||$d>$fresh))$fresh=$d"),'freshness aggregation missing');
must(runtime.includes("value_hash=CHAR")===false,'sanity: no invalid SQL syntax expectation');
must(schema.includes('UNIQUE KEY uq_browser_research_claim_value_v2230 (mission_id,claim_key,value_hash)'),'claim variant dedupe key missing');
must(schema.includes('UNIQUE KEY uq_browser_research_page_fp_v2230 (mission_id,page_fingerprint)'),'exact-page dedupe missing');

// Source provenance and canonical Research integration.
must(api.includes('vp3_extension_research_source_version_v2230'),'canonical Source/version resolver missing');
must(save.includes('vp3_research_insert_item_v2060'),'canonical Research source item insertion missing');
must(save.includes('vp3_research_create_finding_v2060'),'draft Research Finding creation missing');
must(save.includes("'support'"),'support evidence links missing');
must(save.includes("'conflict'"),'conflict evidence links missing');
must(save.includes('vp3_research_create_report_v2060'),'draft Research Report creation missing');
must(save.includes('vp3_research_set_report_items_v2060'),'Research Report finding composition missing');
must(!save.includes('vp3_research_publish_report_v2060'),'Browser Research must not auto-publish reports');
must(!save.includes('vp3_research_publish_finding'),'Browser Research must not auto-publish findings');

// Follow-up actions are prompts only.
must(runtime.includes("'knowledge'=>'Review this Browser Research memo and prepare a Knowledge draft. Do not save anything until I approve it.'"),'Knowledge draft handoff missing');
must(runtime.includes("'crm'=>'Review these Browser Research findings and prepare proposed CRM enrichment changes. Do not modify CRM until I approve them.'"),'CRM proposal handoff missing');
must(runtime.includes("'task'=>'Review the Browser Research gaps and conflicts and propose the highest-value follow-up task. Do not create it until I approve it.'"),'task proposal handoff missing');
must(panel.includes("draftRuntimeResearchHandoffV2230('knowledge')"),'Knowledge UI handoff missing');
must(panel.includes("draftRuntimeResearchHandoffV2230('crm')"),'CRM UI handoff missing');
must(panel.includes("draftRuntimeResearchHandoffV2230('task')"),'task UI handoff missing');
must(panel.includes("ui.agentMessageInput.value=prompt"),'follow-up actions must populate Agent composer');
must(!api.includes('crm_v180_')&&!save.includes('crm_v180_'),'Browser Research API must not mutate CRM');

// Research UI and progress.
for(const id of ['runtimeResearchPanel','runtimeResearchQuestion','runtimeResearchProject','runtimeResearchStartBtn','runtimeResearchAnalyzeBtn','runtimeResearchSaveBtn','runtimeResearchOpenChatBtn','runtimeResearchPageCount','runtimeResearchClaimCount','runtimeResearchCorroborated','runtimeResearchConflicts','runtimeResearchSources','runtimeResearchClaims','runtimeResearchGaps']){
  must(html.includes('id="'+id+'"'),'missing Browser Research UI '+id);
}
must(panel.includes('function renderRuntimeResearchV2230'),'Research mission renderer missing');
must(panel.includes('async function startRuntimeResearchV2230'),'mission start controller missing');
must(panel.includes('async function analyzeRuntimeResearchPageV2230'),'page analysis controller missing');
must(panel.includes('async function saveRuntimeResearchV2230'),'Research save controller missing');
must(panel.includes("String(step.action_key||'')==='browser_research'"),'canonical research checkpoint opener missing');
must(css.includes('.runtime-research-panel')&&css.includes('.runtime-research-claim.corroborated')&&css.includes('.runtime-research-claim.conflicted'),'Research workspace styling missing');

// Conflict notifications and draft-save notification are generic, not value-bearing.
must(api.includes("'browser_research_conflict'"),'Research conflict notification missing');
must(api.includes('Approved research sources disagree on one or more claims.'),'generic conflict notification copy missing');
must(save.includes("'browser_research_saved'"),'Research saved notification missing');

// Upgrade + workflow receipts.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-research-save-v2230.php';"),'upgrade Browser Research include missing');
must(upgrade.includes('vp3_browser_research_schema_ready_v2230()'),'upgrade readiness missing');
must(upgrade.includes('vp3_research_ensure_schema_v2060();')&&upgrade.indexOf('vp3_research_ensure_schema_v2060();')<upgrade.indexOf('vp3_browser_research_ensure_schema_v2230();'),'canonical Research must install before Browser Research schema');
must(workflows.includes("require_once __DIR__ . '/includes/browser-research-save-v2230.php';"),'Agent Workflows Browser Research include missing');
must(workflows.includes('Browser Research Agent'),'Agent Workflows research receipts panel missing');
must(workflows.includes('Raw page text, URLs and browser history are not persisted'),'Agent Workflows privacy disclosure missing');

console.log('VP3 Browser Companion Browser Research Agent v22.30 contract passed.');
