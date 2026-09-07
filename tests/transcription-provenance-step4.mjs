import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const actions = read('includes/transcription-intelligence-actions.php');
const evidence = read('artist-listening-evidence.php');
const knowledge = read('knowledge.php');
const crmLead = read('admin/crm-lead.php');
const lifecycle = read('includes/agent-memory-lifecycle-v123.php');
const proactive = read('includes/agent-proactive-v123.php');

assert.match(actions,/VP3_TRANSCRIPTION_PROVENANCE_V309/,'step 4 must have one canonical provenance version');
assert.match(actions,/function transcription_intelligence_origin_v309/,'one server-owned provenance builder must own Artist Listening origins');
for (const field of ['source_url','workspace_url','session_id','session_title','plugin_id','section_key','item_id','evidence_page','evidence_refs']) {
  assert.ok(actions.includes(`'${field}'`), `provenance must include ${field}`);
}
assert.match(actions,/'label'=>'Artist Listening'/,'provenance must use the visible Artist Listening source label');
assert.match(actions,/Source: Artist Listening/,'promoted record text must visibly identify Artist Listening');
assert.match(actions,/agent_chat_v101_append_ecosystem_message[\s\S]*'origin'=>\$origin/,'Main Feed messages must retain structured provenance');
assert.match(actions,/agent_brain_v122_upsert_system_memory[\s\S]*'source_url'=>\(string\)\$origin\['source_url'\]/,'Agent Brain must retain exact source URL');
assert.match(actions,/'origin'=>\$origin,'source_label'=>'Artist Listening','source_url'=>\(string\)\$origin\['source_url'\]/,'Agent tasks must retain the same provenance object');
assert.match(actions,/personal_knowledge_store[\s\S]*Source: Artist Listening/,'Personal Knowledge must visibly carry Artist Listening provenance');
assert.match(actions,/crm_v180_activity[\s\S]*\$crmDetails/,'CRM notes must use source-aware activity metadata');
assert.match(actions,/transcript_intelligence_task_source/,'CRM tasks must get a source activity that can be rendered without adding a parallel task table');

assert.match(evidence,/require_once __DIR__\.\/includes\/bootstrap\.php|require_once __DIR__.'\/includes\/bootstrap.php'/,'evidence viewer must use canonical auth/bootstrap');
assert.match(evidence,/has_permission\('artist_listening\.access',\$user\)/,'evidence viewer must require Artist Listening access');
assert.match(evidence,/artist_listening_transcript_page\(\$pdo,\$user,\$sessionId,\$page\)/,'evidence viewer must use the owner-scoped canonical transcript page resolver');
assert.match(evidence,/Source: Artist Listening/,'evidence viewer must identify its provenance surface');
assert.match(evidence,/Open Artist Listening/,'evidence viewer must link back to the full workspace');

assert.match(knowledge,/personal_knowledge_artist_listening_source/,'Personal Knowledge must detect transcription-origin records');
assert.match(knowledge,/id="knowledge-<\?= \(int\)\$item\['id'\] \?>"/,'Personal Knowledge rows need stable receipt anchors');
assert.match(knowledge,/Source: Artist Listening · Open evidence/,'Personal Knowledge must expose a clickable source affordance');
assert.match(knowledge,/artist-listening-evidence\.php/,'Personal Knowledge must only accept the canonical evidence path for source links');

assert.match(crmLead,/\$activity\['_source_label'\]/,'CRM activity view must decode source metadata');
assert.match(crmLead,/\$taskSources/,'CRM task view must project task provenance from canonical CRM activities');
assert.match(crmLead,/Source: <\?= e\(\$activity\['_source_label'\]\) \?> · Open evidence/,'CRM activities must show a clickable source link');
assert.match(crmLead,/Source: <\?= e\(\$taskSource\['label'\]\) \?> · Open evidence/,'CRM tasks must show a clickable source link');

assert.match(lifecycle,/'source_label'=>\$sourceLabel,'source_url'=>\$sourceUrl,'origin'=>\$origin/,'task lifecycle must preserve provenance for downstream surfaces');
assert.match(proactive,/Source: '\.\$sourceLabel/,'Agent Brain task suggestions must visibly identify their originating surface');
assert.match(proactive,/'url'=>\$sourceUrl/,'Agent Brain task actions must return to exact source evidence');

for (const file of [actions,evidence,knowledge,crmLead,lifecycle,proactive]) {
  assert.doesNotMatch(file,/CREATE TABLE|ALTER TABLE/,'step 4 provenance must not introduce a parallel schema');
}

console.log('VP3_TRANSCRIPTION_PROVENANCE_STEP4=PASS');
