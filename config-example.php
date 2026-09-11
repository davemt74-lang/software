<?php
declare(strict_types=1);

/**
 * Copy this file to config.php and enter your database credentials.
 * Keep config.php out of version control.
 */
return [
    'db' => [
        // Existing deployments may keep their current database/schema names.
        'dsn'  => 'mysql:host=localhost;dbname=vp3;charset=utf8mb4',
        'user' => 'vp3_user',
        'pass' => 'change-me',
    ],

    'site' => [
        'name' => 'VP3',
        'email' => '',

        // Public origin used for security-sensitive outbound links and Stripe
        // Checkout/Portal callbacks. Use scheme + host only, no trailing slash.
        // Example: 'https://vp3.example.com'
        'base_url' => '',

        // Leave blank when the site lives at the domain root.
        // Example subfolder: '/vp3'
        'base_path' => '',

        // If true, contact/demo submissions are stored in the database AND
        // PHP mail() is attempted. Most production sites should use SMTP.
        'send_contact_email' => false,

        // Enables one-time VP3 password-reset emails. If omitted, the runtime
        // falls back to send_contact_email for backward compatibility.
        'send_password_reset_email' => false,
    ],

    // HomeServer's trusted Remote Relay. Production must use HTTPS. The same
    // value may instead be supplied as VP3_HOMESERVER_RELAY_URL. HomeServer
    // itself connects outbound to the corresponding WSS /bridge endpoint.
    'homeserver' => [
        'relay_base_url' => 'https://relay.example.com',
    ],

    // Optional OpenAI-compatible chat endpoint. Leave blank to use
    // VP3's built-in database/knowledge retrieval responses.
    'ai' => [
        'endpoint' => '',
        'api_key' => '',
        'model' => '',
    ],

    // Optional provider/model cost estimates for the AI Usage ledger. VP3 does
    // not ship mutable API prices as code. Add current rates you actually pay,
    // using provider:model keys. Wildcards such as "openai:*" are supported.
    // Example only — enter your own current rates before relying on estimates.
    'ai_cost_rates' => [
        // 'openai:gpt-example' => [
        //     'input_per_million_usd' => 0.00,
        //     'output_per_million_usd' => 0.00,
        // ],
    ],

    // Phase 5 calendar synchronization. The encryption key protects OAuth
    // access/refresh tokens at rest. Use a long random production secret and
    // keep it stable after users connect calendars. Every value can instead be
    // supplied by environment variables shown below.
    'calendar' => [
        'encryption_key' => '', // VP3_CALENDAR_ENCRYPTION_KEY
        'providers' => [
            'google' => [
                'client_id' => '',     // VP3_GOOGLE_CALENDAR_CLIENT_ID
                'client_secret' => '', // VP3_GOOGLE_CALENDAR_CLIENT_SECRET
            ],
            'microsoft' => [
                'client_id' => '',     // VP3_MICROSOFT_CALENDAR_CLIENT_ID
                'client_secret' => '', // VP3_MICROSOFT_CALENDAR_CLIENT_SECRET
                'tenant' => 'common',  // VP3_MICROSOFT_CALENDAR_TENANT
            ],
        ],
    ],

    // Phase 2 billing. VP3 treats Admin package prices as the source of truth
    // and creates/syncs Stripe Products and recurring Prices automatically.
    // Secret values may instead be supplied as STRIPE_SECRET_KEY and
    // STRIPE_WEBHOOK_SECRET environment variables.
    'billing' => [
        'provider' => 'stripe',
        'currency' => 'usd',
        'stripe' => [
            'secret_key' => '',
            'webhook_secret' => '',
            'allow_promotion_codes' => true,
            'automatic_tax' => false,
        ],
    ],

    'uploads' => [
        'max_audio_bytes' => 25 * 1024 * 1024,
        'max_image_bytes' => 5 * 1024 * 1024,

        // REAPER project ZIPs are uploaded in small browser chunks.
        'max_stem_package_bytes' => 2 * 1024 * 1024 * 1024,
        'stem_chunk_bytes' => 8 * 1024 * 1024,

        // Private user camera/video/audio library limits.
        'max_user_photo_bytes' => 25 * 1024 * 1024,
        'max_user_recording_bytes' => 512 * 1024 * 1024,
        'max_user_video_bytes' => 4 * 1024 * 1024 * 1024,
    ],
];