<?php
declare(strict_types=1);

function vp3_member_transcriptions_runtime_v1(array $user): string
{
    if(preg_match('~(?:^|/)admin(?:/|$)~',(string)($_SERVER['SCRIPT_NAME']??''))||!has_permission('artist_listening.access',$user)||!empty($GLOBALS['VP3_MEMBER_TRANSCRIPTIONS_V1']))return '';
    $GLOBALS['VP3_MEMBER_TRANSCRIPTIONS_V1']=true;$root=dirname(__DIR__);
    $build=substr(hash('sha256',hash_file('sha256',$root.'/chat-transcription-canvas.js').hash_file('sha256',$root.'/artist-listening-recordings.js').hash_file('sha256',$root.'/chat-transcription-canvas.css')),0,12);
    $cfg=['endpoint'=>url('/api/artist-recordings-v198.php'),'csrf'=>csrf_token(),'artistListeningUrl'=>url('/artist-listening.php'),
        'listeningEndpoint'=>url('/api/artist-listening.php'),'intelligenceEndpoint'=>url('/api/artist-listening-intelligence-v300.php'),
        'persistEndpoint'=>url('/api/chat-recordings-v242.php'),'ownerUserId'=>(int)$user['id'],'workingAttentionBinding'=>vp3_catch_up_page_binding_v1($user),'memberSurface'=>true];
    return '<script>window.VP3_RECORDINGS_V198_CONFIG='.json_encode($cfg,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';window.STONEFELLOW_RECORDINGS_V198_CONFIG=window.VP3_RECORDINGS_V198_CONFIG;</script>'
        .'<link rel="stylesheet" data-chat-transcription-canvas href="'.e(url('/chat-transcription-canvas.css?v='.$build)).'">'
        .'<script data-artist-recordings-v198 src="'.e(url('/artist-listening-recordings.js?v='.$build)).'" defer></script>'
        .'<script data-chat-transcription-canvas src="'.e(url('/chat-transcription-canvas.js?v='.$build)).'" defer></script>';
}
