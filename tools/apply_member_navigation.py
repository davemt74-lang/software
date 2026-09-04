#!/usr/bin/env python3
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def write(path: str, text: str) -> None:
    (ROOT / path).write_text(text, encoding='utf-8')


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected exactly one literal match, found {count}')
    return text.replace(old, new, 1)


def sub_once(text: str, pattern: str, replacement: str, label: str, flags: int = 0) -> str:
    result, count = re.subn(pattern, replacement, text, count=1, flags=flags)
    if count != 1:
        raise RuntimeError(f'{label}: expected exactly one regex match, found {count}')
    return result


# Bootstrap the single canonical member-navigation authority after profile support.
path = 'includes/bootstrap.php'
text = read(path)
text = replace_once(
    text,
    "require_once __DIR__ . '/profile-agent-runtime.php';\nrequire_once __DIR__ . '/release-chat-v105.php';",
    "require_once __DIR__ . '/profile-agent-runtime.php';\nrequire_once __DIR__ . '/member-navigation.php';\nrequire_once __DIR__ . '/release-chat-v105.php';",
    'bootstrap member navigation include',
)
write(path, text)

# Keep /knowledge.php as the clean user-facing entry URL while forwarding managers
# to the existing knowledge workspace. Accounts without manage permission retain a
# safe fallback rather than seeing a dead navigation item.
write('knowledge.php', """<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!is_logged_in()) {
    redirect(url('/login.php'));
}

$user = current_user();
if ($user && has_permission('knowledge.manage', $user)) {
    redirect(url('/admin/knowledge.php'));
}
if ($user && has_permission('chat.access', $user)) {
    redirect(url('/chat.php'));
}
redirect(url('/account.php'));
""")

# My Account dropdown.
path = 'account.php'
text = read(path)
text = replace_once(
    text,
    "$accountArtistProfileUrl = artist_workspace_v181_profile_url_for_user($user);",
    "$accountUserMenuLinks = member_navigation_menu_links($user);",
    'account menu state',
)
account_nav = """<nav class=\"chat-profile-links\">
              <?php foreach ($accountUserMenuLinks as $menuLink): ?>
                <a<?= !empty($menuLink['danger']) ? ' class=\"logout\"' : '' ?> href=\"<?= e((string)$menuLink['url']) ?>\"><span><?= e((string)$menuLink['label']) ?></span><span>↗</span></a>
              <?php endforeach; ?>
            </nav>"""
text = sub_once(
    text,
    r'<nav class="chat-profile-links">.*?</nav>',
    account_nav,
    'account dropdown',
    re.S,
)
write(path, text)

# Admin dropdown uses the same canonical list and keeps only its visual logout class.
path = 'admin/_header.php'
text = read(path)
text = replace_once(
    text,
    "$adminArtistProfileUrl = artist_workspace_v181_profile_url_for_user($user);",
    "$adminUserMenuLinks = member_navigation_menu_links($user);",
    'admin menu state',
)
admin_nav = """<nav class=\"admin-user-dropdown-links\">
          <?php foreach ($adminUserMenuLinks as $menuLink): ?>
            <a<?= !empty($menuLink['danger']) ? ' class=\"admin-user-logout\"' : '' ?> href=\"<?= e((string)$menuLink['url']) ?>\"><span><?= e((string)$menuLink['label']) ?></span><span>↗</span></a>
          <?php endforeach; ?>
        </nav>"""
text = sub_once(
    text,
    r'<nav class="admin-user-dropdown-links">.*?</nav>',
    admin_nav,
    'admin dropdown',
    re.S,
)
write(path, text)

# Shared site header dropdown. Remove My Library from the desktop top-level nav; it
# remains a product route but is no longer presented as a primary identity surface.
path = 'includes/header.php'
text = read(path)
text = replace_once(
    text,
    "$headerNotifications = $headerUser ? notification_recent($headerUser, 6) : [];",
    "$headerNotifications = $headerUser ? notification_recent($headerUser, 6) : [];\n$headerUserMenuLinks = $headerUser ? member_navigation_menu_links($headerUser) : [];",
    'site header menu state',
)
text = sub_once(
    text,
    r'\s*<\?php if \(has_permission\(\'account\.access\', \$headerUser\) && function_exists\(\'artist_workspace_v181_schema_ready\'\) && artist_workspace_v181_schema_ready\(\)\): \?><a class="<\?= \$activePage === \'library\' \? \'active\' : \'\' \?>" href="<\?= e\(url\(\'/my-library\.php\'\)\) \?>">My Library</a><\?php endif; \?>',
    '',
    'remove desktop My Library',
)
site_nav = """<div class=\"user-menu-links\">
              <?php foreach ($headerUserMenuLinks as $menuLink): ?>
                <a<?= !empty($menuLink['danger']) ? ' class=\"user-menu-logout\"' : '' ?> href=\"<?= e((string)$menuLink['url']) ?>\"><span><?= e((string)$menuLink['label']) ?></span><span>↗</span></a>
              <?php endforeach; ?>
            </div>"""
