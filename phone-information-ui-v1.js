(() => {
  'use strict';
  // One foreground panel. No local storage, background send, or automatic retry.
  window.VP3PhoneInformationV1=function({holder=false,request,onChanged=()=>{}}){
    const make=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
    const root=make('section',undefined,'phone-information-panel');
    const title=make('h3',holder?'Information inbox':'Information exchange');
    const notice=make('p',undefined,'phone-information-status');notice.setAttribute('role','status');
    const controls=make('div',undefined,'phone-information-controls');
    const refresh=make('button','Refresh exchange');refresh.type='button';controls.append(refresh);
    const list=make('div',undefined,'phone-information-items');
    const form=make('form',undefined,'phone-information-compose');
    const kindLabel=make('label','What to share'),kind=make('select');kind.setAttribute('aria-label','Information type');
    const kinds={update:'Send update',question:'Ask a question',availability:'Request availability',task:'Request a task',photo_request:'Request a photo',file_request:'Request a file or document',file_share:'Send a file'};
    for(const [value,label] of Object.entries(kinds))kind.add(new Option(label,value));
    kindLabel.append(kind);
    const messageLabel=make('label','Message or request'),message=make('textarea');message.maxLength=1000;message.rows=3;message.required=true;
    message.setAttribute('aria-label','Information message');messageLabel.append(message);
    const extensions={'image/jpeg':'jpg','image/png':'png','application/pdf':'pdf','text/plain':'txt'};
    const accepted='image/jpeg,image/png,application/pdf,text/plain,.jpg,.jpeg,.png,.pdf,.txt';
    const isFileKind=k=>['photo_request','file_request','file_share'].includes(k);
    const fileLimit=2097152;
    function fileControls(photoOnly=false){
      const box=make('div',undefined,'phone-information-file'),label=make('label','Choose attachment'),input=make('input');
      input.type='file';input.accept=photoOnly?'image/jpeg,image/png,.jpg,.jpeg,.png':accepted;input.setAttribute('aria-label','Choose attachment');label.append(input);
      const agreement=make('label',undefined,'phone-information-confirm'),check=make('input');check.type='checkbox';
      check.setAttribute('aria-label','Share this selected file with the other party.');
      input.addEventListener('change',()=>{check.checked=false;});
      agreement.append(check,make('span','Share this selected file with the other party.'));
      box.append(label,agreement,make('small','JPEG, PNG, PDF or plain text, up to 2 MiB. Photos can contain location metadata. Files are not malware-scanned.'));
      return {box,input,check};
    }
    const attachment=fileControls();
    const send=make('button','Send to phone');send.type='submit';send.className='phone-primary';form.append(kindLabel,messageLabel,attachment.box,send);
    root.append(title,notice,controls);if(!holder)root.append(form);root.append(list);
    const labels={pending:'Pending',accepted:'Accepted',declined:'Declined',completed:'Completed',acknowledged:'Acknowledged',canceled:'Canceled',expired:'Expired'};
    let device=null,scopeKey='',epoch=0,writing=false,reading=false,nextRead=0,items=[],signature='',draftId='',draftHash='',filesReady=false,replies=new Map();
    let notificationExchange=null;
    const hex=v=>typeof v==='string'&&/^[a-f0-9]{32}$/.test(v);
    const randomId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
    const goodText=s=>typeof s==='string'&&s.trim()&&new TextEncoder().encode(s.trim()).length<=1000;
    const key=d=>hex(d?.id)&&hex(d?.communication?.profile_id)&&Number.isSafeInteger(d.communication.revision)?d.id+'/'+d.communication.profile_id+'/'+d.communication.revision:'';
    const current=(version,expected)=>epoch===version&&scopeKey===expected&&!!device;
    function clear(){
      epoch++;device=null;scopeKey='';writing=reading=false;nextRead=0;items=[];signature='';draftId=draftHash='';replies.clear();
      filesReady=false;message.value='';kind.value='update';attachment.input.value='';attachment.check.checked=false;notice.textContent='';list.replaceChildren();
      notificationExchange=null;
    }
    function setStatus(s){notice.textContent=s;}
    function setControls(){
      refresh.disabled=!device||reading||writing;
      const share=kind.value==='file_share';
      attachment.box.hidden=!share;
      for(const option of kind.options)option.disabled=isFileKind(option.value)&&!filesReady;
      send.disabled=!device||writing||!goodText(message.value)||(isFileKind(kind.value)&&!filesReady)||(share&&(!attachment.check.checked||!attachment.input.files?.[0]||attachment.input.files[0].size>fileLimit));
      kind.disabled=message.disabled=attachment.input.disabled=attachment.check.disabled=writing||!device;
      for(const input of list.querySelectorAll('button,textarea,input'))input.disabled=writing||!device;
    }
    function bound(d){return {device_id:d.id,generation:d.generation,profile_id:d.communication.profile_id,profile_revision:d.communication.revision};}
    function decode(result,d){
      const data=result?.information,p=d.communication;
      if(data?.profile_id!==p.profile_id||data.profile_revision!==p.revision||!Array.isArray(data.items)||data.items.length>20)throw Error('Exchange scope changed.');
      const seen=new Set();
      for(const item of data.items){
        if(!hex(item?.exchange_id)||seen.has(item.exchange_id)||!Number.isSafeInteger(item.version)||item.version<1
          ||!Object.hasOwn(kinds,item.kind)||!Object.hasOwn(labels,item.state)||typeof item.message!=='string'||typeof item.response!=='string'
          ||!Number.isSafeInteger(item.created_epoch)||!Number.isSafeInteger(item.expires_epoch))throw Error('Exchange unavailable.');
        if(item.attachment!=null){
          const f=item.attachment,ext=extensions[f.mime];
          if(!ext||f.filename!=='vp3-'+item.exchange_id.slice(0,12)+'.'+ext||!Number.isSafeInteger(f.bytes)||f.bytes<1||f.bytes>fileLimit||!Number.isSafeInteger(f.expires_epoch))throw Error('Attachment unavailable.');
        }
        seen.add(item.exchange_id);
      }
      return data.items;
    }
    function accept(result,d){
      items=decode(result,d);filesReady=result.information.files_ready===true;
      for(const id of replies.keys())if(!items.some(i=>i.exchange_id===id&&i.state==='accepted'))replies.delete(id);
      renderItems();
    }
    function button(label,action,item){
      const b=make('button',label);b.type='button';
      b.addEventListener('click',event=>{if(event.isTrusted)void mutate(action,{exchange_id:item.exchange_id,version:item.version});});
      return b;
    }
    async function encodeFile(file){
      if(!file||file.size<1||file.size>fileLimit)throw Error('Choose a file up to 2 MiB.');
      const bytes=new Uint8Array(await file.arrayBuffer());
      if(bytes.length!==file.size||bytes.length>fileLimit)throw Error('File changed.');
      let binary='';for(let i=0;i<bytes.length;i+=16384)binary+=String.fromCharCode(...bytes.subarray(i,i+16384));
      const digest=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),v=>v.toString(16).padStart(2,'0')).join('');
      return {base64:btoa(binary),digest};
    }
    async function download(item){
      if(!device||writing||document.hidden||!item.attachment)return;
      const d=device,v=++epoch,k=scopeKey;writing=true;reading=false;setControls();setStatus('Retrieving private file…');
      try{
        const result=await request('information_download',{...bound(d),exchange_id:item.exchange_id,version:item.version});
        if(!current(v,k)||document.hidden)return;
        const f=result?.information?.download,m=item.attachment;
        if(!f||f.filename!==m.filename||f.mime!==m.mime||f.bytes!==m.bytes||typeof f.base64!=='string'||f.base64.length>4*Math.ceil(fileLimit/3)||!/^[a-f0-9]{64}$/.test(f.sha256))throw Error('Invalid attachment.');
        const binary=atob(f.base64),bytes=Uint8Array.from(binary,c=>c.charCodeAt(0));
        const digest=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),n=>n.toString(16).padStart(2,'0')).join('');
        if(bytes.length!==m.bytes||digest!==f.sha256||!current(v,k)||document.hidden)throw Error('Attachment changed.');
        // Always a download, never embed active or untrusted content in the app.
        const url=URL.createObjectURL(new Blob([bytes],{type:'application/octet-stream'}));
        const a=make('a');a.href=url;a.download=m.filename;document.body.append(a);a.click();a.remove();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
        setStatus('Download requested. Open only files you trust.');
      }catch{if(current(v,k)){setStatus('File could not be retrieved. Refresh the exchange.');onChanged();}}
      finally{if(current(v,k)){writing=false;nextRead=0;setControls();}}
    }
    function renderItems(){
      const next=JSON.stringify(items);
      if(next===signature){setControls();return;}
      signature=next;list.replaceChildren();
      if(!items.length)list.append(make('p','No information exchanged yet.','phone-information-empty'));
      for(const i of items){
        const card=make('article',undefined,'phone-information-item');
        card.dataset.exchangeId=i.exchange_id;
        card.append(make('strong',(i.call_companion===true?'Call companion':kinds[i.kind])+' · '+labels[i.state]));
        if(i.message){
          const message=make('p',i.message,'phone-information-text');
          if(i.call_companion===true){
            message.replaceChildren();
            i.message.split('\n').forEach((line,index)=>{
              if(index)message.append(document.createElement('br'));
              try{
                if(!/^https?:\/\/[^\s]+$/i.test(line))throw Error();
                const url=new URL(line);
                if(url.username||url.password)throw Error();
                const link=make('a',line);link.href=url.href;link.target='_blank';link.rel='noopener noreferrer';message.append(link);
              }catch{message.append(document.createTextNode(line));}
            });
          }
          card.append(message);
        }
        if(i.response)card.append(make('p','Response: '+i.response,'phone-information-text'));
        if(i.attachment){const f=i.attachment;card.append(make('p',f.filename+' · '+Math.ceil(f.bytes/1024)+' KiB · Unscanned file','phone-information-text'));const get=make('button','Download file');get.type='button';get.addEventListener('click',event=>{if(event.isTrusted)void download(i);});card.append(get);}
        card.append(make('small',new Date(i.created_epoch*1000).toLocaleString()));
        const actions=make('div',undefined,'phone-information-controls');
        if(holder&&i.state==='pending'){
          actions.append(['update','file_share'].includes(i.kind)?button(i.kind==='file_share'?'Acknowledge file':'Acknowledge update','information_ack',i):button('Accept request','information_accept',i),button('Decline','information_decline',i));
        }
        if(holder&&i.state==='accepted'&&['photo_request','file_request'].includes(i.kind)){
          const upload=make('form'),picker=fileControls(i.kind==='photo_request'),submit=make('button','Share selected file');submit.type='submit';
          upload.append(picker.box,submit);
          upload.addEventListener('submit',event=>{
            event.preventDefault();if(!event.isTrusted||!device||writing||!picker.check.checked||!picker.input.files?.[0])return;
            void uploadFile('information_attach_file',{exchange_id:i.exchange_id,version:i.version,share_confirmed:true},picker.input.files[0]);
          });
          card.append(upload);actions.append(button('Decline','information_decline',i));
        }else if(holder&&i.state==='accepted'){
          const responseForm=make('form'),label=make('label','Your response'),input=make('textarea');input.rows=2;input.maxLength=1000;input.required=true;
          input.value=replies.get(i.exchange_id)||'';input.setAttribute('aria-label','Your response');
          input.addEventListener('input',()=>replies.set(i.exchange_id,input.value));label.append(input);
          const reply=make('button','Send response');reply.type='submit';responseForm.append(label,reply);
          responseForm.addEventListener('submit',event=>{event.preventDefault();if(event.isTrusted&&goodText(input.value))void mutate('information_reply',{exchange_id:i.exchange_id,version:i.version,response:input.value.trim()});});
          card.append(responseForm);actions.append(button('Decline','information_decline',i));
        }
        if(!holder&&['pending','accepted'].includes(i.state))actions.append(button('Cancel request','information_cancel',i));
        card.append(actions);list.append(card);
      }
      setControls();
      focusNotification();
    }
    function focusNotification(){
      if(!notificationExchange||root.hidden)return;
      const card=Array.from(list.children).find(item=>item.dataset.exchangeId===notificationExchange);
      if(card){card.tabIndex=-1;card.focus({preventScroll:true});card.scrollIntoView({block:'center'});notificationExchange=null;}
    }
    function openNotification(exchangeId){
      if(!device||!holder||!hex(exchangeId))return;
      notificationExchange=exchangeId;focusNotification();nextRead=0;void read();
    }
    async function read(){
      if(!device||reading||writing||document.hidden)return;
      const d=device,v=epoch,k=scopeKey;reading=true;setControls();
      nextRead=Date.now()+12000;
      try{
        const result=await request('information_list',{device_id:d.id});
        if(current(v,k)){accept(result,d);setStatus('');}
      }catch{
        if(current(v,k)){items=[];signature='';renderItems();setStatus('Could not confirm the current exchange. Refresh permissions and try again.');onChanged();}
      }finally{if(current(v,k)){reading=false;setControls();}}
    }
    async function mutate(action,fields){
      if(!device||writing||document.hidden)return;
      const d=device,v=epoch,k=scopeKey;writing=true;reading=false;setControls();setStatus('Saving…');
      // Fence a read that started before the mutation.
      const op=++epoch;
      try{
        const result=await request(action,{...bound(d),...fields});
        if(current(op,k)){
          accept(result,d);setStatus('Saved.');
          if(action==='information_create'||action==='information_create_file'){message.value='';attachment.input.value='';attachment.check.checked=false;draftId=draftHash='';}
          onChanged();
        }
      }catch{
        if(current(op,k)){setStatus('Delivery is unconfirmed. Refresh before retrying.');onChanged();}
      }finally{if(current(op,k)){writing=false;nextRead=Date.now()+12000;setControls();}}
    }
    async function uploadFile(action,fields,file){
      if(!device||writing||document.hidden||!filesReady)return;
      const d=device,k=scopeKey,op=++epoch;writing=true;reading=false;setControls();setStatus('Preparing selected file…');
      try{
        const encoded=await encodeFile(file);
        if(!current(op,k)||document.hidden)return;
        if(action==='information_create_file'){
          const hash=JSON.stringify(['file_share',fields.message,encoded.digest]);
          if(!draftId||draftHash!==hash){draftId=randomId();draftHash=hash;}
          fields.exchange_id=draftId;
        }
        const result=await request(action,{...bound(d),...fields,file_base64:encoded.base64});
        if(current(op,k)){
          accept(result,d);setStatus('File shared.');
          if(action==='information_create_file'){message.value='';attachment.input.value='';attachment.check.checked=false;draftId=draftHash='';}
          onChanged();
        }
      }catch{if(current(op,k)){setStatus('File sharing could not be confirmed. Refresh before retrying.');onChanged();}}
      finally{if(current(op,k)){writing=false;nextRead=Date.now()+12000;setControls();}}
    }
    refresh.addEventListener('click',event=>{if(event.isTrusted)void read();});
    message.addEventListener('input',setControls);
    kind.addEventListener('change',setControls);attachment.input.addEventListener('change',()=>{attachment.check.checked=false;setControls();});attachment.check.addEventListener('change',setControls);
    form.addEventListener('submit',event=>{
      event.preventDefault();
      if(!event.isTrusted||holder||!device||writing||!goodText(message.value))return;
      const text=message.value.trim();
      if(kind.value==='file_share'){
        if(filesReady&&attachment.check.checked&&attachment.input.files?.[0])void uploadFile('information_create_file',{kind:'file_share',message:text,share_confirmed:true},attachment.input.files[0]);
        return;
      }
      if(isFileKind(kind.value)&&!filesReady)return;
      const hash=JSON.stringify([kind.value,text]);
      if(!draftId||draftHash!==hash){draftId=randomId();draftHash=hash;}
      void mutate('information_create',{exchange_id:draftId,kind:kind.value,message:text});
    });
    function bind(d,available,show){
      if(!show||!available||document.hidden){
        if(scopeKey)clear();
        root.hidden=!show;
        if(show){form.hidden=true;refresh.hidden=true;setStatus('Reconnect to view information exchange.');}
        return;
      }
      root.hidden=false;
      const p=d?.communication;
      const allowed=d?.status==='approved'&&p?.request_permission_saved===true&&Number.isSafeInteger(p.information_pending)&&!!key(d);
      if(!allowed){
        if(scopeKey)clear();
        form.hidden=true;refresh.hidden=true;setStatus(p?.information_pending==null?'Information exchange requires the latest database upgrade.':'Both the account owner and phone holder must allow information exchange.');return;
      }
      const k=key(d);
      if(k!==scopeKey){clear();scopeKey=k;signature='';setStatus('Loading…');}
      device=d;form.hidden=holder;refresh.hidden=false;setControls();
      if(!reading&&!writing&&Date.now()>=nextRead)void read();
    }
    return {root,bind,clear,openNotification};
  };
})();
