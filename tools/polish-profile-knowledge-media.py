#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def read(p): return (ROOT/p).read_text()
def write(p,s): (ROOT/p).write_text(s)
def once(s,a,b,label):
    if s.count(a)!=1: raise SystemExit(f'{label}: expected 1 match, got {s.count(a)}')
    return s.replace(a,b,1)

# Private personal Knowledge is eligible for Profile Agent retrieval only through the policy gate.
p='includes/profile-agent.php'; s=read(p)
s=once(s,
"$stmt=$pdo->prepare('SELECT id FROM knowledge_items WHERE created_by_user_id=? AND is_published=1 ORDER BY updated_at DESC,id DESC LIMIT 120');$stmt->execute([$owner]);",
"$stmt=$pdo->prepare('SELECT id FROM knowledge_items WHERE created_by_user_id=? ORDER BY updated_at DESC,id DESC LIMIT 120');$stmt->execute([$owner]);",
'owner knowledge query')
s=once(s,
"$item=shared_knowledge_index_item_v236($pdo,(int)$kid);if(!$item)continue;$rid=(string)(int)$kid;$legacy=((string)($item['visibility']??'members')==='public');",
"$item=shared_knowledge_index_item_v236($pdo,(int)$kid);if(!$item)continue;$rid=(string)(int)$kid;$legacy=!empty($item['is_published'])&&((string)($item['visibility']??'members')==='public');",
'legacy knowledge visibility')
write(p,s)

# Explain the private-KB policy in the service UI.
p='profile-agent-portal.js'; s=read(p)
s=once(s,
"<div class=\"profile-agent-card\"><h2>Knowledge Access</h2><p>Your Profile Agent can only use resources explicitly allowed here, and each resource must still pass its own audience/visibility rules.</p>",
"<div class=\"profile-agent-card\"><h2>Knowledge Access</h2><p>Your Profile Agent can use your personal Knowledge Base only when you explicitly allow Knowledge here and the selected audience permits that visitor. Unpublished personal notes are never exposed by inherited visibility alone.</p>",
'knowledge access copy')
write(p,s)

# Strengthen contract around personal knowledge privacy.
p='tests/profile-knowledge-media-contract.mjs'; s=read(p)
s=s.replace("assert.ok(profile.includes(\"created_by_user_id=? AND is_published=1\"),'Profile Agent knowledge must be owner-scoped and published');",
"assert.ok(profile.includes(\"SELECT id FROM knowledge_items WHERE created_by_user_id=? ORDER BY\"),'Profile Agent must consider the owner personal Knowledge Base');\nassert.ok(!profile.includes(\"created_by_user_id=? AND is_published=1 ORDER BY\"),'personal Knowledge retrieval must not be restricted to published rows');")
s=s.replace("assert.ok(profile.includes(\"user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge'\"),'knowledge retrieval must pass canonical data policy');",
"assert.ok(profile.includes(\"user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge'\"),'knowledge retrieval must pass canonical data policy');\nassert.ok(profile.includes(\"$legacy=!empty($item['is_published'])&&((string)($item['visibility']??'members')==='public')\"),'inherit visibility must never make unpublished personal notes public');")
write(p,s)
print('PROFILE_KNOWLEDGE_MEDIA_POLISH=PASS')
