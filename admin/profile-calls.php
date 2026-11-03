<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_login();require_permission('users.manage');
require_once dirname(__DIR__).'/includes/profile-calls-livekit-pa3c.php';
if(!function_exists('vp3_profile_call_configuration_changed_pa3c'))require_once dirname(__DIR__).'/includes/profile-calls-pa3c.php';
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$error='';$callSettingsNotice='';$schemaReady=vp3_profile_call_schema_ready_pa3c($pdo);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!verify_csrf()){$error='Session expired. Refresh and try again.';http_response_code(419);}
    else try{
        $action=$_POST['action']??null;
        if($action==='disable'){
            vp3_profile_call_livekit_disable_pa3c($pdo);$callSettingsNotice='New calls are disabled. Existing call cleanup remains queued for its original project.';
        }elseif(!$schemaReady){$error='Run the Cloud database upgrade before configuring Profile calls.';http_response_code(503);}
        elseif($action==='save'){
            vp3_profile_call_livekit_save_pa3c($pdo,$_POST);$callSettingsNotice='Profile call settings saved. Check the readiness steps below before inviting a visitor.';
        }elseif($action==='verify_api'){
            if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            vp3_profile_call_livekit_verify_pa3c($pdo);$callSettingsNotice='The authenticated RoomService metadata check passed. Verify browser media and the project defaults separately.';
        }else{$error='Choose a Profile call settings action.';http_response_code(422);}
    }catch(VP3ProfileCallProviderException|VP3ProfileCallException $e){$error=$e->getMessage();http_response_code($e->httpStatus);}
    catch(Throwable $e){error_log('Profile call settings failed ('.get_class($e).').');$error='The calling settings could not be updated. Check server readiness and try again.';http_response_code(500);}
}
$readiness=vp3_profile_call_livekit_readiness_pa3c();$settings=[];$saved=null;
try{$settings=vp3_profile_call_livekit_settings_pa3c();$saved=vp3_profile_call_livekit_config_pa3c($settings);}catch(Throwable){}
$source=($settings['profile_calls_source_pa3c']??'profile')==='meetings'?'meetings':'profile';
$reason=match($readiness['reason']??'configuration_missing'){
    'ready'=>'Ready for an explicit browser call',
    'disabled'=>'Calling is disabled',
    'human_only_unacknowledged'=>'Confirm the project defaults for Profile calls',
    'api_unverified'=>'Run the metadata API check',
    'credential_unavailable'=>'Restore the original encryption key before proceeding',
    'transport_unavailable'=>'The server needs PHP cURL with HTTPS support',
    default=>'Configure a supported LiveKit Cloud project',
};
$adminTitle='Profile voice & video';$adminActive='profile-calls';require __DIR__.'/_header.php';
?>
<style>
.profile-call-settings{max-width:1000px;display:grid;gap:20px}.profile-call-settings section{padding:24px;border:1px solid #dfe3ea;border-radius:16px;background:#fff}.profile-call-settings h2{margin:0 0 12px}.profile-call-settings p{line-height:1.6}.profile-call-settings .fields{display:grid;gap:16px}.profile-call-settings label{display:grid;gap:7px}.profile-call-settings input:not([type=checkbox]),.profile-call-settings select{width:100%;padding:11px;border:1px solid #c5cbd5;border-radius:8px}.profile-call-settings .check{display:flex;align-items:flex-start;gap:10px;line-height:1.5}.profile-call-settings .check input{margin-top:5px}.profile-call-settings button{padding:11px 16px;border-radius:8px;border:1px solid #2e4d70;background:#234f77;color:#fff;cursor:pointer}.profile-call-settings button:disabled{opacity:.5;cursor:default}.profile-call-settings .secondary{background:#fff;color:#234f77}.profile-call-settings .notice{padding:16px;border-radius:10px;background:#eaf5ef}.profile-call-settings .error{background:#fff0ee}.profile-call-settings dl{display:grid;grid-template-columns:minmax(130px,1fr) 2fr;gap:12px}.profile-call-settings dt{font-weight:600}.profile-call-settings dd{margin:0}.profile-call-settings small{color:#53616f}.profile-call-settings .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}@media(max-width:600px){.profile-call-settings section{padding:18px}.profile-call-settings dl{grid-template-columns:1fr;gap:6px}.profile-call-settings dd{margin-bottom:10px}}
</style>
<div class="profile-call-settings">
<?php if($error!==''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($callSettingsNotice!==''): ?><div class="notice" role="status"><?= e($callSettingsNotice) ?></div><?php endif; ?>
<section aria-labelledby="profile-calls-readiness"><h2 id="profile-calls-readiness"><?= e($reason) ?></h2>
<p>Visitors and profile owners can speak directly in their browsers, with optional video. Each person joins explicitly and controls their own microphone and camera.</p>
<dl>
<dt>Cloud storage</dt><dd><?= $schemaReady?'Ready':'Run /upgrade.php' ?></dd>
<dt>Project credentials</dt><dd><?= !empty($readiness['configured'])?'Saved and readable':'Not configured or unavailable' ?></dd>
<dt>Project defaults</dt><dd><?= !empty($readiness['human_only_acknowledged'])?'Acknowledged by an administrator':'Administrator acknowledgement required' ?></dd>
<dt>Metadata API</dt><dd><?= (int)($readiness['api_verified_at_ms']??0)>0?'Last check passed at '.e(gmdate('Y-m-d H:i:s \U\T\C',intdiv((int)$readiness['api_verified_at_ms'],1000))):'Not checked for these credentials' ?></dd>
<dt>Browser media</dt><dd>Check microphone, speaker, camera and network access with two consenting people after setup.</dd>
</dl>
<p><small>The metadata check verifies an authenticated API response. It does not verify Agent deployment rules, recording defaults or microphone/video connectivity. LiveKit Cloud is required for immediate token revocation; self-hosted meeting servers are not supported by this calling feature.</small></p>
</section>
<section aria-labelledby="profile-calls-config"><h2 id="profile-calls-config">Calling project</h2>
<form method="post" autocomplete="off">
<?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="fields">
<label>Credential source<select name="source"><option value="profile" <?= $source==='profile'?'selected':'' ?>>Dedicated Profile call credentials</option><option value="meetings" <?= $source==='meetings'?'selected':'' ?>>Explicitly use the existing LiveKit meeting configuration</option></select></label>
<p><small>The existing meeting configuration must point to a supported Cloud project with the defaults below. For dedicated credentials, leave saved fields blank to keep them. A different project URL or key requires the matching secret.</small></p>
<label>LiveKit Cloud URL<input name="url" maxlength="250" placeholder="wss://your-project.livekit.cloud" value="<?= e($source==='profile'&&$saved?$saved['url']:'') ?>" spellcheck="false"></label>
<label>API key<input type="password" name="api_key" maxlength="240" autocomplete="new-password" value="" placeholder="<?= $source==='profile'&&$saved?'Saved key — leave blank to keep':'Enter the project API key' ?>"></label>
<label>API secret<input type="password" name="api_secret" maxlength="240" autocomplete="new-password" value="" placeholder="<?= $source==='profile'&&$saved?'Saved secret — leave blank to keep':'Enter the matching API secret' ?>"></label>
<label class="check"><input type="checkbox" name="human_only_acknowledged" value="1" <?= !empty($readiness['human_only_acknowledged'])?'checked':'' ?>><span>I checked this LiveKit Cloud project and confirmed that automatic Agent dispatch, recording and transcription are disabled for Profile rooms. Disclosed voice-call assistance uses a separately configured, named worker.</span></label>
<label class="check"><input type="checkbox" name="enabled" value="1" <?= !empty($readiness['enabled'])?'checked':'' ?>><span>Enable Profile voice and video when the readiness checks pass.</span></label>
</div>
<div class="actions"><button <?= $schemaReady?'':'disabled' ?>>Save calling settings</button></div>
</form>
<p><small>Credentials and each call’s original project snapshot are encrypted on the server. Back up the existing encryption key. Changing or disabling the configuration closes ongoing calls through their original project; pending cleanup continues until revocation and room closure are verified.</small></p>
</section>
<section aria-labelledby="profile-calls-actions"><h2 id="profile-calls-actions">Check or disable</h2>
<p>The API check makes a read-only metadata request. It does not create a room or start a call.</p>
<div class="actions">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify_api"><button <?= $schemaReady&&!empty($readiness['configured'])?'':'disabled' ?>>Check metadata API</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><button class="secondary">Disable new calls</button></form>
</div>
</section>
</div>
<?php require __DIR__.'/_footer.php'; ?>
