#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, text):
    (ROOT / path).write_text(text, encoding='utf-8')


def must_replace(text, old, new, label):
    if old not in text:
        raise SystemExit(f'missing expected source for {label}: {old[:120]!r}')
    return text.replace(old, new)

# 1) Make the canonical Chat stylesheet light at its source. Do not append an
# override sheet; replace the recovered brown palette and its hard-coded uses.
path = 'chat.css'
css = read(path)
root_old = ':root{--bg:#0b0a09;--side:#11100e;--panel:#171411;--panel2:#1d1915;--cream:#ece4da;--muted:#928980;--line:rgba(190,155,111,.20);--accent:#ddc4a4}'
root_new = ':root{--bg:#ffffff;--side:#f8fafc;--panel:#ffffff;--panel2:#f3f4f6;--cream:#111827;--muted:#6b7280;--line:#e5e7eb;--accent:#111827}'
css = must_replace(css, root_old, root_new, 'chat root palette')

replacements = {
    '#0b0a09':'#ffffff', '#11100e':'#f8fafc', '#171411':'#ffffff', '#1d1915':'#f3f4f6',
    '#ece4da':'#111827', '#928980':'#6b7280', '#ddc4a4':'#111827', '#6f6861':'#6b7280',
    '#a99f95':'#4b5563', '#1a1714':'#f3f4f6', '#635d57':'#9ca3af', '#16130f':'#ffffff',
    '#3a2a1f':'#e5e7eb', '#1d1712':'#f3f4f6', '#e7d7c3':'#374151', '#716a63':'#6b7280',
    '#756e67':'#6b7280', '#18140f':'#f9fafb', '#8f877f':'#6b7280', '#12100e':'#ffffff',
    '#bfb5aa':'#374151', '#d9cec1':'#111827', '#c8beb2':'#374151', '#e2d8cc':'#111827',
    '#887f76':'#6b7280', '#5f5953':'#6b7280', '#100e0c':'#f9fafb', '#15120f':'#ffffff',
    '#e6dbcf':'#111827', '#756d65':'#6b7280', '#978a7c':'#6b7280', '#8e7f70':'#6b7280',
    '#766e67':'#6b7280', '#625b55':'#6b7280', '#241815':'#f3f4f6', '#1b1510':'#ffffff',
    '#d9c1a1':'#374151', '#251c15':'#f3f4f6', '#fff0dc':'#111827', '#17110c':'#ffffff',
    '#39332d':'#d1d5db', '#d2b18b':'#6b7280', '#b8895c':'#111827', '#3b342d':'#d1d5db',
    '#81766b':'#6b7280', '#ddd2c6':'#111827', '#716860':'#6b7280', '#9f9387':'#4b5563',
    '#72573e':'#d1d5db', '#241a12':'#111827', '#e7c79f':'#ffffff', '#6f675f':'#6b7280',
    '#17130f':'#ffffff', '#211b16':'#ffffff', '#cbb69d':'#374151', '#211a14':'#ffffff',
    '#2c241d':'#e5e7eb', '#2a231d':'#f3f4f6', '#30271f':'#e5e7eb', '#231c16':'#f3f4f6',
}
for old, new in replacements.items():
    css = css.replace(old, new)

rgba_replacements = {
    'rgba(190,155,111,.20)':'rgba(17,24,39,.12)',
    'rgba(190,155,111,.28)':'rgba(17,24,39,.14)',
    'rgba(190,155,111,.12)':'rgba(17,24,39,.08)',
    'rgba(190,155,111,.18)':'rgba(17,24,39,.10)',
    'rgba(190,155,111,.45)':'rgba(17,24,39,.22)',
    'rgba(211,175,132,.30)':'rgba(17,24,39,.10)',
    'rgba(211,175,132,.28)':'rgba(17,24,39,.10)',
    'rgba(211,180,138,.30)':'rgba(17,24,39,.12)',
    'rgba(211,180,138,.58)':'rgba(17,24,39,.24)',
    'rgba(223,199,166,.58)':'rgba(17,24,39,.24)',
    'rgba(97,66,42,.10)':'rgba(17,24,39,.025)',
    'rgba(11,10,9,.88)':'rgba(255,255,255,.94)',
}
for old, new in rgba_replacements.items():
    css = css.replace(old, new)

