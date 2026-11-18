import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {periodRef} from './fixtures/profile-analytics-period-ui-v1.mjs';
import {publication,publicationUrl} from './fixtures/product-marketing-ui-v1.mjs';

// Execute the production report projection and DOM renderer. HTML insertion is
// deliberately unavailable: visitor text must remain text at every boundary.
const script = fs.readFileSync(new URL('../agent-activity-hub-v1.js',import.meta.url),'utf8');
const begin = script.indexOf('  const profileReportSections = [');
const end = script.indexOf('  function renderCuriosityWorkspace(current)',begin);
assert.ok(begin > 0 && end > begin,'production Profile report functions are present');
class Element {
  constructor(tag,text='') { this.tagName=tag;this.text=String(text);this.children=[];this.dataset={};this.attributes={};this.listeners={}; }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) { this.children=[...children]; }
  setAttribute(key,value) { this.attributes[key]=String(value); }
  addEventListener(key,handler) { this.listeners[key]=handler; }
  get textContent() { return this.text+this.children.map(child=>typeof child==='string'?child:child.textContent).join(' '); }
  set textContent(value) { this.text=String(value);this.children=[]; }
  set innerHTML(_) { throw new Error('Profile reports must not insert HTML'); }
  insertAdjacentHTML() { throw new Error('Profile reports must not insert HTML'); }
}
const state={owner:1,agent:7,cid:11,binding:'fixture-binding'};
const context=vm.createContext({URL,Date,Set,Map,console,Intl,atob,btoa,
  window:{},document:{createElement:tag=>new Element(tag)},
  cfg:{activityEndpoint:'/vp3/api/agent-activity-hub-v1.php'},location:{origin:'https://fixture.test'},
  current:{...state},workspace:true,snapshot:null,sections:new Element('div'),closed:false,
  positive:value=>Number.isSafeInteger(value)&&value>0,
  localUrl:value=>{
    if(typeof value!=='string'||!value.startsWith('/')||value.startsWith('//')||value.includes('\\'))return '';
    const url=new URL(value,'https://fixture.test');return url.origin==='https://fixture.test'?url.pathname+url.search+url.hash:'';
  },
  node:(tag,text='',className='')=>Object.assign(new Element(tag,text),{className}),
  signature:value=>JSON.stringify([value?.owner,value?.agent,value?.cid,value?.binding]),
  scope:()=>context.current,close:()=>{context.closed=true;}
});
vm.runInContext(script.slice(begin,end),context,{filename:'agent-activity-hub-v1.js:profile-report'});
vm.runInContext(fs.readFileSync(new URL('../product-marketing-report-v1.js',import.meta.url),'utf8'),context);
vm.runInContext(script.slice(script.indexOf('  function productMarketingChatUrl()'),script.indexOf('  function renderProductMarketingReview(current)')),context);
const source=(kind='conversation',id=71,url='/vp3/profile-agent.php?tab=inbox&conversation=71')=>({
  ref:`profile:${kind}:${id}`,revision:'a'.repeat(64),title:'Visitor <img src=x onerror=alert(1)>',
  summary:'Saved visitor question',url,profile_source_kind:kind
});
const report=(changes={})=>({contract:'vp3.profile-report.v1',coverage:'ready',
  key_findings:['A visitor asked <script>window.attack=true</script> about a booking.'],
  actions_followups:['Review the current conversation.'],open_questions:['Which date works?'],
  next_step:'Open the exact conversation.',questions_for_you:['Would you like to reply?'],
  findings:[source()],...changes});
let checks=0;
const check=(truth,message)=>{assert.ok(truth,message);checks++;};
const model=input=>{context.input=input;return vm.runInContext('profileReportModel(input)',context);};
const descendants=(element,tag)=>element.children.flatMap(child=>typeof child==='string'?[]:
  [...(child.tagName===tag?[child]:[]),...descendants(child,tag)]);
const render=(input,full=true,current=state)=>{
  context.current={...current};context.workspace=full;context.sections=new Element('div');
  context.snapshot={observed_at:'2026-11-18T14:00:00Z',profile_report:input};
  vm.runInContext('renderProfileWorkspace(current)',context);return context.sections;
};

check(model(report())?.findings[0].href==='/vp3/profile-agent.php?tab=inbox&conversation=71','exact native conversation source is accepted');
const rendered=render(report());
check(descendants(rendered,'h4').map(row=>row.textContent).join('|')===
  'Key findings|Actions & follow-ups|Open questions|Next step|Questions for you|Sources','all five report sections and their sources render');
