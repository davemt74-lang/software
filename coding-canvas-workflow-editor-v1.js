(() => {
  'use strict';
  function mount({root,context,api,preview,onData,onJob,onStop,onChanged,onReveal}) {
    const node=(tag,text='',cls='')=>{const el=document.createElement(tag);el.textContent=text;el.className=cls;return el;};
    const button=(text,fn)=>{const el=node('button',text);el.type='button';el.addEventListener('click',fn);return el;};
    const field=(title,type='text')=>{const label=node('label',title);const input=node('input');input.type=type;label.append(input);return {label,input};};
    const box=node('section','','vp3-coding-workflow');box.append(node('h4','Workflow checks'),node('p','Test preview uses disposable sample data. Test & repair allows one Coding Agent run if a check fails.','vp3-coding-canvas-description'));
    const list=node('div','','vp3-coding-workflow-list'),status=node('p','','vp3-coding-canvas-description');status.setAttribute('role','status');
    const controls=node('div','','vp3-coding-canvas-controls');
    const record=button('Record a workflow',()=>startRecording());
    const cancelButton=button('Stop check',()=>{const waiting=cycle?.phase==='waiting';cancel();if(waiting)onStop();setStatus('Check stopped. The working version was kept.');});
    const builder=node('section','','vp3-coding-workflow-builder');builder.hidden=true;
    const name=field('Check name'),expected=field('Expected visible text'),persist=field('Must survive reloading the app','checkbox');
    name.input.maxLength=80;expected.input.maxLength=300;
    const steps=node('p','','vp3-coding-canvas-description');
    const save=button('Save workflow check',()=>void saveRecording());
    const discard=button('Cancel recording',()=>{cancel();setStatus('Recording discarded.');});
    builder.append(name.label,expected.label,persist.label,steps,save,discard);
    controls.append(record,cancelButton);box.append(list,controls,builder,status);root.append(box);
    let doc=null,cycle=null,recording=null,pendingFrame=null,saving=null,transition=null,generation=0,message='';
    const key=c=>c?[c.owner,c.agent,c.conversation,c.epoch,c.canvasId,c.revision,c.sourceHash,c.stateRevision].join(':'):'';
    const same=c=>Boolean(c&&key(context())===key(c)&&context()?.visible);
    const busy=()=>Boolean(recording||saving||cycle&&!['waiting','check_recover','repair_recover'].includes(cycle.phase));
    function setStatus(text){message=text;status.textContent=text;}
    function refresh(){
      const c=context(),available=c?.editable&&c?.ready;
      box.hidden=!c?.canvasId;
      if(!busy())list.replaceChildren(...(c?.checks??[]).map((check,index)=>{
        const row=node('div','','vp3-coding-workflow-row');row.append(node('strong',check.name),node('p',check.steps.length+' actions · '+(check.persist?'checks saved data after reload':'temporary test data'),'vp3-coding-canvas-description'));
        const remove=button('Remove '+check.name,()=>void saveChecks((context()?.checks??[]).filter((_,i)=>i!==index)));remove.disabled=!available||c.blocked||Boolean(cycle);row.append(remove);return row;
      }));
      record.disabled=!available||c.blocked||Boolean(cycle)||busy()||(c.checks?.length??0)>=3;
      cancelButton.hidden=!cycle;cancelButton.textContent=cycle?.phase==='waiting'?'Stop repair':'Stop check';
      builder.hidden=!recording&&!saving;
      for(const input of [name.input,expected.input,persist.input])input.disabled=Boolean(saving);
      save.disabled=Boolean(saving)||!recording?.steps.length||!name.input.value.trim()||!expected.input.value.trim();
      if(saving?.uncertain){save.disabled=false;save.textContent='Recover saved workflow';}else save.textContent='Save workflow check';
      steps.textContent=recording?recording.steps.length+' / 12 recorded actions. Use the sample app, then enter the expected result.':'';
      if(!c?.ready)status.textContent='Run Database Upgrade to enable saved workflow checks.';
      else if(!message){const report=c.report;
        status.textContent=report?.status==='passed'?'Saved workflow checks passed for this version.':report?.status==='failed'?'A saved workflow check failed. Review the check or choose Test & repair.':c.checks?.length?'Test the listed workflows with empty app data and sample connected sources.':'No workflow checks are saved. Record the app’s main task to test its behavior.';}
    }
    for(const input of [name.input,expected.input])input.addEventListener('input',refresh);
    function rejectFrame(error){if(pendingFrame){clearTimeout(pendingFrame.timer);const old=pendingFrame;pendingFrame=null;old.reject(error);}}
    function cancel(){generation++;cycle=null;recording=null;saving=null;transition=null;rejectFrame(new DOMException('Workflow context changed.','AbortError'));builder.hidden=true;refresh();onChanged();}
    function guard(c,turn){if(turn!==generation||!same(c))throw new DOMException('Workflow context changed.','AbortError');}
    function bind(){
      const c=context();
      if(doc&&key(doc)!==key(c)){
        if(transition&&c?.canvasId===transition.canvas&&c.revision===transition.revision&&c.owner===doc.owner&&c.agent===doc.agent&&c.conversation===doc.conversation
            &&JSON.stringify(c.checks)===transition.checks&&cycle?.phase==='waiting'){
          cycle.context=c;cycle.phase='retest';transition=null;
        }else cancel();
        message='';
      }
      doc=c;refresh();
    }
    async function saveChecks(checks){
      const c=context();if(!c?.editable||!c.ready||c.blocked||cycle)return;
      const turn=generation;
      if(!saving)saving={context:c,id:crypto.randomUUID(),checks};
      const attempt=saving;attempt.uncertain=false;refresh();onChanged();
      try{
        const data=await api('save_checks',{canvas_id:c.canvasId,revision:c.revision,source_hash:c.sourceHash,request_id:attempt.id,
          expected_active_revision:c.expectedActive,expected_draft_revision:c.expectedDraft,workflow_checks:attempt.checks},c.conversation);
        guard(c,turn);
        if(data.canvas?.id!==c.canvasId||!data.revision||JSON.stringify(data.revision.workflow_checks)!==JSON.stringify(attempt.checks))throw new Error('The saved workflow could not be verified.');
        saving=null;recording=null;onData(data);setStatus('Workflow check saved in version '+data.revision.id+'. Choose Test preview or Test & repair.');
      }catch(error){
        if(turn!==generation)return;
        if(error.name!=='AbortError'){
          if([401,403,404,409,413,419,422].includes(error.status))saving=null;else attempt.uncertain=true;
          setStatus(error.message+(attempt.uncertain?' Recover the same saved request before continuing.':''));
        }
      }finally{if(turn===generation){refresh();onChanged();}}
    }
    function startRecording(){
      const c=context();if(!c?.editable||!c.ready||c.blocked||busy()||cycle||(c.checks?.length??0)>=3)return;
      generation++;recording={context:c,steps:[]};message='Recording with empty app data and sample sources. The working app is kept.';
      name.input.value='';expected.input.value='';persist.input.checked=false;
      onReveal();preview({mode:'record',state:{}});refresh();onChanged();name.input.focus();
    }
    async function saveRecording(){
      if(saving?.uncertain){await saveChecks(saving.checks);return;}
      if(!recording?.steps.length||!same(recording.context))return;
      const check={name:name.input.value.trim(),steps:structuredClone(recording.steps),expect:{kind:'text',selector:'@app',value:expected.input.value.trim(),match:'contains'},persist:persist.input.checked};
      await saveChecks([...(context().checks??[]),check]);
    }
    function frameRun(c,check,state,verifyOnly=false){
      return new Promise((resolve,reject)=>{
        rejectFrame(new DOMException('Next workflow check.','AbortError'));
        const runId=crypto.randomUUID();
        const wait={resolve,reject,candidate:null,check,runId,verifyOnly,timer:setTimeout(()=>{
          if(pendingFrame===wait){pendingFrame=null;resolve({passed:false,reason:'timeout',step:0});}
        },12000)};
        pendingFrame=wait;wait.candidate=preview({mode:'check',state,verifyOnly});
        if(!wait.candidate){clearTimeout(wait.timer);pendingFrame=null;reject(new DOMException('Preview unavailable.','AbortError'));}
      });
    }
    async function execute(c,turn){
      let passed=0,emailChecked=!c.emailSource;
      for(const check of c.checks){
        guard(c,turn);setStatus('Checking '+check.name+'…');
        let result=await frameRun(c,check,{});guard(c,turn);
        emailChecked=emailChecked||result.emailChecked;
        if(result.passed&&check.persist){result=await frameRun(c,check,result.sample_state??{},true);guard(c,turn);emailChecked=emailChecked||result.emailChecked;}
        if(!result.passed)return {passed,failed_case:passed,step:result.step||0,reason:result.reason,email_fixture_checked:Boolean(emailChecked)};
        passed++;
      }
      return {passed,failed_case:null,step:0,reason:'none',email_fixture_checked:Boolean(emailChecked)};
    }
    async function start(allowRepair=false){
      const c=context();if(!c?.editable||!c.ready||!c.canTest||c.blocked||recording||saving||!c.checks?.length)return;
      if(cycle?.phase==='waiting')return;
      const turn=generation;
      if(!cycle)cycle={context:c,request:crypto.randomUUID(),repairRequest:crypto.randomUUID(),allowRepair,phase:'begin',repairUsed:false};
      const attempt=cycle;refresh();onChanged();onReveal();
      try{
        let data;
        if(!['repair','repair_recover'].includes(attempt.phase)){
          data=await api('begin_check',{canvas_id:c.canvasId,revision:c.revision,source_hash:c.sourceHash,state_revision:c.stateRevision,
            request_id:attempt.request,...(c.imageToken?{expected_image:c.imageToken}:{})},c.conversation);
          guard(c,turn);onData(data);
          if(data.revision?.workflow_result?.status==='running'){
            attempt.phase='running';const result=await execute(c,turn);guard(c,turn);attempt.result=result;attempt.phase='finish';
            data=await api('finish_check',{canvas_id:c.canvasId,revision:c.revision,request_id:attempt.request,result},c.conversation);
            guard(c,turn);onData(data);
          }
          if(data.revision?.workflow_result?.status==='passed'){
            cycle=null;setStatus('All '+c.checks.length+' saved workflow checks passed. Review the app, then choose Use this version.');return;
          }
          if(!attempt.allowRepair||attempt.repairUsed){cycle=null;setStatus('A workflow check failed. Review the saved check and source. The working app was kept.');return;}
          attempt.phase='repair';
        }
        setStatus('Preparing one repair attempt for the failed workflow…');
        const response=await api('check_repair',{canvas_id:c.canvasId,revision:c.revision,request_id:attempt.request,repair_request_id:attempt.repairRequest},c.conversation);
        guard(c,turn);
        if(!Number.isSafeInteger(response.job?.run_id)||response.job.canvas_id!==c.canvasId)throw new Error('The workflow repair could not be verified.');
        attempt.runId=response.job.run_id;attempt.repairUsed=true;attempt.phase='waiting';
        setStatus('One repair is running. Its new draft will be retested; the working version is kept.');await onJob(response.job);
      }catch(error){if(turn===generation){
        if(error.name==='AbortError'||[401,403,404,409,419,422].includes(error.status))cycle=null;
        else if(cycle)cycle.phase=['repair','repair_recover'].includes(attempt.phase)?'repair_recover':'check_recover';
        setStatus(error.message+(cycle?' Choose Test preview or Test & repair to recover this same request.':''));
      }}
      finally{if(turn===generation){refresh();onChanged();}}
    }
    function messageFromFrame(candidate,data){
      if(recording&&candidate.workflowMode==='record'&&same(recording.context)){
        if(data.kind==='workflow_record'&&Array.isArray(data.steps)&&data.steps.length<=12){recording.steps=data.steps;refresh();}
        if(data.kind==='workflow_record_limit')setStatus('Twelve actions recorded. Save this check or record a smaller workflow.');
      }
      const waiting=pendingFrame;
      if(!waiting||waiting.candidate!==candidate)return;
      if(data.kind==='ready'){
        waiting.emailChecked=data.emailWorkflowChecked===true;
        candidate.element.contentWindow.postMessage({channel:candidate.channel,nonce:candidate.nonce,kind:'workflow_run',run_id:waiting.runId,check:waiting.check,verify_only:waiting.verifyOnly},'*');
      }
      if(data.kind==='workflow_progress'&&data.run_id===waiting.runId)setStatus('Checking '+waiting.check.name+' · action '+data.step+'…');
      if(data.kind==='workflow_done'&&data.run_id===waiting.runId){
        if(typeof data.passed!=='boolean'||!Number.isSafeInteger(data.step))return;
        clearTimeout(waiting.timer);pendingFrame=null;waiting.resolve({...data,emailChecked:waiting.emailChecked});
      }
    }
    return Object.freeze({bind,start,busy,cancel,
      update(){const c=context();if((cycle||recording||saving)&&doc&&(!c?.visible||key(doc)!==key(c))&&!transition)cancel();refresh();},
      message:messageFromFrame,
      fault(candidate,reason){if(pendingFrame?.candidate===candidate){const w=pendingFrame;pendingFrame=null;clearTimeout(w.timer);w.resolve({passed:false,step:0,reason,emailChecked:false});}else if(recording)setStatus('The sample preview failed: '+reason+'. Repair the source before recording.');},
      prepareJobResult(job){
        if(cycle?.phase!=='waiting'||cycle.runId!==job.run_id||!same(cycle.context))return false;
        transition={canvas:job.canvas_id,revision:job.revision,checks:JSON.stringify(cycle.context.checks)};return true;
      },
      async retest(job){
        if(cycle?.runId!==job.run_id||cycle.phase!=='retest')return;
        cycle=null;setStatus('Retesting the repaired draft once…');await start(false);
      },
      jobStopped(job){if(cycle?.runId===job.run_id&&job.state!=='ready'&&!['queued','running','paused'].includes(job.state)){cycle=null;setStatus('Repair '+job.state+'. Review its status before continuing.');refresh();}},
    });
  }
  window.VP3CanvasWorkflowEditorV1=Object.freeze({mount});
})();
