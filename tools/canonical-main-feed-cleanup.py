from pathlib import Path
import re


def expect_once(text: str, needle: str, label: str) -> str:
    count = text.count(needle)
    if count != 1:
        raise SystemExit(f"{label}: expected 1 match, found {count}")
    return text


# Canonical Main Feed template: navigation and no parallel notification panel.
p = Path("chat-legacy-v108.php")
text = p.read_text()
old_brand = '<a class="chat-brand" href="<?= e(url(\'/chat.php\')) ?>">Stonefellow</a>'
new_brand = '<a class="chat-brand" href="<?= e(url(\'/\')) ?>">Stonefellow</a>'
expect_once(text, old_brand, "chat brand")
text = text.replace(old_brand, new_brand, 1)
expect_once(text, '<strong>Playlists</strong>', "Playlists label")
text = text.replace('<strong>Playlists</strong>', '<strong>My Playlists</strong>', 1)

marker = '''          <button
            class="chat-sidebar-nav-link"
            type="button"
            data-chat-view-target="player"
          >'''
expect_once(text, marker, "player nav marker")
direct_links = '''          <?php if (personal_capability_has_v242('profile_agent.access', $user)): ?>
          <a class="chat-sidebar-nav-link" href="<?= e(url('/profile-agent.php')) ?>">
            <span>◎</span>
            <strong>Profile Agent</strong>
          </a>
          <?php endif; ?>

          <?php if (has_permission('account.access', $user)): ?>
          <a class="chat-sidebar-nav-link" href="<?= e(url('/contacts.php')) ?>">
            <span>●</span>
            <strong>My Contacts</strong>
          </a>
          <?php endif; ?>

          <?php if (has_permission('artist_listening.access', $user)): ?>
          <a class="chat-sidebar-nav-link chat-sidebar-recordings-link" href="<?= e(url('/artist-listening.php')) ?>">
            <span>●</span>
            <strong>My Transcriptions</strong>
          </a>
          <?php endif; ?>

'''
text = text.replace(marker, direct_links + marker, 1)

live_pattern = re.compile(
    r'''\n      <section\n        class="chat-live-updates"\n        id="chatLiveUpdates"\n        aria-live="polite"\n        hidden\n      >.*?\n      </section>\n''',
    re.S,
)
text, count = live_pattern.subn("\n", text, count=1)
if count != 1:
    raise SystemExit(f"live update panel: expected 1 match, found {count}")
p.write_text(text)

# Canonical chat renderer: preserve activity polling because it synchronizes
# newly-persisted messages, but remove the separate activity-card renderer.
p = Path("chat.js")
text = p.read_text()
for needle in (
    "  const liveUpdates = document.getElementById('chatLiveUpdates');\n",
    "  const liveUpdateList = document.getElementById('chatLiveUpdateList');\n",
    "  const liveStatus = document.getElementById('chatLiveStatus');\n",
    "  const renderedActivityIds = new Set();\n",
):
    expect_once(text, needle, f"chat.js declaration {needle.strip()}")
    text = text.replace(needle, "", 1)

