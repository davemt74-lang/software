<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

function vp3_pricing_money(int $cents): string
{
    if($cents<=0)return 'Free';
    $value=$cents/100;
    return '$'.number_format($value,($cents%100)===0?0:2);
}

function vp3_pricing_public_entitlements(array $package): array
{
    $catalog=subscription_capability_catalog();
    $labels=[];
    foreach(($package['entitlements']??[]) as $row){
        if((int)($row['is_enabled']??0)!==1)continue;
        $key=(string)($row['capability_key']??'');
        if($key===''||str_starts_with($key,'permission.')||$key==='legacy.permissions')continue;
        $meta=$catalog[$key]??null;if(!$meta||($meta['category']??'')==='Internal')continue;
        $label=(string)($meta['label']??$key);
        if(($meta['type']??'boolean')==='limit'&&$row['limit_value']!==null){
            $label.=': '.number_format(max(0,(int)$row['limit_value']));
        }
        $labels[]=$label;
    }
    return $labels;
}

$packages=[];
try{
    $packages=subscription_packages(true);
    foreach($packages as &$package){
        $full=subscription_package((int)$package['id']);
        $package['entitlements']=$full['entitlements']??[];
        $package['public_features']=vp3_pricing_public_entitlements($package);
    }
    unset($package);
}catch(Throwable $e){
    error_log('VP3 public pricing read failed: '.$e->getMessage());
    $packages=[];
}
$hasAnnual=false;foreach($packages as $package)if((int)($package['annual_price_cents']??0)>0){$hasAnnual=true;break;}
$capabilityCatalog=subscription_capability_catalog();
$matrixKeys=[];
foreach($capabilityCatalog as $key=>$meta){
    if(($meta['category']??'')==='Internal'||$key==='legacy.permissions')continue;
    foreach($packages as $package){
        foreach(($package['entitlements']??[]) as $row){
            if((string)($row['capability_key']??'')===$key&&(int)($row['is_enabled']??0)===1){$matrixKeys[$key]=$meta;break 2;}
        }
    }
}

vp3_public_header('Pricing — VP3', 'VP3 packages and feature access configured by the service administrator.', ['active'=>'pricing']);
?>
<section class="vp3-public-hero">
  <div class="vp3-kicker">One account. Packages that fit the work.</div>
  <h1>Choose the VP3 access that fits how you work.</h1>
  <p>Packages control commercial features, AI capacity and collaboration limits. Your account identity and security permissions remain separate.</p>
  <?php if($hasAnnual): ?><div class="vp3-billing"><button class="active" type="button" data-billing="monthly">Monthly</button><button type="button" data-billing="annual">Annual</button></div><?php endif; ?>
</section>
<main>
<section class="vp3-section"><div class="vp3-wrap">
<?php if($packages): ?>
  <div class="vp3-price-grid">
  <?php foreach($packages as $package):
      $isTrial=(int)($package['is_trial']??0)===1;
      $monthly=max(0,(int)($package['monthly_price_cents']??0));
      $annual=max(0,(int)($package['annual_price_cents']??0));
      $trialDays=max(0,(int)($package['trial_days']??0));
      $trialTokens=max(0,(int)($package['trial_tokens']??0));
      $monthlyTokens=max(0,(int)($package['ai_tokens_monthly']??0));
      $default=(int)($package['is_default']??0)===1;
      $features=$package['public_features']??[];
  ?>
    <article class="vp3-plan<?= $default?' featured':'' ?>">
      <?php if($default): ?><span class="vp3-plan-badge">Default signup package</span><?php elseif($isTrial): ?><span class="vp3-plan-badge">Trial</span><?php endif; ?>
      <h2><?= e((string)$package['name']) ?></h2>
      <div class="vp3-plan-desc"><?= e(trim((string)($package['description']??''))?:'VP3 access package.') ?></div>
      <?php if($isTrial): ?>
        <div class="vp3-price"><strong>Free trial</strong></div>
        <div class="vp3-price-note"><?= $trialDays>0?number_format($trialDays).' days':'Trial access' ?><?= $trialTokens>0?' · '.number_format($trialTokens).' AI tokens':'' ?></div>
      <?php else: ?>
        <div class="vp3-price"><strong data-monthly-cents="<?= $monthly ?>" data-annual-cents="<?= $annual>0?$annual:'' ?>"><?= e(vp3_pricing_money($monthly)) ?></strong><span data-price-suffix><?= $monthly>0?'/mo':'' ?></span></div>
        <div class="vp3-price-note"><?= $monthlyTokens>0?number_format($monthlyTokens).' AI tokens / month':'AI allowance configured by package' ?></div>
      <?php endif; ?>
      <a class="vp3-btn<?= $default?' primary':'' ?>" href="<?= e(url(($isTrial||$monthly===0)?'/signup.php':'/book-demo.php')) ?>"><?= $isTrial||$monthly===0?'Create account':'Book a demo' ?></a>
      <?php if($features): ?><ul><?php foreach(array_slice($features,0,10) as $feature): ?><li><?= e((string)$feature) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="vp3-cta-box"><div><h2>Packages are being configured.</h2><p>Create your VP3 account to begin onboarding, or book a demo to discuss the right setup.</p></div><div><a class="vp3-btn primary" href="<?= e(url('/signup.php')) ?>">Create account →</a> <a class="vp3-btn" href="<?= e(url('/book-demo.php')) ?>">Book a demo</a></div></div>
