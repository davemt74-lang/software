<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$schema=(string)file_get_contents($root.'/includes/personal-capabilities-v242.php');
$knowledgeLib=(string)file_get_contents($root.'/includes/knowledge.php');
$knowledgePage=(string)file_get_contents($root.'/knowledge.php');
$intelligence=(string)file_get_contents($root.'/api/artist-listening-intelligence-v300.php');
$folderUi=(string)file_get_contents($root.'/transcription-knowledge-folders-v313.js');
$naming=(string)file_get_contents($root.'/artist-listening-naming.js');
$sidebar=(string)file_get_contents($root.'/includes/main-sidebar.php');

$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok)$failures[]=$message;};

$check(str_contains($schema,"ADD COLUMN folder_id BIGINT UNSIGNED NULL"),'Personal Knowledge schema does not add the shared folder id.');
$check(str_contains($schema,"REFERENCES artist_transcript_folders_v177(id) ON DELETE SET NULL"),'Knowledge folder association is not tied to the canonical transcription folder table.');
$check(str_contains($schema,"column_exists('knowledge_items','folder_id')"),'Upgrade readiness does not require the Knowledge folder association.');

$check(str_contains($knowledgeLib,"FROM artist_transcript_folders_v177"),'Knowledge helpers do not reuse canonical transcription folders.');
$check(str_contains($knowledgeLib,"WHERE id=? AND created_by_user_id=? LIMIT 1"),'Shared folder lookup is not owner scoped.');
$check(str_contains($knowledgeLib,'personal_knowledge_create_folder'),'My Knowledge cannot create folders through the shared folder table.');
$check(str_contains($knowledgeLib,'personal_knowledge_resolve_folder_id'),'Knowledge writes do not validate shared folder ownership.');
$check(!str_contains($knowledgeLib,'knowledge_folders'),'A parallel Knowledge folder model was introduced.');

foreach(['mp3','m4a','wav','ogg','pdf','doc','docx'] as $extension){
    $check(str_contains($knowledgePage,"'{$extension}'"),'My Knowledge upload support lost '.$extension.'.');
}
$check(str_contains($knowledgePage,'name="folder_id"'),'My Knowledge upload/edit form is missing its shared-folder selector.');
$check(str_contains($knowledgePage,"action==='create_folder'"),'My Knowledge is missing shared-folder creation.');
$check(str_contains($knowledgePage,"i.folder_id IS NULL"),'My Knowledge is missing the Unfiled filter.');
$check(str_contains($knowledgePage,'LEFT JOIN artist_transcript_folders_v177'),'My Knowledge does not resolve canonical folder names.');
$check(str_contains($knowledgePage,'50*1024*1024'),'My Knowledge 50 MB upload limit was not preserved.');

$check(str_contains($intelligence,"array_key_exists('folder_id',$input)"),'Transcription intelligence does not accept an explicit folder destination.');
$check(str_contains($intelligence,'personal_knowledge_folder($pdo,$user,$folderId)'),'Transcription folder destination is not owner validated.');
$check(str_contains($intelligence,"personal_knowledge_store($user,'artist-listening-analysis:'"),'Transcription summaries no longer use deterministic Personal Knowledge storage.');
$check(str_contains($intelligence,",$folderId);"),'Transcription summary save does not pass the selected folder to Personal Knowledge.');

$check(str_contains($folderUi,"button.textContent = 'Save to folder'"),'AI Summary slideout is missing Save to folder.');
$check(str_contains($folderUi,'data-listening-ai-folder'),'AI Summary slideout is missing the shared folder selector.');
$check(str_contains($folderUi,'folder_id: folderId'),'AI Summary folder id is not sent to the server.');
$check(str_contains($folderUi,'folderSignature'),'Folder selector does not guard against MutationObserver rebuild churn.');
$check(str_contains($naming,'transcription-knowledge-folders-v313.js'),'Artist Listening does not load the shared-folder integration.');

$check(str_contains($sidebar,'>My Transcriptions<'),'Main sidebar is missing My Transcriptions.');
$check(str_contains($sidebar,"url('/artist-listening.php')"),'My Transcriptions does not target the signed-in transcription workspace.');
$check(str_contains($sidebar,'$mainSidebarIsChat'),'Main sidebar does not distinguish Agent Chat for rail settings.');
$check(str_contains($sidebar,'<?php if ($mainSidebarIsChat): ?><script data-chat-rail-controls-v132'),'Chat rail settings script is not restricted to Agent Chat.');

$check(!str_contains($knowledgePage,'native_path'),'My Knowledge must not add native HomeServer paths to Cloud forms or records.');
$check(!str_contains($knowledgeLib,'native_path'),'Knowledge storage must not persist native HomeServer paths.');

if($failures){
    fwrite(STDERR,"Shared Knowledge Folders v3.13 contract failed:\n - ".implode("\n - ",$failures)."\n");
    exit(1);
}

echo "Shared Knowledge Folders v3.13 contract: PASS\n";