check(rendered.textContent.includes('<script>window.attack=true</script>')&&rendered.textContent.includes('<img src=x'),'public visitor text remains literal text');
check(!descendants(rendered,'script').length&&!descendants(rendered,'img').length,'visitor markup cannot create executable elements');
check(descendants(rendered,'a').length===1,'the report exposes only its exact verified source link');

for(const [kind,id,url] of [
  ['call',91,'/vp3/profile-agent.php?tab=inbox&conversation=71'],
  ['event',92,'/vp3/profile-agent.php?tab=visitors&session=3'],
  ['followup',94,'/vp3/artist-listening.php?session=5']
])check(model(report({findings:[source(kind,id,url)]}))!==null,kind+' exact source route is supported');
check(model(report({findings:[source('event',93,'/vp3/profile-agent.php?tab=inbox&conversation=71')]}))===null,
  'visit event sources cannot be presented as an Inbox conversation');
for(const url of [
  'javascript:alert(1)','//other.test/profile-agent.php?tab=inbox&conversation=71',
  'https://fixture.test/profile-agent.php?tab=inbox&conversation=71',
  '/vp3/profile-agent.php?tab=inbox&conversation=72','/vp3/profile-agent.php?tab=inbox&conversation=71&conversation=72',
  '/vp3/profile-agent.php?tab=inbox&conversation=71&redirect=https://other.test',
  '/vp3/profile-agent.php?tab=visitors&session=71','/vp3/profile-agent.php?tab=inbox&conversation=71#unsafe',
  '/vp3\\profile-agent.php?tab=inbox&conversation=71','/other/profile-agent.php?tab=inbox&conversation=71',
  '/vp3/profile-agent.php?tab=inbox&conversation=0','/vp3/profile-agent.php?tab=inbox&conversation=9007199254740993'
])check(model(report({findings:[source('conversation',71,url)]}))===null,'unsafe or mismatched source route rejected: '+url);
check(model(report({findings:[source(),source()]}))===null,'duplicate source references cannot inflate the report');
check(model(report({findings:[{...source(),revision:'stale'}]}))===null,'unverified source revision is rejected');
check(model(report({findings:[{...source(),ref:'profile:conversation:0'}]}))===null,'invalid exact source reference is rejected');
check(model(report({key_findings:['x'.repeat(321)]}))===null,'overlong report statements are rejected');
check(model(report({key_findings:['🙂'.repeat(320)]}))!==null,'Unicode limits agree with the server character contract');
check(model(report({key_findings:Array(7).fill('Finding')}))===null,'more than six section entries is rejected');
check(model(report({findings:Array(25).fill(source())}))===null,'overlong source inventory is rejected');
check(model(report({contract:'unknown'}))===null,'unknown report contract is rejected');
check(model(report({coverage:'unchecked'}))===null,'unknown source availability is rejected');

for(const coverage of ['unavailable','unconfigured']){
  const view=render(report({coverage}));
  check(!view.textContent.includes('A visitor asked')&&!view.textContent.includes('Would you like to reply?'),coverage+' discards stale findings and questions');
  check(descendants(view,'a').length===0,coverage+' discards old source links');
  check(descendants(view,'h4').length===5,coverage+' keeps the five report headings');
}
const unsaved=render(report({coverage:'unconfigured'}),true,{...state,cid:0});
check(unsaved.textContent.includes('Save this Chat'),'an unsaved private Chat receives the exact setup state');
const limited=render(report({coverage:'limited'}));
check(limited.textContent.includes('may be incomplete')&&limited.textContent.includes('A visitor asked'),'limited coverage retains supported facts with an explicit limit');
const empty=render(report({key_findings:[],actions_followups:[],open_questions:[],next_step:'',questions_for_you:[],findings:[]}));
check(empty.textContent.includes('No current findings in the checked Profile sources.'),'empty ready report describes checked evidence without fabricated totals');

const many=Array.from({length:8},(_,i)=>source('conversation',71+i,`/vp3/profile-agent.php?tab=inbox&conversation=${71+i}`));
check(descendants(render(report({findings:many}),false),'a').length===3,'compact report bounds the visible source list');
check(descendants(render(report({findings:many}),true),'a').length===8,'full report exposes every current supported source');
const oldLink=descendants(render(report()),'a')[0];
context.current={...state,cid:12};let prevented=false;
oldLink.listeners.click({preventDefault(){prevented=true;}});
check(prevented&&context.closed,'source links cannot navigate from a stale private Chat scope');
context.closed=false;
const replacedLink=descendants(render(report()),'a')[0];
context.snapshot={profile_report:report()};prevented=false;
replacedLink.listeners.click({preventDefault(){prevented=true;}});
check(prevented&&context.closed,'a link from an earlier report cannot outlive its source refresh');
context.snapshot=null;vm.runInContext('renderProfileWorkspace(current)',context);
check(context.sections.children.length===0,'a failed or cleared read removes the old report');

