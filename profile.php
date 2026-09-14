<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/profile-public-media-v174.php';
require_once __DIR__ . '/includes/profile-commerce-v900.php';
require_once __DIR__ . '/includes/agent-scheduling-public-v450.php';

$pdo=db();
if(!$pdo||!profile_agent_schema_ready($pdo)){http_response_code(503);exit(system_agent_name().' profiles are not ready. Run /upgrade.php.');}
$username=profile_username_normalize((string)($_GET['username']??''));
$profile=profile_by_username($pdo,$username);$viewer=current_user();
$isOwner=$profile&&$viewer&&(int)$viewer['id']===(int)$profile['user_id'];
$preview=$isOwner&&!empty($_GET['preview']);
if(!$profile||empty($profile['is_active'])||(!$preview&&empty($profile['is_public']))){http_response_code(404);exit('Profile not found.');}
if(!$preview)profile_runtime_record_view($pdo,$profile,$viewer);
$catalogViewer=$preview?null:$viewer;
$catalog=profile_public_catalog($pdo,$profile,$catalogViewer);$workspace=$catalog['workspace'];
$agent=profile_active_agent($pdo,$profile);
$displayName=trim((string)$profile['display_name'])?:$username;
$bio=trim((string)($profile['bio']??''));if($bio===''&&$workspace)$bio=trim((string)($workspace['bio']??''));
$canSave=$catalogViewer&&has_permission('account.access',$catalogViewer);

$avatar=profile_public_media_url_v174((string)($profile['avatar_path']??''),'avatars');
if($avatar===''&&$workspace&&!empty($workspace['profile_image_path']))$avatar=url('/artist-profile-image.php?user_id='.(int)$profile['user_id'].'&type=profile');
$cover=profile_public_media_url_v174((string)($profile['cover_path']??''),'profile-covers');
if($cover===''&&$workspace&&!empty($workspace['cover_image_path']))$cover=url('/artist-profile-image.php?user_id='.(int)$profile['user_id'].'&type=cover');

$links=['Website'=>(string)($profile['website_url']??''),'Instagram'=>(string)($profile['instagram_url']??''),'TikTok'=>(string)($profile['tiktok_url']??''),'YouTube'=>(string)($profile['youtube_url']??''),'Spotify'=>(string)($profile['spotify_url']??''),'Apple Music'=>(string)($profile['apple_music_url']??'')];
if($workspace){foreach(['Website'=>'website_url','Instagram'=>'instagram_url','TikTok'=>'tiktok_url','YouTube'=>'youtube_url','Spotify'=>'spotify_url','Apple Music'=>'apple_music_url'] as $label=>$field)if($links[$label]==='')$links[$label]=(string)($workspace[$field]??'');}
$links=array_filter($links,static fn(string $v):bool=>(bool)filter_var($v,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($v,PHP_URL_SCHEME)),['http','https'],true));
$roleLabel=function_exists('role_label')?role_label((string)($profile['role']??'')):ucfirst((string)($profile['role']??''));
$agentGreeting=$agent?(trim((string)($profile['profile_agent_greeting']??''))?:'Hi — I’m '.(string)$agent['display_name'].', '.$displayName.'’s AI representative. What would you like to know?'):'';
$profileToken=$agent&&!$preview?profile_chat_token((int)$profile['user_id']):'';

$workspaceId=$workspace?(int)$workspace['id']:0;
$tracks=$catalog['tracks']??[];$albums=$catalog['albums']??[];$photos=$catalog['photos']??[];$posts=$catalog['posts']??[];$merch=$catalog['merch']??[];
$shows=array_values(array_filter($catalog['shows']??[],static function(array $show):bool{$when=strtotime((string)($show['show_date']??''));return $when!==false&&$when>=time();}));
$publishedAlbumIds=[];foreach($albums as $album)$publishedAlbumIds[(int)$album['id']]=true;
$tracksByAlbum=[];$singles=[];
foreach($tracks as $track){$albumId=(int)($track['album_id']??0);if($albumId>0&&isset($publishedAlbumIds[$albumId]))$tracksByAlbum[$albumId][]=$track;else$singles[]=$track;}
usort($albums,static function(array $a,array $b):int{$sort=(int)($a['sort_order']??0)<=>(int)($b['sort_order']??0);return $sort!==0?$sort:strcmp((string)($b['release_date']??''),(string)($a['release_date']??''));});
foreach($tracksByAlbum as &$group)usort($group,static fn(array $a,array $b):int=>((int)($a['track_number']??0)<=>(int)($b['track_number']??0))?:((int)$a['id']<=>(int)$b['id']));unset($group);