text, count = re.subn(
    r'''\n  function activityIcon\(type\) \{.*?\n  async function pollAgentActivity\(''',
    "\n  async function pollAgentActivity(",
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit(f"activity renderer block: expected 1 match, found {count}")

render_call = '''      renderActivityUpdates(
        data.updates || []
      );

'''
expect_once(text, render_call, "renderActivityUpdates call")
text = text.replace(render_call, "", 1)

old_continuity = '''  window.STONEFELLOW_CHAT_CONTINUITY_V87 = {
    isVoice:() => Boolean(voiceMode),
    conversationId:() => Number(conversationId || 0)
  };'''
new_continuity = '''  const canonicalChatContinuity = {
    isVoice:() => Boolean(voiceMode),
    conversationId:() => Number(conversationId || 0),
    openConversation:async id => {
      const target = Math.max(0, Number(id || 0));
      if (target < 1) return false;
      await loadConversation(target);
      return Number(conversationId || 0) === target;
    },
    syncConversation:async id => {
      const target = Math.max(0, Number(id || conversationId || 0));
      if (target < 1) return false;
      if (target !== Number(conversationId || 0)) await loadConversation(target);
      else await syncConversationMessagesV101(target);
      return true;
    }
  };
  window.STONEFELLOW_CHAT_CONTINUITY_V87 = canonicalChatContinuity;
  window.STONEFELLOW_CHAT_CONTINUITY = canonicalChatContinuity;'''
expect_once(text, old_continuity, "canonical continuity block")
text = text.replace(old_continuity, new_continuity, 1)
p.write_text(text)

# Actionable attention uses the canonical Chat conversation loader instead of
# fabricating a history row and clicking it.
p = Path("chat-notifications-drawer-v240.js")
text = p.read_text()
text, count = re.subn(
    r'''\n  function ensureHistoryButton\(conversationId\) \{.*?\n  function clearResponseWindow\(\) \{''',
    '''
  async function showAttentionConversation(conversationId, message) {
    const id = Math.max(0, Number(conversationId || 0));
    const chat = continuity();
    if (id < 1 || typeof chat.openConversation !== 'function') return false;
    const opened = await chat.openConversation(id);
    if (!opened) return false;
    const expected = String(message || '').trim();
    const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
      const texts = [...document.querySelectorAll('#chatThread .message.assistant .message-text')];
      if (texts.some(node => String(node.textContent || '').trim() === expected)) return true;
      if (typeof chat.syncConversation === 'function') await chat.syncConversation(id);
      await new Promise(resolve => setTimeout(resolve, 80));
    }
    return false;
  }

  function clearResponseWindow() {''',
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit(f"attention history shim: expected 1 match, found {count}")
p.write_text(text)

# User menu: Main Feed first; editors stay in their workspaces, not user menu.
p = Path("includes/member-navigation.php")
text = p.read_text()
anchor = "    $profileUrl = member_navigation_profile_url($user);\n"
expect_once(text, anchor, "member navigation profile anchor")
text = text.replace(
    anchor,
    "    if (has_permission('chat.access',$user)) $add($links,'chat','Main Feed',url('/chat.php'),'primary');\n\n" + anchor,
    1,
)
old_chat = "    if (has_permission('chat.access',$user)) $add($links,'chat','Agent Chat',url('/chat.php'),'agent');\n"
expect_once(text, old_chat, "old Agent Chat menu item")
text = text.replace(old_chat, "", 1)
editor_pattern = re.compile(
    r'''\n    if \(has_permission\('tracks\.manage',\$user\)\|\|has_permission\('track_notes\.manage',\$user\)\|\|has_permission\('producer\.access',\$user\)\) \{\n        \$add\(\$links,'stem_studio','Stem Studio',url\('/admin/stems\.php'\),'creator'\);\n    \}\n    if \(has_permission\('chat\.access',\$user\)\) \$add\(\$links,'video_editor','Video Editor',url\('/video-editor\.php'\),'creator'\);'''
)
text, count = editor_pattern.subn("", text, count=1)
if count != 1:
    raise SystemExit(f"editor dropdown links: expected 1 match, found {count}")
p.write_text(text)

# Profile Agent logo returns to authenticated home/Main Feed.
p = Path("profile-agent.php")
text = p.read_text()
old = '<a class="profile-agent-sidebar-brand" href="<?= e(url(\'/profile-agent.php\')) ?>"><?= e(system_agent_name()) ?></a>'
new = '<a class="profile-agent-sidebar-brand" href="<?= e(url(\'/\')) ?>"><?= e(system_agent_name()) ?></a>'
expect_once(text, old, "Profile Agent logo")
text = text.replace(old, new, 1)
text = text.replace(
    '<a href="<?= e(url(\'/chat.php\')) ?>">Agent Chat</a>',
    '<a href="<?= e(url(\'/chat.php\')) ?>">Main Feed</a>',
    1,
)
p.write_text(text)

# Wrapper cleanup: My Transcriptions is now in the canonical template and the
# deleted live-update surface no longer needs a CSS/observer suppression rule.
p = Path("chat.php")
text = p.read_text()
text, count = re.subn(
    r'''\nif \(has_permission\('artist_listening\.access', \$user\)\) \{\n    \$recordingsNavLink = .*?\n\}\n''',
    "\n",
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit(f"wrapper recordings injection: expected 1 match, found {count}")
text = text.replace("#chatLiveUpdates,.chat-live-updates,", "", 2)
p.write_text(text)

# Build-time guards.
assert 'id="chatLiveUpdates"' not in Path("chat-legacy-v108.php").read_text()
assert "renderActivityUpdates" not in Path("chat.js").read_text()
assert "ensureHistoryButton" not in Path("chat-notifications-drawer-v240.js").read_text()
assert "openConversation:async id" in Path("chat.js").read_text()
assert "<strong>My Contacts</strong>" in Path("chat-legacy-v108.php").read_text()
assert "<strong>Profile Agent</strong>" in Path("chat-legacy-v108.php").read_text()
assert "<strong>My Playlists</strong>" in Path("chat-legacy-v108.php").read_text()