// Exercise the actual asynchronous hub reader at both await boundaries. The
// transport is controlled; no old response may render into a different scope.
const readerBegin=script.indexOf('  async function read() {');
const readerEnd=script.indexOf('  function open(options = {})',readerBegin);
const validatorBegin=script.indexOf('  function validState(data,current) {');
const validatorEnd=script.indexOf('  async function act(',validatorBegin);
assert.ok(readerBegin>0&&readerEnd>readerBegin&&validatorBegin>0&&validatorEnd>validatorBegin);
Object.assign(context,{AbortController,generation:0,controller:null,timer:0,specialistSettings:null,imageStudioView:null,
  connectedSitesView:null,profileView:null,host:{dataset:{}},specialistMode:true,selectedSpecialist:'profile',officeAllLoaded:false,
  accessRejected:false,status:new Element('p'),verified:new Element('small'),renders:0,open:true,
  family:key=>key.split(':')[0],visible:()=>context.open,officeWorkspace:()=>false,
  setTimeout:()=>1,clearTimeout:()=>{},leaseCurrent:()=>true,
  activityUrl:(current,view)=>{check(view==='profile','Profile uses the narrow activity view');return String(current.cid);},
  render:()=>{context.renders++;vm.runInContext('renderProfileWorkspace(current)',context);},
  renderWorkspaceSources:()=>vm.runInContext('renderProfileWorkspace(current)',context),
  renderSummary:()=>{},renderCoordination:()=>{},renderSpecialists:()=>{},
  button:(label,handler)=>Object.assign(new Element('button',label),{handler}),stop:()=>{},
  clear:()=>{context.snapshot=null;context.sections.replaceChildren();}
});
vm.runInContext(script.slice(validatorBegin,validatorEnd)+script.slice(readerBegin,readerEnd),context);
const response=current=>({ok:true,status:200,json:async()=>({ok:true,state:{
  contract:'vp3.agent.activity.v1',owner_user_id:current.owner,agent_id:current.agent,conversation_id:current.cid,
  working_attention_binding:current.binding,observed_at:new Date().toISOString(),sections:[],
  coverage:{profile_report:'ready'},profile_report:report()
}})});
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
context.current={...state};context.snapshot=null;let gate=deferred();
context.requestActivity=()=>gate.promise;
let pending=vm.runInContext('read()',context);
context.current={...state,cid:12};gate.resolve(response(state));await pending;
check(context.renders===0&&context.snapshot===null,'a delayed response cannot render into another private Chat');
context.current={...state};gate=deferred();let jsonEntered=false;
context.requestActivity=async()=>({ok:true,status:200,json:()=>{jsonEntered=true;return gate.promise;}});
pending=vm.runInContext('read()',context);await new Promise(setImmediate);
check(jsonEntered,'the response body is held after the first scope check');
context.current={...state,agent:8};gate.resolve(await response(state).json());await pending;
check(context.renders===0&&context.snapshot===null,'an Agent switch while decoding JSON cannot expose the old report');
context.current={...state};context.requestActivity=async()=>response(context.current);
await vm.runInContext('read()',context);
check(context.renders===1&&context.snapshot?.profile_report.coverage==='ready','a matching current response renders through the production reader');
context.requestActivity=async()=>({ok:false,status:503,json:async()=>({ok:false,error:'fixture failure'})});
await vm.runInContext('read()',context);
check(context.snapshot===null&&context.sections.children.length===0,'a failed refresh removes previously rendered Profile facts');
check(context.status.textContent.includes('Profile report could not be loaded'),'a failed Profile refresh has a truthful retry state');
context.requestActivity=async()=>({ok:false,status:403});
await vm.runInContext('read()',context);
check(context.accessRejected&&context.snapshot===null,'revoked private scope clears the report and requires a new session');

// Explicit checked-view visits retain exact member proofs without becoming new obligations.
const visit=(id,type='profile_view')=>({...source('event',id,'/vp3/profile-agent.php?tab=visitors&session='+id),
  title:'Saved visit '+id,profile_event_type:type,presentation_owner:'profile_attention_pa4b',profile_presentation_owned:true,
  legacy_delivered:false,disposition:'pending',trigger:'activity',priority:'low',status:'current'});
