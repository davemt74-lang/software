import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');
const must=(value,message)=>{if(!value)throw new Error(message);};
const mustNot=(value,message)=>{if(value)throw new Error(message);};

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.html');
const panelJs=read('browser-companion/sidepanel.js');
const service=read('includes/search-discovery-v2090.php');
const api=read('api/search-v2090.php');
const sourceService=read('includes/browser-source-feed-v2050.php');
const trustService=read('includes/browser-trust-v2080.php');
const researchService=read('includes/research-projects-v2060.php');
const liveService=read('includes/live-rooms-v2070.php');
const profileService=read('includes/profile-agent.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const web=read('search.php');
const sourcePage=read('source.php');

must(manifest.manifest_version===3,'Phase 10 must remain Manifest V3');
{const [major,minor]=manifest.version.split('.').map(Number);must(major>20||(major===20&&minor>=90),'Phase 10 Browser Companion must remain v20.90 or newer');}
const runtimeVersion=(background.match(/const VP3_EXTENSION_VERSION = '([0-9.]+)'/)||[])[1]||'';
{const [major,minor]=runtimeVersion.split('.').map(Number);must(major>20||(major===20&&minor>=90),'Phase 10 runtime must remain v20.90 or newer');}

for(const table of ['search_documents_v2090','search_recent_queries_v2090','search_saved_queries_v2090','search_index_events_v2090']){
  must(service.includes(table),'Phase 10 schema missing '+table);
}
for(const type of ["'source'","'annotation'","'claim'","'research'","'live'","'user'","'team'"]){
  must(service.includes(type),'Phase 10 index missing content type '+type);
}
must(service.includes('UNIQUE KEY uq_search_document_object'),'search index must dedupe canonical object documents');
must(service.includes('vp3_search_index_event_v2090'),'index lifecycle audit missing');
must(service.includes('vp3_search_prune_stale_v2090'),'stale index pruning missing');
must(service.includes('vp3_search_tombstone_if_missing_v2090'),'stale result tombstoning missing');

must(service.includes('function vp3_search_document_access_v2090'),'live result authorization gate missing');
must(service.includes('vp3_browser_trust_source_access_v2080'),'Source search must reuse canonical Source authorization');
must(service.includes('vp3_browser_source_share_authorized_v2050'),'Annotation search must reuse canonical publication/delivery authorization');
must(service.includes('vp3_browser_trust_claim_access_v2080'),'Claim search must reuse canonical claim visibility');
must(service.includes('vp3_search_research_access_v2090'),'Research search needs project/publication authorization');
must(service.includes('vp3_live_room_access_v2070'),'Live Room search must reuse live room authorization');
must(service.includes("!empty($profile['is_public'])"),'profile search must expose only public profiles');
must(service.includes('vp3_human_team_authorized_v370'),'Team search must revalidate active membership');
must(service.includes('$result=vp3_search_result_v2090'),'candidate documents must be re-authorized before result serialization');
mustNot(api.includes('search_documents_v2090'),'public API must not bypass the canonical Search service with direct index SQL');

must(service.includes('function vp3_search_score_v2090'),'relevance ranking function missing');
must(service.includes("if($title===$q)$score+=100"),'exact title relevance boost missing');
must(service.includes("if($domain===$q)$score+=60"),'domain relevance boost missing');
must(service.includes("$score+=35"),'current-Source context boost missing');
must(service.includes('activity_score'),'activity ranking signal missing');
must(service.includes("if(!empty($flags['saved']))$score+=8"),'saved annotation relevance must be viewer-specific');
must(service.includes("if(!empty($flags['in_research']))$score+=8"),'Research relevance must be viewer-specific');
must(service.includes("unset($item['_rank_score'])"),'internal ranking scores must not be exposed to clients');
mustNot(service.includes("$saveCount*2+$researchCount*3"),'private aggregate Save/Research counts must not become cross-user activity signals');
must(service.includes('object_updated_at'),'recency ranking signal missing');
must(service.includes('browser_source_follows_v2050'),'followed-source personalization boost missing');
must(service.includes("SELECT source_id FROM browser_source_follows_v2050 WHERE user_id=?"),'opening Search must refresh the viewer\'s followed Source index');

for(const filter of ['types','visibility','domain','team_id','author_id','claim_status','changed','date_from','date_to','context_source_id','context_only']){
  must(service.includes("'"+filter+"'"),'Search filter missing '+filter);
}
must(service.includes("array_merge($filters,['context_only'=>true])"),'contextual discovery must force current-Source isolation');
must(service.includes('vp3_search_discover_v2090'),'discovery feed service missing');
must(service.includes("'trending'"),'trending source discovery missing');
must(service.includes("'active'"),'active content discovery missing');

must(service.includes('vp3_search_record_recent_v2090'),'recent search recording missing');
must(service.includes('vp3_search_save_query_v2090'),'saved search creation missing');
must(service.includes('vp3_search_delete_saved_v2090'),'saved search deletion missing');
must(service.includes('vp3_search_clear_recent_v2090'),'recent search clearing missing');

for(const action of ['search','discover','recent','saved','save_search','delete_saved','clear_recent']){
  must(api.includes(action),'Search API missing '+action);
}
must(api.includes('vp3_extension_session_authenticate_v2001'),'extension Search API must use canonical bearer authentication');
mustNot(api.includes('ensure_schema_v2090('),'public Search API must never execute schema DDL');

must(sourceService.includes('vp3_search_index_annotation_v2090'),'annotation publish/comment/state lifecycle must update Search');
must(sourceService.includes('vp3_search_index_source_v2090'),'Source lifecycle must update Search');
must(trustService.includes('vp3_search_mark_source_changed_v2090'),'source-change events must update Search');
must(trustService.includes('vp3_search_index_claim_v2090'),'claim lifecycle must update Search');
must(researchService.includes('vp3_search_index_research_v2090'),'Research lifecycle must update Search');
must(liveService.includes('vp3_search_index_live_v2090'),'Live lifecycle must update Search');
must(profileService.includes('vp3_search_index_user_v2090'),'profile lifecycle must update Search');
must(profileService.includes("vp3_search_tombstone_v2090($pdo,'user'"),'profile rename must tombstone old search identity');

must(web.includes('SEARCH & DISCOVERY'),'web Search page missing');
for(const label of ['Trending Sources','Active now','Saved searches','Recent','Source changed','Claim status']){
  must(web.includes(label),'web Search UI missing '+label);
}
must(sourcePage.includes('Related on VP3'),'Source pages must surface related discovery');
must(sourcePage.includes('Search this Source'),'Source page contextual Search entry point missing');

for(const id of ['searchTab','searchView','searchInput','searchSubmitBtn','searchType','searchVisibility','searchChanged','searchTeam','searchThisSource','searchResultsList','savedSearchesList','recentSearchesList']){
  must(panel.includes('id="'+id+'"'),'Browser Companion Search UI missing '+id);
}
must(background.includes("'/api/search-v2090.php'"),'Browser Companion Search transport missing');
for(const type of ['search_query','search_discover','search_recent','search_saved','search_action'])must(background.includes("case '"+type+"'"),'Browser Companion transport missing '+type);
must(panelJs.includes("setView('search')"),'Search tab navigation missing');
must(panelJs.includes('context_source_id:currentSource&&currentSource.id'),'Chrome Search must use current Source as ranking context');
must(panelJs.includes("sourceAction('save'"),'Search result Save action missing');
must(panelJs.includes("sourceAction('research'"),'Search result Add to Research action missing');
must(panelJs.includes("sourceAction('follow_source'"),'Search result Follow Source action missing');
must(panelJs.includes("search_join_live"),'Search result Join Live action missing');
must(panelJs.includes("'/claims.php?source='"),'Search result File Claim action missing');
must(panelJs.includes("action:'save_search'"),'Chrome saved search action missing');
must(panelJs.includes("ui.searchVisibility.value=s.filters&&s.filters.visibility||''"),'saved/recent Chrome searches must restore compact filters');

must(bootstrap.includes("search-discovery-v2090.php"),'canonical bootstrap must load Search & Discovery');
must(upgrade.includes('vp3_search_schema_ready_v2090()'),'upgrade readiness must include Phase 10');
must(upgrade.includes('vp3_search_ensure_schema_v2090();'),'upgrade must install and backfill Phase 10');

console.log('VP3 Phase 10 Search & Discovery Intelligence v20.90 contract passed.');
