import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');
const must=(value,message)=>{if(!value)throw new Error(message);};
const mustNot=(value,message)=>{if(value)throw new Error(message);};

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.html');
const panelJs=read('browser-companion/sidepanel.js');
const service=read('includes/research-projects-v2060.php');
const api=read('api/research-projects-v2060.php');
const sourceFeed=read('includes/browser-source-feed-v2050.php');
const media=read('includes/browser-share-media-v2040.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const hub=read('research.php');
const projectPage=read('research-project.php');
const builder=read('research-report-builder.php');
const reportPage=read('research-report.php');
const sourcePage=read('source.php');

must(manifest.manifest_version===3,'Research integration must remain Manifest V3');
must(manifest.version==='20.60.0','Phase 7 Browser Companion version must be v20.60.0');

for(const table of [
  'research_projects_v2060','research_project_members_v2060','research_project_items_v2060','research_project_events_v2060',
  'research_findings_v2060','research_finding_evidence_v2060','research_reports_v2060','research_report_items_v2060',
  'research_report_versions_v2060','research_report_version_sources_v2060'
]) must(service.includes(table),'Phase 7 schema missing '+table);

must(service.includes("require_once __DIR__.'/browser-source-feed-v2050.php'"),'Research must build on canonical Phase 6 Source Feed');
must(service.includes("'owner'=>40,'admin'=>30,'researcher'=>20,'viewer'=>10"),'Research role hierarchy missing');
must(service.includes("vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher')"),'Research writes must require Researcher access');
must(service.includes("vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'admin')"),'Research publishing/admin mutations must require Admin access');
must(service.includes("vp3_human_team_authorized_v370"),'Team Research access must use live canonical Team authorization');

must(service.includes("browser_share_id BIGINT UNSIGNED NULL"),'Research annotations must reference canonical Browser Shares');
must(service.includes("source_id BIGINT UNSIGNED NOT NULL"),'Research items must reference canonical Sources');
must(service.includes("source_version_id BIGINT UNSIGNED NULL"),'Research items must pin Source Versions');
mustNot(service.includes('selected_text VARCHAR'),'Research must not copy Browser Share selection content into project tables');
must(service.includes("DELETE FROM browser_research_queue_v2050"),'assigning an Inbox item must consume its queue entry');
must(service.includes("vp3_research_insert_item_v2060($pdo,$project,$actorUserId,'source'"),'assigning an annotation must add its canonical Source automatically');

must(service.includes('function vp3_research_share_authorized_v2060'),'project-scoped annotation authorization missing');
must(sourceFeed.includes('bool $preauthorized=false'),'canonical annotation serializer must support explicit project-scoped preauthorization');
must(media.includes('vp3_research_share_authorized_v2060'),'Research project media must use project ACL without widening Phase 6 visibility');
must(media.includes("It does not alter the annotation's Phase 6 publication visibility"),'Research media boundary must document Phase 6 visibility isolation');

must(service.includes("['support','conflict','context']"),'Finding evidence roles missing');
must(service.includes("'draft','confirmed'"),'Finding confirmation lifecycle missing');
must(service.includes("Published Findings are immutable"),'published Finding immutability missing');
must(service.includes('pinned_source_version_id'),'Finding evidence must pin Source Versions');
must(service.includes("$evidence[]=['role'=>$ev['role'],'source'=>$src]"),'published evidence snapshot must contain role + pinned Source only');
mustNot(service.includes("$evidence[]=['role'=>$ev['role'],'note'=>$ev['note'],'source'=>$src]"),'internal evidence notes must not leak into published snapshots');

must(service.includes("VP3_RESEARCH_REPORT_SCHEMA_V2060"),'versioned report snapshot schema missing');
must(service.includes("snapshot_hash"),'report snapshot integrity hash missing');
must(service.includes("hash('sha256',$encoded)"),'published report versions must be SHA-256 hashed');
must(service.includes("INSERT INTO research_report_versions_v2060"),'publishing must insert immutable report versions');
mustNot(service.includes('UPDATE research_report_versions_v2060 SET snapshot_json'),'published report snapshots must never be mutated');
must(service.includes("$next=(int)$report['current_version_no']+1"),'report version sequencing missing');
must(service.includes("report_status='unpublished'"),'report unpublishing missing');
must(service.includes("Historical snapshots")||builder.includes('Historical snapshots'),'unpublish flow must preserve historical snapshots');

must(service.includes('function vp3_research_annotation_compatible_v2060'),'annotation/report visibility compatibility gate missing');
must(service.includes("'redacted_to_source'=>true"),'incompatible annotation text must redact back to pinned Source');
must(service.includes("if($visibility==='public')"),'public report access branch missing');
must(service.includes("vp3_human_team_authorized_v370($pdo,$team,$viewerUserId)"),'Team reports must revalidate live Team access');
must(service.includes("research_report_version_sources_v2060"),'normalized report Source provenance missing');
must(service.includes("source_version_id BIGINT UNSIGNED NOT NULL"),'published provenance must require a pinned Source Version');
must(service.includes("Research Source provenance requires a pinned Source Version."),'snapshot construction must fail closed without Source-Version provenance');
must(service.includes("ON DELETE RESTRICT"),'published Source-Version provenance must not silently null on deletion');
must(service.includes('vp3_research_reports_for_source_v2060'),'Source-to-published-report lookup missing');

must(api.includes("action==='placements'"),'Browser Companion project-placement endpoint missing');
must(api.includes("action==='assign'"),'Research assignment API action missing');
must(api.includes("action==='create_project'"),'Research project creation API action missing');
must(api.includes("action==='publish_report'"),'Research report publication API action missing');
must(api.includes("action==='unpublish_report'"),'Research report unpublish API action missing');
must(api.includes("vp3_extension_session_authenticate_v2001"),'Research extension API must use live bearer authentication');
must(api.includes("knowledge.write"),'Research extension writes must require knowledge.write capability');
mustNot(api.includes('ensure_schema_v2060('),'public Research API must never execute schema DDL');

must(background.includes("'/api/research-projects-v2060.php'"),'Browser Companion Research API transport missing');
must(background.includes("case 'research_context'"),'Browser Companion Research placement context transport missing');
must(background.includes("case 'research_action'"),'Browser Companion Research write transport missing');
for(const id of ['researchDialog','researchProjectSelect','researchInboxBtn','researchAddBtn','newResearchProjectTitle','createResearchProjectBtn','openResearchHubBtn']){
  must(panel.includes('id="'+id+'"'),'Browser Companion Research chooser missing '+id);
}
must(panelJs.includes("openResearchDialog(id)"),'Add to Research must open the project chooser');
must(panelJs.includes("researchAction('assign'"),'Browser Companion direct-to-project assignment missing');
must(panelJs.includes("researchAction('create_project'"),'Browser Companion create-project flow missing');
must(panelJs.includes("sourceAction('research'"),'Research Inbox fallback must remain available');
must(panelJs.includes("Already in "),'Browser Companion must show existing project placement');
must(panelJs.includes("create project + add")||panel.includes('Create project + add'),'Browser Companion create-and-assign UX missing');

must(bootstrap.includes("research-projects-v2060.php"),'canonical bootstrap must load Research Projects');
must(upgrade.includes("vp3_research_schema_ready_v2060()"),'upgrade readiness must include Research Projects');
must(upgrade.includes("vp3_research_ensure_schema_v2060();"),'upgrade must install Research Projects schema');

must(hub.includes('vp3_research_inbox_v2060'),'Research Hub must render Browser Companion Inbox');
must(hub.includes('vp3_research_assign_share_v2060'),'Research Hub must assign Inbox items to projects');
must(projectPage.includes('vp3_research_project_bundle_v2060'),'Project workspace must use canonical project bundle');
must(projectPage.includes('vp3_research_update_finding_v2060'),'Project workspace must support Finding edits');
must(projectPage.includes('vp3_research_link_evidence_v2060'),'Project workspace must support evidence linking');
must(builder.includes('vp3_research_set_report_items_v2060'),'Report Builder must compose reports through canonical service');
must(builder.includes('vp3_research_publish_report_v2060'),'Report Builder must publish immutable versions through canonical service');
must(builder.includes('next version only'),'Report Builder must distinguish draft edits from immutable published history');
must(reportPage.includes('vp3_research_report_version_v2060'),'Report viewer must resolve an immutable authorized version');
must(reportPage.includes('SHA-256'),'Report viewer must expose snapshot integrity');
must(sourcePage.includes('vp3_research_reports_for_source_v2060'),'canonical Source pages must surface authorized published Research');
must(sourcePage.includes('empty($feed[\'items\']) && empty($publishedResearch)'),'public Source existence must still require visible annotation or Research');

console.log('VP3 Phase 7 Research Projects + Publishing v20.60 contract passed.');