const visitReport=report({coverage:'limited',findings:[visit(301),source(),visit(302,'return_visitor')]});
let grouped=render(visitReport,false),details=descendants(grouped,'details');
check(details.length===1&&details[0].textContent.includes('2 visit records in this checked view'),'explicit routine visits form one bounded checked-view group');
check(descendants(grouped,'a').length===3&&descendants(details[0],'a').length===2,'compact grouping retains every exact visit source alongside nonroutine sources');
const members=descendants(details[0],'a');
check(members[0].dataset.profileSource==='profile:event:301'&&members[0].dataset.profileSourceRevision==='a'.repeat(64)
  &&members[0].dataset.profileSourceDisposition==='pending'&&members[0].dataset.profilePresentationOwner==='profile_attention_pa4b','group members retain revision, disposition and presentation ownership');
check(descendants(grouped,'button').length===0&&grouped.textContent.includes('may be incomplete'),'opening a limited visit group adds no acknowledgement or action');
const projected=model(visitReport).findings[0];
check(projected.profile_event_type==='profile_view'&&projected.legacy_delivered===false&&projected.profile_presentation_owned===true,'report model retains typed native evidence and receipt flags');
for(const candidate of [{...visit(301),profile_event_type:undefined},{...visit(301),presentation_owner:undefined},{...visit(301),priority:'high'},source('event',301,'/vp3/profile-agent.php?tab=visitors&session=301')]){
  grouped=render(report({findings:[candidate]}));check(descendants(grouped,'details').length===0,'missing routine proof or higher priority remains an individual source');
}
check(model(report({findings:[{...visit(301),presentation_owner:'unknown_publisher'}]}))===null,'unrecognized visit presentation owner is rejected');
check(model(report({findings:[{...visit(301),profile_event_type:'<script>profile_view</script>'}]}))===null,'malformed event type cannot change presentation');
check(model(report({findings:[{...source(),profile_event_type:'profile_view'}]}))===null,'conversation is not reclassified as a routine visit');
check(model(report({findings:[{...visit(301),profile_presentation_owned:false}]}))===null,'inconsistent presentation ownership is rejected');


const fixedRef=periodRef();
const fixedSource={ref:fixedRef,revision:'d'.repeat(64),title:'Completed Profile period · UTC',summary:'Verified period evidence',
  url:'/vp3/chat.php?agent=7&conversation_id=11&profile_analytics='+encodeURIComponent(fixedRef),
  profile_source_kind:'analytics',presentation_owner:'profile_briefing_v1',profile_presentation_owned:true,legacy_delivered:false};
check(model(report({findings:[fixedSource]}))?.findings[0].href===fixedSource.url,'fixed analytics source retains exact original private Chat destination');
check(model(report({findings:[fixedSource]}))?.findings[0].legacy_delivered===false,'briefing ownership is not a delivery receipt');
const gapRef=periodRef(1,Math.floor(Date.parse('2026-03-08T05:00:00Z')/1000),'America/Havana','e');
check(model(report({findings:[{...fixedSource,ref:gapRef,url:fixedSource.url.replace(encodeURIComponent(fixedRef),encodeURIComponent(gapRef))}]}))!==null,
  'Hub accepts the same normalized skipped-midnight source boundary as the workspace');
check(model(report({findings:[{...fixedSource,url:fixedSource.url.replace('agent=7','agent=system')}]}))!==null,'system Agent analytics route is supported');
check(model(report({findings:[{...fixedSource,url:fixedSource.url.replace('conversation_id=11','conversation_id=12')}]}))!==null,'another original Chat remains an exact link rather than being rewritten');
for(const url of [fixedSource.url+'&agent=7',fixedSource.url+'&redirect=/elsewhere',fixedSource.url+'#fragment',
  fixedSource.url.replace('/vp3/','/wrong/'),fixedSource.url.replace(encodeURIComponent(fixedRef),encodeURIComponent(periodRef(7)))]){
  check(model(report({findings:[{...fixedSource,url}]}))===null,'ambiguous or mismatched fixed analytics link rejected');
}
check(model(report({findings:[{...fixedSource,profile_presentation_owned:false}]}))===null,'briefing presenter requires the explicit owned-presentation flag');
const fixedRendered=render(report({findings:[fixedSource]}));
check(descendants(fixedRendered,'a')[0].dataset.profileSource===fixedRef&&descendants(fixedRendered,'a')[0].dataset.profileSourceRevision==='d'.repeat(64),'exact fixed ref and revision survive rendering');

const productPublication=publication(),related={ref:productPublication.ref,revision:productPublication.revision,title:'Review the exact saved product publication',
  url:'/vp3'+publicationUrl(),product_id:productPublication.product_id,publication_id:productPublication.publication_id};
