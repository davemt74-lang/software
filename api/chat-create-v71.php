<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function chat_create_json(
    bool $ok,
    string $message,
    array $extra = [],
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        array_merge(
            [
                'ok'=>$ok,
                'message'=>$message,
            ],
            $extra
        ),
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function chat_create_permission_for(
    string $type
): string {
    return match ($type) {
        'track' => 'tracks.manage',
        'event' => 'shows.manage',
        'knowledge' => 'knowledge.manage',
        'user' => 'users.manage',
        'merch' => 'merch.manage',
        'photo' => 'photos.manage',
        default => '',
    };
}

function chat_create_upload(
    string $field,
    array $extensions,
    array $mimes,
    int $maxBytes,
    string $subdir
): ?string {
    return upload_file(
        $_FILES[$field] ?? [],
        $extensions,
        $mimes,
        $maxBytes,
        $subdir
    );
}

function chat_create_visibility(
    string $fallback = 'members'
): string {
    $visibility = trim(
        (string)($_POST['visibility'] ?? $fallback)
    );

    if (!valid_visibility($visibility)) {
        throw new RuntimeException(
            'Select a valid viewer group.'
        );
    }

    return $visibility;
}

function chat_create_published(): int
{
    return isset($_POST['is_published'])
        ? 1
        : 0;
}

if (!is_logged_in()) {
    chat_create_json(
        false,
        'Please sign in to continue.',
        [],
        401
    );
}

if (!access_schema_ready()) {
    chat_create_json(
        false,
        'The current database upgrade must be installed before creating content.',
        [
            'upgrade_required'=>true,
        ],
        409
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_create_json(
        false,
        'POST is required.',
        [],
        405
    );
}

if (!verify_csrf()) {
    chat_create_json(
        false,
        'Your session expired. Refresh Agent Chat and try again.',
        [],
        419
    );
}

$type = trim(
    (string)($_POST['type'] ?? '')
);
$permission =
    chat_create_permission_for($type);

if (
    $permission === '' ||
    !has_permission($permission)
) {
    chat_create_json(
        false,
        'You do not have permission to create this content type.',
        [],
        403
    );
}

$pdo = db();

if (!$pdo) {
    chat_create_json(
        false,
        'Database unavailable.',
        [],
        503
    );
}

global $config;
$user = current_user();
$createdId = 0;
$uploadedPaths = [];

try {
    if ($type === 'track') {
        $title = trim(
            (string)($_POST['title'] ?? '')
        );
        $album = trim(
            (string)($_POST['album'] ?? 'Stonefellow')
        );
        $duration = trim(
            (string)($_POST['duration'] ?? '')
        );
        $description = trim(
            (string)($_POST['description'] ?? '')
        );
        $genre = trim(
            (string)($_POST['genre'] ?? '')
        );
        $mood = trim(
            (string)($_POST['mood'] ?? '')
        );
        $energy = trim(
            (string)($_POST['energy'] ?? '')
        );
        $tempo = max(
            0,
            min(
                300,
                (int)($_POST['tempo_bpm'] ?? 0)
            )
        );
        $keywords = trim(
            (string)($_POST['keywords'] ?? '')
        );
        $sortOrder = (int)(
            $_POST['sort_order'] ?? 0
        );
        $visibility =
            chat_create_visibility('public');
        $published =
            chat_create_published();

        if ($title === '') {
            throw new RuntimeException(
                'Track title is required.'
            );
        }

        if (
            !in_array(
                $energy,
                ['', 'low', 'medium', 'high'],
                true
            )
        ) {
            throw new RuntimeException(
                'Select a valid energy level.'
            );
        }

        if (strlen($keywords) > 500) {
            $keywords = substr(
                $keywords,
                0,
                500
            );
        }

        $audioPath =
            chat_create_upload(
                'audio_file',
                ['mp3','m4a','wav','ogg'],
                [
                    'audio/mpeg',
                    'audio/mp4',
                    'audio/x-m4a',
                    'audio/wav',
                    'audio/x-wav',
                    'audio/ogg',
                    'application/octet-stream',
                ],
                (int)(
                    $config['uploads']['max_audio_bytes'] ??
                    26214400
                ),
                'audio'
            );

        if (!$audioPath) {
            throw new RuntimeException(
                'Choose an MP3, M4A, WAV or OGG audio file.'
            );
        }

        $uploadedPaths[] = $audioPath;

        $coverPath =
            chat_create_upload(
                'cover_file',
                ['jpg','jpeg','png','webp'],
                [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                ],
                (int)(
                    $config['uploads']['max_image_bytes'] ??
                    5242880
                ),
                'covers'
            );

        if ($coverPath) {
            $uploadedPaths[] = $coverPath;
        } else {
            $coverPath =
                '/images/stonefellow-studio.png';
        }

        $ownerId =
            (int)($user['id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO tracks
             (
                owner_user_id,
                producer_user_id,
                title,
                album,
                duration,
                lyrics,
                description,
                genre,
                mood,
                energy,
                tempo_bpm,
                keywords,
                audio_path,
                cover_path,
                sort_order,
                is_published,
                visibility
             )
             VALUES
             (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            $ownerId ?: null,
            null,
            $title,
            $album !== ''
                ? $album
                : 'Stonefellow',
            $duration,
            '',
            $description,
            $genre,
            $mood,
            $energy,
            $tempo ?: null,
            $keywords,
            $audioPath,
            $coverPath,
            $sortOrder,
            $published,
            $visibility,
        ]);

        $createdId =
            (int)$pdo->lastInsertId();

        chat_create_json(
            true,
            'Track created.',
            [
                'type'=>'track',
                'id'=>$createdId,
                'open_url'=>
                    url(
                        '/admin/stems.php?track='
                        . $createdId
                    ),
                'refresh_view'=>'tracks',
            ]
        );
    }

    if ($type === 'event') {
        $showDate = trim(
            (string)($_POST['show_date'] ?? '')
        );
        $venue = trim(
            (string)($_POST['venue'] ?? '')
        );
        $city = trim(
            (string)($_POST['city'] ?? '')
        );
        $region = trim(
            (string)($_POST['region'] ?? '')
        );
        $notes = trim(
            (string)($_POST['notes'] ?? '')
        );
        $ticketUrl = trim(
            (string)($_POST['ticket_url'] ?? '')
        );
        $published =
            chat_create_published();

        if (
            $showDate === '' ||
            $venue === ''
        ) {
            throw new RuntimeException(
                'Date/time and venue are required.'
            );
        }

        $timestamp = strtotime(
            $showDate
        );

        if ($timestamp === false) {
            throw new RuntimeException(
                'Enter a valid event date and time.'
            );
        }

        if (
            $ticketUrl !== '' &&
            !filter_var(
                $ticketUrl,
                FILTER_VALIDATE_URL
            )
        ) {
            throw new RuntimeException(
                'Enter a valid ticket URL.'
            );
        }

        $stmt = $pdo->prepare(
            'INSERT INTO shows
             (
                show_date,
                venue,
                city,
                region,
                notes,
                ticket_url,
                is_published
             )
             VALUES (?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            date(
                'Y-m-d H:i:s',
                $timestamp
            ),
            $venue,
            $city,
            $region,
            $notes,
            $ticketUrl,
            $published,
        ]);

        $createdId =
            (int)$pdo->lastInsertId();

        chat_create_json(
            true,
            'Event created.',
            [
                'type'=>'event',
                'id'=>$createdId,
                'refresh_view'=>'shows',
            ]
        );
    }

    if ($type === 'knowledge') {
        $title = trim(
            (string)($_POST['title'] ?? '')
        );
        $description = trim(
            (string)($_POST['description'] ?? '')
        );
        $content = trim(
            (string)($_POST['content_text'] ?? '')
        );
        $visibility =
            chat_create_visibility('members');
        $trackId = max(
            0,
            (int)($_POST['track_id'] ?? 0)
        );
        $published =
            chat_create_published();

        if ($title === '') {
            throw new RuntimeException(
                'Knowledge title is required.'
            );
        }

        if ($trackId > 0) {
            $trackCheck = $pdo->prepare(
                'SELECT id
                 FROM tracks
                 WHERE id=?
                 LIMIT 1'
            );
            $trackCheck->execute([
                $trackId
            ]);

            if (!$trackCheck->fetchColumn()) {
                throw new RuntimeException(
                    'Choose a valid track.'
                );
            }
        }

        $filePath = '';
        $fileName = '';
        $fileType = 'text';
        $mimeType = '';
        $fileSize = 0;
        $extractedText = '';

        $upload =
            $_FILES['knowledge_file'] ??
            [];

        if (
            ($upload['error'] ??
                UPLOAD_ERR_NO_FILE)
            !== UPLOAD_ERR_NO_FILE
        ) {
            if (
                ($upload['error'] ??
                    UPLOAD_ERR_OK)
                !== UPLOAD_ERR_OK
            ) {
                throw new RuntimeException(
                    'Knowledge file upload failed.'
                );
            }

            if (
                (int)($upload['size'] ?? 0) >
                50 * 1024 * 1024
            ) {
                throw new RuntimeException(
                    'Knowledge files are limited to 50 MB.'
                );
            }

            $extension = strtolower(
                pathinfo(
                    (string)($upload['name'] ?? ''),
                    PATHINFO_EXTENSION
                )
            );

            $allowed = [
                'txt','md','csv','json',
                'html','htm','xml',
                'doc','docx','pdf',
                'mp3','m4a','wav','ogg',
            ];

            if (
                !in_array(
                    $extension,
                    $allowed,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Unsupported knowledge file type.'
                );
            }

            $targetDir =
                STONEFELLOW_ROOT
                . '/uploads/knowledge';

            if (
                !is_dir($targetDir) &&
                !mkdir(
                    $targetDir,
                    0755,
                    true
                ) &&
                !is_dir($targetDir)
            ) {
                throw new RuntimeException(
                    'Could not create the knowledge upload directory.'
                );
            }

            $newName =
                bin2hex(
                    random_bytes(16)
                )
                . '.'
                . $extension;

            $absolute =
                $targetDir
                . '/'
                . $newName;

            if (
                !move_uploaded_file(
                    (string)$upload['tmp_name'],
                    $absolute
                )
            ) {
                throw new RuntimeException(
                    'Could not save the knowledge file.'
                );
            }

            $filePath =
                '/uploads/knowledge/'
                . $newName;
            $uploadedPaths[] =
                $filePath;
            $fileName =
                basename(
                    (string)$upload['name']
                );
            $fileType =
                $extension;
            $fileSize =
                (int)$upload['size'];

            if (
                function_exists(
                    'finfo_open'
                )
            ) {
                $finfo =
                    finfo_open(
                        FILEINFO_MIME_TYPE
                    );

                if ($finfo) {
                    $detected =
                        finfo_file(
                            $finfo,
                            $absolute
                        );

                    if (
                        is_string($detected)
                    ) {
                        $mimeType =
                            $detected;
                    }

                    finfo_close(
                        $finfo
                    );
                }
            }

            $extractedText =
                knowledge_extract_file_text(
                    $absolute,
                    $extension
                );
        }

        $content = trim(
            $content
            . (
                $extractedText !== ''
                    ? "\n\n"
                        . $extractedText
                    : ''
            )
        );

        if (
            $content === '' &&
            $filePath === ''
        ) {
            throw new RuntimeException(
                'Add knowledge text or attach a knowledge file.'
            );
        }

        $creatorId =
            (int)($user['id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO knowledge_items
             (
                track_id,
                title,
                description,
                file_name,
                file_path,
                file_type,
                mime_type,
                file_size,
                content_text,
                visibility,
                is_published,
                created_by_user_id
             )
             VALUES
             (?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            $trackId ?: null,
            $title,
            $description,
            $fileName,
            $filePath,
            $fileType,
            $mimeType,
            $fileSize,
            $content,
            $visibility,
            $published,
            $creatorId ?: null,
        ]);

        $createdId =
            (int)$pdo->lastInsertId();

        reindex_knowledge_item(
            $createdId,
            $content
        );

        chat_create_json(
            true,
            'Knowledge item created and indexed.',
            [
                'type'=>'knowledge',
                'id'=>$createdId,
            ]
        );
    }

    if ($type === 'user') {
        $displayName = trim(
            (string)($_POST['display_name'] ?? '')
        );
        $email = strtolower(
            trim(
                (string)($_POST['email'] ?? '')
            )
        );
        $role = trim(
            (string)($_POST['role'] ?? 'fan')
        );
        $password =
            (string)($_POST['password'] ?? '');
        $isActive =
            isset($_POST['is_active'])
                ? 1
                : 0;

        if ($displayName === '') {
            throw new RuntimeException(
                'Display name is required.'
            );
        }

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'Enter a valid email address.'
            );
        }

        if (!valid_role($role)) {
            throw new RuntimeException(
                'Select a valid account type.'
            );
        }

        if (strlen($password) < 12) {
            throw new RuntimeException(
                'New accounts require a password with at least 12 characters.'
            );
        }

        $check = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email=?
             LIMIT 1'
        );
        $check->execute([$email]);

        if ($check->fetchColumn()) {
            throw new RuntimeException(
                'That email address is already in use.'
            );
        }

        $avatarPath =
            chat_create_upload(
                'avatar_file',
                ['jpg','jpeg','png','webp'],
                [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                ],
                (int)(
                    $config['uploads']['max_image_bytes'] ??
                    5242880
                ),
                'avatars'
            )
            ?? '';

        if ($avatarPath !== '') {
            $uploadedPaths[] =
                $avatarPath;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users
             (
                email,
                password_hash,
                display_name,
                role,
                avatar_path,
                is_active
             )
             VALUES (?,?,?,?,?,?)'
        );

        $stmt->execute([
            $email,
            password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            $displayName,
            $role,
            $avatarPath,
            $isActive,
        ]);

        $createdId =
            (int)$pdo->lastInsertId();

        chat_create_json(
            true,
            'User created.',
            [
                'type'=>'user',
                'id'=>$createdId,
            ]
        );
    }

    if ($type === 'merch') {
        $title = trim(
            (string)($_POST['title'] ?? '')
        );
        $description = trim(
            (string)($_POST['description'] ?? '')
        );
        $price = trim(
            (string)($_POST['price'] ?? '0')
        );
        $productUrl = trim(
            (string)($_POST['product_url'] ?? '')
        );
        $visibility =
            chat_create_visibility('members');
        $sortOrder =
            (int)($_POST['sort_order'] ?? 0);
        $published =
            chat_create_published();

        if ($title === '') {
            throw new RuntimeException(
                'Merch title is required.'
            );
        }

        if (
            !is_numeric($price) ||
            (float)$price < 0
        ) {
            throw new RuntimeException(
                'Enter a valid price.'
            );
        }

        if (
            $productUrl !== '' &&
            !filter_var(
                $productUrl,
                FILTER_VALIDATE_URL
            )
        ) {
            throw new RuntimeException(
                'Enter a valid product URL.'
            );
        }

        $imagePath =
            chat_create_upload(
                'merch_image',
                ['jpg','jpeg','png','webp'],
                [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                ],
                (int)(
                    $config['uploads']['max_image_bytes'] ??
                    5242880
                ),
                'merch'
            )
            ?? '';

        if ($imagePath !== '') {
            $uploadedPaths[] =
                $imagePath;
        }

        $creatorId =
            (int)($user['id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO merch_items
             (
                title,
                description,
                price_cents,
                product_url,
                image_path,
                visibility,
                sort_order,
                is_published,
                created_by_user_id
             )
             VALUES (?,?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            $title,
            $description,
            (int)round(
                (float)$price * 100
            ),
            $productUrl,
            $imagePath,
            $visibility,
            $sortOrder,
            $published,
            $creatorId ?: null,
        ]);

        $createdId =
            (int)$pdo->lastInsertId();

        chat_create_json(
            true,
            'Merch item created.',
            [
                'type'=>'merch',
                'id'=>$createdId,
            ]
        );
    }

    if ($type === 'photo') {
        $title = trim(
            (string)($_POST['title'] ?? '')
        );
        $caption = trim(
            (string)($_POST['caption'] ?? '')
        );
        $altText = trim(
            (string)($_POST['alt_text'] ?? '')
        );
        $visibility =
            chat_create_visibility('members');
        $sortOrder =
            (int)($_POST['sort_order'] ?? 0);
        $published =
            chat_create_published();

        if ($title === '') {
            throw new RuntimeException(
                'Photo title is required.'
            );
        }

        $imagePath =
            chat_create_upload(
                'photo_file',
                ['jpg','jpeg','png','webp'],
                [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                ],
                (int)(
                    $config['uploads']['max_image_bytes'] ??
                    5242880
                ),
                'photos'
            );

        if (!$imagePath) {
            throw new RuntimeException(
                'Choose a JPG, PNG or WEBP image.'
            );
        }

        $uploadedPaths[] =
            $imagePath;
        $creatorId =
            (int)($user['id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO photos
             (
                title,
                caption,
                alt_text,
                image_path,
                visibility,
                sort_order,
                is_published,
                created_by_user_id
             )
             VALUES (?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            $title,
            $caption,
            $altText,
            $imagePath,
            $visibility,
            $sortOrder,
            $published,
            $creatorId ?: null,
        ]);

        $createdId =
            (int)$pdo->lastInsertId();

        chat_create_json(
            true,
            'Photo created.',
            [
                'type'=>'photo',
                'id'=>$createdId,
                'image_url'=>
                    url(
                        '/content-image.php?type=photo&id='
                        . $createdId
                    ),
                'refresh_view'=>'photos',
            ]
        );
    }

    throw new RuntimeException(
        'Unsupported content type.'
    );
} catch (Throwable $e) {
    foreach ($uploadedPaths as $path) {
        delete_local_upload(
            (string)$path
        );
    }

    chat_create_json(
        false,
        $e->getMessage(),
        [],
        422
    );
}
