import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(p)=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const alert=read('includes/profile-commerce-seller-alert-v1140.php');
const lifecycle=read('includes/profile-commerce-lifecycle-v1100.php');
const notifications=read('includes/notifications.php');

assert.match(alert,/profile_commerce_seller_refund_alert_v1140/,'Phase 11.4 must expose a focused seller refund-alert helper');
assert.match(alert,/create_notification\(/,'seller alert must reuse the native durable notification carrier');
assert.match(alert,/profile_commerce_refund_approval_request/,'seller alert must be classified as an approval request requiring attention');
assert.match(alert,/profile-commerce-refund-requests\.php/,'seller alert must deep-link to the refund review workspace');
assert.match(alert,/'profile_commerce_refund_request:'\.\$requestId,\s*\$orderId\s*\)/s,'dedupe identity must bind the refund request id and canonical order id');
assert.match(alert,/request_id/,'seller alert must bind to the canonical customer refund request identity');
assert.doesNotMatch(alert,/INSERT INTO notifications|UPDATE notifications/,'seller alert helper must not duplicate notification persistence');

assert.match(lifecycle,/require_once __DIR__\.'\/profile-commerce-seller-alert-v1140\.php'/,'customer lifecycle must load the Phase 11.4 seller alert helper');
assert.match(lifecycle,/if\(\$existing&&\$existing\['status'\]==='pending'\)\{\$pdo->commit\(\);return/s,'pending retries must return before seller alert emission');
assert.match(lifecycle,/\$pdo->commit\(\);\s*\}catch\(Throwable \$e\)\{[^}]*\}[^\n]*throw \$e;\}\s*profile_commerce_seller_refund_alert_v1140\(\$locked,\$request\)/s,'seller alert must fire only after the canonical refund-request transaction commits');
assert.equal((lifecycle.match(/profile_commerce_seller_refund_alert_v1140\(\$locked,\$request\)/g)||[]).length,1,'customer lifecycle must have exactly one seller-alert emission point');

assert.match(notifications,/source_type=\? AND source_id=\? AND type=\?/,'native notification carrier must deduplicate by source identity and notification type');
assert.match(notifications,/approval_request/,'approval-request notifications must enter the existing attention classifier');

console.log('PROFILE_COMMERCE_SELLER_ALERT_V1140_CONTRACT=PASS');
