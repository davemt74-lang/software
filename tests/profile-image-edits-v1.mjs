import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {File} from 'node:buffer';
import {webcrypto} from 'node:crypto';
import {Element,Document} from './fixtures/profile-calls-pa3c.mjs';

// Exercise the production module with the same lightweight DOM used by Profile calls.
const simpleMatch=Element.prototype.matches;
Element.prototype.matches=function(selector){
  const parts=selector.trim().match(/(?:\[[^\]]*\]|[^\s\[])+/g)||[];if(parts.length===1)return simpleMatch.call(this,selector);
  if(!simpleMatch.call(this,parts.pop()))return false;
  let ancestor=this.parentElement;
  while(parts.length){const part=parts.pop();while(ancestor&&!simpleMatch.call(ancestor,part))ancestor=ancestor.parentElement;if(!ancestor)return false;ancestor=ancestor.parentElement;}
  return true;
};
Element.prototype.closest=function(selector){for(let current=this;current;current=current.parentElement)if(current.matches(selector))return current;return null;};
Element.prototype.before=function(...nodes){for(const item of nodes)this.parentElement.insertBefore(item,this);};
Element.prototype.removeAttribute=function(key){delete this.attrs[key];if(key==='src')delete this.src;};
const document=new Document(),create=document.createElement.bind(document);let chooser=null;
document.createElement=tag=>{
  const element=create(tag);
  if(tag==='input')element.click=function(){if(this.type==='file')chooser=this;else Element.prototype.click.call(this);};
  if(tag==='select'){
    let selected='';Object.defineProperty(element,'value',{get(){return this.children.some(option=>option.value===selected)?selected:(this.children[0]?.value||'');},set(value){selected=String(value);},configurable:true});
  }
  return element;
};
const make=(tag,attrs={})=>{const element=document.createElement(tag);for(const [key,value] of Object.entries(attrs))element.setAttribute(key,value);return element;};
const shell=make('section'),thread=make('div'),footer=make('footer',{class:'profile-agent-footer'}),form=make('form');
const toolbar=make('div',{class:'profile-agent-toolbar'});form.append(toolbar);footer.append(form);
const help=make('section',{'data-profile-attachment-help':''}),helpOpen=make('button',{'data-profile-image-edit-open':''}),share=make('button',{'data-profile-image-share-owner':''});help.append(helpOpen,share);
const options=make('section'),open=make('button',{'data-profile-image-edit-open':''});options.append(open);
shell.append(thread,help,options,footer);document.body.append(shell);
const namespace='a'.repeat(64),cfg={username:'artist',conversationNamespace:namespace,endpoint:'/api/profile-agent.php'};
const current={id:31,key:'artist|visitor|31',active:true,sessionChanged:false,loading:false,busy:false,human:false,clockOffset:0};
let now=1800000000000,changes=0,focused=0,interval=0,selectedPanel=null;const notices=[],panels=[],revoked=[];
class Clock extends Date{static now(){return now;}}
class LocalURL extends URL{static createObjectURL(){return 'blob:fixture/'+String(changes);}static revokeObjectURL(value){revoked.push(value);}}
const window={location:{href:'https://profile.example.test/artist',origin:'https://profile.example.test'},setInterval:()=>++interval};
function Option(label,value){const option=make('option');option.textContent=label;option.value=String(value);return option;}
vm.runInNewContext(fs.readFileSync(new URL('../profile-image-edits-v1.js',import.meta.url),'utf8'),{window,document,URL:LocalURL,Option,Date:Clock,crypto:webcrypto,Uint8Array,clearInterval:()=>{}});
const surface=window.VP3ProfileImageEditsV1.mount({shell,thread,form,cfg,context:()=>current,
  registerPanel:entry=>panels.push(entry),showPanel:entry=>{selectedPanel=entry;for(const panel of panels)panel.panel.hidden=panel!==entry;thread.hidden=Boolean(entry);},
  onDraft:()=>changes++,notice:message=>notices.push(message),focusComposer:()=>focused++});
