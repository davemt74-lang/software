#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def replace(path,old,new):
    p=ROOT/path
    text=p.read_text()
    if old not in text:
        raise SystemExit(f'Expected fragment not found in {path}: {old[:120]!r}')
    p.write_text(text.replace(old,new,1))

def insert_before(path,marker,addition):
    p=ROOT/path
    text=p.read_text()
    if marker not in text:
        raise SystemExit(f'Marker not found in {path}: {marker[:120]!r}')
    p.write_text(text.replace(marker,addition+marker,1))

# Profile visitor labels distinguish guests from authenticated members without
# disclosing identity unless the visitor opted into it.
replace('includes/profile-agent.php',
"function profile_visitor_label(PDO $pdo, array $session): string\n{\n    if(empty($session['identity_disclosed'])||(int)($session['visitor_user_id']??0)<1)return 'Someone';\n    $u=profile_user_row($pdo,(int)$session['visitor_user_id']);return trim((string)($u['display_name']??''))?:'A Stonefellow member';\n}",
"function profile_visitor_label(PDO $pdo, array $session): string\n{\n    $visitorId=(int)($session['visitor_user_id']??0);\n    if($visitorId<1)return 'Guest visitor';\n    if(empty($session['identity_disclosed']))return 'Signed-in member';\n    $u=profile_user_row($pdo,$visitorId);return trim((string)($u['display_name']??''))?:('A '.system_agent_name().' member');\n}")
replace('includes/profile-agent.php',
"if($type==='profile_view'){$headline=$label.' viewed your profile';$summary=$summary?:'A visitor landed on your public Stonefellow profile.';$assessment='Profile interest detected.';$recommended='No response is required unless their activity becomes more meaningful.';$actions=['open_profile','ignore'];}",
"if($type==='profile_view'){$headline=$label.' viewed your profile';$summary=$summary?:('A visitor landed on your public '.system_agent_name().' profile.');$assessment='Profile interest detected.';$recommended='No response is required unless their activity becomes more meaningful.';$actions=['open_profile','ignore'];}")
replace('includes/profile-agent.php',
"        create_notification($owner,'profile_'.$type,$headline,$summary,url('/account.php#profile-agent'),'profile_event',(int)$event['id']);",
"        $target=$conversationId?url('/profile-agent.php?tab=inbox&conversation='.(int)$conversationId):url('/profile-agent.php?tab=visitors&session='.(int)$session['id']);\n        create_notification($owner,'profile_'.$type,$headline,$summary,$target,'profile_event',(int)$event['id']);")

# Agent Chat context can answer owner questions about current profile visitors
# and Profile Agent conversations using the same privacy-aware descriptor as UI.
profile_context = r'''function chat_policy_profile_activity_v236(PDO $pdo,array $user,string $query): array
{
    if(!function_exists('profile_agent_schema_ready')||!profile_agent_schema_ready($pdo)||!function_exists('profile_runtime_visitor_descriptor'))return [];
    if(!preg_match('/\b(profile(?:\s+agent)?|visitor|visitors|visited|visiting|profile\s+activity|on\s+my\s+profile|who(?:\x27s|\s+is)\s+on|asked\s+my\s+agent|profile\s+conversation)\b/i',$query))return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    $lines=[];
    $visits=$pdo->prepare('SELECT id,visitor_user_id,identity_disclosed,view_count,first_seen_at,last_seen_at,last_message_at FROM profile_visit_sessions WHERE owner_user_id=? AND view_count>0 ORDER BY last_seen_at DESC,id DESC LIMIT 12');
    $visits->execute([$uid]);
    foreach($visits->fetchAll()?:[] as $row){
        $d=profile_runtime_visitor_descriptor($pdo,$uid,$row);$active=!empty($row['last_seen_at'])&&strtotime((string)$row['last_seen_at'])>=time()-300;
        $identity=(string)$d['visitor_label'];if(!empty($d['username']))$identity.=' @'.(string)$d['username'];if(($d['relationship_scope']??'none')!=='none')$identity.=' · '.(string)$d['relationship_scope'];
        $lines[]=$identity.' · '.((bool)$d['signed_in']?'signed in':'guest').' · '.($active?'active now':'last seen '.(string)$row['last_seen_at']).' · '.(int)$row['view_count'].' profile view'.((int)$row['view_count']===1?'':'s').(!empty($row['last_message_at'])?' · has chatted':'');
    }
    $conversations=$pdo->prepare('SELECT c.id,c.status,c.last_summary,c.last_message_at,s.identity_disclosed,s.visitor_user_id FROM profile_agent_conversations c INNER JOIN profile_visit_sessions s ON s.id=c.profile_session_id WHERE c.owner_user_id=? ORDER BY c.last_message_at DESC,c.id DESC LIMIT 10');
    $conversations->execute([$uid]);$conversationLines=[];
    foreach($conversations->fetchAll()?:[] as $row){$d=profile_runtime_visitor_descriptor($pdo,$uid,$row);$conversationLines[]='#'.(int)$row['id'].' · '.(string)$d['visitor_label'].' · '.(string)$row['status'].' · '.(string)$row['last_summary'].' · last message '.(string)$row['last_message_at'];}
    $text="Current owner-only Profile Agent activity. Identity is included only where the visitor explicitly disclosed it.\nVisitors:\n".($lines?implode("\n",$lines):'No recent visitors.')."\nConversations:\n".($conversationLines?implode("\n",$conversationLines):'No Profile Agent conversations.');
    return [['source'=>'profile:activity','title'=>'Your Profile Agent activity','text'=>mb_strimwidth($text,0,9000,'…')]];
}

'''
insert_before('includes/chat-agent-policy-v236.php','function chat_policy_context_v236(string $query,array $user,array $principal,int $conversationId=0): array\n',profile_context)
replace('includes/chat-agent-policy-v236.php',
"    foreach(chat_policy_workspace_context_v236($pdo,$principal,$user,$query,$terms,$conversationId) as $item)$context[]=$item;",
"    foreach(chat_policy_profile_activity_v236($pdo,$user,$query) as $item)$context[]=$item;\n    foreach(chat_policy_workspace_context_v236($pdo,$principal,$user,$query,$terms,$conversationId) as $item)$context[]=$item;")

