<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();

// Campaigns & Rewards V1.10 moved the personal Reward Wallet into the
// dedicated Reward Inbox page so it is available without becoming another sidebar app.
redirect(url('/reward-inbox.php'));
