<?php
declare(strict_types=1);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Stonefellow-Runtime: conversation-integration-v131-20260826');
    header('X-Stonefellow-Conversation: conversation-integration-v131-20260826');
}

$advancedStudioRuntime = (string)($_GET['advanced_runtime'] ?? '') === '1';
$GLOBALS['STONEFELLOW_STEM_ADVANCED_RUNTIME'] = $advancedStudioRuntime;

ob_start();
require __DIR__ . '/stems-legacy-v108.php';
$html = (string)ob_get_clean();

$token = 'conversation-integration-v131-20260826';
$transportToken = 'c4d304cb';
$projectLoaderToken = 'd2a53ac3';
$coreToken = '005a182d';
$editingToken = 'stem-editing-foundation-v209-20260901';
$professionalEditingToken = 'stem-professional-editing-v210-20260901';
$automationMixerToken = 'stem-automation-mixer-v211-20260901';
$recordingTakesToken = 'stem-recording-takes-v212-20260901';
$recordingEngineToken = 'stem-recording-engine-v213-20260901';
$renderExportToken = 'stem-render-export-v214-20260901';
$audioEngineToken = 'stem-audio-engine-v215-20260901';
$sessionSafetyToken = 'stem-session-safety-v216-20260901';
$voiceToken = 'voice-three-of-three-v157-20260829';
$phase1Token = 'stem-tools-phase1-v127-20260826';
$phase2Token = 'stem-tools-phase2-v128-20260826';
$projectAgentToken = 'stem-project-library-v158-20260829';
$commandBusToken = 'stem-command-bus-v159-20260829';
$studioUser = current_user();
$studioConversationId = max(0,(int)($_GET['conversation_id'] ?? 0));
$studioTaskTitle = 'Stem Studio · ' . (string)($track['title'] ?? 'Track');
$preloaderTitle = e((string)($track['title'] ?? 'song'));
$preloaderHead = <<<'HTML'
<style data-stem-first-paint-loader-v233>
.stem-project-loader-v232{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;background:rgba(15,17,17,.94);backdrop-filter:blur(12px);color:#f5f7f4;font-family:Inter,system-ui,sans-serif;transition:opacity .28s ease,visibility .28s ease}
.stem-project-loader-boot-v233{z-index:100001}
.stem-project-loader-v232.is-ready{opacity:0;visibility:hidden;pointer-events:none}
.stem-project-loader-v232-card{width:min(460px,calc(100vw - 38px));padding:28px;border:1px solid rgba(255,255,255,.12);border-radius:18px;background:rgba(28,31,30,.94);box-shadow:0 28px 90px rgba(0,0,0,.38)}
.stem-project-loader-v232-mark{height:48px;display:flex;align-items:center;gap:5px;margin-bottom:20px}
.stem-project-loader-v232-mark i{display:block;width:5px;border-radius:8px;background:#eef3ed;animation:stemLoaderPulse .88s ease-in-out infinite alternate}
.stem-project-loader-v232-mark i:nth-child(1),.stem-project-loader-v232-mark i:nth-child(7){height:13px}.stem-project-loader-v232-mark i:nth-child(2),.stem-project-loader-v232-mark i:nth-child(6){height:25px;animation-delay:.09s}.stem-project-loader-v232-mark i:nth-child(3),.stem-project-loader-v232-mark i:nth-child(5){height:37px;animation-delay:.18s}.stem-project-loader-v232-mark i:nth-child(4){height:46px;animation-delay:.27s}
@keyframes stemLoaderPulse{from{transform:scaleY(.45);opacity:.45}to{transform:scaleY(1);opacity:1}}
.stem-project-loader-v232-card small{display:block;margin-bottom:7px;color:#aeb8b0;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
.stem-project-loader-v232-card h2{margin:0;font-size:24px;line-height:1.15;font-weight:750;letter-spacing:-.025em}
.stem-project-loader-v232-status{margin-top:16px;font-size:13px;color:#d6ddd7}
.stem-project-loader-v232-wave{margin-top:5px;font-size:11px;color:#8f9b92}
.stem-project-loader-v232-track{height:5px;margin-top:18px;border-radius:999px;background:rgba(255,255,255,.09);overflow:hidden}
.stem-project-loader-v232-track i{display:block;height:100%;width:8%;border-radius:inherit;background:#f1f5f1;transition:width .18s ease}
</style>
<script>window.__STONEFELLOW_STEM_FIRST_PAINT_V233__=performance.now();</script>
HTML;
$html = str_replace('</head>', $preloaderHead . '</head>', $html);
$preloaderBody = <<<HTML
<div id="stemProjectBootLoaderV233" class="stem-project-loader-v232 stem-project-loader-boot-v233" data-stem-first-paint-loader-v233="1">
  <div class="stem-project-loader-v232-card" role="status" aria-live="polite">
    <div class="stem-project-loader-v232-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
    <small>Stonefellow Studio</small>
    <h2>Loading {$preloaderTitle}</h2>
    <div class="stem-project-loader-v232-status">Starting Studio…</div>
    <div class="stem-project-loader-v232-wave">Loading project audio and waveforms.</div>
    <div class="stem-project-loader-v232-track"><i></i></div>
  </div>
</div>
<script data-stem-first-paint-loader-v233-bridge>
(function(root){
  'use strict';
  const boot=document.getElementById('stemProjectBootLoaderV233');
  if(!boot)return;
  let observer=null;
  const removeBoot=()=>{
    observer?.disconnect?.();
    boot.remove?.();
  };
  const handoff=()=>{
    const runtimeLoader=[...document.querySelectorAll('.stem-project-loader-v232')]
      .find(node=>node!==boot);
    if(runtimeLoader){removeBoot();return true;}
    return false;
  };
  if(!handoff()&&typeof MutationObserver==='function'){
    observer=new MutationObserver(handoff);
    observer.observe(document.documentElement,{childList:true,subtree:true});
  }
  root.addEventListener?.('stonefellow:stem-playback-ready',removeBoot,{once:true});
})(window);
</script>
HTML;
$html = preg_replace_callback(
    '~(<body\b[^>]*>)~i',
    static function (array $match) use ($preloaderBody): string {
        return $match[1] . $preloaderBody;
    },
    $html,
    1
) ?? $html;
$probe = <<<'HTML'
<script>
window.STONEFELLOW_STUDIO_RUNTIME_PROBE={
  build:'conversation-integration-v131-20260826',
  errors:[],
  rejections:[],
  guardLoaded:false,
  mainBridge:false,
  metronomeBridge:false,
  toolTruthBridge:false,
  advancedToolsBridge:false,
  commandBusBridge:false,
  conversationEngine:false,
  agentContext:false,
  controls:null,
  resources:[]
};
window.addEventListener('error',function(event){
  window.STONEFELLOW_STUDIO_RUNTIME_PROBE.errors.push({message:String(event.message||'JavaScript error'),source:String(event.filename||''),line:Number(event.lineno||0),column:Number(event.colno||0)});
},true);
window.addEventListener('unhandledrejection',function(event){var reason=event.reason;window.STONEFELLOW_STUDIO_RUNTIME_PROBE.rejections.push(String(reason&&reason.message?reason.message:reason||'Unhandled promise rejection'));});
window.addEventListener('stonefellow:stem-tool-truth',function(){window.STONEFELLOW_STUDIO_RUNTIME_PROBE.toolTruthBridge=true;});
window.addEventListener('stonefellow:stem-advanced-tools',function(){window.STONEFELLOW_STUDIO_RUNTIME_PROBE.advancedToolsBridge=true;});
window.addEventListener('stonefellow:stem-command-bus',function(){window.STONEFELLOW_STUDIO_RUNTIME_PROBE.commandBusBridge=true;});
window.addEventListener('stonefellow:agent-context',function(){window.STONEFELLOW_STUDIO_RUNTIME_PROBE.agentContext=true;});
</script>
HTML;
$voicePrereqs = '<script src="' . e(url('/voice-lease-v122.js?v=' . $voiceToken)) . '"></script>'
              . '<script src="' . e(url('/premium-voice-v117.js?v=' . $voiceToken)) . '"></script>';
$html = str_replace('</head>', $probe . $voicePrereqs . '</head>', $html);

$replacements = [
    'api/stem-agent-v91.php' => 'api/stem-agent-v105.php',
    'admin/stem-master-clock-v201.js?v=201' => 'admin/stem-master-clock-v201.js?v=' . $transportToken,
    'admin/stem-buffer-scheduler-v202.js?v=202' => 'admin/stem-buffer-scheduler-v202.js?v=' . $transportToken,
    'admin/stem-project-loader.js?v=loader' => 'admin/stem-project-loader.js?v=' . $projectLoaderToken,
    'admin/stem-time-stretch-v203.js?v=203' => 'admin/stem-time-stretch-v203.js?v=' . $transportToken,
    'admin/stem-time-stretch-worklet-v203.js?v=203' => 'admin/stem-time-stretch-worklet-v203.js?v=' . $transportToken,
    'admin/stem-loop-planner-v204.js?v=204' => 'admin/stem-loop-planner-v204.js?v=' . $transportToken,
    'admin/stem-transport-v200.js?v=200' => 'admin/stem-transport-v200.js?v=' . $transportToken,
    'admin/stems-v79.js?v=101' => 'admin/stem-editor.js?v=' . $coreToken,
    'admin/stems-v107.js?v=107' => 'admin/stem-editor.js?v=' . $coreToken,
    'admin/stems-v108.js?v=108' => 'admin/stem-editor.js?v=' . $coreToken,
    'admin/stems-v108.js?v=runtime-root-cause-20260825' => 'admin/stem-editor.js?v=' . $coreToken,
    'admin/stems-v108.js?v=force-cache-bust-20260825-2' => 'admin/stem-editor.js?v=' . $coreToken,
    'editor-agent-v91.css?v=97' => 'editor-agent-v107.css?v=' . $token,
    'editor-agent-v107.css?v=108' => 'editor-agent-v107.css?v=' . $token,
    'editor-agent-v107.css?v=runtime-root-cause-20260825' => 'editor-agent-v107.css?v=' . $token,
    'admin/stems-v91.css?v=101' => 'admin/stems-v107.css?v=' . $token,
    'admin/stems-v107.css?v=108' => 'admin/stems-v107.css?v=' . $token,
    'admin/stems-v107.css?v=runtime-root-cause-20260825' => 'admin/stems-v107.css?v=' . $token,
    'admin/stems-v92.css?v=92' => 'admin/stems-extra-v107.css?v=' . $token,
    'admin/stems-extra-v107.css?v=108' => 'admin/stems-extra-v107.css?v=' . $token,
    'admin/stems-extra-v107.css?v=runtime-root-cause-20260825' => 'admin/stems-extra-v107.css?v=' . $token,
    'admin/stem-live-recording-v87.css?v=88' => 'admin/stem-live-recording-v107.css?v=' . $token,
    'admin/stem-live-recording-v107.css?v=108' => 'admin/stem-live-recording-v107.css?v=' . $token,
    'admin/stem-live-recording-v107.css?v=runtime-root-cause-20260825' => 'admin/stem-live-recording-v107.css?v=' . $token,
    'admin/stem-live-recording-v87.js?v=88' => 'admin/stem-live-recording-v107.js?v=' . $token,
    'admin/stem-live-recording-v107.js?v=108' => 'admin/stem-live-recording-v107.js?v=' . $token,
    'admin/stem-live-recording-v107.js?v=runtime-root-cause-20260825' => 'admin/stem-live-recording-v107.js?v=' . $token,
    'editor-voice-barge-v89.js?v=89' => 'editor-voice-barge-v117.js?v=' . $token,
    'editor-voice-barge-v107.js?v=108' => 'editor-voice-barge-v117.js?v=' . $token,
    'editor-voice-barge-v107.js?v=runtime-root-cause-20260825' => 'editor-voice-barge-v117.js?v=' . $token,
    'editor-voice-barge-v107.js?v=voice-continuity-v114-20260825' => 'editor-voice-barge-v117.js?v=' . $token,
    'admin/stem-metronome-v91.js?v=97' => 'admin/stem-metronome-v107.js?v=' . $token,
    'admin/stem-metronome-v107.js?v=108' => 'admin/stem-metronome-v107.js?v=' . $token,
    'admin/stem-metronome-v107.js?v=runtime-root-cause-20260825' => 'admin/stem-metronome-v107.js?v=' . $token,
    'admin/stem-agent-v91.js?v=91' => 'admin/stem-agent-v131.js?v=' . $projectAgentToken,
    'admin/stem-agent-v107.js?v=108' => 'admin/stem-agent-v131.js?v=' . $projectAgentToken,
    'admin/stem-agent-v107.js?v=runtime-root-cause-20260825' => 'admin/stem-agent-v131.js?v=' . $projectAgentToken,
    'admin/stem-agent-v107.js?v=force-cache-bust-20260825-2' => 'admin/stem-agent-v131.js?v=' . $projectAgentToken,
    'admin/stem-agent-v114.js?v=voice-continuity-v114-20260825' => 'admin/stem-agent-v131.js?v=' . $projectAgentToken,
    'admin/stem-agent-v114.js?v=conversation-phase2-v122-20260826' => 'admin/stem-agent-v131.js?v=' . $projectAgentToken,
    'admin/stem-agent-v127.js?v=stem-tools-phase1-v127-20260826' => 'admin/stem-agent-v131.js?v=' . $projectAgentToken,
];
$html = str_replace(array_keys($replacements), array_values($replacements), $html);

$professionalEditingRuntime = '<link data-stem-editing-v210 rel="stylesheet" href="' . e(url('/admin/stem-professional-editing-v210.css?v=' . $professionalEditingToken)) . '">'
    . '<script data-stem-editing-v210 src="' . e(url('/admin/stem-professional-editing-v210.js?v=' . $professionalEditingToken)) . '"></script>';
$editingRuntime = '<script data-stem-editing-v209 src="' . e(url('/admin/stem-editing-v209.js?v=' . $editingToken)) . '"></script>';
$automationMixerRuntime = '<link data-stem-automation-v211 rel="stylesheet" href="' . e(url('/admin/stem-automation-mixer-v211.css?v=' . $automationMixerToken)) . '">'
    . '<script data-stem-automation-v211 src="' . e(url('/admin/stem-automation-mixer-v211.js?v=' . $automationMixerToken)) . '"></script>';
$recordingTakesRuntime = '<link data-stem-recording-v212 rel="stylesheet" href="' . e(url('/admin/stem-recording-takes-v212.css?v=' . $recordingTakesToken)) . '">'
    . '<script data-stem-recording-v212 src="' . e(url('/admin/stem-recording-takes-v212.js?v=' . $recordingTakesToken)) . '"></script>';
$recordingEngineConfig = '<script>window.STONEFELLOW_STEM_RECORDING_V213={build:' . json_encode($recordingEngineToken) . ',endpoint:' . json_encode(url('/api/stem-recording-v213.php')) . '};</script>';
$recordingEngineRuntime = $recordingEngineConfig
    . '<link data-stem-recording-v213 rel="stylesheet" href="' . e(url('/admin/stem-recording-engine-v213.css?v=' . $recordingEngineToken)) . '">'
    . '<script data-stem-recording-v213 src="' . e(url('/admin/stem-recording-engine-v213.js?v=' . $recordingEngineToken)) . '"></script>'
    . '<script data-stem-recording-v213-hardening src="' . e(url('/admin/stem-recording-engine-v213-hardening.js?v=' . $recordingEngineToken)) . '"></script>';
$renderExportConfig = '<script>window.STONEFELLOW_STEM_RENDER_V214={build:' . json_encode($renderExportToken) . ',endpoint:' . json_encode(url('/api/stem-render-v214.php')) . '};</script>';
$renderExportRuntime = $renderExportConfig
    . '<link data-stem-render-v214 rel="stylesheet" href="' . e(url('/admin/stem-render-export-v214.css?v=' . $renderExportToken)) . '">'
    . '<script data-stem-render-v214 src="' . e(url('/admin/stem-render-export-v214.js?v=' . $renderExportToken)) . '"></script>'
    . '<script data-stem-render-v214-hardening src="' . e(url('/admin/stem-render-export-v214-hardening.js?v=' . $renderExportToken)) . '"></script>';
$audioEngineConfig = '<script>window.STONEFELLOW_STEM_AUDIO_V215={build:' . json_encode($audioEngineToken) . ',endpoint:' . json_encode(url('/api/stem-audio-engine-v215.php')) . '};</script>';
$audioEngineRuntime = $audioEngineConfig
    . '<link data-stem-audio-v215 rel="stylesheet" href="' . e(url('/admin/stem-audio-engine-v215.css?v=' . $audioEngineToken)) . '">'
    . '<script data-stem-audio-v215 src="' . e(url('/admin/stem-audio-engine-v215.js?v=' . $audioEngineToken)) . '"></script>'
    . '<script data-stem-audio-v215-hardening src="' . e(url('/admin/stem-audio-engine-v215-hardening.js?v=' . $audioEngineToken)) . '"></script>';
$sessionSafetyConfig = '<script>window.STONEFELLOW_STEM_SESSION_V216={build:' . json_encode($sessionSafetyToken) . ',endpoint:' . json_encode(url('/api/stem-session-v216.php')) . '};</script>';
$sessionRecoveryGuard = <<<'HTML'
<script data-stem-session-v216-recovery-guard>
(function(root){
  'use strict';
  if(root.__STONEFELLOW_STEM_V216_RECOVERY_GUARD__||!root.fetch)return;
  root.__STONEFELLOW_STEM_V216_RECOVERY_GUARD__=true;
  const BUILD='stem-session-safety-v216-recovery-guard-20260901';
  const cfg=root.STONEFELLOW_STEM_STUDIO||{};
  const ext=root.STONEFELLOW_STEM_SESSION_V216||{};
  const dirtyKey=`stonefellow:stem:v216:dirty:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
  const coreStateKey=`stonefellow:stem-studio:state:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}:${String(cfg.pluginImportVersion||'base')}`;
  const nextFetch=root.fetch.bind(root);
  function readMarker(){try{return JSON.parse(root.localStorage?.getItem(dirtyKey)||'null');}catch(error){return null;}}
  function persistAppliedMix(){
    const studio=root.StonefellowStemStudioV91;
    const mix=studio?.collectMixState?.()||studio?.getMixState?.();
    if(!mix)return false;
    try{
      root.localStorage?.setItem(coreStateKey,JSON.stringify({schemaVersion:1,savedAt:Date.now(),trackId:Number(cfg.trackId||0),userId:Number(cfg.userId||0),mix,view:{}}));
      return true;
    }catch(error){return false;}
  }
  function patchApplyMixState(){
    const studio=root.StonefellowStemStudioV91;
    if(!studio?.applyMixState||studio.__STONEFELLOW_V216_APPLY_PATCHED__)return false;
    const original=studio.applyMixState.bind(studio);
    studio.applyMixState=function(state){
      const result=original(state);
      persistAppliedMix();
      return result;
    };
    studio.__STONEFELLOW_V216_APPLY_PATCHED__=true;
    return true;
  }
  function isIndexRequest(input,init){
    if(!ext.endpoint||!init?.body||!root.FormData||!(init.body instanceof root.FormData))return false;
    try{
      const actual=new URL(typeof input==='string'?input:input?.url||'',root.location.href).href;
      const target=new URL(String(ext.endpoint),root.location.href).href;
      return actual===target&&String(init.body.get('action')||'')==='index';
    }catch(error){return false;}
  }
  async function guardedFetch(input,init){
    const response=await nextFetch(input,init);
    if(!isIndexRequest(input,init)||!response?.ok||!root.Response)return response;
    const marker=readMarker();
    const localSignature=String(marker?.signature||'');
    if(!marker?.dirty||!localSignature)return response;
    try{
      const data=await response.clone().json();
      const serverSignature=String(data?.records?.autosave?.signature||'');
      if(!serverSignature||serverSignature===localSignature)return response;
      data.records.autosave=null;
      const headers=new root.Headers(response.headers);
      headers.delete('content-length');
      headers.set('content-type','application/json; charset=utf-8');
      return new root.Response(JSON.stringify(data),{status:response.status,statusText:response.statusText,headers});
    }catch(error){return response;}
  }
  patchApplyMixState();
  root.fetch=guardedFetch;
  root.StonefellowStemSessionSafetyV216RecoveryGuard={build:BUILD,persistAppliedMix};
})(window);
</script>
HTML;
$sessionSafetyRuntime = $sessionSafetyConfig
    . $sessionRecoveryGuard
    . '<link data-stem-session-v216 rel="stylesheet" href="' . e(url('/admin/stem-session-safety-v216.css?v=' . $sessionSafetyToken)) . '">'
    . '<script data-stem-session-v216 src="' . e(url('/admin/stem-session-safety-v216.js?v=' . $sessionSafetyToken)) . '"></script>';
if ($advancedStudioRuntime) {
    $html = preg_replace(
        '~(<script[^>]+src="[^"]*admin/stem-editor\.js[^"]*"[^>]*></script>)~i',
        '$1' . $editingRuntime . $professionalEditingRuntime . $automationMixerRuntime . $recordingTakesRuntime . $recordingEngineRuntime . $renderExportRuntime . $audioEngineRuntime . $sessionSafetyRuntime,
        $html,
        1
    ) ?? $html;
} else {
    $html = preg_replace(
        '~(<script[^>]+src="[^"]*admin/stem-editor\.js[^"]*"[^>]*></script>)~i',
        '$1' . $editingRuntime . $professionalEditingRuntime,
        $html,
        1
    ) ?? $html;
}

$html = preg_replace('~<script[^>]+src="[^"]*editor-voice-barge-v117\.js[^"]*"[^>]*></script>~i','',$html) ?? $html;

$contextConfig = '<script>window.STONEFELLOW_AGENT_CONTEXT={userId:' . (int)($studioUser['id'] ?? 0) . ',surface:"stem",trackId:' . (int)$trackId . ',projectId:0,conversationId:' . $studioConversationId . ',taskTitle:' . json_encode($studioTaskTitle) . ',taskKey:' . json_encode('stem:' . (int)$trackId) . ',csrf:' . json_encode(csrf_token()) . ',proactiveEndpoint:' . json_encode(url('/api/agent-proactive-v93.php')) . '};</script>';
$sharedConversation = $contextConfig
    . '<script src="' . e(url('/conversation-voice-v122.js?v=' . $voiceToken)) . '" onload="window.STONEFELLOW_STUDIO_RUNTIME_PROBE.conversationEngine=!!window.StonefellowConversationVoiceV122"></script>'
    . '<script src="' . e(url('/editor-voice-barge-v117.js?v=' . $voiceToken)) . '"></script>'
    . '<script src="' . e(url('/agent-context-v131.js?v=' . $token)) . '"></script>';
$toolBridge = '<script src="' . e(url('/admin/stem-tool-bridge-v127.js?v=' . $phase1Token)) . '"></script>';
$advancedBridge = '<script src="' . e(url('/admin/stem-advanced-tools-v128.js?v=' . $phase2Token)) . '"></script>';
$projectAgentBridge = '<script src="' . e(url('/admin/stem-project-agent-v158.js?v=' . $projectAgentToken)) . '"></script>';
$commandBusBridge = '<script src="' . e(url('/admin/stem-command-bus-v159.js?v=' . $commandBusToken)) . '"></script>';
$inserted = preg_replace(
    '~(<script[^>]+src="[^"]*admin/stem-agent-v131\.js[^"]*"[^>]*></script>)~i',
    $sharedConversation . $toolBridge . $advancedBridge . $projectAgentBridge . $commandBusBridge . '$1',
    $html,
    1
);
if (is_string($inserted)) $html = $inserted;

$guard = '<script src="' . e(url('/admin/stem-studio-guard-v107.js?v=' . $token)) . '"></script>'
       . '<span data-stonefellow-build="conversation-integration-v131-20260826" hidden></span>'
       . '<span data-stonefellow-build="stem-tools-phase1-v127-20260826" hidden></span>'
       . '<span data-stonefellow-build="stem-tools-phase2-v128-20260826" hidden></span>'
       . '<span data-stonefellow-build="stem-command-bus-v159-20260829" hidden></span>'
       . '<span data-stonefellow-build="stem-media-failure-v228-20260902" hidden></span>'
       . '<span data-stonefellow-build="stem-first-paint-loader-v233-20260902" hidden></span>'
       . '<span data-stonefellow-build="stem-editor-canonical-4079e5a0" hidden></span>'
       . '<span data-stonefellow-build="stem-editing-foundation-v209-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-professional-editing-v210-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-automation-mixer-v211-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-recording-takes-v212-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-recording-engine-v213-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-recording-engine-v213-hardening-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-render-export-v214-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-render-export-v214-hardening-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-audio-engine-v215-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-audio-engine-v215-hardening-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-session-safety-v216-recovery-guard-20260901" hidden></span>'
       . '<span data-stonefellow-build="stem-session-safety-v216-20260901" hidden></span>';
if (!str_contains($html,'conversation-voice-v122.js')) $guard = $sharedConversation . $guard;
if (!str_contains($html,'stem-tool-bridge-v127.js')) $guard = $toolBridge . $advancedBridge . $guard;
elseif (!str_contains($html,'stem-advanced-tools-v128.js')) $guard = $advancedBridge . $guard;
if (!str_contains($html,'stem-project-agent-v158.js')) $guard = $projectAgentBridge . $guard;
if (!str_contains($html,'stem-command-bus-v159.js')) $guard = $commandBusBridge . $guard;
$html = str_replace('</body>', $guard . '</body>', $html);
echo $html;