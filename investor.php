<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('investor.access');

$pageTitle = 'Stonefellow | Investor';
$pageDescription = 'Private Stonefellow investor area.';
$activePage = 'account';
require __DIR__ . '/includes/header.php';
?>
<main>
  <section class="account-hero">
    <div class="wrap">
      <p class="section-kicker">Private Access</p>
      <h1>Investor Area</h1>
      <div class="account-meta"><span class="account-pill">Investor Access</span></div>
    </div>
  </section>
  <section class="section">
    <div class="wrap" style="max-width:850px">
      <div class="card">
        <p class="section-kicker">Stonefellow</p>
        <h2>Private Investor Content</h2>
        <p style="color:#aaa095;line-height:1.8">This area is permission-protected and is ready for private investor updates, presentations, financial materials, production notes or other restricted media.</p>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
