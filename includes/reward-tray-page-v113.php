<?php
declare(strict_types=1);

require_login();
$user=current_user();
$pdo=db();
$bucket=isset($rewardTrayPageBucket)?trim((string)$rewardTrayPageBucket):'inbox';
if(!in_array($bucket,['inbox','sent','claimed'],true))$bucket='inbox';
$labels=['inbox'=>'Inbox','sent'=>'Sent','claimed'=>'Claimed'];
$subtitles=[
    'inbox'=>'Certificates available to send or claim.',
    'sent'=>'Certificates you sent to your CRM contacts.',
    'claimed'=>'Your claimed certificate history.',
];
$routes=[
    'inbox'=>url('/reward-inbox.php'),
    'sent'=>url('/reward-sent.php'),
    'claimed'=>url('/reward-claimed.php'),
];
$schemaReady=(bool)$pdo&&function_exists('campaigns_rewards_v110_schema_ready')&&campaigns_rewards_v110_schema_ready($pdo);

$memberHeaderUser=$user;
$memberHeaderTitle='Rewards';
$memberHeaderSubtitle=$labels[$bucket];
$memberHeaderActions='';
$memberHeaderLeadingHtml='<nav id="rewardTrayTabs" class="reward-tray-tabs reward-tray-tabs-desktop" aria-label="Reward certificates">'
    .implode('',array_map(static function(string $key)use($bucket,$routes,$labels):string{
        $active=$key===$bucket;
        return '<a class="reward-tray-tab'.($active?' active':'').'" href="'.e($routes[$key]).'" data-reward-tray-tab="'.e($key).'"'.($active?' aria-current="page"':'').'>'.strtoupper($labels[$key]).' <span class="reward-tray-count" data-reward-count="'.e($key).'">0</span></a>';
    },['inbox','sent','claimed']))
    .'</nav>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f7f8">
<title>VP3 | Rewards · <?= e($labels[$bucket]) ?></title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" data-reward-tray-v113 href="<?= e(url('/reward-tray-v110.css?v=113')) ?>">
</head>
<body class="reward-page reward-tray-ready" data-reward-page="<?= e($bucket) ?>">
<div class="chat-app">
<?php
$workspaceSidebarUser=$user;
$workspaceSidebarActive='chat';
require __DIR__.'/workspace-sidebar-v82.php';
?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main reward-page-main" data-reward-page-main>
<?php require __DIR__.'/member-header.php'; ?>
<nav class="reward-tray-subheader" aria-label="Reward certificates mobile">
<?php foreach(['inbox','sent','claimed'] as $key): $active=$key===$bucket; ?>
<a class="reward-tray-tab<?= $active?' active':'' ?>" href="<?= e($routes[$key]) ?>" data-reward-tray-tab="<?= e($key) ?>"<?= $active?' aria-current="page"':'' ?>><?= e(strtoupper($labels[$key])) ?> <span class="reward-tray-count" data-reward-count="<?= e($key) ?>">0</span></a>
<?php endforeach; ?>
</nav>
<section id="rewardTrayCanvas" class="reward-tray-canvas reward-page-canvas">
<header class="reward-tray-head">
<div><small>Rewards</small><h1 id="rewardTrayTitle"><?= e($labels[$bucket]) ?></h1><p id="rewardTraySubtitle"><?= e($subtitles[$bucket]) ?></p></div>
</header>
<?php if(!$schemaReady): ?>
<div id="rewardTrayStatus" class="reward-tray-status error">Rewards needs the latest VP3 database upgrade.<?php if(user_has_role('admin',$user)): ?> <a href="<?= e(url('/upgrade.php')) ?>">Run upgrade</a>.<?php endif; ?></div>
<?php else: ?>
<div id="rewardTrayStatus" class="reward-tray-status" hidden></div>
<?php endif; ?>
<div id="rewardTrayList" class="reward-tray-list"><div class="reward-tray-empty"><strong>Loading <?= e(strtolower($labels[$bucket])) ?> certificates…</strong></div></div>
</section>
</main>
</div>
<script data-reward-tray-config-v113>window.VP3_REWARD_TRAY_V110=<?= json_encode([
    'endpoint'=>url('/api/reward-tray-v110.php'),
    'csrf'=>csrf_token(),
    'pageBucket'=>$bucket,
    'routes'=>$routes,
    'schemaReady'=>$schemaReady,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<script data-reward-qr-v113 src="<?= e(url('/reward-qr-v110.js?v=113')) ?>"></script>
<script data-reward-tray-v113 src="<?= e(url('/reward-tray-v110.js?v=113')) ?>"></script>
</body>
</html>