# Base controls/text that depended on the old cream/dark inverse relationship.
css = css.replace('background:var(--accent);color:#ffffff', 'background:var(--accent);color:#ffffff')
css = css.replace('border:2px solid #ffffff;', 'border:2px solid #ffffff;')
write(path, css)

# 2) Rewrite the old media workspace CSS files themselves to light sources.
write('chat-media-v86.css', '''.chat-media-button{border:0;background:transparent;color:inherit;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;cursor:pointer;font-size:17px}\n.chat-media-button:hover{background:#f3f4f6}\n.chat-media-studio{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:24px;background:rgba(15,23,42,.28);backdrop-filter:blur(8px)}\n.chat-media-studio[hidden]{display:none}\n.chat-media-panel{width:min(1120px,96vw);max-height:90vh;display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 28px 80px rgba(15,23,42,.18);color:#111827;overflow:hidden}\n.chat-media-head{display:flex;align-items:center;gap:12px;padding:15px 18px;border-bottom:1px solid #e5e7eb;background:#fff}\n.chat-media-head>div:first-child{min-width:0;flex:1}\n.chat-media-head span{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#6b7280}\n.chat-media-head h2{margin:2px 0 0;font-size:18px}\n.chat-media-head-actions{display:flex;gap:7px;flex-wrap:wrap}\n.chat-media-head button,.chat-media-feed button,.chat-media-library-card button,.chat-media-library-card a{border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:8px;padding:8px 10px;font:inherit;cursor:pointer;text-decoration:none}\n.chat-media-head .primary,.chat-media-feed .recording{background:#111827;color:#fff;border-color:#111827}\n.chat-media-body{overflow:auto;padding:16px;background:#fff}\n.chat-media-notice{margin:0 0 12px;padding:10px 12px;border-radius:10px;background:#f9fafb;color:#4b5563;font-size:12px}\n.chat-media-status{margin:0 0 12px;padding:9px 12px;border-radius:9px;background:#f0fdf4;color:#166534}\n.chat-media-status[data-state="error"]{background:#fef2f2;color:#b91c1c}\n.chat-media-feeds{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}\n.chat-media-feed{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}\n.chat-media-feed video{display:block;width:100%;aspect-ratio:16/9;object-fit:contain;background:#000}\n.chat-media-feed footer{display:flex;align-items:center;gap:7px;padding:9px;background:#fff;border-top:1px solid #e5e7eb}\n.chat-media-feed footer strong{min-width:0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}\n.chat-media-library{margin-top:16px;border-top:1px solid #e5e7eb;padding-top:14px}\n.chat-media-library h3{margin:0 0 9px}\n.chat-media-library-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}\n.chat-media-library-card{display:grid;grid-template-columns:58px minmax(0,1fr);gap:9px;border:1px solid #e5e7eb;background:#fff;border-radius:10px;padding:8px}\n.chat-media-library-card img,.chat-media-library-card video{width:58px;height:58px;object-fit:cover;border-radius:7px;background:#f3f4f6}\n.chat-media-library-card .media-icon{width:58px;height:58px;border-radius:7px;background:#f3f4f6;display:grid;place-items:center;color:#6b7280}\n.chat-media-library-card strong,.chat-media-library-card small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}\n.chat-media-library-card small{color:#6b7280}\n.chat-media-library-actions{display:flex;gap:4px;margin-top:5px}\n.chat-agent-action-button{border:1px solid currentColor;background:transparent;color:inherit;border-radius:8px;padding:6px 9px;cursor:pointer;font:inherit}\n@media(max-width:800px){.chat-media-studio{padding:8px}.chat-media-panel{max-height:96vh}.chat-media-feeds{grid-template-columns:1fr}.chat-media-library-grid{grid-template-columns:1fr}.chat-media-head{align-items:flex-start}.chat-media-head-actions{justify-content:flex-end}}\n''')

