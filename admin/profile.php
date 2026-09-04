<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('profile.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired.');
        redirect(url('/admin/profile.php'));
    }

    $keys = [
        'tagline','bio_subhead','genre','focus','contact_email','artist_bio','player_description',
        'link_spotify','link_apple_music','link_tidal','link_youtube','link_instagram','link_facebook'
    ];

    foreach ($keys as $key) {
        save_setting($key, trim((string)($_POST[$key] ?? '')));
    }

    flash('notice', 'Artist profile and links updated.');
    redirect(url('/admin/profile.php'));
}

$defaults = default_links();
$adminTitle = 'Artist / Links';
$adminActive = 'profile';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <h2>Artist Content</h2>
  <form class="grid-form" method="post">
    <?= csrf_field() ?>
    <div class="field full"><label>Tagline</label><input name="tagline" value="<?= e(setting('tagline','Music. Stories. Connection.')) ?>"></div>
    <div class="field full"><label>Bio Page Subhead</label><input name="bio_subhead" value="<?= e(setting('bio_subhead','Rock, Americana and acoustic storytelling with a raw, close-to-the-room sound.')) ?>"></div>
    <div class="field"><label>Genre</label><input name="genre" value="<?= e(setting('genre','Rock / Americana / Acoustic')) ?>"></div>
    <div class="field"><label>Focus</label><input name="focus" value="<?= e(setting('focus','Original songs & studio sessions')) ?>"></div>
    <div class="field full"><label>Player Description</label><input name="player_description" value="<?= e(setting('player_description','A dark, intimate player for Stonefellow songs, acoustic sessions and new releases.')) ?>"></div>
    <div class="field full"><label>Artist Bio</label><textarea name="artist_bio" style="min-height:320px"><?= e(setting('artist_bio',default_bio())) ?></textarea><small>Separate paragraphs with a blank line.</small></div>
    <div class="field full"><label>Contact Email</label><input name="contact_email" type="email" value="<?= e(setting('contact_email',(string)site_config('email','stonefellow74@gmail.com'))) ?>"></div>

    <div class="field full"><h2 style="margin:12px 0 0">External Links</h2></div>
    <div class="field full"><label>Spotify</label><input name="link_spotify" type="url" value="<?= e(setting('link_spotify',$defaults['spotify'])) ?>"></div>
    <div class="field full"><label>Apple Music</label><input name="link_apple_music" type="url" value="<?= e(setting('link_apple_music',$defaults['apple_music'])) ?>"></div>
    <div class="field full"><label>TIDAL</label><input name="link_tidal" type="url" value="<?= e(setting('link_tidal',$defaults['tidal'])) ?>"></div>
    <div class="field full"><label>YouTube</label><input name="link_youtube" type="url" value="<?= e(setting('link_youtube',$defaults['youtube'])) ?>"></div>
    <div class="field full"><label>Instagram</label><input name="link_instagram" type="url" value="<?= e(setting('link_instagram',$defaults['instagram'])) ?>"></div>
    <div class="field full"><label>Facebook</label><input name="link_facebook" type="url" value="<?= e(setting('link_facebook',$defaults['facebook'])) ?>"></div>
    <div class="field full"><button class="btn primary" type="submit">Save Changes</button></div>
  </form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
