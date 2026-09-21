import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);
const safety=read('includes/browser-transaction-safety-v2240.php');
const outcome=read('includes/browser-transaction-outcome-v2250.php');
const continuity=read('includes/browser-transaction-continuity-v2260.php');
const intelligence=read('includes/browser-transaction-intelligence-v2270.php');
const control=read('includes/browser-transaction-control-v2280.php');
const background=read('browser-companion/background.js');
const controlApi=read('api/extension-transaction-control-v2280.php');

const scenarios=[
  {
    name:'successful purchase',
    checks:[
      safety.includes("status='completed'")||safety.includes("'completed'"),
      outcome.includes("outcome_state='confirmed'"),
      continuity.includes("if((string)$intent['status']!=='completed')"),
      background.includes('browserTransactionContinuityEnsureV2260')
    ]
  },
  {
    name:'uncertain submission',
    checks:[
      safety.includes("'uncertain'"),
      outcome.includes('retry_allowed'),
      outcome.includes('confirmed_not_submitted'),
      !controlApi.includes("action==='retry'")
    ]
  },
  {
    name:'booking schedule change',
    checks:[
      continuity.includes("'schedule_changed'"),
      continuity.includes("'calendar_review'"),
      intelligence.includes("'schedule_change'"),
      intelligence.includes("'potential_schedule_conflict'")
    ]
  },
  {
    name:'application rejection',
    checks:[
      continuity.includes("'rejected'"),
      continuity.includes("'decision_received'"),
      intelligence.includes("'application_rejected'")
    ]
  },
  {
    name:'fulfillment exception',
    checks:[
      continuity.includes("'exception'"),
      intelligence.includes("'fulfillment_exception'"),
      intelligence.includes("'prepare_support_request'")
    ]
  },
  {
    name:'reply received',
    checks:[
      continuity.includes("'reply_received'"),
      continuity.includes("'reply_review'"),
      intelligence.includes("'response_received'"),
      intelligence.includes("'prepare_reply'")
    ]
  },
  {
    name:'terminal cancellation',
    checks:[
      continuity.includes("'cancellation'"),
      continuity.includes("$newTracking=$terminal?'closed':'active'"),
      intelligence.includes("'cancellation_change'")
    ]
  },
  {
    name:'stop and resume',
    checks:[
      control.includes('vp3_browser_control_stop_all_v2280'),
      control.includes('vp3_browser_control_resume_all_v2280'),
      control.includes("pause_scope='global'"),
      control.includes("pause_scope='tracker'"),
      control.includes('vp3_browser_control_tracker_action_v2280'),
      control.includes("match_mode='reference' AND reference_hash<>''")
    ]
  },
  {
    name:'explicit forget',
    checks:[
      control.includes("if((string)$row['tracking_status']!=='closed')"),
      control.includes('DELETE FROM browser_transaction_continuities_v2260'),
      control.includes("'submission_history_retained'=>true")
    ]
  },
  {
    name:'global monitoring shutdown',
    checks:[
      control.includes("monitoring_enabled=0"),
      control.includes("'monitoring_stopped'"),
      intelligence.includes('vp3_browser_intelligence_monitoring_enabled_v2270'),
      background.includes("if(!permit||!permit.allowed){")
    ]
  },
  {
    name:'cross-device duplicate suppression',
    checks:[
      control.includes("'leased_to_another_device'"),
      control.includes("'scan_cooldown'"),
      control.includes('device_hash'),
      background.includes("browserTransactionControlApiV2280('scan_permit'")
    ]
  },
  {
    name:'external recovery remains gated',
    checks:[
      intelligence.includes("authorization_path VARCHAR(20) NOT NULL DEFAULT 'v22.40'"),
      controlApi.includes("'external_write_requires_fresh_v2240'=>true"),
      controlApi.includes("'automatic_external_writes'=>false")
    ]
  }
];

for(const scenario of scenarios){
  must(scenario.checks.every(Boolean),'v22.80 release matrix failed: '+scenario.name);
}
must(scenarios.length>=12,'transaction release matrix must remain comprehensive');
console.log('VP3 Browser Companion v22.80 transaction production release matrix passed: '+scenarios.length+' scenarios.');