write('chat-media-v91.css', '''/* Canonical light full-canvas camera/media workspace. */\n.chat-media-studio{position:fixed!important;inset:0!important;z-index:30000!important;display:block!important;padding:0!important;background:#fff!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important}\n.chat-media-studio[hidden]{display:none!important}\n.chat-media-panel{width:100vw!important;height:100dvh!important;max-height:none!important;border:0!important;border-radius:0!important;box-shadow:none!important;background:#fff!important;color:#111827!important;overflow:hidden!important}\n.chat-media-head{position:sticky;top:0;z-index:10;min-height:62px;padding:10px 16px!important;background:rgba(255,255,255,.96);border-bottom:1px solid #e5e7eb!important;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}\n.chat-media-head h2{font-size:16px!important}.chat-media-head span{font-size:9px!important}\n.chat-media-head-actions{align-items:center}.chat-media-head-actions button{min-height:36px;border-radius:7px!important}\n.chat-media-body{flex:1!important;min-height:0!important;overflow:auto!important;padding:16px 18px 40px!important;background:#fff}\n.chat-media-status{max-width:1200px;margin:0 auto 12px!important}\n.chat-media-feeds{max-width:1500px;margin:0 auto;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:14px!important}\n.chat-media-feed{border-radius:8px!important;border-color:#e5e7eb!important;background:#fff!important}\n.chat-media-feed video{width:100%;height:min(55vh,620px);aspect-ratio:auto!important;object-fit:contain!important;background:#000}\n.chat-media-feed footer{min-height:50px;background:#fff;border-top:1px solid #e5e7eb}\n.chat-media-library{max-width:1500px;margin:20px auto 0!important;padding-top:18px!important}\n.chat-media-library-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important}\n.chat-media-library-card{background:#fff!important;border-color:#e5e7eb!important}\n.chat-media-button,.editor-agent-video{border:0;background:transparent;color:inherit;width:38px;height:38px;border-radius:10px;display:grid;place-items:center;cursor:pointer;font-size:0;flex:0 0 38px}\n.chat-media-button:hover,.editor-agent-video:hover{background:#f3f4f6}\n.chat-media-button::before,.editor-agent-video::before{content:'';width:19px;height:14px;border:1.8px solid currentColor;border-radius:4px;display:block;box-sizing:border-box;position:relative}\n.chat-media-button::after,.editor-agent-video::after{content:'';width:6px;height:8px;border:1.8px solid currentColor;border-left:0;border-radius:0 3px 3px 0;transform:translate(12px,-11px) skewY(-18deg);display:block;box-sizing:border-box}\n.editor-agent-composer .editor-agent-video{color:#374151!important;border:1px solid #d1d5db!important;background:#fff!important;align-self:flex-end!important}\n.chat-media-feed-state{min-height:220px;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;gap:9px;padding:24px;color:#374151;background:#f9fafb}\n.chat-media-feed-state strong{font-size:15px}.chat-media-feed-state span{max-width:680px;color:#6b7280;line-height:1.45}.chat-media-feed-state small{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#9ca3af}\n.chat-media-feed-state.error{border-left:3px solid #dc2626;background:#fef2f2}.chat-media-feed-state button{min-height:34px;padding:7px 12px;border:1px solid #d1d5db;border-radius:7px;background:#fff;color:#374151;cursor:pointer}\n.chat-media-feed-ready{margin-left:auto;color:#15803d;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}\n@media(max-width:900px){.chat-media-feeds{grid-template-columns:1fr!important}.chat-media-feed video{height:auto;max-height:58vh}.chat-media-library-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}\n@media(max-width:600px){.chat-media-head{align-items:flex-start!important;padding:8px 10px!important}.chat-media-head-actions{gap:4px!important}.chat-media-head-actions button{padding:6px 8px!important;font-size:10px}.chat-media-body{padding:10px 8px 28px!important}.chat-media-library-grid{grid-template-columns:1fr!important}}\n''')