const linkedFinding={...source(),related_product_marketing:[related],product_marketing_coverage:'ready'};
check(model(report({findings:[linkedFinding]}))?.findings[0].related_product_marketing[0].href===related.url,'Profile finding retains exact related publication and fixed period');
const linkedRendered=render(report({findings:[linkedFinding]})),relatedAnchor=descendants(linkedRendered,'a').find(link=>link.dataset.productMarketingSource);
check(relatedAnchor.href===related.url&&relatedAnchor.dataset.productMarketingRevision===related.revision,'related publication source and revision survive rendering');
const publicationOpens=[];context.window.VP3MediaCommands={openPublication:target=>{publicationOpens.push(target);return true;}};
let productPrevented=false;relatedAnchor.listeners.click({preventDefault(){productPrevented=true;}});
check(productPrevented&&publicationOpens.length===1&&publicationOpens[0].conversationId===11,'same original private Chat opens exact publication inline');
const otherFinding={...linkedFinding,related_product_marketing:[{...related,url:related.url.replace('conversation_id=11','conversation_id=12')}]};
const otherRendered=render(report({findings:[otherFinding]}));productPrevented=false;
descendants(otherRendered,'a').find(link=>link.dataset.productMarketingSource).listeners.click({preventDefault(){productPrevented=true;}});
check(!productPrevented&&publicationOpens.length===1,'another original Chat follows the validated original URL without rewriting its scope');
context.current={...state,cid:99};productPrevented=false;relatedAnchor.listeners.click({preventDefault(){productPrevented=true;}});
check(productPrevented&&publicationOpens.length===1,'stale Profile related action cannot open after scope changes');
for(const changed of [{...related,publication_id:'b'.repeat(32)},{...related,product_id:992102},{...related,url:related.url+'&agent=7'},
  {...related,url:related.url.replace('marketing_period=','period=')},{...related,revision:'bad'}])
  check(model(report({findings:[{...linkedFinding,related_product_marketing:[changed]}]}))===null,'invalid related product tuple rejected');
check(model(report({findings:[{...linkedFinding,related_product_marketing:[related,related]}]}))===null,'duplicate related source is rejected');
check(model(report({findings:[{...linkedFinding,related_product_marketing:Array(5).fill(related)}]}))===null,'related Product Marketing links remain bounded');

const orb=fs.readFileSync(new URL('../agent-orb.js',import.meta.url),'utf8');
const catalogBegin=orb.indexOf('  const specialistFamilies={');
const catalogEnd=orb.indexOf('  function installOrbAppearance(',catalogBegin);
assert.ok(catalogBegin>0&&catalogEnd>catalogBegin);
const registry=new Map(),events=new Map();
const polling=vm.createContext({URL,Date,Set,Map,AbortController,
  window:{addEventListener:(name,fn)=>events.set(name,fn)},document:{addEventListener:()=>{}},
  setTimeout:()=>1,clearTimeout:()=>{},normalizeJobs:()=>[],
  options:{registry:{update:(key,value)=>registry.set(key,value),remove:key=>registry.delete(key)},
    scope:()=>11,configured:id=>id==='profile'?'idle':'',allowed:()=>true,setAssessmentJobs:()=>{},
    read:async()=>({observed_at:new Date().toISOString(),health:{enabled:false,active_jobs:[]},
      specialists:[{id:'profile',conversation_id:11,state:'checked',detail:'Current Profile sources checked.'}]})}
});
vm.runInContext(orb.slice(catalogBegin,catalogEnd)+'\ninstallChatSpecialistPolling(options);',polling);
await new Promise(setImmediate);
check(registry.get('profile')?.label==='Profile'&&registry.get('profile')?.state==='checked','the production specialist catalog publishes the scoped Profile entry');
check(registry.get('profile')?.keepLauncher===true&&registry.get('profile')?.viewLabel==='Open Profile report','the Profile launcher survives a refresh as a report entry');
events.get('vp3:participant-task-context')({detail:{participant_id:42}});
check(!registry.has('profile'),'participant context cannot retain the owner Profile launcher');

for(const filename of ['agent-activity-hub-v1.js','agent-top-of-mind-v1.js','chat-specialist-rail-v1.js','agent-orb.js','chat-cognitive-presentation-v510.js']){
  new vm.Script(fs.readFileSync(new URL('../'+filename,import.meta.url),'utf8'),{filename});
}
console.log(`AGENT_PROFILE_SPECIALIST_REPORT_V1=PASS (${checks} checks)`);
