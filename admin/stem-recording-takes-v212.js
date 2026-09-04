(function(root,factory){
  'use strict';
  const api=factory();
  root.StonefellowStemRecordingTakesV212=api;
  if(root.document)api.install(root,root.document);
})(typeof globalThis!=='undefined'?globalThis:window,function(){
  'use strict';

  const BUILD='stem-recording-takes-v212-20260901';
  const TAKE_RE=/\s+Take\s+(\d+)\s*$/i;
  const clone=value=>JSON.parse(JSON.stringify(value));
  const num=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;

  function takeNumber(name){
    const match=String(name||'').match(TAKE_RE);
    return match?Math.max(0,Number(match[1])||0):0;
  }

  function baseTakeName(name){
    const clean=String(name||'').trim();
    return clean.replace(TAKE_RE,'').trim()||clean||'Track';
  }

  function buildTakeFamilies(stems,takeOf){
    const rows=(Array.isArray(stems)?stems:[])
      .map((stem,index)=>({id:Number(stem?.id||0),name:String(stem?.name||stem?.label||`Track ${index+1}`),index,stem}))
      .filter(row=>row.id>0);
    const byId=new Map(rows.map(row=>[row.id,row]));
    const groups=new Map();
    rows.forEach(row=>{
      const parent=Math.max(0,Number(typeof takeOf==='function'?takeOf(row.id,row.stem):row.stem?.takeOfStemId)||0);
      if(parent>0){
        const rootId=parent;
        if(!groups.has(rootId))groups.set(rootId,[]);
        groups.get(rootId).push({...row,parentId:rootId,isParent:false,takeNumber:takeNumber(row.name)});
      }
    });
    for(const [parentId,members] of groups){
      const parent=byId.get(parentId);
      if(parent)members.unshift({...parent,parentId,isParent:true,takeNumber:0});
      members.sort((a,b)=>{
        if(a.isParent!==b.isParent)return a.isParent?-1:1;
        const an=a.takeNumber||Number.MAX_SAFE_INTEGER;
        const bn=b.takeNumber||Number.MAX_SAFE_INTEGER;
        return an-bn||a.index-b.index||a.id-b.id;
      });
    }
    return groups;
  }

  function familyForStem(families,stemId){
    const id=Number(stemId||0);
    for(const [parentId,members] of families||[]){
      if(parentId===id||members.some(member=>member.id===id))return {parentId,members};
    }
    return null;
  }

  function nextFamilyMember(family,currentId,direction=1){
    const members=family?.members||[];
    if(!members.length)return null;
    const found=members.findIndex(member=>member.id===Number(currentId||0));
    const current=found>=0?found:0;
    const offset=direction<0?-1:1;
    return members[(current+offset+members.length)%members.length]||members[0];
  }

  function ratingValue(value){return Math.max(0,Math.min(5,Math.round(num(value,0))));}

  function install(root,document){
    if(!document||root.__STONEFELLOW_STEM_V212_INSTALLED__)return false;
    root.__STONEFELLOW_STEM_V212_INSTALLED__=true;

    const cfg=root.STONEFELLOW_STEM_STUDIO||{};
    const storageKey=`stonefellow:stem:v212:${Number(cfg.userId||0)}:${Number(cfg.trackId||0)}`;
    let bindAttempts=0;
    let observer=null;
    let refreshQueued=false;
    let toolbar=null;
    let activeStemId=0;
    let audition=null;
    let settings={collapsed:{},ratings:{},countIn:0};

    try{
      const saved=JSON.parse(root.localStorage?.getItem(storageKey)||'null');
      if(saved&&typeof saved==='object')settings={...settings,...saved};
    }catch(error){}
    settings.collapsed=settings.collapsed&&typeof settings.collapsed==='object'?settings.collapsed:{};
    settings.ratings=settings.ratings&&typeof settings.ratings==='object'?settings.ratings:{};
    settings.countIn=[0,1,2,4].includes(Number(settings.countIn))?Number(settings.countIn):0;

    const studio=()=>root.StonefellowStemStudioV91||null;
    const core=()=>root.STONEFELLOW_STUDIO_RUNTIME_V87||null;
    const v209=()=>root.StonefellowStemEditingV209Runtime||null;
    const v210=()=>root.StonefellowStemProfessionalEditingV210Runtime||null;
    const agent=()=>{try{return studio()?.getAgentState?.()||{};}catch(error){return{};}};
    const stems=()=>Array.isArray(agent().stems)?agent().stems:[];
    const takeOf=id=>Number(core()?.getStem?.(Number(id||0))?.takeOfStemId||0);
    const families=()=>buildTakeFamilies(stems(),takeOf);
    const clips=()=>Array.isArray(agent().clips)?agent().clips:[];

    function persist(){try{root.localStorage?.setItem(storageKey,JSON.stringify(settings));}catch(error){}}
    function setText(node,value){const text=String(value??'');if(node&&node.textContent!==text)node.textContent=text;}
    function clipForStem(stemId){return clips().find(clip=>clip.kind==='stem'&&Number(clip.stem_id)===Number(stemId))||null;}
    function stemForClip(clipId){return clips().find(clip=>String(clip.id)===String(clipId||''))||null;}
    function selectedStemId(){
      const selection=v209()?.getSelection?.()||[];
      for(const id of selection){const clip=stemForClip(id);if(clip?.stem_id)return Number(clip.stem_id);}
      return Number(core()?.getSelectedStemId?.()||activeStemId||agent().selected_id||0);
    }
    function currentFamily(){return familyForStem(families(),selectedStemId());}
    function memberLabel(member,index,total){
      const rating=ratingValue(settings.ratings[String(member.id)]||0);
      const stars=rating?` · ${'★'.repeat(rating)}`:'';
      return `${member.isParent?'MAIN':`TAKE ${member.takeNumber||index}`} · ${member.name}${stars} · ${index+1}/${total}`;
    }

    function moveAfter(reference,node){
      if(!reference?.parentNode||!node||reference===node)return reference;
      if(reference.nextSibling!==node)reference.parentNode.insertBefore(node,reference.nextSibling);
      return node;
    }

    function orderFamily(family){
      if(!family?.members?.length)return;
      for(const key of ['leftRow','arrangeRow','mixer']){
        const parentStem=core()?.getStem?.(family.parentId);
        let anchor=parentStem?.[key];
        if(!anchor)continue;
        family.members.filter(member=>!member.isParent).forEach(member=>{
          const node=core()?.getStem?.(member.id)?.[key];
          if(node)anchor=moveAfter(anchor,node);
        });
      }
    }

    function decorateFamily(family){
      if(!family?.members?.length)return;
      orderFamily(family);
      const collapsed=settings.collapsed[String(family.parentId)]===true;
      family.members.forEach((member,index)=>{
        const stem=core()?.getStem?.(member.id);
        if(!stem)return;
        const parts=[stem.leftRow,stem.arrangeRow,stem.mixer].filter(Boolean);
        parts.forEach(node=>{
          node.dataset.v212TakeParent=String(family.parentId);
          node.dataset.v212TakeIndex=String(index);
          node.classList.toggle('sf-v212-take-child',!member.isParent);
          node.classList.toggle('sf-v212-take-parent',member.isParent);
          if(!member.isParent)node.hidden=collapsed;
        });
        const target=stem.leftRow?.querySelector('.daw-track-select')||stem.leftRow;
        if(target){
          let badge=target.querySelector(':scope > [data-v212-take-badge]');
          if(!badge){
            badge=document.createElement('span');
            badge.className='sf-v212-take-badge';
            badge.dataset.v212TakeBadge=String(member.id);
            target.appendChild(badge);
          }
          setText(badge,member.isParent?`TAKES ${family.members.length}`:`T${member.takeNumber||index}`);
          const title=memberLabel(member,index,family.members.length);
          if(badge.title!==title)badge.title=title;
        }
      });
      core()?.getStem?.(family.parentId)?.leftRow?.classList.toggle('sf-v212-family-collapsed',collapsed);
    }

    function clearStaleDecorations(validParents){
      document.querySelectorAll('[data-v212-take-parent]').forEach(node=>{
        if(validParents.has(Number(node.dataset.v212TakeParent||0)))return;
        node.hidden=false;
        node.classList.remove('sf-v212-take-child','sf-v212-take-parent','sf-v212-family-collapsed');
        delete node.dataset.v212TakeParent;
        delete node.dataset.v212TakeIndex;
        node.querySelector?.('[data-v212-take-badge]')?.remove();
      });
    }

    async function setFamilyMuteState(family,chosenId){
      if(!family)return false;
      const snapshot=[];
      const state=agent();
      for(const member of family.members){
        const row=state.stems?.find(stem=>Number(stem.id)===member.id);
        snapshot.push({id:member.id,muted:Boolean(row?.muted),solo:Boolean(row?.solo)});
      }
      for(const member of family.members){
        const shouldMute=member.id!==Number(chosenId);
        await studio()?.executeAgentCommand?.({type:shouldMute?'mute':'unmute',stem_id:member.id});
        await studio()?.executeAgentCommand?.({type:'unsolo',stem_id:member.id});
      }
      audition={parentId:family.parentId,chosenId:Number(chosenId),snapshot};
      return true;
    }

    async function restoreAudition(){
      if(!audition)return false;
      const snapshot=audition.snapshot.slice();
      audition=null;
      for(const row of snapshot){
        await studio()?.executeAgentCommand?.({type:row.muted?'mute':'unmute',stem_id:row.id});
        await studio()?.executeAgentCommand?.({type:row.solo?'solo':'unsolo',stem_id:row.id});
      }
      refresh();
      return true;
    }

    async function toggleAudition(){
      const family=currentFamily();
      const chosen=selectedStemId();
      if(!family)return false;
      if(audition){await restoreAudition();return false;}
      await setFamilyMuteState(family,chosen);
      refresh();
      return true;
    }

    function selectTake(stemId){
      const clip=clipForStem(stemId);
      if(!clip)return false;
      activeStemId=Number(stemId);
      v209()?.select?.([String(clip.id)]);
      const escaped=String(clip.id).replace(/["\\]/g,'\\$&');
      const el=document.querySelector(`[data-main-clip-id="${escaped}"]`);
      try{el?.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));}catch(error){}
      el?.scrollIntoView?.({block:'nearest',inline:'nearest',behavior:'smooth'});
      refresh();
      return true;
    }

    function stepTake(direction){
      const family=currentFamily();
      if(!family)return false;
      const member=nextFamilyMember(family,selectedStemId(),direction);
      return member?selectTake(member.id):false;
    }

    async function compCurrent(){
      const family=currentFamily();
      if(!family)return false;
      const chosen=family.members.find(member=>member.id===selectedStemId());
      if(!chosen||chosen.isParent)return false;
      const clip=clipForStem(chosen.id);
      if(!clip)return false;
      if(audition)await restoreAudition();
      const ok=await v210()?.compSelectedTake?.(String(clip.id));
      refresh();
      return Boolean(ok);
    }

    function toggleCollapsed(parentId){
      const key=String(Number(parentId)||0);
      settings.collapsed[key]=settings.collapsed[key]!==true;
      persist();
      refresh();
      return settings.collapsed[key];
    }

    function setRating(stemId,value){
      settings.ratings[String(Number(stemId)||0)]=ratingValue(value);
      persist();
      refresh();
      return settings.ratings[String(Number(stemId)||0)];
    }

    function syncCountIn(value){
      const next=[0,1,2,4].includes(Number(value))?Number(value):0;
      settings.countIn=next;
      persist();
      for(const id of ['recordCountInBars','studioMetronomeCountIn']){
        const control=document.getElementById(id);
        if(!control)continue;
        if(control.value!==String(next))control.value=String(next);
        control.dispatchEvent(new Event('change',{bubbles:true}));
      }
      refresh();
      return next;
    }

    async function punchFromRange(){
      const range=v210()?.getRange?.();
      if(!range||!(Number(range.end)>Number(range.start))){showStatus('Select a time range first.');return false;}
      const result=await studio()?.executeAgentCommand?.({type:'loop_set',start:Number(range.start),end:Number(range.end)});
      if(result?.status!=='success'){showStatus('Could not set loop range.');return false;}
      const punch=document.getElementById('recordPunchFromLoop');
      if(!punch){showStatus('Punch control unavailable.');return false;}
      punch.click();
      showStatus(`Punch ${Number(range.start).toFixed(2)}–${Number(range.end).toFixed(2)}s ready.`);
      refresh();
      return true;
    }

    function showStatus(message){
      let node=document.getElementById('stemV212Status');
      if(!node){
        node=document.createElement('div');
        node.id='stemV212Status';
        node.className='sf-v212-status';
        document.body.appendChild(node);
      }
      setText(node,message);
      node.classList.add('show');
      root.clearTimeout(node._timer);
      node._timer=root.setTimeout(()=>node.classList.remove('show'),1800);
    }

    function buildToolbar(){
      const host=document.querySelector('.daw-mixer-toolbar');
      if(!host)return false;
      if(host.querySelector('[data-v212-toolbar]')){
        toolbar=host.querySelector('[data-v212-toolbar]');
        return true;
      }
      toolbar=document.createElement('div');
      toolbar.className='sf-v212-toolbar';
      toolbar.dataset.v212Toolbar=BUILD;
      toolbar.innerHTML=`<span class="sf-v212-title">TAKES</span><button type="button" data-v212-prev title="Previous take">‹ TAKE</button><strong data-v212-current>NO TAKE STACK</strong><button type="button" data-v212-next title="Next take">TAKE ›</button><button type="button" data-v212-audition>AUDITION</button><button type="button" data-v212-comp>COMP RANGE</button><button type="button" data-v212-collapse>HIDE TAKES</button><label>RATE <select data-v212-rating aria-label="Take rating"><option value="0">—</option><option value="1">★</option><option value="2">★★</option><option value="3">★★★</option><option value="4">★★★★</option><option value="5">★★★★★</option></select></label><button type="button" data-v212-punch>RANGE → PUNCH</button><label>COUNT <select data-v212-count aria-label="Recording count-in bars"><option value="0">0</option><option value="1">1 BAR</option><option value="2">2 BARS</option><option value="4">4 BARS</option></select></label>`;
      host.appendChild(toolbar);
      toolbar.querySelector('[data-v212-prev]').addEventListener('click',()=>stepTake(-1));
      toolbar.querySelector('[data-v212-next]').addEventListener('click',()=>stepTake(1));
      toolbar.querySelector('[data-v212-audition]').addEventListener('click',()=>void toggleAudition());
      toolbar.querySelector('[data-v212-comp]').addEventListener('click',()=>void compCurrent());
      toolbar.querySelector('[data-v212-collapse]').addEventListener('click',()=>{const family=currentFamily();if(family)toggleCollapsed(family.parentId);});
      toolbar.querySelector('[data-v212-rating]').addEventListener('change',event=>{const id=selectedStemId();if(id>0)setRating(id,event.currentTarget.value);});
      toolbar.querySelector('[data-v212-punch]').addEventListener('click',()=>void punchFromRange());
      const count=toolbar.querySelector('[data-v212-count]');
      count.value=String(settings.countIn);
      count.addEventListener('change',()=>syncCountIn(count.value));
      return true;
    }

    function updateToolbar(){
      if(!toolbar)return;
      const family=currentFamily();
      const selected=selectedStemId();
      const current=toolbar.querySelector('[data-v212-current]');
      const controls=['prev','next','audition','comp','collapse','rating'];
      controls.forEach(key=>{const el=toolbar.querySelector(`[data-v212-${key}]`);if(el)el.disabled=!family;});
      if(!family){
        setText(current,'NO TAKE STACK');
        const auditionButton=toolbar.querySelector('[data-v212-audition]');
        auditionButton?.classList.remove('active');
        setText(auditionButton,'AUDITION');
        return;
      }
      const found=family.members.findIndex(member=>member.id===selected);
      const index=found>=0?found:0;
      const member=family.members[index]||family.members[0];
      setText(current,memberLabel(member,index,family.members.length));
      const comp=toolbar.querySelector('[data-v212-comp]');
      if(comp)comp.disabled=!member||member.isParent;
      const auditionButton=toolbar.querySelector('[data-v212-audition]');
      auditionButton?.classList.toggle('active',Boolean(audition));
      setText(auditionButton,audition?'RESTORE MIX':'AUDITION');
      const collapse=toolbar.querySelector('[data-v212-collapse]');
      const collapsed=settings.collapsed[String(family.parentId)]===true;
      setText(collapse,collapsed?'SHOW TAKES':'HIDE TAKES');
      const rating=toolbar.querySelector('[data-v212-rating]');
      if(rating)rating.value=String(ratingValue(settings.ratings[String(member.id)]||0));
      const count=toolbar.querySelector('[data-v212-count]');
      if(count)count.value=String(settings.countIn);
    }

    function refresh(){
      if(refreshQueued)return;
      refreshQueued=true;
      root.requestAnimationFrame(()=>{
        refreshQueued=false;
        if(!buildToolbar())return;
        const map=families();
        const valid=new Set(map.keys());
        clearStaleDecorations(valid);
        map.forEach((members,parentId)=>decorateFamily({parentId,members}));
        updateToolbar();
      });
    }

    function keyHandler(event){
      const target=event.target;
      if(target?.matches?.('input,textarea,select,[contenteditable="true"]')||target?.closest?.('[contenteditable="true"]'))return;
      if(!(event.ctrlKey||event.metaKey)||!event.altKey)return;
      if(event.key==='ArrowUp'){event.preventDefault();stepTake(-1);}
      else if(event.key==='ArrowDown'){event.preventDefault();stepTake(1);}
      else if(String(event.key).toLowerCase()==='a'){event.preventDefault();void toggleAudition();}
      else if(event.key==='Enter'){event.preventDefault();void compCurrent();}
    }

    function lateBind(){
      if(!studio()||!core()||!v209()||!v210()||!buildToolbar()){
        bindAttempts+=1;
        if(bindAttempts<240)root.setTimeout(lateBind,60);
        else root.__STONEFELLOW_STEM_V212_INSTALLED__=false;
        return;
      }
      const count=document.getElementById('recordCountInBars')||document.getElementById('studioMetronomeCountIn');
      if(count&&[0,1,2,4].includes(Number(count.value)))settings.countIn=Number(count.value);
      refresh();
      observer=new MutationObserver(refresh);
      observer.observe(document.getElementById('stemStudio')||document.body,{childList:true,subtree:true});
      root.addEventListener('stonefellow:stem-editing-v209',refresh);
      root.addEventListener('stonefellow:stem-edit-v209',refresh);
      root.addEventListener('stonefellow:stem-edit-v210',refresh);
      root.addEventListener('stonefellow:stem-professional-editing-v210',refresh);
      root.addEventListener('stonefellow:stem-automation-mixer-v211',refresh);
      document.addEventListener('keydown',keyHandler,true);
      root.dispatchEvent(new CustomEvent('stonefellow:stem-recording-takes-v212',{detail:{build:BUILD}}));
    }
    lateBind();

    root.addEventListener('pagehide',()=>{
      observer?.disconnect();
      document.removeEventListener('keydown',keyHandler,true);
      if(audition)void restoreAudition();
    },{once:true});

    root.StonefellowStemRecordingTakesV212Runtime={
      build:BUILD,
      getFamilies:()=>[...families()].map(([parentId,members])=>({parentId,members:clone(members.map(({id,name,isParent,takeNumber})=>({id,name,isParent,takeNumber})))})),
      getFamily:()=>{const family=currentFamily();return family?clone(family):null;},
      previous:()=>stepTake(-1),
      next:()=>stepTake(1),
      select:selectTake,
      audition:toggleAudition,
      restoreAudition,
      comp:compCurrent,
      setCollapsed:(parentId,value)=>{settings.collapsed[String(Number(parentId)||0)]=Boolean(value);persist();refresh();return Boolean(value);},
      setRating,
      setCountIn:syncCountIn,
      punchFromRange,
      refresh
    };
    return true;
  }

  return Object.freeze({
    build:BUILD,
    takeNumber,
    baseTakeName,
    buildTakeFamilies,
    familyForStem,
    nextFamilyMember,
    ratingValue,
    install
  });
});
