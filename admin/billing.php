<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
if(!billing_schema_ready($pdo)){flash('error','Run the database upgrade before configuring billing.');redirect(url('/upgrade.php'));}

function billing_admin_money(int $cents): string{return '$'.number_format(max(0,$cents)/100,2);}
function billing_admin_short_number(int $value): string
{
    $value=max(0,$value);
    if($value>=1000000000)return rtrim(rtrim(number_format($value/1000000000,1,'.',''),'0'),'.').'B';
    if($value>=1000000)return rtrim(rtrim(number_format($value/1000000,1,'.',''),'0'),'.').'M';
    if($value>=1000)return rtrim(rtrim(number_format($value/1000,1,'.',''),'0'),'.').'K';
    return number_format($value);
}

$error='';$syncSummary=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{
            $action=(string)($_POST['action']??'');
            if($action==='sync_catalog'){
                if(!billing_stripe_configured())throw new RuntimeException('Add the Stripe secret key to server configuration or the STRIPE_SECRET_KEY environment variable before syncing.');
                foreach(subscription_packages(true) as $row){
                    if((int)($row['is_trial']??0)===1)continue;
                    $package=subscription_package((int)$row['id'])?:$row;
                    foreach(['monthly','annual'] as $interval){
                        $amount=subscription_self_service_price_cents($package,$interval);if($amount===null||$amount<1)continue;
                        try{$mapping=billing_stripe_ensure_package_price($package,$interval,$pdo);$syncSummary[]=(string)$package['name'].' · '.ucfirst($interval).' → '.(string)$mapping['provider_price_id'];}
                        catch(Throwable $e){$syncSummary[]=(string)$package['name'].' · '.ucfirst($interval).' ERROR: '.$e->getMessage();}
                    }
                }
                flash('notice','Stripe catalog sync completed.');
            }elseif($action==='test_connection'){
                if(!billing_stripe_configured())throw new RuntimeException('Stripe is not configured.');
                $account=billing_stripe_request('GET','account');$name=trim((string)($account['business_profile']['name']??$account['settings']['dashboard']['display_name']??$account['id']??'Stripe account'));
                flash('notice','Stripe connection verified: '.$name.'.');
            }else throw new RuntimeException('Unknown billing action.');
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}

$mappings=$pdo->query("SELECT bp.*,p.name package_name,p.slug package_slug FROM package_billing_prices bp INNER JOIN subscription_packages p ON p.id=bp.package_id WHERE bp.provider='stripe' ORDER BY p.sort_order,p.name,bp.billing_interval,bp.is_active DESC,bp.id DESC")->fetchAll()?:[];
$activeMappingCount=0;foreach($mappings as $mappingRow){if((int)$mappingRow['is_active']===1)$activeMappingCount++;}
$billingSubs=$pdo->query("SELECT bs.*,u.display_name,u.email,p.name package_name FROM billing_subscriptions bs INNER JOIN users u ON u.id=bs.user_id LEFT JOIN subscription_packages p ON p.id=bs.package_id WHERE bs.provider='stripe' ORDER BY bs.updated_at DESC LIMIT 50")->fetchAll()?:[];
$events=$pdo->query("SELECT event_id,event_type,livemode,status,error_message,processed_at,created_at FROM billing_webhook_events WHERE provider='stripe' ORDER BY id DESC LIMIT 30")->fetchAll()?:[];
$webhookUrl='';try{$webhookUrl=billing_absolute_url('/billing-webhook.php');}catch(Throwable $e){}

$intel=subscription_intelligence_summary($pdo);
$packageMix=subscription_intelligence_package_mix($pdo);
$trialsEnding=subscription_intelligence_trials_ending(7,$pdo);
$aiPackages=subscription_intelligence_ai_by_package(30,$pdo);
$aiScopes=subscription_intelligence_ai_by_scope(30,$pdo);
$heavyUsers=subscription_intelligence_heavy_users(30,20,$pdo);
$aiDaily=subscription_intelligence_ai_daily(30,$pdo);
$creditSources=subscription_intelligence_credit_sources(30,$pdo);
$runRatePackages=subscription_intelligence_run_rate_by_package($pdo);
$dailyMax=0;foreach($aiDaily as $row)$dailyMax=max($dailyMax,(int)$row['tokens']);

