import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {workspaceDocument} from './fixtures/profile-workspace-dom-v1.mjs';
import {marketingRef,publicationId} from './fixtures/marketing-followthrough-ui-v1.mjs';

// Exercise the actual Hub summary/related-source projection and its one detail handoff.
const script=readFileSync(new URL('../agent-activity-hub-v1.js',import.meta.url),'utf8');
const start=script.indexOf('  const profileReportSections = ['),end=script.indexOf('  function renderCuriosityWorkspace(current)',start);
const summaryStart=script.indexOf('  function renderProductMarketingReview(current)'),summaryEnd=script.indexOf('  function renderMarketingReview(current)',summaryStart);
assert.ok(start>0&&end>start&&summaryStart>0&&summaryEnd>summaryStart);
let checks=0;const check=(truth,label)=>{assert.ok(truth,label);checks++;};
const ref=marketingRef(),scope={owner:1,agent:7,cid:11,binding:'binding'};
const href=(reference=ref,agent=7,chat=11)=>'/sub/chat.php?'+new URLSearchParams({agent:agent===0?'system':String(agent),conversation_id:String(chat),marketing_followthrough:reference});
const planHref='/sub/chat.php?agent=7&conversation_id=11&marketing_plan=87';
const summary=()=>({contract:'vp3.product-marketing-summary.v1',coverage:'ready',items:[
  {ref:'marketing:product-plan:87',revision:'a'.repeat(64),kind:'plan',title:'Current shirt plan',summary:'Review the saved design.',next_step:'Review the original plan.',status:'design_review',url:planHref},
  {ref,revision:'b'.repeat(64),kind:'followthrough',title:'Saved promotion <script>bad()</script>',summary:'3 recorded clicks; previous: 1.',next_step:'',status:'completed_period',url:href()}
]});
const related=()=>({ref,revision:'b'.repeat(64),title:'Related saved promotion <img src=x>',url:href(),publication_id:publicationId,product_id:87,
  public_conversation_id:51,turn_id:61,visitor_message_id:71,answer_message_id:72,question:'Can I get this in blue?',linked_at_utc_ms:1763337600000,relation:'saved_promotion_reply'});
const profile=()=>({contract:'vp3.profile-report.v1',coverage:'ready',key_findings:['A saved visitor conversation.'],actions_followups:[],open_questions:[],next_step:'Open its source.',questions_for_you:[],
  findings:[{ref:'profile:conversation:51',revision:'c'.repeat(64),title:'Visitor question',url:'/sub/profile-agent.php?tab=inbox&conversation=51',profile_source_kind:'conversation',
    related_product_marketing:[related()],related_product_marketing_coverage:'ready'}]});
