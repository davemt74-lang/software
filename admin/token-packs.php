<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
require_permission('users.manage');

$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
if(!token_pack_schema_ready($pdo))token_pack_ensure_schema($pdo);

function admin_token_pack_price_cents(string $value): int
{
    $value=trim($value);
    if(!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/',$value,$m))throw new RuntimeException('Enter a valid price with no more than two decimal places.');
    $whole=(int)$m[1];$fraction=str_pad((string)($m[2]??''),2,'0');
    if($whole>999999)throw new RuntimeException('Token pack price is too large.');
    return ($whole*100)+(int)$fraction;
}

$error='';$editId=max(0,(int)($_GET['edit']??$_POST['pack_id']??0));
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{
            $action=(string)($_POST['action']??'save');
            if($action==='save'){
                $id=token_pack_save([
                    'id'=>$editId,
                    'name'=>(string)($_POST['name']??''),
                    'slug'=>(string)($_POST['slug']??''),
                    'description'=>(string)($_POST['description']??''),
                    'token_amount'=>(int)($_POST['token_amount']??0),
                    'price_cents'=>admin_token_pack_price_cents((string)($_POST['price_dollars']??'0')),
                    'expires_days'=>(string)($_POST['expires_days']??''),
                    'sort_order'=>(int)($_POST['sort_order']??100),
                    'is_public'=>!empty($_POST['is_public'])?1:0,
                    'is_active'=>!empty($_POST['is_active'])?1:0,
                ]);
                flash('notice','AI token pack saved.');redirect(url('/admin/token-packs.php?edit='.$id));
            }
            if($action==='archive'){
                if($editId<1)throw new RuntimeException('Token pack not found.');
                $pdo->beginTransaction();
                try{
                    $pack=token_pack_find($editId,$pdo,false,true);if(!$pack)throw new RuntimeException('Token pack not found.');
                    $pdo->prepare('UPDATE ai_token_packs SET is_active=0,is_public=0,updated_at=NOW() WHERE id=?')->execute([$editId]);
                    $pdo->commit();
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                flash('notice','AI token pack archived. Historical purchases were preserved.');redirect(url('/admin/token-packs.php'));
            }
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
    }
}

$packs=$pdo->query("SELECT p.*,
    COUNT(CASE WHEN x.status='credited' THEN 1 END) credited_sales,
    COALESCE(SUM(CASE WHEN x.status='credited' THEN x.price_cents ELSE 0 END),0) credited_revenue_cents,
    COALESCE(SUM(CASE WHEN x.status='credited' THEN x.token_amount ELSE 0 END),0) credited_tokens
  FROM ai_token_packs p LEFT JOIN ai_token_pack_purchases x ON x.token_pack_id=p.id
  GROUP BY p.id ORDER BY p.sort_order,p.name,p.id")->fetchAll()?:[];
$pack=$editId>0?token_pack_find($editId,$pdo):null;if($editId>0&&!$pack){$error=$error?:'Token pack not found.';$editId=0;}
$recent=$pdo->query("SELECT x.*,u.email,u.display_name FROM ai_token_pack_purchases x INNER JOIN users u ON u.id=x.user_id ORDER BY x.id DESC LIMIT 50")->fetchAll()?:[];
$stats=$pdo->query("SELECT COUNT(CASE WHEN status='credited' THEN 1 END) sales,COALESCE(SUM(CASE WHEN status='credited' THEN price_cents ELSE 0 END),0) revenue,COALESCE(SUM(CASE WHEN status='credited' THEN token_amount ELSE 0 END),0) tokens FROM ai_token_pack_purchases")->fetch()?:[];
$adminTitle='AI Token Packs';$adminActive='token-packs';require __DIR__.'/_header.php';
?>
<div class="admin-section-heading"><div><span class="eyebrow">Monetization</span><h2>AI Token Packs</h2><p>Sell one-time AI token credits without changing a customer's subscription package.</p></div><a class="button" href="<?= e(url('/admin/token-packs.php')) ?>">New Token Pack</a></div>
<?php if($error):?><div class="notice error"><?= e($error) ?></div><?php endif;?>
<div class="admin-grid admin-grid-3" style="margin-bottom:18px"><section class="admin-card"><small>Credited sales</small><h3><?= number_format((int)($stats['sales']??0)) ?></h3></section><section class="admin-card"><small>Token revenue</small><h3>$<?= number_format(((int)($stats['revenue']??0))/100,2) ?></h3></section><section class="admin-card"><small>Purchased tokens granted</small><h3><?= number_format((int)($stats['tokens']??0)) ?></h3></section></div>
<div class="admin-grid admin-grid-2">
<section class="admin-card"><div class="admin-card-head"><div><h3>Configured Packs</h3><p><?= count($packs) ?> total</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pack</th><th>Price</th><th>Sales</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($packs as $row):?><tr><td><strong><?= e((string)$row['name']) ?></strong><br><small><?= number_format((int)$row['token_amount']) ?> tokens<?= (int)($row['expires_days']??0)>0?' · expires '.$row['expires_days'].'d':'' ?></small></td><td>$<?= number_format(((int)$row['price_cents'])/100,2) ?></td><td><?= number_format((int)$row['credited_sales']) ?><br><small>$<?= number_format(((int)$row['credited_revenue_cents'])/100,2) ?></small></td><td><?= (int)$row['is_active']===1?'Active':'Inactive' ?><?= (int)$row['is_public']===1?' · Public':' · Hidden' ?></td><td><a class="button button-small" href="<?= e(url('/admin/token-packs.php?edit='.(int)$row['id'])) ?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="admin-card"><div class="admin-card-head"><div><h3><?= $pack?'Edit Token Pack':'Create Token Pack' ?></h3><p>Price and token values are snapshotted when Checkout begins.</p></div></div><form method="post" class="admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="pack_id" value="<?= (int)$editId ?>"><div class="form-row"><label>Name<input name="name" maxlength="120" required value="<?= e((string)($pack['name']??'')) ?>"></label><label>Slug<input name="slug" maxlength="80" value="<?= e((string)($pack['slug']??'')) ?>" placeholder="auto-from-name"></label></div><label>Description<textarea name="description" rows="3" maxlength="500"><?= e((string)($pack['description']??'')) ?></textarea></label><div class="form-row"><label>AI tokens<input type="number" min="1000" step="1000" required name="token_amount" value="<?= (int)($pack['token_amount']??50000) ?>"></label><label>Price (USD)<input inputmode="decimal" required name="price_dollars" value="<?= e(number_format(((int)($pack['price_cents']??500))/100,2,'.','')) ?>"></label></div><div class="form-row"><label>Expires after days <small>(blank = no pack expiration)</small><input type="number" min="1" max="3650" name="expires_days" value="<?= e(isset($pack['expires_days'])&&$pack['expires_days']!==null?(string)$pack['expires_days']:'') ?>"></label><label>Sort order<input type="number" name="sort_order" value="<?= (int)($pack['sort_order']??100) ?>"></label></div><div class="admin-check-grid"><label><input type="checkbox" name="is_public" value="1" <?= !$pack||(int)$pack['is_public']===1?'checked':'' ?>> Public storefront</label><label><input type="checkbox" name="is_active" value="1" <?= !$pack||(int)$pack['is_active']===1?'checked':'' ?>> Active</label></div><div class="form-actions"><button class="button primary" type="submit">Save Token Pack</button></div></form><?php if($pack):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Archive this token pack? Existing purchases and credits remain unchanged.')"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="pack_id" value="<?= (int)$editId ?>"><button class="button" type="submit">Archive Pack</button></form><?php endif;?></section></div>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Recent Token Purchases</h3><p>Stripe Checkout history and fulfillment state.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>User</th><th>Pack</th><th>Tokens</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach($recent as $row):?><tr><td><?= e(date('M j, Y g:i a',strtotime((string)$row['created_at']))) ?></td><td><strong><?= e((string)$row['display_name']) ?></strong><br><small><?= e((string)$row['email']) ?></small></td><td><?= e((string)$row['pack_name_snapshot']) ?></td><td><?= number_format((int)$row['token_amount']) ?></td><td>$<?= number_format(((int)$row['price_cents'])/100,2) ?></td><td><?= e(ucfirst((string)$row['status'])) ?><?= (int)($row['credit_id']??0)>0?'<br><small>Credit #'.(int)$row['credit_id'].'</small>':'' ?></td></tr><?php endforeach;?><?php if(!$recent):?><tr><td colspan="6">No token purchases yet.</td></tr><?php endif;?></tbody></table></div></section>
<?php require __DIR__.'/_footer.php'; ?>