$adminTitle='Billing';$adminActive='billing';require __DIR__.'/_header.php';
?>
<style>
.billing-intel-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px}.billing-intel-stat{border:1px solid #e3e7ed;border-radius:12px;background:#fff;padding:15px}.billing-intel-stat small{display:block;color:#687180;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}.billing-intel-stat strong{display:block;margin-top:5px;font-size:1.55rem;line-height:1.1;color:#161b22}.billing-intel-stat span{display:block;margin-top:5px;color:#6b7280;font-size:.76rem;line-height:1.35}.billing-intel-chart{display:flex;align-items:flex-end;gap:4px;height:150px;padding:14px 4px 24px;border-bottom:1px solid #e5e7eb}.billing-intel-bar{position:relative;flex:1;min-width:4px;max-width:24px;height:var(--bar-height);min-height:2px;border-radius:3px 3px 0 0;background:#1f2937}.billing-intel-bar:hover:after{content:attr(data-label);position:absolute;left:50%;bottom:calc(100% + 6px);transform:translateX(-50%);white-space:nowrap;border:1px solid #d1d5db;border-radius:6px;background:#fff;padding:4px 6px;color:#111827;font-size:10px;z-index:4;box-shadow:0 4px 14px rgba(0,0,0,.08)}.billing-intel-note{color:#667085;font-size:.78rem;line-height:1.45}.billing-intel-danger{color:#b42318;font-weight:700}@media(max-width:1050px){.billing-intel-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.billing-intel-grid{grid-template-columns:1fr}}
</style>
<div class="admin-section-heading"><div><span class="eyebrow">Monetization</span><h2>Stripe Billing & Subscription Intelligence</h2><p>Recurring subscription state, package run-rate, trial conversion, AI consumption and one-time token revenue.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="button" href="<?= e(url('/admin/packages.php')) ?>">Packages</a><?php if(token_pack_schema_ready($pdo)):?><a class="button" href="<?= e(url('/admin/token-packs.php')) ?>">AI Token Packs</a><?php endif;?></div></div>
<?php if($error):?><div class="notice error"><?= e($error) ?></div><?php endif;?>

<div class="billing-intel-grid">
  <section class="billing-intel-stat"><small>Recurring MRR</small><strong><?= e(billing_admin_money((int)($intel['mrr_cents']??0))) ?></strong><span><?= number_format((int)($intel['paid_accounts']??0)) ?> active Stripe-paid account<?= (int)($intel['paid_accounts']??0)===1?'':'s' ?> · current provider + local entitlement only</span></section>
  <section class="billing-intel-stat"><small>Recurring ARR</small><strong><?= e(billing_admin_money((int)($intel['arr_cents']??0))) ?></strong><span>Annualized active recurring run-rate. This is not a collected-cash total.</span></section>
  <section class="billing-intel-stat"><small>Trial → Paid</small><strong><?= e(number_format((float)($intel['trial_conversion_percent']??0),1)) ?>%</strong><span><?= number_format((int)($intel['trial_converted_accounts']??0)) ?> of <?= number_format((int)($intel['trial_started_accounts']??0)) ?> accounts that ever entered a VP3 trial later created a Stripe subscription.</span></section>
  <section class="billing-intel-stat"><small>Token Revenue · 30d</small><strong><?= e(billing_admin_money((int)($intel['token_pack_revenue_30d_cents']??0))) ?></strong><span><?= number_format((int)($intel['token_pack_sales_30d']??0)) ?> verified credited token-pack purchase<?= (int)($intel['token_pack_sales_30d']??0)===1?'':'s' ?>.</span></section>
  <section class="billing-intel-stat"><small>Current Accounts</small><strong><?= number_format((int)($intel['current_accounts']??0)) ?></strong><span><?= number_format((int)($intel['trialing']??0)) ?> trial · <?= number_format((int)($intel['active']??0)) ?> active · <?= number_format((int)($intel['complimentary']??0)) ?> complimentary</span></section>
  <section class="billing-intel-stat"><small>AI Tokens · 30d</small><strong><?= e(billing_admin_short_number((int)($intel['ai_tokens_30d']??0))) ?></strong><span><?= number_format((int)($intel['ai_requests_30d']??0)) ?> metered provider request<?= (int)($intel['ai_requests_30d']??0)===1?'':'s' ?>.</span></section>
  <section class="billing-intel-stat"><small>Purchased Tokens · 30d</small><strong><?= e(billing_admin_short_number((int)($intel['purchased_tokens_30d']??0))) ?></strong><span>Credits created from verified <code>purchased_topup</code> transactions.</span></section>
  <section class="billing-intel-stat"><small>All Token Revenue</small><strong><?= e(billing_admin_money((int)($intel['token_pack_revenue_all_cents']??0))) ?></strong><span><?= number_format((int)($intel['token_pack_sales_all']??0)) ?> verified credited one-time sale<?= (int)($intel['token_pack_sales_all']??0)===1?'':'s' ?>.</span></section>
</div>

<div class="admin-grid admin-grid-2">
<section class="admin-card"><div class="admin-card-head"><div><h3>Trials Ending in 7 Days</h3><p>Current trial subscriptions ordered by expiration.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Package</th><th>Ends</th><th>AI used</th></tr></thead><tbody><?php foreach($trialsEnding as $row):$end=(string)($row['ends_at']?:$row['current_period_end']);?><tr><td><strong><?= e((string)$row['display_name']) ?></strong><br><small><?= e((string)$row['email']) ?></small></td><td><?= e((string)$row['package_name']) ?></td><td><span class="billing-intel-danger"><?= e(date('M j, Y',strtotime($end))) ?></span></td><td><?= number_format((int)$row['tokens_used']) ?></td></tr><?php endforeach;?><?php if(!$trialsEnding):?><tr><td colspan="4">No current trials expire in the next 7 days.</td></tr><?php endif;?></tbody></table></div></section>
<section class="admin-card"><div class="admin-card-head"><div><h3>Current Package Mix</h3><p>Commercial package distribution across current local subscriptions.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Package</th><th>Accounts</th><th>Trial</th><th>Active</th><th>Comp</th></tr></thead><tbody><?php foreach($packageMix as $row):?><tr><td><strong><?= e((string)$row['name']) ?></strong></td><td><?= number_format((int)$row['account_count']) ?></td><td><?= number_format((int)$row['trialing_count']) ?></td><td><?= number_format((int)$row['active_count']) ?></td><td><?= number_format((int)$row['complimentary_count']) ?></td></tr><?php endforeach;?><?php if(!$packageMix):?><tr><td colspan="5">No current package assignments.</td></tr><?php endif;?></tbody></table></div></section>
</div>

<div class="admin-grid admin-grid-2" style="margin-top:18px">
<section class="admin-card"><div class="admin-card-head"><div><h3>Recurring Run-Rate by Package</h3><p>Normalized monthly value from each active Stripe Price mapping.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Package</th><th>Paid accounts</th><th>MRR</th><th>ARR</th></tr></thead><tbody><?php foreach($runRatePackages as $row):$mrr=(int)$row['mrr_cents'];?><tr><td><strong><?= e((string)$row['package_name']) ?></strong></td><td><?= number_format((int)$row['paid_accounts']) ?></td><td><?= e(billing_admin_money($mrr)) ?></td><td><?= e(billing_admin_money($mrr*12)) ?></td></tr><?php endforeach;?><?php if(!$runRatePackages):?><tr><td colspan="4">No active paid Stripe subscriptions.</td></tr><?php endif;?></tbody></table></div><p class="billing-intel-note">Run-rate is derived from immutable Stripe Price mappings linked to current active local subscriptions. It is intentionally not presented as collected revenue.</p></section>
<section class="admin-card"><div class="admin-card-head"><div><h3>AI Credit Sources · 30 Days</h3><p>Purchased, Admin and promotional capacity added to user balances.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Source</th><th>Credits</th><th>Granted</th><th>Remaining</th></tr></thead><tbody><?php foreach($creditSources as $row):?><tr><td><strong><?= e((string)$row['source']) ?></strong></td><td><?= number_format((int)$row['credits']) ?></td><td><?= number_format((int)$row['granted']) ?></td><td><?= number_format((int)$row['remaining']) ?></td></tr><?php endforeach;?><?php if(!$creditSources):?><tr><td colspan="4">No AI credits created in the last 30 days.</td></tr><?php endif;?></tbody></table></div></section>
</div>

<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>AI Usage Trend · 30 Days</h3><p>Actual provider tokens recorded in the canonical AI usage ledger.</p></div><strong><?= e(billing_admin_short_number((int)($intel['ai_tokens_30d']??0))) ?> tokens</strong></div><?php if($aiDaily):?><div class="billing-intel-chart" role="img" aria-label="Daily AI token usage for the last 30 days"><?php foreach($aiDaily as $row):$tokens=(int)$row['tokens'];$height=$dailyMax>0?max(2,(int)round(($tokens/$dailyMax)*100)):2;$label=date('M j',strtotime((string)$row['usage_date'])).' · '.number_format($tokens).' tokens';?><i class="billing-intel-bar" style="--bar-height:<?= $height ?>%" data-label="<?= e($label) ?>" title="<?= e($label) ?>"></i><?php endforeach;?></div><?php else:?><p>No metered AI usage in the last 30 days.</p><?php endif;?></section>

<div class="admin-grid admin-grid-2" style="margin-top:18px">
<section class="admin-card"><div class="admin-card-head"><div><h3>AI Consumption by Package · 30 Days</h3><p>Historical usage is attributed to the subscription ID recorded on each AI request.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Package</th><th>Users</th><th>Requests</th><th>Tokens</th></tr></thead><tbody><?php foreach($aiPackages as $row):?><tr><td><strong><?= e((string)$row['package_name']) ?></strong></td><td><?= number_format((int)$row['users']) ?></td><td><?= number_format((int)$row['requests']) ?></td><td><?= number_format((int)$row['tokens']) ?></td></tr><?php endforeach;?><?php if(!$aiPackages):?><tr><td colspan="4">No AI usage in the last 30 days.</td></tr><?php endif;?></tbody></table></div></section>
<section class="admin-card"><div class="admin-card-head"><div><h3>AI Consumption by Feature · 30 Days</h3><p>Scopes are the canonical feature labels recorded by AI metering.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Scope</th><th>Users</th><th>Requests</th><th>Tokens</th></tr></thead><tbody><?php foreach($aiScopes as $row):?><tr><td><strong><?= e((string)$row['scope']) ?></strong></td><td><?= number_format((int)$row['users']) ?></td><td><?= number_format((int)$row['requests']) ?></td><td><?= number_format((int)$row['tokens']) ?></td></tr><?php endforeach;?><?php if(!$aiScopes):?><tr><td colspan="4">No AI usage in the last 30 days.</td></tr><?php endif;?></tbody></table></div></section>
</div>

<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Highest AI Usage · 30 Days</h3><p>Use this to identify package pressure, support needs and likely upgrade opportunities.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Current package</th><th>Requests</th><th>Total tokens</th><th>Package tokens</th><th>Top-up tokens</th></tr></thead><tbody><?php foreach($heavyUsers as $row):?><tr><td><strong><?= e((string)$row['display_name']) ?></strong><br><small><?= e((string)$row['email']) ?></small></td><td><?= e((string)($row['package_name']??'—')) ?></td><td><?= number_format((int)$row['requests']) ?></td><td><strong><?= number_format((int)$row['tokens']) ?></strong></td><td><?= number_format((int)$row['package_tokens_used']) ?></td><td><?= number_format((int)$row['credit_tokens_used']) ?></td></tr><?php endforeach;?><?php if(!$heavyUsers):?><tr><td colspan="6">No AI usage in the last 30 days.</td></tr><?php endif;?></tbody></table></div></section>

<div class="admin-grid admin-grid-2" style="margin-top:18px">
<section class="admin-card"><div class="admin-card-head"><div><h3>Provider Status</h3><p>Secrets stay in server configuration and are never stored in the database.</p></div></div><table class="admin-table"><tbody>
<tr><td>Provider</td><td><strong>Stripe</strong></td></tr>
<tr><td>Secret key</td><td><?= billing_stripe_secret_key()!==''?'Configured':'Missing' ?></td></tr>
<tr><td>Webhook secret</td><td><?= billing_stripe_webhook_secret()!==''?'Configured':'Missing' ?></td></tr>
<tr><td>Currency</td><td><?= e(strtoupper(billing_currency())) ?></td></tr>
<tr><td>Webhook endpoint</td><td><code><?= e($webhookUrl!==''?$webhookUrl:'Set site.base_url first') ?></code></td></tr>
</tbody></table><div class="form-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_connection"><button class="button" type="submit">Test Stripe Connection</button></form></div></section>
<section class="admin-card"><div class="admin-card-head"><div><h3>Stripe Catalog</h3><p>VP3 package prices are authoritative. Sync creates immutable Stripe Prices and preserves old mappings for existing subscribers.</p></div></div><p><?= $activeMappingCount ?> active package/interval mappings<?= count($mappings)>$activeMappingCount?' · '.(count($mappings)-$activeMappingCount).' historical':'' ?> are stored locally.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_catalog"><button class="button primary" type="submit">Sync Stripe Catalog</button></form><?php if($syncSummary):?><div style="margin-top:14px"><strong>Last sync</strong><ul><?php foreach($syncSummary as $line):?><li><?= e($line) ?></li><?php endforeach;?></ul></div><?php endif;?></section>
</div>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Package Price Mappings</h3><p>Checkout uses only the active Price. Historical Price IDs remain mapped so existing Stripe subscriptions continue reconciling after price changes.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Package</th><th>Interval</th><th>Amount</th><th>State</th><th>Product</th><th>Price</th><th>Updated</th></tr></thead><tbody><?php foreach($mappings as $row):?><tr><td><strong><?= e((string)$row['package_name']) ?></strong><br><small><?= e((string)$row['package_slug']) ?></small></td><td><?= e(ucfirst((string)$row['billing_interval'])) ?></td><td><?= e(strtoupper((string)$row['currency'])) ?> <?= number_format((int)$row['unit_amount_cents']/100,2) ?></td><td><?= (int)$row['is_active']===1?'<strong>Active</strong>':'Historical' ?></td><td><code><?= e((string)$row['provider_product_id']) ?></code></td><td><code><?= e((string)$row['provider_price_id']) ?></code></td><td><?= e((string)$row['updated_at']) ?></td></tr><?php endforeach;?><?php if(!$mappings):?><tr><td colspan="7">No Stripe prices synchronized yet.</td></tr><?php endif;?></tbody></table></div></section>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Billing Subscriptions</h3><p>Provider state linked to the entitlement-bearing local subscription.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Package</th><th>Status</th><th>Interval</th><th>Period end</th><th>Stripe subscription</th></tr></thead><tbody><?php foreach($billingSubs as $row):?><tr><td><strong><?= e((string)$row['display_name']) ?></strong><br><small><?= e((string)$row['email']) ?></small></td><td><?= e((string)($row['package_name']??'Unmapped')) ?></td><td><?= e((string)$row['status']) ?><?= (int)$row['cancel_at_period_end']===1?' · cancels at period end':'' ?></td><td><?= e((string)$row['billing_interval']) ?></td><td><?= e((string)($row['current_period_end']??'—')) ?></td><td><code><?= e((string)$row['provider_subscription_id']) ?></code></td></tr><?php endforeach;?><?php if(!$billingSubs):?><tr><td colspan="6">No Stripe subscriptions have been linked yet.</td></tr><?php endif;?></tbody></table></div></section>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Webhook Events</h3><p>Verified Stripe events are idempotently recorded before processing. Failed or abandoned processing attempts remain safely retryable.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Type</th><th>Mode</th><th>Status</th><th>Processed</th></tr></thead><tbody><?php foreach($events as $row):?><tr><td><code><?= e((string)$row['event_id']) ?></code></td><td><?= e((string)$row['event_type']) ?></td><td><?= (int)$row['livemode']===1?'Live':'Test' ?></td><td><?= e((string)$row['status']) ?><?php if((string)$row['error_message']!==''):?><br><small><?= e((string)$row['error_message']) ?></small><?php endif;?></td><td><?= e((string)($row['processed_at']??$row['created_at'])) ?></td></tr><?php endforeach;?><?php if(!$events):?><tr><td colspan="5">No Stripe webhook events received yet.</td></tr><?php endif;?></tbody></table></div></section>
<?php require __DIR__.'/_footer.php'; ?>