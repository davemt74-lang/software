<?php
declare(strict_types=1);

const VP3_PROFILE_CHAT_ATTACHMENT_MAX_V1 = 2097152;
const VP3_PROFILE_CHAT_ATTACHMENT_MESSAGE_V1 = 'Shared a private attachment with the profile owner.';

function vp3_profile_chat_attachment_install_v1(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
    $userId = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $blob = $sqlite ? 'BLOB' : 'MEDIUMBLOB';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $index = $sqlite ? '' : ', INDEX idx_profile_chat_attachment_owner (owner_user_id, created_at)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_chat_attachments_v1 (
        message_id $id PRIMARY KEY,
        owner_user_id $userId NOT NULL,
        filename VARCHAR(180) NOT NULL,
        mime_type VARCHAR(80) NOT NULL,
        byte_count INTEGER NOT NULL,
        caption TEXT NOT NULL,
        content $blob NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (message_id) REFERENCES profile_agent_messages(id) ON DELETE CASCADE
        $index
    )".$engine);
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profile_chat_attachment_owner ON profile_chat_attachments_v1 (owner_user_id, created_at)');
}

function vp3_profile_chat_attachment_ready_v1(PDO $pdo): bool
{
    static $ready;
    $ready ??= new WeakMap();
    if (isset($ready[$pdo])) return $ready[$pdo];
    try {
        $pdo->query('SELECT message_id, owner_user_id, filename, mime_type, byte_count, caption, content, created_at FROM profile_chat_attachments_v1 WHERE 1=0');
        return $ready[$pdo] = true;
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02' && !str_contains($e->getMessage(), 'no such table: profile_chat_attachments_v1')) throw $e;
        return false;
    }
}

function vp3_profile_chat_attachment_error_v1(string $message, string $code='PROFILE_ATTACHMENT_INVALID', int $status=422): never
{
    throw new VP3ProfileAgentPublicException($code, $message, $status);
}

/** Inspect PHP's temporary upload and never trust browser MIME metadata. */
function vp3_profile_chat_attachment_upload_v1(array $upload, string $caption): array
{
    $tmp = $upload['tmp_name'] ?? '';
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
        vp3_profile_chat_attachment_error_v1('Choose a supported image or file up to 2 MB.');
    }
    $bytes = filesize($tmp);
    if (!$bytes || $bytes > VP3_PROFILE_CHAT_ATTACHMENT_MAX_V1) vp3_profile_chat_attachment_error_v1('Choose a file between 1 byte and 2 MB.');
    if (!mb_check_encoding($caption, 'UTF-8') || mb_strlen($caption) > 2000) vp3_profile_chat_attachment_error_v1('Enter a caption up to 2,000 characters.');

    $name = is_string($upload['name'] ?? null) ? $upload['name'] : '';
    if (!mb_check_encoding($name, 'UTF-8')) vp3_profile_chat_attachment_error_v1('Choose a file with a valid filename.');
    $name = trim((string)preg_replace('/[\p{C}]/u', '', basename(str_replace('\\', '/', $name))));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $images = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
    $documents = ['pdf'=>['application/pdf'],'txt'=>['text/plain'],'csv'=>['text/plain','text/csv']];
    if (isset($images[$extension])) {
        $info = @getimagesize($tmp);
        if ($mime !== $images[$extension] || ($info['mime'] ?? null) !== $mime || $info[0] > 8000 || $info[1] > 8000 || $info[0] * $info[1] > 16000000) {
            vp3_profile_chat_attachment_error_v1('Choose a valid JPG, PNG, WebP, or GIF image up to 16 megapixels.');
        }
    } elseif (!in_array($mime, $documents[$extension] ?? [], true)) {
        vp3_profile_chat_attachment_error_v1('Choose a JPG, PNG, WebP, GIF, PDF, TXT, or CSV file.');
    }
    $contents = file_get_contents($tmp);
    if (!is_string($contents) || strlen($contents) !== $bytes) vp3_profile_chat_attachment_error_v1('The file could not be read.');
    $name = (mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 170) ?: 'attachment').'.'.$extension;
    return [
        'name'=>$name, 'mime'=>$mime, 'bytes'=>$bytes, 'caption'=>$caption, 'content'=>$contents,
        'fingerprint'=>hash('sha256', json_encode([$name,$mime,$caption,hash('sha256',$contents)], JSON_THROW_ON_ERROR)),
    ];
}

