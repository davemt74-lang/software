<?php
declare(strict_types=1);

// Private-file and RFC MIME behavior uses real rasters and the production
// resolver/provider. An optional native MySQL run also exercises send claims.
define('STONEFELLOW_ROOT', sys_get_temp_dir().'/vp3-email-attachments-'.bin2hex(random_bytes(6)));
mkdir(STONEFELLOW_ROOT, 0700, true);
require dirname(__DIR__).'/includes/agent-email-attachments-v1.php';
require dirname(__DIR__).'/includes/agent-connection-gmail-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function url(string $path): string { return '/fixture'.$path; }
function setting(string $key, mixed $fallback=''): mixed { return $fallback; }
function table_exists(string $name): bool
{
    $query = db()->prepare(db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        : "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$name]);
    return (bool)$query->fetchColumn();
}
function attachment_check(bool $pass, string $label): void
{
    if (!$pass) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function attachment_reject(callable $run, int $status, string $label): void
{
    try { $run(); }
    catch (RuntimeException|DomainException $error) {
        if ($error instanceof PDOException || $error->getCode() !== $status) throw $error;
        attachment_check(true, $label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
function attachment_png(string $pixel="\xff\0\0\xff", int $width=1, string $extra=''): string
{
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)).$type.$data.hash('crc32b', $type.$data, true);
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, 1, 8, 6, 0, 0, 0))
        .($extra === '' ? '' : $chunk('tEXt', 'Fixture'."\0".$extra))
        .$chunk('IDAT', gzcompress("\0".$pixel)).$chunk('IEND', '');
}
function attachment_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT)');
    $pdo->exec('CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER,media_type VARCHAR(30),
        title VARCHAR(190),mime_type VARCHAR(60),file_path TEXT,file_size BIGINT,metadata_json TEXT)');
}
function attachment_asset(int $id, int $owner, string $bytes, string $mime='image/png', ?string $name=null): string
{
    $extension = media_studio_extension_for_mime($mime);
    $path = media_studio_ensure_user_root($owner).'/image-'.$id.'.'.$extension;
    file_put_contents($path, $bytes);
    $query = db()->prepare('INSERT INTO user_media_assets(id,user_id,media_type,title,mime_type,file_path,file_size,metadata_json)
        VALUES(?,?,\'photo\',?,?,?,?,?)');
    $query->execute([$id,$owner,'Fixture image '.$id,$mime,'private/user-media/'.$owner.'/'.basename($path),strlen($bytes),
        json_encode(['original_name'=>$name ?? 'image-'.$id.'.'.$extension], JSON_THROW_ON_ERROR)]);
    return $path;
}
function attachment_cleanup(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $child) if ($child !== '.' && $child !== '..') attachment_cleanup($path.'/'.$child);
    rmdir($path);
}

