#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(path): return (ROOT/path).read_text()
def write(path,text): (ROOT/path).write_text(text)
def replace_once(text,old,new,label):
    if text.count(old)!=1:
        raise SystemExit(f'{label}: expected exactly one match, found {text.count(old)}')
    return text.replace(old,new,1)

# 1) Profile Agent owner API: secure multipart avatar/cover uploads using canonical upload_file().
p='api/profile-agent.php'; s=read(p)
s=replace_once(s,
"$ownerActions=['owner_state','save_profile','save_profile_agent','save_profile_access','attention_action','conversation_messages','conversation_status','owner_reply'];",
"$ownerActions=['owner_state','save_profile','save_profile_media','save_profile_agent','save_profile_access','attention_action','conversation_messages','conversation_status','owner_reply'];",
'owner actions')
needle="    if($action==='save_profile'){profile_save($pdo,$user,$input);profile_agent_json(true,['state'=>profile_runtime_owner_state($pdo,$user)]);}\n"
insert=needle+"""    if($action==='save_profile_media'){
        $mediaType=(string)($input['media_type']??'');
        if(!in_array($mediaType,['avatar','cover'],true))throw new RuntimeException('Choose a profile image or cover image.');
        global $config;
        $maxBytes=(int)($config['uploads']['max_image_bytes']??5242880);
        $subdir=$mediaType==='avatar'?'avatars':'profile-covers';
        $newPath=upload_file($_FILES['media_file']??[],['jpg','jpeg','png','webp'],['image/jpeg','image/png','image/webp'],$maxBytes,$subdir);
        if(!$newPath)throw new RuntimeException('Choose a JPG, PNG or WEBP image to upload.');
        $profileRow=profile_for_user($pdo,$uid,true)?:throw new RuntimeException('Profile could not be loaded.');
        $oldPath=$mediaType==='avatar'?(string)($user['avatar_path']??''):(string)($profileRow['cover_path']??'');
        try{
            if($mediaType==='avatar'){
                $pdo->prepare('UPDATE users SET avatar_path=? WHERE id=?')->execute([$newPath,$uid]);
                reset_current_user_cache();
                $user=current_user()?:$user;
            }else{
                $pdo->prepare('UPDATE user_profiles SET cover_path=? WHERE user_id=?')->execute([$newPath,$uid]);
            }
        }catch(Throwable $e){delete_local_upload($newPath);throw $e;}
        if($oldPath!==''&&$oldPath!==$newPath)delete_local_upload($oldPath);
        profile_agent_json(true,['state'=>profile_runtime_owner_state($pdo,$user)]);
    }
"""
s=replace_once(s,needle,insert,'media upload action')
write(p,s)

# 2) Owner state exposes canonical media URLs for the portal preview.
p='includes/profile-agent-runtime.php'; s=read(p)
needle="    $attention=profile_runtime_attention_list($pdo,$uid,50);\n"
insert="""    $profileAvatarUrl=!empty($profile['avatar_path'])&&str_starts_with((string)$profile['avatar_path'],'/uploads/')?url((string)$profile['avatar_path']):'';
    $profileCoverUrl=!empty($profile['cover_path'])&&str_starts_with((string)$profile['cover_path'],'/uploads/')?url((string)$profile['cover_path']):'';

    $attention=profile_runtime_attention_list($pdo,$uid,50);
"""
s=replace_once(s,needle,insert,'media urls')
needle="        'profile_url_example'=>url('/username'),\n"
insert=needle+"        'profile_media'=>['avatar_url'=>$profileAvatarUrl,'cover_url'=>$profileCoverUrl],\n"
s=replace_once(s,needle,insert,'media state')
write(p,s)

