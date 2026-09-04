<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pdo=db();
if(!$pdo||!profile_agent_schema_ready($pdo)){http_response_code(503);exit('Stonefellow profiles are not ready. Run /upgrade.php.');}
$username=profile_username_normalize((string)($_GET['username']??''));
$profile=profile_by_username($pdo,$username);$viewer=current_user();
$isOwner=$profile&&$viewer&&(int)$viewer['id']===(int)$profile['user_id'];
$preview=$isOwner&&!empty($_GET['preview']);
if(!$profile||empty($profile['is_active'])||(!$preview&&empty($profile['is_public']))){http_response_code(404);exit('Profile not found.');}
if(!$preview)profile_record_view($pdo,$profile,$viewer);
$catalogViewer=$preview?null:$viewer;
$catalog=profile_public_catalog($pdo,$profile,$catalogViewer);$workspace=$catalog['workspace'];
$agent=profile_active_agent($pdo,$profile);
$displayName=trim((string)$profile['display_name'])?:$username;
$bio=trim((string)($profile['bio']??''));if($bio===''&&$workspace)$bio=trim((string)($workspace['bio']??''));
$canSave=$catalogViewer&&has_permission('account.access',$catalogViewer);

$avatar='';$cover='';
if($workspace&&!empty($workspace['profile_image_path']))$avatar=url('/artist-profile-image.php?user_id='.(int)$profile['user_id'].'&type=profile');
elseif(!empty($profile['avatar_path'])&&str_starts_with((string)$profile['avatar_path'],'/uploads/'))$avatar=url((string)$profile['avatar_path']);
if(!empty($profile['cover_path'])&&str_starts_with((string)$profile['cover_path'],'/uploads/'))$cover=url((string)$profile['cover_path']);
elseif($workspace&&!empty($workspace['cover_image_path']))$cover=url('/artist-profile-image.php?user_id='.(int)$profile['user_id'].'&type=cover');
$links=['Website'=>(string)($profile['website_url']??''),'Instagram'=>(string)($profile['instagram_url']??''),'TikTok'=>(string)($profile['tiktok_url']??''),'YouTube'=>(string)($profile['youtube_url']??''),'Spotify'=>(string)($profile['spotify_url']??''),'Apple Music'=>(string)($profile['apple_music_url']??'')];
if($workspace){foreach(['Website'=>'website_url','Instagram'=>'instagram_url','TikTok'=>'tiktok_url','YouTube'=>'youtube_url','Spotify'=>'spotify_url','Apple Music'=>'apple_music_url'] as $label=>$field)if($links[$label]==='')$links[$label]=(string)($workspace[$field]??'');}
$links=array_filter($links,static fn(string $v):bool=>(bool)filter_var($v,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($v,PHP_URL_SCHEME)),['http','https'],true));
$roleLabel=function_exists('role_label')?role_label((string)$profile['role']):ucfirst((string)$profile['role']);
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="<?= e(mb_strimwidth($bio!==''?$bio:$displayName.' on Stonefellow',0,155,'…')) ?>">
<meta name="theme-color" content="#f6f5f2">
<link rel="canonical" href="<?= e(profile_public_url($username)) ?>">
<title><?= e($displayName) ?> | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/profile.css?v=profile-agent-attention-20260903')) ?>">
</head>
<body class="profile-page">
<header class="profile-topbar">
  <a class="profile-brand" href="<?= e(url('/')) ?>"><span class="profile-brand-mark">S</span><span><?= e(system_agent_name()) ?></span></a>
  <nav class="profile-top-actions">
    <?php if($viewer): ?><a href="<?= e(url('/chat.php')) ?>">Agent Chat</a><a href="<?= e(url('/account.php#profile-agent')) ?>">My Account</a><?php else: ?><a href="<?= e(url('/login.php')) ?>">Sign in</a><?php endif; ?>
  </nav>
