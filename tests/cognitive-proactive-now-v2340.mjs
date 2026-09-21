import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const feed=read('includes/cognitive-feed-v530.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const extension=read('includes/extension-notifications-v2140.php');
const browser=read('api/extension-cognitive-now-v2120.php');
const chatJs=read('chat-cognitive-feed-v530.js');
const chatCss=read('chat-cognitive-feed-v530.css');
const bootstrap=read('includes/bootstrap.php');
const spec=read('docs/VP3_COGNITIVE_PROACTIVE_NOW_V2340.md');

const checks=[
 ['presentation only', /Presentation-only projection/.test(proactive)],
 ['no schema', !/CREATE TABLE|ALTER TABLE|INSERT INTO|DELETE FROM|UPDATE .* SET/i.test(proactive)],
 ['bounded focus', /MAX_FOCUS_V2340=3/.test(proactive)],
 ['selected queue authority', /selected_queue_brief/.test(proactive) && /cognitive_priority_queue_v2310/.test(proactive)],
 ['timezone from calendar', /user_calendar_default_timezone_v1300/.test(proactive)],
 ['voice preference reused', /chat_settings_get_v237/.test(proactive)],
 ['voice context aggregate only', /Your Agent brief also has/.test(proactive) && !/reason.*voice_context|title.*voice_context/.test(proactive)],
 ['no standalone opportunity voice', /standalone_opportunity_voice'=>false/.test(proactive)],
 ['existing delivery authority retained', /cognitive_presentation_v510_and_extension_notifications_v2140/.test(proactive)],
 ['feed exposes proactive brief', /vp3_cognitive_proactive_now_compose_v2340/.test(feed) && /'proactive_brief'=>\$proactiveBrief/.test(feed)],
 ['chat renders proactive brief', /data-cognitive-proactive-brief/.test(chatJs) && /vp3-cognitive-proactive-brief-v2340/.test(chatCss)],
 ['focus links existing cards', /data-proactive-focus/.test(chatJs) && /scrollIntoView/.test(chatJs)],
 ['browser receives same brief', /'proactive_brief'=>is_array\(\$feed\['proactive_brief'\]/.test(browser)],
 ['return digest enriched before persistence', /vp3_cognitive_proactive_now_digest_summary_v2340/.test(presentation) && presentation.indexOf('vp3_cognitive_proactive_now_digest_summary_v2340')<presentation.indexOf('INSERT INTO cognitive_return_digests_v510')],
 ['immediate voice function unchanged authority', /vp3_cognitive_presentation_voice_candidate_v510/.test(presentation) && /last_voice_notification_id/.test(presentation)],
 ['shared Chrome cursor retained', /vp3_cognitive_presentation_voice_candidate_v510/.test(extension) && /voice_through_notification_id/.test(extension)],
 ['Chrome reuses persisted return digest', /vp3_cognitive_presentation_open_digest_v510/.test(extension) && /voice_candidate_v510\(\$pdo,\$user,\$state,\$digest\)/.test(extension)],
 ['bootstrap order', bootstrap.indexOf('cognitive-proactive-now-v2340.php')<bootstrap.indexOf('cognitive-feed-v530.php') && bootstrap.indexOf('cognitive-feed-v530.php')<bootstrap.indexOf('cognitive-presentation-v510.php')],
 ['no second voice ledger invariant', /no new voice-delivery table/.test(spec)],
 ['pure opportunities visual only invariant', /Pure opportunities remain visual\/proposal-only/.test(spec)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Proactive Agent Now v23.40 contract: ${checks.length}/${checks.length} passed`);
