/* Local greeting capture only: bounded 16 kHz mono PCM, silent output, no network. */
class VP3VoicemailGreetingCaptureV1 extends AudioWorkletProcessor {
  constructor(){super();this.frame=new Int16Array(1600);this.used=0;this.sum=0;this.weight=0;this.total=0;}
  process(inputs,outputs){
    for(const output of outputs)for(const channel of output)channel.fill(0);
    const samples=inputs[0]?.[0];if(!samples||sampleRate<16000||sampleRate>96000||this.total>=960000)return true;
    const ratio=sampleRate/16000;
    for(const sample of samples){let remain=1;while(remain>1e-9&&this.total<960000){
      const take=Math.min(remain,ratio-this.weight);this.sum+=(Number.isFinite(sample)?sample:0)*take;this.weight+=take;remain-=take;
      if(this.weight>=ratio-1e-9){const value=Math.max(-1,Math.min(1,this.sum/ratio));this.frame[this.used++]=Math.round(value*(value<0?32768:32767));this.sum=0;this.weight=0;this.total++;
        if(this.used===this.frame.length){const bytes=new ArrayBuffer(this.frame.length*2),view=new DataView(bytes);for(let i=0;i<this.frame.length;i++)view.setInt16(i*2,this.frame[i],true);this.port.postMessage(bytes,[bytes]);this.used=0;}
      }
    }}return true;
  }
}
registerProcessor('vp3-voicemail-greeting-v1',VP3VoicemailGreetingCaptureV1);
