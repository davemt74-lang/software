'use strict';

class StonefellowTimeStretchV203 extends AudioWorkletProcessor {
  constructor(){
    super();
    this.channels=[];
    this.events=[];
    this.generation=0;
    this.grain=1024;
    this.hop=256;
    this.port.onmessage=event=>this.receive(event.data||{});
  }

  receive(message){
    if(message.type==='buffer'){
      this.channels=(message.channels||[]).map(channel=>channel instanceof Float32Array?channel:new Float32Array(channel));
      return;
    }
    if(message.type==='clear'){
      this.generation=Number(message.generation)||0;
      this.events=[];
      return;
    }
    if(message.type==='schedule'&&Number(message.generation)===this.generation){
      this.events=(message.events||[]).map(item=>({
        startFrame:Math.max(0,Math.round(Number(item.startFrame)||0)),
        outputFrames:Math.max(1,Math.round(Number(item.outputFrames)||1)),
        sourceFrame:Math.max(0,Number(item.sourceFrame)||0),
        rate:Math.max(.25,Math.min(4,Number(item.rate)||1)),
        gain:Math.max(0,Number(item.gain)||0),
        fadeInFrames:Math.max(0,Math.round(Number(item.fadeInFrames)||0)),
        fadeOutFrames:Math.max(0,Math.round(Number(item.fadeOutFrames)||0))
      }));
    }
  }

  window(position){
    if(position<0||position>=this.grain)return 0;
    return .5-.5*Math.cos(2*Math.PI*position/(this.grain-1));
  }

  sample(channel,event,localFrame){
    const data=this.channels[Math.min(channel,this.channels.length-1)];
    if(!data)return 0;
    const center=Math.floor(localFrame/this.hop);
    let sum=0;
    let weight=0;
    for(let grainIndex=center-3;grainIndex<=center+1;grainIndex++){
      if(grainIndex<0)continue;
      const outputStart=grainIndex*this.hop;
      const within=localFrame-outputStart;
      const win=this.window(within);
      if(win<=0)continue;
      const input=event.sourceFrame+grainIndex*this.hop*event.rate+within;
      const left=Math.floor(input);
      if(left<0||left>=data.length-1)continue;
      const fraction=input-left;
      const value=data[left]+(data[left+1]-data[left])*fraction;
      sum+=value*win;
      weight+=win;
    }
    return weight>0?sum/weight:0;
  }

  envelope(event,localFrame){
    let value=event.gain;
    if(event.fadeInFrames>0)value*=Math.min(1,localFrame/event.fadeInFrames);
    const remaining=event.outputFrames-localFrame;
    if(event.fadeOutFrames>0)value*=Math.min(1,remaining/event.fadeOutFrames);
    return Math.max(0,value);
  }

  process(inputs,outputs){
    const output=outputs[0];
    if(!output?.length)return true;
    output.forEach(channel=>channel.fill(0));
    if(!this.channels.length||!this.events.length)return true;
    const blockStart=currentFrame;
    for(const event of this.events){
      const eventEnd=event.startFrame+event.outputFrames;
      if(eventEnd<=blockStart||event.startFrame>=blockStart+output[0].length)continue;
      const first=Math.max(0,event.startFrame-blockStart);
      const last=Math.min(output[0].length,eventEnd-blockStart);
      for(let index=first;index<last;index++){
        const local=blockStart+index-event.startFrame;
        const gain=this.envelope(event,local);
        for(let channel=0;channel<output.length;channel++){
          output[channel][index]+=this.sample(channel,event,local)*gain;
        }
      }
    }
    this.events=this.events.filter(event=>event.startFrame+event.outputFrames>blockStart);
    return true;
  }
}

registerProcessor('stonefellow-time-stretch-v203',StonefellowTimeStretchV203);
