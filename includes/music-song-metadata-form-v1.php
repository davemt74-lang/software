<?php
declare(strict_types=1);

require_once __DIR__.'/music-song-metadata-v1.php';

/** Form helpers never create schema or publish a metadata projection. */
function music_song_metadata_form_text_v1(mixed $value): string
{
    return is_scalar($value) ? (string)$value : '';
}

function music_song_metadata_form_blank_v1(bool $ready = true, array $track = []): array
{
    return [
        'ready'=>$ready,
        'error'=>$ready ? '' : 'Song metadata needs a database upgrade before songs can be saved.',
        'metadata'=>music_song_metadata_v1_defaults($track),
        'metadata_revision'=>0,
        'recording_id'=>'',
        'archived'=>false,
        'readiness'=>[],
        'parties'=>[],
        'works'=>[],
        'request_key'=>bin2hex(random_bytes(16)),
    ];
}

function music_song_metadata_form_state_v1(PDO $pdo, int $workspaceId, int $catalogId, array $user, array $track = [], ?array $submitted = null): array
{
    if (!music_workspace_resources_v330_can_manage($pdo, $workspaceId, 'tracks', $user)) {
        throw new RuntimeException('Song editing is not available to your workspace role.');
    }
    $state=music_song_metadata_form_blank_v1(music_song_metadata_v1_schema_ready($pdo), $track);
    if ($state['ready']) {
        try {
            $recording=$catalogId>0 ? music_song_metadata_v1_get($pdo,$workspaceId,$catalogId,$user) : null;
            if ($recording) {
                $state['metadata']=$recording['metadata'];
                $state['metadata']['work_revision']=(int)($recording['work_revision']??0);
                $state['metadata_revision']=(int)$recording['revision'];
                $state['recording_id']=(string)$recording['recording_id'];
                $state['archived']=!empty($recording['archived']);
                $state['readiness']=$recording['readiness']??[];
            }
            $state['parties']=music_song_metadata_v1_parties($pdo,$workspaceId,$user);
            $state['works']=music_song_metadata_v1_works($pdo,$workspaceId,$user);
        } catch (Throwable $e) {
            $state['ready']=false;
            $state['error']='Song details could not be loaded. Reload this page before saving.';
        }
    }
    if ($submitted!==null) {
        // Keep the submitted revisions on failure. Refreshing them silently would
        // turn a stale edit into an overwrite on the next attempt.
        if (is_array($submitted['music_metadata']??null)) {
            // A removed final contributor has no HTML field in the POST. Start
            // from the empty form contract so an error cannot restore that row.
            $state['metadata']=array_replace(music_song_metadata_v1_defaults($track),$submitted['music_metadata']);
        }
        $state['metadata_revision']=music_song_metadata_form_text_v1($submitted['metadata_revision']??0);
        $state['request_key']=music_song_metadata_form_text_v1($submitted['request_key']??$state['request_key']);
    }
    return $state;
}

