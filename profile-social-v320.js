(() => {
  const config=window.STONEFELLOW_PROFILE_AGENT;
  const username=String(config?.username||'').trim();
  const identity=document.querySelector('.profile-identity');
  if(!username||!identity||document.querySelector('[data-profile-social-actions]'))return;
  const base=new URL('.',document.currentScript?.src||window.location.href);
  const bootstrapUrl=new URL('api/profile-social-v320.php',base);
  bootstrapUrl.searchParams.set('username',username);
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  let state=null;

  function addStyles(){
    if(document.querySelector('style[data-profile-social-v320]'))return;
    const style=document.createElement('style');style.dataset.profileSocialV320='1';
    style.textContent='.vp3-profile-social{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-left:auto}.vp3-profile-social button,.vp3-profile-social a{appearance:none;border:1px solid #d9dadd;border-radius:999px;background:#fff;color:#202225;padding:9px 14px;font:700 13px/1.1 inherit;text-decoration:none;cursor:pointer}.vp3-profile-social .primary{background:#202225;color:#fff;border-color:#202225}.vp3-profile-social button[disabled]{opacity:.58;cursor:default}.vp3-profile-social-status{font-size:12px;color:#73767c;width:100%;text-align:right}@media(max-width:700px){.vp3-profile-social{margin-left:0;width:100%;padding-top:8px}.vp3-profile-social-status{text-align:left}}';
    document.head.appendChild(style);
  }

  async function post(action,extra={}){
    if(!state)return null;
    const body=new FormData();body.set('action',action);body.set('csrf_token',state.csrf_token);for(const [key,value] of Object.entries(extra))body.set(key,String(value));
    const response=await fetch(state.api_url,{method:'POST',credentials:'same-origin',cache:'no-store',body});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.message||'That action could not be completed.');
    return data;
  }

  function render(){
    if(!state)return;addStyles();
    const rel=state.relationship||{};const target=state.target||{};
    let shell=document.querySelector('[data-profile-social-actions]');if(!shell){shell=document.createElement('div');shell.className='vp3-profile-social';shell.dataset.profileSocialActions='1';identity.appendChild(shell);}
    const following=!!rel.following;const friendship=String(rel.friendship_status||'');const requestedBy=Number(rel.friend_requested_by||0);const targetId=Number(target.user_id||0);const blocked=!!rel.blocked;
    let friendButton='';
    if(!blocked){
      if(friendship==='accepted')friendButton='<button type="button" disabled>Friends</button>';
      else if(friendship==='pending'&&requestedBy===targetId)friendButton='<button type="button" data-social-action="friend_accept">Accept Friend</button>';
      else if(friendship==='pending')friendButton='<button type="button" disabled>Friend Request Sent</button>';
      else friendButton='<button type="button" data-social-action="friend_request">Add Friend</button>';
    }
    const message=(!blocked&&String(rel.dm_route||'deny')!=='deny')?`<a class="primary" href="${esc(state.messages_url)}?user_id=${targetId}">Message</a>`:'';
    shell.innerHTML=`<button type="button" data-social-action="${following?'unfollow':'follow'}">${following?'Following':'Follow'}</button>${friendButton}${message}<span class="vp3-profile-social-status" data-social-status></span>`;
    shell.querySelectorAll('[data-social-action]').forEach(button=>button.addEventListener('click',()=>act(button)));
  }

  async function refresh(){
    const response=await fetch(bootstrapUrl,{credentials:'same-origin',cache:'no-store'});const data=await response.json().catch(()=>null);if(!response.ok||!data?.ok)return;state=data;render();
  }

  async function act(button){
    if(!state||button.disabled)return;const action=button.dataset.socialAction;const status=document.querySelector('[data-social-status]');button.disabled=true;if(status)status.textContent='';
    try{await post(action,{user_id:state.target.user_id});await refresh();}catch(error){button.disabled=false;if(status)status.textContent=error?.message||'Action failed.';}
  }

  refresh().catch(()=>{});
})();
