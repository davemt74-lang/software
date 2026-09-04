(() => {
  'use strict';

  const BUILD='stem-project-library-v158-20260829';
  const cfg=window.STONEFELLOW_STEM_STUDIO||{};
  const ROLE_ALIASES={
    drum:['drum','drums','percussion','rhythm','beat'],
    bass:['bass','bass guitar','synth bass'],
    vocal:['vocal','vocals','voice','lead vocal','background vocal']
  };
  const proof=window.STONEFELLOW_STEM_PROJECT_V158={build:BUILD,attempts:0,projectsCreated:0,libraryAdds:0,rollbacks:0,lastTrackId:0,lastRoles:[]};

  const clean=value=>String(value||'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
  function canonicalRole(value){
    const text=clean(value);
    for(const [role,aliases] of Object.entries(ROLE_ALIASES)){
      if(aliases.some(alias=>text===alias||text.split(' ').includes(alias)))return role;
    }
    return '';
  }
  function requestedRoles(values){
    const out=[];
    for(const value of Array.isArray(values)?values:[]){const role=canonicalRole(value);if(role&&!out.includes(role))out.push(role);}
    return out.slice(0,12);
  }
  function roleScore(card,role){
    const aliases=ROLE_ALIASES[role]||[];
    const declared=clean(card?.dataset?.libraryRole||card?.dataset?.libraryCategory||'');
    const name=clean(card?.dataset?.libraryName||'');
    const search=clean(card?.dataset?.librarySearch||'');
    if(canonicalRole(declared)===role)return 100;
    if(aliases.some(alias=>declared.includes(alias)))return 80;
    if(aliases.some(alias=>name.includes(alias)))return 50;
    if(aliases.some(alias=>search.split(' ').includes(alias)))return 20;
    return 0;
  }
  function selectRoles(values,cards=[...document.querySelectorAll('[data-library-card]')]){
    const roles=requestedRoles(values);const used=new Set();const selected=[];
    for(const role of roles){
      const candidates=cards.map((card,index)=>({card,index,id:Number(card?.dataset?.libraryStemId||0),score:roleScore(card,role)})).filter(item=>item.id>0&&!used.has(item.id)&&item.score>0).sort((a,b)=>b.score-a.score||a.index-b.index);
      const match=candidates[0];if(!match)return {ok:false,roles,selected,missing:role};
      used.add(match.id);selected.push({role,source_stem_id:match.id,name:String(match.card?.dataset?.libraryName||match.role)});
    }
    return {ok:true,roles,selected,missing:''};
  }
  async function request(action,fields={}){
    if(!cfg.projectEndpoint)throw new Error('Stem Studio project endpoint is unavailable.');
    const form=new FormData();form.append('csrf_token',String(cfg.csrf||''));form.append('action',String(action||''));
    for(const [key,value] of Object.entries(fields)){if(value!==undefined&&value!==null)form.append(key,String(value));}
    const response=await fetch(String(cfg.projectEndpoint),{method:'POST',credentials:'same-origin',body:form});
    const payload=await response.json().catch(()=>({ok:false,error:'Invalid Studio project response.'}));
    if(!response.ok||!payload?.ok)throw new Error(payload?.error||`Studio project request failed (${response.status}).`);
    return payload;
  }
  async function rollback(trackId){
    if(!(trackId>0))return false;
    try{await request('delete_project',{track_id:trackId});proof.rollbacks+=1;return true;}catch(error){return false;}
  }
  async function createProject(command={}){
    proof.attempts+=1;
    const selection=selectRoles(command.library_roles||[]);
    if(!selection.ok)return {status:'failed',verified:false,result:`No ${selection.missing} sample is available in the Track Library. No project was created.`,verification:'library-preflight'};
    const tempo=Math.max(40,Math.min(300,Number(command.tempo_bpm)||120));
    const name=String(command.project_name||'Untitled Project').trim().slice(0,190)||'Untitled Project';
    let trackId=0;
    try{
      const created=await request('create_project',{project_name:name,tempo_bpm:tempo,time_signature:String(command.time_signature||'4/4')});
      trackId=Number(created.track_id||0);
      if(!(trackId>0)||trackId===Number(cfg.trackId||0)||Number(created.tempo_bpm||0)!==tempo)throw new Error('The new Studio project was not verified.');
      proof.projectsCreated+=1;proof.lastTrackId=trackId;
      const added=[];
      for(const item of selection.selected){
        const result=await request('add_library_stem',{track_id:trackId,source_stem_id:item.source_stem_id,source_start:0});
        if(Number(result.source_stem_id||0)!==item.source_stem_id||!(Number(result.stem_id||0)>0))throw new Error(`The ${item.role} sample was not verified in the new project.`);
        added.push({...item,stem_id:Number(result.stem_id)});proof.libraryAdds+=1;
      }
      if(new Set(added.map(item=>item.source_stem_id)).size!==selection.roles.length)throw new Error('Track Library samples were not distinct.');
      proof.lastRoles=[...selection.roles];
      return {status:'success',verified:true,verification:'new-project-library-stems',result:`Created “${name}” at ${tempo} BPM with one ${selection.roles.join(', one ')} sample`,track_id:trackId,tempo_bpm:tempo,added,redirect:String(created.redirect||`/admin/stems.php?track=${trackId}`)};
    }catch(error){
      const removed=await rollback(trackId);
      const cleanup=trackId>0?(removed?' The incomplete project was removed.':' The incomplete draft could not be removed automatically.'):' No project was created.';
      return {status:'failed',verified:false,verification:'new-project-library-stems',result:`${String(error?.message||error||'Could not create the Studio project.')}${cleanup}`};
    }
  }

  window.StonefellowStemProjectAgentV158={build:BUILD,canonicalRole,selectRoles,createProject};
})();
