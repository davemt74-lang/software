import assert from 'node:assert/strict';
import fs from 'node:fs';

const profile = fs.readFileSync('profile.php', 'utf8');

assert.match(profile, /function vp3_profile_optional_failure/, 'profile must own a bounded optional-stage error logger');
assert.match(profile, /profile_runtime_record_view[\s\S]*catch \(Throwable \$e\)[\s\S]*view-telemetry/, 'view telemetry must fail soft');
assert.match(profile, /profile_public_catalog[\s\S]*catch \(Throwable \$e\)[\s\S]*public-catalog/, 'public catalog projection must fail soft');
assert.match(profile, /profile_active_agent[\s\S]*catch \(Throwable \$e\)[\s\S]*profile-agent/, 'Profile Agent lookup must fail soft');
assert.match(profile, /profile_chat_token[\s\S]*catch \(Throwable \$e\)[\s\S]*profile-agent-token/, 'Profile Agent token creation must fail soft');
assert.match(profile, /profile_commerce_products_for_profile_v900[\s\S]*catch \(Throwable \$e\)[\s\S]*commerce/, 'Commerce projection must fail soft');
assert.match(profile, /agent_scheduling_public_schedule_v450[\s\S]*catch \(Throwable \$e\)[\s\S]*scheduling/, 'Scheduling projection must fail soft');
assert.match(profile, /\$catalog=\['workspace'=>null,'tracks'=>\[\],'albums'=>\[\],'photos'=>\[\],'posts'=>\[\],'merch'=>\[\],'shows'=>\[\]\]/, 'profile must have a safe empty public-catalog fallback');
assert.match(profile, /\$commerceProducts=\[\]/, 'profile must have a safe empty Commerce fallback');
assert.match(profile, /\$publicSchedule=null;\$bookingTypes=\[\]/, 'profile must have a safe empty Scheduling fallback');
assert.match(profile, /http_response_code\(503\)[\s\S]*Profile temporarily unavailable/, 'core profile lookup failures must return a bounded 503 instead of an uncaught 500');
assert.doesNotMatch(profile, /catch \(Throwable \$e\) \{\s*\}/, 'optional profile failures must be logged rather than silently swallowed');

console.log('profile runtime resilience contract: PASS');
