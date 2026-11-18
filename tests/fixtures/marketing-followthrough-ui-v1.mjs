import {periodValue,periodRef,cutoff} from './profile-analytics-period-ui-v1.mjs';
export const publicationId='a'.repeat(32),promotionId='b'.repeat(32),draftId='c'.repeat(64);
export const marketingRef=(days=1,at=cutoff,zone='UTC',provenance='u',publication=publicationId)=>'marketing:ft:v1:'+publication+':'+periodRef(days,at,zone,provenance).slice('profile:analytics:v1:'.length);
export function followthroughValue(ref=marketingRef()){
  const match=/^marketing:ft:v1:([a-f0-9]{32}):(.+)$/.exec(ref);if(!match)throw new Error('Bad fixture ref');
  const base=periodValue('profile:analytics:v1:'+match[2]);
  const point=value=>({value,observed_value:null,coverage:'complete',reason:'complete'});
  const definitions=[['recorded_cta_clicks','clicks',3,1],['conversation_attributed_clicks','clicks',2,1],['promotion_linked_exchanges','exchanges',1,0],
    ['recorded_attributed_orders','orders',2,1],['verified_checkout_openings','checkouts',1,1],['verified_paid_orders','commerce',2,1],['verified_refunded_orders','commerce',0,0]];
  const money=structuredClone(base.money);money[0].comparison=null;
  return {contract:'vp3.profile-commerce-followthrough.v1',ref,revision:'d'.repeat(64),coverage:'ready',observed_at_utc_ms:base.observed_at_utc_ms,
    window:base.window,period_options:base.period_options.map(row=>({...row,ref:'marketing:ft:v1:'+match[1]+':'+row.ref.slice('profile:analytics:v1:'.length)})),
    publication:{publication_id:match[1],promotion_id:promotionId,product_id:87,chat_agent_id:7,chat_conversation_id:11,source_asset_id:23,draft_key:draftId,
      title:'Saved shirt promotion <img src=x onerror=alert(1)>',recorded_at_utc_ms:cutoff*1000-31*86400000},
    metrics:definitions.map(([key,group,current,previous])=>({key,group,label:key,unit:'count',definition:'Saved exact '+key+' observations.',current:point(current),previous:point(previous),comparison:null})),
    groups:Object.fromEntries(['clicks','exchanges','orders','checkouts','commerce'].map(key=>[key,{coverage:'ready',reason:'complete'}])),money,
    click_breakdowns:{current:{profile:1,profile_agent:2,coverage:'complete',reason:'complete'},previous:{profile:0,profile_agent:1,coverage:'complete',reason:'complete'}},
    exchanges:{coverage:'ready',reason:'complete',total:1,observed_total:null,items:[{public_conversation_id:51,turn_id:61,visitor_message_id:71,answer_message_id:72,
      visitor_at_utc_ms:base.window.from_utc_ms+1000,linked_at_utc_ms:base.window.from_utc_ms+2000,question:'Is this available in blue? <script>bad()</script>',
      answer_excerpt:'Here is the exact promotion shown in this exchange.',revision:'e'.repeat(64),open_selection:{tab:'conversations',publicConversationId:51}}],has_more:false},
    current_plan:{availability:'ready',reason:'complete',revision:'f'.repeat(64),url:'/sub/chat.php?agent=7&conversation_id=11&marketing_plan=87'},
    exact_creative:{availability:'ready',reason:'complete',revision:'9'.repeat(64),marker:{owner_user_id:1,agent_id:7,conversation_id:11,product_id:87,source_id:'23',draft_id:draftId,creative_id:promotionId,stage:'promotion_outcomes'}}};
}
export function publicationsValue(ids=[publicationId]){
  return {coverage:'ready',reason:'complete',product_id:87,publications:ids.map(id=>{
    const report=followthroughValue(marketingRef(1,cutoff,'UTC','u',id));return {...report.publication,ref:report.ref,
      url:'/sub/chat.php?agent=7&conversation_id=11&marketing_followthrough='+encodeURIComponent(report.ref)};
  }),has_more:false,next_cursor:null};
}
