(() => {
  'use strict';
  const boot=()=>{
    const root=document.querySelector('.vp3-orb-root'),adapter=window.VP3_ORB_ADAPTER;
    if(!root||!adapter||root.dataset.motionInstalled)return;
    root.dataset.motionInstalled='true';
    const key=`vp3:orb-motion:${adapter.ownerKey}`,reduced=matchMedia('(prefers-reduced-motion: reduce)');
    let enabled=true,disposed=false,frame=0,last=0,target=null,restUntil=performance.now()+2500,pointer=null;
    try{enabled=localStorage.getItem(key)!=='off';}catch(_){}
    const button=document.createElement('button');button.type='button';button.dataset.orbMotion='';button.textContent='Float';
    button.setAttribute('aria-label','Float agent around screen');
    root.querySelector('.vp3-orb-controls').append(button);
    const sync=()=>{button.setAttribute('aria-pressed',String(enabled&&!reduced.matches));button.disabled=reduced.matches;root.dataset.floating=String(enabled&&!reduced.matches);};
    button.addEventListener('click',()=>{enabled=!enabled;try{localStorage.setItem(key,enabled?'on':'off');}catch(_){}target=null;sync();});
    const pause=()=>{restUntil=performance.now()+90000;target=null;};
    root.addEventListener('pointerdown',pause);root.addEventListener('focusin',pause);
    const point=event=>{pointer={x:event.clientX,y:event.clientY};};
    document.addEventListener('pointermove',point,{passive:true});
    const outside=()=>{pointer=null;};document.addEventListener('pointerleave',outside);
    const overlays=()=>Array.from(document.querySelectorAll('.vp3-eyes-panel:not([hidden]),.vp3-orb-panel:not([hidden]),#chatProfileDropdown:not([hidden]),.chat-notification-drawer:not([hidden]),[role="dialog"]:not([hidden])')).filter(node=>node.getClientRects().length).map(node=>node.getBoundingClientRect());
    const intersects=(x,y,r,o)=>x+r.width>o.left-16&&x<o.right+16&&y+r.height>o.top-16&&y<o.bottom+16;
    const tick=now=>{
      if(disposed)return;
      const dt=Math.min(.05,(now-(last||now))/1000);last=now;
      const r=root.getBoundingClientRect(),near=pointer&&pointer.x>r.left-100&&pointer.x<r.right+100&&pointer.y>r.top-100&&pointer.y<r.bottom+100;
      const paused=!enabled||reduced.matches||document.hidden||now<restUntil||near||root.matches(':hover,:focus-within')||root.dataset.following==='true'||root.dataset.popupOpen==='true'||root.dataset.small==='true'||root.dataset.recording==='true';
      if(!paused){
        const margin=14,minY=74,maxX=Math.max(margin,innerWidth-r.width-margin);
        let maxY=innerHeight-r.height-96;
        const composer=document.querySelector('#chatForm,.vp3-orb-bar')?.getBoundingClientRect();
        if(composer&&composer.top>minY&&composer.top<innerHeight)maxY=Math.min(maxY,composer.top-r.height-18);
        maxY=Math.max(minY,maxY);
        const obstacles=overlays();
        if(!target||target.x>maxX||target.y>maxY){
          const candidates=[{x:margin,y:minY},{x:maxX,y:minY},{x:margin,y:maxY},{x:maxX,y:maxY},{x:maxX,y:(minY+maxY)/2},{x:margin,y:(minY+maxY)/2}];
          const choices=candidates.filter(p=>Math.hypot(p.x-r.left,p.y-r.top)>50&&!obstacles.some(o=>intersects(p.x,p.y,r,o)));
          target=choices[Math.floor(Math.random()*choices.length)]||null;
        }
        if(target){
          const dx=target.x-r.left,dy=target.y-r.top,distance=Math.hypot(dx,dy),step=Math.min(distance,32*dt);
          const x=Math.max(margin,Math.min(maxX,r.left+(distance?dx/distance*step:0))),y=Math.max(minY,Math.min(maxY,r.top+(distance?dy/distance*step:0)));
          if(obstacles.some(o=>intersects(x,y,r,o))){target=null;restUntil=now+3000;}
          else {root.style.left=`${x}px`;root.style.top=`${y}px`;root.style.right='auto';root.style.bottom='auto';if(distance<2){target=null;restUntil=now+6000;}}
        }
      }
      frame=requestAnimationFrame(tick);
    };
    sync();reduced.addEventListener('change',sync);frame=requestAnimationFrame(tick);
    window.addEventListener('pagehide',()=>{disposed=true;cancelAnimationFrame(frame);document.removeEventListener('pointermove',point);document.removeEventListener('pointerleave',outside);reduced.removeEventListener('change',sync);},{once:true});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
