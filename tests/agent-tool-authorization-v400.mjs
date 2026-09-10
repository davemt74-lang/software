import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=path=>fs.readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const boundary=read('includes/agent-tool-authorization-v400.php');
const chat=read('api/chat-v236.php');
const legacyTools=read('includes/agent-tools-v84.php');
const resources=read('includes/music-workspace-resources-v330.php');
const releaseWorkspace=read('includes/release-workspace-v332.php');
const homeApprovals=read('includes/homeserver-approvals-v028.php');
const actionSystem=read('includes/agent-action-system-v124.php');
const browser=read('chat.js');

assert.match(boundary,/VP3_AGENT_TOOL_AUTHORIZATION_V400/);
assert.match(chat,/agent-tool-authorization-v400\.php/);
assert.match(chat,/vp3_agent_tool_execute_query_v400\(\$query,\$user,\$conversationId\)/);
assert.match(chat,/vp3_agent_tool_authorize_result_v400\(\$toolResult,\$user,\$query\)/);
assert.doesNotMatch(chat,/if\(empty\(\$toolResult\['handled'\]\)\)\$toolResult=agent_tool_execute_query\(/,'Agent Chat must not call the legacy executable tool layer directly');

// Legacy v84 still documents why this boundary exists, but its global professional
// shortcuts are not the active Chat authorization decision anymore.
assert.match(legacyTools,/\['manager','producer','supervisor','admin'\]/);
assert.match(boundary,/function vp3_agent_tool_track_manage_v400/);
const trackBoundary=boundary.match(/function vp3_agent_tool_track_manage_v400[\s\S]*?\n}/)?.[0]||'';
assert.ok(trackBoundary,'track authorization helper is inspectable');
assert.match(trackBoundary,/music_workspace_resources_v330_can_manage_track/);
assert.doesNotMatch(trackBoundary,/has_permission\s*\(/,'resource authorization does not inherit legacy permission rows');
assert.doesNotMatch(trackBoundary,/user_has_role\s*\(\s*['"](?:manager|producer|supervisor)['"]/,'resource authorization does not inherit legacy professional roles');
assert.doesNotMatch(trackBoundary,/\$user\s*\[\s*['"]role['"]\s*\]/,'resource authorization never trusts a copied primary role');
assert.match(trackBoundary,/owner_user_id/);
assert.match(trackBoundary,/producer_user_id/);
assert.match(resources,/professional music catalog, production, release and credit resources resolve to/);
assert.match(resources,/function music_workspace_resources_v330_can_manage_track/);
assert.match(resources,/if\(\$role==='producer'\)return \(int\)\(\$track\['producer_user_id'\]/);

// Production discovery is filtered row-by-row against the exact track. Public
// visibility or another workspace's role/permission can never make a stem editable.
assert.match(boundary,/function vp3_agent_tool_find_track_v400/);
assert.match(boundary,/if\(!vp3_agent_tool_track_manage_v400\(\$pdo,\$track,\$user\)\)continue/);
assert.match(boundary,/function vp3_agent_tool_search_stems_v400/);
assert.match(boundary,/if\(!vp3_agent_tool_track_manage_v400\(\$pdo,\$row,\$user\)\)continue/);
assert.match(boundary,/I could not find a matching production track that your current Music Workspace role is authorized to open/);

// Booking/listener intelligence is scoped to workspaces where the current principal
// can manage shows. The legacy global tracks.manage switch is absent from v4.00.
assert.match(boundary,/function vp3_agent_tool_booking_workspace_ids_v400/);
assert.match(boundary,/music_workspace_resources_v330_can_manage\(\$pdo,\$workspaceId,'shows',\$user\)/);
assert.match(boundary,/t\.workspace_id IN \(\{\$in\}\)/);
assert.match(boundary,/shows WHERE show_date>=NOW\(\) AND workspace_id IN/);
assert.doesNotMatch(boundary,/\?=1 OR t\.owner_user_id|has_permission\('tracks\.manage'/);

// Returned executable actions are rebuilt by the server. Client/model supplied
// auto flags, external schemes and unknown action types never become authority.
assert.match(boundary,/function vp3_agent_tool_internal_action_v400/);
assert.match(boundary,/if\(!in_array\(\$mode,\['camera','photo','video','audio'\],true\)\)return null/);
assert.match(boundary,/preg_match\('#\^\[a-z\]\[a-z0-9\+\.\-\]\*:#i',\$raw\)/);
assert.match(boundary,/if\(\$type!=='open_url'\)return null/);
assert.doesNotMatch(boundary,/\$action\['auto'\]/,'incoming auto flags are ignored');
assert.match(boundary,/'server_derived'=>true/);
assert.match(boundary,/'domain'=>'browser_local'/);
assert.match(boundary,/'domain'=>'navigation'/);
assert.match(boundary,/admin\/stems\.php/);
assert.match(boundary,/vp3_agent_tool_track_manage_v400\(\$pdo,\$track,\$user\)/);
assert.match(boundary,/music-releases\.php/);
assert.match(boundary,/music_workspace_resources_v330_can_manage\(\$pdo,\$workspaceId,'releases',\$user\)/);

// The browser may auto-run only the sanitized server response; historical stored
// action metadata is rendered but not replayed automatically on load.
assert.match(browser,/\(data\.actions\|\|\[\]\)\.find\(action=>action && action\.auto && action\.url\)/);
assert.match(browser,/\(data\.actions\|\|\[\]\)\.find\(action=>action && action\.auto && action\.type==='media_capture'\)/);

// External provider work remains owner-scoped and approval-first; HomeServer
// approval execution remains delegated to HomeServer rather than VP3.
assert.match(releaseWorkspace,/Only the Music Workspace owner can queue external provider actions/);
assert.match(releaseWorkspace,/\$requiresApproval\?'draft':'queued'/);
assert.match(actionSystem,/requires_approval.*external\|\|\$destructive/);
assert.match(homeApprovals,/\$operation = \$decision === 'approve' \? 'action\.approve' : 'action\.deny'/);
assert.match(homeApprovals,/homeserver_vp3_remote_operation\(\$credentials\['relay'\], \$operation/);
assert.doesNotMatch(homeApprovals,/DELETE FROM|UPDATE agent_work_actions|INSERT INTO agent_work_actions/,'VP3 approval review does not execute HomeServer actions locally');

console.log('AGENT_TOOL_AUTHORIZATION_V400=PASS');