text = sub_once(
    text,
    r'<div class="user-menu-links">.*?</div>',
    site_nav,
    'site header dropdown',
    re.S,
)
write(path, text)

# Chat owns the visible dropdown in chat.php, not the legacy shell. Replace the
# whole legacy nav once instead of injecting Agent Settings/Profile Agent aliases.
path = 'chat.php'
text = read(path)
text = text.replace('aria-label="Open My Recordings"', 'aria-label="Open My Transcriptions"', 1)
text = text.replace('<strong>My Recordings</strong>', '<strong>My Transcriptions</strong>', 1)
chat_menu_block = """// Canonical account dropdown. Replace the legacy menu as one unit so Chat does
// not inject parallel account.php hash links that drift from the other surfaces.
$chatProfileLinks = '';
foreach (member_navigation_menu_links($user) as $menuLink) {
    $class = !empty($menuLink['danger']) ? ' class=\"logout\"' : '';
    $chatProfileLinks .= '<a' . $class
        . ' data-chat-profile-link=\"' . e((string)$menuLink['key']) . '\"'
        . ' href=\"' . e((string)$menuLink['url']) . '\">'
        . '<span>' . e((string)$menuLink['label']) . '</span><span>↗</span></a>';
}
$html = preg_replace(
    '~<nav class=\"chat-profile-links\">.*?</nav>~s',
    '<nav class=\"chat-profile-links\">' . $chatProfileLinks . '</nav>',
    $html,
    1
) ?? $html;

$chatApiEndpoint ="""
text = sub_once(
    text,
    r'// Public-profile lookup is deliberately isolated from Chat identity\..*?\n\$chatApiEndpoint =',
    chat_menu_block,
    'chat canonical dropdown block',
    re.S,
)
write(path, text)

# Artist Listening receives the same server-resolved menu as JSON and renders it
# with DOM APIs; no route names or account-role assumptions live in this page JS.
path = 'artist-listening.php'
text = read(path)
text = replace_once(
    text,
    "    'userId'=>(int)$user['id'],\n    'csrf'=>csrf_token(),",
    "    'userId'=>(int)$user['id'],\n    'userMenuLinks'=>member_navigation_menu_links($user),\n    'csrf'=>csrf_token(),",
    'artist listening menu config',
)
listening_menu = """menu.innerHTML = `<button type=\"button\" class=\"sf-listening-user-menu-button\" data-listening-user-menu-toggle aria-expanded=\"false\" aria-controls=\"sfListeningUserMenuDropdown\" aria-label=\"Open user menu\"><span class=\"sf-listening-user-avatar\"><span data-listening-user-avatar-fallback aria-hidden=\"true\"><svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"8\" r=\"3.5\"></circle><path d=\"M5.5 19c.8-3.7 3-5.5 6.5-5.5s5.7 1.8 6.5 5.5\"></path></svg></span><img data-listening-user-avatar hidden alt=\"\"></span></button><div class=\"sf-listening-user-menu-dropdown\" id=\"sfListeningUserMenuDropdown\" data-listening-user-menu-dropdown hidden></div>`;
      const dropdown = menu.querySelector('[data-listening-user-menu-dropdown]');
      const menuLinks = Array.isArray(listeningConfig.userMenuLinks) ? listeningConfig.userMenuLinks : [];
      menuLinks.forEach(item => {
        if (!item || !item.url || !item.label || !dropdown) return;
        const link = document.createElement('a');
        link.href = String(item.url);
        if (item.danger) link.classList.add('logout');
        const label = document.createElement('span');
        label.textContent = String(item.label);
        const arrow = document.createElement('span');
        arrow.textContent = '↗';
        link.append(label, arrow);
        dropdown.appendChild(link);
      });
      aiButton.insertAdjacentElement('afterend', menu);"""
