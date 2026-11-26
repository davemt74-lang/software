/* Consent-gated, aligned phone audio PCM capture. Outputs are always silent. */
class VP3OperatorRemoteCaptureV1 extends AudioWorkletProcessor {
  constructor(options={}){
    super();this.both=options.processorOptions?.mode==='both';this.frame=new Int16Array(64000);
    this.localFrame=this.both?new Int16Array(64000):null;this.used=0;this.sum=0;this.localSum=0;this.weight=0;this.enabled=false;
    this.port.onmessage=event=>{if(event.data==='start'){this.reset();this.enabled=true;}else if(event.data==='stop'){this.enabled=false;this.reset();}};
  }
  reset(){this.used=0;this.sum=0;this.localSum=0;this.weight=0;this.frame.fill(0);this.localFrame?.fill(0);}
  pcm(samples){const buffer=new ArrayBuffer(samples.length*2),view=new DataView(buffer);
    for(let i=0;i<samples.length;i++)view.setInt16(i*2,samples[i],true);return buffer;}
  quantize(value){value=Math.max(-1,Math.min(1,value));return Math.round(value*(value<0?32768:32767));}
  process(inputs,outputs){
    for(const output of outputs)for(const channel of output)channel.fill(0);
    if(!this.enabled||sampleRate<16000||sampleRate>96000)return true;
    const remote=inputs[0]?.[0],local=this.both?inputs[1]?.[0]:null;
    // A missing side must not be presented as a complete two-sided frame.
    if(!remote||(this.both&&(!local||local.length!==remote.length))){this.reset();return true;}
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
