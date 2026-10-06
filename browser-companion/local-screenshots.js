(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const status = byId('localScreenshotStatus');
  const image = byId('localScreenshotImage');
  const cancel = byId('cancelCapture');
  const jobId = new URL(location.href).searchParams.get('capture');
  let screenshot = null, busy = false, closed = false, timer = null;
  function show(text,error=false) {status.textContent=text;status.dataset.error=error?'true':'false';}
  function controls() {for (const id of ['localScreenshotSave','localScreenshotCopy','localScreenshotClear']) byId(id).disabled=busy||!screenshot;}
  function clear() {if(screenshot)URL.revokeObjectURL(screenshot.url);screenshot=null;image.removeAttribute('src');byId('localScreenshotPreview').hidden=true;byId('localScreenshotSize').textContent='';controls();}
  async function poll() {
    if(closed)return;
    try {
      const response=await chrome.runtime.sendMessage({type:'local_screenshot_status',job_id:jobId});
      if(!response?.ok)throw Error(response?.error||'Preview unavailable. Capture again from the toolbar menu.');
      const job=response.value;
      show(job.message||'Capturing…',job.state==='error');
      if(job.state==='running'){timer=setTimeout(poll,500);return;}
      cancel.hidden=true;
      if(job.state!=='ready')return;
      const record=await VP3ScreenshotStore.take(jobId);
      if(!record?.blob?.size)throw Error('The screenshot preview expired or was cleared. Capture again from the toolbar menu.');
      if(closed)return;
      screenshot={blob:record.blob,url:URL.createObjectURL(record.blob),filename:record.filename};
      image.src=screenshot.url;byId('localScreenshotPreview').hidden=false;
      byId('localScreenshotSize').textContent=`${record.width} × ${record.height} px · PNG`;
      show('Screenshot ready. Save the PNG or copy it to paste into chat.');controls();
    } catch(error){cancel.hidden=true;show(String(error?.message||error),true);}
  }
  byId('localScreenshotSave').addEventListener('click',async()=>{
    if(busy||!screenshot)return;busy=true;controls();
    try {await chrome.downloads.download({url:screenshot.url,filename:screenshot.filename,saveAs:true,conflictAction:'uniquify'});show('PNG sent to Chrome’s Save dialog.');}
    catch(error){show(`PNG could not be saved: ${error.message}`,true);}finally{busy=false;controls();}
  });
  byId('localScreenshotCopy').addEventListener('click',async()=>{
    if(busy||!screenshot)return;busy=true;controls();
    try{await navigator.clipboard.write([new ClipboardItem({'image/png':screenshot.blob})]);show('Image copied. Paste into chat with Ctrl+V.');}
    catch(_){show('Chrome could not copy the image. Use Save PNG, then attach the file to chat.',true);}finally{busy=false;controls();}
  });
  byId('localScreenshotClear').addEventListener('click',()=>{if(!busy){clear();show('Screenshot cleared.');}});
  cancel.addEventListener('click',async()=>{cancel.disabled=true;await chrome.runtime.sendMessage({type:'local_screenshot_cancel',job_id:jobId}).catch(()=>{});});
  window.addEventListener('pagehide',()=>{closed=true;clearTimeout(timer);clear();});
  if(jobId)void poll();else{cancel.hidden=true;show('Choose a capture option from the VP3 toolbar menu.');}
})();
