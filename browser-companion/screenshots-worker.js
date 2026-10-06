/* Local capture only. No VP3 credentials, publication, or network requests. */
let vp3ScreenshotJob = null;
let vp3ScreenshotLastFrame = 0;
async function vp3ScreenshotThrottle() {
  const delay = Math.max(0, vp3ScreenshotLastFrame + 650 - Date.now());
  if (delay) await new Promise(resolve => setTimeout(resolve, delay));
  vp3ScreenshotLastFrame = Date.now();
}

// Serialized into the page by Chrome. State lives only for one capture and is
// restored in finally, on Escape, or by the page watchdog if the worker exits.
function vp3ScreenshotPage(action, token, position) {
  const key = '__vp3Screenshot_' + token;
  let state = window[key];
  function restore() {
    const saved = window[key];
    if (!saved) return;
    clearTimeout(saved.timer);
    window.removeEventListener('keydown',saved.onKey,true);
    saved.style.remove();
    for (const item of saved.hidden) {
      if(item.value)item.node.style.setProperty('visibility',item.value,item.priority);
      else item.node.style.removeProperty('visibility');
    }
    if(saved.nested)saved.target.scrollTo(saved.x,saved.y);
    else window.scrollTo(saved.x,saved.y);
    delete window[key];
  }
  if(action==='restore'){restore();return true;}
  if(action==='init') {
    const root = document.scrollingElement || document.documentElement;
    const vw=innerWidth,vh=innerHeight;
    let target=root,nested=false;
    if(root.scrollHeight<=vh+2 && root.scrollWidth<=vw+2) {
      let best=0;
      for(const node of Array.from(document.querySelectorAll('main,section,article,div')).slice(0,5000)) {
        if(node.clientWidth<vw*.4||node.clientHeight<vh*.3||node.scrollHeight<=node.clientHeight+8)continue;
        const rect=node.getBoundingClientRect();
        if(rect.top<0||rect.left<0||rect.bottom>vh+2||rect.right>vw+2)continue;
        const overflow=getComputedStyle(node).overflowY;
        if(!['auto','scroll'].includes(overflow))continue;
        const score=node.clientWidth*(node.scrollHeight-node.clientHeight);
        if(score>best){best=score;target=node;nested=true;}
      }
    }
    const rect=nested?target.getBoundingClientRect():null;
    const style=document.createElement('style');style.id='vp3-screenshot-style-'+token;
    style.textContent='*,*::before,*::after{animation-play-state:paused!important;transition:none!important;scroll-behavior:auto!important;scroll-snap-type:none!important}';
    state={target,nested,x:nested?target.scrollLeft:scrollX,y:nested?target.scrollTop:scrollY,style,hidden:[],cancelled:false,
      width:nested?vw:Math.max(vw,root.scrollWidth),height:nested?vh+target.scrollHeight-target.clientHeight:Math.max(vh,root.scrollHeight),
      viewportWidth:vw,viewportHeight:vh,scrollHeight:target.scrollHeight,scrollWidth:target.scrollWidth,
      rect:nested?{left:rect.left+target.clientLeft,top:rect.top+target.clientTop,width:target.clientWidth,height:target.clientHeight}:null,
      background:getComputedStyle(document.body).backgroundColor};
    window[key]=state;document.documentElement.append(style);
    state.onKey=event=>{if(event.key==='Escape'){event.preventDefault();restore();}};
    window.addEventListener('keydown',state.onKey,true);
    state.timer=setTimeout(restore,65000);
  }
  if(!state||!state.target.isConnected)throw Error('Capture cancelled or the page changed.');
  if(action==='scroll') {
    if(position.x||position.y) {
      if(!state.hidden.length)for(const node of Array.from((state.nested?state.target:document).querySelectorAll('*')).slice(0,5000)) {
        if(!['fixed','sticky'].includes(getComputedStyle(node).position))continue;
        state.hidden.push({node,value:node.style.getPropertyValue('visibility'),priority:node.style.getPropertyPriority('visibility')});
        node.style.setProperty('visibility','hidden','important');
      }
    }
    if(state.nested)state.target.scrollTo(position.x,position.y);else window.scrollTo(position.x,position.y);
  }
  return new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>resolve({
    nested:state.nested,width:state.width,height:state.height,viewportWidth:state.viewportWidth,viewportHeight:state.viewportHeight,rect:state.rect,background:state.background,
    x:state.nested?state.target.scrollLeft:scrollX,y:state.nested?state.target.scrollTop:scrollY,
    scrollHeight:state.target.scrollHeight,scrollWidth:state.target.scrollWidth
  }))));
}