# 3) Public profile: uploaded account avatar wins, and cover is a true full-width hero.
p='profile.php'; s=read(p)
s=replace_once(s,
"if($workspace&&!empty($workspace['profile_image_path']))$avatar=url('/artist-profile-image.php?user_id='.(int)$profile['user_id'].'&type=profile');\nelseif(!empty($profile['avatar_path'])&&str_starts_with((string)$profile['avatar_path'],'/uploads/'))$avatar=url((string)$profile['avatar_path']);",
"if(!empty($profile['avatar_path'])&&str_starts_with((string)$profile['avatar_path'],'/uploads/'))$avatar=url((string)$profile['avatar_path']);\nelseif($workspace&&!empty($workspace['profile_image_path']))$avatar=url('/artist-profile-image.php?user_id='.(int)$profile['user_id'].'&type=profile');",
'avatar precedence')
s=replace_once(s,
"<link rel=\"stylesheet\" href=\"<?= e(url('/profile.css?v=profile-agent-widget-20260905')) ?>\">",
"<link rel=\"stylesheet\" href=\"<?= e(url('/profile.css?v=profile-media-20260905')) ?>\">",
'profile css cache')
s=replace_once(s,
"<main class=\"profile-shell\">\n  <section class=\"profile-cover\"><?php if($cover!==''): ?><img src=\"<?= e($cover) ?>\" alt=\"\"><?php endif; ?></section>\n  <section class=\"profile-identity\">",
"<section class=\"profile-cover profile-cover-full\"><?php if($cover!==''): ?><img src=\"<?= e($cover) ?>\" alt=\"\"><?php endif; ?></section>\n<main class=\"profile-shell\">\n  <section class=\"profile-identity\">",
'full width cover markup')
write(p,s)

# 4) Public profile CSS: viewport-wide cover, narrow content below, avatar overlaps the cover edge.
p='profile.css'; s=read(p)
s=replace_once(s,
".profile-shell{width:min(1180px,calc(100% - 36px));margin:28px auto 90px}.profile-cover{height:min(38vw,390px);min-height:230px;background:linear-gradient(145deg,#d7d2cb,#98928a);border-radius:24px;overflow:hidden;position:relative}",
".profile-cover{width:100%;height:clamp(250px,34vw,430px);min-height:250px;background:linear-gradient(145deg,#d7d2cb,#98928a);overflow:hidden;position:relative}.profile-shell{width:min(1180px,calc(100% - 36px));margin:0 auto 90px}",
'profile cover css')
s=replace_once(s,
".profile-identity{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:20px;align-items:end;margin:-62px 28px 0;position:relative;z-index:2}",
".profile-identity{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:20px;align-items:end;margin:-72px 28px 0;position:relative;z-index:2}",
'identity overlap')
s=s.replace(".profile-shell{width:min(100% - 20px,1180px);margin-top:10px}.profile-cover{border-radius:16px;min-height:210px}",".profile-shell{width:min(100% - 20px,1180px);margin-top:0}.profile-cover{height:clamp(210px,58vw,300px);min-height:210px}")
write(p,s)

