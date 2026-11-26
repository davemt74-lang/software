/* One explicitly enabled, bounded Agent conversation on an existing human call. */
(() => {
  'use strict';
  const prefix='This is the VP3 AI assistant. ';
  const hash=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);
  class Conversation {
    constructor(options){
      this.options=options;this.now=options.now||Date.now;
      this.set=options.setTimeout||((fn,ms)=>setTimeout(fn,ms));this.clear=options.clearTimeout||(id=>clearTimeout(id));
      this.phase='off';this.message='';this.token='';this.mode='addressed';this.deadline=options.deadline;
      this.decisions=0;this.responses=0;this.lastSeq=0;this.nextAt=0;this.lastActivity=this.now();this.activityVersion=0;
      this.receipts=[];this.previous=[];this.turn=null;this.timers=new Set();this.pumpTimer=null;
      this.maxDecisions=4;this.maxResponses=2;this.hourDecisions=8;this.hourResponses=8;this.revision=0;this.controls=false;this.pending=0;
    }
    get snapshot(){return {phase:this.phase,message:this.message,mode:this.mode,decisions:this.decisions,responses:this.responses,
      max_decisions:this.maxDecisions,max_responses:this.maxResponses,checks_remaining:Math.max(0,Math.min(this.maxDecisions-this.decisions,this.hourDecisions)),
      responses_remaining:Math.max(0,Math.min(this.maxResponses-this.responses,this.hourResponses)),seconds_remaining:Math.max(0,Math.ceil((this.deadline-this.now())/1000)),
      controls:this.controls,pending:this.pending>0};}
    suspended(){return ['pausing','paused','resuming'].includes(this.phase);}
    balance(result,initial=false){
      if(!Number.isInteger(result.max_decisions)||result.max_decisions<1||result.max_decisions>8
        ||!Number.isInteger(result.max_responses)||result.max_responses<1||result.max_responses>4
        ||!Number.isInteger(result.decisions_used)||result.decisions_used<0||result.decisions_used>result.max_decisions
        ||!Number.isInteger(result.responses_used)||result.responses_used<0||result.responses_used>result.max_responses
        ||(!initial&&(result.max_decisions!==this.maxDecisions||result.max_responses!==this.maxResponses)))
        throw Error('The Agent conversation allowance could not be verified.');
      for(const key of ['hour_decisions_remaining','hour_responses_remaining'])if(key in result&&(!Number.isInteger(result[key])||result[key]<0||result[key]>8))
        throw Error('The hourly conversation allowance could not be verified.');
      this.maxDecisions=result.max_decisions;this.maxResponses=result.max_responses;this.decisions=result.decisions_used;this.responses=result.responses_used;
      this.hourDecisions=result.hour_decisions_remaining??8;this.hourResponses=result.hour_responses_remaining??8;
    }
    settled(){
      this.pending--;if(this.phase==='paused'&&!this.pending)this.options.capture('listen');
      this.options.changed?.(this.snapshot);if(this.phase==='listening')this.pump();
    }
    alive(){return this.phase!=='closed'&&this.options.current()&&this.now()<this.deadline;}
    change(phase,message=''){this.phase=phase;this.message=message;this.options.changed?.(this.snapshot);}
    later(fn,ms){const id=this.set(()=>{this.timers.delete(id);fn();},Math.max(0,ms));this.timers.add(id);return id;}
    cancel(id){if(id!==null&&id!==undefined){this.clear(id);this.timers.delete(id);}}
    async start(mode='addressed',limits={}){
      if(this.phase!=='off'||!this.alive()||!['addressed','helpful'].includes(mode))return;
      this.mode=mode;this.change('starting','Adding Agent to this conversation…');
      this.options.capture('watch');
      try{
        const grant=await this.options.request('participation_start',{consent_attested:true,mode,...limits});
        if(!this.alive()){
          if(hash(grant?.participation_token))void this.options.revoke(grant.participation_token);
          return;
        }
        if(hash(grant?.participation_token))this.token=grant.participation_token;
        if(grant.call_id!==this.options.callId||!hash(grant.participation_token)||grant.mode!==mode||grant.state!=='listening'
          ||grant.minimum_gap!==8||!Number.isSafeInteger(grant.expires_epoch)
          ||grant.max_decisions!==(limits.max_decisions??4)||grant.max_responses!==(limits.max_responses??2))
          throw Error('The Agent conversation permission could not be verified.');
        this.balance(grant,true);this.controls=grant.control_revision===0;
        this.token=grant.participation_token;this.grantDeadline=grant.expires_epoch*1000;this.deadline=Math.min(this.deadline,this.grantDeadline,this.now()+120000);
        if(this.deadline<=this.now())throw Error('The Agent conversation permission expired.');
        this.options.capture('observe');
        this.later(()=>this.stop('Agent conversation allowance ended. The human call continues.'),this.deadline-this.now());
        this.heartbeat();this.listen();
      }catch(error){this.stop(error.message||'The Agent conversation could not start.');}
    }
    heartbeat(){
      this.later(()=>{
        if(!this.alive()){this.stop('Agent conversation permission ended. The human call continues.');return;}
        this.options.refresh?.();this.heartbeat();
        this.options.changed?.(this.snapshot);
      },2000);
    }
    listen(message='Agent is listening. Say Agent or VP3 to ask a question.'){
      if(!this.alive()){this.stop('Agent conversation permission ended.');return;}
      if(!this.snapshot.checks_remaining||!this.snapshot.responses_remaining){this.stop('Agent conversation allowance ended. The human call continues.');return;}
      this.change('listening',message);this.options.capture('listen');this.pump();
    }
    observe(receipts){
      if(!this.alive()||!this.token||!Array.isArray(receipts))return;
      this.receipts=receipts.slice(-8).map(item=>({...item}));
      const seq=this.receipts.at(-1)?.seq;
      if(this.receipts.some(item=>item.seq===seq&&typeof item.text==='string'&&/^(?:hey[, ]+)?(?:vp3|agent|assistant)[,.:]?\s+(?:please\s+)?(?:stop|leave(?:\s+the\s+call)?|be\s+quiet)\b/i.test(item.text.trim()))){
        this.stop('Agent removed at a participant’s request. The human call and captions continue.');return;
      }
      this.pump();
    }
    activity(){
      if(!this.alive()||!this.token)return;
      this.lastActivity=this.now();this.activityVersion++;
      if(this.phase==='speaking'&&this.turn){
        // Interrupt locally before waiting for a network receipt.
        this.options.stopVoice();void this.finish(this.turn,true);
      }else if(this.phase==='listening')this.pump();
    }
    pump(){
      this.cancel(this.pumpTimer);this.pumpTimer=null;
      if(!this.alive()||this.phase!=='listening'||!this.token||this.pending)return;
      const seq=this.receipts.at(-1)?.seq||0;if(seq<=this.lastSeq)return;
      if(this.mode==='addressed'&&!this.receipts.some(item=>item.seq===seq&&/\b(?:vp3|agent|assistant)\b/i.test(item.text)))return;
      const delay=Math.max(this.nextAt-this.now(),900-(this.now()-this.lastActivity));
      if(delay>0){this.pumpTimer=this.later(()=>this.pump(),delay);return;}
      void this.decide(seq);
    }
    async decide(seq){
      if(!this.alive()||this.phase!=='listening'||!this.snapshot.checks_remaining)return;
      this.lastSeq=seq;this.decisions++;this.hourDecisions--;this.nextAt=this.now()+8000;this.pending++;
      const version=this.activityVersion,receipts=this.receipts.map(item=>({...item}));
      this.change('deciding','Agent is considering a response…');this.options.capture('watch');
      try{
        const decision=await this.options.request('participation_decide',{participation_token:this.token,receipts,previous:this.previous});
        if(!this.alive()||this.suspended())return;
        if(decision.call_id!==this.options.callId||decision.based_on_seq!==seq||!['wait','speak','stopped'].includes(decision.state))
          throw Error('The Agent response decision could not be verified.');
        if(decision.state==='stopped'){this.stop('Agent removed at a participant’s request. The human call and captions continue.');return;}
        if(decision.state==='wait'||version!==this.activityVersion){this.listen();return;}
        if(typeof decision.text!=='string'||!decision.text.trim()||Array.from(decision.text).length>180||/[\p{Cc}\p{Cf}<>]/u.test(decision.text)||!hash(decision.proof))
          throw Error('The Agent response could not be verified.');
        await this.prepare({seq,text:decision.text,proof:decision.proof,version,finishing:false,pcm:null,token:''});
      }catch(error){if(!this.suspended())this.stop(error.message||'The Agent conversation stopped.');}
      finally{this.settled();}
    }
    async prepare(turn){
      this.turn=turn;this.responses++;this.hourResponses--;this.change('preparing','Preparing Agent speech…');
      const result=await this.options.request('voice_prepare',{participation_token:this.token,source_seq:turn.seq,
        suggestion:turn.text,proof:turn.proof,voice_consent_attested:false});
      if(!this.alive()||this.turn!==turn)return;
      if(result.call_id!==this.options.callId||result.source_seq!==turn.seq||result.script!==prefix+turn.text||result.state!=='ready'
        ||result.sample_rate!==16000||!hash(result.turn_token)||!Number.isSafeInteger(result.expires_epoch)||result.expires_epoch*1000<=this.now())
        throw Error('The Agent speech permission could not be verified.');
      turn.token=result.turn_token;turn.expires=result.expires_epoch*1000;turn.samples=result.samples;
      turn.pcm=this.options.decode(result.pcm,result.samples);
      if(turn.version!==this.activityVersion){await this.finish(turn,true);return;}
      const claim=await this.options.request('voice_claim',{participation_token:this.token,source_seq:turn.seq,turn_token:turn.token});
      if(!this.alive()||this.turn!==turn)return;
      if(claim.call_id!==this.options.callId||claim.source_seq!==turn.seq||claim.samples!==turn.samples||claim.state!=='playing'
        ||!Number.isSafeInteger(claim.start_by_epoch)||!Number.isSafeInteger(claim.expires_epoch)
        ||this.now()>claim.start_by_epoch*1000||this.now()+turn.samples/16>claim.expires_epoch*1000)
        throw Error('The Agent playback permission expired.');
      if(turn.version!==this.activityVersion||this.now()-this.lastActivity<900){await this.finish(turn,true);return;}
      this.previous.push({seq:turn.seq,text:turn.text,proof:turn.proof});
      this.change('speaking','Agent is speaking. Either person can interrupt.');
      this.options.play(turn.pcm,()=>void this.finish(turn,false));turn.pcm.fill(0);turn.pcm=null;
      turn.timer=this.later(()=>{if(this.turn===turn){this.options.stopVoice();void this.finish(turn,true);}},claim.expires_epoch*1000-this.now());
    }
    async finish(turn,interrupted){
      if(!this.alive()||this.turn!==turn||turn.finishing)return;
      turn.finishing=true;this.pending++;this.options.stopVoice();this.cancel(turn.timer);turn.pcm?.fill(0);turn.pcm=null;
      this.change('finishing',interrupted?'Agent paused for you.':'Agent response finished.');
      try{
        const result=await this.options.request('voice_finish',{source_seq:turn.seq,turn_token:turn.token});
        if(!this.alive()||this.turn!==turn)return;
        if(result.call_id!==this.options.callId||result.source_seq!==turn.seq||result.state!=='closed')throw Error('Agent speech closure could not be verified.');
        this.turn=null;this.listen(interrupted?'Agent paused for you and is listening.':undefined);
      }catch(error){if(!this.suspended())this.stop(error.message||'The Agent conversation stopped.');}
      finally{this.settled();}
    }
    async pause(){
      if(!this.alive()||!this.controls||!this.token||this.suspended()||['off','starting'].includes(this.phase))return;
      this.options.stopVoice();this.cancel(this.pumpTimer);this.cancel(this.turn?.timer);this.activityVersion++;
      this.turn?.pcm?.fill(0);this.turn=null;this.receipts=[];
      this.change('pausing','Pausing Agent…');this.options.capture('watch');
      try{
        const result=await this.options.request('participation_pause',{participation_token:this.token,expected_revision:this.revision});
        if(!this.alive())return;
        this.controlResult(result,'paused');this.change('paused','Agent paused. Captions and the session clock continue.');
        if(!this.pending)this.options.capture('listen');
      }catch(error){this.stop(error.message||'The Agent pause could not be verified.');}
    }
    controlResult(result,state){
      if(result.call_id!==this.options.callId||result.state!==state||result.control_revision!==this.revision+1
        ||!Number.isSafeInteger(result.expires_epoch)||result.expires_epoch*1000!==this.grantDeadline
        ||!Number.isInteger(result.resume_seq)||result.resume_seq<0||result.resume_seq>30)
        throw Error('The Agent conversation controls could not be verified.');
      this.balance(result);this.revision=result.control_revision;this.lastSeq=Math.max(this.lastSeq,result.resume_seq);
    }
    async resume(){
      if(!this.alive()||this.phase!=='paused'||this.pending||this.options.canResume?.()===false)return;
      this.change('resuming','Resuming Agent…');this.options.capture('watch');
      try{
        const result=await this.options.request('participation_resume',{participation_token:this.token,expected_revision:this.revision});
        if(!this.alive())return;
        this.controlResult(result,'listening');this.receipts=[];this.lastActivity=this.now();this.activityVersion++;
        this.listen('Agent resumed. Ask a new question as Agent or VP3.');
      }catch(error){this.stop(error.message||'The Agent resume could not be verified.');}
    }
    stop(message='Agent removed. The human call and captions continue.'){
      if(this.phase==='closed')return;
      this.options.stopVoice();for(const id of this.timers)this.clear(id);this.timers.clear();
      this.turn?.pcm?.fill(0);this.turn=null;this.receipts=[];this.previous=[];
      const token=this.token;this.token='';this.change('closed',message);this.options.capture('off');
      if(token)void this.options.revoke(token);
    }
  }
  window.VP3OperatorParticipationV1=Object.freeze({create:options=>new Conversation(options)});
})();