const catalog={collection_id:7,name:'Warm portrait style',catalog_revision:3,references:[{asset_id:12,title:'Amber portrait',price_cents:0},{asset_id:14,title:'Soft light',price_cents:0}]};
const availability={contract:'vp3.profile-image-edit.v1',enabled:true,max_input_bytes:4194304,catalogs:[catalog]};
const flush=async()=>{for(let i=0;i<8;i++)await new Promise(resolve=>setTimeout(resolve,2));};
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
surface.update({image_editing:availability,image_edits:[]});
check(surface.enabled()&&!open.hidden&&!surface.hasDraft(),'approved free styles expose editing without creating a request or draft');
open.click();check(selectedPanel?.panel.dataset.profileImagePanel===''&&thread.hidden,'style selection uses the conversation canvas');
const catalogSelect=shell.querySelector('[aria-label="Style catalog"]'),referenceSelect=shell.querySelector('[aria-label="Style reference"]');
check(catalogSelect.value==='7'&&referenceSelect.value==='0','catalog style is the default and exact image choices remain available');
shell.querySelector('[data-profile-image-choose]').click();
const photo=new File(['image-bytes'],'portrait.png',{type:'image/png'});chooser.files=[photo];chooser.dispatchEvent(new Event('change'));await flush();
check(surface.hasDraft()&&shell.querySelector('[data-profile-image-draft]').textContent.includes('portrait.png'),'choosing a subject stages a visible removable draft');
const intent=surface.prepare('Match this style and soften the lighting.');
check(intent.kind==='image_edit'&&intent.catalog_id===7&&intent.catalog_revision===3&&intent.image_id===0&&intent.upload===photo&&!('base_message_id'in intent),'explicit edit intent binds prompt submission to the exact selected catalog and uploaded subject');
check(/^[a-f0-9]{64}$/.test(intent.image_subject.sha256)&&intent.image_subject.bytes===photo.size,'subject hash is available for exact same-file recovery before dispatch');
assert.throws(()=>surface.prepare(''),/Describe/);check(true,'empty instructions cannot silently start image editing');
referenceSelect.value='14';referenceSelect.dispatchEvent(new Event('change'));
check(surface.prepare('Use this style.').image_id===14,'an explicit style image replaces the catalog choice');
surface.update({image_editing:{...availability,catalogs:[{...catalog,catalog_revision:4}]}});
assert.throws(()=>surface.prepare('Keep the same style.'),/changed/);check(true,'a changed catalog cannot reuse the earlier frozen selection');
shell.querySelector('[data-profile-image-use]').click();
check(surface.prepare('Keep the same style.').catalog_revision===4&&focused===1&&!thread.hidden,'reviewing the refreshed style explicitly updates its revision and returns to the composer');
current.human=true;assert.throws(()=>surface.prepare('Edit.'),/Return to Agent/);check(true,'human live chat cannot be silently converted into an AI edit');current.human=false;
current.busy=true;assert.throws(()=>surface.prepare('Edit.'),/retry/);check(true,'uncertain or active sends lock image intent changes');current.busy=false;
shell.querySelector('[data-profile-image-choose]').click();const staleChooser=chooser;
current.key='artist|another-visitor|31';staleChooser.files=[photo];staleChooser.dispatchEvent(new Event('change'));
await flush();
check(shell.querySelector('[data-profile-image-panel]').textContent.includes('conversation changed'),'a file chooser opened for another visitor context is rejected');current.key='artist|visitor|31';
const imageUrl=(id,preview=true,overrides={})=>'/api/profile-agent.php?'+new URLSearchParams({action:'image',username:'artist',conversation_namespace:namespace,conversation_id:'31',message_id:String(id),...(preview?{preview:'1'}:{}),...overrides});
const image={kind:'result',request_id:'b'.repeat(32),source_message_id:101,filename:'edited-photo.png',mime:'image/png',bytes:123,url:imageUrl(202),download_url:imageUrl(202,false)};
const result=make('div');surface.renderMessage(result,image,202,'agent');thread.append(result);
check(result.querySelector('img')?.src===new URL(image.url,window.location.href).href,'saved Agent results use the authorized conversation image route');
result.querySelector('button').click();
check(selectedPanel?.panel.dataset.profileImageViewer===''&&shell.querySelector('[data-profile-image-viewer] a')?.download==='edited-photo.png','result opens an inline viewer with a real download');
const input=make('div',{'data-message-id':'101'});thread.append(input);
const job={request_id:image.request_id,input_message_id:101,result_message_id:202,state:'queued',catalog_id:7,image_id:12,started_at_ms:null,active_lease_expires_at:null};
surface.update({image_edits:[job]});
check(input.textContent==='Queued for photo editing'&&input.querySelector('[data-profile-image-job]').dataset.active==='false','queued status is saved state without invented active progress');
surface.update({image_edits:[{...job,state:'editing',started_at_ms:now-5000,active_lease_expires_at:now+60000}]});
check(input.textContent.includes('5s elapsed')&&input.querySelector('[data-profile-image-job]').dataset.active==='true','animation and elapsed time require an actual active provider lease');
now+=61000;surface.renderStatuses();
check(input.textContent==='Checking photo edit status'&&input.querySelector('[data-profile-image-job]').dataset.active==='false','expired provider lease removes the working animation');
surface.update({image_edits:[{...job,state:'ready'}]});
const reuse=shell.querySelector('[data-profile-image-viewer]').querySelectorAll('button').find(item=>item.textContent==='Edit this result');reuse.click();
const followup=surface.prepare('Make the lighting cooler.');
check(followup.base_message_id===202&&!followup.upload&&followup.catalog_id===7&&followup.image_id===12,'revision uses the exact generated result as subject and restores its original style choice');
for(const modified of [{...image,url:'https://other.example/image.png'},{...image,url:imageUrl(202,true,{conversation_id:'99'})},{...image,download_url:imageUrl(202,false,{conversation_namespace:'c'.repeat(64)})},{...image,url:imageUrl(203)}]){
  const container=make('div');surface.renderMessage(container,modified,202,'agent');check(!container.children.length,'foreign or mismatched result URL cannot become an image or download');
}
const visitorResult=make('div');surface.renderMessage(visitorResult,image,202,'visitor');check(!visitorResult.children.length,'a visitor message cannot manufacture an Agent result');
surface.update({image_editing:{...availability,catalogs:[{...catalog,references:[{asset_id:12,title:'Paid style',price_cents:1}]}]}});
check(!surface.enabled(),'nonzero style references are unavailable in the free-only client');
let reattached=null;current.busy=true;current.sending=false;
surface.recover({...intent,client_request_id:'d'.repeat(32)},async file=>{reattached=file;});
const restore=shell.querySelector('[data-profile-image-reattach]');
check(!restore.disabled&&!surface.hasDraft(),'dedicated exact-file recovery stays usable while ordinary drafts are locked');
restore.click();chooser.files=[new File(['wrong-bytes'],'portrait.png',{type:'image/png'})];chooser.dispatchEvent(new Event('change'));await flush();
check(reattached===null&&notices.at(-1).includes('different photo'),'matching file name and size cannot bypass the saved subject hash');
restore.click();chooser.files=[photo];chooser.dispatchEvent(new Event('change'));await flush();
check(reattached===photo,'reattaching the same bytes invokes the frozen retry callback');current.busy=false;
surface.reset(true);
check(!surface.hasDraft()&&revoked.length>0&&!shell.querySelector('[data-profile-image-viewer] img').src&&open.hidden,'reset revokes local photo previews and clears old conversation image state');
console.log(`PROFILE_IMAGE_EDITS_UI_V1=PASS ${checks} checks`);
