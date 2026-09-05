<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('admin.access');

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/site-settings.php'));
    }

    $action = trim((string)($_POST['action'] ?? 'upload_logo'));
    $currentLogoPath = site_logo_path();

    if ($action === 'remove_logo') {
        try {
            save_setting('site_logo_path', '');
            delete_local_upload($currentLogoPath);
            flash('notice', 'System logo removed. Text branding will be used until a new logo is uploaded.');
        } catch (Throwable $e) {
            flash('error', 'Could not remove the system logo.');
        }

        redirect(url('/admin/site-settings.php'));
    }

    $newLogoPath = null;

    try {
        global $config;
        $newLogoPath = upload_file(
            $_FILES['site_logo'] ?? [],
            ['jpg', 'jpeg', 'png', 'webp'],
            ['image/jpeg', 'image/png', 'image/webp'],
            (int)($config['uploads']['max_image_bytes'] ?? 5242880),
            'branding'
        );

        if ($newLogoPath === null) {
            throw new RuntimeException('Choose a JPG, PNG or WEBP logo to upload.');
        }

        save_setting('site_logo_path', $newLogoPath);
        delete_local_upload($currentLogoPath);
        flash('notice', 'System logo updated.');
    } catch (Throwable $e) {
        if ($newLogoPath !== null && $newLogoPath !== $currentLogoPath) {
            delete_local_upload($newLogoPath);
        }
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/site-settings.php'));
}

$logoUrl = site_logo_url();
$adminTitle = 'Site Settings';
$adminActive = 'site-settings';
require __DIR__ . '/_header.php';
?>
<div class="panel admin-site-settings-panel">
  <div class="admin-site-settings-heading">
    <div>
      <span class="status">System</span>
      <h2>Site Branding</h2>
      <p class="muted">Manage the shared logo used by the Main Feed and Admin sidebars.</p>
    </div>
  </div>

  <div class="admin-site-settings-grid">
    <section class="admin-site-settings-preview" aria-labelledby="site-logo-preview-title">
      <div>
        <span class="admin-site-settings-kicker">Current logo</span>
        <h3 id="site-logo-preview-title"><?= $logoUrl !== '' ? 'Uploaded system logo' : 'Text branding' ?></h3>
      </div>

      <div class="admin-site-logo-stage">
        <?php if ($logoUrl !== ''): ?>
          <img class="admin-site-logo-preview-image" src="<?= e($logoUrl) ?>" alt="<?= e(site_brand_name()) ?>">
        <?php else: ?>
          <strong class="admin-site-logo-text-fallback"><?= e(site_brand_name()) ?></strong>
        <?php endif; ?>
      </div>

      <p class="muted admin-site-settings-note">
        <?= $logoUrl !== ''
          ? 'This logo is active in both navigation sidebars.'
          : 'Upload a logo to replace the text brand in both navigation sidebars.' ?>
      </p>
    </section>

    <section class="admin-site-settings-form" aria-labelledby="site-logo-upload-title">
      <div>
        <span class="admin-site-settings-kicker">Brand asset</span>
        <h3 id="site-logo-upload-title">System logo</h3>
        <p class="muted">Transparent PNG or WEBP works best. JPG is also supported. Maximum file size: 5 MB.</p>
      </div>

      <form method="post" action="" enctype="multipart/form-data" class="admin-site-logo-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_logo">
        <label class="admin-site-file-field">
          <span>Choose logo</span>
          <input type="file" name="site_logo" accept="image/jpeg,image/png,image/webp" required>
        </label>
        <button class="btn primary" type="submit">Upload Logo</button>
      </form>

      <?php if ($logoUrl !== ''): ?>
        <form method="post" action="" class="admin-site-remove-logo-form" onsubmit="return confirm('Remove the current system logo?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_logo">
          <button class="btn" type="submit">Remove Logo</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</div>

<div class="panel admin-site-settings-info">
  <h2>Branding behavior</h2>
  <p class="muted">The logo is stored once as a site setting and reused by the canonical Main Feed and Admin navigation. Removing it restores the existing text brand automatically.</p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
