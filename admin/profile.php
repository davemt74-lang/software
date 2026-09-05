<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('profile.manage');
$user=current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {flash('error','Session expired.');redirect(url('/admin/profile.php'));}
    $keys=['tagline','bio_subhead','genre','focus','contact_email','artist_bio','player_description','link_spotify','link_apple_music','link_tidal','link_youtube','link_instagram','link_facebook'];
    foreach($keys as $key)save_setting($key,trim((string)($_POST[$key]??'')));
    flash('notice','System artist defaults and links updated. Personal user profiles are managed from Profile Agent → Profile Settings.');
    redirect(url('/admin/profile.php'));
}

$defaults=default_links();$adminTitle='System Profile Defaults';$adminActive='profile';require __DIR__.'/_header.php';
?>
<div class="panel">
  <div class="content-library-heading"><div><span class="status">System defaults</span><h2>System Artist Profile Defaults</h2><p class="muted">These values remain as legacy/site-wide Stonefellow defaults. Each signed-in member now owns their own profile fields in <strong>Profile Agent → Profile Settings</strong>; those personal records do not live in this system settings table.</p></div><?php if($user&&personal_capability_has_v242('profile_agent.access',$user)):?><a class="btn primary" href="<?= e(url('/profile-agent.php?tab=profile')) ?>">Edit My Profile</a><?php endif;?></div>
  <form class="grid-form" method="post"><?= csrf_field() ?>
    <div class="field full"><label>Default Tagline</label><input name="tagline" value="<?= e(setting('tagline','Music. Stories. Connection.')) ?>"></div>
    <div class="field full"><label>Default Bio Page Subhead</label><input name="bio_subhead" value="<?= e(setting('bio_subhead','Rock, Americana and acoustic storytelling with a raw, close-to-the-room sound.')) ?>"></div>
    <div class="field"><label>Default Genre</label><input name="genre" value="<?= e(setting('genre','Rock / Americana / Acoustic')) ?>"></div>
    <div class="field"><label>Default Focus</label><input name="focus" value="<?= e(setting('focus','Original songs & studio sessions')) ?>"></div>
    <div class="field full"><label>Default Player Description</label><input name="player_description" value="<?= e(setting('player_description','A dark, intimate player for Stonefellow songs, acoustic sessions and new releases.')) ?>"></div>
    <div class="field full"><label>Default Artist Bio</label><textarea name="artist_bio" style="min-height:320px"><?= e(setting('artist_bio',default_bio())) ?></textarea><small>Used only as a system/legacy default. Existing Artist accounts are migrated into their personal profile once.</small></div>
    <div class="field full"><label>Default Contact Email</label><input name="contact_email" type="email" value="<?= e(setting('contact_email',(string)site_config('email','stonefellow74@gmail.com'))) ?>"></div>
    <div class="field full"><h2 style="margin:12px 0 0">Default External Links</h2></div>
    <div class="field full"><label>Spotify</label><input name="link_spotify" type="url" value="<?= e(setting('link_spotify',$defaults['spotify'])) ?>"></div>
    <div class="field full"><label>Apple Music</label><input name="link_apple_music" type="url" value="<?= e(setting('link_apple_music',$defaults['apple_music'])) ?>"></div>
    <div class="field full"><label>TIDAL</label><input name="link_tidal" type="url" value="<?= e(setting('link_tidal',$defaults['tidal'])) ?>"></div>
    <div class="field full"><label>YouTube</label><input name="link_youtube" type="url" value="<?= e(setting('link_youtube',$defaults['youtube'])) ?>"></div>
    <div class="field full"><label>Instagram</label><input name="link_instagram" type="url" value="<?= e(setting('link_instagram',$defaults['instagram'])) ?>"></div>
    <div class="field full"><label>Facebook</label><input name="link_facebook" type="url" value="<?= e(setting('link_facebook',$defaults['facebook'])) ?>"></div>
    <div class="field full"><button class="btn primary" type="submit">Save System Defaults</button></div>
  </form>
</div>
<?php require __DIR__.'/_footer.php'; ?>