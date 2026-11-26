/* Optional Twilio local AudioProcessor. Passes the human microphone through
 * until a separately authorized, bounded synthetic turn is explicitly played. */
(() => {
  'use strict';
  class VoiceMixer {
    constructor(context,onLoss=()=>{}){
      this.context=context;this.onLoss=onLoss;this.graph=null;this.speaking=null;this.closed=false;
      context.onstatechange=()=>{
        if(!this.closed&&this.graph&&context.state!=='running'){
          this.stop();this.onLoss('audio_context_lost');
        }
      };
    }
    async createProcessedStream(input){
      if(this.closed||this.context.state!=='running')throw new Error('Agent voice audio is not ready.');
      const tracks=input?.getAudioTracks?.()||[];
      if(tracks.length!==1||tracks[0].readyState!=='live')throw new Error('The existing phone microphone track is unavailable.');
      this.clearGraph();
      const ctx=this.context,source=ctx.createMediaStreamSource(input),mic=ctx.createGain(),destination=ctx.createMediaStreamDestination();
      destination.channelCount=1;destination.channelCountMode='explicit';mic.gain.value=1;
      try{source.connect(mic);mic.connect(destination);}catch(error){
        try{source.disconnect();mic.disconnect();}catch(_){}
        for(const track of destination.stream.getTracks())track.stop();throw error;
      }
      const graph={input,source,mic,destination,output:destination.stream,track:tracks[0]};
      graph.ended=()=>{if(this.graph===graph&&!this.closed){this.stop();this.onLoss('input_lost');}};
      graph.track.addEventListener('ended',graph.ended,{once:true});
      this.graph=graph;return graph.output;
    }
    async destroyProcessedStream(stream){
      if(this.graph?.output!==stream)return;
      const wasSpeaking=Boolean(this.speaking);this.clearGraph();
      if(wasSpeaking&&!this.closed)this.onLoss('processor_changed');
    }
    get originalStream(){return this.graph?.input||null;}
    matches(stream){
      const graph=this.graph,tracks=stream?.getAudioTracks?.()||[],output=graph?.output.getAudioTracks()||[];
      return !this.closed&&this.context.state==='running'&&!!graph&&tracks.length===1&&output.length===1&&
        tracks[0]===output[0]&&output[0].readyState==='live'&&output[0].enabled!==false&&graph.track.readyState==='live'&&graph.track.enabled!==false;
    }
    setMic(graph,value){
      // Clear the previous turn's automation, not just future events. AudioParam
      // readback is updated by the render thread on the next audio quantum.
      const gain=graph.mic.gain;gain.cancelScheduledValues(0);gain.value=value;gain.setValueAtTime(value,this.context.currentTime);
    }
    play(pcm,stream,onEnded){
      if(this.speaking||!this.matches(stream)||!(pcm instanceof Int16Array)||pcm.length<1600||pcm.length>320000)
        throw new Error('The approved voice turn is unavailable.');
      const graph=this.graph,ctx=this.context,buffer=ctx.createBuffer(1,pcm.length,16000),samples=buffer.getChannelData(0);
      for(let i=0;i<pcm.length;i++)samples[i]=pcm[i]/32768;
      const source=ctx.createBufferSource();source.buffer=buffer;
      const voice=ctx.createGain();voice.gain.value=.85;source.connect(voice);voice.connect(graph.destination);
      const turn={source,voice,graph};this.speaking=turn;
      source.onended=()=>{if(this.speaking===turn){this.stop();onEnded?.();}};
      try{this.setMic(graph,0);source.start();}catch(error){this.stop();throw error;}
    }
    stop(){
      const turn=this.speaking;this.speaking=null;
      if(turn){
        turn.source.onended=null;
        try{turn.source.stop();}catch(_){}
        try{turn.source.disconnect();turn.voice.disconnect();}catch(_){}
      }
      if(this.graph){try{this.setMic(this.graph,1);}catch(_){}}
    }
    clearGraph(){
      this.stop();const graph=this.graph;this.graph=null;if(!graph)return;
      graph.track.removeEventListener('ended',graph.ended);
      try{graph.source.disconnect();graph.mic.disconnect();}catch(_){}
      // Only the derived outbound stream belongs to this processor. The SDK
      // and the existing phone capture lease own the original microphone.
      for(const track of graph.output.getTracks())track.stop();
    }
    close(){
      if(this.closed)return;this.closed=true;this.context.onstatechange=null;this.clearGraph();
      try{void this.context.close().catch(()=>{});}catch(_){}
    }
  }
  window.VP3OperatorVoiceMixerV1=Object.freeze({create:(context,onLoss)=>new VoiceMixer(context,onLoss)});
})();
