/* Consent-gated remote call PCM capture. No network, recording, or mic access here. */
class VP3OperatorRemoteCaptureV1 extends AudioWorkletProcessor {
  constructor(){
    super();this.frame=new Int16Array(64000);this.used=0;this.sum=0;this.weight=0;this.enabled=false;
    this.port.onmessage=event=>{if(event.data==='start'){this.used=0;this.sum=0;this.weight=0;this.enabled=true;}else if(event.data==='stop'){this.enabled=false;this.used=0;this.sum=0;this.weight=0;}};
  }
  process(inputs,outputs){
    for(const output of outputs)for(const channel of output)channel.fill(0);
    const samples=inputs[0]?.[0];
    if(!this.enabled||!samples||sampleRate<16000||sampleRate>96000)return true;
    // Integrate the input sample over each output sample period. This avoids
    // unbounded queues and supports 44.1/48 kHz hardware as well as 16 kHz.
    const ratio=sampleRate/16000;
    for(const sample of samples){
      let remain=1;
      while(remain>1e-9){
        const take=Math.min(remain,ratio-this.weight);
        this.sum+=(Number.isFinite(sample)?sample:0)*take;this.weight+=take;remain-=take;
        if(this.weight>=ratio-1e-9){
          const value=Math.max(-1,Math.min(1,this.sum/ratio));
          this.frame[this.used++]=Math.round(value*(value<0?32768:32767));
          this.weight=0;this.sum=0;
          if(this.used===this.frame.length){
            const bytes=new ArrayBuffer(this.frame.length*2),view=new DataView(bytes);
            for(let i=0;i<this.frame.length;i++)view.setInt16(i*2,this.frame[i],true);
            this.port.postMessage(bytes,[bytes]);this.used=0;
          }
        }
      }
    }
    return true;
  }
}
registerProcessor('vp3-operator-remote-capture-v1',VP3OperatorRemoteCaptureV1);