<?php endif; ?>
</div></section>

<?php if(count($packages)>1&&$matrixKeys): ?>
<section class="vp3-section soft"><div class="vp3-wrap"><div class="vp3-section-head"><div class="vp3-kicker">Compare packages</div><h2>Features and limits from the live package catalog.</h2></div><div class="vp3-matrix"><table><thead><tr><th>Capability</th><?php foreach($packages as $package): ?><th><?= e((string)$package['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
<tr><td>AI token allowance</td><?php foreach($packages as $package): ?><td><?= (int)$package['is_trial']===1?number_format((int)$package['trial_tokens']).' trial':number_format((int)$package['ai_tokens_monthly']).' / month' ?></td><?php endforeach; ?></tr>
<?php foreach($matrixKeys as $key=>$meta): ?><tr><td><?= e((string)$meta['label']) ?></td><?php foreach($packages as $package):
    $state=null;foreach(($package['entitlements']??[]) as $row)if((string)$row['capability_key']===$key){$state=$row;break;}
    $enabled=$state&&(int)$state['is_enabled']===1;
?><td><?php if(!$enabled): ?>—<?php elseif(($meta['type']??'boolean')==='limit'&&$state['limit_value']!==null): ?><?= number_format((int)$state['limit_value']) ?><?php else: ?>✓<?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div></div></section>
<?php endif; ?>

<section class="vp3-section"><div class="vp3-wrap"><div class="vp3-section-head"><div class="vp3-kicker">Questions</div><h2>Pricing FAQ</h2></div><div class="vp3-faq-grid"><details open><summary>Do packages change my account permissions?</summary><p>No. Packages provide commercial entitlements and capacity. They cannot create security permissions or Artist/Team authority that your account does not already have.</p></details><details><summary>Can my package change later?</summary><p>Yes. An administrator can assign or change packages, trial periods, complimentary access and AI token capacity without changing your account identity.</p></details><details><summary>How are AI tokens counted?</summary><p>VP3 records actual provider-reported input and output tokens against the active package period. Added token credits extend the included allowance.</p></details><details><summary>How does team access work?</summary><p>Packages can set the number of Team seats, while Manager and Producer authority remains scoped to the specific Artist relationship and assigned work.</p></details></div></div></section>
<section class="vp3-section soft"><div class="vp3-wrap"><div class="vp3-cta-box"><div><h2>Not sure which package fits?</h2><p>Tell us how you work and we’ll map the right VP3 configuration.</p></div><a class="vp3-btn primary" href="<?= e(url('/book-demo.php')) ?>">Find my setup →</a></div></div></section>
</main>
<?php if($hasAnnual): ?><script>(()=>{const buttons=document.querySelectorAll('[data-billing]'),prices=document.querySelectorAll('[data-monthly-cents]');const money=cents=>{const n=Number(cents);if(!Number.isFinite(n))return 'Contact';if(n<=0)return 'Free';const value=n/100;return '$'+value.toLocaleString(undefined,{minimumFractionDigits:n%100?2:0,maximumFractionDigits:2});};buttons.forEach(btn=>btn.addEventListener('click',()=>{buttons.forEach(b=>b.classList.remove('active'));btn.classList.add('active');const annual=btn.dataset.billing==='annual';prices.forEach(p=>{const raw=annual?p.dataset.annualCents:p.dataset.monthlyCents;p.textContent=raw===''?'Contact':money(raw);const suffix=p.parentElement.querySelector('[data-price-suffix]');if(suffix)suffix.textContent=raw!==''&&Number(raw)>0?(annual?'/yr':'/mo'):'';});}));})();</script><?php endif; ?>
<?php vp3_public_footer(); ?>