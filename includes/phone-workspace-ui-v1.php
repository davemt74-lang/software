<?php
declare(strict_types=1);

/** The member phone is exposed only after an administrator assigns a number. */
function vp3_phone_workspace_button_v1(array $user): string
{
    $pdo=db();
    if(!$pdo instanceof PDO||!vp3_phone_workspace_available_v1($pdo,$user))return '';
    return '<button type="button" class="chat-icon-button vp3-phone-header-button" data-phone-workspace-toggle aria-label="Phone" aria-expanded="false" aria-controls="vp3PhoneWorkspace">'
        .'<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-5-2-2 2a13 13 0 0 1-7-7l2-2-2-5Z"/></svg>'
        .'<span data-phone-workspace-badge hidden></span></button>';
}

function vp3_phone_workspace_runtime_v1(array $user,int $agentId=0,int $conversationId=0): string
{
    if(!empty($GLOBALS['VP3_PHONE_WORKSPACE_RUNTIME_V1']))return '';
    $pdo=db();
    if(!$pdo instanceof PDO||!vp3_phone_workspace_available_v1($pdo,$user))return '';
    $GLOBALS['VP3_PHONE_WORKSPACE_RUNTIME_V1']=true;
    $root=dirname(__DIR__);
    $build=substr(hash('sha256',hash_file('sha256',$root.'/phone-workspace-v1.js').hash_file('sha256',$root.'/phone-workspace-v1.css').hash_file('sha256',$root.'/phone-operator-capture-v1.js').hash_file('sha256',$root.'/phone-voicemail-capture-v1.js')),0,12);
    $config=['endpoint'=>url('/api/phone-workspace-v1.php'),'urlbase'=>url('/'),'csrf'=>csrf_token(),
        'operator_live_endpoint'=>url('/api/phone-operator-live-v1.php'),'operator_capture_url'=>url('/phone-operator-capture-v1.js?v='.$build),
        'build'=>$build,'owner_id'=>(int)$user['id'],'agent_id'=>max(0,$agentId),'conversation_id'=>max(0,$conversationId),
        'greeting_endpoint'=>url('/api/phone-voicemail-greeting-v1.php'),'greeting_capture_url'=>url('/phone-voicemail-capture-v1.js?v='.$build),
        'sdk_url'=>url('/assets/twilio-voice-2.18.5.min.js'),
        'sdk_integrity'=>'sha384-LUG7DmzDPWNKRYoqyGpV4d/IByMLiya/oUhMSc/4/6hi0wbzO/8PffM4lzeFEYS8'];
    return '<link rel="stylesheet" data-phone-workspace-v1 href="'.e(url('/phone-workspace-v1.css?v='.$build)).'">'
        .'<script>window.VP3_PHONE_WORKSPACE_V1='.json_encode($config,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
        .'<script data-phone-workspace-v1 src="'.e(url('/phone-workspace-v1.js?v='.$build)).'" defer></script>';
}
