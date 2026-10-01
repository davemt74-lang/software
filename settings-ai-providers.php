<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/ai-settings.php';
require_permission('account.access');
$user=current_user();
if(!$user)redirect(url('/login.php'));
$models=ai_model_catalog();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e(system_agent_name())?> | AI Providers</title>
<link rel="stylesheet" href="<?=e(url('/chat.css?v=82'))?>">
<link rel="stylesheet" href="<?=e(url('/account.css?v=account-light-20260904'))?>">
<style>
.llm-shell{width:min(960px,100%);margin:0 auto;padding:32px 22px 80px}.llm-shell h1{font-size:29px;margin:0 0 10px}
.llm-help{line-height:1.6;color:#576174}.llm-cards{display:grid;gap:18px;margin-top:22px}
.llm-card{background:#fff;color:#1b2736;border:1px solid #dce3ea;border-radius:15px;padding:22px}
.llm-card h2{font-size:19px}.llm-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:14px 0}
.llm-row>*{flex:1;min-width:160px} .llm-row label{display:grid;gap:7px;font-size:13px}
.llm-row input,.llm-row select{padding:12px;border-radius:8px;border:1px solid #b9c6d5;max-width:100%;box-sizing:border-box}
.llm-card button{padding:11px 15px;border:0;border-radius:8px;background:#263e62;color:#fff;cursor:pointer}
.llm-card button:disabled{opacity:.5;cursor:default}.llm-card button.secondary{background:#e3e9ef;color:#233349}
.llm-status{font-weight:600}.llm-note{font-size:12px;color:#4a5568}#llmStatus{min-height:22px}
@media(max-width:700px){.llm-shell{padding:18px}.llm-row{display:grid}}
</style></head><body>
<div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='account';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="llm-shell" aria-label="Cloud AI provider settings">
<a href="<?=e(url('/settings-homeserver.php'))?>">← HomeServer connection</a>
<h1>AI providers and compute</h1>
<p class="llm-help">Choose VP3's subscription-funded AI or use your own provider key for Cloud inference. HomeServer keeps its own private keys locally. Your keys are never sent through the HomeServer relay.</p>
<p id="llmStatus" role="status" aria-live="polite"></p>
<section class="llm-cards">
<article class="llm-card"><h2>Compute source</h2>
<p class="llm-note">VP3 system providers use your account's existing subscription and token limits. Your own key is billed directly by its provider; account access and request-rate limits still apply.</p>
<div class="llm-row"><label for="llmRoute">Cloud inference source
<select id="llmRoute"><option value="system">VP3 system LLM (subscription tokens)</option><option value="openai">My OpenAI API key</option><option value="anthropic">My Anthropic API key</option></select></label><button id="llmSaveRoute" type="button">Save source</button></div>
</article>
<?php foreach(['openai'=>'OpenAI','anthropic'=>'Anthropic'] as $provider=>$label): ?>
<article class="llm-card" data-llm-provider="<?=e($provider)?>"><h2><?=e($label)?></h2><p class="llm-status" data-key-status>Checking…</p>
<form class="llm-provider-form" data-provider="<?=e($provider)?>">
<div class="llm-row"><label>Private API key<input type="password" name="api_key" autocomplete="off" required maxlength="4000" placeholder="Key is never displayed after saving"></label>
<label>Model<select name="model"><?php foreach($models[$provider] as $id=>$name): ?><option value="<?=e($id)?>"><?=e($name)?></option><?php endforeach; ?></select></label></div>
<button type="submit">Save encrypted key</button> <button type="button" class="secondary" data-remove="<?=e($provider)?>">Remove key</button>
</form></article>
<?php endforeach; ?>
<article class="llm-card"><h2>HomeServer provider keys</h2>
<p>Manage Ollama, OpenAI, Anthropic and OpenRouter in your local HomeServer's Agent Brain settings. Cloud uses paired capability status but never transfers local keys or sends Cloud personal keys to HomeServer.</p>
<p id="llmHomeStatus" role="status" aria-live="polite">Reading paired HomeServer status…</p>
<a href="<?=e(url('/settings-homeserver.php'))?>">View Cloud–HomeServer connection</a></article>
</section></main></div>
<script src="<?=e(url('/member-shell-v77.js'))?>"></script>
<script>
(()=>{'use strict';
const endpoint=<?=json_encode(url('/api/user-llm-v1.php'))?>;
const csrf=<?=json_encode(csrf_token())?>;
const byId=id=>document.getElementById(id);
let state=null;
const status=(message,error=false)=>{const n=byId('llmStatus');n.textContent=message;n.style.color=error?'#a32929':'#1a6550';};
async function request(payload){
 const response=await fetch(endpoint,payload?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,csrf_token:csrf})}:{credentials:'same-origin',cache:'no-store'});
 const result=await response.json();
 if(!response.ok||!result.ok)throw new Error(result.error||'Provider settings could not be saved.');
 return result.state;
}
function render(next){
 state=next;byId('llmRoute').value=next.route==='own_key'?next.provider:'system';
 for(const form of document.querySelectorAll('[data-provider]')){
  const provider=form.dataset.provider;const info=next.providers?.[provider];form.querySelector('[data-key-status]').textContent=info?.configured?'Saved key ending '+info.suffix:'No Cloud key saved';
  if(info?.model)form.querySelector('[name=model]').value=info.model;
 }
}
async function run(fn){
 try{status('Saving…');render(await fn());status('Provider settings saved.');return true;}
 catch(error){status(error.message,true);return false;}
}
document.querySelectorAll('form[data-provider]').forEach(form=>form.addEventListener('submit',event=>{
 event.preventDefault();const provider=form.dataset.provider||form.dataset.llmProvider;
 const key=form.querySelector('[name=api_key]');const model=form.querySelector('[name=model]');
 run(()=>request({action:'save',provider,api_key:key.value,model:model.value})).then(saved=>{if(saved)key.value='';});
}));
document.querySelectorAll('[data-remove]').forEach(button=>button.addEventListener('click',()=>{
 if(!confirm('Remove this Cloud API key?'))return;
 run(()=>request({action:'remove',provider:button.dataset.remove}));
}));
byId('llmSaveRoute').addEventListener('click',()=>run(()=>{
 const value=byId('llmRoute').value;
 return request({action:'select',route:value==='system'?'system':'own_key',provider:value==='system'?'':value});
}));
request().then(render).catch(e=>status(e.message,true));
fetch(<?=json_encode(url('/api/homeserver-connection-v1200.php'))?>,{credentials:'same-origin',cache:'no-store'})
 .then(response=>response.ok?response.json():Promise.reject())
 .then(data=>{
    const state=data.status||{},node=byId('llmHomeStatus');
    if(!node)return;
    const raw=state.compute||state.capabilities?.inference||state.inference||{};
    const source=String(raw.source_label||raw.compute_source||raw.source||'');
    node.textContent=source?'Paired HomeServer: '+source:
      'HomeServer inference status is not reported here. Open connection settings for details.';
 }).catch(()=>{const node=byId('llmHomeStatus');if(node)node.textContent='HomeServer not connected or status unavailable.';});
})();
</script></body></html>
