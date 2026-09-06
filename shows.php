<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// The former Stonefellow artist-site shows page is no longer part of the VP3
// public product surface. Artist/event content now belongs to published user
// profiles and workspaces, so old bookmarks return to the canonical VP3 site.
redirect(url('/index.php'));