# Chat bootstraps the dedicated canvas and shares the canonical Profile Agent
# owner endpoint with both proactive attention and the new canvas runtime.
replace('chat.php',"$agentIdentityBuild = 'live-wiring-20260903-3';","$agentIdentityBuild = 'profile-activity-20260905';\n$profileActivityBuild = 'profile-activity-20260905';")
replace('chat.php',
"    . ',accountUrl:' . json_encode(url('/account.php#agents-data'), JSON_UNESCAPED_SLASHES)\n    . ',csrf:' . json_encode(csrf_token(), JSON_UNESCAPED_SLASHES)",
"    . ',accountUrl:' . json_encode(url('/account.php#agents-data'), JSON_UNESCAPED_SLASHES)\n    . ',profileAgentEndpoint:' . json_encode(url('/api/profile-agent.php'), JSON_UNESCAPED_SLASHES)\n    . ',profileAgentUrl:' . json_encode(url('/profile-agent.php'), JSON_UNESCAPED_SLASHES)\n    . ',csrf:' . json_encode(csrf_token(), JSON_UNESCAPED_SLASHES)")
marker="$agentIdentityRuntime = $agentFeatureReady\n    ? '<link rel=\"stylesheet\" data-chat-agent-identity-v236 href=\"' . e(url('/chat-agent-identity-v236.css?v=' . $agentIdentityBuild)) . '\">'\n        . '<script data-chat-agent-identity-v236 src=\"' . e(url('/chat-agent-identity-v236.js?v=' . $agentIdentityBuild)) . '\"></script>'\n    : '';\n\n"
activity_runtime=marker+"$profileActivityRuntime = $agentFeatureReady && $pdoForAgent && profile_agent_schema_ready($pdoForAgent)\n    ? '<link rel=\"stylesheet\" data-profile-activity-chat href=\"' . e(url('/profile-activity-chat.css?v=' . $profileActivityBuild)) . '\">'\n        . '<script data-profile-activity-chat src=\"' . e(url('/profile-activity-chat.js?v=' . $profileActivityBuild)) . '\"></script>'\n    : '';\n\n"
replace('chat.php',marker,activity_runtime)
replace('chat.php',"         . $agentIdentityRuntime\n         . '<script data-team-chat-admin-v109", "         . $agentIdentityRuntime\n         . $profileActivityRuntime\n         . '<script data-team-chat-admin-v109")
replace('chat-agent-identity-v236.js',"  const build='live-wiring-20260903-2';","  const build='profile-activity-20260905';")

