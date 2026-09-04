<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');
$user=current_user(); $pdo=db();
if (!$user || !$pdo || !artist_workspace_v181_schema_ready($pdo)) { http_response_code(503); exit('My Library is not ready. Run the database upgrade.'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf()) { flash('library_error','Your session expired. Please try again.'); redirect(url('/my-library.php')); }
    try { $saved=artist_workspace_v181_toggle_saved((string)($_POST['kind']??''),(int)($_POST['item_id']??0),$user); flash('library_notice',$saved?'Saved to My Library.':'Removed from My Library.'); }
    catch (Throwable $e) { flash('library_error',$e->getMessage()); }
    redirect(url('/my-library.php'));
}
$shows=artist_workspace_v181_saved_records('shows',$user); $photos=artist_workspace_v181_saved_records('photos',$user);
$notice=flash('library_notice'); $error=flash('library_error'); $pageTitle='My Library | Stonefellow'; $activePage='library'; require __DIR__.'/includes/header.php';
?>
<main class="section"><div class="wrap"><p class="eyebrow">Private collection</p><h1>My Library</h1><p class="subhead">Shows and photos you choose to save stay visible only to your account.</p><?php if($notice): ?><p class="notice success"><?= e($notice) ?></p><?php endif; ?><?php if($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>
<section class="section"><h2>Saved shows</h2><div class="show-list"><?php foreach($shows as $show): ?><article class="show-row"><div class="show-date"><?= e(date('M j',strtotime((string)$show['show_date']))) ?></div><div class="show-copy"><strong><?= e((string)$show['venue']) ?></strong><span><?= e(trim((string)$show['city'].' '.(string)$show['region'])) ?></span></div><form method="post"><input type="hidden" name="kind" value="shows"><input type="hidden" name="item_id" value="<?= (int)$show['id'] ?>"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Remove</button></form></article><?php endforeach; ?><?php if(!$shows): ?><p>No saved shows yet. Save one from an artist profile.</p><?php endif; ?></div></section>
<section class="section"><h2>Saved photos</h2><div class="card-grid"><?php foreach($photos as $photo): ?><article class="card"><img src="<?= e(url('/content-image.php?type=artist_photo&id='.(int)$photo['id'])) ?>" alt="<?= e((string)$photo['title']) ?>"><strong><?= e((string)$photo['title']) ?></strong><form method="post"><input type="hidden" name="kind" value="photos"><input type="hidden" name="item_id" value="<?= (int)$photo['id'] ?>"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Remove</button></form></article><?php endforeach; ?><?php if(!$photos): ?><p>No saved photos yet. Save one from an artist profile.</p><?php endif; ?></div></section></div></main>
<?php require __DIR__.'/includes/footer.php';
