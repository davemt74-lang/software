import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
const read=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

const php=read('api/agent-missions-cloud-v1.php');
const chat=read('chat.php');
const js=read('chat-agent-teams-a3.js');
const css=read('chat-agent-teams-a3.css');

execFileSync('node',['--check',new URL('../chat-agent-teams-a3.js',import.meta.url).pathname],{stdio:'pipe'});

assert.match(php,/require_permission\('chat\.access'\)/,'must use the canonical Cloud chat permission');
assert.match(php,/hash_equals\(csrf_token\(\)/,'must enforce CSRF on every operation');
assert.match(php,/current_user\(\)/,'must bind to authenticated user');
assert.match(php,/homeserver_https_v1300_status\(\$userId\)/,'must verify live per-user pairing');
assert.match(php,/homeserver_https_v1300_queue\(\$userId/,'must route through owner-scoped HTTPS queue');
assert.match(php,/homeserver_https_v1300_wait\(/,'must inspect actual HomeServer results');
assert.match(php,/agent\.missions\./,'must use allowlisted operation namespace');
assert.doesNotMatch(php,/file_get_contents\(['"]https?:/,'no arbitrary network fetch');
assert.doesNotMatch(php,/api\/v1\/control/,'must not use owner endpoints');

for(const action of ['list','get','create','start','pause','resume','retry','cancel','events']){
 assert.match(php,new RegExp("'"+action+"'"),action+' must be allowlisted');
}
assert.match(php,/allow_reexecution/,'resume must not replay silently');
assert.match(php,/request_id/,'create must use idempotency key');
assert.match(php,/thread_id/,'create must bind to Cloud conversation');
assert.match(php,/no-store/,'private mission responses must not be cached');

assert.match(chat,/data-agent-teams-a3/,'Chat must load the embedded runtime');
assert.match(chat,/chat-agent-teams-a3\.js/);
assert.match(chat,/chat-agent-teams-a3\.css/);
assert.match(chat,/VP3_AGENT_TEAMS_A3/);
assert.match(js,/chatComposerShell/,'use canonical composer');
assert.match(js,/insertBefore\(root,form\)/,'do not replace chat canvas');
assert.match(js,/window\.crypto\.randomUUID/,'use stable unique creation request key');
assert.match(js,/textContent/,'render dynamic content without HTML injection');
assert.match(js,/credentials:'same-origin'/,'never expose relay tokens in browser');
assert.match(js,/window\.confirm/,'require explicit confirmation before replay');
assert.match(js,/setInterval/,'support live progress refresh');
assert.match(js,/document\.hidden/,'suspend hidden-tab polling');
assert.match(js,/aria-live="polite"/,'announcements must be accessible');
assert.match(css,/max-height:/,'bound panel height inside chat');
assert.match(css,/@media\(max-width:600px\)/,'mobile responsive layout');
assert.doesNotMatch(js,/innerHTML\s*=\s*(?:[a-zA-Z_$][\w$]*\.)?objective/,'never interpolate user objective into innerHTML');

console.log('CLOUD_MISSION_A3 PASS: auth and CSRF relay contract, chat canvas wiring, safe rendering, reviewed replay and UI syntax');
