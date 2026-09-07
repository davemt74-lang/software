import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const profiles = read('includes/agent-radar-access-profiles.php');
const gateway = read('includes/agent-radar-gateway.php');
const policyApi = read('api/agent-radar-policy.php');
const chat = read('includes/agent-radar-chat.php');
const ui = read('profile-agent-radar-gateway.js');
const css = read('profile-agent-radar-gateway.css');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-access-profiles.php';"), 'bootstrap must load Access Profiles');
assert.ok(bootstrap.includes("require_once __DIR__.'/vp3-analytics-chat.php';"), 'bootstrap must load Analytics chat runtime');

for (const slug of ['open_web','search_friendly','agent_friendly','private','locked_down','monitor_all']) {
  assert.ok(profiles.includes(`'${slug}'=>[`), `Access Profile catalog must contain ${slug}`);
}
assert.ok(profiles.includes("'source'=>'access_profile'"), 'Access Profile policies must be source tagged');
assert.ok(profiles.includes("agent_contact_id IS NULL"), 'Access Profiles must be defaults, not per-contact policies');
assert.ok(profiles.includes("'rules'=>$rules"), 'public profile catalog must expose safe class defaults for inherited UI state');

assert.ok(gateway.includes("$score+=1000"), 'contact policy must have highest base specificity');
assert.ok(gateway.includes("$score+=400+min(80,strlen($pathPattern))"), 'path-specific rules must beat broad class/operator defaults');
assert.ok(gateway.includes('VP3_RADAR_GATEWAY_CLASSES'), 'Gateway must maintain a bounded class catalog');
assert.ok(gateway.includes('vp3_radar_gateway_set_scoped_rule'), 'Gateway must support operator/class/path rules');
assert.ok(gateway.includes('vp3_radar_gateway_delete_scoped_rule'), 'Gateway scoped rules must be removable');
assert.ok(gateway.includes("['operator','class','path']"), 'scoped rule types must be explicitly bounded');
assert.ok(gateway.includes("$value[0]!=='/'"), 'path rules must require root-relative paths');
assert.ok(gateway.includes("preg_replace('/[?#].*$/'"), 'path rules must strip query strings and fragments');
assert.ok(gateway.includes("'source'=>'scoped_rule'"), 'advanced rules must be distinguishable from Access Profile defaults');
assert.ok(gateway.includes('owner_user_id=?'), 'Gateway rule reads/writes must remain owner scoped');

assert.ok(policyApi.includes("$action==='apply_access_profile'"), 'policy API must support Access Profiles');
assert.ok(policyApi.includes("$action==='set_scoped_rule'"), 'policy API must support advanced rules');
assert.ok(policyApi.includes("$action==='delete_scoped_rule'"), 'policy API must support advanced rule removal');
assert.ok(policyApi.includes('hash_equals(csrf_token(),$csrf)'), 'Gateway writes must remain CSRF protected');
assert.ok(policyApi.includes("'scoped_rules'=>vp3_radar_gateway_scoped_rules"), 'policy API must return current scoped rules');

assert.ok(ui.includes('Default Access Profile'), 'Radar UI must expose one simple default Access Profile');
assert.ok(ui.includes('profile default'), 'contact rows must visibly distinguish inherited profile behavior');
assert.ok(ui.includes('Per-contact controls below always override this default'), 'UI must explain precedence simply');
assert.ok(ui.includes('Advanced Rules'), 'operator/class/path controls must be optional advanced UI');
assert.ok(ui.includes("data-scoped-rule-form"), 'Advanced Rules must use a bounded form');
assert.ok(ui.includes("action:'set_scoped_rule'"), 'Advanced Rules UI must save through canonical policy API');
assert.ok(ui.includes("action:'delete_scoped_rule'"), 'Advanced Rules UI must remove rules through canonical policy API');
assert.ok(css.includes('.profile-agent-radar-advanced'), 'Advanced Rules must have isolated styling');
assert.ok(css.includes('@media(max-width:480px)'), 'Gateway controls must remain usable on small screens');

assert.ok(chat.includes('vp3_radar_chat_access_profile_action'), 'Main Feed must control Access Profiles');
assert.ok(chat.includes('vp3_radar_chat_scoped_action'), 'Main Feed must control scoped Gateway rules');
assert.ok(chat.includes("'block operator'"), 'Radar chat intent must include operator-wide actions');
assert.ok(chat.includes("'block all crawlers'"), 'Radar chat intent must include class-wide actions');
assert.ok(chat.includes('Contact-specific overrides still take precedence'), 'chat must explain scoped-rule precedence');

console.log('AGENT_RADAR_ACCESS_CONTROLS_CONTRACT=PASS');