</header>
<?php if($preview): ?><div class="profile-preview-banner"><span>Visitor preview — public visibility rules are active and this view is not being counted.</span><a href="<?= e(url('/account.php#profile-agent')) ?>">Back to Profile Agent settings</a></div><?php endif; ?>
<main class="profile-shell">
  <section class="profile-cover"><?php if($cover!==''): ?><img src="<?= e($cover) ?>" alt=""><?php endif; ?></section>
  <section class="profile-identity">
    <div class="profile-avatar"><?php if($avatar!==''): ?><img src="<?= e($avatar) ?>" alt="<?= e($displayName) ?>"><?php else: ?><span><?= e(mb_strtoupper(mb_substr($displayName,0,1))) ?></span><?php endif; ?></div>
    <div class="profile-name"><small><?= e($roleLabel) ?></small><h1><?= e($displayName) ?></h1><span>@<?= e($username) ?></span></div>
    <?php if($agent): ?><button type="button" class="profile-primary-action" data-open-profile-agent>Ask <?= e((string)$agent['display_name']) ?></button><?php endif; ?>
  </section>

  <div class="profile-grid">
    <div>
      <section class="profile-card profile-about">
        <div><h2>About</h2><?php if($bio!==''): ?><p><?= nl2br(e($bio)) ?></p><?php else: ?><p>This profile has not added a bio yet.</p><?php endif; ?></div>
        <?php if($links): ?><div class="profile-links"><?php foreach($links as $label=>$href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($label) ?> ↗</a><?php endforeach; ?></div><?php endif; ?>
      </section>

      <?php if($workspace): ?>
      <div class="profile-tabs" role="tablist" aria-label="Profile sections">
        <?php foreach(['music'=>'Music','shows'=>'Shows','photos'=>'Photos','posts'=>'Posts','merch'=>'Merch','about'=>'About'] as $key=>$label): ?><button type="button" role="tab" aria-selected="<?= $key==='music'?'true':'false' ?>" data-profile-tab="<?= e($key) ?>"><?= e($label) ?></button><?php endforeach; ?>
      </div>

      <section class="profile-card profile-panel" data-profile-panel="music">
        <h2>Music</h2>
        <?php if($tracks||$albums): ?>
          <div class="profile-albums">
            <?php foreach($albums as $album): $albumId=(int)$album['id'];$albumTracks=$tracksByAlbum[$albumId]??[];$legacyCover=trim((string)($album['cover_path']??'')); ?>
              <article class="profile-album">
                <?php if((int)($album['cover_photo_id']??0)>0): ?><img class="profile-album-cover" src="<?= e(url('/artist-music-image.php?type=album&id='.$albumId)) ?>" alt="<?= e((string)$album['title']) ?>">
                <?php elseif($legacyCover!==''): ?><img class="profile-album-cover" src="<?= e(url('/content-image.php?type=artist_album&id='.$albumId)) ?>" alt="<?= e((string)$album['title']) ?>">
                <?php else: ?><div class="profile-album-cover profile-album-placeholder"></div><?php endif; ?>
                <div class="profile-album-copy"><h3><?= e((string)$album['title']) ?></h3><div class="profile-album-meta"><?php if(!empty($album['release_date'])): ?><?= e(date('M j, Y',strtotime((string)$album['release_date']))) ?><?php endif; ?><?php if($albumTracks): ?><?= !empty($album['release_date'])?' · ':'' ?><?= count($albumTracks) ?> track<?= count($albumTracks)===1?'':'s' ?><?php endif; ?></div><?php if(trim((string)($album['description']??''))!==''): ?><p><?= e((string)$album['description']) ?></p><?php endif; ?>
                  <?php if($albumTracks): ?><div class="profile-track-list"><?php foreach($albumTracks as $track): ?><div class="profile-track"><span class="profile-track-number"><?= (int)($track['track_number']??0)>0?(int)$track['track_number']:'•' ?></span><span class="profile-track-title"><strong><?= e((string)$track['title']) ?></strong><small><?php if(trim((string)($track['genre']??''))!==''): ?><?= e((string)$track['genre']) ?><?php endif; ?><?php if((int)($track['duration_seconds']??0)>0): ?><?= trim((string)($track['genre']??''))!==''?' · ':'' ?><?= e(sprintf('%d:%02d',intdiv((int)$track['duration_seconds'],60),(int)$track['duration_seconds']%60)) ?><?php endif; ?></small></span><?php if(str_starts_with((string)($track['audio_path']??''),'/uploads/artist-music/'.$workspaceId.'/')): ?><audio controls preload="none" src="<?= e(url('/artist-track-audio.php?track='.(int)$track['id'])) ?>"></audio><?php else: ?><a href="<?= e(url('/chat.php?view=player')) ?>">Listen ↗</a><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if($singles): ?><div class="profile-singles"><h3>Singles & Tracks</h3><div class="profile-track-list"><?php foreach($singles as $track): ?><div class="profile-track"><span class="profile-track-number">•</span><span class="profile-track-title"><strong><?= e((string)$track['title']) ?></strong><small><?= e((string)($track['genre']??'')) ?><?php if((int)($track['duration_seconds']??0)>0): ?><?= trim((string)($track['genre']??''))!==''?' · ':'' ?><?= e(sprintf('%d:%02d',intdiv((int)$track['duration_seconds'],60),(int)$track['duration_seconds']%60)) ?><?php endif; ?></small></span><?php if(str_starts_with((string)($track['audio_path']??''),'/uploads/artist-music/'.$workspaceId.'/')): ?><audio controls preload="none" src="<?= e(url('/artist-track-audio.php?track='.(int)$track['id'])) ?>"></audio><?php else: ?><a href="<?= e(url('/chat.php?view=player')) ?>">Listen ↗</a><?php endif; ?></div><?php endforeach; ?></div></div><?php endif; ?>
        <?php else: ?><div class="profile-empty">No published music yet.</div><?php endif; ?>
      </section>

      <section class="profile-card profile-panel" data-profile-panel="shows" hidden>
        <h2>Shows</h2>
        <?php foreach($shows as $show): $when=strtotime((string)$show['show_date']);$status=(string)($show['show_status']??'scheduled');$eventName=trim((string)($show['event_name']??''));$location=trim((string)($show['city']??'').(trim((string)($show['region']??''))!==''?', '.(string)$show['region']:'')); ?>
          <article class="profile-show"><div class="profile-show-date"><strong><?= e(date('M j, Y',$when)) ?></strong><span><?= e(date('g:i A',$when)) ?></span></div><div class="profile-show-copy"><span class="profile-show-status <?= e($status) ?>"><?= e(function_exists('artist_shows_v184_statuses')?(artist_shows_v184_statuses()[$status]??ucfirst($status)):ucfirst($status)) ?></span><strong><?= e($eventName!==''?$eventName:(string)($show['venue']??'Show')) ?></strong><?php if($eventName!==''&&trim((string)($show['venue']??''))!==''): ?><div class="profile-show-venue"><?= e((string)$show['venue']) ?></div><?php endif; ?><?php if($location!==''): ?><div class="profile-show-location"><?= e($location) ?></div><?php endif; ?><?php if(trim((string)($show['notes']??''))!==''): ?><div class="profile-show-notes"><?= nl2br(e((string)$show['notes'])) ?></div><?php endif; ?></div><div class="profile-show-actions"><?php if($status!=='cancelled'&&!empty($show['ticket_url'])): ?><a href="<?= e((string)$show['ticket_url']) ?>" target="_blank" rel="noopener noreferrer">Tickets ↗</a><?php endif; ?><?php if($canSave): ?><form method="post" action="<?= e(url('/my-library.php')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="shows"><input type="hidden" name="item_id" value="<?= (int)$show['id'] ?>"><button type="submit">Save</button></form><?php endif; ?></div></article>
        <?php endforeach; ?>
        <?php if(!$shows): ?><div class="profile-empty">No upcoming published shows yet.</div><?php endif; ?>
      </section>

      <section class="profile-card profile-panel" data-profile-panel="photos" hidden>
        <h2>Photos</h2><div class="profile-media-grid"><?php foreach($photos as $photo): ?><article class="profile-media"><img src="<?= e(url('/content-image.php?type=artist_photo&id='.(int)$photo['id'])) ?>" alt="<?= e((string)($photo['alt_text']??$photo['title']??'')) ?>" loading="lazy"><div><strong><?= e((string)($photo['title']??'')) ?></strong><?php if(trim((string)($photo['caption']??''))!==''): ?><p><?= e((string)$photo['caption']) ?></p><?php endif; ?><?php if($canSave): ?><form method="post" action="<?= e(url('/my-library.php')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="photos"><input type="hidden" name="item_id" value="<?= (int)$photo['id'] ?>"><button class="profile-save-button" type="submit">Save</button></form><?php endif; ?></div></article><?php endforeach; ?></div><?php if(!$photos): ?><div class="profile-empty">No published photos yet.</div><?php endif; ?>
      </section>

      <section class="profile-card profile-panel" data-profile-panel="posts" hidden>
        <h2>Posts</h2><div class="profile-posts"><?php foreach($posts as $post): ?><article class="profile-post"><?php if(function_exists('artist_posts_v183_schema_ready')&&artist_posts_v183_schema_ready()&&(int)($post['image_photo_id']??0)>0): ?><img src="<?= e(url('/artist-post-image.php?post='.(int)$post['id'])) ?>" alt="" loading="lazy"><?php endif; ?><div class="profile-post-copy"><div class="profile-post-meta"><span><?= e(ucwords(str_replace('-',' ',(string)($post['post_type']??'update')))) ?></span><?php if(!empty($post['published_at'])): ?><span>·</span><time datetime="<?= e(date(DATE_ATOM,strtotime((string)$post['published_at']))) ?>"><?= e(date('M j, Y',strtotime((string)$post['published_at']))) ?></time><?php endif; ?></div><h3><?= e((string)$post['title']) ?></h3><p><?= nl2br(e((string)$post['body'])) ?></p><?php if(!empty($post['media_url'])): ?><a class="profile-post-link" href="<?= e((string)$post['media_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Open media ↗</a><?php endif; ?></div></article><?php endforeach; ?></div><?php if(!$posts): ?><div class="profile-empty">No published posts yet.</div><?php endif; ?>
      </section>

      <section class="profile-card profile-panel" data-profile-panel="merch" hidden>
        <h2>Merch</h2><div class="profile-media-grid"><?php foreach($merch as $item): ?><article class="profile-media"><?php if(trim((string)($item['image_path']??''))!==''): ?><img src="<?= e(url('/content-image.php?type=artist_merch&id='.(int)$item['id'])) ?>" alt="<?= e((string)$item['title']) ?>" loading="lazy"><?php endif; ?><div><strong><?= e((string)$item['title']) ?></strong><?php if(isset($item['price_cents'])): ?><p>$<?= e(number_format(((int)$item['price_cents'])/100,2)) ?></p><?php endif; ?><?php if(trim((string)($item['description']??''))!==''): ?><p><?= e((string)$item['description']) ?></p><?php endif; ?><?php if(!empty($item['product_url'])): ?><a href="<?= e((string)$item['product_url']) ?>" target="_blank" rel="noopener noreferrer">View item ↗</a><?php endif; ?></div></article><?php endforeach; ?></div><?php if(!$merch): ?><div class="profile-empty">No published merch yet.</div><?php endif; ?>
      </section>

      <section class="profile-card profile-panel" data-profile-panel="about" hidden><h2>About</h2><?php if($bio!==''): ?><p><?= nl2br(e($bio)) ?></p><?php else: ?><div class="profile-empty">No bio has been published yet.</div><?php endif; ?><?php if($links): ?><div class="profile-links profile-about-links"><?php foreach($links as $label=>$href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($label) ?> ↗</a><?php endforeach; ?></div><?php endif; ?></section>
      <?php endif; ?>
    </div>

    <aside>
      <?php if($agent): ?>
      <section class="profile-card profile-agent-card" id="profileAgentShell">
        <header class="profile-agent-head"><span class="profile-agent-avatar"><?= e(mb_strtoupper(mb_substr((string)$agent['display_name'],0,1))) ?></span><div><strong><?= e((string)$agent['display_name']) ?></strong><small><?= e($displayName) ?>’s Profile Agent · powered by <?= e(system_agent_name()) ?></small></div></header>
        <div class="profile-agent-disclosure">AI representative — not the profile owner live. Answers use only information this profile has approved.</div>
        <?php if($preview): ?>
          <div class="profile-agent-preview"><p><?= e($agentGreeting) ?></p><strong>Visitor preview</strong><span>Conversation sending is disabled here so your own preview cannot create visitor events, notifications, or attention items.</span></div>
        <?php else: ?>
          <div class="profile-agent-thread" data-profile-agent-thread aria-live="polite"></div>
          <form class="profile-agent-compose"><textarea maxlength="2000" placeholder="Ask about music, shows, projects…" aria-label="Message Profile Agent"></textarea><button type="submit">Send</button><div class="profile-agent-status" data-profile-agent-status role="status" aria-live="polite"></div></form>
        <?php endif; ?>
      </section>
      <?php else: ?><section class="profile-card profile-no-agent"><strong>Profile Agent is off</strong><p>This user has not enabled a public Profile Agent.</p></section><?php endif; ?>
    </aside>
  </div>
</main>
<script>
window.STONEFELLOW_PROFILE_AGENT={username:<?= json_encode($username,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,endpoint:<?= json_encode(url('/api/profile-agent.php'),JSON_UNESCAPED_SLASHES) ?>,profileToken:<?= json_encode($profileToken,JSON_UNESCAPED_SLASHES) ?>,agentName:<?= json_encode((string)($agent['display_name']??''),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,greeting:<?= json_encode($agentGreeting,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>};
for(const tab of document.querySelectorAll('[data-profile-tab]'))tab.addEventListener('click',()=>{const key=tab.dataset.profileTab;document.querySelectorAll('[data-profile-tab]').forEach(t=>t.setAttribute('aria-selected',String(t===tab)));document.querySelectorAll('[data-profile-panel]').forEach(p=>p.hidden=p.dataset.profilePanel!==key);});
document.querySelector('[data-open-profile-agent]')?.addEventListener('click',()=>document.getElementById('profileAgentShell')?.scrollIntoView({behavior:'smooth',block:'start'}));
</script>
<?php if($agent&&!$preview): ?><script src="<?= e(url('/profile-agent.js?v=profile-agent-attention-20260903')) ?>"></script><?php endif; ?>
</body>
</html>