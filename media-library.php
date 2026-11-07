<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if (!$user) redirect(url('/login.php'));
if (!has_permission('chat.access', $user)) {
    http_response_code(403);
    exit('Media Library access is not available for this account.');
}
$mediaReady = media_studio_schema_ready();
header('Cache-Control: private, no-store');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Media Library | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/media-library-v1.css?v=1')) ?>">
<?php if ($mediaReady): ?>
<link rel="stylesheet" href="<?= e(url('/media-library-editor-v1.css?v=1')) ?>">
<script defer src="<?= e(url('/media-library-editor-v1.js?v=1')) ?>"></script>
<script defer src="<?= e(url('/media-library-image-v1.js?v=1')) ?>"></script>
<script defer src="<?= e(url('/media-library-v1.js?v=1')) ?>"></script>
<?php endif; ?>
</head>
<body>
<div class="chat-app">
<?php $mainSidebarUser=$user; $mainSidebarActive='media_library'; require __DIR__.'/includes/main-sidebar.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main vp3-media-main">
<?php
$memberHeaderUser = $user;
$memberHeaderTitle = 'Media Library';
$memberHeaderSubtitle = 'Your private Cloud media';
require __DIR__ . '/includes/member-header.php';
?>
<div class="vp3-media-content">
<?php if (!$mediaReady): ?>
  <section class="vp3-media-panel">
    <h1>Media Library</h1>
    <p>Media storage is unavailable until an administrator runs Database Upgrade.</p>
  </section>
<?php else: ?>
  <div class="vp3-media-workspace" data-media-workspace
       data-media-endpoint="<?= e(url('/api/media-library-v86.php')) ?>"
       data-image-endpoint="<?= e(url('/api/media-library-image-v1.php')) ?>"
       data-owner-key="<?= e((string)$user['id']) ?>"
       data-csrf="<?= e(csrf_token()) ?>">
    <header class="vp3-media-intro">
      <h1>Media Library</h1>
      <p>Find your saved media, create an image from a prompt, or edit a private copy.</p>
      <div class="vp3-media-actions" role="group" aria-label="Media workspace">
        <button type="button" data-media-view="library" aria-pressed="true" aria-controls="mediaLibraryView">Library</button>
        <button type="button" data-media-view="create" aria-pressed="false" aria-controls="mediaCreateView">Generate image</button>
      </div>
    </header>
    <section id="mediaLibraryView" data-media-panel="library" aria-label="Saved media">
      <section class="vp3-media-panel vp3-media-editor" data-media-editor hidden></section>
      <section class="vp3-media-panel">
        <h2>Saved media</h2>
        <div class="vp3-media-toolbar">
          <form data-media-filters>
            <label>Search by name <input type="search" name="q" maxlength="190" placeholder="Search your media"></label>
            <label>Type <select name="type"><option value="">All types</option><option value="photo">Images</option><option value="video">Videos</option><option value="audio">Audio</option></select></label>
            <label>Source <select name="source"><option value="">All sources</option><option value="uploaded">Uploads and captures</option><option value="generated">Generated</option><option value="edited">Edited copies</option></select></label>
            <button type="submit">Search</button>
          </form>
          <label class="vp3-media-upload">Upload image <input type="file" accept="image/jpeg,image/png,image/webp" data-media-upload></label>
        </div>
        <p class="vp3-media-status" data-media-upload-status role="status"></p>
        <p class="vp3-media-status" data-media-status role="status"></p>
        <div class="vp3-media-grid" data-media-assets></div>
        <button class="vp3-media-more" type="button" data-media-more hidden>Load more</button>
      </section>
    </section>
    <section id="mediaCreateView" class="vp3-media-panel vp3-media-generation" data-media-panel="create" data-media-generation aria-label="Generate an image" hidden></section>
    <noscript><p>Enable JavaScript to browse, create, and edit media.</p></noscript>
  </div>
<?php endif; ?>
</div>
</main>
</div>
</body>
</html>
