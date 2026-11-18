export const publicationId='a'.repeat(32);
export const periodRef=(days=1)=>`profile:analytics:v1:${days}:1794873600:e:VVRD`;
export const publicationUrl=(days=1,cid=11)=>'/chat.php?agent=7&conversation_id='+cid+'&marketing_plan=992101&marketing_publication='+publicationId+'&marketing_period='+encodeURIComponent(periodRef(days));
const point=value=>({value,observed_value:null,coverage:'complete',reason:'complete'});
export function publication(days=1){
  const cutoff=1794873600000,from=cutoff-days*86400000,previous=from-days*86400000;
  const local=value=>new Date(value).toISOString().replace('.000Z','+00:00');
  const resume={owner_user_id:1,agent_id:7,conversation_id:11,product_id:992101,publication_id:publicationId,source_id:'997601',draft_id:'d'.repeat(64),creative_id:'1'.repeat(32),stage:'promotion_outcomes'};
  return {contract:'vp3.product-marketing-outcome.v1',ref:'marketing:product-outcome:'+publicationId+':'+periodRef(days).slice('profile:analytics:'.length),
    revision:'f'.repeat(64),period_ref:periodRef(days),product_id:992101,product_title:'Handcrafted <img onerror=alert(1)> vase',publication_id:publicationId,promotion_id:'1'.repeat(32),
    original_scope:{agent_id:7,conversation_id:11},source_id:resume.source_id,draft_id:resume.draft_id,creative_id:resume.creative_id,plan_ref:'marketing:product-plan:992101',
    window:{period:days,timezone:'UTC',timezone_source:'explicit',from_local:local(from),to_local:local(cutoff),from_utc_ms:from,to_utc_ms:cutoff,as_of_utc_ms:cutoff,
      previous:{from_local:local(previous),to_local:local(from),from_utc_ms:previous,to_utc_ms:from}},
    period_options:[1,7,30].map(period=>({period,ref:periodRef(period)})),observed_at_utc_ms:1795003200000,coverage:'ready',
    metrics:[['accepted_promotion_clicks',2,1],['native_promotion_conversations',1,0],['checkout_started_orders',0,1],['verified_paid_orders',1,0],['verified_refunded_orders',1,0],['promotion_associated_exchanges',0,1]].map(([key,current,previous])=>({key,label:key,unit:'count',definition:'Verified saved '+key.replaceAll('_',' ')+'.',current:point(current),previous:point(previous),comparison:{delta:current-previous,percent:previous?100*(current-previous)/previous:null}})),
    money:[{key:'verified_payments',currency:'USD',amount_unit:'commerce_cent',scale:2,scale_source:'vp3.commerce.v800',current:{value_minor:'2000',observed_value_minor:null,coverage:'complete',reason:'complete'},previous:{value_minor:'1000',observed_value_minor:null,coverage:'complete',reason:'complete'},comparison:{delta_minor:'1000'}}],
    placements:[{placement:'profile',current:point(1),previous:point(1)},{placement:'profile_agent',current:point(1),previous:point(0)}],
    associations:{coverage:'complete',items:[{public_conversation_id:991002,visitor_message_id:991201,answer_message_id:991202,at_utc_ms:1794812400000,reply_at_utc_ms:1794812460000,excerpt:'Does this vase come in blue? <script>alert(1)</script>',evidence_revision:'7'.repeat(64),url:'/profile-agent.php?tab=inbox&conversation=991002'}]},
    publication_status:'historical',presentation_owner:'initiative_promotion_outcome_v1',resume,
    actions:[{kind:'url',label:'Review this publication',href:publicationUrl(days)},
      {kind:'url',label:'Open original marketing plan',href:'/chat.php?agent=7&conversation_id=11&marketing_plan=992101'},
      {kind:'url',label:'Open product listing',href:'/profile-commerce-products.php?workspace_product=992101'},
      {kind:'chat',label:'View recorded outcome',href:'/chat.php?agent=7&conversation_id=11&message_id=997901',agent_id:7,conversation_id:11,message_id:997901}]};
}
export function plan(){return {product_id:992101,product_title:'Existing product plan',agent_id:7,conversation_id:11,stage:'design_review',stage_label:'Design graphic',needs_attention:false,
  ref:'marketing:product-plan:992101',revision:'e'.repeat(64),coverage:'ready',chat_url:'/chat.php?agent=7&conversation_id=11&marketing_plan=992101',
  actions:[{kind:'url',label:'Review existing plan',href:'/chat.php?agent=7&conversation_id=11&marketing_plan=992101'}],presentation_owner:'initiative_product_launch_v1'};}
export function review(){return {contract:'vp3.product-marketing-review.v1',coverage:'ready',observed_at_utc_ms:1795003200000,plans:[plan()],publications:[publication()]};}