$checks = 0;
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$server = null;
$database = '';
try {
    attachment_schema($pdo);
    $png = attachment_png();
    $jpg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDnaKKK8Q/XD//Z', true);
    $webp = base64_decode('UklGRjwAAABXRUJQVlA4IDAAAADwAQCdASoBAAEAAUAmJaACdLoB+AAETAAA/u921/4VbhiGar/8gP+QH/ID/+PIAAA=', true);
    $path = attachment_asset(1, 1, $png, 'image/png', "../Quarterly\" report\r\nBcc: hidden@example.test.png");
    attachment_asset(2, 1, $jpg, 'image/jpeg');
    attachment_asset(3, 1, $webp, 'image/webp');
    $foreign = attachment_asset(4, 2, $png);
    $manifest = agent_email_attachments_freeze_v1($pdo, 1, [1,2,3]);
    attachment_check(count($manifest) === 3 && $manifest[0]['sha256'] === hash('sha256', $png)
        && $manifest[0]['size'] === strlen($png) && count($manifest[0]) === 5,
        'JPEG, PNG and WebP selections freeze exact immutable byte manifests');
    attachment_check(!preg_match('/[\r\n"\\\\\/]/', $manifest[0]['name'])
        && str_ends_with($manifest[0]['name'], '.png'), 'attachment names cannot inject MIME headers or carry paths');
    $public = agent_email_attachments_public_v1($manifest);
    attachment_check($public[0]['url'] === '/fixture/media-library-file-v86.php?id=1'
        && count($public[0]) === 5 && !isset($public[0]['sha256']) && !isset($public[0]['bytes']),
        'public projections contain only safe metadata and owner-gated image URLs');
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [4]), 404, 'another account image cannot be attached');
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [1,1]), 422, 'duplicate selected images are rejected');
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, ['1']), 422, 'asset IDs are not loosely coerced');
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [1,2,3,4,5,6]), 422, 'attachment count is bounded before reads');
    $unsafe = $manifest; $unsafe[0]['name'] = "photo.png\r\nBcc: hidden@example.test";
    attachment_reject(fn()=>agent_email_attachments_manifest_v1($unsafe), 422, 'stored manifests cannot introduce unsafe names');
    $unsafe = $manifest; $unsafe[0]['path'] = '/etc/passwd';
    attachment_reject(fn()=>agent_email_attachments_manifest_v1($unsafe), 422, 'stored manifests cannot introduce file paths');
    $oversize = [$manifest[0], $manifest[1]];
    $oversize[0]['size'] = $oversize[1]['size'] = 6 * 1024 * 1024;
    attachment_reject(fn()=>agent_email_attachments_manifest_v1($oversize), 422, 'combined attachment bytes are bounded');

    $spoof = attachment_asset(5, 1, '<html>not an image</html>');
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [5]), 422, 'photo metadata cannot disguise HTML as an image');
    attachment_asset(6, 1, substr($png, 0, -2));
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [6]), 422, 'truncated PNG containers are rejected');
    attachment_asset(7, 1, attachment_png(width:8193));
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [7]), 422, 'raster dimensions are bounded');
    $pdo->exec("UPDATE user_media_assets SET file_path='private/user-media/2/image-4.png' WHERE id=5");
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [5]), 409, 'a foreign private path fails even with owner metadata');
    unlink($spoof); symlink($foreign, $spoof);
    $pdo->exec("UPDATE user_media_assets SET file_path='private/user-media/1/image-5.png',file_size=".strlen($png).' WHERE id=5');
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [5]), 409, 'a symlink cannot escape the owner private root');
    $pdo->exec('UPDATE user_media_assets SET file_size=file_size+1 WHERE id=1');
    attachment_reject(fn()=>agent_email_attachments_freeze_v1($pdo, 1, [1]), 409, 'stored byte count must match the actual file');
    $pdo->exec('UPDATE user_media_assets SET file_size=file_size-1 WHERE id=1');
    file_put_contents($path, attachment_png("\0\xff\0\xff"));
    attachment_reject(fn()=>agent_email_attachments_parts_v1($pdo, 1, [$manifest[0]]), 409, 'changed image bytes invalidate the reviewed draft');
    file_put_contents($path, $png);
    $pdo->exec("UPDATE user_media_assets SET title='Renamed',metadata_json='{}' WHERE id=1");
    $parts = agent_email_attachments_parts_v1($pdo, 1, $manifest);
    attachment_check($parts[0]['name'] === $manifest[0]['name'] && $parts[0]['bytes'] === $png,
        'library renames preserve the already-reviewed attachment filename');

    $operation = str_repeat('a', 64);
    $reply = ['message_id'=>'inbound-1','thread_id'=>'thread-1','in_reply_to'=>'<inbound@example.test>',
        'references'=>['<ancestor@example.test>','<inbound@example.test>']];
    $posts = 0;
    $receipt = agent_connection_gmail_send_v1('fixture-token', 'owner@example.test', 'recipient@example.test',
        'Résumé image', "One\nTwo", $operation,
        function ($method, $endpoint, $headers, $payload, $form) use (&$posts, $parts, $reply, $operation): array {
            $posts++;
            attachment_check($method === 'POST' && $endpoint === VP3_GMAIL_API_V1.'/messages/send'
                && $payload['threadId'] === $reply['thread_id'] && !$form, 'multipart mail uses the existing Gmail send endpoint and thread');
            $mime = base64_decode(strtr($payload['raw'], '-_', '+/'), true);
            attachment_check(str_contains($mime, 'Message-ID: '.agent_connection_gmail_rfc_message_id_v1($operation))
                && str_contains($mime, 'In-Reply-To: '.$reply['in_reply_to'])
                && str_contains($mime, "References: <ancestor@example.test>\r\n <inbound@example.test>"),
                'multipart send preserves deterministic identity and reviewed reply headers');
            preg_match('/boundary="([^"]+)"/', $mime, $found);
            $sections = explode('--'.$found[1], $mime);
            attachment_check(count($sections) === count($parts) + 3 && str_starts_with($sections[array_key_last($sections)], '--'),
                'multipart MIME has one body, exact attachment count and a closing boundary');
            [, $body] = explode("\r\n\r\n", $sections[1], 2);
            attachment_check(base64_decode(trim($body), true) === "One\r\nTwo", 'multipart body round-trips UTF-8 line normalization');
            foreach ($parts as $index=>$part) {
                [$partHeaders, $encoded] = explode("\r\n\r\n", $sections[$index+2], 2);
                attachment_check(str_contains($partHeaders, 'Content-Type: '.$part['mime'])
                    && str_contains($partHeaders, 'filename="'.$part['name'].'"')
                    && base64_decode(trim($encoded), true) === $part['bytes'], 'attachment '.($index+1).' round-trips exact reviewed bytes and filename');
            }
            return ['status'=>200,'json'=>['id'=>'sent-1','threadId'=>'thread-1']];
        }, $reply, $parts);
    attachment_check($posts === 1 && $receipt['message_id'] === 'sent-1', 'multipart success returns one verified provider receipt');
    $badParts = $parts; $badParts[0]['name'] = "bad.png\r\nBcc: injected@example.test";
    attachment_reject(fn()=>agent_connection_gmail_send_v1('token','owner@example.test','recipient@example.test','Subject','Body',$operation,
        fn()=>throw new RuntimeException('Invalid attachment reached Gmail.'), null, $badParts), 400,
        'provider MIME boundary rejects unsafe attachment names before transport');
    unlink($path);
    attachment_check(agent_email_attachments_public_v1($manifest) === $public, 'deleted files do not hide saved draft or receipt metadata');

    $dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
    if (str_starts_with($dsn, 'mysql:')) {
        $rows = $pdo->query('SELECT * FROM user_media_assets')->fetchAll(PDO::FETCH_ASSOC);
        $server = new PDO($dsn, getenv('VP3_TEST_MYSQL_USER') ?: 'root', (string)getenv('VP3_TEST_MYSQL_PASS'),
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $database = 'vp3_attachment_fixture_'.bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `'.$database.'`');
        $pdo = new PDO(preg_replace('/;dbname=[^;]+/', '', $dsn).';dbname='.$database,
            getenv('VP3_TEST_MYSQL_USER') ?: 'root', (string)getenv('VP3_TEST_MYSQL_PASS'),
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        attachment_schema($pdo);
        foreach ($rows as $row) $pdo->prepare('INSERT INTO user_media_assets VALUES(?,?,?,?,?,?,?,?)')->execute(array_values($row));
        require dirname(__DIR__).'/includes/agent-connections-v1.php';
        $pdo->exec("CREATE TABLE agent_connections_v1(id BIGINT PRIMARY KEY,owner_user_id BIGINT,provider VARCHAR(32),
            account_email VARCHAR(254),status VARCHAR(20),read_enabled INT,send_enabled INT,followups_enabled INT,
            authorization_key CHAR(64),client_fingerprint CHAR(64),access_token_enc TEXT,refresh_token_enc TEXT,access_expires_at BIGINT)");
        $pdo->exec("CREATE TABLE agent_connection_sends_v1(id BIGINT AUTO_INCREMENT PRIMARY KEY,owner_user_id BIGINT,connection_id BIGINT,
            authorization_key CHAR(64),operation_key CHAR(64),payload_digest CHAR(64),status VARCHAR(20) DEFAULT 'pending',
            provider_message_id VARCHAR(191) DEFAULT '',provider_thread_id VARCHAR(191) DEFAULT '',sent_at BIGINT DEFAULT 0,
            UNIQUE KEY send_identity(connection_id,operation_key))");
        $pdo->exec('CREATE TABLE agent_connection_email_drafts_v1(authorization_key CHAR(64),message_enc TEXT);
            CREATE TABLE agent_connection_email_followups_v1(claim_key CHAR(64))');
        $config = ['connections'=>['gmail'=>['client_id'=>'attachment-fixture','client_secret'=>'attachment-secret']]];
        $grant = str_repeat('b', 64);
        $pdo->prepare("INSERT INTO agent_connections_v1 VALUES(1,1,'gmail','owner@example.test','connected',1,1,0,?,?,?,?,?)")
            ->execute([$grant,agent_connections_client_fingerprint_v1(),ai_encrypt_secret('fixture-access'),'unused',time()+3600]);
        file_put_contents($path, $png);
        $frozen = [$manifest[0]];
        $posts = 0; $gets = 0; $unknown = false;
        $transport = function ($method, $endpoint, $headers, $payload) use (&$posts,&$gets,&$unknown,&$activeOperation): array {
            if ($method === 'POST') {
                $posts++;
                return $unknown ? ['status'=>0,'json'=>null] : ['status'=>200,'json'=>['id'=>'send-native','threadId'=>'native-thread']];
            }
            $gets++;
            if (str_contains($endpoint, '/messages?')) return ['status'=>200,'json'=>['messages'=>[['id'=>'send-native']]]];
            return ['status'=>200,'json'=>['id'=>'send-native','threadId'=>'native-thread','labelIds'=>['SENT'],'internalDate'=>'1777777700000',
                'payload'=>['headers'=>[['name'=>'Message-ID','value'=>agent_connection_gmail_rfc_message_id_v1($activeOperation)]]]]];
        };
        $send = static fn(string $key,array $images,bool $reconcile=false,?callable $before=null): array => agent_connections_send_v1(
            1,1,'recipient@example.test','Subject','Body',$key,$grant,null,$transport,$before,null,$reconcile,null,$images);
        $activeOperation = str_repeat('1',64);
        $sent = $send($activeOperation,$frozen);
        unlink($path);
        attachment_check($send($activeOperation,$frozen)['message_id'] === $sent['message_id'] && $posts === 1 && $gets === 0,
            'native send replay returns its receipt after image deletion without another POST');
        $changed = $frozen; $changed[0]['name'] = 'changed.png';
        attachment_reject(fn()=>$send($activeOperation,$changed), 409, 'same send key cannot silently substitute a different attachment manifest');
        file_put_contents($path,$png);
        $activeOperation = str_repeat('2',64); $unknown = true;
        attachment_reject(fn()=>$send($activeOperation,$frozen), 502, 'uncertain multipart delivery retains an unresolved send claim');
        unlink($path);
        $reconciled = $send($activeOperation,$frozen,true);
        attachment_check($reconciled['message_id'] === 'send-native' && $posts === 2 && $gets === 2,
            'uncertain send reconciles exact RFC identity with deleted image and zero duplicate POSTs');
        $activeOperation = str_repeat('3',64); $unknown = false;
        file_put_contents($path,$png);
        attachment_reject(fn()=>$send($activeOperation,$frozen,false,
            static function () use ($path): void {file_put_contents($path,attachment_png("\0\xff\0\xff"));}),409,
            'image mutation during final draft authorization is caught before Gmail dispatch');
        attachment_check($posts === 2 && (int)$pdo->query("SELECT COUNT(*) FROM agent_connection_sends_v1 WHERE operation_key='".$activeOperation."'")->fetchColumn() === 0,
            'rejected attachment source releases only its unsubmitted send claim');
    } else {
        echo "SKIP native send-claim cases: set VP3_TEST_MYSQL_DSN to enable them.\n";
    }
    echo 'PASS '.$checks." email attachment checks.\n";
} finally {
    if ($server instanceof PDO && $database !== '') $server->exec('DROP DATABASE `'.$database.'`');
    attachment_cleanup(STONEFELLOW_ROOT);
}