text = sub_once(
    text,
    r'menu\.innerHTML = `<button type="button" class="sf-listening-user-menu-button".*?;\n      aiButton\.insertAdjacentElement\(\'afterend\', menu\);',
    listening_menu,
    'artist listening dropdown',
    re.S,
)
# The replacement declares dropdown before insertion; remove the old duplicate declaration.
text = replace_once(
    text,
    "\n      const button = menu.querySelector('[data-listening-user-menu-toggle]');\n      const dropdown = menu.querySelector('[data-listening-user-menu-dropdown]');",
    "\n      const button = menu.querySelector('[data-listening-user-menu-toggle]');",
    'artist listening duplicate dropdown const',
)
write(path, text)

# Focused source contract: labels/routes are centralized and old dropdown aliases do
# not reappear in the critical authenticated surfaces.
test = r"""import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const nav = read('includes/member-navigation.php');
const chat = read('chat.php');
const listening = read('artist-listening.php');
const account = read('account.php');
const admin = read('admin/_header.php');
const header = read('includes/header.php');
const knowledge = read('knowledge.php');
const bootstrap = read('includes/bootstrap.php');

for (const [label, route] of [
  ['View Profile', 'profile_public_url'],
  ['My Account', '/account.php'],
  ['My Knowledge', '/knowledge.php'],
  ['My Transcriptions', '/artist-listening.php'],
  ['Agent Chat', '/chat.php'],
  ['Voice Profile', '/voice-profile.php'],
  ['Artist Workspace', '/admin/artist.php'],
  ['Stem Studio', '/admin/stems.php'],
  ['Video Editor', '/video-editor.php'],
  ['Admin Dashboard', '/admin/index.php'],
  ['Log Out', '/logout.php'],
]) {
  assert.ok(nav.includes(label), `canonical navigation should contain ${label}`);
  assert.ok(nav.includes(route), `canonical navigation should resolve ${route}`);
}

assert.ok(nav.includes("has_permission('knowledge.manage'"), 'My Knowledge must be permission gated');
assert.ok(nav.includes("has_permission('artist_listening.access'"), 'My Transcriptions must be permission gated');
assert.ok(nav.includes("user_has_role('artist'"), 'Artist Workspace must require artist identity');
assert.ok(nav.includes("has_permission('producer.access'"), 'Stem Studio must retain producer access');
assert.ok(nav.includes("?preview=1"), 'unpublished owners should receive a usable profile preview URL');
assert.ok(!nav.includes('My Library'), 'My Library is not a canonical user-dropdown destination');
assert.ok(!nav.includes('Agent Settings'), 'Agent Settings belongs inside My Account');
assert.ok(!nav.includes("'Profile Agent'"), 'Profile Agent belongs inside My Account');

for (const [name, source] of [['account', account], ['admin', admin], ['site header', header]]) {
  assert.ok(source.includes('member_navigation_menu_links'), `${name} should use canonical member navigation`);
}
assert.ok(chat.includes('member_navigation_menu_links($user)'), 'Chat should replace its legacy dropdown from the canonical map');
assert.ok(!chat.includes('<span>Agent Settings</span>'), 'Chat must not inject Agent Settings into the dropdown');
assert.ok(!chat.includes('<span>Profile Agent</span>'), 'Chat must not inject Profile Agent into the dropdown');
assert.ok(chat.includes('My Transcriptions'), 'Chat sidebar should use the My Transcriptions product name');
assert.ok(listening.includes("'userMenuLinks'=>member_navigation_menu_links($user)"), 'Artist Listening should receive canonical menu JSON');
assert.ok(!listening.includes('my-library.php'), 'Artist Listening menu must not hardcode My Library');
assert.ok(!listening.includes('artist-profile.php?user_id='), 'Artist Listening menu must not use the legacy artist profile route');
assert.ok(knowledge.includes("has_permission('knowledge.manage'"), '/knowledge.php should forward managers to the real knowledge workspace');
assert.ok(knowledge.includes("/admin/knowledge.php"), '/knowledge.php should resolve to the actual knowledge manager');
assert.ok(bootstrap.includes("/member-navigation.php"), 'bootstrap should load the canonical member navigation helper');

console.log('MEMBER_NAVIGATION_CONTRACT=PASS');
"""
write('tests/member-navigation-contract.mjs', test)

# Make the contract part of the exact-head recovery baseline.
path = 'tools/run_recovery_baseline.py'
text = read(path)
text = replace_once(
    text,
    "    'tests/account-scope-v181.mjs',\n]",
    "    'tests/account-scope-v181.mjs',\n    'tests/member-navigation-contract.mjs',\n]",
    'baseline member navigation contract',
)
write(path, text)

print('CANONICAL_MEMBER_NAVIGATION_PATCH=PASS')