# 5) Portal JS: image cards + multipart upload path.
p='profile-agent-portal.js'; s=read(p)
needle="""async function req(action,payload=null){
  const post=payload!==null;
  const options=post?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf_token:cfg.csrf,...payload})}:{credentials:'same-origin',cache:'no-store'};
  const url=post?cfg.endpoint:`${cfg.endpoint}?action=${encodeURIComponent(action)}`;
  const r=await fetch(url,options),d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||'Profile Agent request failed.');
  return d;
}
"""
insert=needle+"""async function uploadProfileMedia(mediaType,file){
  if(!file)throw new Error('Choose an image first.');
  if(file.size>5*1024*1024)throw new Error('Profile images must be 5 MB or smaller.');
  const form=new FormData();form.set('action','save_profile_media');form.set('csrf_token',cfg.csrf);form.set('media_type',mediaType);form.set('media_file',file);
  const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',body:form}),d=await r.json().catch(()=>null);
  if(!r.ok||!d?.ok)throw new Error(d?.error||'Profile image upload failed.');
  return d;
}
"""
s=replace_once(s,needle,insert,'multipart upload helper')
old="""function renderProfileSettings(){
  const p=state?.profile||{},url=state?.profile_url||state?.profile_url_example||'/username';
  profileHost.innerHTML=`<form class=\"profile-agent-card\" id=\"paProfileForm\"><h2>Public Profile</h2><p>This is the identity visitors see before they start a Profile Agent conversation.</p><label class=\"profile-agent-field\"><span>Username</span><input name=\"username\" maxlength=\"60\" required value=\"${esc(p.username||'')}\" placeholder=\"username\"></label><code class=\"profile-agent-url\">${esc(new URL(url,window.location.origin).href)}</code><label class=\"profile-agent-field\"><span>Bio</span><textarea name=\"bio\" maxlength=\"4000\">${esc(p.bio||'')}</textarea></label><div class=\"profile-agent-panel-grid\">${urlField(p,'website_url','Website')}${urlField(p,'instagram_url','Instagram')}${urlField(p,'tiktok_url','TikTok')}${urlField(p,'youtube_url','YouTube')}${urlField(p,'spotify_url','Spotify')}${urlField(p,'apple_music_url','Apple Music')}</div><label class=\"profile-agent-toggle\"><input type=\"checkbox\" name=\"is_public\"${Number(p.is_public)?' checked':''}> Publish my profile</label><label class=\"profile-agent-toggle\"><input type=\"checkbox\" name=\"share_visit_identity\"${Number(p.share_visit_identity)?' checked':''}> Let signed-in profiles know when I visit them</label><div class=\"profile-agent-form-actions\"><button class=\"primary\" type=\"submit\">Save Profile</button>${state.profile_url?`<a class=\"profile-agent-view-profile\" href=\"${esc(state.profile_url)}?preview=1\" target=\"_blank\" rel=\"noopener\">View as visitor ↗</a>`:''}</div></form>`;
}
"""
new="""function renderProfileSettings(){
  const p=state?.profile||{},media=state?.profile_media||{},url=state?.profile_url||state?.profile_url_example||'/username';
  const avatar=media.avatar_url||'',cover=media.cover_url||'';
  profileHost.innerHTML=`<div class=\"profile-agent-media-grid\"><form class=\"profile-agent-media-card\" data-profile-media-form=\"avatar\"><div class=\"profile-agent-media-preview avatar\">${avatar?`<img src=\"${esc(avatar)}\" alt=\"Current profile image\">`:`<span>${esc(String(p.display_name||'P').charAt(0).toUpperCase())}</span>`}</div><div><h3>Profile image</h3><p>JPG, PNG or WEBP · up to 5 MB.</p><input type=\"file\" name=\"media_file\" accept=\"image/jpeg,image/png,image/webp\" required><button type=\"submit\">Upload profile image</button></div></form><form class=\"profile-agent-media-card cover\" data-profile-media-form=\"cover\"><div class=\"profile-agent-media-preview cover\">${cover?`<img src=\"${esc(cover)}\" alt=\"Current cover image\">`:'<span>Full-width cover image</span>'}</div><div><h3>Cover image</h3><p>Displayed full width across the top of your public profile.</p><input type=\"file\" name=\"media_file\" accept=\"image/jpeg,image/png,image/webp\" required><button type=\"submit\">Upload cover image</button></div></form></div><form class=\"profile-agent-card\" id=\"paProfileForm\"><h2>Public Profile</h2><p>This is the identity visitors see before they start a Profile Agent conversation.</p><label class=\"profile-agent-field\"><span>Username</span><input name=\"username\" maxlength=\"60\" required value=\"${esc(p.username||'')}\" placeholder=\"username\"></label><code class=\"profile-agent-url\">${esc(new URL(url,window.location.origin).href)}</code><label class=\"profile-agent-field\"><span>Bio</span><textarea name=\"bio\" maxlength=\"4000\">${esc(p.bio||'')}</textarea></label><div class=\"profile-agent-panel-grid\">${urlField(p,'website_url','Website')}${urlField(p,'instagram_url','Instagram')}${urlField(p,'tiktok_url','TikTok')}${urlField(p,'youtube_url','YouTube')}${urlField(p,'spotify_url','Spotify')}${urlField(p,'apple_music_url','Apple Music')}</div><label class=\"profile-agent-toggle\"><input type=\"checkbox\" name=\"is_public\"${Number(p.is_public)?' checked':''}> Publish my profile</label><label class=\"profile-agent-toggle\"><input type=\"checkbox\" name=\"share_visit_identity\"${Number(p.share_visit_identity)?' checked':''}> Let signed-in profiles know when I visit them</label><div class=\"profile-agent-form-actions\"><button class=\"primary\" type=\"submit\">Save Profile</button>${state.profile_url?`<a class=\"profile-agent-view-profile\" href=\"${esc(state.profile_url)}?preview=1\" target=\"_blank\" rel=\"noopener\">View as visitor ↗</a>`:''}</div></form>`;
}
"""
s=replace_once(s,old,new,'profile media UI')
needle="""function bindDynamic(){
  document.getElementById('paAgentForm')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget);try{const d=await req('save_profile_agent',{profile_agent_id:Number(f.get('profile_agent_id')||0),profile_agent_enabled:f.get('profile_agent_enabled')?1:0,profile_agent_greeting:f.get('profile_agent_greeting'),profile_agent_instructions:f.get('profile_agent_instructions')});state=d.state;renderAll();setNotice('Profile Agent settings saved.');}catch(err){setNotice(err.message,true);}});
"""
insert="""function bindDynamic(){
  profileHost.querySelectorAll('[data-profile-media-form]').forEach(form=>form.addEventListener('submit',async e=>{e.preventDefault();const input=form.querySelector('input[type=file]'),file=input?.files?.[0];const button=form.querySelector('button[type=submit]');if(button)button.disabled=true;try{const d=await uploadProfileMedia(form.dataset.profileMediaForm,file);state=d.state;renderAll();setNotice(form.dataset.profileMediaForm==='cover'?'Cover image updated.':'Profile image updated.');}catch(err){setNotice(err.message,true);}finally{if(button)button.disabled=false;}}));
  document.getElementById('paAgentForm')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget);try{const d=await req('save_profile_agent',{profile_agent_id:Number(f.get('profile_agent_id')||0),profile_agent_enabled:f.get('profile_agent_enabled')?1:0,profile_agent_greeting:f.get('profile_agent_greeting'),profile_agent_instructions:f.get('profile_agent_instructions')});state=d.state;renderAll();setNotice('Profile Agent settings saved.');}catch(err){setNotice(err.message,true);}});
"""
s=replace_once(s,needle,insert,'media bindings')
write(p,s)