write('chat-media-v93.css', '''/* Mobile capture-first controls — canonical light source. */\n.chat-media-mobile-quick{display:none;max-width:1200px;margin:0 auto 12px;padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;color:#111827}.chat-media-mobile-quick>header{display:flex;justify-content:space-between;gap:10px;align-items:end;margin-bottom:10px}.chat-media-mobile-quick>header div{display:grid;gap:2px}.chat-media-mobile-quick>header span{font-size:9px;color:#6b7280;text-transform:uppercase;letter-spacing:.08em}.chat-media-mobile-quick>header strong{font-size:16px}.chat-media-mobile-quick>header small{font-size:9px;color:#6b7280}.chat-media-mobile-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}.chat-media-mobile-actions label{position:relative;display:grid;gap:2px;padding:12px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;cursor:pointer}.chat-media-mobile-actions label:active{transform:scale(.985)}.chat-media-mobile-actions b{font-size:13px}.chat-media-mobile-actions span{font-size:9px;color:#6b7280}.chat-media-mobile-actions input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer}\n@media(max-width:768px){.chat-media-mobile-quick{display:block}.chat-media-notice{font-size:10px}.chat-media-head [data-media-refresh]{display:none}.chat-media-mobile-actions{grid-template-columns:repeat(2,1fr)}}\n''')

# Make v97 neutral rather than assuming a dark composer.
v97 = read('chat-v97.css')
v97 = v97.replace('rgba(255,255,255,.07)', 'rgba(17,24,39,.08)')
v97 = v97.replace('rgba(255,255,255,.02)', '#f9fafb')
v97 = v97.replace('rgba(255,255,255,.04)', '#f3f4f6')
v97 = v97.replace('rgba(255,255,255,.05)', '#f3f4f6')
v97 = v97.replace('#8f918f', '#6b7280').replace('#929792', '#6b7280').replace('#aeb4af', '#374151')
v97 = v97.replace('linear-gradient(90deg,#737a75,#a3aaa5)', 'linear-gradient(90deg,#6b7280,#111827)')
write('chat-v97.css', v97)

# Overlay light presentation is now canonical, not conditional on a theme flag.
overlay = read('chat-media-overlays.css')
overlay = overlay.replace('body[data-agent-theme="light"] ', '')
overlay = overlay.replace('/* Chat media overlays — canonical light-theme repair.', '/* Chat media overlays — canonical presentation.')
write('chat-media-overlays.css', overlay)

# 3) Remove the old theme runtime/toggle from Chat entirely and make Chat-only
# account-menu exclusions explicit.
chat = read('chat.php')
chat = re.sub(r"^\$agentThemeBuild = .*?;\n", '', chat, count=1, flags=re.M)
chat = re.sub(r"\$themeRuntime = '<link rel=\"stylesheet\" data-agent-theme-v242.*?;\n", '', chat, count=1, flags=re.S)
chat = chat.replace("$runtime = $headerUiRuntime\n         . $themeRuntime\n         . $mediaOverlayRuntime", "$runtime = $headerUiRuntime\n         . $mediaOverlayRuntime")
chat = chat.replace("$mediaOverlayBuild = 'chat-media-overlays-20260905';", "$mediaOverlayBuild = 'chat-media-overlays-source-light-20260905';")
old_loop = """$chatProfileLinks = '';\nforeach (member_navigation_menu_links($user) as $menuLink) {\n    $class = !empty($menuLink['danger']) ? ' class=\"logout\"' : '';"""
new_loop = """$chatProfileLinks = '';\n$chatProfileMenuExcluded = ['profile'=>true, 'account'=>true, 'profile_agent'=>true];\nforeach (member_navigation_menu_links($user) as $menuLink) {\n    if (isset($chatProfileMenuExcluded[(string)($menuLink['key'] ?? '')])) {\n        continue;\n    }\n    $class = !empty($menuLink['danger']) ? ' class=\"logout\"' : '';"""
chat = must_replace(chat, old_loop, new_loop, 'Chat profile-menu filtering')
# Remove recovered brown inline cards from the current runtime source.
for old, new in {
    "color:#8f877f":"color:#6b7280",
    "border:1px solid rgba(255,255,255,.055);border-radius:8px;background:rgba(8,7,7,.28);color:inherit":"border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:inherit",
    "border-color:rgba(187,160,238,.34);background:rgba(187,160,238,.05)":"border-color:#d1d5db;background:#f9fafb",
    "color:#d8cec4":"color:#111827",
    "color:#766e67":"color:#6b7280",
}.items():
    chat = chat.replace(old, new)
