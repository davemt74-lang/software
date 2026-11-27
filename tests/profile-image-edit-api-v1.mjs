import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {randomBytes} from 'node:crypto';
import {spawn,spawnSync} from 'node:child_process';

const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-profile-image-api-'));
const php=process.env.VP3_PHP_BINARY||'php',config=path.join(tmp,'fixture.json'),fixture=path.resolve('tests/fixtures/profile-image-edit-api-db-v1.php');
const invoke=(action,...args)=>{const r=spawnSync(php,[fixture,action,config,...args.map(String)],{encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;};
let catalog=JSON.parse(invoke('init'));
const png=Buffer.from(invoke('png'),'base64'),otherPNG=Buffer.from(invoke('png',99),'base64');
fs.mkdirSync(path.join(tmp,'includes'));fs.mkdirSync(path.join(tmp,'api'));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
fs.copyFileSync('api/profile-agent.php',path.join(tmp,'api/profile-agent.php'));
fs.copyFileSync('tests/fixtures/profile-image-edit-api-bootstrap-v1.php',path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'boot.php'),`<?php require __DIR__.'/includes/bootstrap.php'; header('Content-Type: application/json'); $ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user()); echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$ctx['conversation_authority']['credential_hash'])],JSON_THROW_ON_ERROR);`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-d','upload_max_filesize=5M','-d','post_max_size=8M','-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config},stdio:['ignore','ignore','pipe']});
let logs='',checks=0;child.stderr.on('data',d=>logs+=d);const base=`http://127.0.0.1:${port}`,key=()=>randomBytes(16).toString('hex');
const who=(id,cookie)=>({id,cookie:`PHPSESSID=edit${cookie}; sf_profile_visitor=${cookie.repeat(64)}; sf_profile_conversation=${cookie.repeat(64)}`});
const visitor=who(2,'b'),foreign=who(3,'c'),owner=who(1,'d');
const anonymous={id:0,cookie:''},readOnly=actor=>({...actor,readOnly:true});
const headers=actor=>({'X-Fixture-User':String(actor.id),Cookie:actor.cookie,...(actor.readOnly?{'X-Fixture-Read-Only':'1'}:{})});
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function jsonRequest(method,fields,actor=visitor){const read=method==='GET',r=await fetch(base+'/api/profile-agent.php'+(read?'?'+new URLSearchParams(fields):''),{method,headers:{...headers(actor),'Content-Type':'application/json'},...(!read?{body:JSON.stringify(fields)}:{})});const raw=await r.text();let data;try{data=JSON.parse(raw);}catch{throw Error(raw+'\n'+logs.slice(-4000));}return {status:r.status,data,headers:r.headers};}
async function upload(fields,bytes=png,actor=visitor){const form=new FormData();form.append('image_edit_payload',JSON.stringify(fields));form.append('profile_image',new Blob([bytes],{type:'image/png'}),'visitor.png');const r=await fetch(base+'/api/profile-agent.php',{method:'POST',headers:headers(actor),body:form});const raw=await r.text();let data;try{data=JSON.parse(raw);}catch{throw Error(raw+'\n'+logs.slice(-4000));}return {status:r.status,data};}
const state=()=>JSON.parse(invoke('state'));
try{
    const galleryFields={action:'image_collection',username:'fixture-owner'};
    let ready=false;for(let i=0;i<80;i++){try{if((await jsonRequest('GET',galleryFields,readOnly(anonymous))).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);
    const galleryState=()=>invoke('gallery_state');
    const referenceFields=(asset=11)=>({action:'reference_image',username:'fixture-owner',catalog_id:catalog.catalog_id,image_id:asset,catalog_revision:catalog.revision});
    const referenceURL=fields=>base+'/api/profile-agent.php?'+new URLSearchParams(fields);
    const reference=async(fields=referenceFields(),actor=readOnly(anonymous))=>fetch(referenceURL(fields),{headers:headers(actor)});
    const extra=JSON.parse(invoke('gallery_extra')),beforeGallery=galleryState();
    const publicGallery=await jsonRequest('GET',galleryFields,readOnly(anonymous));
    check(publicGallery.status===200&&publicGallery.data.image_editing.enabled&&publicGallery.data.image_editing.catalogs.length===1,'anonymous gallery read works without creating a visitor conversation or requiring a token');
    const choices=publicGallery.data.image_editing.catalogs[0].references;
    check(choices.map(item=>item.asset_id).join(',')==='11,12','gallery lists only the explicitly approved free references, not every Media collection member');
    const previewURL=new URL(choices[0].preview_url,base);
    check(previewURL.origin===base&&previewURL.pathname==='/api/profile-agent.php'&&previewURL.searchParams.get('action')==='reference_image'&&previewURL.searchParams.get('username')==='fixture-owner'&&previewURL.searchParams.get('catalog_id')===String(catalog.catalog_id)&&previewURL.searchParams.get('catalog_revision')===String(catalog.revision)&&previewURL.searchParams.get('image_id')==='11'&&Array.from(previewURL.searchParams.keys()).length===5,'reference URL carries only current public catalog identity and revision');
    check(!JSON.stringify(publicGallery.data).match(/private\/|file_path|source_sha256|profile_token|conversation_namespace|visitor_message_id|media-library-file/),'gallery exposes no private storage, visitor authority, conversation originals or results');
    let response=await reference();
    check(response.status===200&&response.headers.get('Content-Type')==='image/png'&&Buffer.from(await response.arrayBuffer()).equals(Buffer.from(invoke('png',11),'base64')),'public reference endpoint returns the exact approved PNG bytes under a database read-only guard');
    check(response.headers.get('Cache-Control')==='private, no-store'&&response.headers.get('X-Content-Type-Options')==='nosniff'&&response.headers.get('Cross-Origin-Resource-Policy')==='same-origin'&&response.headers.get('Content-Security-Policy')==="default-src 'none'; sandbox"&&response.headers.get('Content-Disposition')==='inline; filename="reference-image-11.png"','public reference delivery prevents caching, MIME sniffing, cross-origin embedding and executable content');
    for(const actor of [owner,visitor]){
        const view=await jsonRequest('GET',galleryFields,readOnly(actor));response=await reference(referenceFields(),readOnly(actor));
        const common=data=>({...data,image_editing:Object.fromEntries(Object.entries(data.image_editing).filter(([field])=>field!=='allowance'))});
        check(view.status===200&&JSON.stringify(common(view.data))===JSON.stringify(common(publicGallery.data))&&response.status===200,actor===owner?'owner preview can browse the same public collection without visitor impersonation':'signed-in visitor sees the same explicitly public free collection');
        const allowance=view.data.image_editing.allowance;
        check(actor===owner?allowance.ready===false&&allowance.remaining===null:allowance.ready===true&&Number.isInteger(allowance.remaining)&&allowance.remaining>0,actor===owner?'owner preview reports unknown visitor allowance':'signed-in gallery visitor receives their current positive allowance');
        await response.arrayBuffer();
    }
    check(galleryState()===beforeGallery,'anonymous, owner and signed-in gallery/image reads change no sessions, conversations, work, accounting, usage or catalog rows');
    let r=await jsonRequest('POST',galleryFields,readOnly(anonymous));check(r.status===405,'gallery browsing accepts only GET');
    r=await jsonRequest('POST',referenceFields(),readOnly(anonymous));check(r.status===405,'reference image delivery accepts only GET');
    r=await jsonRequest('GET',{...galleryFields,conversation_id:1},readOnly(anonymous));check(r.status===422,'gallery cannot accept conversation selectors');
    r=await jsonRequest('GET',{...referenceFields(),file_path:'private/user-media/1/style-11.png'},readOnly(anonymous));check(r.status===422,'reference delivery rejects arbitrary path selectors');
    for(const image_id of ['0','-1','11junk','1e1','9999999999999999']){
        response=await reference({...referenceFields(),image_id});check(response.status===404,'invalid reference image ID '+image_id+' fails closed');await response.arrayBuffer();
    }
    for(const [fields,label] of [
        [{...referenceFields(),image_id:13},'unapproved owned member'],
        [{...referenceFields(),image_id:21},'foreign image'],
        [{...referenceFields(),catalog_id:extra.foreign_collection_id,image_id:21},'another owner approved collection'],
        [{...referenceFields(),catalog_revision:catalog.revision+1},'different approval revision'],
    ]){response=await reference(fields);check(response.status===404,label+' cannot reveal reference bytes');await response.arrayBuffer();}
    invoke('gallery_change','priced_default');
    r=await jsonRequest('GET',galleryFields,readOnly(anonymous));
    check(r.status===200&&r.data.image_editing.catalogs[0].references.map(item=>item.asset_id).join(',')==='12','positive catalog price hides inherited prices while explicit zero remains public');
    catalog.revision++;
    response=await reference(referenceFields(11));check(response.status===404,'inherited positive price prevents direct reference delivery');await response.arrayBuffer();
    response=await reference(referenceFields(12));check(response.status===200,'explicit zero-priced image remains viewable under a priced catalog');await response.arrayBuffer();
    for(const change of ['priced_image','unapproved','disabled','member_removed','reference_removed','revision','metadata','bytes','private','inactive_owner','agent_disabled','inactive_agent','foreign_agent','profile_permission','chat_permission','private_path','unsupported','schema_missing']){
        catalog=JSON.parse(invoke('init'));invoke('gallery_change',change);
        response=await reference();check(response.status===404,'reference delivery rejects '+change.replaceAll('_',' '));await response.arrayBuffer();
        r=await jsonRequest('GET',galleryFields,readOnly(anonymous));
        if(['private','inactive_owner','agent_disabled','inactive_agent','foreign_agent','profile_permission','chat_permission'].includes(change)){
            check(r.status===404,'gallery rechecks current '+change.replaceAll('_',' ')+' authority');
        }else if(['disabled','unapproved','schema_missing'].includes(change)){
            check(r.status===200&&!r.data.image_editing.enabled&&r.data.image_editing.catalogs.length===0&&r.data.image_editing.reason.length>0,'gallery explains '+change.replaceAll('_',' ')+' without hiding its section or creating schema');
        }else if(['priced_image','member_removed','reference_removed','metadata','private_path','unsupported'].includes(change)){
            check(r.status===200&&r.data.image_editing.catalogs[0].references.every(item=>item.asset_id!==11),'gallery omits '+change.replaceAll('_',' ')+' reference');
        }
    }
    catalog=JSON.parse(invoke('init'));
    const boot=async actor=>(await (await fetch(base+'/boot.php',{headers:headers(actor)})).json());
    const a=await boot(visitor),b=await boot(foreign);const binding={username:'fixture-owner',profile_token:a.token,conversation_namespace:a.namespace};
    const intent={...binding,action:'image_edit',conversation_id:0,client_request_id:key(),catalog_id:catalog.catalog_id,catalog_revision:catalog.revision,message:'Match this style and keep my face.'};
    r=await jsonRequest('GET',{...binding,action:'state'});check(r.status===200&&r.data.image_editing.enabled&&r.data.image_editing.catalogs.length===1,'actual public state exposes approved free style choices');
    check(r.data.image_editing.catalogs[0].references.every(item=>new URL(item.preview_url,base).searchParams.get('action')==='reference_image'),'normal chat state retains public reference preview URLs');
    const before=JSON.stringify(state());r=await jsonRequest('GET',intent);check(r.status===405,'GET cannot admit a paid-provider photo operation');
    r=await upload({...intent,profile_token:'wrong'});check(r.status===419,'photo upload requires the current Profile token');
    r=await upload({...intent,conversation_namespace:'f'.repeat(64)});check(r.status===409&&r.data.code==='PROFILE_SESSION_CHANGED','upload cannot cross the current visitor namespace');
    r=await upload(intent,Buffer.from('not pixels'));check(r.status===422,'declared image MIME cannot admit non-image bytes');
    check(JSON.stringify(state())===before,'invalid uploads and authorization failures leave no messages or image jobs');
    r=await upload(intent);check(r.status===200&&r.data.accepted&&r.data.image_edit?.kind==='input','real PHP multipart upload saves a distinct AI-processable photo');
    const receipt=r.data,cid=receipt.conversation_id,inputUrl=receipt.image_edit.url;
    const beforeRecovery=JSON.stringify(state());
    r=await jsonRequest('GET',{...binding,action:'image_edit_receipt',client_request_id:intent.client_request_id});check(r.status===200&&r.data.found&&r.data.accepted&&r.data.duplicate&&r.data.conversation_id===cid&&r.data.visitor_message_id===receipt.visitor_message_id&&r.data.image_edit.request_id===receipt.image_edit.request_id,'lost first-upload acknowledgment recovers the exact saved receipt without a File object or conversation ID');
    r=await jsonRequest('GET',{username:'fixture-owner',conversation_namespace:b.namespace,action:'image_edit_receipt',client_request_id:intent.client_request_id},foreign);check(r.status===200&&r.data.found===false,'receipt lookup does not expose another visitor request');
    r=await jsonRequest('GET',{...binding,action:'image_edit_receipt',client_request_id:key()});check(r.status===200&&r.data.found===false,'receipt read distinguishes a request not yet admitted');
    r=await jsonRequest('POST',{...binding,action:'image_edit_receipt',client_request_id:intent.client_request_id});check(r.status===405,'receipt recovery exposes only the read action');
    check(JSON.stringify(state())===beforeRecovery,'receipt recovery creates no messages, work or provider calls');
    r=await upload(intent);check(r.status===200&&r.data.duplicate&&r.data.visitor_message_id===receipt.visitor_message_id,'multipart uncertain-response retry recovers the same exact saved input');
    r=await upload(intent,otherPNG);check(r.status===409&&r.data.code==='HANDOFF_REPLAY_CONFLICT','same multipart request cannot replace the uploaded pixels');
    response=await fetch(base+inputUrl,{headers:headers(visitor)});check(response.status===200&&response.headers.get('Content-Type')==='image/png'&&Buffer.from(await response.arrayBuffer()).equals(png),'authorized visitor input URL returns exact photo bytes');
    check(response.headers.get('Cache-Control')==='private, no-store'&&response.headers.get('X-Content-Type-Options')==='nosniff','image delivery is private and disallows MIME sniffing');
    const foreignUrl=new URL(base+inputUrl);foreignUrl.searchParams.set('conversation_namespace',b.namespace);response=await fetch(foreignUrl,{headers:headers(foreign)});check(response.status===404,'another authenticated visitor cannot download the input');
    response=await fetch(base+inputUrl,{headers:headers(foreign)});check(response.status===409,'stale copied image URL rejects an account change');
    const noNamespace=new URL(base+inputUrl);noNamespace.searchParams.delete('conversation_namespace');response=await fetch(noNamespace,{headers:headers(visitor)});check(response.status===404,'input download requires the exact conversation namespace');
    response=await fetch(base+'/api/profile-agent.php?'+new URLSearchParams({action:'owner_image',conversation_id:cid,message_id:receipt.visitor_message_id,preview:'1'}),{headers:headers(owner)});check(response.status===200&&Buffer.from(await response.arrayBuffer()).equals(png),'the authorized profile owner can review the private visitor photo');
    response=await fetch(base+'/api/profile-agent.php?'+new URLSearchParams({action:'owner_image',conversation_id:cid,message_id:receipt.visitor_message_id,preview:'1'}),{headers:headers(visitor)});check(response.status===404,'visitor cannot use the owner image endpoint');
    const execution=JSON.parse(invoke('dispatch',receipt.handoff.turn_id));check(execution.result.reason==='completed'&&execution.calls===1,'controlled worker executes the real accepted public turn once');
    r=await jsonRequest('GET',{...binding,action:'poll',conversation_id:cid,after_id:receipt.visitor_message_id});
    check(r.data.image_editing.catalogs[0].references.every(item=>new URL(item.preview_url,base).searchParams.get('action')==='reference_image'),'subsequent chat polling retains public reference preview URLs');
    const output=r.data.messages.find(m=>m.image_edit?.kind==='result');check(r.status===200&&output&&r.data.image_edits[0].state==='ready','fresh poll publishes a saved Agent image plus real completed status');
    const credit={contract:'vp3.profile-image-credit.v1',owner_name:'Fixture Owner',catalog_name:'Warm style',references:[{title:'Style 11'},{title:'Style 12'}],price_cents:0};
    check(JSON.stringify(output.image_edit.source_credit)===JSON.stringify(credit),'real public HTTP result contains only frozen owner, collection, exact reference titles and zero price');
    response=await fetch(base+output.image_edit.url,{headers:headers(visitor)});check(response.status===200&&response.headers.get('Content-Type')==='image/png','only the published Agent result receives a working image URL');
    response=await fetch(base+output.image_edit.download_url,{headers:headers(visitor)});check(response.status===200&&response.headers.get('Content-Disposition').startsWith('attachment;'),'published result has a private download action');
    invoke('age');r=await jsonRequest('POST',{...intent,conversation_id:cid,client_request_id:key(),base_message_id:output.id,image_id:12,message:'Make the colors warmer.'});check(r.status===200&&r.data.accepted&&r.data.image_edit.kind==='input'&&!r.data.image_edit.source_credit,'JSON follow-up reuses a published result only in the same conversation without attributing its subject');
    const revision=r.data;JSON.parse(invoke('dispatch',revision.handoff.turn_id));
    r=await jsonRequest('GET',{...binding,action:'poll',conversation_id:cid,after_id:revision.visitor_message_id});
    check(JSON.stringify(r.data.messages.find(m=>m.image_edit?.kind==='result')?.image_edit.source_credit)===JSON.stringify({...credit,references:[{title:'Style 12'}]}),'real HTTP follow-up credits only its selected image instead of the full catalog');
    invoke('rename_credit_sources');const beforeCreditReads=JSON.stringify(state());
    r=await jsonRequest('GET',{...binding,action:'state',conversation_id:cid});
    check(JSON.stringify(r.data.messages.find(m=>m.id===output.id)?.image_edit.source_credit)===JSON.stringify(credit),'public history keeps the original source names after owner, catalog and image renames');
    r=await jsonRequest('GET',{action:'conversation_messages',conversation_id:cid},owner);
    check(r.status===200&&JSON.stringify(r.data.messages.find(m=>m.id===output.id)?.image_edit.source_credit)===JSON.stringify(credit),'authenticated owner HTTP conversation keeps the identical saved source credit');
    check(JSON.stringify(state())===beforeCreditReads,'source credit reads create no extra work, image records or messages');
    invoke('age');r=await jsonRequest('POST',{...intent,client_request_id:key(),base_message_id:output.id});check(r.status===409&&r.data.code==='PROFILE_IMAGE_UNAVAILABLE','reusing a result into a new conversation fails closed');
    invoke('paid');r=await jsonRequest('GET',{...binding,action:'state',conversation_id:cid});check(r.status===200&&!r.data.image_editing.enabled&&r.data.image_editing.catalogs.length===0,'positive-priced references disappear from free choices');
    invoke('age');r=await upload({...intent,conversation_id:cid,client_request_id:key()});check(r.status===409&&r.data.code==='PROFILE_IMAGE_STYLE_CHANGED','positive-priced styles cannot start a free edit or a payment flow');
    check(state().files===0&&state().images.length===2,'AI photo requests never become human-only attachments or extra catalog records');
    invoke('init');invoke('limit_one');const barrier=path.join(tmp,'image-quota-barrier');
    const contenders=[[2,'b'],[3,'c']].map(([id,browser])=>{
        const process=spawn(php,[fixture,'parallel',config,String(id),browser,barrier],{stdio:['ignore','pipe','pipe']});let out='',err='';
        process.stdout.on('data',d=>out+=d);process.stderr.on('data',d=>err+=d);
        return {id,done:new Promise((resolve,reject)=>process.once('exit',code=>code===0?resolve(JSON.parse(out)):reject(Error(err||out))))};
    });
    for(let attempt=0;attempt<400&&!contenders.every(p=>fs.existsSync(barrier+'.'+p.id+'.ready'));attempt++)await new Promise(r=>setTimeout(r,10));
    assert.ok(contenders.every(p=>fs.existsSync(barrier+'.'+p.id+'.ready')),'both independent SQL connections reached admission barrier');fs.writeFileSync(barrier+'.go','go');
    const concurrent=await Promise.all(contenders.map(p=>p.done));
    check(concurrent.filter(r=>r.accepted).length===1&&concurrent.some(r=>r.code==='PROFILE_IMAGE_LIMIT')&&state().images.length===1,'parallel visitors serialize under the owner lock and cannot exceed one remaining daily edit');
    console.log(`PROFILE_IMAGE_EDIT_API_V1=PASS (${checks} cases)`);
}finally{
    child.kill('SIGTERM');await new Promise(resolve=>{if(child.exitCode!==null)resolve();else child.once('exit',resolve);});
    const cleanup=spawnSync(php,[path.resolve('tests/fixtures/profile-handoff-api-pa3-db.php'),'cleanup',config],{encoding:'utf8'});assert.equal(cleanup.status,0,cleanup.stderr||cleanup.stdout);
    fs.rmSync(tmp,{recursive:true,force:true});
}
