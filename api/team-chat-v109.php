<?php
declare(strict_types=1);
// Compatibility endpoint. Existing clients may keep the v109 URL, but all
// authorization and directory scoping now run through the canonical v320 Team runtime.
require __DIR__.'/team-chat-v320.php';
