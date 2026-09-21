import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const multi=read('includes/browser-multisite-v2220.php');
const api=read('api/extension-multisite-v2220.php');
const web=read('includes/browser-web-interaction-v2210.php');
const runtime=read('includes/browser-agent-runtime-v2200.php');
const delegation=read('includes/browser-delegation-v2190.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

const multiVersion=String(manifest.version||'').split('.').map(Number);
must(multiVersion.length===3&&(multiVersion[0]>22||(multiVersion[0]===22&&multiVersion[1]>=2)),'v22.20+ manifest version missing');
must(background.includes(`const VP3_EXTENSION_VERSION = '${manifest.version}';`),'v22.20+ request version must match manifest');
must(Array.isArray(manifest.permissions)&&manifest.permissions.includes('downloads'),'download observation permission missing');
must(!manifest.permissions.includes('cookies'),'v22.20 must not request cookies permission');

must(background.includes("authorizedFetch('/api/extension-multisite-v2220.php'"),'Multi-Site API adapter missing');
for(const route of ['multisite_attach','multisite_state','multisite_policy','multisite_handoff','multisite_fact_add']){
  must(background.includes("case '"+route+"'"),`message route missing ${route}`);
}

// v21.90 / v22.00 / v22.10 remain authoritative layers.
must(multi.includes("require_once __DIR__.'/browser-web-interaction-v2210.php';"),'v22.20 must layer over v22.10');
must(api.includes("'authority_source'=>'v21.90_delegation'"),'v21.90 authority declaration missing');
must(api.includes("'runtime_source'=>'v22.00_browser_agent_runtime'"),'v22.00 runtime declaration missing');
must(api.includes("'interaction_source'=>'v22.10_controlled_web_interaction'"),'v22.10 interaction source declaration missing');
must(delegation.includes("'multisite_handoff'"),'multi-site delegation action missing');
must(html.includes('data-delegation-action="multisite_handoff"'),'explicit multi-site delegation checkbox missing');
must(delegation.includes("'action_key'=>'multisite_handoff'"),'canonical multi-site workflow checkpoint missing');
must(runtime.includes("'multisite_handoff'=>["),'v22.00 runtime skill registry missing multi-site checkpoint');
must(runtime.includes("'mode'=>'checkpoint'")&&runtime.includes("'verification_mode'=>'user_confirmation'"),'multi-site workflow step must stay checkpoint-bounded');

// Schema is server-authoritative and does not persist raw browser URLs/history or credentials.
for(const table of [
  'browser_multisite_sessions_v2220','browser_multisite_domain_policies_v2220','browser_multisite_handoffs_v2220',
  'browser_multisite_tabs_v2220','browser_multisite_facts_v2220','browser_multisite_artifacts_v2220'
]){
  must(multi.includes('CREATE TABLE IF NOT EXISTS '+table),`missing v22.20 table ${table}`);
}
const schemaBlocks=[...multi.matchAll(/CREATE TABLE IF NOT EXISTS browser_multisite_[\s\S]*?ENGINE=InnoDB/g)].map(x=>x[0]).join('\n');
for(const forbidden of ['page_url VARCHAR','source_url VARCHAR','target_url VARCHAR','raw_url','browser_history','cookie_value','password_value','access_token','session_token','mfa_code']){
  must(!schemaBlocks.toLowerCase().includes(forbidden.toLowerCase()),`multi-site schema must not persist ${forbidden}`);
}
must(schemaBlocks.includes('target_url_fingerprint CHAR(64)'),'handoff target URL fingerprint missing');
must(schemaBlocks.includes('client_tab_hash CHAR(64)'),'opaque tab hash missing');
must(!schemaBlocks.includes('tab_title')&&!schemaBlocks.includes('page_title'),'external tab schema must not store titles');
must(api.includes("'raw_urls_persisted'=>false"),'API raw URL privacy declaration missing');
must(api.includes("'cookies_persisted'=>false"),'API cookie privacy declaration missing');
must(api.includes("'credentials_persisted'=>false"),'API credential privacy declaration missing');

// Explicitly approved domain envelope only.
must(multi.includes('VP3_BROWSER_MULTISITE_MAX_DOMAINS_V2220=5'),'approved-domain hard cap missing');
must(multi.includes("vp3_browser_delegation_json_array_v2190($runtime['allowed_domains_json']"),'multi-site domains must come from delegation');
must(multi.includes("!in_array('multisite_handoff',$actions,true)"),'multi-site action authority gate missing');
must(multi.includes("One of these domains is outside the approved delegation."),'handoff approved-domain validation missing');
must(multi.includes("The target domain is blocked by this runtime policy."),'blocked-domain gate missing');

// Per-domain policy can only select subsets of already delegated actions.
must(multi.includes("in_array($mode,['browse','delegated','blocked'],true)"),'domain policy modes missing');
must(multi.includes('vp3_browser_multisite_browse_actions_v2220($delegationActions)'),'browse policy must derive from delegated actions');
must(multi.includes('vp3_browser_multisite_web_actions_v2220($delegationActions)'),'delegated policy must derive from delegated actions');
must(multi.includes("$policy=$primary?'delegated':'browse'"),'secondary approved domains must begin in Browse mode');
must(html.includes('Policy can only narrow the delegation'),'policy-boundary UI missing');

// Handoff is separate from v22.10 same-domain interaction and uses a one-time permit.
must(multi.includes('VP3_BROWSER_MULTISITE_PERMIT_SECONDS_V2220=90'),'handoff permit TTL missing');
must(schemaBlocks.includes('permit_hash CHAR(64)'),'handoff hashed permit missing');
must(multi.includes("status='executing',permit_hash=?"),'handoff permit claim missing');
must(multi.includes("hash_equals((string)$row['permit_hash'],hash('sha256',$permitToken))"),'handoff permit validation missing');
must(background.includes("browserMultiSiteApiV2220('handoff_preview'"),'Chrome handoff preview missing');
must(background.includes("browserMultiSiteApiV2220('handoff_claim'"),'Chrome handoff permit claim missing');
must(background.includes("browserMultiSiteApiV2220('handoff_complete'"),'Chrome handoff completion receipt missing');
must(panel.includes("actions.unshift({key:'handoff'"),'cross-domain links must use synthetic handoff action');
must(web.includes('v22.10 Open Link is limited to the current approved domain'),'v22.10 same-domain link boundary must remain intact');

// Destination URLs stay local in Chrome; server sees fingerprints.
must(background.includes('target_url:targetUrl'),'ephemeral link target missing from Chrome observation');
must(background.includes('target_url_fingerprint:targetFingerprint'),'handoff URL fingerprint transport missing');
must(!api.includes("['target_url']")&&!multi.includes("$input['target_url']"),'v22.20 server must not accept raw target URLs');
must(panel.includes('URL stays local in Chrome'),'Runtime Map raw-URL disclosure missing');

// Redirect and popup scope.
must(background.includes('redirect_outside_scope'),'unapproved redirect failure missing');
must(background.includes("handoff_verified_redirect"),'same-approved-domain redirect verification missing');
must(background.includes('runtimeMultiSiteAllowedDomainsV2220'),'approved-domain runtime guard missing');
must(background.includes('chrome.tabs.onCreated.addListener'),'popup guard missing');
must(background.includes('chrome.tabs.onUpdated.addListener'),'runtime tab navigation guard missing');
must(background.includes("window.stop()"),'out-of-scope navigation stop missing');
must(background.includes('if(row.opened_by_runtime)chrome.tabs.remove'),'only runtime-owned out-of-scope tabs should be auto-closed');

// Runtime tab coordination is opaque and bounded.
must(multi.includes('VP3_BROWSER_MULTISITE_MAX_TABS_V2220=12'),'tab hard cap missing');
must(multi.includes("status='open'"),'open-tab accounting missing');
must(background.includes('runtimeMultiSiteTabsV2220=new Map()'),'ephemeral Chrome tab map missing');
must(!background.includes('localStorage')&&!panel.includes('localStorage')&&!panel.includes('sessionStorage'),'no Chrome durable multi-site ledger allowed');

// Existing signed-in sessions are used without credential/cookie access.
must(!background.includes('chrome.cookies')&&!panel.includes('chrome.cookies'),'multi-site runtime must not read cookies');
must(!background.includes('document.cookie'),'multi-site runtime must not inspect document cookies');
must(multi.includes('Credentials, authentication secrets, financial account data and health/medical data cannot be stored'),'sensitive structured-fact boundary missing');

// Structured facts: bounded, task-scoped, provenance + conflict detection.
must(schemaBlocks.includes('fact_key VARCHAR(120)')&&schemaBlocks.includes('value_text VARCHAR(500)'),'structured fact schema missing');
must(schemaBlocks.includes('source_domain VARCHAR(190)')&&schemaBlocks.includes('page_fingerprint CHAR(64)'),'fact provenance fields missing');
must(schemaBlocks.includes('expires_at DATETIME NOT NULL'),'fact expiry missing');
must(multi.includes("status='conflict'"),'structured fact conflict state missing');
must(multi.includes("'multisite_fact_conflict'"),'fact conflict runtime event missing');
must(multi.includes("'browser_multisite_fact_conflict'"),'fact conflict proactive notification missing');
must(runtime.includes('DELETE FROM browser_multisite_facts_v2220'),'terminal structured-fact purge missing');
must(runtime.includes('DELETE FROM browser_multisite_tabs_v2220'),'terminal external-tab purge missing');

// Download awareness records metadata/fingerprints but never executes a file.
must(background.includes('chrome.downloads.onCreated.addListener'),'download observation listener missing');
must(background.includes('filenameHash=await sha256HexV2220(filename)'),'download filename fingerprint missing');
must(schemaBlocks.includes('filename_hash CHAR(64)'),'download filename hash missing');
must(!schemaBlocks.includes('filename VARCHAR')&&!schemaBlocks.includes('download_url'),'raw download filename/URL must not persist');
must(multi.includes('The file was not executed.'),'download non-execution runtime event missing');

// Runtime Map UI.
for(const id of [
  'runtimeMultiPanel','runtimeMultiStatus','runtimeMultiAttachBtn','runtimeMultiCurrentDomain','runtimeMultiHandoffCount',
  'runtimeMultiTabCount','runtimeMultiConflictCount','runtimeMultiDomains','runtimeMultiHandoffComposer','runtimeMultiTargetUrl',
  'runtimeMultiGoBtn','runtimeMultiFactComposer','runtimeMultiFactKey','runtimeMultiFactValue','runtimeMultiFactAddBtn',
  'runtimeMultiFacts','runtimeMultiHandoffs','runtimeMultiArtifacts'
]){
  must(html.includes(`id="${id}"`),`missing Runtime Map UI element ${id}`);
}
must(panel.includes('function renderRuntimeMultiV2220'),'Runtime Map renderer missing');
must(panel.includes('async function runRuntimeMultiHandoffV2220'),'Runtime Map handoff controller missing');
must(panel.includes('async function updateRuntimeMultiPolicyV2220'),'per-domain policy controller missing');
must(panel.includes('async function addRuntimeMultiFactV2220'),'structured fact controller missing');
must(css.includes('.runtime-multi-panel')&&css.includes('.runtime-multi-domain')&&css.includes('.runtime-multi-row'),'Runtime Map styling missing');

// Existing proactive notifications / Agent Voice surface is reused without fact values in notifications.
must(multi.includes("'browser_multisite_handoff_verified'"),'handoff milestone notification missing');
must(multi.includes("'browser_multisite_handoff_failed'"),'handoff failure notification missing');
const conflictNotify=multi.slice(multi.indexOf("browser_multisite_fact_conflict"),multi.indexOf("browser_multisite_artifact_add_v2220"));
must(!conflictNotify.includes('$value'),'fact conflict notification must not speak/store conflicting values');

// Upgrade + Agent Workflows integration.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-multisite-v2220.php';"),'upgrade v22.20 include missing');
must(upgrade.includes('vp3_browser_multisite_schema_ready_v2220()'),'upgrade v22.20 readiness missing');
must(upgrade.includes('vp3_browser_multisite_ensure_schema_v2220();'),'upgrade v22.20 install missing');
must(workflows.includes("require_once __DIR__ . '/includes/browser-multisite-v2220.php';"),'Agent Workflows v22.20 include missing');
must(workflows.includes('Multi-Site Workflow Runtime'),'Agent Workflows multi-site panel missing');
must(workflows.includes('Raw URLs, browser history, cookies, credentials, MFA codes and tokens are not persisted'),'workflow privacy disclosure missing');

console.log('VP3 Browser Companion Multi-Site Workflow Automation v22.20 contract passed.');
