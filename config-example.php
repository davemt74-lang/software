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

        // Public origin used for security-sensitive outbound links such as
        // password recovery. Use scheme + host only, with no trailing slash.
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

    // Optional OpenAI-compatible chat endpoint. Leave blank to use
    // VP3's built-in database/knowledge retrieval responses.
    'ai' => [
        'endpoint' => '',
        'api_key' => '',
        'model' => '',
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