write('chat.php', chat)

# 4) Cache-bust the source CSS and remove the old sidebar destinations at markup.
legacy = read('chat-legacy-v108.php')
legacy = must_replace(legacy, '<meta name="theme-color" content="#0b0a09">', '<meta name="theme-color" content="#ffffff">', 'theme-color')
legacy = must_replace(legacy, "url('/chat.css?v=205')", "url('/chat.css?v=206-source-light-20260905')", 'chat.css cache key')
for view in ('shows','photos','merch'):
    pattern = re.compile(r'\n\s*<button\s+class="chat-sidebar-nav-link"\s+type="button"\s+data-chat-view-target="' + view + r'"\s*>.*?</button>\n', re.S)
    legacy, count = pattern.subn('\n', legacy, count=1)
    if count != 1:
        raise SystemExit(f'expected one sidebar {view} button, got {count}')
if 'data-chat-view-target="playlists"' not in legacy:
    raise SystemExit('Playlists sidebar destination must remain')
write('chat-legacy-v108.php', legacy)

# 5) Make Profile Activity an icon/badge peer in the top-right cluster instead
# of a labeled pill. The canvas behavior is unchanged.
pa = read('profile-activity-chat.js')
old = "button.innerHTML='<span class=\"chat-profile-activity-dot\"></span><strong>Profile</strong>';badge=document.createElement('em');"
new = "button.innerHTML='<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><circle cx=\"12\" cy=\"8\" r=\"3.5\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.7\"/><path d=\"M5.5 20c.5-4 2.7-6 6.5-6s6 2 6.5 6\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.7\" stroke-linecap=\"round\"/></svg>';badge=document.createElement('em');"
pa = must_replace(pa, old, new, 'Profile Activity topbar icon')
old_insert = "const before=document.getElementById('chatNotificationMenu')||actions.firstChild;actions.insertBefore(button,before);"
new_insert = "const avatar=document.getElementById('chatProfileMenu');actions.insertBefore(button,avatar||null);"
pa = must_replace(pa, old_insert, new_insert, 'Profile Activity topbar position')
write('profile-activity-chat.js', pa)

pacss = read('profile-activity-chat.css')
first_rule_end = pacss.find('\n.profile-activity-backdrop')
if first_rule_end < 0:
    raise SystemExit('profile activity CSS boundary not found')
button_css = '''.chat-profile-activity-button{position:relative;width:34px;height:34px;display:grid;place-items:center;padding:0;border:1px solid #e5e7eb;border-radius:50%;background:#fff;color:#4b5563;cursor:pointer}.chat-profile-activity-button:hover,.chat-profile-activity-button:focus-visible{border-color:#d1d5db;background:#f9fafb;color:#111827;outline:none}.chat-profile-activity-button>svg{width:16px;height:16px;display:block}.chat-profile-activity-button>em{position:absolute;top:-5px;right:-6px;display:grid;place-items:center;min-width:17px;height:17px;padding:0 4px;border:2px solid #fff;border-radius:999px;background:#171717;color:#fff;font:800 8px/1 system-ui;font-style:normal}.chat-profile-activity-button>em[hidden]{display:none!important}\n'''
pacss = button_css + pacss[first_rule_end+1:]
pacss = pacss.replace('@media(max-width:720px){.chat-profile-activity-button>strong{display:none}.chat-profile-activity-button{padding:0 9px}', '@media(max-width:720px){.chat-profile-activity-button{width:34px;height:34px;padding:0}')
write('profile-activity-chat.css', pacss)

