import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const original=await fs.readFile(new URL('../chat-homeserver-transcription-import.js',import.meta.url),'utf8');
const source=original.replace(/\}\)\(\);\s*$/,'window.ImportTest={open,close,request,state:()=>({generation,busy,overlay})};\n})();');
const workspace=await fs.readFile(new URL('../artist-listening-workspace.js',import.meta.url),'utf8');
const workspaceSource=workspace.slice(0,workspace.lastIndexOf('\n  buildWorkspace();'))+'\nwindow.ClipTest={deleteRetainedRecording,renderRecordings,state};\n})();';
assert.notEqual(original,source);
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
let passed=0;
async function test(name,run){
    const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    try{
        await page.setContent('<div id="chatTranscriptionCanvas"><header><button data-transcription-close>Close</button></header></div>');
        await page.evaluate(()=>{
            window.defer=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
            window.tick=()=>new Promise(resolve=>setTimeout(resolve,0));
            window.check=(value,label)=>{if(!value)throw Error(label);};
            window.reply=data=>({ok:true,json:async()=>({ok:true,...data})});
            window.calls=[];window.opened=[];
            window.STONEFELLOW_TRANSCRIPTION_CANVAS={open:async data=>opened.push(data.sessionId)};
            window.fetch=async(url,init)=>{calls.push(init);return reply({sessions:[{id:'a'.repeat(32),title:'Shared A',segment_count:2}]});};
        });
        await page.evaluate(code=>{
            window.loadClips=()=>{
                window.STONEFELLOW_ARTIST_LISTENING_V172={endpoint:'https://example.test/api/artist-listening-v172.php',userId:1,csrf:'fixture'};
                const script=document.createElement('script');script.textContent=code;document.body.appendChild(script);
                const root=document.createElement('div');root.innerHTML='<div data-listening-workspace-recordings></div><div data-listening-workspace-footer-message></div>';document.body.appendChild(root);
                ClipTest.state.workspace=root;ClipTest.state.current={id:42,status:'draft',recordings:[{key:'c'.repeat(32),url:'/private-audio',duration_ms:100}]};ClipTest.renderRecordings(ClipTest.state.current);
                return root;
            };
        },workspaceSource);
        await page.addScriptTag({content:source});await page.evaluate(run);assert.deepEqual(errors,[]);passed++;console.log('PASS '+name);
    }finally{await page.close();}
}
try{
    await test('late shared lists cannot replace reopened dialog',async()=>{
        const requests=[];window.fetch=async(_url,init)=>{const d=defer();requests.push({d,signal:init.signal});return d.promise;};
        const first=ImportTest.open(),second=ImportTest.open();check(requests[0].signal.aborted,'old read not aborted');
        requests[1].d.resolve(reply({sessions:[{id:'b'.repeat(32),title:'Newest',segment_count:1}]}));await second;
        requests[0].d.resolve(reply({sessions:[{id:'a'.repeat(32),title:'Old',segment_count:1}]}));await first;
        check(document.getElementById('hsCloudImportList').textContent.includes('Newest')&&!document.getElementById('hsCloudImportList').textContent.includes('Old'),'stale list');
    });
    await test('closing a list aborts it and suppresses late errors',async()=>{
        const d=defer();let signal;window.fetch=async(_url,init)=>{signal=init.signal;return d.promise;};const work=ImportTest.open();ImportTest.close();
        d.reject(Error('late network error'));await work;check(signal.aborted&&ImportTest.state().overlay.hidden,'not closed');check(!document.getElementById('hsCloudImportStatus').textContent.includes('late'),'late error');
    });
    await test('closing a write cannot navigate when the acknowledgement arrives',async()=>{
        await ImportTest.open();const d=defer();window.fetch=async()=>d.promise;
        document.querySelector('#hsCloudImportList button').click();await tick();ImportTest.close();d.resolve(reply({cloud_session_id:42,imported:true}));await tick();await tick();
        check(!opened.length&&ImportTest.state().overlay.hidden&&!ImportTest.state().busy,'late navigation or busy');
    });
    await test('only one import runs even when clicked twice',async()=>{
        await ImportTest.open();const d=defer();let count=0;window.fetch=async()=>{count++;return d.promise;};
        const button=document.querySelector('#hsCloudImportList button');button.click();button.click();await tick();check(count===1,'duplicate POST');
        d.resolve(reply({cloud_session_id:42,imported:true}));await tick();await tick();check(opened.join(',')==='42'&&!ImportTest.state().busy,'success navigation');
    });
    await test('request timeout bounds fetch even when abort is ignored',async()=>{
        let timeout;const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>ms===20000?(timeout=fn,123456):original(fn,ms,...args);
        window.fetch=()=>new Promise(()=>{});const work=ImportTest.request().then(()=>false,e=>e.message);timeout();check((await work).includes('timed out'),'unbounded fetch');
    });
    await test('deadline includes response decoding',async()=>{
        let timeout;const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>ms===20000?(timeout=fn,123456):original(fn,ms,...args);
        window.fetch=async()=>({ok:true,json:()=>new Promise(()=>{})});const work=ImportTest.request().then(()=>false,e=>e.message);await Promise.resolve();timeout();check((await work).includes('timed out'),'unbounded JSON decode');
    });
    await test('pagehide aborts pending imports and ignores their later result',async()=>{
        await ImportTest.open();const d=defer();let signal;window.fetch=async(_url,init)=>{signal=init.signal;return d.promise;};document.querySelector('#hsCloudImportList button').click();await tick();
        window.dispatchEvent(new Event('pagehide'));check(signal.aborted,'pending write not aborted');d.resolve(reply({cloud_session_id:42}));await tick();await tick();check(!opened.length,'unload navigation');
    });
    await test('shared titles render as text without executable markup',async()=>{
        window.fetch=async()=>reply({sessions:[{id:'a'.repeat(32),title:'<img src=x onerror=alert(1)>',segment_count:1}]});await ImportTest.open();check(!document.querySelector('#hsCloudImportList img'),'unsafe title');
    });
    await test('a failed request permits an explicit retry of the original source',async()=>{
        await ImportTest.open();const ids=[];window.fetch=async(_url,init)=>{ids.push(JSON.parse(init.body).session_id);if(ids.length===1)throw Error('lost response');return reply({cloud_session_id:42,already_imported:true});};
        document.querySelector('#hsCloudImportList button').click();await tick();await tick();check(!ImportTest.state().busy,'failed write left busy');document.querySelector('#hsCloudImportList button').click();await tick();await tick();check(ids.length===2&&ids[0]===ids[1]&&opened[0]===42,'retry retargeted source');
    });
    await test('deleting a retained clip requires explicit owner confirmation',async()=>{
        let writes=0;window.fetch=async()=>{writes++;return reply({});};const root=loadClips();window.confirm=()=>false;
        check(root.querySelector('[data-listening-workspace-delete-recording]')?.textContent==='Delete clip','missing removal control');
        await ClipTest.deleteRetainedRecording('c'.repeat(32));check(!writes&&ClipTest.state.current.recordings.length===1,'deletion without confirmation');
    });
    await test('retained audio deletion keeps the transcript and original document identity',async()=>{
        let body;window.fetch=async(_url,init)=>{body=JSON.parse(init.body);return reply({session:{id:42,recordings:[]}});};const root=loadClips();window.confirm=()=>true;
        await ClipTest.deleteRetainedRecording('c'.repeat(32));check(body.action==='delete_recording'&&body.session_id===42&&body.recording_key==='c'.repeat(32),'wrong mutation');
        check(ClipTest.state.current.id===42&&!root.querySelector('audio'),'clip still shown');
    });
    await test('a late clip deletion cannot repaint another transcript',async()=>{
        const pending=defer();window.fetch=async()=>pending.promise;loadClips();window.confirm=()=>true;
        const work=ClipTest.deleteRetainedRecording('c'.repeat(32));await tick();ClipTest.state.selectionEpoch++;ClipTest.state.current={id:99,recordings:[{key:'new clip'}]};
        pending.resolve(reply({session:{id:42,recordings:[]}}));await work;check(ClipTest.state.current.id===99&&ClipTest.state.current.recordings.length===1,'late delete replaced current doc');
    });
}finally{await browser.close();}
console.log(`TRANSFER_SECTION7=PASS (${passed} real Chromium cases)`);
