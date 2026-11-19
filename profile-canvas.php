<?php
declare(strict_types=1);

/**
 * Published Profile Canvases use the canonical Profile owner and chat context.
 * profile.php validates live visibility, attachment and the published release
 * before recording a view or rendering the full-page Canvas surface.
 */
define('VP3_PROFILE_CANVAS_PAGE_V1',true);
require __DIR__.'/profile.php';
