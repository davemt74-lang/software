import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const memory=read('includes/browser-memory-v2170.php');
const memoryApi=read('api/extension-memory-v2170.php');
const cognitiveMemory=read('includes/cognitive-memory-v570.php');
const upgrade=read('upgrade.php');

must(manifest.version==='21.7.0','v21.70 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '21.7.0';"),'v21.70 request version missing');
must(background.includes("if(['candidates','approve'].includes(normalizedAction)&&request.context){")
  &&background.includes("request.context=browserContextPayload(request.context);"),
  'Memory discovery and approval must reuse the bounded v21.30 page-context envelope');

// Reference-only schema: no copied page/browser content columns.
const schemaStart=memory.indexOf('CREATE TABLE IF NOT EXISTS browser_memory_approvals_v2170');
const schemaEnd=memory.indexOf(') ENGINE=InnoDB',schemaStart);
const schema=memory.slice(schemaStart,schemaEnd);
must(schemaStart>=0&&schemaEnd>schemaStart,'Browser Memory approval schema missing');
for(const forbidden of ['page_url','source_url','canonical_url','title','selected_text','page_text','body','content','summary','domain','excerpt']){
  must(!schema.includes(forbidden),`approval schema must not store ${forbidden}`);
}
for(const required of ['owner_user_id','agent_namespace','target_type','target_id','target_scope','approved_at','revoked_at']){
  must(schema.includes(required),`approval schema missing reference field ${required}`);
}
must(schema.includes('UNIQUE KEY uq_browser_memory_target_v2170 (owner_user_id,agent_namespace,target_type,target_id,target_scope)'),
  'approval identity must be unique per user/Agent/target reference');

// Explicit approval only.
const candidateStart=memory.indexOf('function vp3_browser_memory_candidates_v2170');
const candidateEnd=memory.indexOf('function vp3_browser_memory_list_v2170',candidateStart);
const candidateBlock=memory.slice(candidateStart,candidateEnd);
must(candidateStart>=0&&candidateEnd>candidateStart,'candidate resolver missing');
must(!/\b(?:INSERT|UPDATE|DELETE)\b/i.test(candidateBlock),'candidate discovery must be read-only');
must(memoryApi.includes("if($action==='approve')"),'explicit Remember action missing');
must(memoryApi.includes("vp3_browser_context_validate_v2130($rawContext)"),
  'Remember must revalidate the current bounded page context');
must(memoryApi.includes("vp3_browser_context_relationships_v2130(")
  &&memoryApi.includes("$candidates=vp3_browser_memory_candidates_v2170"),
  'Remember must re-derive authorized current-page candidates server-side');
must(memoryApi.includes("if(!$allowed)throw new RuntimeException('That VP3 object is no longer a current-page Memory candidate.')"),
  'Remember must reject stale or client-invented target references');
must(memoryApi.includes("if($action==='revoke')"),'explicit Forget action missing');
must(!memoryApi.includes("$input['page_url']")&&!memoryApi.includes("$input['selected_text']"),
  'approve/revoke API must not accept page content fields');

// Only durable, authorized VP3 target types.
for(const type of ['browser_source','research_project','knowledge_item','public_profile','crm_contact']){
  must(memory.includes(`'${type}'`),`missing durable memory target ${type}`);
}
must(memory.includes("vp3_browser_source_share_authorized_v2050"),'Browser Source memory must require an authorized Source relationship');
must(memory.includes("vp3_research_project_role_v2060"),'Research memory must reauthorize project membership');
must(memory.includes("personal_knowledge_available($user)")&&memory.includes("knowledge_visibility_allowed"),'Knowledge memory must reauthorize personal/system visibility');
must(memory.includes("p.is_public=1 AND u.is_active=1"),'profile memory must resolve only public active profiles');
must(memory.includes("crm_v180_can_manage($user)"),'CRM memory must remain admin-scoped');

// Cognitive object is reference-only and reauthorized on every read.
must(memory.includes("'objects'=>['browser_memory_ref']"),'browser_memory_ref Cognitive object registration missing');
must(memory.includes("'requires_user_approval'=>true"),'Cognitive module must declare user approval requirement');
must(memory.includes("'stores_browsing_history'=>false"),'Cognitive module must declare no browsing-history storage');
must(memory.includes("vp3_browser_memory_target_v2170("),'live target resolver missing');
const permissionStart=memory.indexOf('function vp3_browser_memory_permission_v2170');
const permissionEnd=memory.indexOf('function vp3_browser_memory_context_v2170',permissionStart);
const permissionBlock=memory.slice(permissionStart,permissionEnd);
must(permissionBlock.includes("!empty($approval['revoked_at'])"),'revoked Browser Memory must fail authorization');
must(permissionBlock.includes("(string)$approval['agent_namespace']!==$agentNamespace"),'Browser Memory must remain Agent-namespace scoped');
must(permissionBlock.includes("vp3_browser_memory_target_v2170("),'Browser Memory permission must reauthorize underlying VP3 target');

// Stable approval IDs even under duplicate/racing Remember requests.
must(memory.includes("ON DUPLICATE KEY UPDATE approved_at=UTC_TIMESTAMP(),revoked_at=NULL"),
  'reapproval must reactivate stable target reference');
must(!memory.includes("ON DUPLICATE KEY UPDATE public_id=VALUES(public_id)"),
  'duplicate approval must never rotate public reference ID');
must(memory.includes("$ownsTransaction=!$pdo->inTransaction();")&&memory.includes("vp3_browser_memory_sync_approval_v2170($pdo,$user,$namespace,$approval);"),
  'approval row and Cognitive Memory registration must be atomic');
must(memory.includes("if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();"),
  'failed Remember must roll back approval state');

// Integration with reference-only Cognitive Memory v5.70.
must(cognitiveMemory.includes("require_once __DIR__.'/browser-memory-v2170.php';"),'Cognitive Memory must register Browser Memory object type');
must(memory.includes("vp3_cognitive_memory_observe_candidates_v570"),'approved Browser Memory must enter canonical Cognitive Memory');
must(memory.includes("'source'=>'browser_memory_approval'"),'Cognitive Memory provenance missing');
must(memory.includes("'object_ref'=>$ref"),'approved memory candidate must carry registered object reference');
must(memory.includes("'reference_only'=>true"),'resolved Browser Memory context must declare reference-only storage');
must(memory.includes("function vp3_browser_memory_remove_cognitive_signal_v2170"),'Forget must remove its Cognitive Memory signal');
must(memory.includes("DELETE FROM cognitive_memory_occurrences_v570")
  &&memory.includes("object_type='browser_memory_ref' AND object_id=?"),
  'Forget must delete browser-approved v5.70 occurrences');
must(memory.includes("DELETE FROM cognitive_memory_threads_v570"),
  'Forget must remove empty Browser Memory continuity threads');
must(memory.includes("vp3_cognitive_memory_refresh_thread_v570($pdo,$threadId)"),
  'Forget must refresh any shared surviving memory thread');


// Durable-token API + same Agent namespace as Browser Agent Workspace.
must(memoryApi.includes("vp3_extension_session_authenticate_v2001($pdo)"),'Browser Memory durable-token auth missing');
must(memoryApi.includes("vp3_extension_session_has_capability_v2001($session,'agent.message')"),'Browser Memory live Agent capability gate missing');
must(memoryApi.includes("has_permission('chat.access',$user)"),'Browser Memory live Chat permission gate missing');
must(memoryApi.includes("vp3_agent_chat_runtime_default_agent_v2160($pdo,$user)"),'Browser Memory must share Browser Agent default-Agent behavior');
must(memoryApi.includes("vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId)"),'Browser Memory Agent namespace resolution missing');

// Current-page relationship discovery remains ephemeral.
must(memoryApi.includes("vp3_browser_context_validate_v2130($rawContext)"),'Browser Memory current-page validation missing');
must(memoryApi.includes("vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session['capabilities']??[]))"),
  'Browser Memory candidates must reuse live authorized v21.30 relationships');
must(memoryApi.includes("'page_context'=>['ephemeral'=>true"),'Memory API must mark page context ephemeral');

// Browser UI requires direct Remember/Forget clicks; merely opening/refreshing Memory does not approve.
for(const id of ['memoryTab','memoryView','memoryAgentName','memoryRefreshBtn','memoryPageLabel','memoryCandidates','memoryCandidatesEmpty','memoryRemembered','memoryRememberedEmpty','memoryCount']){
  must(html.includes(`id="${id}"`),`missing Browser Memory UI element ${id}`);
}
must(css.includes('.memory-boundary')&&css.includes('.memory-row'),'Browser Memory styling missing');
must(panel.includes("function loadMemoryV2170()"),'Browser Memory loader missing');
must(panel.includes("await memoryRequestV2170('candidates',{context:capture})"),'current-page memory candidate lookup missing');
must(panel.includes("await memoryRequestV2170('list',{})"),'approved memory list lookup missing');
const loadStart=panel.indexOf('async function loadMemoryV2170');
const loadEnd=panel.indexOf('async function memoryClickV2170',loadStart);
const loadBlock=panel.slice(loadStart,loadEnd);
must(!loadBlock.includes("'approve'")&&!loadBlock.includes("'revoke'"),'loading Memory view must never mutate approvals');
must(panel.includes("await memoryRequestV2170('approve'"),'Remember click action missing');
must(panel.includes("context:capture"),'Remember click must bind approval to the current page context');
must(panel.includes("await memoryRequestV2170('revoke'"),'Forget click action missing');
must(panel.includes("ui.quickMemoryBtn.onclick=()=>setView('memory');"),'This Page Memory shortcut must only open approval view');
must(panel.includes("['now','agent','memory'].includes(activeView)&&!c.has('agent.message')"),'Memory view must leave immediately if Agent access is revoked');
must(panel.includes("if(activeView==='memory')await loadMemoryV2170();"),'page changes must refresh Memory candidates without writing');

// No Chrome-side Browser Memory store/history.
must(!background.includes('browser_memory_approvals_v2170'),'Chrome must not know Browser Memory database internals');
must(!panel.includes('localStorage')&&!panel.includes('sessionStorage'),'Memory view must not create browser-side durable memory');

// Upgrade integration.
must(upgrade.includes("vp3_browser_memory_schema_ready_v2170()"),'upgrade readiness missing Browser Memory');
must(upgrade.includes("vp3_browser_memory_ensure_schema_v2170();"),'upgrade install missing Browser Memory');

console.log('VP3 Browser Companion Cross-Web Cognitive Memory v21.70 contract passed.');
