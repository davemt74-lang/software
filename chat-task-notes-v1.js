(() => {
  'use strict';
  if(window.VP3TaskNotes)return;
  const cfg=window.VP3_TASK_NOTES_V1||{}, notes=new Map();
  const key=`vp3-task-notes-v1:${Number(cfg.userId)}:${Number(cfg.agentId)}`;
  let z=1065, generation=0;
  const make=(tag,cls,value)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(value!==undefined)e.textContent=String(value);return e;};
  const refKey=r=>r.kind==='reminders'?`reminders:${r.view||'open'}`:`task_list:${Number(r.id)}`;
  function safeRef(r){if(!r||typeof r!=='object')return null;if(r.kind==='task_list'&&Number.isInteger(r.id)&&r.id>0)return {kind:r.kind,id:r.id};if(r.kind==='reminders'&&['open','today','all','overdue'].includes(r.view||'open'))return {kind:r.kind,id:0,view:r.view||'open',timezone:typeof r.timezone==='string'?r.timezone:'UTC'};return null;}
  function store(){try{localStorage.setItem(key,JSON.stringify([...notes.values()].map(n=>({ref:n.ref,x:parseInt(n.root.style.left,10),y:parseInt(n.root.style.top,10),collapsed:n.body.hidden}))));}catch{}}
  function place(n,x,y){const w=n.root.offsetWidth||300,h=Math.min(n.root.offsetHeight||180,innerHeight-110);n.root.style.left=Math.max(8,Math.min(Number(x)||8,innerWidth-w-8))+'px';n.root.style.top=Math.max(62,Math.min(Number(y)||100,innerHeight-h-90))+'px';}
  function prompt(value){const input=document.getElementById('chatInput'),form=document.getElementById('chatForm');if(!input||!form)return false;input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));form.requestSubmit();return true;}
  function button(label,title,fn){const b=make('button','',label);b.type='button';b.title=title;b.setAttribute('aria-label',title);b.addEventListener('click',fn);return b;}
  async function refresh(n){
    const seq=++n.seq, epoch=generation;n.status.textContent='Loading…';
    try{
      const res=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:cfg.csrf,agent_id:Number(cfg.agentId)||0,note:n.ref})});
      const data=await res.json();if(epoch!==generation||seq!==n.seq||!n.root.isConnected)return;
      n.body.replaceChildren();if(!res.ok||!data.ok)throw Error(data.error||'Could not load this note.');
      const note=data.note;n.title.textContent=note.title;n.root.setAttribute('aria-label',note.title);n.status.textContent='';
      for(const item of note.items||[]){
        const row=make('label','vp3-task-note-row');row.dataset.done=String(!!item.done);
        const box=make('input');box.type='checkbox';box.checked=!!item.done;box.setAttribute('aria-label',`${item.done?'Reopen':'Complete'} ${item.title}`);
        if(note.kind==='reminders')box.disabled=!!item.done||!['pending','overdue'].includes(item.status);
        box.addEventListener('change',()=>{box.checked=!!item.done;box.disabled=true;n.status.textContent='Sending to Chat…';const sent=prompt(note.kind==='reminders'?`Mark reminder #${item.id} done`:`Mark task #${item.id} ${item.done?'open':'completed'}`);if(!sent){box.disabled=false;n.status.textContent='Open Chat to update this item.';}});
        const copy=make('span');copy.append(make('span','vp3-task-note-label',item.title));if(item.detail)copy.append(make('small','',item.detail));else if(item.status&&item.status!=='open')copy.append(make('small','',item.status));row.append(box,copy);n.body.append(row);
      }
      if(!(note.items||[]).length)n.body.append(make('p','vp3-task-note-status','Nothing here yet.'));
      if(note.truncated)n.body.append(make('p','vp3-task-note-status','Showing the first saved items. Ask Chat to narrow the list.'));
      place(n,parseInt(n.root.style.left,10),parseInt(n.root.style.top,10));
    }catch(e){if(epoch!==generation||seq!==n.seq||!n.root.isConnected)return;n.body.replaceChildren();n.title.textContent='Note unavailable';n.status.textContent=e.message||'Could not load this note.';}
  }
  function open(ref,saved={}){
    ref=safeRef(ref);if(!ref)return;const id=refKey(ref);if(notes.has(id)){const n=notes.get(id);n.ref=ref;n.body.hidden=false;n.root.style.zIndex=String(++z);refresh(n);store();return;}
    if(notes.size>=8)return;
    const root=make('section','vp3-task-note');root.dataset.kind=ref.kind;root.setAttribute('role','region');root.style.zIndex=String(++z);
    const header=make('header'),title=make('strong','',ref.kind==='reminders'?'Reminders':'List'),body=make('div','vp3-task-note-body'),status=make('p','vp3-task-note-status'),foot=make('footer');status.setAttribute('role','status');
    const n={root,title,body,status,ref,seq:0};notes.set(id,n);body.hidden=!!saved.collapsed;
    header.tabIndex=0;header.setAttribute('aria-label','Move note with arrow keys');
    header.append(title,button('↻','Refresh note',()=>refresh(n)),button('−','Collapse or expand note',()=>{body.hidden=!body.hidden;store();}),button('×','Close note',()=>{n.seq++;root.remove();notes.delete(id);store();}));
    foot.append(status);
    root.append(header,body,foot);document.body.append(root);place(n,saved.x??innerWidth-320-(notes.size-1)*18,saved.y??130+(notes.size-1)*28);
    let drag=null;
    header.addEventListener('pointerdown',e=>{if(e.target.closest('button')||e.button!==0)return;drag={x:e.clientX,y:e.clientY,left:parseInt(root.style.left,10),top:parseInt(root.style.top,10)};root.style.zIndex=String(++z);header.setPointerCapture(e.pointerId);});
    header.addEventListener('pointermove',e=>{if(drag)place(n,drag.left+e.clientX-drag.x,drag.top+e.clientY-drag.y);});
    for(const type of ['pointerup','pointercancel','lostpointercapture'])header.addEventListener(type,()=>{if(drag){drag=null;store();}});
    header.addEventListener('keydown',e=>{if(e.target!==header||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();place(n,parseInt(root.style.left,10)+(e.key==='ArrowLeft'?-16:e.key==='ArrowRight'?16:0),parseInt(root.style.top,10)+(e.key==='ArrowUp'?-16:e.key==='ArrowDown'?16:0));store();});
    refresh(n);store();
  }
  function consume(refs){for(const ref of (Array.isArray(refs)?refs:[]).slice(0,8))open(ref);for(const n of notes.values())if(!(refs||[]).some(r=>safeRef(r)&&refKey(r)===refKey(n.ref)))refresh(n);}
  window.VP3TaskNotes={consume,open,refresh:()=>{for(const n of notes.values())refresh(n);}};
  function boot(){try{const saved=JSON.parse(localStorage.getItem(key)||'[]');if(Array.isArray(saved))for(const n of saved.slice(0,8))open(n.ref,n);}catch{}
    const shortcuts=make('nav','vp3-task-notes-shortcuts');shortcuts.setAttribute('aria-label','Show lists and reminders');shortcuts.append(button('SHOW REMINDERS','Show reminders',()=>prompt('Show reminders')),button('SHOW PREP LIST','Show prep list',()=>prompt('Show prep list')));document.body.append(shortcuts);
  }
  window.addEventListener('resize',()=>{for(const n of notes.values())place(n,parseInt(n.root.style.left,10),parseInt(n.root.style.top,10));store();});
  window.addEventListener('pagehide',()=>{generation++;store();});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