/** The owner row and conversation are already locked by native message admission. */
function vp3_profile_chat_attachment_admit_v1(PDO $pdo, array $conversation, string $mode, bool $continueLive): void
{
    if (!vp3_profile_chat_attachment_ready_v1($pdo)) vp3_profile_chat_attachment_error_v1('Private attachments require a database upgrade.', 'PROFILE_ATTACHMENT_UNAVAILABLE', 503);
    $owner = (int)$conversation['owner_user_id'];
    $cid = (int)$conversation['id'];
    $thread = vp3_profile_handoff_thread_pa3($pdo, $owner, $cid, true);
    $media = vp3_profile_handoff_media_active_pa3d($pdo, $owner, $cid, true);
    $live = $continueLive && $mode === 'live' && ($thread['requested_mode'] ?? null) === 'live'
        && ($thread['live_status'] ?? null) === 'accepted';
    $ownerChat = !$continueLive && $mode === 'auto' && ($conversation['status'] ?? null) === 'owner_joined'
        && (!$thread || ($thread['requested_mode'] ?? null) === 'auto');
    if ((!$media && !$live && !$ownerChat) || ($thread && $thread['mode'] !== 'owner')) vp3_profile_chat_attachment_owner_required_v1();

    $count = $pdo->prepare('SELECT COUNT(*) FROM profile_chat_attachments_v1 a JOIN profile_agent_messages m ON m.id=a.message_id WHERE m.conversation_id=?');
    $count->execute([$cid]);
    if ((int)$count->fetchColumn() >= 25) vp3_profile_chat_attachment_error_v1('This conversation has reached its attachment limit.', 'PROFILE_ATTACHMENT_LIMIT', 429);
    $count = $pdo->prepare('SELECT COUNT(*) FROM profile_chat_attachments_v1 WHERE owner_user_id=? AND created_at>=?');
    $count->execute([$owner, gmdate('Y-m-d H:i:s', time()-86400)]);
    if ((int)$count->fetchColumn() >= 100) vp3_profile_chat_attachment_error_v1('This profile has reached its daily attachment limit.', 'PROFILE_ATTACHMENT_LIMIT', 429);
}

function vp3_profile_chat_attachment_owner_required_v1(): never
{
    vp3_profile_chat_attachment_error_v1('You can send attachments after the profile owner joins the chat.', 'PROFILE_ATTACHMENT_OWNER_REQUIRED', 409);
}

function vp3_profile_chat_attachment_save_v1(PDO $pdo, int $owner, int $messageId, array $file): void
{
    $query = $pdo->prepare('INSERT INTO profile_chat_attachments_v1 (message_id,owner_user_id,filename,mime_type,byte_count,caption,content,created_at) VALUES (?,?,?,?,?,?,?,?)');
    $query->bindValue(1, $messageId, PDO::PARAM_INT);
    $query->bindValue(2, $owner, PDO::PARAM_INT);
    $query->bindValue(3, $file['name']);
    $query->bindValue(4, $file['mime']);
    $query->bindValue(5, $file['bytes'], PDO::PARAM_INT);
    $query->bindValue(6, $file['caption']);
    $query->bindValue(7, $file['content'], PDO::PARAM_LOB);
    $query->bindValue(8, gmdate('Y-m-d H:i:s'));
    $query->execute();
}

/** For already authorized owner or native visitor reads. Never project file contents. */
function vp3_profile_chat_attachment_messages_v1(PDO $pdo, array $messages): array
{
    if (!$messages || !vp3_profile_chat_attachment_ready_v1($pdo)) return $messages;
    $ids = array_values(array_filter(array_map('intval', array_column($messages, 'id'))));
    if (!$ids) return $messages;
    $params = implode(',',array_fill(0,count($ids),'?'));
    $query = $pdo->prepare("SELECT message_id,filename,mime_type,byte_count,caption FROM profile_chat_attachments_v1 WHERE message_id IN ($params)");
    $query->execute($ids);
    $files = $query->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
    foreach ($messages as &$message) {
        $file = $files[(int)($message['id'] ?? 0)] ?? null;
        if (!$file) continue;
        $message['message'] = $file['caption'];
        $message['attachment'] = ['name'=>$file['filename'],'mime'=>$file['mime_type'],'bytes'=>(int)$file['byte_count']];
    }
    return $messages;
}

function vp3_profile_chat_attachment_output_v1(PDO $pdo, int $owner, int $cid, int $messageId, bool $preview): never
{
    if ($cid < 1 || $messageId < 1 || !vp3_profile_chat_attachment_ready_v1($pdo)) {
        vp3_profile_chat_attachment_error_v1('Attachment not found.', 'PROFILE_ATTACHMENT_NOT_FOUND', 404);
    }
    $query = $pdo->prepare("SELECT a.filename,a.mime_type,a.byte_count,a.content FROM profile_chat_attachments_v1 a
        INNER JOIN profile_agent_messages m ON m.id=a.message_id AND m.sender_type='visitor'
        WHERE a.message_id=? AND a.owner_user_id=? AND m.conversation_id=?");
    $query->execute([$messageId, $owner, $cid]);
    $file = $query->fetch(PDO::FETCH_ASSOC);
    if (!$file || !is_string($file['content']) || strlen($file['content']) !== (int)$file['byte_count']) {
        vp3_profile_chat_attachment_error_v1('Attachment not found.', 'PROFILE_ATTACHMENT_NOT_FOUND', 404);
    }

    $inline = $preview && in_array($file['mime_type'], ['image/jpeg','image/png','image/webp','image/gif'], true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: '.($inline ? $file['mime_type'] : 'application/octet-stream'));
    header('Content-Length: '.strlen($file['content']));
    $fallback = 'attachment.'.pathinfo($file['filename'], PATHINFO_EXTENSION);
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($file['filename']));
    header('Cache-Control: private, no-store');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('X-Content-Type-Options: nosniff');
    echo $file['content'];
    exit;
}