# Portal visitor cards consume enriched identity and notification deep links.
replace('profile-agent-portal.js',
"let state=null,selectedConversation=0,refreshTimer=null,requestBusy=false;",
"let state=null,selectedConversation=0,selectedSession=0,refreshTimer=null,requestBusy=false;")
insert_before('profile-agent-portal.js','function renderVisitors(){\n',r'''function visitorAvatar(v){if(v?.identity_disclosed&&v.avatar_url)return `<img src="${esc(v.avatar_url)}" alt="">`;return `<span>${esc(String(v?.visitor_label||'Visitor').charAt(0).toUpperCase()||'?')}</span>`;}
function visitorMeta(v){if(v?.identity_disclosed){const bits=[];if(v.username)bits.push(`@${v.username}`);if(v.role_label)bits.push(v.role_label);if(v.relationship_scope&&v.relationship_scope!=='none')bits.push(v.relationship_scope);return bits.join(' · ');}return v?.signed_in?'Signed-in member · identity private':'Guest visitor';}
''')
old_render="""function renderVisitors(){
  const rows=Array.isArray(state?.visits)?state.visits:[];
  visitorsHost.innerHTML=rows.length?rows.map(v=>{const live=recentlyActive(v.last_seen_at);return `<article class=\"profile-agent-visitor-row\"><div class=\"profile-agent-row-main\"><strong>${esc(v.visitor_label||'Visitor')}</strong><p>${Number(v.view_count||0)} profile view${Number(v.view_count||0)===1?'':'s'}${v.last_message_at?' · has chatted':''}</p><small>First seen ${relative(v.first_seen_at)} · last seen ${relative(v.last_seen_at)}</small></div><div class=\"profile-agent-presence ${live?'live':''}\">${live?'Active now':'Recent'}</div></article>`;}).join(''):`<div class=\"profile-agent-empty\">No profile visitors yet.</div>`;
}
"""
new_render="""function renderVisitors(){
  const rows=Array.isArray(state?.visits)?state.visits:[];
  visitorsHost.innerHTML=rows.length?rows.map(v=>{const live=!!v.active_now||recentlyActive(v.last_seen_at),selected=Number(v.profile_session_id||v.id)===selectedSession;return `<article class=\"profile-agent-visitor-row ${selected?'selected':''}\" data-profile-session=\"${Number(v.profile_session_id||v.id||0)}\"><div class=\"profile-agent-visitor-avatar\">${visitorAvatar(v)}</div><div class=\"profile-agent-row-main\"><strong>${esc(v.visitor_label||'Visitor')}</strong><p>${esc(visitorMeta(v))}</p><small>${Number(v.view_count||0)} profile view${Number(v.view_count||0)===1?'':'s'}${v.last_message_at?' · has chatted':''} · first seen ${relative(v.first_seen_at)} · last seen ${relative(v.last_seen_at)}</small>${v.profile_url?`<a href=\"${esc(v.profile_url)}\" target=\"_blank\" rel=\"noopener\">View profile ↗</a>`:''}</div><div class=\"profile-agent-presence ${live?'live':''}\">${live?'Active now':v.signed_in?'Member':'Guest'}</div></article>`;}).join(''):`<div class=\"profile-agent-empty\">No profile visitors yet.</div>`;
}
"""
replace('profile-agent-portal.js',old_render,new_render)
replace('profile-agent-portal.js',
"function renderAll(){renderService();renderMetrics();renderInbox();renderVisitors();renderAgentSettings();renderKnowledge();renderProfileSettings();renderAnalytics();bindDynamic();}",
"function renderAll(){renderService();renderMetrics();renderInbox();renderVisitors();renderAgentSettings();renderKnowledge();renderProfileSettings();renderAnalytics();const unread=Number(state?.notifications?.unread||0),bell=document.getElementById('chatNotificationButton');if(bell){let badge=bell.querySelector(':scope > span');if(unread>0){if(!badge){badge=document.createElement('span');bell.appendChild(badge);}badge.textContent=unread>99?'99+':String(unread);}else badge?.remove();}bindDynamic();}")
replace('profile-agent-portal.js',
"try{activateTab(localStorage.getItem('profile-agent-portal-tab')||'inbox');}catch(e){activateTab('inbox');}\nrefresh(true);",
"const deepLink=new URLSearchParams(window.location.search);selectedConversation=Number(deepLink.get('conversation')||0);selectedSession=Number(deepLink.get('session')||0);let initialTab=deepLink.get('tab')||'';try{if(!initialTab)initialTab=localStorage.getItem('profile-agent-portal-tab')||'inbox';}catch(e){if(!initialTab)initialTab='inbox';}activateTab(initialTab);\nrefresh(true).then(()=>{if(selectedConversation){activateTab('inbox');openConversation(selectedConversation);}else if(selectedSession){activateTab('visitors');renderVisitors();const row=visitorsHost.querySelector(`[data-profile-session=\"${selectedSession}\"]`);row?.scrollIntoView({block:'center'});}});")
replace('profile-agent.php','profile-agent-portal.css?v=profile-agent-service-shell-20260905','profile-agent-portal.css?v=profile-activity-20260905')
replace('profile-agent.php','member-shell-v77.js?v=profile-agent-service-shell-20260905','member-shell-v77.js?v=profile-activity-20260905')
replace('profile-agent.php','profile-agent-portal.js?v=profile-agent-service-shell-20260905','profile-agent-portal.js?v=profile-activity-20260905')

# Baseline owns the new integration contract.
replace('tools/run_recovery_baseline.py',
"    'tests/profile-agent-attention-contract.mjs',\n",
"    'tests/profile-agent-attention-contract.mjs',\n    'tests/profile-activity-chat-contract.mjs',\n")

print('PROFILE_ACTIVITY_PHASE=PATCHED')