$commerceProducts=profile_commerce_products_for_profile_v900($pdo,$profile,true,40);
$publicSchedule=null;$bookingTypes=[];
if(table_exists('agent_scheduling_schedules')&&table_exists('agent_scheduling_event_types')){
    $publicSchedule=agent_scheduling_public_schedule_v450($pdo,(int)$profile['user_id']);
    if($publicSchedule)$bookingTypes=agent_scheduling_public_events_v450($pdo,(int)$publicSchedule['id']);
}

$commerceNotice=null;
$commerceReturn=trim((string)($_GET['commerce']??''));
if($commerceReturn==='return'){
    $commerceNotice=$_SESSION['profile_commerce_return_notices'][(string)(int)$profile['user_id']]??null;
    unset($_SESSION['profile_commerce_return_notices'][(string)(int)$profile['user_id']]);
    if(!is_array($commerceNotice)||(int)($commerceNotice['expires_at']??0)<time())$commerceNotice=null;
}elseif($commerceReturn==='cancelled'){
    $commerceNotice=['kind'=>'cancelled','headline'=>'Checkout cancelled.','message'=>'No purchase is reported as paid until the provider verifies it.'];
}

$profileTabs=[];
if($bio!==''||$links)$profileTabs['about']='About';
if($shows)$profileTabs['calendar']='Calendar';
if($bookingTypes)$profileTabs['booking']='Booking';
if($commerceProducts)$profileTabs['products']='Products';
if($tracks||$albums)$profileTabs['music']='Music';
if($photos)$profileTabs['photos']='Photos';
if($posts)$profileTabs['posts']='Posts';
if($merch)$profileTabs['merch']='Merch';
$activeTab=(string)(array_key_first($profileTabs)??'');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="<?= e(mb_strimwidth($bio!==''?$bio:$displayName.' on '.system_agent_name(),0,155,'…')) ?>">
<meta name="theme-color" content="#f6f5f2">
<link rel="canonical" href="<?= e(profile_public_url($username)) ?>">
<title><?= e($displayName) ?> | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/profile.css?v=profile-media-20260905')) ?>">
<link rel="stylesheet" href="<?= e(url('/profile-cleanup-v174.css?v=profile-cleanup-v174-20260913')) ?>">
</head>
<body class="profile-page">
<?php if($viewer): ?>
  <?php
    $memberHeaderUser=$viewer;
    $memberHeaderTitle='Profile';
    $memberHeaderSubtitle='@'.$username;
    $memberHeaderClass='';
    $memberHeaderShowSidebarToggle=false;
    $memberHeaderActions='';
    require __DIR__.'/includes/member-header.php';
  ?>
<?php else: ?>
  <header class="profile-topbar profile-guest-topbar">
    <a class="profile-brand" href="<?= e(url('/')) ?>"><span class="profile-brand-mark"><?= e(mb_strtoupper(mb_substr(system_agent_name(),0,1))) ?></span><span><?= e(system_agent_name()) ?></span></a>
    <nav class="profile-top-actions"><a href="<?= e(url('/login.php')) ?>">Sign in</a></nav>
  </header>
