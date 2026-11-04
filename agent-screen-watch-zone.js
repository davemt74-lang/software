/* Watches pixels inside a user-drawn region of an active screen share. */
(() => {
  'use strict';
  const sampleInterval=500,minimumCaptureInterval=5000;

  function changeFraction(a,b){
    let changed=0;
    for(let i=0;i<a.length;i+=4){
      if(Math.max(Math.abs(a[i]-b[i]),Math.abs(a[i+1]-b[i+1]),Math.abs(a[i+2]-b[i+2]))>24)changed++;
    }
    return changed/(a.length/4);
  }

  function mount({preview,video,canWatch,save,onChange}){
    const area=document.createElement('div');area.className='vp3-screen-watch-preview';
    const box=document.createElement('div');box.className='vp3-screen-watch-box';box.hidden=true;box.setAttribute('aria-hidden','true');
    preview.before(area);area.append(preview,box);
    const tools=document.createElement('div');tools.className='vp3-screen-watch-tools';
    tools.innerHTML='<button type="button" data-screen-watch-draw disabled>Draw watch zone</button><button type="button" data-screen-watch-clear disabled>Clear watch zone</button><p data-screen-watch-status role="status">A watch zone saves a crop when its pixels change.</p>';
    area.after(tools);
    const draw=tools.querySelector('[data-screen-watch-draw]'),clear=tools.querySelector('[data-screen-watch-clear]');
    const status=tools.querySelector('[data-screen-watch-status]');
    const sample=document.createElement('canvas');sample.width=48;sample.height=24;
    const sampleContext=sample.getContext('2d',{willReadFrequently:true});
    let zone=null,drag=null,drawing=false,active=false,timer=null,request=null,pending=null,epoch=0,lastCapture=-Infinity;
    let baseline=null,candidate=null,dimensions='';

    function paint(region){
      box.hidden=!region;
      if(region){
        box.style.left=100*region.x+'%';box.style.top=100*region.y+'%';
        box.style.width=100*region.width+'%';box.style.height=100*region.height+'%';
      }
    }
    function sampleReset(){baseline=candidate=null;dimensions='';}
    function setActive(value){
      if(active===value)return;
      active=value;onChange?.();
    }
    function stopSampling(){
      epoch++;clearTimeout(timer);timer=null;
      request?.abort();request=pending=null;sampleReset();
      setActive(false);
    }
    function endDrawing(){
      const pointerId=drag?.pointerId;
      drag=null;drawing=false;delete area.dataset.watchDrawing;
      if(pointerId!==undefined&&area.hasPointerCapture(pointerId))area.releasePointerCapture(pointerId);
      draw.textContent=zone?'Redraw watch zone':'Draw watch zone';
      paint(zone);
    }
    function point(event){
      const bounds=preview.getBoundingClientRect();
      const limit=n=>Math.min(1,Math.max(0,n));
      return {x:limit((event.clientX-bounds.left)/bounds.width),y:limit((event.clientY-bounds.top)/bounds.height)};
    }
    function rectangle(a,b){
      return {x:Math.min(a.x,b.x),y:Math.min(a.y,b.y),width:Math.abs(a.x-b.x),height:Math.abs(a.y-b.y)};
    }
    function pixels(){
      const width=video.videoWidth,height=video.videoHeight;
      const x=Math.floor(width*zone.x),y=Math.floor(height*zone.y);
      return [x,y,Math.min(width,Math.ceil(width*(zone.x+zone.width)))-x,Math.min(height,Math.ceil(height*(zone.y+zone.height)))-y];
    }
    function crop(source){
      const [, ,width,height]=source,scale=Math.min(1,1280/Math.max(width,height));
      const w=Math.max(1,Math.round(width*scale)),h=Math.max(1,Math.round(height*scale));
      const canvas=document.createElement('canvas');canvas.width=Math.max(160,w);canvas.height=Math.max(90,h);
      const ctx=canvas.getContext('2d');ctx.fillStyle='#000';ctx.fillRect(0,0,canvas.width,canvas.height);
      ctx.drawImage(video,...source,Math.floor((canvas.width-w)/2),Math.floor((canvas.height-h)/2),w,h);
      const image=canvas.toDataURL('image/jpeg',.7);
      if(image.length>420000)throw Error('Watched crop is too large. Draw a smaller zone.');
      return image;
    }
    function savePending(){
      const own=new AbortController(),version=epoch;
      const deadline=setTimeout(()=>own.abort(),12000);
      request=own;lastCapture=performance.now();pending.attempts++;
      void save(pending.frame,own.signal).then(()=>{
        if(version!==epoch)return;
        pending=null;status.textContent='Watched zone change saved to My Knowledge.';
      }).catch(error=>{
        if(version!==epoch)return;
        if(pending.attempts===1&&(!error.status||error.status===429||error.status>=500)){
          status.textContent='Watched zone save could not be confirmed. Retrying once.';
          return;
        }
        pending=null;status.textContent='Watched zone save could not be confirmed. '+(error.message||'');
        if([401,403,409,419,422].includes(error.status))reset(false);
      }).finally(()=>{
        clearTimeout(deadline);
        if(request===own)request=null;
      });
    }
    function tick(){
      timer=null;
      if(!canWatch()||drawing||!zone){setActive(false);return;}
      if(pending){
        if(!request&&performance.now()-lastCapture>=minimumCaptureInterval)savePending();
      }else if(video.readyState>=2&&video.videoWidth&&video.videoHeight){
        try{
          const size=video.videoWidth+'×'+video.videoHeight;
          if(dimensions!==size){sampleReset();dimensions=size;}
          const source=pixels();
          sampleContext.drawImage(video,...source,0,0,sample.width,sample.height);
          const current=sampleContext.getImageData(0,0,sample.width,sample.height).data;
          if(!baseline)baseline=current;
          if(changeFraction(baseline,current)<.035)candidate=null;
          else if(!candidate||changeFraction(candidate,current)>.02)candidate=current;
          else if(performance.now()-lastCapture>=minimumCaptureInterval){
            const frame={image:crop(source),watchRegion:zone,captureId:crypto.randomUUID(),captured_at:new Date().toISOString()};
            pending={frame,attempts:0};baseline=current;candidate=null;savePending();
          }
        }catch(error){status.textContent=error.message||'Watched zone pixels could not be read.';reset(false);return;}
      }else sampleReset();
      timer=setTimeout(tick,sampleInterval);
    }
    function sync(){
      const ready=canWatch()&&!preview.hidden;
      draw.disabled=!ready;clear.disabled=!zone;
      if(ready&&zone&&!drawing&&timer===null){
        if(!active)status.textContent='Watching the selected zone for stable pixel changes.';
        setActive(true);tick();
      }
    }
    function pause(){
      stopSampling();endDrawing();draw.disabled=true;
      if(zone)status.textContent='Watch zone paused.';
    }
    function reset(announce=true){
      stopSampling();zone=null;endDrawing();lastCapture=-Infinity;
      if(announce)status.textContent='A watch zone saves a crop when its pixels change.';
      sync();
    }
    draw.addEventListener('click',()=>{
      if(drawing){endDrawing();status.textContent=zone?'Watching the selected zone.':'Select a zone to start watching.';sync();return;}
      if(!canWatch()||preview.hidden)return;
      stopSampling();drawing=true;area.dataset.watchDrawing='';draw.textContent='Cancel drawing';
      status.textContent='Drag a rectangle on the preview.';
    });
    clear.addEventListener('click',()=>reset());
    area.addEventListener('pointerdown',event=>{
      if(!drawing||drag||event.button!==0||!canWatch())return;
      event.preventDefault();drag={start:point(event),pointerId:event.pointerId};area.setPointerCapture(event.pointerId);
      paint(rectangle(drag.start,drag.start));
    });
    area.addEventListener('pointermove',event=>{if(drag?.pointerId===event.pointerId)paint(rectangle(drag.start,point(event)));});
    area.addEventListener('pointerup',event=>{
      if(drag?.pointerId!==event.pointerId)return;
      const region=rectangle(drag.start,point(event));
      if(region.width>=.02&&region.height>=.02){zone=region;status.textContent='Watching the selected zone for stable pixel changes.';}
      else status.textContent='Draw a larger rectangle to watch it.';
      endDrawing();sync();
    });
    const cancel=event=>{if(drag?.pointerId===event.pointerId){endDrawing();sync();}};
    area.addEventListener('pointercancel',cancel);
    area.addEventListener('lostpointercapture',cancel);
    return {sync,pause,reset,active:()=>active};
  }

  window.VP3ScreenWatchZone=Object.freeze({mount});
})();