function fixture(){
  const document=workspaceDocument(),opens=[],closed=[];
  const window={VP3MarketingFollowthroughV1:{openSource:source=>{opens.push(structuredClone(source));return true;}}};
  const context=vm.createContext({window,document,URL,Date,Number,Object,Array,Map,Set,Intl,atob,btoa,location:{origin:'https://example.test'},
    cfg:{activityEndpoint:'/sub/api/agent-activity-hub-v1.php'},workspace:true,snapshot:null,current:{...scope},sections:document.body,
    positive:value=>Number.isSafeInteger(value)&&value>0,localUrl:value=>{if(typeof value!=='string'||!value.startsWith('/')||value.startsWith('//')||value.includes('\\'))return '';const url=new URL(value,'https://example.test');return url.pathname+url.search+url.hash;},
    node:(tag,text='',className='')=>{const node=document.createElement(tag);node.textContent=text;node.className=className;return node;},
    signature:value=>JSON.stringify([value?.owner,value?.agent,value?.cid,value?.binding]),scope:()=>context.current,close:restore=>closed.push(restore)});
  vm.runInContext(script.slice(start,end)+script.slice(summaryStart,summaryEnd),context);
  return {document,context,opens,closed,model(value){context.value=value;return vm.runInContext('productMarketingModel(value,current)',context);},
    profileModel(value){context.value=value;return vm.runInContext('profileReportModel(value)',context);},
    render(value){context.snapshot={product_marketing:value};const node=vm.runInContext('renderProductMarketingReview(current)',context);document.body.replaceChildren(node);return node;},
    renderProfile(value){context.snapshot={profile_report:value,observed_at:'2026-11-18T12:00:00Z'};vm.runInContext('renderProfileWorkspace(current)',context);return document.body;}};
}
{
  const f=fixture(),value=summary();check(f.model(value)?.items.length===2,'canonical plan and fixed-period sources share one bounded Hub summary');
  const body=f.render(value);check(body.textContent.includes('Current plans')&&body.textContent.includes('Completed promotion periods'),'Hub exposes separate current plans and completed periods');
  check(body.textContent.includes('<script>bad()</script>')&&!body.querySelector('script'),'saved summary text remains escaped');
  check(body.textContent.includes('Completed day: 17 Nov 2026 · UTC'),'summary names the fixed completed date and saved timezone');
  const links=body.querySelectorAll('[data-product-marketing-link]');
  links[1].click();check(f.opens.length===1&&f.opens[0].ref===ref&&f.closed.at(-1)===false,'same-scope summary opens the existing detail controller and closes Hub without moving focus back');
  const modified={type:'click',ctrlKey:true};links[1].dispatchEvent(modified);check(!modified.defaultPrevented&&f.opens.length===1,'modified click keeps its validated original Chat link');
  const plan={type:'click'};links[0].dispatchEvent(plan);check(!plan.defaultPrevented&&links[0].href===planHref,'current plan remains a separate exact existing-plan route');
  f.context.snapshot={product_marketing:summary()};const stale={type:'click'};links[1].dispatchEvent(stale);check(stale.defaultPrevented&&f.opens.length===1,'replaced report cannot open stale saved summary evidence');
  f.context.current={...scope,cid:12};check(f.model(value)===null,'summary cannot inherit a different private Chat');
}
for(const mutate of [v=>v.items[0].url=planHref+'&agent=7',v=>v.items[1].url=href(ref,7,12),v=>v.items[1].url=href(marketingRef(7)),
  v=>v.items[1].url=href()+'#other',v=>v.items[1].url=href().replace('/sub/','/elsewhere/'),v=>v.items[1].kind='plan',
  v=>v.items[1].revision='x',v=>v.items.push(v.items[1]),v=>v.items[0].ref='marketing:product-plan:9007199254740992',
  v=>v.items[1].title='x'.repeat(191),v=>{v.coverage='unavailable';}]){
  const f=fixture(),value=summary();mutate(value);check(f.model(value)===null,'malformed, cross-scope or unavailable populated summary is rejected');
}
for(const coverage of ['ready','limited','unavailable','unconfigured']){
  const f=fixture(),body=f.render({contract:'vp3.product-marketing-summary.v1',coverage,items:[]});
  check(body.dataset.productMarketingSummary===coverage&&!body.querySelector('[data-product-marketing-link]'),'empty '+coverage+' state cannot retain previous links');
}
{
  const f=fixture(),value=profile();check(f.profileModel(value)?.findings[0].related_product_marketing.length===1,'exact receipt-associated source stays attached to its native conversation finding');
  const body=f.renderProfile(value),link=body.querySelector('[data-product-marketing-source]');
  check(body.textContent.includes('Related Product Marketing')&&body.textContent.includes('<img src=x>')&&!body.querySelector('img'),'related source title is literal and has no executable markup');
  check(body.querySelectorAll('[data-profile-source]').length===1,'related link creates no second native attention finding');
  link.click();check(f.opens.length===1&&f.opens[0].ref===ref,'related Profile source uses the same Marketing detail runtime');
  f.context.current={...scope,cid:12};const staleRelated={type:'click'};link.dispatchEvent(staleRelated);
  check(staleRelated.defaultPrevented&&f.opens.length===1,'previously rendered related link cannot open after the private Chat scope changes');
  f.context.current={...scope};
  const foreign=profile();foreign.findings[0].related_product_marketing[0].url=href(ref,7,12);const foreignBody=f.renderProfile(foreign);
  const event={type:'click'};foreignBody.querySelector('[data-product-marketing-source]').dispatchEvent(event);
  check(!event.defaultPrevented&&f.opens.length===1,'authorized source from another original Chat keeps its exact link and never retargets current Chat');
  const current=profile(),entry=current.findings[0].related_product_marketing[0];entry.ref='marketing:product-plan:87';entry.url=planHref;entry.title='Open current plan';
  check(f.profileModel(current)!==null,'a reply in an unfinished day can point to its current plan without fabricating a completed report');
  current.findings[0].related_product_marketing.push({...entry,publication_id:'d'.repeat(32),turn_id:62,visitor_message_id:73,answer_message_id:74});
  check(f.profileModel(current)?.findings[0].related_product_marketing.length===2,'two distinct publications may retain verified associations to the same current product plan');
  const sharedPlan=f.renderProfile(current);check(sharedPlan.querySelectorAll('[data-product-marketing-source]').length===2
    &&sharedPlan.querySelectorAll('[data-profile-source]').length===1,'shared current-plan links preserve both publication associations without duplicating the native finding');
  current.findings[0].related_product_marketing.push({...entry});
  check(f.profileModel(current)===null,'repeated publication association is rejected even when valid distinct publications share the current plan');
}
{
  const f=fixture(),value=profile();
  value.findings[0].related_product_marketing=Array.from({length:4},(_,index)=>{
    const id=String(index+2).repeat(32),reference=ref.replace(publicationId,id);
    return {...related(),publication_id:id,ref:reference,url:href(reference),turn_id:61+index,visitor_message_id:71+index*2,answer_message_id:72+index*2};
  });
  const bounded=f.profileModel(value)?.findings[0].related_product_marketing.length===4;
  const id='6'.repeat(32),reference=ref.replace(publicationId,id);
  value.findings[0].related_product_marketing.push({...related(),publication_id:id,ref:reference,url:href(reference),turn_id:65,visitor_message_id:79,answer_message_id:80});
  check(bounded&&f.profileModel(value)===null,'four distinct proven related links are accepted but a fifth exceeds the bounded source contract');
}
for(const mutate of [v=>v.findings[0].related_product_marketing[0].public_conversation_id=52,v=>v.findings[0].related_product_marketing[0].relation='inferred_interest',
  v=>v.findings[0].related_product_marketing[0].answer_message_id=70,v=>v.findings[0].related_product_marketing[0].revision='bad',
  v=>v.findings[0].related_product_marketing[0].publication_id='d'.repeat(32),v=>v.findings[0].related_product_marketing[0].question='x'.repeat(601),
  v=>v.findings[0].related_product_marketing.push(v.findings[0].related_product_marketing[0]),v=>v.findings[0].related_product_marketing_coverage='unavailable',
  v=>{v.findings[0].ref='profile:call:51';v.findings[0].profile_source_kind='call';}]){
  const f=fixture(),value=profile();mutate(value);check(f.profileModel(value)===null,'unproven, mismatched or malformed related association is rejected');
}
console.log('PRODUCT_MARKETING_REPORT=PASS ('+checks+' checks; canonical Hub summaries and exact related links)');
