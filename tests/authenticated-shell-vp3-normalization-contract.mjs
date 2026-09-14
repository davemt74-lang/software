import fs from 'node:fs';
import assert from 'node:assert/strict';

const header = fs.readFileSync('includes/header.php', 'utf8');
const memberShell = fs.readFileSync('member-shell-v77.js', 'utf8');
const profileSocial = fs.readFileSync('profile-social-v320.js', 'utf8');
const memberHeader = fs.readFileSync('includes/member-header.php', 'utf8');

assert.match(header, /\$headerBrandName\s*=\s*\$headerUser\s*\?\s*'VP3'\s*:\s*'Stonefellow'/, 'authenticated legacy header must render VP3 while preserving guest Stonefellow surfaces');
assert.match(header, /aria-label="<\?= e\(\$headerBrandName\) \?> home"/, 'header brand aria label must follow the active brand');
assert.match(header, />\s*<\?= e\(\$headerBrandName\) \?>\s*<\/a>/, 'header logo text must follow the active brand');

assert.match(memberHeader, /member-shell-v77\.js/, 'shared member header must load the active v77 shell');
assert.match(memberShell, /window\.VP3_PROFILE_AGENT\s*\|\|\s*window\.STONEFELLOW_PROFILE_AGENT/, 'member shell must prefer canonical VP3 Profile Agent config with legacy fallback');
assert.match(memberShell, /window\.VP3_PROFILE_AGENT\s*=\s*profileAgent/, 'member shell must bridge legacy Profile Agent config to the canonical VP3 namespace');
assert.doesNotMatch(memberShell, /contains\('profile-page'\)\s*&&\s*window\.STONEFELLOW_PROFILE_AGENT/, 'active shell must not gate profile behavior directly on the legacy namespace');
assert.match(profileSocial, /window\.VP3_PROFILE_AGENT\|\|window\.STONEFELLOW_PROFILE_AGENT/, 'profile social runtime must prefer canonical VP3 config');

console.log('Authenticated shell VP3 normalization contract passed.');
