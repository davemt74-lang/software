<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/knowledge.php');
$css = (string)file_get_contents($root . '/personal-knowledge.css');
$libraryJs = (string)file_get_contents($root . '/knowledge-library-v161.js');
$pickerJs = (string)file_get_contents($root . '/shared-folder-picker-v161.js');
$folderService = (string)file_get_contents($root . '/includes/shared-folders-v161.php');
$folderApi = (string)file_get_contents($root . '/api/shared-folders-v161.php');
$naming = (string)file_get_contents($root . '/artist-listening-naming.js');

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
$has = static fn(string $haystack, string $needle): bool => str_contains($haystack, $needle);

// Canonical shared-folder authority and ownership boundary.
$check($has($folderService, 'artist_transcript_folders_v177'), 'Phase 16.1 does not reuse the canonical shared folder table.');
$check($has($folderService, 'created_by_user_id'), 'Shared-folder service does not enforce folder ownership.');
$check(!$has($folderService . $page, 'CREATE TABLE knowledge_folders'), 'A parallel Knowledge folder model was introduced.');
$check($has($folderService, 'shared_folders_v161_unfile_transcripts'), 'Folder deletion does not clear transcription folder metadata.');
$check($has($folderService, "SET folder_id=NULL"), 'Folder deletion does not explicitly move Knowledge records to Unfiled.');

// Knowledge library UX.
foreach (['personal-knowledge-folder-grid','personal-knowledge-breadcrumbs','personal-knowledge-type-filters','knowledge-bulk-form','data-knowledge-folder-drop'] as $needle) {
    $check($has($page, $needle), 'Knowledge library is missing ' . $needle . '.');
}
$check($has($page, "['all','documents','audio','transcription','music','notes']"), 'Knowledge type filters are incomplete.');
$check($has($page, "action === 'bulk_move'"), 'Knowledge bulk move handler is missing.');
$check($has($page, "action === 'rename_folder'") && $has($page, "action === 'delete_folder'"), 'Knowledge folder rename/delete controls are incomplete.');
$check($has($page, 'Updated 7d') && $has($page, 'MAX(updated_at)'), 'Knowledge folder/recent activity is not surfaced.');
$check($has($page, 'source cloud') && $has($page, 'HomeServer Local Knowledge remains a separate local source'), 'Cloud vs HomeServer source boundary is not explicit in the library UI.');

// Reusable folder picker across Knowledge and Artist Listening/Transcriptions/Music organization.
$check($has($page, 'data-vp3-shared-folder-picker'), 'My Knowledge does not use the shared folder picker.');
$check($has($pickerJs, '[data-listening-workspace-folder-select]'), 'Transcription/music metadata folder selector is not enhanced by the shared picker.');
$check($has($pickerJs, '[data-listening-ai-folder]'), 'AI Summary destination selector is not enhanced by the shared picker.');
$check($has($pickerJs, '+ New folder…') && $has($pickerJs, 'createFolder'), 'Shared folder picker cannot create folders in place.');
$check($has($naming, 'shared-folder-picker-v161.js'), 'Artist Listening does not load the reusable shared folder picker.');
$check($has($folderApi, 'shared_folders_v161_create') && $has($folderApi, 'csrf_token'), 'Shared folder create API is missing owner/session protections.');

// Multi-select and drag-to-folder behavior.
$check($has($libraryJs, 'data-knowledge-select-all') && $has($libraryJs, 'data-knowledge-bulk-submit'), 'Knowledge multi-select behavior is incomplete.');
$check($has($libraryJs, "addEventListener('drop'") && $has($libraryJs, 'knowledge-drag-form'), 'Knowledge drag-to-folder behavior is incomplete.');

// Existing document/media intake and privacy constraints remain intact.
foreach (['mp3','m4a','wav','ogg','pdf','doc','docx'] as $extension) {
    $check($has($page, "'{$extension}'"), 'Knowledge upload support lost ' . $extension . '.');
}
$check((bool)preg_match('/50\s*\*\s*1024\s*\*\s*1024/', $page), 'Knowledge 50 MB upload limit was not preserved.');
$check(!$has($page . $folderService . $folderApi, 'native_path'), 'Cloud Knowledge must not store native HomeServer paths.');

if ($failures) {
    fwrite(STDERR, "Knowledge Library v16.1 contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Knowledge Library v16.1 contract: PASS\n";
