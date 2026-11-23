import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

class Element extends EventTarget {
  dataset = {};
  textContent = '';
  open = false;
  showModal() { this.open = true; }
  close() { this.open = false; this.dispatchEvent(new Event('close')); }
}

const elements = new Map(['dialog','preview','name','status','video','close','retry'].map(key => [key,new Element()]));
const requests = [], receivers = [], timers = new Map();
let now = 100;
const phone = {id:'a'.repeat(32),name:'Studio',status:'approved',
  live_camera:{session_id:'b'.repeat(32),agent_id:0,conversation_id:8,expires_at:112}};
const preview = {
  async post(endpoint,headers,request) {
    requests.push(request);
    return {camera:request.action === 'camera_map_close' ? null : {...phone.live_camera,viewer_id:request.viewer_id}};
  },
  receive(options) {
    const receiver = {options,ended:false,finish(state,message) {
      if (receiver.ended) return;
      receiver.ended = true;
      options.display(state,message);
      options.onEnd();
    }};
    receivers.push(receiver);
    return receiver;
  }
};
const window = {VP3PhoneCameraPreview:preview};
vm.runInNewContext(readFileSync(new URL('../phone-map-camera-v1.js',import.meta.url),'utf8'), {
  window, crypto:{randomUUID}, document:{getElementById:id=>elements.get(id.slice('phone-stream-'.length))},
  setTimeout(callback,milliseconds) {
    const id = Symbol();
    timers.set(id,{callback,due:now+milliseconds/1000});
    return id;
  },
  clearTimeout(id) { timers.delete(id); }
});
const map = window.vp3PhoneMapCameraV1({dataset:{}},()=>now,()=>{});
const settle = () => new Promise(resolve=>setImmediate(resolve));
function advanceWithActiveSource(seconds) {
  for (let i=0; i<seconds; i++) {
    now++;
    phone.live_camera.expires_at = now+12;
    map.update([phone]);
    for (const [id,timer] of [...timers]) {
      if (timer.due <= now) {
        timers.delete(id);
        timer.callback();
      }
    }
  }
}

map.open(phone,true);
await settle();
assert.equal(receivers.length,1);
advanceWithActiveSource(30);
assert.equal(receivers[0].ended,false,'normal ICE negotiation gets time to finish');
advanceWithActiveSource(30);
await settle();
assert.equal(receivers[0].ended,true,'a fresh source alone cannot keep an unsuccessful viewer polling indefinitely');
assert.equal(elements.get('preview').dataset.state,'unavailable');
assert.equal(elements.get('retry').hidden,false);
assert.deepEqual(requests.map(request=>request.action),['camera_map_open','camera_map_close']);
assert.equal(requests[0].viewer_id,requests[1].viewer_id,'only the timed-out viewer is released');

elements.get('retry').dispatchEvent(new Event('click'));
await settle();
assert.equal(receivers.length,2);
assert.notEqual(requests[2].viewer_id,requests[0].viewer_id,'retry negotiates a fresh map peer');
assert.equal(requests[2].session_id,requests[0].session_id,'the existing holder consent remains in use');
receivers[1].options.display('live','Receiving video');
advanceWithActiveSource(90);
assert.equal(receivers[1].ended,false,'a playing preview outlives the initial frame deadline');
map.update([{...phone,live_camera:null}]);
await settle();
assert.equal(elements.get('retry').hidden,true,'a stopped source cannot be retried from its old popup');
assert.equal(elements.get('preview').dataset.state,'stopped');
assert.equal(requests.at(-1).action,'camera_map_close');
console.log('PASS map handshake timeout releases only its viewer, retries the same phone with a fresh peer, and leaves moving video connected.');
