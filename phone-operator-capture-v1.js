/* Consent-gated, aligned phone audio PCM capture. Outputs are always silent. */
class VP3OperatorRemoteCaptureV1 extends AudioWorkletProcessor {
  constructor(options={}){
    super();this.both=options.processorOptions?.mode==='both';this.frame=new Int16Array(64000);
    this.localFrame=this.both?new Int16Array(64000):null;this.used=0;this.sum=0;this.localSum=0;this.weight=0;this.enabled=false;
    this.watching=false;this.observing=false;this.voiceSamples=0;this.activitySamples=0;
    this.port.onmessage=event=>{
      if(event.data==='start'||event.data==='watch'){this.reset();this.enabled=true;this.watching=event.data==='watch';}
      else if(event.data==='observe')this.observing=true;
      else if(event.data==='observe_off'){this.observing=false;this.voiceSamples=0;this.activitySamples=0;}
      else if(event.data==='stop'){this.enabled=false;this.observing=false;this.watching=false;this.reset();}
    };
  }
  reset(){this.used=0;this.sum=0;this.localSum=0;this.weight=0;this.voiceSamples=0;this.activitySamples=0;this.frame.fill(0);this.localFrame?.fill(0);}
  pcm(samples){const buffer=new ArrayBuffer(samples.length*2),view=new DataView(buffer);
    for(let i=0;i<samples.length;i++)view.setInt16(i*2,samples[i],true);return buffer;}
  quantize(value){value=Math.max(-1,Math.min(1,value));return Math.round(value*(value<0?32768:32767));}
  process(inputs,outputs){
    for(const output of outputs)for(const channel of output)channel.fill(0);
    if(!this.enabled||sampleRate<16000||sampleRate>96000)return true;
    const remote=inputs[0]?.[0],local=this.both?inputs[1]?.[0]:null;
    // A missing side must not be presented as a complete two-sided frame.
    if(!remote||(this.both&&(!local||local.length!==remote.length))){this.reset();return true;}
    if(this.observing){
      let remoteEnergy=0,localEnergy=0;
      for(let i=0;i<remote.length;i++){
        const r=Number.isFinite(remote[i])?Math.max(-1,Math.min(1,remote[i])):0;
        const l=local&&Number.isFinite(local[i])?Math.max(-1,Math.min(1,local[i])):0;
        remoteEnergy+=r*r;localEnergy+=l*l;
      }
      if(Math.max(remoteEnergy,localEnergy)/remote.length>=.0004){
        this.voiceSamples+=remote.length;this.activitySamples+=remote.length;
        if(this.voiceSamples>=sampleRate*.12&&this.activitySamples>=sampleRate*.1){this.port.postMessage({activity:true});this.activitySamples=0;}
      }else{this.voiceSamples=0;this.activitySamples=0;}
    }
    // During model/speech work only local activity metadata leaves this worklet.
    // No PCM is uploaded, retained as a backlog, or mixed into the human call.
    if(this.watching)return true;
    const ratio=sampleRate/16000;
    for(let i=0;i<remote.length;i++){
      let remain=1;
      while(remain>1e-9){
        const take=Math.min(remain,ratio-this.weight);
        this.sum+=(Number.isFinite(remote[i])?remote[i]:0)*take;
        if(this.both)this.localSum+=(Number.isFinite(local[i])?local[i]:0)*take;
        this.weight+=take;remain-=take;
        if(this.weight>=ratio-1e-9){
          this.frame[this.used]=this.quantize(this.sum/ratio);
          if(this.both)this.localFrame[this.used]=this.quantize(this.localSum/ratio);
          this.used++;this.weight=0;this.sum=0;this.localSum=0;
          if(this.used===this.frame.length){
            const remotePcm=this.pcm(this.frame);
            if(this.both){const localPcm=this.pcm(this.localFrame);this.port.postMessage({remote:remotePcm,local:localPcm},[remotePcm,localPcm]);}
            else this.port.postMessage(remotePcm,[remotePcm]);
            this.reset();
          }
        }
      }
    }
    return true;
  }
}
registerProcessor('vp3-operator-remote-capture-v1',VP3OperatorRemoteCaptureV1);