async function vp3ScreenshotGuard(job) {
  if(job.cancelled)throw Error(job.cancelled);
  const [active]=await chrome.tabs.query({active:true,windowId:job.tab.windowId});
  if(active?.id!==job.tab.id||active.url!==job.tab.url)throw Error('The active page changed. Return to your webpage and capture again.');
}
async function vp3ScreenshotExecute(job,action,position={x:0,y:0}) {
  const results=await chrome.scripting.executeScript({target:{tabId:job.tab.id},func:vp3ScreenshotPage,args:[action,job.id,position]});
  return results?.[0]?.result;
}
async function vp3ScreenshotFrame(job) {
  await vp3ScreenshotThrottle();await vp3ScreenshotGuard(job);
  const data=await chrome.tabs.captureVisibleTab(job.tab.windowId,{format:'png'});
  await vp3ScreenshotGuard(job);
  return createImageBitmap(await (await fetch(data)).blob());
}
function vp3ScreenshotPositions(length,viewport) {
  const points=[0];
  for(let offset=viewport;offset<length;offset+=viewport)points.push(Math.min(offset,length-viewport));
  return [...new Set(points)];
}
async function vp3ScreenshotFull(job) {
  let canvas;
  try {
    const plan=await vp3ScreenshotExecute(job,'init');
    const xs=plan.nested?[0]:vp3ScreenshotPositions(plan.width,plan.viewportWidth);
    const ys=vp3ScreenshotPositions(plan.nested?plan.scrollHeight:plan.height,plan.nested?plan.rect.height:plan.viewportHeight);
    if(xs.length*ys.length>60||plan.width*plan.height>32000000||plan.width>30000||plan.height>30000)throw Error('This page is too large for one PNG. Capture a region or visible sections.');
    let scaleX,scaleY,context,index=0;
    for(const y of ys)for(const x of xs) {
      await vp3ScreenshotGuard(job);
      const viewport=await vp3ScreenshotExecute(job,'scroll',{x,y});
      if(viewport.scrollHeight!==plan.scrollHeight||viewport.scrollWidth!==plan.scrollWidth)throw Error('The page size changed while capturing. Let it finish loading and try again.');
      job.message=`Capturing entire webpage (${++index}/${xs.length*ys.length})… Keep this tab open. Escape cancels.`;
      const bitmap=await vp3ScreenshotFrame(job);
      try {
        if(!canvas) {
          scaleX=bitmap.width/plan.viewportWidth;scaleY=bitmap.height/plan.viewportHeight;
          const width=Math.round(plan.width*scaleX),height=Math.round(plan.height*scaleY);
          if(width*height>32000000||width>30000||height>30000)throw Error('This page is too large for one PNG. Capture a region or visible sections.');
          canvas=new OffscreenCanvas(width,height);context=canvas.getContext('2d');
          if(!context)throw Error('Chrome could not prepare the PNG.');
          context.fillStyle=plan.background==='rgba(0, 0, 0, 0)'?'#fff':plan.background;context.fillRect(0,0,width,height);
          if(plan.nested) {
            context.drawImage(bitmap,0,0);
            const bottom=Math.round((plan.rect.top+plan.rect.height)*scaleY);
            const extra=Math.round((plan.scrollHeight-plan.rect.height)*scaleY);
            if(bottom<bitmap.height)context.drawImage(bitmap,0,bottom,bitmap.width,bitmap.height-bottom,0,bottom+extra,bitmap.width,bitmap.height-bottom);
          }
        }
        if(plan.nested) {
          const left=Math.round(plan.rect.left*scaleX),top=Math.round(plan.rect.top*scaleY),width=Math.round(plan.rect.width*scaleX),height=Math.round(plan.rect.height*scaleY);
          context.drawImage(bitmap,left,top,width,height,left,Math.round((plan.rect.top+viewport.y)*scaleY),width,height);
        } else context.drawImage(bitmap,Math.round(viewport.x*scaleX),Math.round(viewport.y*scaleY));
      } finally {bitmap.close();}
    }
    const blob=await canvas.convertToBlob({type:'image/png'});
    return {blob,width:canvas.width,height:canvas.height};
  } finally {
    if(canvas){canvas.width=1;canvas.height=1;}
    try {await vp3ScreenshotExecute(job,'restore');}catch(_){}
  }
}
async function vp3LocalScreenshotRun(job) {
  const abort=reason=>{void vp3LocalScreenshotCancel(job.id,reason);};
  const activated=info=>{if(info.windowId===job.tab.windowId&&info.tabId!==job.tab.id)abort('The active page changed. Return to your webpage and capture again.');};
  const updated=(id,info)=>{if(id===job.tab.id&&info.url&&info.url!==job.tab.url)abort('The webpage navigated during capture. Try again.');};
  const removed=id=>{if(id===job.tab.id||id===job.preview)abort('Capture cancelled.');};
  const focus=id=>{if(id>=0&&id!==job.tab.windowId)abort('The active browser window changed. Try again.');};
  chrome.tabs.onActivated.addListener(activated);chrome.tabs.onUpdated.addListener(updated);chrome.tabs.onRemoved.addListener(removed);chrome.windows.onFocusChanged.addListener(focus);
  const timeout=setTimeout(()=>{void vp3LocalScreenshotCancel(job.id,'Capture timed out. Try again.');},65000);
  let ready=false;
  try {
    await new Promise(resolve=>setTimeout(resolve,150));
    await vp3ScreenshotGuard(job);
    let result;
    if(job.mode==='full')result=await vp3ScreenshotFull(job);
    else if(job.mode==='region') {
      job.message='Drag a region on the webpage. Escape cancels.';
      const captured=await selectScreenshotRegion();
      await vp3ScreenshotGuard(job);
      if(captured.cancelled)throw Error('Capture cancelled.');
      const blob=await (await fetch(captured.data_url)).blob();
      result={blob,width:captured.metadata.width,height:captured.metadata.height};
    } else {
      const bitmap=await vp3ScreenshotFrame(job);
      try{const canvas=new OffscreenCanvas(bitmap.width,bitmap.height);canvas.getContext('2d').drawImage(bitmap,0,0);result={blob:await canvas.convertToBlob({type:'image/png'}),width:bitmap.width,height:bitmap.height};canvas.width=1;canvas.height=1;}finally{bitmap.close();}
    }
    await vp3ScreenshotGuard(job);
    if(!result.blob.size||result.blob.size>32*1024*1024)throw Error('The PNG is too large. Capture a smaller region.');
    await VP3ScreenshotStore.save({id:job.id,...result,filename:`VP3-Screenshot-${new Date().toISOString().replace(/[:.]/g,'-')}.png`});
    await vp3ScreenshotGuard(job);
    job.state='ready';job.message='Screenshot ready.';ready=true;
  } catch(error) {
    job.state='error';
    const message=String(error?.message||error);
    job.message=/activeTab|permission|Cannot access/i.test(message)?'Click the VP3 toolbar icon on the webpage and retry capture.':message;
  } finally {
    clearTimeout(timeout);
    chrome.tabs.onActivated.removeListener(activated);chrome.tabs.onUpdated.removeListener(updated);chrome.tabs.onRemoved.removeListener(removed);chrome.windows.onFocusChanged.removeListener(focus);
    if(ready||!job.cancelled)try{await chrome.tabs.update(job.preview,{active:true});}catch(_){}
  }
}
async function vp3LocalScreenshotStart(mode,sender) {
  if(sender?.id!==chrome.runtime.id||sender.url!==chrome.runtime.getURL('popup.html'))throw Error('Start capture from the VP3 toolbar menu.');
  if(!['visible','full','region'].includes(mode))throw Error('Choose a valid capture option.');
  if(vp3ScreenshotJob?.state==='running')throw Error('A capture is already running. Escape cancels it.');
  const [tab]=await chrome.tabs.query({active:true,currentWindow:true});
  if(!tab?.id||!/^https?:\/\//i.test(tab.url||''))throw Error('Open a webpage, then click the VP3 toolbar icon to capture it.');
  if(vp3ScreenshotJob?.state==='running')throw Error('A capture is already running. Escape cancels it.');
  const job={id:crypto.randomUUID(),tab,mode,state:'running',message:'Preparing screenshot…',cancelled:''};
  // Reserve before asynchronous storage/tab calls so two clicks cannot start jobs.
  vp3ScreenshotJob=job;
  try {
    await VP3ScreenshotStore.clear();
    const preview=await chrome.tabs.create({url:chrome.runtime.getURL('screenshot-preview.html')+'?capture='+job.id,active:false,windowId:tab.windowId});
    job.preview=preview.id;
    void vp3LocalScreenshotRun(job);
    return {job_id:job.id};
  } catch(error){job.state='error';job.message=String(error.message);throw error;}
}
function vp3LocalScreenshotStatus(id) {
  if(!vp3ScreenshotJob||vp3ScreenshotJob.id!==id)return {state:'error',message:'This preview expired. Capture again from the toolbar menu.'};
  return {state:vp3ScreenshotJob.state,message:vp3ScreenshotJob.message};
}
async function vp3LocalScreenshotCancel(id,reason='Capture cancelled.') {
  const job=vp3ScreenshotJob;
  if(!job||job.id!==id||job.state!=='running')return false;
  job.cancelled=reason;
  if(job.mode==='region')try{await chrome.scripting.executeScript({target:{tabId:job.tab.id},func:()=>window.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))});}catch(_){}
  return true;
}
