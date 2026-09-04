  </main>
</div>

<script src="<?= e(url('/admin/admin.js?v=77')) ?>"></script>
<?php $stemMediaHealthV229 = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'stems.php'; ?>
<?php if ($stemMediaHealthV229): ?>
<style data-stem-media-health-v229>
.daw-track-row[data-stem-media-status="offline"] .daw-track-select{opacity:.72}
.daw-track-row[data-stem-media-status="offline"] .daw-track-dot{background:#b76f56!important;box-shadow:0 0 0 2px rgba(183,111,86,.18)}
[data-stem-media-offline-v229]{display:inline-flex;align-items:center;width:max-content;margin-top:3px;padding:2px 5px;border:1px solid rgba(183,111,86,.55);border-radius:999px;font:700 9px/1 system-ui,sans-serif;letter-spacing:.06em;color:#9d5038;background:rgba(183,111,86,.08)}
.daw-arrange-row[data-stem-media-status="offline"] .daw-arrange-track{position:relative;opacity:.68}
.daw-arrange-row[data-stem-media-status="offline"] .daw-arrange-track::after{content:attr(data-stem-media-label);position:absolute;inset:5px auto auto 8px;z-index:5;padding:2px 6px;border-radius:999px;background:rgba(48,35,31,.82);color:#fff;font:700 9px/1.2 system-ui,sans-serif;letter-spacing:.05em;pointer-events:none}
.daw-stem-channel[data-stem-media-status="offline"]{opacity:.72}
.daw-stem-channel[data-stem-media-status="offline"] .daw-channel-head::after{content:attr(data-stem-media-label);display:block;width:max-content;margin-top:3px;padding:2px 5px;border-radius:999px;background:rgba(183,111,86,.12);color:#9d5038;font:700 9px/1 system-ui,sans-serif;letter-spacing:.05em}
</style>
<script data-stem-media-health-v229>
(function(root){
  'use strict';
  const BUILD='stem-media-health-v229-20260902';
  if(root.__STONEFELLOW_STEM_MEDIA_HEALTH_V229__)return;
  root.__STONEFELLOW_STEM_MEDIA_HEALTH_V229__=true;

  const labelForReason=reason=>{
    const clean=String(reason||'').toLowerCase();
    if(clean==='missing'||clean==='not_found')return 'MISSING MEDIA';
    if(clean==='invalid_signature'||clean==='signature_mismatch'||clean==='unsupported_extension'||clean==='empty')return 'INVALID MEDIA';
    return 'MEDIA OFFLINE';
  };

  function mark(stemId,reason='offline',httpStatus=0){
    const id=String(stemId||'');
    if(!id)return;
    const label=labelForReason(reason);
    const detail=`${label}${httpStatus?` · HTTP ${httpStatus}`:''} · ${String(reason||'offline')}`;
    const sidebar=root.document.querySelector(`.daw-track-row[data-stem-id="${CSS.escape(id)}"]`);
    const arrange=root.document.querySelector(`.daw-arrange-row[data-arrange-stem="${CSS.escape(id)}"]`);
    const mixer=root.document.querySelector(`.daw-stem-channel[data-mixer-stem="${CSS.escape(id)}"]`);
    [sidebar,arrange,mixer].forEach(node=>{
      if(!node)return;
      node.dataset.stemMediaStatus='offline';
      node.dataset.stemMediaLabel=label;
      node.title=detail;
    });
    if(sidebar&&!sidebar.querySelector('[data-stem-media-offline-v229]')){
      const badge=root.document.createElement('span');
      badge.dataset.stemMediaOfflineV229='1';
      badge.textContent=label;
      badge.title=detail;
      sidebar.querySelector('.daw-track-select > span:last-child')?.appendChild(badge);
    }
    root.dispatchEvent(new CustomEvent('stonefellow:stem-media-offline',{detail:{build:BUILD,stemId:Number(id),reason:String(reason||'offline'),status:Number(httpStatus||0)}}));
  }

  async function diagnose(audio){
    const id=String(audio?.dataset?.stemAudio||'');
    if(!id||audio.dataset.stemMediaOfflineV229==='1')return;
    audio.dataset.stemMediaOfflineV229='1';
    try{audio.pause();audio.preload='none';}catch(error){}
    let reason='offline';
    let status=0;
    try{
      const response=await root.fetch(String(audio.currentSrc||audio.src||''),{method:'HEAD',credentials:'same-origin',cache:'no-store'});
      status=Number(response.status||0);
      reason=String(response.headers.get('x-stonefellow-media-status')||'offline');
      if(response.ok)reason='decode_error';
    }catch(error){}
    mark(id,reason,status);
  }

  function bind(audio){
    if(!audio||audio.dataset.stemMediaHealthV229==='1')return;
    audio.dataset.stemMediaHealthV229='1';
    audio.addEventListener('error',()=>{diagnose(audio).catch(()=>mark(audio.dataset.stemAudio,'offline',0));});
    if(audio.error||audio.networkState===HTMLMediaElement.NETWORK_NO_SOURCE){
      diagnose(audio).catch(()=>mark(audio.dataset.stemAudio,'offline',0));
    }
  }

  function boot(){
    root.document.querySelectorAll('audio.stem-audio[data-stem-audio]').forEach(bind);
  }

  if(root.document.readyState==='loading')root.document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})(window);
</script>
<?php endif; ?>
<?php if (function_exists('midi_v217_can_manage') && midi_v217_can_manage()): ?>
<script>
(function(){
  const nav=document.querySelector('.admin-navigation');
  if(!nav||nav.querySelector('[data-admin-midi-v217]'))return;
  const link=document.createElement('a');
  link.dataset.adminMidiV217='1';
  link.href=<?= json_encode(url('/admin/midi.php')) ?>;
  link.innerHTML='<span>MIDI</span>';
  if(<?= json_encode((string)($adminActive ?? '')) ?>==='midi')link.classList.add('active');
  const permissions=Array.from(nav.querySelectorAll('a')).find(node=>/\/admin\/permissions\.php(?:\?|$)/.test(node.getAttribute('href')||''));
  if(permissions)nav.insertBefore(link,permissions);else nav.appendChild(link);
})();
</script>
<?php endif; ?>
<?php
$midiV217StudioRequest = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'stems.php';
$midiV217Allowed = $midiV217StudioRequest
    && !empty($GLOBALS['STONEFELLOW_STEM_ADVANCED_RUNTIME'])
    && function_exists('midi_v217_can_access')
    && midi_v217_can_access();
?>
<?php if ($midiV217Allowed): ?>
<link data-stem-midi-v217 rel="stylesheet" href="<?= e(url('/admin/stem-midi-v217.css?v=' . STONEFELLOW_MIDI_V217)) ?>">
<script>
window.STONEFELLOW_STEM_MIDI_V217=<?= json_encode([
    'build'=>STONEFELLOW_MIDI_V217,
    'enabled'=>true,
    'allowed'=>true,
    'endpoint'=>url('/api/stem-midi-v217.php'),
    'compositionOwnsSnapshots'=>true,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
</script>
<script data-stem-midi-v217 src="<?= e(url('/admin/stem-midi-v217.js?v=' . STONEFELLOW_MIDI_V217)) ?>"></script>
<script data-stem-midi-session-v217>
(function(root){
  'use strict';
  const BUILD='stem-midi-session-v217-20260901';
  if(root.__STONEFELLOW_STEM_MIDI_SESSION_V217__)return;
  root.__STONEFELLOW_STEM_MIDI_SESSION_V217__=true;
  const studioCfg=root.STONEFELLOW_STEM_STUDIO||{};
  const midiCfg=root.STONEFELLOW_STEM_MIDI_V217||{};
  let attempts=0,nextFetch=null,wrapper=null;
  const runtime=()=>root.StonefellowStemMidiV217Runtime||null;
  const wait=ms=>new Promise(resolve=>root.setTimeout(resolve,ms));
  function sameUrl(input,target){try{return new URL(typeof input==='string'?input:input?.url||'',root.location.href).href===new URL(String(target||''),root.location.href).href;}catch(error){return false;}}
  function mixPayload(input,init){if(!studioCfg.mixEndpoint||typeof init?.body!=='string'||!sameUrl(input,studioCfg.mixEndpoint))return null;try{const body=JSON.parse(init.body);return body&&typeof body==='object'?body:null;}catch(error){return null;}}
  async function snapshotRequest(action,fields={}){
    const form=new root.FormData();
    form.append('csrf_token',String(studioCfg.csrf||''));form.append('action',String(action));form.append('track_id',String(studioCfg.trackId||0));
    Object.entries(fields).forEach(([key,value])=>{if(value!==undefined&&value!==null)form.append(key,String(value));});
    const response=await nextFetch(String(midiCfg.endpoint),{method:'POST',credentials:'same-origin',body:form});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error||'MIDI snapshot request failed.');
    return data;
  }
  async function retrySnapshot(action,fields={},limit=3){
    let lastError=null;
    for(let attempt=1;attempt<=limit;attempt+=1){
      try{return await snapshotRequest(action,fields);}catch(error){lastError=error;if(attempt<limit)await wait(120*attempt);}
    }
    throw lastError||new Error('MIDI snapshot request failed.');
  }
  function failureResponse(message,partial=false){
    if(typeof root.Response!=='function')return null;
    return new root.Response(JSON.stringify({ok:false,error:String(message),partial_save:Boolean(partial)}),{
      status:502,
      headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'}
    });
  }
  function setStatus(text,error=false){const node=root.document?.querySelector('[data-midi-status]');if(node){node.textContent=String(text||'');node.classList.toggle('error',Boolean(error));}}
  function reportError(action,error){
    console.warn(`Stem MIDI v217 snapshot ${action}:`,error);
    root.dispatchEvent(new CustomEvent('stonefellow:stem-midi-v217-session-error',{detail:{build:BUILD,action,message:String(error?.message||error)}}));
  }
  async function bridgedFetch(input,init){
    const payload=mixPayload(input,init);
    const midiBefore=payload?.action==='save'?runtime()?.getState?.():null;
    const response=await nextFetch(input,init);
    if(!payload||!response?.ok)return response;
    if(payload.action==='save'&&midiBefore){
      try{
        const result=await response.clone().json();
        const mixId=Number(result?.mix_id||0);
        if(mixId>0){
          await retrySnapshot('snapshot_attach',{mix_id:mixId,state_json:JSON.stringify(midiBefore)});
          setStatus('MIDI SNAPSHOT SAVED');
        }
      }catch(error){
        reportError('save',error);
        setStatus('MIDI SNAPSHOT FAILED · RETRY SAVE',true);
        return failureResponse('The mix audio state was saved, but its MIDI snapshot was not. Retry Save before leaving the session.',true)||response;
      }
      return response;
    }
    if(payload.action==='load'){
      try{
        const mixId=Number(payload.mix_id||0);
        if(mixId>0){
          const snapshot=await retrySnapshot('snapshot_load',{mix_id:mixId});
          if(snapshot?.has_midi&&snapshot.state){
            runtime()?.restoreState?.(snapshot.state,{save:true});
            setStatus('MIDI SNAPSHOT RESTORED');
          }
        }
      }catch(error){
        reportError('load',error);
        setStatus('MIDI RESTORE FAILED · RETRY LOAD',true);
        return failureResponse('Mix recall was stopped because its MIDI snapshot could not be restored. Retry Load to avoid a partial session recall.',false)||response;
      }
    }
    return response;
  }
  function bind(){
    if(midiCfg.compositionOwnsSnapshots){
      root.StonefellowStemMidiSessionV217={build:BUILD,delegatedTo:'v218'};
      return;
    }
    if(!root.fetch||!runtime()||!studioCfg.mixEndpoint||!midiCfg.endpoint){attempts+=1;if(attempts<240)root.setTimeout(bind,50);else root.__STONEFELLOW_STEM_MIDI_SESSION_V217__=false;return;}
    nextFetch=root.fetch.bind(root);wrapper=bridgedFetch;root.fetch=wrapper;root.StonefellowStemMidiSessionV217={build:BUILD};
  }
  root.addEventListener('pagehide',()=>{if(wrapper&&root.fetch===wrapper)root.fetch=nextFetch;},{once:true});
  bind();
})(window);
</script>
<link data-stem-midi-v218 rel="stylesheet" href="<?= e(url('/admin/stem-midi-composition-v218.css?v=stem-midi-composition-v218-20260902')) ?>">
<script data-stem-midi-v218 src="<?= e(url('/admin/stem-midi-composition-v218.js?v=stem-midi-composition-v218-20260902')) ?>"></script>
<script data-stem-midi-v218-hardening src="<?= e(url('/admin/stem-midi-composition-v218-hardening.js?v=stem-midi-composition-v218-hardening-20260902')) ?>"></script>
<link data-stem-virtual-midi-v219 rel="stylesheet" href="<?= e(url('/admin/stem-virtual-midi-keyboard-v219.css?v=stem-virtual-midi-keyboard-v219-20260902')) ?>">
<script data-stem-virtual-midi-v219 src="<?= e(url('/admin/stem-virtual-midi-keyboard-v219.js?v=stem-virtual-midi-keyboard-v219-20260902')) ?>"></script>
<span data-stonefellow-build="stem-midi-foundation-v217-20260901" hidden></span>
<span data-stonefellow-build="stem-midi-session-v217-20260901" hidden></span>
<span data-stonefellow-build="stem-midi-composition-v218-20260902" hidden></span>
<span data-stonefellow-build="stem-midi-composition-v218-hardening-20260902" hidden></span>
<span data-stonefellow-build="stem-virtual-midi-keyboard-v219-20260902" hidden></span>
<?php endif; ?>
<?php $teamChatFooterUser = current_user(); ?>
<?php if ($teamChatFooterUser): ?>
<script>
window.STONEFELLOW_TEAM_CHAT_ADMIN = <?= json_encode([
    'endpoint'=>url('/api/team-chat-v109.php'),
    'csrf'=>csrf_token(),
    'userId'=>(int)$teamChatFooterUser['id'],
    'role'=>(string)($teamChatFooterUser['role'] ?? 'manager'),
    'pageKey'=>'admin_' . preg_replace('/[^a-z0-9_-]/i', '', (string)($adminActive ?? 'workspace')),
    'contextLabel'=>'Admin · ' . ucfirst((string)($adminActive ?? 'Workspace')),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
<script data-team-chat-admin-v109 src="<?= e(url('/team-chat-admin-v109.js?v=mobile-rail-v115-20260825')) ?>"></script>
<?php if (($adminActive ?? '') === 'ai'): ?>
<script src="<?= e(url('/admin/ai-elevenlabs-v102.js?v=rail-mic-debug-v110-20260825')) ?>"></script>
<?php endif; ?>
<?php
$artistCmsFooterUser=current_user();
$artistCmsProfileUrl='';
if($artistCmsFooterUser && user_has_role('artist',$artistCmsFooterUser)){
    $artistCmsProfileUrl=url('/artist-profile.php?user_id='.(int)$artistCmsFooterUser['id']);
    if(function_exists('artist_workspace_v181_schema_ready') && artist_workspace_v181_schema_ready()){
        $artistCmsFooterPdo=db();
        if($artistCmsFooterPdo){
            $artistCmsFooterWorkspace=artist_workspace_v181_lookup_public($artistCmsFooterPdo,'',(int)$artistCmsFooterUser['id']);
            if($artistCmsFooterWorkspace)$artistCmsProfileUrl=artist_workspace_v181_profile_url($artistCmsFooterWorkspace);
        }
    }
}
?>
<?php if($artistCmsProfileUrl!==''): ?>
<script>
window.STONEFELLOW_ARTIST_CMS_V186=<?= json_encode([
    'musicUrl'=>url('/admin/artist-music.php?tab=tracks'),
    'profileUrl'=>$artistCmsProfileUrl,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(url('/admin/artist-cms-nav-v186.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
