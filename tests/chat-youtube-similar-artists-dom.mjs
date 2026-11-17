import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';

const source = await fs.readFile(new URL('../chat-youtube-v1.js',import.meta.url),'utf8');
class Node {
  constructor(tag) { this.tag=tag;this.children=[];this.dataset={};this.attributes={};this.events={};this.className='';this.ownText=''; }
  get textContent() { return this.ownText+this.children.map(child=>child.textContent).join(''); }
  set textContent(text) { this.ownText=String(text);this.children=[]; }
  get childElementCount() { return this.children.length; }
  get isConnected() { return true; }
  get classList() { return {add:name=>{this.className+=' '+name;}}; }
  append(...children) { for(const child of children){child.remove();child.parentNode=this;this.children.push(child);} }
  after(child) { child.remove();child.parentNode=this.parentNode;this.parentNode.children.splice(this.parentNode.children.indexOf(this)+1,0,child); }
  remove() { if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(child=>child!==this);this.parentNode=null;} }
  replaceChildren(...children) { for(const child of [...this.children])child.remove();this.append(...children); }
  setAttribute(name,value) { this.attributes[name]=String(value); }
  getAttribute(name) { return this.attributes[name]??null; }
  addEventListener(type,callback) { (this.events[type]??=[]).push(callback); }
  click() { for(const callback of this.events.click??[])callback(); }
  all(match) { return [...(match(this)?[this]:[]),...this.children.flatMap(child=>child.all(match))]; }
  contains(node) { return this.all(candidate=>candidate===node).length>0; }
  querySelector(selector) { return this.all(node=>node.className.split(' ').includes(selector.slice(1)))[0]??null; }
}
const ids=['seed0000001','seed0000002','seed0000003','moon0000001','soft0000001','sable000001'];
const names=['Moon Harbor','Soft Current','Sable'];
const videos=ids.map((video_id,index)=>({video_id,title:'Evening lights '+(index+1),made_for_kids:false}));
const result={query:'Paper Lanterns',suggestion_kind:'playlist',initiative_suggestion:true,source_message_id:101,
  expires_at:Math.floor(Date.now()/1000)+600,videos,
  similar_artists:names.map((artist,index)=>({artist,video_id:ids[index+3]}))};
const makeFixture = payload => {
  const body=new Node('body'),message=new Node('article'),text=new Node('div');
  text.className='message-text';text.textContent='Artist report';message.dataset.chatMessageId='302';
  message.append(text);body.append(message);
  const requests=[];const players=[];
  const document={body,head:new Node('head'),baseURI:'https://vp3.test/chat.php',hidden:false,
    createElement:tag=>new Node(tag),addEventListener(){},getElementById:()=>null,querySelectorAll:()=>[]};
  const window={VP3_YOUTUBE_PLAYBACK_V1:{endpoint:'/playback',csrf:'fixture',captureKey:'fixture-capture',resumeCapture:false,playlistReady:true},
    VP3ParticipantContinuity:{selectedContext:()=>null},crypto:{randomUUID:()=> '11111111-1111-4111-8111-111111111111'},
    addEventListener(){},dispatchEvent(){},setInterval:()=>1,clearInterval(){},
    YT:{PlayerState:{PLAYING:1,PAUSED:2,BUFFERING:3,ENDED:0},Player:class {
      constructor(frame,{events}) { this.id=new URL(frame.src).pathname.split('/').at(-1);this.events=events;this.state=-1;players.push(this);queueMicrotask(()=>events.onReady({target:this})); }
      getPlayerState(){return this.state;} getCurrentTime(){return 0;} getPlaybackRate(){return 1;}
      getVideoUrl(){return 'https://www.youtube.com/watch?v='+this.id;}
      change(state){this.state=state;this.events.onStateChange({target:this,data:state});}
      destroy(){} pauseVideo(){this.change(2);} playVideo(){this.change(1);}
    }}};
  const context=vm.createContext({window,document,location:new URL(document.baseURI),URL,URLSearchParams,
    Event,AbortSignal,performance,setTimeout,clearTimeout,
    fetch:async(url,options)=>{requests.push(Object.fromEntries(options.body));return {ok:true,json:async()=>({ok:true,recorded:true})};}});
  vm.runInContext(source,context);
  window.VP3_CHAT_YOUTUBE_V1.attach(message,payload);
  return {message,requests,players,window,all:match=>message.all(match)};
};
const settle=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
const fixture=makeFixture(result);
assert.equal(fixture.all(node=>node.className==='vp3-youtube-result').length,6);
assert.deepEqual(fixture.all(node=>node.dataset.youtubeSimilarArtist==='1').map(node=>node.textContent),names.map(name=>'Artist suggestion · '+name));
assert.match(fixture.all(node=>node.dataset.youtubeSimilarArtists==='1')[0].textContent,/Moon Harbor, Soft Current, Sable/);
assert.equal(fixture.players.length,0,'Restoring a report does not create a player');
const play=fixture.all(node=>node.attributes['aria-label']==='Play in Chat · Evening lights 4')[0];
play.click();await settle();
assert.equal(fixture.players[0].id,ids[3]);
fixture.players[0].change(1);await settle();
assert.equal(fixture.requests[0].action,'record');
assert.equal(fixture.requests[0].video_id,ids[3]);
assert.equal(fixture.requests[0].message_id,'101','The exact related video uses the original persisted result for playback');
await fixture.window.VP3_CHAT_YOUTUBE_V1.runCommand('next');await settle();
assert.equal(fixture.players.at(-1).id,ids[4],'Playlist next advances through the related video IDs');
const solo=makeFixture({...result,suggestion_kind:'similar',videos:videos.slice(3)});
assert.equal(solo.all(node=>node.className==='vp3-youtube-result').length,3);
assert.equal(solo.all(node=>node.dataset.youtubeSimilarArtist==='1').length,3);
const absent=makeFixture({...result,similar_artists:[{artist:'Ghost artist',video_id:'missing0001'}]});
assert.equal(absent.all(node=>node.dataset.youtubeSimilarArtists==='1').length,0);
const expired=makeFixture({...result,expires_at:1});
assert.equal(expired.all(node=>node.className==='vp3-youtube-result').length,0);
console.log('YOUTUBE_SIMILAR_ARTISTS_DOM=PASS · source renderer, related names, exact click/record IDs, playlist next, standalone/expired results and no autoplay');