# 6) Add a regression contract to the recovered baseline.
test = r'''import fs from 'node:fs';
import assert from 'node:assert/strict';

const chatCss = fs.readFileSync('chat.css','utf8');
const chatPhp = fs.readFileSync('chat.php','utf8');
const legacy = fs.readFileSync('chat-legacy-v108.php','utf8');
const overlay = fs.readFileSync('chat-media-overlays.css','utf8');
const profileActivity = fs.readFileSync('profile-activity-chat.js','utf8');

assert.match(chatCss, /:root\{--bg:#ffffff;--side:#f8fafc;--panel:#ffffff;--panel2:#f3f4f6;--cream:#111827;/, 'Chat base palette must be light at source');
for (const token of ['#0b0a09','#11100e','#171411','#1d1915','#ddc4a4','rgba(190,155,111']) {
  assert.equal(chatCss.includes(token), false, `legacy brown Chat token must be removed: ${token}`);
}
assert.equal(chatPhp.includes('agent-theme-v242.css'), false, 'Chat must not load theme override CSS');
assert.equal(chatPhp.includes('agent-theme-v242.js'), false, 'Chat must not load the theme toggle runtime');
assert.match(legacy, /meta name="theme-color" content="#ffffff"/, 'browser theme color must be light');
assert.match(legacy, /chat\.css\?v=206-source-light-20260905/, 'Chat base CSS must be cache-busted');
assert.match(legacy, /data-chat-view-target="playlists"/, 'Playlists must stay in the sidebar');
for (const removed of ['shows','photos','merch']) {
  assert.equal(legacy.includes(`data-chat-view-target="${removed}"`), false, `${removed} must be removed from the main sidebar`);
}
assert.match(chatPhp, /\['profile'=>true, 'account'=>true, 'profile_agent'=>true\]/, 'Chat dropdown must exclude profile/account/Profile Agent shortcuts');
assert.equal(overlay.includes('body[data-agent-theme="light"]'), false, 'media overlay presentation must not depend on theme state');
assert.match(profileActivity, /actions\.insertBefore\(button,avatar\|\|null\)/, 'Profile Activity badge must live in the upper-right action cluster');
console.log('chat-light-source-contract=PASS');
'''
write('tests/chat-light-source-contract.mjs', test)

runner = read('tools/run_recovery_baseline.py')
needle = "    'tests/chat-player-responsive-v205.mjs',\n"
runner = must_replace(runner, needle, needle + "    'tests/chat-light-source-contract.mjs',\n", 'recovery baseline registration')
write('tools/run_recovery_baseline.py', runner)

# Validate before the workflow commits anything.
chat_css_now = read('chat.css')
for banned in ['#0b0a09','#11100e','#171411','#1d1915','#ddc4a4','rgba(190,155,111']:
    if banned in chat_css_now:
        raise SystemExit(f'legacy brown token remains in chat.css: {banned}')
if 'agent-theme-v242' in read('chat.php'):
    raise SystemExit('agent theme runtime still referenced by chat.php')

# Temporary migration files should never land in the PR.
for temp in [ROOT / 'tools/tmp_chat_light_source_migration.py', ROOT / '.github/workflows/tmp-chat-light-source-migration.yml']:
    if temp.exists():
        temp.unlink()

print('CHAT_LIGHT_SOURCE_MIGRATION=PASS')
