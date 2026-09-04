<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
redirect_logged_in_public_page();

$shows = get_upcoming_shows();

$pageTitle = 'Stonefellow | Shows';
$pageDescription = 'Stonefellow live shows and upcoming performance dates.';
$activePage = 'shows';
require __DIR__ . '/includes/header.php';
?>
<main>
  <section class="page-hero">
    <img class="hero-image" src="<?= e(url('/images/stonefellow-studio.png')) ?>" alt="Stonefellow studio session">
    <div class="hero-overlay"></div>
    <div class="page-hero-content">
      <p class="eyebrow">Live</p>
      <h1>Shows</h1>
      <p class="subhead">Upcoming performances, listening-room appearances and Stonefellow live dates.</p>
    </div>
  </section>

  <section class="section">
    <div class="wrap">
      <p class="section-kicker">Upcoming</p>
      <h2>Live Dates</h2>

      <div class="show-list">
        <?php if (!$shows): ?>
          <article class="show-row">
            <div class="show-date">TBA</div>
            <div class="show-copy">
              <strong>Next Stonefellow Show</strong>
              <span>New live dates will be posted here as they are announced.</span>
            </div>
            <div class="show-status">Coming Soon</div>
          </article>
        <?php else: ?>
          <?php foreach ($shows as $show): ?>
            <?php
              $date = new DateTime((string)$show['show_date']);
              $location = trim(implode(', ', array_filter([$show['city'] ?? '', $show['region'] ?? ''])));
            ?>
            <article class="show-row">
              <div class="show-date"><?= e($date->format('M j')) ?></div>
              <div class="show-copy">
                <strong><?= e($show['venue']) ?></strong>
                <span><?= e($location) ?><?= !empty($show['notes']) ? ' — ' . e($show['notes']) : '' ?></span>
              </div>
              <div class="show-status">
                <?php if (!empty($show['ticket_url'])): ?>
                  <a href="<?= e($show['ticket_url']) ?>" target="_blank" rel="noopener noreferrer">Tickets ↗</a>
                <?php else: ?>
                  Announced
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div style="margin-top:24px">
        <a class="btn" href="<?= e(url('/contact.php')) ?>">Booking & Show Inquiries</a>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
