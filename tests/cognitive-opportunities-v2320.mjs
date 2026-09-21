import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const opp=read('includes/cognitive-opportunities-v2320.php');
const feed=read('includes/cognitive-feed-v530.php');
const runtime=read('includes/cognitive-runtime-v500.php');
const planning=read('includes/cognitive-planning-v550.php');
const queue=read('includes/cognitive-priority-queue-v2310.php');
const bootstrap=read('includes/bootstrap.php');
const spec=read('docs/VP3_COGNITIVE_OPPORTUNITIES_V2320.md');

const checks=[
 ['uses existing observation store', /vp3_cognitive_observation_store_v500/.test(opp)],
 ['no new schema', !/CREATE TABLE|ALTER TABLE/i.test(opp)],
 ['no model calls', !/openai|anthropic|gemini|chat_remote_answer|ai_generate|curl_exec/i.test(opp)],
 ['relationship evidence only', /cognitive_relationships_v500/.test(opp) && /vp3_cognitive_relationships_for_ref_v500/.test(opp)],
 ['anchor explicitly reauthorized', /vp3_cognitive_authorize_ref_v500\(\$pdo,\$user,\$namespace,\$anchor,'read'\)/.test(opp)],
 ['model inferred relationships excluded', /deterministic','user_confirmed/.test(opp) && !/\['deterministic','user_confirmed','model_inferred'\]/.test(opp)],
 ['confidence floor', /MIN_RELATION_CONFIDENCE_V2320=0\.80/.test(opp)],
 ['bounded anchors', /MAX_ANCHORS_V2320=16/.test(opp)],
 ['bounded detections', /MAX_DETECTIONS_V2320=8/.test(opp)],
 ['meeting context pattern', /prepare_with_context/.test(opp)],
 ['supporting evidence pattern', /apply_supporting_evidence/.test(opp)],
 ['commitment coordination pattern', /coordinate_work_commitment/.test(opp)],
 ['category opportunity', /'category'=>'opportunity'/.test(opp)],
 ['proposal contains no tools', /'proposed_action_ids'=>\[\]/.test(opp)],
 ['brief not voice', /'presentation_recommendation'=>'brief'/.test(opp) && /'voice_safe_summary'=>''/.test(opp)],
 ['stable unchanged observation', /sameSemantic/.test(opp) && /SET valid_until=\? WHERE id=\?/.test(opp) && !/SET valid_until=\?,updated_at/.test(opp)],
 ['ttl-only refresh preserves feed fingerprint', /without touching updated_at/.test(opp) && /vp3_cognitive_score_v500\(max/.test(opp)],
 ['bounded scans never resolve unseen opportunities', /valid_until IS NOT NULL AND valid_until<UTC_TIMESTAMP\(\)/.test(opp) && !/observation_key NOT IN/.test(opp)],
 ['bounded scan reports truncation', /'truncated'=>\$truncated/.test(opp) && /'cleanup'=>'expired_only'/.test(opp)],
 ['feed syncs detector before rereading observations', /vp3_cognitive_opportunity_sync_v2320/.test(feed) && /vp3_cognitive_feed_observation_candidates_v530/.test(feed)],
 ['existing planning handles opportunity', /'opportunity'=>'evaluate_opportunity'/.test(planning)],
 ['queue opportunities lane retained', /opportunities/.test(queue)],
 ['runtime validates evidence authorization', /Observation evidence is not authorized/.test(runtime)],
 ['bootstrap ordering', bootstrap.indexOf('cognitive-opportunities-v2320.php')>-1 && bootstrap.indexOf('cognitive-opportunities-v2320.php')<bootstrap.indexOf('cognitive-feed-v530.php')],
 ['no automatic writes invariant', /no automatic external writes/.test(spec)],
 ['no fuzzy matching invariant', /no fuzzy entity matching/.test(spec)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Opportunities v23.20 contract: ${checks.length}/${checks.length} passed`);