# 6) Portal styling for media controls.
p='profile-agent-portal.css'; s=read(p)
s += "\n.profile-agent-media-grid{display:grid;grid-template-columns:minmax(240px,.72fr) minmax(360px,1.28fr);gap:14px;margin-bottom:16px}.profile-agent-media-card{display:grid;grid-template-columns:116px minmax(0,1fr);gap:16px;align-items:center;padding:16px;border:1px solid #dfe3e7;border-radius:14px;background:#fff}.profile-agent-media-card.cover{grid-template-columns:minmax(180px,.85fr) minmax(0,1fr)}.profile-agent-media-card h3{margin:0 0 4px;color:#111318;font-size:.84rem}.profile-agent-media-card p{margin:0 0 10px;color:#737b85;font-size:.64rem;line-height:1.45}.profile-agent-media-card input[type=file]{display:block;width:100%;margin-bottom:9px;color:#5f6872;font-size:.62rem}.profile-agent-media-card button{border:1px solid #111318;border-radius:7px;background:#111318;color:#fff;padding:8px 10px;font:800 .64rem/1 inherit;cursor:pointer}.profile-agent-media-card button:disabled{opacity:.55;cursor:wait}.profile-agent-media-preview{display:grid;place-items:center;overflow:hidden;background:#eef1f4;color:#6f7781}.profile-agent-media-preview.avatar{width:104px;height:104px;border-radius:50%;font-size:1.5rem;font-weight:900}.profile-agent-media-preview.cover{width:100%;aspect-ratio:16/7;border-radius:9px;font-size:.6rem;font-weight:800}.profile-agent-media-preview img{width:100%;height:100%;object-fit:cover}@media(max-width:900px){.profile-agent-media-grid{grid-template-columns:1fr}.profile-agent-media-card.cover{grid-template-columns:150px minmax(0,1fr)}}@media(max-width:560px){.profile-agent-media-card,.profile-agent-media-card.cover{grid-template-columns:1fr}.profile-agent-media-preview.avatar{width:88px;height:88px}.profile-agent-media-preview.cover{aspect-ratio:16/8}}\n"
write(p,s)

