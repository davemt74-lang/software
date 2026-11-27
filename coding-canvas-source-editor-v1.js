(() => {
  'use strict';
  const names={html:'HTML',css:'CSS',js:'JavaScript'},limits={html:24000,css:16000,js:32000};
  const bytes=text=>new TextEncoder().encode(text).length;
  const copy=value=>({html:value.html,css:value.css,js:value.js});
  const same=(a,b)=>Object.keys(names).every(key=>a[key]===b[key]);
  const key=doc=>doc.scopeKey+':'+doc.canvasId+':'+doc.revision;
  function location(text,pos){const lines=text.slice(0,pos).split('\n');return {line:lines.length,column:lines.at(-1).length+1,pos};}
  function diagnose(artifact){
    for(const language of Object.keys(names)){
      if(typeof artifact[language]!=='string'||bytes(artifact[language])>limits[language]||artifact[language].includes('\0'))return {language,message:names[language]+' exceeds its source limit or contains an invalid character.',blocking:true,...location(artifact[language]||'',0)};
    }
    if(!artifact.html.trim()||Object.values(artifact).reduce((sum,text)=>sum+bytes(text),0)>60000)return {language:'html',message:'Use a nonempty HTML fragment and at most 60,000 bytes across the three files.',blocking:true,...location(artifact.html,0)};
    const allowed=new Set('h1 h2 h3 h4 h5 h6 div span p section article header footer main nav aside button input select optgroup option textarea label fieldset legend ul ol li dl dt dd table thead tbody tfoot tr td th caption details summary strong b em i small code pre kbd blockquote hr br output progress meter'.split(' '));
    for(const match of artifact.html.matchAll(/<\/?([a-z][a-z0-9:-]*)(?=[\s/>])/ig)){
      if(!allowed.has(match[1].toLowerCase()))return {language:'html',message:'This element is outside the Canvas preview contract.',blocking:true,...location(artifact.html,match.index)};
    }
    for(const [language,pattern,message] of [['html',/<!doctype\b|<[^<>]*\s(?:on[a-z]+|style|src|srcset|href|action|formaction)\s*=/i,'Use a body fragment without external resources, inline events or styles.'],['css',/@import\b|\burl\s*\(/i,'Canvas styles cannot import or load external resources.']]){
      const match=pattern.exec(artifact[language]);if(match)return {language,message,blocking:true,...location(artifact[language],match.index)};
    }
    if(window.acorn){
      try{window.acorn.parse('"use strict";\n'+artifact.js,{ecmaVersion:2022,sourceType:'script'});}
      catch(error){return {language:'js',message:String(error.message||'Invalid JavaScript syntax.').replace(/\s*\(\d+:\d+\)$/,'').slice(0,180),blocking:false,
        ...location(artifact.js,Math.max(0,(Number(error.pos)||14)-14))};}
    }
    return null;
  }
  function highlight(code,text,language){
    const ranges=[];
    if(text.length>60000){code.textContent=text;return;}
    if(language==='js'&&window.acorn){
      try{
        const add=range=>{if(ranges.length<5000)ranges.push(range);};
        const tokens=window.acorn.tokenizer(text,{ecmaVersion:2022,onComment:(_block,_value,start,end)=>add({start,end,type:'comment'})});
        for(let n=0;n<5000&&ranges.length<5000;n++){
          const token=tokens.getToken();if(token.type.label==='eof')break;
          const type=token.type.keyword?'keyword':['string','regexp','`','template'].includes(token.type.label)?'string':token.type.label==='num'?'number':'';
          if(type)add({start:token.start,end:token.end,type});
        }
      }catch(_error){/* Keep incomplete source readable while typing. */}
    }else{
      const pattern=language==='html'?/<!--[\s\S]*?(?:-->|$)|<\/?[a-z][^<>]*>/ig:/\/\*[\s\S]*?(?:\*\/|$)|"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'|#[a-f\d]{3,8}\b|\b\d+(?:\.\d+)?(?:px|rem|em|%)?|[-a-z]+(?=\s*:)/ig;
      let count=0;for(const match of text.matchAll(pattern)){
        if(++count>5000)break;
        ranges.push({start:match.index,end:match.index+match[0].length,type:/^(?:<!--|\/\*)/.test(match[0])?'comment':language==='html'?'tag':/^['"]/.test(match[0])?'string':/^[#\d]/.test(match[0])?'number':'keyword'});
      }
    }
    ranges.sort((a,b)=>a.start-b.start);
    const fragment=document.createDocumentFragment();let offset=0;
    for(const range of ranges){
      if(range.start<offset)continue;
      fragment.append(document.createTextNode(text.slice(offset,range.start)));
      const span=document.createElement('span');span.className='vp3-coding-source-'+range.type;span.textContent=text.slice(range.start,range.end);fragment.append(span);offset=range.end;
    }
    fragment.append(document.createTextNode(text.slice(offset)));code.replaceChildren(fragment);
  }
  function mount({root,code,language,onChanged,onSave,onSaved,onPreview,onAsk,onOpenRevision}){
    const create=(tag,text='',className='')=>{const el=document.createElement(tag);el.textContent=text;el.className=className;return el;};
    const button=(text,callback)=>{const el=create('button',text);el.type='button';el.addEventListener('click',callback);return el;};
    const controls=create('div','','vp3-coding-canvas-controls vp3-coding-source-controls');
    const status=create('p','','vp3-coding-canvas-description');status.setAttribute('role','status');
    const diagnostic=create('p','','vp3-coding-source-diagnostic');diagnostic.id='coding-source-diagnostic-v1';diagnostic.setAttribute('role','status');
    const position=create('p','','vp3-coding-canvas-description');
    const field=create('div','','vp3-coding-source-field');
    const input=create('textarea','','vp3-coding-source-input');input.spellcheck=false;input.autocomplete='off';input.autocapitalize='off';input.setAttribute('autocorrect','off');input.maxLength=120000;
    input.setAttribute('aria-describedby',diagnostic.id);code.setAttribute('aria-hidden','true');field.append(code,input);
    const buffers=new Map();let state=null,paint=0,blocked=false,generation=0;
    const dirty=()=>Boolean(state&&!same(state.artifact,state.doc.artifact));
    const hasRoom=()=>!state||buffers.has(key(state.doc))||buffers.size<8;
    function remember(candidate=state){
      if(!candidate||candidate.generation!==generation)return;
      const id=key(candidate.doc);
      if(!same(candidate.artifact,candidate.doc.artifact)||candidate.pending)buffers.set(id,candidate);else buffers.delete(id);
    }
    const save=button('Save source draft',()=>void saveDraft());
    const preview=button('Preview edits',()=>{
      if(!state||!dirty()||state.issue)return;
      onPreview(copy(state.artifact));
    });
    const ask=button('Use selection in Agent request',()=>{
      if(!state||dirty()||input.selectionEnd<=input.selectionStart)return;
      const text=state.artifact[language.value],start=bytes(text.slice(0,input.selectionStart)),end=bytes(text.slice(0,input.selectionEnd));
      if(end-start>8000){state.message='Select at most 8,000 bytes of saved source for the Agent.';renderStatus();return;}
      const first=location(text,input.selectionStart).line,last=location(text,input.selectionEnd).line;
      onAsk({language:language.value,start_byte:start,end_byte:end,source_hash:state.doc.sourceHash},names[language.value]+' lines '+first+'–'+last+' · saved version '+state.doc.revision);
    });
    const discard=button('Discard source edits',()=>{
      if(!state)return;state.artifact=copy(state.doc.artifact);state.pending=null;state.message='Local source edits discarded.';remember();render();onChanged();
    });
    const recover=button('Recover source edits',()=>{
      const found=recoverable();if(found)onOpenRevision(found.doc.revision);
    });
    const goTo=button('Go to error',()=>{
      if(!state?.issue)return;
      language.value=state.issue.language;render();input.focus({preventScroll:true});input.setSelectionRange(state.issue.pos,state.issue.pos);updatePosition();
      const walker=document.createTreeWalker(code,NodeFilter.SHOW_TEXT);let offset=state.issue.pos,text;
      while((text=walker.nextNode())){
        if(offset<=text.textContent.length){const range=document.createRange();range.setStart(text,offset);range.collapse(true);
          const rect=range.getBoundingClientRect(),thread=root.closest('#chatThread');
          if(thread&&rect.height)thread.scrollTop+=rect.top-thread.getBoundingClientRect().top-thread.clientHeight/3;
          else input.scrollIntoView({block:'start'});break;}
        offset-=text.textContent.length;
      }
    });
    controls.append(save,preview,ask,discard,recover,goTo);root.append(controls,status,diagnostic,position,field);
    function recoverable(){return state&&[...buffers.values()].find(item=>item.doc.scopeKey===state.doc.scopeKey&&item.doc.canvasId===state.doc.canvasId&&key(item.doc)!==key(state.doc));}
    function renderStatus(){
      if(!state){status.textContent='';diagnostic.textContent='';position.textContent='';input.readOnly=true;for(const control of [save,preview,ask,discard])control.disabled=true;recover.hidden=goTo.hidden=true;return;}
      const editable=state.doc.editable&&hasRoom();
      input.readOnly=!editable;input.setAttribute('aria-label',names[language.value]+' source');
      const isDirty=dirty(),found=recoverable();
      status.textContent=!state.doc.ready?'Run Database Upgrade to enable source editing.':!state.doc.editable?'This account can inspect saved source.':!hasRoom()?'Save or discard one of the eight unsaved source buffers before editing another app.':
        state.busy?'Saving the source draft…':state.message||(isDirty?'Unsaved source edits. Preview uses a disposable copy of app data.':'Edit the source, or select saved code to include in an Agent request.');
      const issue=state.issue;
      diagnostic.textContent=issue?names[issue.language]+' · Line '+issue.line+', column '+issue.column+': '+issue.message:'';
      diagnostic.hidden=!issue;goTo.hidden=!issue;
      save.disabled=!editable||blocked||state.busy||(!isDirty&&!state.pending)||Boolean(issue?.blocking);
      preview.disabled=!editable||blocked||!isDirty||Boolean(issue);
      ask.disabled=!editable||blocked||isDirty||input.selectionEnd<=input.selectionStart;
      discard.disabled=!isDirty&&!state.pending;
      recover.hidden=!found;recover.textContent=found?'Recover source edits · version '+found.doc.revision:'Recover source edits';
      input.setAttribute('aria-invalid',String(Boolean(issue&&issue.language===language.value)));
    }
    function updatePosition(){
      if(!state)return;
      const loc=location(input.value,input.selectionStart);position.textContent='Line '+loc.line+', column '+loc.column+' · '+bytes(input.value)+' / '+limits[language.value]+' bytes';renderStatus();
    }
    function render(){
      clearTimeout(paint);
      const text=state?.artifact[language.value]??'';
      if(input.value!==text)input.value=text;
      state&&(state.issue=diagnose(state.artifact));highlight(code,text,language.value);renderStatus();updatePosition();
    }
    input.addEventListener('input',()=>{
      if(!state||!state.doc.editable||!hasRoom()){render();return;}
      state.artifact[language.value]=input.value;state.message='';remember();
      clearTimeout(paint);paint=setTimeout(render,100);onChanged();
    });
    for(const event of ['select','keyup','click'])input.addEventListener(event,updatePosition);
    language.addEventListener('change',render);
    async function saveDraft(){
      if(!state||save.disabled)return;
      const selected=state;let confirmed=false;
      const pending=selected.pending??{id:crypto.randomUUID(),doc:{...selected.doc},artifact:copy(selected.artifact)};
      selected.pending=pending;selected.busy=true;remember();renderStatus();
      try{
        const data=await onSave(pending);
        if(state!==selected)throw new DOMException('Editor context changed.','AbortError');
        const carried=copy(selected.artifact);buffers.delete(key(selected.doc));
        const doc={...selected.doc,revision:data.revision.id,sourceHash:data.revision.source_hash,artifact:copy(data.revision.artifact),
          expectedDraft:data.source_saved?data.revision.id:selected.doc.expectedDraft,expectedActive:pending.doc.expectedActive};
        state={doc,generation,artifact:carried,pending:null,busy:false,message:same(carried,doc.artifact)?'Source draft saved. Test the preview before using it.':'Source draft saved. Your newer source edits were kept; save again when ready.'};
        buffers.set(key(doc),state);render();onSaved(data);remember();confirmed=true;
      }catch(error){
        selected.pending=[401,403,404,409,413,419,422].includes(error.status)?null:pending;
        selected.message=error.name==='AbortError'?'The previous save was not confirmed. Save again to recover the same request.':error.message;
        if(state===selected)renderStatus();
      }finally{selected.busy=false;if(confirmed)buffers.delete(key(selected.doc));else remember(selected);if(state===selected)renderStatus();}
    }
    window.addEventListener('beforeunload',event=>{
      if(dirty()||[...buffers.values()].some(item=>!same(item.artifact,item.doc.artifact)||item.pending)){event.preventDefault();event.returnValue='';}
    });
    return Object.freeze({
      bind(doc){
        remember();
        if(!doc){state=null;render();return;}
        const saved=buffers.get(key(doc));
        state=saved&&saved.doc.sourceHash===doc.sourceHash?saved:{doc:{...doc,artifact:copy(doc.artifact)},generation,artifact:copy(doc.artifact),pending:null,busy:false,message:''};
        state.doc.editable=doc.editable;state.doc.ready=doc.ready;render();
      },
      show:render,dirty,artifact:()=>state?copy(state.artifact):null,
      pending:()=>Boolean(state?.pending),
      setBlocked(value){blocked=Boolean(value);renderStatus();},
      clear(dropBuffers=false){remember();if(dropBuffers){buffers.clear();generation++;}state=null;render();},
      reportPreviewFailure(message){if(state){state.message='Unsaved preview: '+message;renderStatus();}},
      document:()=>state?.doc??null,
    });
  }
  window.VP3CanvasSourceEditorV1=Object.freeze({mount,diagnose,highlight});
})();
