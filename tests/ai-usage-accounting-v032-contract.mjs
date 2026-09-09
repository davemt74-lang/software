import fs from 'node:fs';

const read=(path)=>fs.readFileSync(path,'utf8');
const must=(condition,message)=>{if(!condition){console.error(`FAIL: ${message}`);process.exit(1);}};

const helper=read('includes/ai-usage-accounting-v032.php');
const chat=read('api/chat-v236.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const member=read('ai-usage.php');
const memberExport=read('ai-usage-export-v032.php');
const admin=read('admin/ai-data-usage-v236.php');
const adminExport=read('admin/ai-usage-export-v032.php');
const nav=read('includes/member-navigation.php');
const quota=read('includes/subscription-quota.php');

must(helper.includes('CREATE TABLE IF NOT EXISTS ai_execution_ledger'),'execution ledger schema missing');
must(helper.includes('cloud_ledger_id'),'cloud billing ledger linkage missing');
must(helper.includes("'homeserver_local','vp3_retrieval','vp3_tool'"),'zero-cloud-cost route classification missing');
must(helper.includes('estimated_cost_micros'),'cost accounting field missing');
must(helper.includes('ai_cost_rates'),'configurable provider/model cost catalog missing');
must(chat.includes('ai_usage_accounting_v032_record($pdo,$user,$activeAgentId,$conversationId,$execution);'),'canonical Agent route does not record final execution');
must(bootstrap.includes("require_once __DIR__.'/ai-usage-accounting-v032.php';"),'accounting runtime is not bootstrapped');
must(upgrade.includes('ai_usage_accounting_v032_schema_ready()')&&upgrade.includes('ai_usage_accounting_v032_ensure_schema();'),'database upgrade contract missing accounting schema');
must(member.includes('HomeServer-local')&&member.includes('VP3 tokens charged')&&member.includes('Export CSV'),'member execution accounting surface incomplete');
must(memberExport.includes('vp3_tokens_charged')&&memberExport.includes('estimated_cost_usd'),'member CSV fields incomplete');
must(admin.includes('Recent AI Executions')&&admin.includes('Execution Mix'),'Admin compute accounting surface incomplete');
must(adminExport.includes("require_permission('ai.manage')"),'Admin CSV is not permission-gated');
must(nav.includes("'ai_usage','AI Usage History'"),'member menu does not expose AI usage history');

// Section 3 must stay additive: the proven package-first, credit-second debit
// engine remains canonical and is not replaced by ai_execution_ledger.
must(quota.includes('Consume the included monthly allowance before purchased/admin token'),'canonical package-before-credit debit behavior changed or disappeared');
must(quota.includes('INSERT INTO ai_usage_ledger'),'canonical billing ledger is no longer present');
must(!helper.includes('UPDATE ai_token_credits SET remaining_amount'),'execution accounting must never debit credits itself');

console.log('AI usage accounting v0.32 integration contract passed.');
