(() => {
  'use strict';
  window.VP3PhoneHandoffV1=({request,onMoved})=>{
    const get=id=>document.getElementById('phone-handoff-'+id),panel=get('panel'),source=get('source'),target=get('target'),review=get('review');
    let device=null,ready=false,version=0,busy=false,ticket=null,proof=null,receipt=null,feedback='';
    const validCode=v=>typeof v==='string'&&/^[A-Za-z0-9_-]{43}$/.test(v);
    const active=r=>r&&['offered','claimed','approved'].includes(r.state);
    const key=()=>Array.from(crypto.getRandomValues(new Uint8Array(32)),v=>v.toString(16).padStart(2,'0')).join('');
    const live=()=>ready&&!document.hidden&&!busy&&navigator.onLine!==false;
    const remaining=r=>r?.deadline>performance.now();
    function clearSecrets(){ticket=null;proof=null;receipt=null;get('code').value='';get('entry').value='';get('compare').textContent='';}
    function render(){
      if(ticket&&!remaining(ticket))ticket=null;
      if(proof&&proof.deadline<=performance.now()){proof=null;receipt=null;feedback='Move code expired. Request a new one.';}
      const approved=device?.status==='approved',saved=device?.handoff,r=active(saved)?saved:null;
      panel.hidden=Boolean(device&&!approved)||(!approved&&!ready&&!proof);
      source.hidden=!approved;
      target.hidden=Boolean(device)||Boolean(proof);
      review.hidden=!proof;
      get('help').textContent=approved?'Move only to your own installed app. Stop sharing and finish calls first. The owner must approve the move; the old session disconnects only after you finish. Sharing, saved phone number and information access reset.':'Use the move code from your existing connected app to keep its private Chat. The owner must verify and approve this new installation.';
      get('offer').hidden=Boolean(r)||Boolean(ticket)||!saved;
      get('offer').disabled=!live()||!['off','denied'].includes(device?.location_state);
      get('cancel').hidden=!r;get('cancel').disabled=!live();
      get('ticket').hidden=!ticket||!r||ticket.transfer_id!==r.transfer_id||r.state!=='offered';
      get('code').value=get('ticket').hidden||document.hidden?'':ticket.code;
      get('copy').disabled=!live();
      get('claim').disabled=!live();
      get('compare').textContent=receipt?.comparison_code||'Checking…';
      get('check').disabled=!live()||!proof;
      get('finish').hidden=receipt?.state!=='approved';get('finish').disabled=!live()||!proof;
      get('abandon').disabled=busy;
      get('status').textContent=feedback||(approved&&r?(r.state==='offered'?'Code issued. Open the new app.':r.state==='claimed'?'Waiting for owner approval.':'Owner approved. Finish in the new app.'):'');
    }
    function bind(d,available){
      const old=device?.id||'',next=d?.id||'';
      if(old!==next||(device&&d&&device.generation!==d.generation)){version++;clearSecrets();feedback='';}
      device=d;ready=Boolean(available);
      if(ticket&&(!active(d?.handoff)||d.handoff.transfer_id!==ticket.transfer_id||d.handoff.state!=='offered'))ticket=null;
      render();
    }
    async function invoke(action,body,handle){
      if(!live())return;
      const v=version;busy=true;feedback='';render();
      try{
        const result=await request(action,body);
        if(v===version&&!document.hidden)handle(result);
      }catch(error){if(v===version){feedback=[403,404].includes(error.status)?'Move unavailable. Check the code or ask the owner for a new one.':'Move not confirmed. Check the connection or current sharing state.';}}
      finally{busy=false;render();}
    }
    function receive(r){
      if(!proof||!r||!['claimed','approved'].includes(r.state)||!(/^[0-9A-F]{8}$/).test(r.comparison_code)||!Number.isSafeInteger(r.expires_epoch)||!Number.isSafeInteger(r.server_epoch)||r.expires_epoch<=r.server_epoch)throw Error('Unverified transfer');
      proof.deadline=Math.min(proof.deadline,performance.now()+(r.expires_epoch-r.server_epoch)*1000);receipt=r;
      feedback=r.state==='approved'?'Approved. Finish move when ready.':'Waiting for the owner to compare and approve.';
    }
    get('offer').addEventListener('click',event=>{
      if(!event.isTrusted||get('offer').disabled||get('offer').hidden||!device)return;
      if(!window.confirm('Move this existing pairing to your own installed app? Active sharing and private information grants will reset; existing Chat history remains linked.'))return;
      void invoke('handoff_offer',{device_id:device.id,generation:device.generation,confirmed:true},result=>{
        const t=result.handoff_ticket;
        if(!validCode(t?.code)||!Number.isSafeInteger(t.expires_epoch)||!Number.isSafeInteger(t.server_epoch)||t.expires_epoch<=t.server_epoch)throw Error('Missing move code');
        device=result.device||device;ticket={...t,deadline:performance.now()+(t.expires_epoch-t.server_epoch)*1000};
        feedback='Copy this one-time code to your own installed app. It expires in five minutes.';
      });
    });
    get('copy').addEventListener('click',async event=>{
      if(!event.isTrusted||!ticket||!remaining(ticket)||!live()||get('ticket').hidden)return;
      try{await navigator.clipboard.writeText(ticket.code);feedback='Copied. Paste only into your own VP3 Phone app.';}
      catch(_error){get('code').select();feedback='Select and copy the move code.';}render();
    });
    get('cancel').addEventListener('click',event=>{
      if(!event.isTrusted||!active(device?.handoff)||!live())return;
      void invoke('handoff_cancel',{device_id:device.id,generation:device.generation,transfer_id:device.handoff.transfer_id,confirmed:true},result=>{
        device=result.device||device;clearSecrets();feedback='Move canceled.';
      });
    });
    target.addEventListener('submit',event=>{
      event.preventDefault();
      if(!event.isTrusted||device||proof||!live())return;
      const code=get('entry').value.trim();if(!validCode(code)){feedback='Enter the full move code.';render();return;}
      proof={code,claim_key:key(),deadline:performance.now()+300000};get('entry').value='';
      void invoke('handoff_claim',{code:proof.code,claim_key:proof.claim_key,confirmed:true},r=>receive(r.handoff));
    });
    get('check').addEventListener('click',event=>{
      if(!event.isTrusted||!proof||!live()||!remaining(proof))return;
      const fields={code:proof.code,claim_key:proof.claim_key};
      void invoke(receipt?'handoff_check':'handoff_claim',{...fields,...(receipt?{}:{confirmed:true})},r=>receive(r.handoff));
    });
    get('finish').addEventListener('click',event=>{
      if(!event.isTrusted||!proof||receipt?.state!=='approved'||!remaining(proof)||!live())return;
      if(!window.confirm('Finish moving your existing connection? The old app disconnects and you will need to approve sharing again.'))return;
      void invoke('handoff_finish',{code:proof.code,claim_key:proof.claim_key,confirmed:true},result=>{
        if(result.handoff?.state!=='finished'||result.device?.status!=='approved')throw Error('Unconfirmed move');
        clearSecrets();feedback='Connection moved.';onMoved(result);
      });
    });
    get('abandon').addEventListener('click',event=>{if(!event.isTrusted||busy)return;clearSecrets();feedback='Request cleared. The old connection remains until a move finishes.';render();});
    window.addEventListener('pagehide',()=>{version++;clearSecrets();render();});
    document.addEventListener('visibilitychange',()=>{if(document.hidden)get('code').value='';else render();});
    panel.addEventListener('toggle',()=>{if(!panel.open){get('entry').value='';}else render();});
    render();return {bind};
  };
})();
