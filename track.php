<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$trackId = (int)($_GET['id'] ?? 0);
$track = get_track_by_id($trackId);

if (!$track || !can_view_track($track)) {
    http_response_code(is_logged_in() ? 404 : 401);
    exit('Track not found.');
}

$knowledge = [];
$pdo = db();
$user = current_user();

if ($pdo && has_permission('knowledge.access', $user) && table_exists('knowledge_items') && column_exists('knowledge_items','track_id')) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id,title,description,file_name,file_type,file_path,visibility
             FROM knowledge_items
             WHERE track_id=? AND is_published=1
             ORDER BY updated_at DESC'
        );
        $stmt->execute([$trackId]);
        foreach ($stmt->fetchAll() as $row) {
            if (knowledge_visibility_allowed((string)$row['visibility'], $user)) {
                $knowledge[] = $row;
            }
        }
    } catch (Throwable $e) {}
}

$pageTitle = 'Stonefellow | ' . (string)$track['title'];
$pageDescription = 'Lyrics and song information for ' . (string)$track['title'] . '.';
$activePage = 'player';
require __DIR__ . '/includes/header.php';
?>
<main class="track-detail-page">
  <section class="track-detail-hero">
    <div class="wrap track-detail-hero-grid">
      <img class="track-detail-cover" src="<?= e(url('/media.php?track=' . $trackId . '&type=cover')) ?>" alt="">
      <div>
        <p class="section-kicker">Stonefellow Song</p>
        <h1><?= e($track['title']) ?></h1>
        <p class="track-detail-meta"><?= e($track['album']) ?><?= !empty($track['duration']) ? ' · ' . e($track['duration']) : '' ?></p>
        <a class="btn primary" href="<?= e(url('/chat.php?view=player')) ?>">Open Player</a>
      </div>
    </div>
  </section>

  <section class="track-detail-content">
    <div class="wrap track-detail-grid">
      <article class="track-detail-card">
        <p class="section-kicker">Lyrics</p>
        <h2>Lyrics</h2>
        <?php if (trim((string)($track['lyrics'] ?? '')) !== ''): ?>
          <div class="track-lyrics"><?= nl2br(e((string)$track['lyrics'])) ?></div>
        <?php else: ?>
          <p class="track-empty">Lyrics have not been added yet.</p>
        <?php endif; ?>
      </article>

      <?php if (has_permission('knowledge.access', $user)): ?>
        <aside class="track-detail-card">
          <p class="section-kicker">Knowledge</p>
          <h2>Song Knowledge</h2>
          <?php if (!$knowledge): ?>
            <p class="track-empty">No song-specific knowledge has been published for your account yet.</p>
          <?php else: ?>
            <div class="track-knowledge-list">
              <?php foreach ($knowledge as $item): ?>
                <div class="track-knowledge-item">
                  <span><?= e(strtoupper((string)$item['file_type'])) ?></span>
                  <strong><?= e($item['title']) ?></strong>
                  <p><?= e($item['description']) ?></p>
                  <?php if (!empty($item['file_path'])): ?>
                    <a href="<?= e(url('/knowledge-file.php?id=' . (int)$item['id'])) ?>" target="_blank">Open file ↗</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </aside>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
