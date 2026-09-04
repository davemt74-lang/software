<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
redirect_logged_in_public_page();

$pageTitle = 'Stonefellow | Artist Bio';
$pageDescription = 'Read the official Stonefellow artist bio.';
$activePage = 'about';
require __DIR__ . '/includes/header.php';
?>
<main>
  <section class="page-hero">
    <img class="hero-image" src="<?= e(url('/images/stonefellow-studio.png')) ?>" alt="Stonefellow recording in the studio">
    <div class="hero-overlay"></div>
    <div class="page-hero-content">
      <p class="eyebrow">Stonefellow</p>
      <h1>Artist Bio</h1>
      <p class="subhead"><?= e(setting('bio_subhead', 'Rock, Americana and acoustic storytelling with a raw, close-to-the-room sound.')) ?></p>
    </div>
  </section>

  <section class="section">
    <div class="wrap two-col">
      <article class="prose">
        <?php foreach (artist_bio_paragraphs() as $paragraph): ?>
          <p><?= e($paragraph) ?></p>
        <?php endforeach; ?>
      </article>

      <aside class="card facts">
        <div class="fact"><span>Artist</span><span>Stonefellow</span></div>
        <div class="fact"><span>Sound</span><span><?= e(setting('genre', 'Rock / Americana / Acoustic')) ?></span></div>
        <div class="fact"><span>Focus</span><span><?= e(setting('focus', 'Original songs & studio sessions')) ?></span></div>
        <div class="fact"><span>Contact</span><span><?= e(setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'))) ?></span></div>
      </aside>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
