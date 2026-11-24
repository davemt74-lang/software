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
    const kinds={update:'Send update',question:'Ask a question',availability:'Request availability',task:'Request a task'};
    for(const [value,label] of Object.entries(kinds))kind.add(new Option(label,value));
    kindLabel.append(kind);
    const messageLabel=make('label','Message or request'),message=make('textarea');message.maxLength=1000;message.rows=3;message.required=true;
    message.setAttribute('aria-label','Information message');messageLabel.append(message);
    const send=make('button','Send to phone');send.type='submit';send.className='phone-primary';form.append(kindLabel,messageLabel,send);
    root.append(title,notice,controls);if(!holder)root.append(form);root.append(list);
    const labels={pending:'Pending',accepted:'Accepted',declined:'Declined',completed:'Completed',acknowledged:'Acknowledged',canceled:'Canceled',expired:'Expired'};
    let device=null,scopeKey='',epoch=0,writing=false,reading=false,nextRead=0,items=[],signature='',draftId='',draftHash='',replies=new Map();
    const hex=v=>typeof v==='string'&&/^[a-f0-9]{32}$/.test(v);
    const randomId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
    const goodText=s=>typeof s==='string'&&s.trim()&&new TextEncoder().encode(s.trim()).length<=1000;
    const key=d=>hex(d?.id)&&hex(d?.communication?.profile_id)&&Number.isSafeInteger(d.communication.revision)?d.id+'/'+d.communication.profile_id+'/'+d.communication.revision:'';
    const current=(version,expected)=>epoch===version&&scopeKey===expected&&!!device;
    function clear(){
      epoch++;device=null;scopeKey='';writing=reading=false;nextRead=0;items=[];signature='';draftId=draftHash='';replies.clear();
      message.value='';kind.value='update';notice.textContent='';list.replaceChildren();
    }
    function setStatus(s){notice.textContent=s;}
    function setControls(){
      refresh.disabled=!device||reading||writing;
      send.disabled=!device||writing||!goodText(message.value);
      kind.disabled=message.disabled=writing||!device;
      for(const input of list.querySelectorAll('button,textarea'))input.disabled=writing||!device;
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
        seen.add(item.exchange_id);
      }
      return data.items;
    }
    function accept(result,d){
      items=decode(result,d);
      for(const id of replies.keys())if(!items.some(i=>i.exchange_id===id&&i.state==='accepted'))replies.delete(id);
      renderItems();
    }
    function button(label,action,item){
      const b=make('button',label);b.type='button';
      b.addEventListener('click',event=>{if(event.isTrusted)void mutate(action,{exchange_id:item.exchange_id,version:item.version});});
      return b;
    }
    function renderItems(){
      const next=JSON.stringify(items);
      if(next===signature){setControls();return;}
      signature=next;list.replaceChildren();
      if(!items.length)list.append(make('p','No information exchanged yet.','phone-information-empty'));
      for(const i of items){
        const card=make('article',undefined,'phone-information-item');
        card.append(make('strong',kinds[i.kind]+' · '+labels[i.state]));
        if(i.message)card.append(make('p',i.message,'phone-information-text'));
        if(i.response)card.append(make('p','Response: '+i.response,'phone-information-text'));
        card.append(make('small',new Date(i.created_epoch*1000).toLocaleString()));
        const actions=make('div',undefined,'phone-information-controls');
        if(holder&&i.state==='pending'){
          actions.append(i.kind==='update'?button('Acknowledge update','information_ack',i):button('Accept request','information_accept',i),button('Decline','information_decline',i));
        }
        if(holder&&i.state==='accepted'){
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
          if(action==='information_create'){message.value='';draftId=draftHash='';}
          onChanged();
        }
      }catch{
        if(current(op,k)){setStatus('Delivery is unconfirmed. Refresh before retrying.');onChanged();}
      }finally{if(current(op,k)){writing=false;nextRead=Date.now()+12000;setControls();}}
    }
    refresh.addEventListener('click',event=>{if(event.isTrusted)void read();});
    message.addEventListener('input',setControls);
    form.addEventListener('submit',event=>{
      event.preventDefault();
      if(!event.isTrusted||holder||!device||writing||!goodText(message.value))return;
      const text=message.value.trim(),hash=JSON.stringify([kind.value,text]);
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
    return {root,bind,clear};
  };
})();