function music_song_metadata_form_party_v1(string $group, string $index, array $row, array $parties): void
{
    $base='music_metadata['.$group.']['.$index.']';
    $partyId=music_song_metadata_form_text_v1($row['party_id']??'');
    $found=false;
    ?>
    <div class="song-meta-party" data-party-row data-party-group="<?= e($group) ?>">
      <label class="song-meta-wide"><span>Reuse a workspace contributor</span>
        <select name="<?= e($base) ?>[party_id]" data-party-select>
          <option value="">Enter a new contributor</option>
          <?php foreach ($parties as $party): $id=music_song_metadata_form_text_v1($party['party_id']??''); $selected=$id!==''&&$id===$partyId; $found=$found||$selected; ?>
          <option value="<?= e($id) ?>" <?= $selected?'selected':'' ?>><?= e(music_song_metadata_form_text_v1($party['name']??'')) ?></option>
          <?php endforeach; ?>
          <?php if ($partyId!==''&&!$found): ?><option value="<?= e($partyId) ?>" selected>Saved contributor: <?= e(music_song_metadata_form_text_v1($row['name']??'')) ?></option><?php endif; ?>
        </select>
      </label>
      <label><span>Name</span><input name="<?= e($base) ?>[name]" value="<?= e(music_song_metadata_form_text_v1($row['name']??'')) ?>" maxlength="190" data-party-field="name" <?= $partyId!==''?'readonly':'' ?>></label>
      <label><span>Credit role</span><input name="<?= e($base) ?>[role]" value="<?= e(music_song_metadata_form_text_v1($row['role']??'')) ?>" maxlength="100" placeholder="<?= $group==='writers'?'Songwriter / composer':($group==='publishers'?'Publisher / administrator':'Vocals / guitar / producer') ?>" data-party-field="role"></label>
      <label><span>IPI / CAE, if known</span><input name="<?= e($base) ?>[ipi]" value="<?= e(music_song_metadata_form_text_v1($row['ipi']??'')) ?>" maxlength="32" data-party-field="ipi" <?= $partyId!==''?'readonly':'' ?>></label>
      <label><span>PRO / collecting society, if known</span><input name="<?= e($base) ?>[pro]" value="<?= e(music_song_metadata_form_text_v1($row['pro']??'')) ?>" maxlength="120" data-party-field="pro" <?= $partyId!==''?'readonly':'' ?>></label>
      <div class="song-meta-wide song-meta-row-actions"><small data-party-reuse-note <?= $partyId===''?'hidden':'' ?>>Reusing saved details. Choose “Enter a new contributor” to describe a different person or company.</small><button type="button" class="song-meta-button" data-party-remove>Remove contributor</button></div>
    </div>
    <?php
}

