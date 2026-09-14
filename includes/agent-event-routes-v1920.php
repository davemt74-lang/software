<?php
declare(strict_types=1);
require_once __DIR__.'/agent-event-infrastructure-v1920.php';

/**
 * Server-side extension point for provider adapters and event handlers.
 * Application modules may define this function before including this file.
 * No public request may register its own verifier or handler.
 */
if(function_exists('vp3_register_agent_event_routes_v1920')){
    vp3_register_agent_event_routes_v1920();
}
