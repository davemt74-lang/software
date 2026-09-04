<?php
declare(strict_types=1);

/**
 * Copy this file to config.php and enter your database credentials.
 * Keep config.php out of version control.
 */
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=stonefellow;charset=utf8mb4',
        'user' => 'stonefellow_user',
        'pass' => 'change-me',
    ],

    'site' => [
        'name' => 'Stonefellow',
        'email' => 'stonefellow74@gmail.com',

        // Leave blank when the site lives at the domain root.
        // Example subfolder: '/stonefellow'
        'base_path' => '',

        // If true, contact submissions are stored in the database AND PHP mail()
        // is attempted. Most production sites should use SMTP instead.
        'send_contact_email' => false,
    ],

    // Optional OpenAI-compatible chat endpoint. Leave blank to use
    // Stonefellow's built-in database/knowledge retrieval responses.
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
