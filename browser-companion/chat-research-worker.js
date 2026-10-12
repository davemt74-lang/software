/* Chrome reads only exact Chat-reviewed URLs under the existing server research grant. */
let chatResearchFlight=null;
const chatResearchTabs=new Map();
async function chatResearchApi(action,payload={}){
 return authorizedFetch('/api/chat-browser-research-v1.php',{method:'POST',json:{...payload,action}},'agent.message');
}
async function chatResearchGuard(tabId,changeInfo,tab){
 const expected=chatResearchTabs.get(tabId);if(!expected||!changeInfo.url)return;
 if(String(tab.url||changeInfo.url)!==expected){
  await chrome.scripting.executeScript({target:{tabId},func:()=>window.stop()}).catch(()=>{});
  await chrome.tabs.remove(tabId).catch(()=>{});chatResearchTabs.delete(tabId);
 }
}
async function chatResearchRun(payload={}){
 if(chatResearchFlight)throw new Error('Chrome is already reading a Chat research plan.');
 chatResearchFlight=chatResearchRunOwned(payload);
 try{return await chatResearchFlight;}finally{chatResearchFlight=null;}
}
async function chatResearchRunOwned(payload){
 const context=await connectionContext();
 const claim=await chatResearchApi('worker.claim',{id:String(payload.id||''),resume_confirmed:payload.resume_confirmed===true});
 const id=claim.request.id,lease_token=claim.lease_token,agent_id=Number(claim.request.agent_id||0);
 let tabId=null;
 try{
  for(let count=0;count<5;count++){
   const step=await chatResearchApi('worker.step',{id,lease_token});
   if(!step.next)return step.request;
   const url=String(step.next.url||''),parsed=new URL(url);
   if(parsed.protocol!=='https:'||parsed.username||parsed.password||parsed.search||parsed.hash)throw new Error('The reviewed source URL is invalid.');
   // Permission prompts require an explicit click in the extension; an alarm never expands grants.
   if(!(await chrome.permissions.contains({origins:[parsed.origin+'/*']})))throw new Error('Review the research websites in Chrome’s Agent workspace to grant site access.');
   if(!(await connectionStillCurrent(context)))throw new Error('Chrome connection changed.');
   const tab=await chrome.tabs.create({url,active:false});if(!tab?.id)throw new Error('Chrome could not open the reviewed source.');
   tabId=tab.id;chatResearchTabs.set(tabId,url);
   const nav=await waitForGuardedNavigationV2220(tabId,step.next.domain,await sha256HexV2220(url),true);
   if(!nav.verified||nav.result_code!=='handoff_verified')throw new Error('Source redirected or did not load at the reviewed URL. Review it before retrying.');
   // Recheck current server authority immediately before capture.
   await chatResearchApi('worker.step',{id,lease_token});
   const page=await browserResearchCaptureV2230(tabId);
   if(page.url!==url||!(await connectionStillCurrent(context)))throw new Error('The reviewed source or connection changed.');
   await browserResearchApiV2230('analyze_page',{mission_id:step.request.mission.mission_id,agent_id,page,lease_token});
   chatResearchTabs.delete(tabId);await chrome.tabs.remove(tabId).catch(()=>{});tabId=null;
  }
  return (await chatResearchApi('worker.step',{id,lease_token})).request;
 }catch(error){
  // Leave interruption visible and require owner review before any retry.
  if(await connectionStillCurrent(context))await chatResearchApi('worker.step',{id,lease_token,failed:true}).catch(()=>{});
  throw error;
 }finally{
  if(tabId!==null){chatResearchTabs.delete(tabId);await chrome.tabs.remove(tabId).catch(()=>{});}
 }
}
async function chatResearchPoll(){
 if(chatResearchFlight)return;
 try{
  const list=await chatResearchApi('worker.list');
  const pending=(list.requests||[]).find(x=>x.status==='waiting_chrome');
  if(pending)await chatResearchRun({id:pending.id});
 }catch(_){} // The Chat canvas shows durable attention state; pairing remains intact.
}
