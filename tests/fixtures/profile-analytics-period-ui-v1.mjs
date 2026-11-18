export const metricCatalog={
  visit_records:['events','count'],recognized_browser_contacts:['events','count'],
  accepted_visitor_turns:['conversations','count'],active_conversations:['conversations','count'],new_conversations:['conversations','count'],
  owner_takeovers:['conversations','count'],owner_reply_messages:['conversations','count'],agent_reply_messages:['conversations','count'],
  median_first_reply_seconds:['conversations','seconds'],calls_offered:['calls','count'],calls_accepted:['calls','count'],calls_finished:['calls','count'],
  median_call_accept_seconds:['calls','seconds'],recorded_product_conversions:['commerce','count'],recorded_booking_conversions:['commerce','count'],
  verified_paid_orders:['commerce','count'],verified_refunded_orders:['commerce','count'],
  image_creations_completed:['images','count'],image_initial_creations:['images','count'],
  image_revisions_completed:['images','count'],image_requests_failed:['images','count']
};
export const cutoff=Math.floor(Date.parse('2026-11-18T00:00:00Z')/1000);
export const periodRef=(days=1,end=cutoff,zone='UTC',source='u')=>'profile:analytics:v1:'+days+':'+end+':'+source+':'+Buffer.from(zone).toString('base64url');
const local=(ms,zone)=>{
  const parts=new Intl.DateTimeFormat('en-CA',{timeZone:zone,hourCycle:'h23',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'}).formatToParts(ms);
  const get=key=>parts.find(item=>item.type===key).value;
  const clock=Date.UTC(Number(get('year')),Number(get('month'))-1,Number(get('day')),Number(get('hour')),Number(get('minute')),Number(get('second')));
  const offset=(clock-ms)/60000,sign=offset<0?'-':'+';
  return get('year')+'-'+get('month')+'-'+get('day')+'T'+get('hour')+':'+get('minute')+':'+get('second')+sign+String(Math.floor(Math.abs(offset)/60)).padStart(2,'0')+':'+String(Math.abs(offset)%60).padStart(2,'0');
};
export function periodValue(ref=periodRef()){
  const [, , , daysText,endText,source,encoded]=ref.split(':'),days=Number(daysText),end=Number(endText),zone=Buffer.from(encoded,'base64url').toString();
  const from=(end-days*86400)*1000,previous=from-days*86400000;
  const point=n=>({value:n,observed_value:null,coverage:'complete',reason:'complete'});
  const currentCohort={eligible:3,replied:2,unanswered:1,unverifiable:0,coverage:'complete',reason:'complete'};
  const calls={accepted:{direct:2,assisted:1},finished:{ended:1,declined:1,cancelled:1,expired:0,failed:0},acceptance_delay:{eligible:3,verified:3,unverifiable:0}};
  const imageCounts={image_creations_completed:[8,4],image_initial_creations:[5,3],image_revisions_completed:[3,1],image_requests_failed:[2,1]};
  const imageSide=previous=>({coverage:'complete',reason:'complete',rankings_coverage:'complete',failure_coverage:'complete',
    top_catalogs:previous?[{collection_id:41,name:'Studio portraits',completed_count:4,initial_count:3,revision_count:1}]
      :[{collection_id:41,name:'Studio portraits',completed_count:5,initial_count:3,revision_count:2},
        {collection_id:42,name:'Soft watercolor',completed_count:3,initial_count:2,revision_count:1}],
    top_images:[{asset_id:501,title:'Warm window light',completed_count:previous?4:5},...previous?[]:[{asset_id:502,title:'Paper texture',completed_count:3}]],
    catalogs_truncated:false,images_truncated:false,failures:[{code:'provider_busy',label:'Image provider was busy',count:previous?1:2}]});
  const allowanceDay=Math.floor((end+3600)*1000/86400000)*86400000;
  return {contract:'vp3.profile-analytics-period.v1',ref,revision:'a'.repeat(64),coverage:'ready',observed_at_utc_ms:(end+3600)*1000,
    window:{period:days,timezone:zone,timezone_source:{e:'explicit',s:'schedule',u:'utc_fallback'}[source],
      from_local:local(from,zone),to_local:local(end*1000,zone),from_utc_ms:from,to_utc_ms:end*1000,as_of_utc_ms:end*1000,
      previous:{from_local:local(previous,zone),to_local:local(from,zone),from_utc_ms:previous,to_utc_ms:from}},
    period_options:[1,7,30].map(period=>({period,label:period+' '+(period===1?'day':'days'),ref:periodRef(period,end,zone,source)})),
    groups:Object.fromEntries(['images','events','conversations','calls','commerce'].map(key=>[key,{coverage:'ready',reason:'complete'}])),
    metrics:Object.entries(metricCatalog).map(([key,[group,unit]])=>{const [current,previous]=imageCounts[key]||[3,2];return {key,group,unit,label:key,definition:'Verified definition for '+key+'.',
      current:point(current),previous:point(previous),comparison:{delta:current-previous,percent:100*(current-previous)/previous}};}),
    image_usage:{contract:'vp3.profile-image-analytics.v1',current:imageSide(false),previous:imageSide(true)},
    first_reply_cohorts:{current:{...currentCohort},previous:{...currentCohort}},
    call_breakdowns:{current:structuredClone(calls),previous:structuredClone(calls)},
    money:[{key:'verified_payments',currency:'USD',amount_unit:'commerce_cent',scale:2,scale_source:'vp3.commerce.v800',
      current:{value_minor:'9007199254740993123',observed_value_minor:null,coverage:'complete',reason:'complete'},
      previous:{value_minor:'9007199254740993122',observed_value_minor:null,coverage:'complete',reason:'complete'},comparison:{delta_minor:'1'}}],
    backlog:{as_of_utc_ms:(end+3600)*1000,revision:'b'.repeat(64),coverage:'ready',
      image_allowance:{coverage:'complete',reason:'complete',enabled:true,timezone:'UTC',day_start_utc_ms:allowanceDay,resets_at_utc_ms:allowanceDay+86400000,
        daily_limit:10,visitor_daily_limit:3,used:4,remaining:6},
      image_failures:{coverage:'complete',reason:'complete',count:3,observed_count:null,
        reasons:[{code:'provider_busy',label:'Image provider was busy',count:2},{code:'other',label:'Image request could not complete',count:1}]},
      metrics:[{key:'current_unresolved_conversations',value:7,coverage:'complete',reason:'complete'},
        {key:'current_unanswered_visitor_turns',value:1,observed_value:null,coverage:'complete',reason:'complete'}]}
  };
}