<?php endif; ?>
<?php if($preview): ?><div class="profile-preview-banner"><span>Visitor preview — public visibility rules are active and this view is not being counted.</span><a href="<?= e(url('/profile-agent.php')) ?>">Back to Profile Agent</a></div><?php endif; ?>
<section class="profile-cover profile-cover-full"><?php if($cover!==''): ?><img src="<?= e($cover) ?>" alt=""><?php endif; ?></section>
<main class="profile-shell">
  <section class="profile-identity">
    <div class="profile-avatar"><?php if($avatar!==''): ?><img src="<?= e($avatar) ?>" alt="<?= e($displayName) ?>"><?php else: ?><span><?= e(mb_strtoupper(mb_substr($displayName,0,1))) ?></span><?php endif; ?></div>
    <div class="profile-name"><small><?= e($roleLabel) ?></small><h1><?= e($displayName) ?></h1><span>@<?= e($username) ?></span></div>
  </section>

  <?php if($commerceNotice): $noticeKind=(string)($commerceNotice['kind']??'pending'); ?>
    <div class="profile-commerce-return-v900<?= in_array($noticeKind,['verified'],true)?'':' '.e($noticeKind==='cancelled'?'cancelled':'pending') ?>" role="status"><strong><?= e((string)($commerceNotice['headline']??'Payment return received.')) ?></strong><span><?= e((string)($commerceNotice['message']??'VP3 is verifying the provider result.')) ?></span></div>
  <?php endif; ?>

  <?php if(!$shows): ?><div class="profile-public-calendar-empty" role="status">This user has no public calendar events.</div><?php endif; ?>

  <?php if($profileTabs): ?>
    <div class="profile-tabs" role="tablist" aria-label="Profile sections">
      <?php foreach($profileTabs as $key=>$label): ?><button type="button" role="tab" aria-selected="<?= $key===$activeTab?'true':'false' ?>" data-profile-tab="<?= e($key) ?>"><?= e($label) ?></button><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="profile-grid"><div>
    <?php if(isset($profileTabs['about'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="about"<?= $activeTab==='about'?'':' hidden' ?>><h2>About</h2><?php if($bio!==''): ?><p><?= nl2br(e($bio)) ?></p><?php endif; ?><?php if($links): ?><div class="profile-links profile-about-links"><?php foreach($links as $label=>$href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($label) ?> ↗</a><?php endforeach; ?></div><?php endif; ?></section>
    <?php endif; ?>

    <?php if(isset($profileTabs['calendar'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="calendar"<?= $activeTab==='calendar'?'':' hidden' ?>>
        <h2>Calendar</h2>
        <?php foreach($shows as $show): $when=strtotime((string)$show['show_date']);$status=(string)($show['show_status']??'scheduled');$eventName=trim((string)($show['event_name']??''));$location=trim((string)($show['city']??'').(trim((string)($show['region']??''))!==''?', '.(string)$show['region']:'')); ?>
          <article class="profile-show"><div class="profile-show-date"><strong><?= e(date('M j, Y',$when)) ?></strong><span><?= e(date('g:i A',$when)) ?></span></div><div class="profile-show-copy"><span class="profile-show-status <?= e($status) ?>"><?= e(function_exists('artist_shows_v184_statuses')?(artist_shows_v184_statuses()[$status]??ucfirst($status)):ucfirst($status)) ?></span><strong><?= e($eventName!==''?$eventName:(string)($show['venue']??'Event')) ?></strong><?php if($eventName!==''&&trim((string)($show['venue']??''))!==''): ?><div class="profile-show-venue"><?= e((string)$show['venue']) ?></div><?php endif; ?><?php if($location!==''): ?><div class="profile-show-location"><?= e($location) ?></div><?php endif; ?><?php if(trim((string)($show['notes']??''))!==''): ?><div class="profile-show-notes"><?= nl2br(e((string)$show['notes'])) ?></div><?php endif; ?></div><div class="profile-show-actions"><?php if($status!=='cancelled'&&!empty($show['ticket_url'])): ?><a href="<?= e((string)$show['ticket_url']) ?>" target="_blank" rel="noopener noreferrer">Tickets ↗</a><?php endif; ?><?php if($canSave): ?><form method="post" action="<?= e(url('/my-library.php')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="shows"><input type="hidden" name="item_id" value="<?= (int)$show['id'] ?>"><button type="submit">Save</button></form><?php endif; ?></div></article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <?php if(isset($profileTabs['booking'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="booking"<?= $activeTab==='booking'?'':' hidden' ?>>
        <div class="profile-panel-heading"><h2>Booking</h2><?php if($isOwner): ?><a class="profile-tab-manage" href="<?= e(url('/scheduling.php')) ?>">Manage scheduling</a><?php endif; ?></div>
        <div class="profile-booking-list">
          <?php foreach($bookingTypes as $event): $duration=max(0,(int)($event['duration_minutes']??0)); ?>
            <article class="profile-booking-item"><div class="profile-booking-meta"><?php if($duration>0): ?><span><?= $duration ?> min</span><?php endif; ?></div><h3><?= e((string)($event['title']??'Appointment')) ?></h3><?php if(trim((string)($event['description']??''))!==''): ?><p><?= e(mb_strimwidth((string)$event['description'],0,260,'…')) ?></p><?php endif; ?><a href="<?= e(agent_scheduling_public_booking_url_v450($username,(string)($event['slug']??''))) ?>">Book a time →</a></article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if(isset($profileTabs['products'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="products"<?= $activeTab==='products'?'':' hidden' ?>>
        <div class="profile-panel-heading"><h2>Products & services</h2><?php if($isOwner): ?><a class="profile-tab-manage" href="<?= e(url('/profile-commerce-products.php')) ?>">Manage products</a><?php endif; ?></div>
        <div class="profile-products-list">
          <?php foreach($commerceProducts as $product): ?>
            <article class="profile-product-item<?= !empty($product['featured'])?' featured':'' ?>"><div class="profile-product-tags"><span><?= e(ucfirst((string)$product['product_type'])) ?></span><span><?= e(ucfirst((string)$product['fulfillment_type'])) ?></span></div><h3><?= e((string)$product['title']) ?></h3><?php if(trim((string)$product['description'])!==''): ?><p><?= e(mb_strimwidth((string)$product['description'],0,260,'…')) ?></p><?php endif; ?><strong class="profile-product-price"><?= e((string)$product['price_label']) ?></strong><a href="<?= e((string)$product['product_url']) ?>"><?= e((string)$product['cta']) ?> →</a></article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if(isset($profileTabs['music'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="music"<?= $activeTab==='music'?'':' hidden' ?>>
        <h2>Music</h2>
        <div class="profile-albums">
          <?php foreach($albums as $album): $albumId=(int)$album['id'];$albumTracks=$tracksByAlbum[$albumId]??[];$legacyCover=trim((string)($album['cover_path']??'')); ?>
            <article class="profile-album">
              <?php if((int)($album['cover_photo_id']??0)>0): ?><img class="profile-album-cover" src="<?= e(url('/artist-music-image.php?type=album&id='.$albumId)) ?>" alt="<?= e((string)$album['title']) ?>"><?php elseif($legacyCover!==''): ?><img class="profile-album-cover" src="<?= e(url('/content-image.php?type=artist_album&id='.$albumId)) ?>" alt="<?= e((string)$album['title']) ?>"><?php else: ?><div class="profile-album-cover profile-album-placeholder"></div><?php endif; ?>
              <div class="profile-album-copy"><h3><?= e((string)$album['title']) ?></h3><div class="profile-album-meta"><?php if(!empty($album['release_date'])): ?><?= e(date('M j, Y',strtotime((string)$album['release_date']))) ?><?php endif; ?><?php if($albumTracks): ?><?= !empty($album['release_date'])?' · ':'' ?><?= count($albumTracks) ?> track<?= count($albumTracks)===1?'':'s' ?><?php endif; ?></div><?php if(trim((string)($album['description']??''))!==''): ?><p><?= e((string)$album['description']) ?></p><?php endif; ?><?php if($albumTracks): ?><div class="profile-track-list"><?php foreach($albumTracks as $track): ?><div class="profile-track"><span class="profile-track-number"><?= (int)($track['track_number']??0)>0?(int)$track['track_number']:'•' ?></span><span class="profile-track-title"><strong><?= e((string)$track['title']) ?></strong><small><?php if(trim((string)($track['genre']??''))!==''): ?><?= e((string)$track['genre']) ?><?php endif; ?><?php if((int)($track['duration_seconds']??0)>0): ?><?= trim((string)($track['genre']??''))!==''?' · ':'' ?><?= e(sprintf('%d:%02d',intdiv((int)$track['duration_seconds'],60),(int)$track['duration_seconds']%60)) ?><?php endif; ?></small></span><?php if(str_starts_with((string)($track['audio_path']??''),'/uploads/artist-music/'.$workspaceId.'/')): ?><audio controls preload="none" src="<?= e(url('/artist-track-audio.php?track='.(int)$track['id'])) ?>"></audio><?php else: ?><a href="<?= e(url('/chat.php?view=player')) ?>">Listen ↗</a><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?></div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if($singles): ?><div class="profile-singles"><h3>Singles & Tracks</h3><div class="profile-track-list"><?php foreach($singles as $track): ?><div class="profile-track"><span class="profile-track-number">•</span><span class="profile-track-title"><strong><?= e((string)$track['title']) ?></strong><small><?= e((string)($track['genre']??'')) ?><?php if((int)($track['duration_seconds']??0)>0): ?><?= trim((string)($track['genre']??''))!==''?' · ':'' ?><?= e(sprintf('%d:%02d',intdiv((int)$track['duration_seconds'],60),(int)$track['duration_seconds']%60)) ?><?php endif; ?></small></span><?php if(str_starts_with((string)($track['audio_path']??''),'/uploads/artist-music/'.$workspaceId.'/')): ?><audio controls preload="none" src="<?= e(url('/artist-track-audio.php?track='.(int)$track['id'])) ?>"></audio><?php else: ?><a href="<?= e(url('/chat.php?view=player')) ?>">Listen ↗</a><?php endif; ?></div><?php endforeach; ?></div></div><?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if(isset($profileTabs['photos'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="photos"<?= $activeTab==='photos'?'':' hidden' ?>><h2>Photos</h2><div class="profile-media-grid"><?php foreach($photos as $photo): ?><article class="profile-media"><img src="<?= e(url('/content-image.php?type=artist_photo&id='.(int)$photo['id'])) ?>" alt="<?= e((string)($photo['alt_text']??$photo['title']??'')) ?>" loading="lazy"><div><strong><?= e((string)($photo['title']??'')) ?></strong><?php if(trim((string)($photo['caption']??''))!==''): ?><p><?= e((string)$photo['caption']) ?></p><?php endif; ?><?php if($canSave): ?><form method="post" action="<?= e(url('/my-library.php')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="photos"><input type="hidden" name="item_id" value="<?= (int)$photo['id'] ?>"><button class="profile-save-button" type="submit">Save</button></form><?php endif; ?></div></article><?php endforeach; ?></div></section>
    <?php endif; ?>

    <?php if(isset($profileTabs['posts'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="posts"<?= $activeTab==='posts'?'':' hidden' ?>><h2>Posts</h2><div class="profile-posts"><?php foreach($posts as $post): ?><article class="profile-post"><?php if(function_exists('artist_posts_v183_schema_ready')&&artist_posts_v183_schema_ready()&&(int)($post['image_photo_id']??0)>0): ?><img src="<?= e(url('/artist-post-image.php?post='.(int)$post['id'])) ?>" alt="" loading="lazy"><?php endif; ?><div class="profile-post-copy"><div class="profile-post-meta"><span><?= e(ucwords(str_replace('-',' ',(string)($post['post_type']??'update')))) ?></span><?php if(!empty($post['published_at'])): ?><span>·</span><time datetime="<?= e(date(DATE_ATOM,strtotime((string)$post['published_at']))) ?>"><?= e(date('M j, Y',strtotime((string)$post['published_at']))) ?></time><?php endif; ?></div><h3><?= e((string)$post['title']) ?></h3><p><?= nl2br(e((string)$post['body'])) ?></p><?php if(!empty($post['media_url'])): ?><a class="profile-post-link" href="<?= e((string)$post['media_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Open media ↗</a><?php endif; ?></div></article><?php endforeach; ?></div></section>
    <?php endif; ?>

    <?php if(isset($profileTabs['merch'])): ?>
      <section class="profile-card profile-panel" data-profile-panel="merch"<?= $activeTab==='merch'?'':' hidden' ?>><h2>Merch</h2><div class="profile-media-grid"><?php foreach($merch as $item): ?><article class="profile-media"><?php if(trim((string)($item['image_path']??''))!==''): ?><img src="<?= e(url('/content-image.php?type=artist_merch&id='.(int)$item['id'])) ?>" alt="<?= e((string)$item['title']) ?>" loading="lazy"><?php endif; ?><div><strong><?= e((string)$item['title']) ?></strong><?php if(isset($item['price_cents'])): ?><p>$<?= e(number_format(((int)$item['price_cents'])/100,2)) ?></p><?php endif; ?><?php if(trim((string)($item['description']??''))!==''): ?><p><?= e((string)$item['description']) ?></p><?php endif; ?><?php if(!empty($item['product_url'])): ?><a href="<?= e((string)$item['product_url']) ?>" target="_blank" rel="noopener noreferrer">View item ↗</a><?php endif; ?></div></article><?php endforeach; ?></div></section>
    <?php endif; ?>

    <?php if(!$profileTabs): ?><div class="profile-tabs-empty">This user has no additional public profile sections.</div><?php endif; ?>
  </div></div>
</main>
<?php if($agent): ?>
<div class="profile-agent-widget-root" data-profile-agent-widget>
  <section class="profile-agent-widget" id="profileAgentShell" hidden aria-label="Chat with <?= e((string)$agent['display_name']) ?>">
    <header class="profile-agent-widget-head"><span class="profile-agent-avatar"><?= e(mb_strtoupper(mb_substr((string)$agent['display_name'],0,1))) ?></span><div><strong><?= e((string)$agent['display_name']) ?></strong><small><?= e($displayName) ?>’s Profile Agent · powered by <?= e(system_agent_name()) ?></small></div><button type="button" class="profile-agent-widget-close" data-close-profile-agent aria-label="Close Profile Agent">×</button></header>
    <div class="profile-agent-disclosure">AI representative — not the profile owner live. Answers use only information this profile has approved.</div>
    <?php if($preview): ?><div class="profile-agent-preview"><p><?= e($agentGreeting) ?></p><strong>Visitor preview</strong><span>Conversation sending is disabled here so your own preview cannot create visitor events, notifications, or attention items.</span></div><?php else: ?><div class="profile-agent-thread" data-profile-agent-thread aria-live="polite"></div><form class="profile-agent-compose"><textarea maxlength="2000" placeholder="Ask about this profile…" aria-label="Message Profile Agent"></textarea><button type="submit">Send</button><div class="profile-agent-status" data-profile-agent-status role="status" aria-live="polite"></div></form><?php endif; ?>
  </section>
  <button type="button" class="profile-agent-launcher" data-open-profile-agent aria-controls="profileAgentShell" aria-expanded="false"><span class="profile-agent-launcher-mark"><?= e(mb_strtoupper(mb_substr((string)$agent['display_name'],0,1))) ?></span><span>Ask <?= e((string)$agent['display_name']) ?></span></button>
</div>
<?php endif; ?>
<script>
window.STONEFELLOW_PROFILE_AGENT={username:<?= json_encode($username,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,endpoint:<?= json_encode(url('/api/profile-agent.php'),JSON_UNESCAPED_SLASHES) ?>,profileToken:<?= json_encode($profileToken,JSON_UNESCAPED_SLASHES) ?>,agentName:<?= json_encode((string)($agent['display_name']??''),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,greeting:<?= json_encode($agentGreeting,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>};
for(const tab of document.querySelectorAll('[data-profile-tab]'))tab.addEventListener('click',()=>{const key=tab.dataset.profileTab;document.querySelectorAll('[data-profile-tab]').forEach(t=>t.setAttribute('aria-selected',String(t===tab)));document.querySelectorAll('[data-profile-panel]').forEach(p=>p.hidden=p.dataset.profilePanel!==key);});
const profileAgentShell=document.getElementById('profileAgentShell');
const profileAgentLauncher=document.querySelector('[data-open-profile-agent]');
const profileAgentClose=document.querySelector('[data-close-profile-agent]');
function setProfileAgentOpen(open){if(!profileAgentShell||!profileAgentLauncher)return;profileAgentShell.hidden=!open;profileAgentLauncher.setAttribute('aria-expanded',String(open));if(open){(profileAgentShell.querySelector('textarea')||profileAgentClose)?.focus();}else{profileAgentLauncher.focus();}}
profileAgentLauncher?.addEventListener('click',()=>setProfileAgentOpen(profileAgentShell?.hidden!==false));
profileAgentClose?.addEventListener('click',()=>setProfileAgentOpen(false));
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&profileAgentShell&&!profileAgentShell.hidden)setProfileAgentOpen(false);});
</script>
<?php if($viewer): ?><script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script><?php endif; ?>
<?php if($agent&&!$preview): ?><script src="<?= e(url('/profile-agent.js?v=profile-activity-20260905')) ?>"></script><?php endif; ?>
</body>
</html>
