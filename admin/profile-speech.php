<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_login();require_permission('ai.manage');
if(!function_exists('vp3_profile_speech_config_pa4a'))require_once dirname(__DIR__).'/includes/profile-speech-elevenlabs-pa4a.php';
if(!function_exists('vp3_profile_speech_schema_ready_pa4a'))require_once dirname(__DIR__).'/includes/profile-visitor-speech-pa4a.php';
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$speechError='';$speechNotice='';$catalog=null;$adminId=(int)current_user()['id'];$schemaReady=vp3_profile_speech_schema_ready_pa4a($pdo);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!verify_csrf()){$speechError='Session expired. Refresh and try again.';http_response_code(419);}
    else try{
        $action=$_POST['action']??null;
        if($action==='disable'){vp3_profile_speech_admin_disable_pa4a($pdo);$speechNotice='Profile speech is disabled. Visible visitor messages remain available.';}
        elseif(!$schemaReady){$speechError='Run the Cloud database upgrade before configuring Profile speech.';http_response_code(503);}
        elseif($action==='save'){
            vp3_profile_speech_admin_save_pa4a($pdo,$_POST,null,static function()use($adminId):void{
                if(function_exists('reset_current_user_cache'))reset_current_user_cache();$live=current_user();
                if(!$live||(int)$live['id']!==$adminId||!has_permission('ai.manage',$live))vp3_profile_speech_provider_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);
            });$speechNotice='Profile speech settings saved. Each approved public voice was checked using the current ElevenLabs credential.';
        }elseif($action==='check_catalog'){
            $catalog=vp3_profile_speech_catalog_pa4a();$speechNotice=$catalog['ready']?'Current eligible voices are available. This check did not generate audio or verify a browser speaker.':($catalog['reason']==='catalog_unavailable'?'The provider voice catalog is currently unavailable. No audio was generated; try the metadata check again later.':'No eligible visitor voice is available. Add and validate an accessible public preset below.');
        }else{http_response_code(422);$speechError='Choose a Profile speech settings action.';}
    }catch(VP3ProfileSpeechProviderException|VP3ProfileSpeechException $error){$speechError=$error->getMessage();http_response_code($error->httpStatus);}
    catch(Throwable $error){error_log('Profile speech settings failed ('.get_class($error).').');$speechError='Profile speech settings could not be updated. Existing provider credentials were preserved.';http_response_code(500);}
}
$state=['ready'=>false,'reason'=>'configuration_unavailable','credential_state'=>'unavailable','enabled'=>false,'presets'=>[],'settings_revision'=>'','model_id'=>'','output_format'=>''];
try{$state=vp3_profile_speech_admin_state_pa4a();}catch(Throwable){}
$configurationNotice=match($state['reason']){'model_unsupported'=>'The configured ElevenLabs model ID is invalid. Correct it in AI / API settings; Profile speech has not selected a replacement model.','format_unsupported'=>'The configured ElevenLabs output format is unsupported. Set ELEVENLABS_OUTPUT_FORMAT to mp3_22050_32 or mp3_44100_128 before enabling audio.',default=>''};
$recent=[];$receiptsAvailable=$schemaReady;
if($schemaReady)try{$recent=$pdo->query('SELECT id,owner_user_id,input_characters,provider_character_cost,provider_state,delivery_state,last_reason,created_at_ms FROM profile_speech_attempts_pa4a ORDER BY id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable){$receiptsAvailable=false;}
$adminTitle='Profile speech';$adminActive='profile-speech';require __DIR__.'/_header.php';
?>
<style>
.profile-speech-settings{max-width:1050px;display:grid;gap:20px}.profile-speech-settings section{padding:24px;background:#fff;border:1px solid #dfe3ea;border-radius:16px}.profile-speech-settings h2{margin:0 0 12px}.profile-speech-settings p{line-height:1.6}.profile-speech-settings .notice{padding:16px;border-radius:10px;background:#eaf5ef}.profile-speech-settings .error{background:#fff0ee}.profile-speech-settings label{display:grid;gap:7px}.profile-speech-settings input:not([type=checkbox]){padding:10px;border:1px solid #c5cbd5;border-radius:8px;max-width:100%;width:100%}.profile-speech-settings .check{display:flex;gap:10px;align-items:flex-start;line-height:1.5}.profile-speech-settings .check input{margin-top:5px}.profile-speech-settings button{padding:11px 16px;border-radius:8px;border:1px solid #2e4d70;background:#234f77;color:#fff;cursor:pointer}.profile-speech-settings button:disabled{opacity:.5;cursor:default}.profile-speech-settings .secondary{background:#fff;color:#234f77}.profile-speech-settings .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.profile-speech-settings .preset{display:grid;grid-template-columns:1fr 1.2fr;gap:14px;padding:14px 0;border-bottom:1px solid #e6e9ef}.profile-speech-settings small{color:#53616f}.profile-speech-settings .scroll{overflow:auto}.profile-speech-settings table{width:100%;border-collapse:collapse}.profile-speech-settings th,.profile-speech-settings td{text-align:left;padding:10px;border-bottom:1px solid #e6e9ef}.profile-speech-settings dl{display:grid;grid-template-columns:180px 1fr;gap:10px}.profile-speech-settings dd{margin:0}@media(max-width:620px){.profile-speech-settings section{padding:18px}.profile-speech-settings .preset,.profile-speech-settings dl{grid-template-columns:1fr}.profile-speech-settings dd{margin-bottom:10px}}
</style>
<div class="profile-speech-settings">
<?php if($speechError!==''): ?><div role="alert" class="notice error"><?= e($speechError) ?></div><?php endif; ?>
<?php if($speechNotice!==''): ?><div role="status" class="notice"><?= e($speechNotice) ?></div><?php endif; ?>
<?php if($configurationNotice!==''): ?><div role="status" class="notice error"><?= e($configurationNotice) ?></div><?php endif; ?>
<section><h2>Visitor announcements and message playback</h2>
<p>Profile speech reads authorized visitor arrivals and public visitor messages in the owner’s current Chat. Visitors can choose a voice style or an approved preset. Their voice remains distinct from the owner’s Agent voice.</p>
<dl><dt>Cloud storage</dt><dd><?= $schemaReady?'Ready':'Run /upgrade.php' ?></dd><dt>ElevenLabs credential</dt><dd><?= in_array($state['credential_state'],['saved','environment'],true)?'Available from the existing AI / API settings':'Unavailable — check AI / API settings' ?></dd><dt>Profile speech</dt><dd><?= $configurationNotice!==''?'Unavailable until the configuration is corrected':(!empty($state['enabled'])?'Enabled, subject to each owner’s sound and voice preferences':'Disabled') ?></dd><dt>Current model</dt><dd><?= e($state['model_id']?:'Unavailable') ?></dd></dl>
<p><small>Audio also requires an available owner Chat, permission to use Agent Voice and browser playback consent. Speech stops for capture, hidden or changed Chat context, and accepted human calls. Visible text remains available when audio cannot play.</small></p>
<div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check_catalog"><button <?= $schemaReady?'':'disabled' ?>>Check eligible voices</button></form><a href="<?= e(url('/admin/ai.php')) ?>">Open AI / API settings</a></div>
<?php if(is_array($catalog)&&$catalog['ready']): ?><p><?= count($catalog['voices']) ?> currently eligible voice<?= count($catalog['voices'])===1?'':'s' ?>. Voice eligibility does not guarantee a successful speech request or available account quota.</p><?php endif; ?>
</section>
<section><h2>Approved public visitor presets</h2>
<p>Existing provider default voices are discovered automatically when available. Some ElevenLabs accounts have no default voices. For those accounts, add a public-library voice to the ElevenLabs account, copy its voice ID, then validate it here. The provider’s current public sharing information must confirm that the voice is available.</p>
<p><small>Approve up to eight public voices. This form does not import voices or expose anyone’s private clone. Voice style comes from the provider’s current label; an unclassified voice can still be chosen by its preset name. A voice that retires, changes public origin or becomes inaccessible must be validated again. Changing the provider credential invalidates previous approvals.</small></p>
<form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="settings_revision" value="<?= e($state['settings_revision']) ?>">
<label class="check"><input type="checkbox" name="enabled" value="1" <?= !empty($state['enabled'])?'checked':'' ?>><span>Enable Profile speech when a permitted voice and the current owner’s audio preferences allow it.</span></label>
<?php for($i=0;$i<8;$i++): $preset=$state['presets'][$i]??[]; ?>
<div class="preset"><label>Preset <?= $i+1 ?> name<input name="presets[<?= $i ?>][label]" maxlength="120" value="<?= e($preset['label']??'') ?>" placeholder="Visitor voice"></label><label>Public ElevenLabs voice ID<input name="presets[<?= $i ?>][voice_id]" maxlength="128" value="<?= e($preset['voice_id']??'') ?>" spellcheck="false" placeholder="Paste an accessible public voice ID"><?php if($preset): ?><small><?= e($preset['style']==='unclassified'?'Unclassified preset':ucfirst($preset['style']).' voice style') ?> · <?= !empty($preset['credentials_current'])?'Approved for the saved credential':'Validate again after credential change' ?></small><?php endif; ?></label></div>
<?php endfor; ?>
<div class="actions"><button <?= $schemaReady&&$state['settings_revision']!==''?'':'disabled' ?>>Validate and save presets</button></div></form>
<div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><button class="secondary">Disable Profile speech</button></form></div>
</section>
<section><h2>Recent speech attempts</h2><p>Input characters and provider-reported character cost are different measures. An unreported cost remains unknown. A lost response may have consumed provider quota, so retries require an explicit new attempt. Saved audio expires after ten minutes and is never regenerated automatically.</p>
<?php if(!$receiptsAvailable): ?><p>Speech receipts are currently unavailable.</p><?php elseif(!$recent): ?><p>No speech attempts have been recorded.</p><?php else: ?>
<div class="scroll"><table><thead><tr><th>Attempt</th><th>Owner</th><th>Input characters</th><th>Reported character cost</th><th>Provider outcome</th><th>Playback availability</th></tr></thead><tbody>
<?php foreach($recent as $row): ?><tr><td>#<?= (int)$row['id'] ?></td><td>#<?= (int)$row['owner_user_id'] ?></td><td><?= (int)$row['input_characters'] ?></td><td><?= $row['provider_character_cost']===null?'Unreported':e((string)$row['provider_character_cost']) ?></td><td><?= e((string)$row['provider_state']) ?></td><td><?= e((string)$row['delivery_state']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section></div>
<?php require __DIR__.'/_footer.php'; ?>