# 7) Cache-bust portal media assets.
p='profile-agent.php'; s=read(p)
s=s.replace("profile-agent-portal.css?v=profile-activity-20260905","profile-agent-portal.css?v=profile-media-20260905")
s=s.replace("profile-agent-portal.js?v=profile-activity-20260905","profile-agent-portal.js?v=profile-media-20260905")
write(p,s)

# 8) Regression contract for knowledge + media architecture.
test='''import fs from 'node:fs';\nimport assert from 'node:assert/strict';\nconst read=p=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');\nconst profile=read('includes/profile-agent.php');\nconst userAgents=read('includes/user-agent-system-v236.php');\nconst api=read('api/profile-agent.php');\nconst portal=read('profile-agent-portal.js');\nconst publicProfile=read('profile.php');\nconst profileCss=read('profile.css');\n\nassert.ok(profile.includes("created_by_user_id=? AND is_published=1"),'Profile Agent knowledge must be owner-scoped and published');\nassert.ok(profile.includes('shared_knowledge_index_item_v236'),'Profile Agent must retrieve canonical indexed knowledge/chunks');\nassert.ok(profile.includes("user_data_policy_can_use_v236($pdo,$principal,$owner,'knowledge'"),'knowledge retrieval must pass canonical data policy');\nassert.ok(userAgents.includes("if($kind==='profile_agent')"),'data policy must distinguish the public Profile Agent principal');\nassert.ok(userAgents.includes("empty($p['profile_agent_allowed'])"),'Profile Agent access must require explicit profile_agent_allowed consent');\nassert.ok(api.includes('profile_agent_needs_owner'),'insufficient approved context must escalate instead of guessing');\nassert.ok(api.includes("'save_profile_media'"),'owner API must support profile media upload');\nassert.ok(api.includes("['jpg','jpeg','png','webp']"),'profile media must use an image allow-list');\nassert.ok(api.includes("['image/jpeg','image/png','image/webp']"),'profile media must validate MIME types');\nassert.ok(api.includes("'avatars':'profile-covers'"),'avatar and cover uploads must use separate canonical directories');\nassert.ok(api.includes('delete_local_upload($newPath)'),'failed media persistence must clean the newly uploaded file');\nassert.ok(portal.includes("data-profile-media-form=\\\"avatar\\\""),'Profile Settings must expose profile image upload');\nassert.ok(portal.includes("data-profile-media-form=\\\"cover\\\""),'Profile Settings must expose cover image upload');\nassert.ok(portal.includes('new FormData()'),'media upload must use multipart FormData');\nassert.ok(publicProfile.includes('profile-cover profile-cover-full'),'public profile must render a dedicated full-width cover hero');\nassert.ok(publicProfile.indexOf('profile-cover profile-cover-full')<publicProfile.indexOf('<main class=\\\"profile-shell\\\">'),'cover hero must sit outside the narrow content shell');\nassert.ok(profileCss.includes('.profile-cover{width:100%'),'cover CSS must own the full page width');\nconsole.log('PROFILE_KNOWLEDGE_MEDIA_CONTRACT=PASS');\n'''
write('tests/profile-knowledge-media-contract.mjs',test)

# 9) Add test to full recovery baseline.
p='tools/run_recovery_baseline.py'; s=read(p)
needle="    'tests/profile-activity-chat-contract.mjs',\n"
if needle not in s: raise SystemExit('baseline insertion point missing')
s=s.replace(needle,needle+"    'tests/profile-knowledge-media-contract.mjs',\n",1)
write(p,s)

print('PROFILE_KNOWLEDGE_MEDIA_PATCH=PASS')
