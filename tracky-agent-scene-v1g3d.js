/* Expire the server-rendered checked snapshot; never renew perception. */
(()=>{'use strict';const panel=document.getElementById('agentSceneMeaning');if(!panel||panel.dataset.sceneState!=='available')return;
const clear=()=>{panel.querySelector('[data-scene-meaning]').textContent='No current checked scene is available. Reload to check status; start a new supervised observation on HomeServer if needed.';};
const age=Number(panel.dataset.sceneAge);if(!Number.isFinite(age)||age<0||age>60||document.visibilityState!=='visible'){clear();return;}
// Navigation started before the response was generated: subtracting its total
// duration is conservative and cannot extend the original 60-second ceiling.
const transit=typeof performance!=='undefined'?performance.now()/1000:0;
setTimeout(clear,Math.max(0,(60-age-transit)*1000));
document.addEventListener('visibilitychange',()=>{if(document.visibilityState!=='visible')clear();});})();
