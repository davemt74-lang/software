import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const loader = read('includes/subscriptions.php');
const schema = read('includes/subscription-self-service-schema.php');
const runtime = read('includes/subscription-self-service.php');
const lifecycle = read('includes/subscription-lifecycle.php');
const page = read('subscription.php');
const upgrade = read('upgrade.php');

assert.ok(loader.includes("subscription-self-service-schema.php") && loader.includes("subscription-self-service.php"), 'canonical subscription loader must include self-service modules');
assert.ok(schema.includes('CREATE TABLE IF NOT EXISTS subscription_plan_requests'), 'self-service plan intents need durable storage');
for (const field of ['subscription_id','from_package_id','target_package_id','billing_interval','status','amount_cents','effective_at','resolved_at']) {
  assert.ok(schema.includes(field), `plan request schema must preserve ${field}`);
}
assert.ok(upgrade.includes('subscription_self_service_schema_ready()'), 'database upgrade readiness must include self-service plan storage');
assert.ok(upgrade.includes('subscription_self_service_ensure_schema();'), 'database upgrade must create self-service plan storage explicitly');

assert.ok(runtime.includes("(int)$package['is_public']!==1"), 'self-service must reject hidden packages');
assert.ok(runtime.includes("(int)$package['is_trial']===1"), 'self-service must prevent manual trial replay');
assert.ok(runtime.includes("'pending_billing'"), 'paid selections must have a pending-billing state');
assert.match(runtime, /if\(\$price>0\)[\s\S]*subscription_self_service_insert_request[\s\S]*pending_billing/, 'paid plans must create a request rather than granting access');
const paidBlock = runtime.match(/if\(\$price>0\)\{([\s\S]*?)\n\s*\}/)?.[1] ?? '';
assert.ok(!paidBlock.includes('subscription_assign_package'), 'paid plan selection must never grant a package before payment');
assert.ok(runtime.includes("'scheduled'"), 'period-end changes need a scheduled state');
assert.ok(runtime.includes("(int)($current['billing_required']??0)===1"), 'paid current access must be preserved through its period end');
assert.ok(runtime.includes("This package is managed by an administrator and cannot be canceled from this page."), 'admin-managed packages must not be self-cancelled');
assert.ok(runtime.includes('subscription_self_service_cancel_request'), 'pending/scheduled changes must be reversible');
assert.ok(runtime.includes('subscription_audit'), 'self-service plan mutations must be audited');
assert.ok(runtime.includes("SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE"), 'plan mutations must serialize on the user account');

assert.ok(lifecycle.indexOf('subscription_self_service_apply_due_for_user') < lifecycle.indexOf('subscription_lifecycle_refresh_user_period($userId,$pdo)'), 'scheduled changes must execute before recurring period rollover');
assert.ok(runtime.includes("SELECT * FROM user_subscriptions WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE"), 'period-end actions must bind to the exact subscription even after a trial expires');

assert.ok(page.includes("action\" value=\"select_plan") || page.includes('value="select_plan"'), 'plan page must expose self-service plan selection');
assert.ok(page.includes('value="cancel_request"'), 'plan page must allow reversing a pending change');
assert.ok(page.includes('value="schedule_cancel"'), 'plan page must support period-end cancellation for billable subscriptions');
assert.ok(page.includes('csrf_field()'), 'all plan mutations must remain CSRF protected');
assert.ok(page.includes('subscription_packages(true)'), 'plan comparison must come from live public Admin packages');
assert.ok(page.includes('subscription_self_service_price_cents'), 'plan page must use canonical package pricing');
assert.ok(page.includes('billing=monthly') && page.includes('billing=annual'), 'plan page must support monthly/annual comparison');
assert.ok(page.includes('Plan Activity'), 'customer must be able to review self-service plan history');
assert.ok(page.includes('AI Usage by Feature') && page.includes('Token Credits'), 'plan management must retain quota and top-up visibility');
assert.ok(!page.includes('subscription_self_service_ensure_schema'), 'customer GET/POST must never perform schema DDL');

console.log('SELF_SERVICE_PLAN_MANAGEMENT_CONTRACT=PASS');
