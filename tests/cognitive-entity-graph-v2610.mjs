import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const graph=read('includes/cognitive-entity-graph-v2610.php');
const release=read('includes/cognitive-release-v2610.php');
const bootstrap=read('includes/bootstrap.php');
const context=read('includes/cognitive-context-v2420.php');
const brain=read('api/chat-notifications-brain-v240.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const campaigns=read('includes/campaigns-rewards-domain-v100.php');
const sidebar=read('includes/main-sidebar.php');
const nav=read('includes/member-navigation.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_ENTITY_GRAPH_V2610.md');

const checks=[
 ['graph is projection-only with no schema authority',!/CREATE TABLE|ALTER TABLE|INSERT INTO cognitive_entity|entity_graph_nodes|entity_graph_edges/i.test(graph)&&/materialization'=>'ephemeral_read_only'/.test(graph)],
 ['graph is bounded to ten seeds thirty-six nodes seventy-two edges and two hops',/MAX_SEEDS_V2610=10/.test(graph)&&/MAX_NODES_V2610=36/.test(graph)&&/MAX_EDGES_V2610=72/.test(graph)&&/MAX_DEPTH_V2610=2/.test(graph)],
 ['trusted event seeds read object_refs only',/verification_status IN \('trusted','verified'\)/.test(graph)&&/payload\['object_refs'\]/.test(graph)&&/event_seed_source.*object_refs_only/.test(graph)],
 ['every seed and hop uses canonical object authorization',/vp3_cognitive_validate_object_ref_v500/.test(graph)&&/vp3_cognitive_authorize_ref_v500/.test(graph)&&/vp3_cognitive_relationships_for_ref_v500/.test(graph)],
 ['model inferred links stay unresolved',/model_inferred_not_merged/.test(graph)&&/confirmation==='model_inferred'/.test(graph)],
 ['only deterministic or user confirmed relationships expand graph',graph.includes("in_array($confirmation,['deterministic','user_confirmed'],true)")],
 ['conflicting verified identities become diagnostics not merges',/conflicting_verified_identity_links/.test(graph)&&/same_as/.test(graph)&&/represents/.test(graph)],
 ['graph presentation passes through v25.90 firewall',/vp3_cognitive_presentation_firewall_validate_v2590/.test(graph)&&/entity_graph_presentation_v2610/.test(graph)],
 ['Working Context has a single entity_graph section',/'entity_graph'=>1/.test(context)&&/vp3_cognitive_entity_graph_context_item_v2610/.test(context)&&/cognitive_entity_graph_v2610_ephemeral_projection/.test(context)],
 ['Agent Brain exposes bounded graph summary and firewall presentation only',brain.includes("'entity_graph_presentation'=>$entityGraphPresentation")&&brain.includes("'domain_counts'=>$entityGraph['domain_counts']??[]")&&!brain.includes("'nodes'=>$entityGraph")&&!brain.includes("'edges'=>$entityGraph")],
 ['Proactive Now exposes bounded graph summary and firewall presentation only',proactive.includes("'entity_graph_presentation'=>$entityGraphPresentation")&&proactive.includes("'counts'=>$entityGraph['counts']??[]")&&/entity_graph_v2610_ephemeral_projection/.test(proactive)&&!proactive.includes("'nodes'=>$entityGraph")&&!proactive.includes("'edges'=>$entityGraph")],
 ['Campaigns relationships cross Merchant Profile Team CRM Campaign Reward and Claim boundaries',/owned_by','profile'/.test(campaigns)&&/has_member','team_member'/.test(campaigns)&&/related_to','contact'/.test(campaigns)&&/issued_to','contact'/.test(campaigns)&&/processed_by','team_member'/.test(campaigns)],
 ['Campaign cross-domain relationships remain deterministic',/campaigns_rewards_domain_v100/.test(campaigns)&&/confirmation_state'=>'deterministic'/.test(campaigns)],
 ['bootstrap loads graph after Campaigns domain provider and before v26.10 release gate',bootstrap.indexOf("campaigns-rewards-domain-v100.php")<bootstrap.indexOf("cognitive-entity-graph-v2610.php")&&bootstrap.indexOf("cognitive-entity-graph-v2610.php")<bootstrap.indexOf("cognitive-release-v2610.php")],
 ['release forbids graph table entity copies fuzzy merges and execution authority',/new_graph_table'=>false/.test(release)&&/domain_business_records_copied'=>false/.test(release)&&/name_or_email_similarity_auto_merges_identity'=>false/.test(release)&&/second_execution_authority'=>false/.test(release)],
 ['Campaigns & Rewards is visible in primary sidebar Plan & Sell',/mainSidebarPrimaryOrder[^\\n]*'campaigns'/.test(sidebar)&&/'campaigns'=>'Campaigns & Rewards'/.test(sidebar)&&/'campaigns'=>'Plan & Sell'/.test(sidebar)],
 ['Campaigns & Rewards is intentionally duplicated in bottom sidebar user menu',/Campaigns & Rewards is intentionally available in both Plan & Sell/.test(sidebar)&&/array_unshift\\(\\$mainSidebarFooterLinks,\\$campaignsFooterLink\\)/.test(sidebar)],
 ['navigation catalog still gates Campaigns by actual plugin access',/campaigns_rewards_user_has_access_v100/.test(nav)&&/'campaigns','Campaigns & Rewards'/.test(nav)],
 ['dedicated cognitive workflow is v26.10 aware',/Cognitive Runtime Release v26\\.10/.test(workflow)&&/cognitive-entity-graph-v2610\\.php/.test(workflow)&&/cognitive-entity-graph-v2610\\.mjs/.test(workflow)],
 ['Recovery Baseline includes both v26.10 contracts',/cognitive-entity-graph-v2610\\.mjs/.test(recovery)&&/cognitive-entity-graph-v2610\\.php/.test(recovery)],
 ['production package requires v26.10 graph and release files',/Cognitive Runtime v26\\.10/.test(packageWorkflow)&&/cognitive-entity-graph-v2610\\.php/.test(packageWorkflow)&&/cognitive-release-v2610\\.php/.test(packageWorkflow)],
 ['documentation states no duplicate domain data and no fuzzy identity merge',/does not create a graph database/i.test(docs)&&/does not merge records because names, email addresses/i.test(docs)&&/Model-inferred links/.test(docs)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('Cognitive Entity Graph v26.10 contract: '+checks.length+'/'+checks.length+' passed');
