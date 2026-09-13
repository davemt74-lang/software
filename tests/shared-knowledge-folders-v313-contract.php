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
$has=static fn(string $haystack,string $needle):bool=>str_contains($haystack,$needle);

// One canonical folder authority: the existing Artist Listening / music folder table.
$check($has($schema,'folder_id')&&$has($schema,'artist_transcript_folders_v177'),'Knowledge schema is not associated with the canonical shared folder table.');
$check($has($knowledgeLib,'artist_transcript_folders_v177'),'Knowledge helpers do not reuse canonical shared folders.');
$check($has($knowledgeLib,'created_by_user_id')&&$has($knowledgeLib,'personal_knowledge_resolve_folder_id'),'Shared folder writes are not owner validated.');
$check(!preg_match('/CREATE\s+TABLE\s+`?knowledge_folders`?/i',$schema.$knowledgeLib.$knowledgePage),'A parallel Knowledge folder table was introduced.');

// My Knowledge remains a full document/media intake workspace and can file content.
foreach(['mp3','m4a','wav','ogg','pdf','doc','docx'] as $extension){
    $check($has($knowledgePage,"'{$extension}'"),'My Knowledge upload support lost '.$extension.'.');
}
$check($has($knowledgePage,'50*1024*1024'),'My Knowledge 50 MB upload limit was not preserved.');
$check($has($knowledgePage,'name="folder_id"'),'My Knowledge upload/edit form is missing its folder selector.');
$check($has($knowledgePage,'create_folder')&&$has($knowledgePage,'folder=unfiled'),'My Knowledge folder create/open/filter flow is incomplete.');
$check($has($knowledgePage,'artist_transcript_folders_v177'),'My Knowledge does not resolve canonical shared folders.');

// Transcription AI summaries carry a validated destination folder into deterministic Personal Knowledge storage.
$check($has($intelligence,'folder_id')&&$has($intelligence,'personal_knowledge_folder'),'Transcription intelligence does not validate a shared folder destination.');
$check($has($intelligence,'personal_knowledge_store')&&$has($intelligence,'artist-listening-analysis:'),'Transcription summaries no longer use deterministic Personal Knowledge storage.');
$check($has($folderUi,'Save to folder')&&$has($folderUi,'data-listening-ai-folder'),'AI Summary slideout is missing its shared-folder save UI.');
$check($has($folderUi,'folder_id')&&$has($folderUi,'sessionId'),'AI Summary save does not carry folder/session identity to the server.');
$check($has($folderUi,'folderSignature'),'Folder selector does not guard against MutationObserver rebuild churn.');
$check($has($naming,'transcription-knowledge-folders-v313.js'),'Artist Listening does not load the shared-folder integration.');

// Navigation/settings shell requirements.
$check($has($sidebar,'My Transcriptions')&&$has($sidebar,"/artist-listening.php"),'Main sidebar is missing the signed-in My Transcriptions workspace.');
$check($has($sidebar,'$mainSidebarIsChat')&&$has($sidebar,'data-chat-rail-controls-v132'),'Chat rail settings are not scoped through the Agent Chat page condition.');

// HomeServer privacy boundary: Cloud Knowledge must never gain a native local filesystem path field.
$check(!$has($knowledgePage,'native_path')&&!$has($knowledgeLib,'native_path'),'Cloud Knowledge must not store native HomeServer paths.');

if($failures){
    fwrite(STDERR,"Shared Knowledge Folders v3.13 contract failed:\n - ".implode("\n - ",$failures)."\n");
    exit(1);
}

echo "Shared Knowledge Folders v3.13 contract: PASS\n";
