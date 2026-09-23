<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/cognitive-runtime-v500.php';
require_once __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require_once __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
require_once __DIR__.'/../includes/cognitive-presentation-firewall-v2590.php';
require_once __DIR__.'/../includes/cognitive-entity-graph-v2610.php';
require_once __DIR__.'/../includes/cognitive-release-v2610.php';

function v2610_assert(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);echo "PASS: {$message}\n";}

v2610_assert(vp3_cognitive_entity_graph_relation_v2610('relates_to')==='related_to','relationship aliases normalize deterministically');
v2610_assert(vp3_cognitive_entity_graph_relation_v2610('issued_by')==='issued_by','declared relationship vocabulary is preserved');
v2610_assert(vp3_cognitive_entity_graph_relation_v2610('unknown_relation')==='related_to','unknown relationship labels collapse to related_to');
$keyA=vp3_cognitive_entity_graph_node_key_v2610(['type'=>'campaign','id'=>'abc','scope'=>'workspace','workspace_id'=>7]);
$keyB=vp3_cognitive_entity_graph_node_key_v2610(['type'=>'campaign','id'=>'abc','scope'=>'workspace','workspace_id'=>7]);
$keyC=vp3_cognitive_entity_graph_node_key_v2610(['type'=>'campaign','id'=>'def','scope'=>'workspace','workspace_id'=>7]);
v2610_assert($keyA===$keyB&&$keyA!==$keyC,'entity graph node identity uses exact canonical reference values');
v2610_assert(VP3_COGNITIVE_ENTITY_GRAPH_MAX_DEPTH_V2610===2&&VP3_COGNITIVE_ENTITY_GRAPH_MAX_NODES_V2610===36&&VP3_COGNITIVE_ENTITY_GRAPH_MAX_EDGES_V2610===72,'entity graph projection is hard bounded');

$graph=[
 'counts'=>['nodes'=>8,'domains'=>3,'edges'=>9,'cross_domain_edges'=>4,'unresolved'=>2,'conflicts'=>0,'orphaned_seeds'=>1],
 'diagnostics'=>['unresolved'=>[['reason'=>'model_inferred_not_merged']],'conflicts'=>[],'truncated'=>false],
];
$health=vp3_cognitive_entity_graph_health_v2610($graph);
v2610_assert(!empty($health['ok'])&&(int)$health['counts']['unresolved']===2,'graph health reports unresolved links without merging them');
$presentation=vp3_cognitive_entity_graph_presentation_v2610($graph);
v2610_assert(($presentation['contract']??'')===VP3_COGNITIVE_PRESENTATION_CONTRACT_V2590,'entity graph user-facing summary passes through v25.90 Presentation Firewall');
v2610_assert(!isset($presentation['nodes'])&&!isset($presentation['edges'])&&!isset($presentation['diagnostics']),'presentation excludes raw graph internals');

$manifest=vp3_cognitive_release_manifest_v2610();
$invariants=$manifest['invariants']??[];
v2610_assert(($manifest['release']??'')==='Cognitive Runtime v26.10','release manifest identifies v26.10');
v2610_assert(($invariants['new_graph_table']??true)===false&&($invariants['domain_business_records_copied']??true)===false,'v26.10 creates no graph store or duplicate business records');
v2610_assert(($invariants['name_or_email_similarity_auto_merges_identity']??true)===false&&($invariants['model_inferred_identity_links_auto_merge']??true)===false,'fuzzy and model-inferred identity links never auto-merge');
v2610_assert(!empty($invariants['authorization_checked_each_hop'])&&!empty($invariants['ambiguous_links_remain_unresolved']),'authorization and unresolved-link invariants are explicit');

$campaign=vp3_cognitive_domain_registry_v2600()['domains']['campaigns_rewards']??[];
v2610_assert(in_array('contact',$campaign['related_objects']??[],true)&&in_array('team_member',$campaign['related_objects']??[],true)&&in_array('profile',$campaign['related_objects']??[],true),'Campaigns reference domain declares CRM Team and Profile cross-domain object types');
echo "COGNITIVE_ENTITY_GRAPH_V2610=PASS\n";
