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
    }
    get snapshot(){return {phase:this.phase,message:this.message,mode:this.mode,decisions:this.decisions,responses:this.responses};}
    alive(){return this.phase!=='closed'&&this.options.current()&&this.now()<this.deadline;}
    change(phase,message=''){this.phase=phase;this.message=message;this.options.changed?.(this.snapshot);}
    later(fn,ms){const id=this.set(()=>{this.timers.delete(id);fn();},Math.max(0,ms));this.timers.add(id);return id;}
    cancel(id){if(id!==null&&id!==undefined){this.clear(id);this.timers.delete(id);}}
    async start(mode='addressed'){
      if(this.phase!=='off'||!this.alive()||!['addressed','helpful'].includes(mode))return;
      this.mode=mode;this.change('starting','Adding Agent to this conversation…');
      this.options.capture('watch');
      try{
        const grant=await this.options.request('participation_start',{consent_attested:true,mode});
        if(!this.alive()){
          if(hash(grant?.participation_token))void this.options.revoke(grant.participation_token);
          return;
        }
        if(hash(grant?.participation_token))this.token=grant.participation_token;
        if(grant.call_id!==this.options.callId||!hash(grant.participation_token)||grant.mode!==mode||grant.state!=='listening'
          ||grant.max_decisions!==4||grant.max_responses!==2||grant.minimum_gap!==8||!Number.isSafeInteger(grant.expires_epoch))
          throw Error('The Agent conversation permission could not be verified.');
        if(!Number.isInteger(grant.decisions_used)||grant.decisions_used<0||grant.decisions_used>=4||!Number.isInteger(grant.responses_used)||grant.responses_used<0||grant.responses_used>=2)
          throw Error('The Agent conversation allowance could not be verified.');
        this.decisions=grant.decisions_used;this.responses=grant.responses_used;
        this.token=grant.participation_token;this.deadline=Math.min(this.deadline,grant.expires_epoch*1000,this.now()+60000);
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
      },2000);
    }
    listen(message='Agent is listening. Say Agent or VP3 to ask a question.'){
      if(!this.alive()){this.stop('Agent conversation permission ended.');return;}
      if(this.decisions>=4||this.responses>=2){this.stop('Agent conversation allowance ended. The human call continues.');return;}
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
      if(!this.alive()||this.phase!=='listening'||!this.token)return;
      const seq=this.receipts.at(-1)?.seq||0;if(seq<=this.lastSeq)return;
      if(this.mode==='addressed'&&!this.receipts.some(item=>item.seq===seq&&/\b(?:vp3|agent|assistant)\b/i.test(item.text)))return;
      const delay=Math.max(this.nextAt-this.now(),900-(this.now()-this.lastActivity));
      if(delay>0){this.pumpTimer=this.later(()=>this.pump(),delay);return;}
      void this.decide(seq);
    }
    async decide(seq){
      if(!this.alive()||this.phase!=='listening'||this.decisions>=4)return;
      this.lastSeq=seq;this.decisions++;this.nextAt=this.now()+8000;
      const version=this.activityVersion,receipts=this.receipts.map(item=>({...item}));
      this.change('deciding','Agent is considering a response…');this.options.capture('watch');
      try{
        const decision=await this.options.request('participation_decide',{participation_token:this.token,receipts,previous:this.previous});
        if(!this.alive())return;
        if(decision.call_id!==this.options.callId||decision.based_on_seq!==seq||!['wait','speak','stopped'].includes(decision.state))
          throw Error('The Agent response decision could not be verified.');
        if(decision.state==='stopped'){this.stop('Agent removed at a participant’s request. The human call and captions continue.');return;}
        if(decision.state==='wait'||version!==this.activityVersion){this.listen();return;}
        if(typeof decision.text!=='string'||!decision.text.trim()||Array.from(decision.text).length>180||/[\p{Cc}\p{Cf}<>]/u.test(decision.text)||!hash(decision.proof))
          throw Error('The Agent response could not be verified.');
        await this.prepare({seq,text:decision.text,proof:decision.proof,version,finishing:false,pcm:null,token:''});
      }catch(error){this.stop(error.message||'The Agent conversation stopped.');}
    }
    async prepare(turn){
      this.turn=turn;this.change('preparing','Preparing Agent speech…');
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
      this.responses++;this.previous.push({seq:turn.seq,text:turn.text,proof:turn.proof});
      this.change('speaking','Agent is speaking. Either person can interrupt.');
      this.options.play(turn.pcm,()=>void this.finish(turn,false));turn.pcm.fill(0);turn.pcm=null;
      turn.timer=this.later(()=>{if(this.turn===turn){this.options.stopVoice();void this.finish(turn,true);}},claim.expires_epoch*1000-this.now());
    }
    async finish(turn,interrupted){
      if(!this.alive()||this.turn!==turn||turn.finishing)return;
      turn.finishing=true;this.options.stopVoice();this.cancel(turn.timer);turn.pcm?.fill(0);turn.pcm=null;
      this.change('finishing',interrupted?'Agent paused for you.':'Agent response finished.');
      try{
        const result=await this.options.request('voice_finish',{source_seq:turn.seq,turn_token:turn.token});
        if(!this.alive()||this.turn!==turn)return;
        if(result.call_id!==this.options.callId||result.source_seq!==turn.seq||result.state!=='closed')throw Error('Agent speech closure could not be verified.');
        this.turn=null;this.listen(interrupted?'Agent paused for you and is listening.':undefined);
      }catch(error){this.stop(error.message||'The Agent conversation stopped.');}
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