/** Render only inside an authenticated, authorized song-management form. */
function music_song_metadata_form_v1(array $state, string $prefix = 'song'): void
{
    $m=is_array($state['metadata']??null)?$state['metadata']:[];
    $parties=is_array($state['parties']??null)?$state['parties']:[];
    $works=is_array($state['works']??null)?$state['works']:[];
    $text=static fn(string $key):string=>e(music_song_metadata_form_text_v1($m[$key]??''));
    $workId=music_song_metadata_form_text_v1($m['work_id']??'');
    $label=is_array($m['label']??null)?$m['label']:[];
    $labelId=music_song_metadata_form_text_v1($label['party_id']??'');
    $statuses=['unknown'=>'Unknown','unassigned'=>'Not assigned','provided'=>'Provided — format checked on save'];
    $defaults=json_encode(['parties'=>$parties,'works'=>$works],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
    ?>
    <section class="song-metadata-form wide" data-song-metadata data-schema-ready="<?= !empty($state['ready'])?'1':'0' ?>" aria-labelledby="<?= e($prefix) ?>-metadata-heading">
      <input type="hidden" name="metadata_revision" value="<?= e(music_song_metadata_form_text_v1($state['metadata_revision']??0)) ?>">
      <input type="hidden" name="request_key" value="<?= e(music_song_metadata_form_text_v1($state['request_key']??'')) ?>" data-song-request-key>
      <input type="hidden" name="music_metadata[work_revision]" value="<?= $text('work_revision') ?>" data-work-revision>
      <header class="song-meta-heading"><h3 id="<?= e($prefix) ?>-metadata-heading">Song metadata &amp; rights</h3><p>Save what you know now. Credits describe the song; payment agreements are configured separately.</p></header>
      <?php if (!empty($state['error'])): ?><div class="song-meta-notice" role="alert"><?= e((string)$state['error']) ?> <?php if (empty($state['ready'])): ?><a href="<?= e(url('/upgrade.php')) ?>">Database upgrade</a><?php endif; ?></div><?php endif; ?>
      <p class="song-meta-notice" data-metadata-load-status role="status" hidden></p>
      <button class="song-meta-button" type="button" data-metadata-retry hidden>Retry loading workspace details</button>
      <fieldset class="song-meta-section"><legend>Recording &amp; identifiers</legend><div class="song-meta-grid">
        <label><span>Recording / version label</span><input name="music_metadata[recording_version]" maxlength="120" value="<?= $text('recording_version') ?>" placeholder="Original, live, acoustic…"></label>
        <label><span>Language, if known</span><input name="music_metadata[language]" maxlength="40" value="<?= $text('language') ?>" placeholder="English, instrumental…"></label>
        <label><span>ISRC</span><input name="music_metadata[isrc]" maxlength="20" value="<?= $text('isrc') ?>" placeholder="Leave blank if unknown" autocomplete="off" data-identifier="isrc"></label>
        <label><span>ISRC status</span><select name="music_metadata[isrc_status]" data-identifier-status="isrc"><?php foreach($statuses as $value=>$caption): ?><option value="<?= e($value) ?>" <?= (string)($m['isrc_status']??'unknown')===$value?'selected':'' ?>><?= e($caption) ?></option><?php endforeach; ?></select></label>
        <label><span>Explicit content</span><select name="music_metadata[explicit_content]"><?php foreach(['unknown'=>'Unknown','no'=>'No','yes'=>'Yes'] as $value=>$caption): ?><option value="<?= e($value) ?>" <?= (string)($m['explicit_content']??'unknown')===$value?'selected':'' ?>><?= e($caption) ?></option><?php endforeach; ?></select></label>
        <p class="song-meta-help song-meta-wide">An ISRC describes a recording; an ISWC describes the written work. Saving a valid format does not register an identifier or confirm ownership.</p>
      </div></fieldset>
      <fieldset class="song-meta-section"><legend>Writers &amp; composition</legend><div class="song-meta-grid">
        <label class="song-meta-wide"><span>Composition / written work</span><select name="music_metadata[work_id]" data-work-select><option value=""><?= $workId!==''?'Keep this work; edit its details below':'Create a work for this song' ?></option><?php $found=false; foreach($works as $work): $id=music_song_metadata_form_text_v1($work['work_id']??'');$selected=$id!==''&&$id===$workId;$found=$found||$selected; ?><option value="<?= e($id) ?>" <?= $selected?'selected':'' ?>><?= e(music_song_metadata_form_text_v1($work['work_title']??'Untitled work')) ?></option><?php endforeach; ?><?php if($workId!==''&&!$found): ?><option value="<?= e($workId) ?>" selected>Current work: <?= $text('work_title') ?></option><?php endif; ?></select></label>
        <label class="song-meta-wide"><span>Work title</span><input name="music_metadata[work_title]" maxlength="190" value="<?= $text('work_title') ?>" data-work-field <?= $workId!==''?'readonly':'' ?>></label>
        <label><span>ISWC, if known</span><input name="music_metadata[iswc]" maxlength="24" value="<?= $text('iswc') ?>" data-work-field data-identifier="iswc" <?= $workId!==''?'readonly':'' ?>></label>
        <label><span>ISWC status</span><select name="music_metadata[iswc_status]" data-work-status data-identifier-status="iswc"><?php foreach($statuses as $value=>$caption): ?><option value="<?= e($value) ?>" <?= (string)($m['iswc_status']??'unknown')===$value?'selected':'' ?>><?= e($caption) ?></option><?php endforeach; ?></select></label>
        <p class="song-meta-help song-meta-wide" data-work-reuse-note <?= $workId===''?'hidden':'' ?>>Reusing this work's title, identifiers, writers and publishers. Shared work changes need a separate review.</p>
      </div>
      <?php foreach(['writers'=>'Writer','publishers'=>'Publisher'] as $group=>$caption): $rows=is_array($m[$group]??null)?array_values($m[$group]):[]; ?>
        <div class="song-meta-party-group" data-party-group-container="<?= e($group) ?>"><h4><?= e($caption) ?>s</h4><div data-party-list="<?= e($group) ?>"><?php foreach($rows as $index=>$row): if(is_array($row))music_song_metadata_form_party_v1($group,(string)$index,$row,$parties); endforeach; ?></div><button type="button" class="song-meta-button" data-party-add="<?= e($group) ?>">+ Add <?= strtolower(e($caption)) ?></button></div>
      <?php endforeach; ?>
      </fieldset>
      <fieldset class="song-meta-section"><legend>Performers &amp; record company</legend>
        <div class="song-meta-party-group" data-party-group-container="performers"><div data-party-list="performers"><?php foreach(is_array($m['performers']??null)?array_values($m['performers']):[] as $index=>$row): if(is_array($row))music_song_metadata_form_party_v1('performers',(string)$index,$row,$parties); endforeach; ?></div><button type="button" class="song-meta-button" data-party-add="performers">+ Add performer or production credit</button></div>
        <div class="song-meta-grid song-meta-label"><label><span>Reuse a label / company</span><select name="music_metadata[label][party_id]" data-label-select><option value="">Enter a label / company</option><?php $found=false; foreach($parties as $party): $id=music_song_metadata_form_text_v1($party['party_id']??'');$selected=$id!==''&&$id===$labelId;$found=$found||$selected; ?><option value="<?= e($id) ?>" <?= $selected?'selected':'' ?>><?= e(music_song_metadata_form_text_v1($party['name']??'')) ?></option><?php endforeach; ?><?php if($labelId!==''&&!$found): ?><option value="<?= e($labelId) ?>" selected><?= e(music_song_metadata_form_text_v1($label['name']??'Saved company')) ?></option><?php endif; ?></select></label>
        <label><span>Label / record company</span><input name="music_metadata[label][name]" maxlength="190" value="<?= e(music_song_metadata_form_text_v1($label['name']??'')) ?>" data-label-name <?= $labelId!==''?'readonly':'' ?> placeholder="Independent / self-released, or company name"></label>
        <label><span>Composition copyright notice</span><input name="music_metadata[copyright_notice]" maxlength="500" value="<?= $text('copyright_notice') ?>"></label>
        <label><span>Recording copyright notice (℗)</span><input name="music_metadata[phonogram_notice]" maxlength="500" value="<?= $text('phonogram_notice') ?>"></label></div>
      </fieldset>
      <details class="song-meta-section song-meta-private"><summary>Private rights notes &amp; contact</summary><div class="song-meta-grid"><label class="song-meta-wide"><span>Private rights notes</span><textarea name="music_metadata[rights_notes]" rows="3" maxlength="10000"><?= $text('rights_notes') ?></textarea></label><label><span>Private contact email</span><input type="email" name="music_metadata[contact_email]" maxlength="254" value="<?= $text('contact_email') ?>" autocomplete="off"></label><p class="song-meta-help song-meta-wide">These details stay in the authorized workspace editor. They are excluded from public credits and visitor responses.</p></div></details>
      <section class="song-meta-preview" aria-label="Public credit preview"><h4>Public credit preview</h4><p class="song-meta-help">Preview of credit names and roles from this form. Saving a draft does not publish it.</p><ul data-credit-preview></ul><p data-credit-empty>No public credit names entered yet.</p></section>
      <section class="song-meta-readiness" aria-label="Saved song readiness"><h4><?= !empty($state['recording_id'])?'Last saved readiness':'Readiness' ?></h4><p class="song-meta-help">Saving rechecks the recording. Unsaved changes are not a readiness decision.</p><div class="song-meta-readiness-grid">
      <?php $readiness=is_array($state['readiness']??null)?$state['readiness']:[];
      foreach(['catalog'=>['Catalog','Save song details to establish its catalog record.'],'playback'=>['Playback','An audio file, publication and audience permission are required.'],'earnings'=>['Earnings','Not configured. Saving credits does not create a rate or earnings.'],'payout'=>['Payout','Not configured. This form does not set payment destinations.']] as $key=>$copy): $item=is_array($readiness[$key]??null)?$readiness[$key]:[];$reasons=is_array($item['reasons']??null)?$item['reasons']:[];$fallback=!empty($item['ready'])?(['catalog'=>'Saved catalog identity is ready.','playback'=>'Listening audio is ready for its selected audience.'][$key]??$copy[1]):$copy[1]; ?>
        <article><strong><?= e($copy[0]) ?></strong><span><?= e(isset($item['state'])?ucfirst(str_replace('_',' ',(string)$item['state'])):($key==='earnings'||$key==='payout'?'Not configured':'Draft')) ?></span><p><?= e($reasons?implode(' ',array_map('music_song_metadata_form_text_v1',$reasons)):$fallback) ?></p></article>
      <?php endforeach; ?></div></section>
      <?php foreach(['writers','publishers','performers'] as $group): ?><template data-party-template="<?= e($group) ?>"><?php music_song_metadata_form_party_v1($group,'__INDEX__',[],$parties); ?></template><?php endforeach; ?>
      <script type="application/json" data-music-defaults><?= $defaults?:'{"parties":[],"works":[]}' ?></script>
      <noscript><p class="song-meta-notice">Enable JavaScript to add contributors or choose reusable work details. Existing values can still be saved.</p></noscript>
    </section>
    <?php
}

/** The workspace and legacy artist page share the same song editor contract. */
function music_song_catalog_editor_v1(array $options): void
{
    $kind=$options['kind']==='album'?'album':'track';
    $values=is_array($options['values']??null)?$options['values']:[];
    $editing=is_array($options['editing']??null)?$options['editing']:null;
    $id=(int)($editing['id']??0);
    $workspaceId=(int)$options['workspace_id'];
    $state=$options['state']??null;
    $ready=$kind==='album'||!empty($state['ready']);
    $error=(string)($options['error']??'');
    $prefix=(string)($options['prefix']??'song-editor');
    $value=static fn(string $key,mixed $fallback=''):string=>e(music_song_metadata_form_text_v1($values[$key]??$fallback));
    $revision=music_song_metadata_form_text_v1($options['revision']??'');
    if ($kind==='track'&&!empty($state['archived'])) {
        ?>
        <section class="song-archive-banner" id="<?= e($prefix) ?>"><h3><?= e((string)($editing['title']??'Archived song')) ?> is archived</h3><p>Its identity and listening history are preserved. Restore it as an unpublished draft before editing or publishing it again.</p>
          <?php if($error!==''): ?><div class="song-editor-error" role="alert" tabindex="-1" data-song-error-summary><?= e($error) ?></div><?php endif; ?>
          <form method="post" action="<?= e((string)$options['action_url']) ?>"><?= csrf_field() ?><input type="hidden" name="workspace_id" value="<?= $workspaceId ?>"><input type="hidden" name="kind" value="track"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="metadata_revision" value="<?= e(music_song_metadata_form_text_v1($state['metadata_revision'])) ?>"><input type="hidden" name="request_key" value="<?= e((string)$state['request_key']) ?>"><input type="hidden" name="view_archived" value="1"><div class="song-editor-actions"><button class="ml-btn btn primary" name="action" value="restore" <?= !$ready?'disabled':'' ?>>Restore draft</button><a class="ml-btn btn" href="<?= e((string)$options['cancel_url']) ?>">Back to library</a></div></form>
        </section>
        <?php
        return;
    }
    ?>
    <form id="<?= e($prefix) ?>" method="post" action="<?= e((string)$options['action_url']) ?>" enctype="multipart/form-data" class="song-catalog-editor" data-song-catalog-editor>
      <?= csrf_field() ?><input type="hidden" name="workspace_id" value="<?= $workspaceId ?>"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= $id ?>">
      <?php if($editing): ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><?php endif; ?>
      <?php if($kind==='album'): ?><input type="hidden" name="request_key" value="<?= e(music_song_metadata_form_text_v1($values['request_key']??bin2hex(random_bytes(16)))) ?>"><?php endif; ?>
      <header class="wide song-meta-heading"><h2><?= $editing?'Edit':'New' ?> <?= $kind==='track'?'song':'album' ?></h2><p><?= $kind==='track'?'A draft can be saved before its audio and rights details are complete.':'Organize the recordings and artwork in this workspace.' ?></p></header>
      <?php if($error!==''): ?><div class="wide song-editor-error" role="alert" tabindex="-1" data-song-error-summary><strong>Changes were not saved.</strong><p><?= e($error) ?></p><?php if(!empty($options['reselect_files'])): ?><p>Your text and selections are kept below. Choose the audio file again before retrying an upload.</p><?php endif; ?><?php if($editing): ?><a href="<?= e((string)$options['reload_url']) ?>" target="_blank" rel="noopener">Review the latest saved version in a new tab</a><?php endif; ?></div><?php endif; ?>
      <?php if(!$ready): ?><p class="wide song-meta-notice" role="alert"><?= e((string)($state['error']??'Song metadata needs a database upgrade before songs can be saved.')) ?> <a href="<?= e(url('/upgrade.php')) ?>">Database upgrade</a></p><?php endif; ?>
      <fieldset class="song-editor-fieldset" <?= !$ready?'disabled':'' ?>>
      <label><span>Title</span><input name="title" required maxlength="190" value="<?= $value('title') ?>"></label>
      <?php if($kind==='track'): ?>
      <label><span>Album</span><select name="album_id"><option value="0">Single / unassigned</option><?php foreach($options['albums'] as $album): ?><option value="<?= (int)$album['id'] ?>" <?= music_song_metadata_form_text_v1($values['album_id']??0)===(string)$album['id']?'selected':'' ?>><?= e((string)$album['title']) ?></option><?php endforeach; ?></select></label>
      <label><span>Track number</span><input type="number" min="0" step="1" name="track_number" value="<?= $value('track_number',0) ?>"></label>
      <label><span>Duration in seconds</span><input type="number" min="0" step="1" name="duration_seconds" value="<?= $value('duration_seconds',0) ?>"><small class="song-editor-help">Leave at 0 if unknown.</small></label>
      <label><span>Genre</span><input name="genre" maxlength="120" value="<?= $value('genre') ?>"></label>
      <label><span><?= $editing?'Replace audio (optional)':'Audio file (optional for a draft)' ?></span><input type="file" name="audio_file" accept=".mp3,.m4a,.wav,.ogg,audio/mpeg,audio/mp4,audio/wav,audio/ogg"><small class="song-editor-help">MP3, M4A, WAV or OGG. <?= $editing?'Leave blank to keep the current file.':'Add audio before publishing.' ?></small></label>
      <?php else: ?>
      <label><span>Release date</span><input type="date" name="release_date" value="<?= $value('release_date') ?>"></label><label><span>Sort order</span><input type="number" name="sort_order" value="<?= $value('sort_order',0) ?>"></label>
      <?php endif; ?>
      <label class="wide"><span>Description</span><textarea name="description" rows="4"><?= $value('description') ?></textarea></label>
      <label class="wide"><span>Cover artwork</span><select name="cover_photo_id"><option value="0">No cover / inherit album artwork</option><?php foreach($options['photos'] as $photo): ?><option value="<?= (int)$photo['id'] ?>" <?= music_song_metadata_form_text_v1($values['cover_photo_id']??0)===(string)$photo['id']?'selected':'' ?>><?= e((string)$photo['title']) ?></option><?php endforeach; ?></select><?php if(!empty($options['can_media'])): ?><a href="<?= e(url('/admin/artist-media.php?workspace='.$workspaceId)) ?>" target="_blank" rel="noopener">Upload or manage artwork in a new tab</a><?php endif; ?></label>
      <label><span>Audience when published</span><select name="visibility"><?php foreach(visibility_options() as $key=>$caption): ?><option value="<?= e($key) ?>" <?= music_song_metadata_form_text_v1($values['visibility']??'members')===$key?'selected':'' ?>><?= e($caption) ?></option><?php endforeach; ?></select></label>
      <label class="song-editor-publish"><span><input type="checkbox" name="is_published" value="1" <?= !empty($values['is_published'])?'checked':'' ?>> Publish <?= $kind==='track'?'song':'album' ?></span><small class="song-editor-help">Leave unchecked to save a private draft.</small></label>
      <?php if($kind==='track')music_song_metadata_form_v1($state,$prefix.'-metadata'); ?>
      <div class="wide song-editor-actions"><button class="ml-btn btn primary" type="submit" name="action" value="save" data-song-save <?= !$ready?'disabled':'' ?>>Save <?= $kind==='track'?'song':'album' ?></button><?php if($kind==='track'): ?><button class="ml-btn btn" type="submit" name="after_save" value="reaper" data-song-save <?= !$ready?'disabled':'' ?>>Save &amp; add REAPER project</button><?php endif; ?><a class="ml-btn btn" href="<?= e((string)$options['cancel_url']) ?>">Cancel</a><?php if($editing&&$kind==='track'): ?><button class="ml-btn btn song-archive-action" type="submit" name="action" value="delete" formnovalidate data-song-archive>Archive song</button><?php elseif($editing&&!empty($options['allow_album_delete'])): ?><button class="ml-btn btn song-archive-action" type="submit" name="action" value="delete" formnovalidate onclick="return confirm('Delete this album? Its tracks will be retained.')">Delete album</button><?php endif; ?></div>
      </fieldset>
    </form>
    <?php
}
