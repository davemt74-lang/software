<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pdo = db();
if (!$pdo) {
    http_response_code(500);
    exit('Database connection failed. Create config.php first and verify your database credentials.');
}

$error = '';
$complete = false;
$alreadyConfigured = false;

try {
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema === false) throw new RuntimeException('Could not read schema.sql.');
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $schema) ?: []));
    foreach ($statements as $sql) if ($sql !== '') $pdo->exec($sql);

    ensure_access_schema();
    password_reset_ensure_schema();

    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        $alreadyConfigured = true;
        $complete = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) throw new RuntimeException('Session expired. Please try again.');
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $displayName = trim((string)($_POST['display_name'] ?? 'VP3 Admin'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid admin email.');
        if (strlen($password) < 12) throw new RuntimeException('Use an admin password with at least 12 characters.');

        $stmt = $pdo->prepare('INSERT INTO users (email,password_hash,display_name,role) VALUES (?,?,?,?)');
        $stmt->execute([$email,password_hash($password,PASSWORD_DEFAULT),$displayName,'admin']);

        $defaults = [
            'tagline' => 'Music. Stories. Connection.',
            'bio_subhead' => 'Rock, Americana and acoustic storytelling with a raw, close-to-the-room sound.',
            'genre' => 'Rock / Americana / Acoustic',
            'focus' => 'Original songs & studio sessions',
            'contact_email' => (string)site_config('email', 'stonefellow74@gmail.com'),
            'artist_bio' => default_bio(),
            'player_description' => 'A dark, intimate player for Stonefellow songs, acoustic sessions and new releases.',
            'link_spotify' => 'https://open.spotify.com/artist/4cngj2wPSfLjyibLMUpQFI',
            'link_apple_music' => 'https://music.apple.com/us/artist/stonefellow/1588143974',
            'link_tidal' => 'https://tidal.com/artist/28653042',
            'link_youtube' => 'https://www.youtube.com/@stonefellow',
            'link_instagram' => 'https://www.instagram.com/stonefellow',
            'link_facebook' => 'https://www.facebook.com/stonefellow',
        ];
        foreach ($defaults as $settingKey => $value) save_setting($settingKey, $value);

        $trackCount = (int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
        if ($trackCount === 0) {
            $stmt = $pdo->prepare('INSERT INTO tracks (title,album,duration,description,genre,mood,energy,tempo_bpm,keywords,audio_path,cover_path,sort_order,is_published,visibility) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
            foreach (fallback_tracks() as $order => $track) {
                $stmt->execute([$track['title'],$track['album'],$track['duration'],$track['description']??'',$track['genre']??'',$track['mood']??'',$track['energy']??'',$track['tempo_bpm']??null,$track['keywords']??'',$track['audio_path'],$track['cover_path'],$order+1,$track['visibility']??'public']);
            }
        }
        $complete = true;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($complete) {
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VP3 Setup</title>';
    echo '<style>body{font-family:Inter,Arial,sans-serif;background:#f5f8fb;color:#07101f;padding:40px 20px;max-width:720px;margin:auto}main{background:#fff;border:1px solid #dfe6ee;border-radius:18px;padding:30px}a{color:#07101f;font-weight:700}</style><main>';
    if ($alreadyConfigured) echo '<h1>VP3 is already configured</h1><p>An administrator account already exists, so setup is locked.</p>';
    else echo '<h1>VP3 setup complete</h1><p>The database schema exists and the administrator account has been created.</p>';
    echo '<p><strong>Delete or rename setup.php now.</strong></p><p><a href="' . e(url('/login.php')) . '">Go to sign in →</a></p></main>';
    exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VP3 Setup</title><style>body{font-family:Inter,Arial,sans-serif;background:linear-gradient(180deg,#eef5fa,#fff);color:#07101f;margin:0;padding:60px 20px}main{max-width:620px;margin:auto;background:#fff;border:1px solid #dfe6ee;padding:30px;border-radius:18px;box-shadow:0 30px 80px rgba(31,48,72,.1)}label{display:block;margin:16px 0 6px;font-weight:700;font-size:13px}input{width:100%;padding:13px;box-sizing:border-box;background:#fff;color:#07101f;border:1px solid #ccd6e1;border-radius:10px}button{margin-top:20px;padding:13px 18px;background:#07101f;color:#fff;border:0;border-radius:999px;font-weight:bold;cursor:pointer}.error{color:#a74840}.note{color:#66758b;line-height:1.55}</style></head><body><main><h1>VP3 Setup</h1><p class="note">Create the first administrator account. Setup automatically locks after the first administrator is created.</p><?php if($error):?><p class="error"><?=e($error)?></p><?php endif;?><form method="post"><?=csrf_field()?><label for="display_name">Display Name</label><input id="display_name" name="display_name" value="VP3 Admin" required><label for="email">Admin Email</label><input id="email" name="email" type="email" required><label for="password">Admin Password (12+ characters)</label><input id="password" name="password" type="password" minlength="12" required><button type="submit">Create Admin</button></form></main></body></html>
