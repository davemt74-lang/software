<?php
declare(strict_types=1);

/**
 * Cookie-independent external API bootstrap. Load the same catalog, entitlement
 * and role services as VP3, without starting a personal session or running
 * unrelated request gates, notifications, schema upgrades or Agent maintenance.
 */
if (!defined('STONEFELLOW_ROOT')) define('STONEFELLOW_ROOT',dirname(__DIR__));
$configFile=STONEFELLOW_ROOT.'/config.php';
$config=is_file($configFile)?require $configFile:['db'=>['dsn'=>''],'site'=>[]];
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/subscriptions.php';
require_once __DIR__.'/plugin-registry-v320.php';
require_once __DIR__.'/music-workspace-plugin-v320.php';
require_once __DIR__.'/plugin-lifecycle-v360.php';
require_once __DIR__.'/artist-workspace-v181.php';
require_once __DIR__.'/music-workspace-resources-v330.php';
require_once __DIR__.'/artist-media-v182.php';
require_once __DIR__.'/artist-music-v185.php';
require_once __DIR__.'/music-song-metadata-v1.php';
require_once __DIR__.'/music-media.php';
require_once __DIR__.'/stem-mp3-metadata-v1.php';
require_once __DIR__.'/music-provider-v1.php';
require_once __DIR__.'/music-provider-http-v1.php';
