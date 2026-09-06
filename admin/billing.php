<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
if(!billing_schema_ready($pdo)){flash('error','Run the database upgrade before configuring billing.');redirect(url('/upgrade.php'));}

$error='';$syncSummary=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{
            $action=(string)($_POST['action']??'');
            if($action==='sync_catalog'){
                if(!billing_stripe_configured())throw new RuntimeException('Add the Stripe secret key to config.php or STRIPE_SECRET_KEY before syncing.');
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

$mappings=$pdo->query("SELECT bp.*,p.name package_name,p.slug package_slug FROM package_billing_prices bp INNER JOIN subscription_packages p ON p.id=bp.package_id WHERE bp.provider='stripe' ORDER BY p.sort_order,p.name,bp.billing_interval")->fetchAll()?:[];
$billingSubs=$pdo->query("SELECT bs.*,u.display_name,u.email,p.name package_name FROM billing_subscriptions bs INNER JOIN users u ON u.id=bs.user_id LEFT JOIN subscription_packages p ON p.id=bs.package_id WHERE bs.provider='stripe' ORDER BY bs.updated_at DESC LIMIT 50")->fetchAll()?:[];
$events=$pdo->query("SELECT event_id,event_type,livemode,status,error_message,processed_at,created_at FROM billing_webhook_events WHERE provider='stripe' ORDER BY id DESC LIMIT 30")->fetchAll()?:[];
$webhookUrl='';try{$webhookUrl=billing_absolute_url('/billing-webhook.php');}catch(Throwable $e){}
$adminTitle='Billing';$adminActive='billing';require __DIR__.'/_header.php';
?>
<div class="admin-section-heading"><div><span class="eyebrow">Monetization</span><h2>Stripe Billing</h2><p>Checkout, recurring subscriptions, verified webhooks, package price synchronization and customer billing status.</p></div><a class="button" href="<?= e(url('/admin/packages.php')) ?>">Packages</a></div>
<?php if($error):?><div class="notice error"><?= e($error) ?></div><?php endif;?>
<div class="admin-grid admin-grid-2">
<section class="admin-card"><div class="admin-card-head"><div><h3>Provider Status</h3><p>Secrets stay in server configuration and are never stored in the database.</p></div></div><table class="admin-table"><tbody>
<tr><td>Provider</td><td><strong>Stripe</strong></td></tr>
<tr><td>Secret key</td><td><?= billing_stripe_secret_key()!==''?'Configured':'Missing' ?></td></tr>
<tr><td>Webhook secret</td><td><?= billing_stripe_webhook_secret()!==''?'Configured':'Missing' ?></td></tr>
<tr><td>Currency</td><td><?= e(strtoupper(billing_currency())) ?></td></tr>
<tr><td>Webhook endpoint</td><td><code><?= e($webhookUrl!==''?$webhookUrl:'Set site.base_url first') ?></code></td></tr>
</tbody></table><div class="form-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_connection"><button class="button" type="submit">Test Stripe Connection</button></form></div></section>
<section class="admin-card"><div class="admin-card-head"><div><h3>Stripe Catalog</h3><p>VP3 package prices are authoritative. Sync creates or reuses Stripe Products and recurring Prices.</p></div></div><p><?= count($mappings) ?> active package/interval mappings are stored locally.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_catalog"><button class="button primary" type="submit">Sync Stripe Catalog</button></form><?php if($syncSummary):?><div style="margin-top:14px"><strong>Last sync</strong><ul><?php foreach($syncSummary as $line):?><li><?= e($line) ?></li><?php endforeach;?></ul></div><?php endif;?></section>
</div>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Package Price Mappings</h3><p>These IDs are generated by VP3; checkout never trusts a browser-supplied Stripe Price ID.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Package</th><th>Interval</th><th>Amount</th><th>Product</th><th>Price</th><th>Updated</th></tr></thead><tbody><?php foreach($mappings as $row):?><tr><td><strong><?= e((string)$row['package_name']) ?></strong><br><small><?= e((string)$row['package_slug']) ?></small></td><td><?= e(ucfirst((string)$row['billing_interval'])) ?></td><td><?= e(strtoupper((string)$row['currency'])) ?> <?= number_format((int)$row['unit_amount_cents']/100,2) ?></td><td><code><?= e((string)$row['provider_product_id']) ?></code></td><td><code><?= e((string)$row['provider_price_id']) ?></code></td><td><?= e((string)$row['updated_at']) ?></td></tr><?php endforeach;?><?php if(!$mappings):?><tr><td colspan="6">No Stripe prices synchronized yet.</td></tr><?php endif;?></tbody></table></div></section>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Billing Subscriptions</h3><p>Provider state linked to the entitlement-bearing local subscription.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Package</th><th>Status</th><th>Interval</th><th>Period end</th><th>Stripe subscription</th></tr></thead><tbody><?php foreach($billingSubs as $row):?><tr><td><strong><?= e((string)$row['display_name']) ?></strong><br><small><?= e((string)$row['email']) ?></small></td><td><?= e((string)($row['package_name']??'Unmapped')) ?></td><td><?= e((string)$row['status']) ?><?= (int)$row['cancel_at_period_end']===1?' · cancels at period end':'' ?></td><td><?= e((string)$row['billing_interval']) ?></td><td><?= e((string)($row['current_period_end']??'—')) ?></td><td><code><?= e((string)$row['provider_subscription_id']) ?></code></td></tr><?php endforeach;?><?php if(!$billingSubs):?><tr><td colspan="6">No Stripe subscriptions have been linked yet.</td></tr><?php endif;?></tbody></table></div></section>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Webhook Events</h3><p>Verified Stripe events are idempotently recorded before processing.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Event</th><th>Type</th><th>Mode</th><th>Status</th><th>Processed</th></tr></thead><tbody><?php foreach($events as $row):?><tr><td><code><?= e((string)$row['event_id']) ?></code></td><td><?= e((string)$row['event_type']) ?></td><td><?= (int)$row['livemode']===1?'Live':'Test' ?></td><td><?= e((string)$row['status']) ?><?php if((string)$row['error_message']!==''):?><br><small><?= e((string)$row['error_message']) ?></small><?php endif;?></td><td><?= e((string)($row['processed_at']??$row['created_at'])) ?></td></tr><?php endforeach;?><?php if(!$events):?><tr><td colspan="5">No Stripe webhook events received yet.</td></tr><?php endif;?></tbody></table></div></section>
<?php require __DIR__.'/_footer.php'; ?>
