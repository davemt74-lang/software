<?php
declare(strict_types=1);
require __DIR__.'/../includes/speaker-attribution-v1.php';

function check9(bool $ok,string $message): void {if(!$ok)throw new LogicException($message);}
function pass9(string $name): void {echo "PASS {$name}\n";}

$f=vp3_speaker_fuse_v1([['source'=>'heuristic_acoustic','speaker_label'=>'Speaker 2','confidence'=>.93,'participant_id'=>99]]);
check9($f['source']==='heuristic_acoustic'&&$f['participant_id']===0&&!$f['speaker_identity_verified'],'heuristic claimed identity');
pass9('acoustic heuristic can label a turn but cannot identify a person');

$f=vp3_speaker_fuse_v1([['source'=>'provider_diarization','speaker_label'=>'Speaker 3','confidence'=>.91,'provider_speaker_id'=>'secret-provider-id']]);
check9($f['diarization_source']==='provider_diarization'&&!$f['speaker_identity_verified'],'diarization claimed identity');
check9(!str_contains(json_encode($f),'secret-provider-id'),'raw provider id leaked');
pass9('provider diarization separates speakers without inventing identity or leaking provider IDs');

$f=vp3_speaker_fuse_v1([
 ['source'=>'verified_voice','speaker_label'=>'Dave','participant_id'=>7,'confidence'=>.91,'provider_speaker_id'=>'voice-7'],
 ['source'=>'visual_corroboration','participant_id'=>7,'confidence'=>.88],
]);
check9($f['participant_id']===7&&$f['speaker_identity_verified']&&$f['visual_corroborated'],'verified voice fusion failed');
check9($f['identity_confidence']>.91&&!$f['authentication_authority'],'corroboration boundary failed');
pass9('visual evidence only corroborates a verified speaker identity');

$f=vp3_speaker_fuse_v1([['source'=>'visual_corroboration','speaker_label'=>'Dave','participant_id'=>7,'confidence'=>.99]]);
check9($f['participant_id']===0&&!$f['speaker_identity_verified']&&$f['source']==='unknown','visual-only identity accepted');
pass9('camera evidence alone cannot name the speaker');

$f=vp3_speaker_fuse_v1([
 ['source'=>'verified_voice','speaker_label'=>'Speaker 1','participant_identity'=>'tracky:owner-1','confidence'=>.94],
 ['source'=>'visual_corroboration','participant_identity'=>'tracky:owner-1','confidence'=>.91],
]);
check9($f['participant_identity']==='tracky:owner-1'&&$f['visual_corroborated']&&$f['speaker_identity_verified'],'opaque local voice/visual fusion failed');
pass9('opaque local participant references can be corroborated without visual-only identity');

$f=vp3_speaker_fuse_v1([
 ['source'=>'verified_voice','speaker_label'=>'Speaker 1','participant_identity'=>'tracky:owner-1','confidence'=>.96],
 ['source'=>'visual_corroboration','participant_identity'=>'tracky:guest-2','confidence'=>.92],
]);
check9($f['visual_conflict']&&!$f['speaker_identity_verified']&&$f['participant_identity']===''&&$f['identity_confidence']===0.0,'visual conflict did not fail closed');
pass9('strong voice/camera identity conflict clears the resolved speaker');

$f=vp3_speaker_fuse_v1([
 ['source'=>'verified_voice','speaker_label'=>'Dave','participant_id'=>7,'confidence'=>.94],
 ['source'=>'account_identity','speaker_label'=>'Alex','participant_id'=>8,'confidence'=>.99],
]);
check9($f['identity_conflict']&&$f['participant_id']===0&&!$f['speaker_identity_verified'],'conflict did not fail closed');
pass9('conflicting strong identity evidence downgrades to unknown');

$f=vp3_speaker_fuse_v1([['source'=>'livekit_track','speaker_label'=>'Alex','participant_identity'=>'vp3p-abc','track_id'=>'TR_x','confidence'=>1,'overlap'=>true,'overlap_group'=>'g1']]);
check9($f['source']==='livekit_track'&&$f['participant_identity']==='vp3p-abc'&&$f['overlap']&&$f['diarization_source']==='livekit_track','LiveKit track attribution failed');
check9(!str_contains(json_encode($f),'TR_x'),'raw track id leaked');
pass9('separate LiveKit tracks are first-class speaker channels and preserve overlap');

$f=vp3_speaker_fuse_v1([
 ['source'=>'heuristic_acoustic','speaker_label'=>'Speaker 2','confidence'=>.8],
 ['source'=>'manual_correction','speaker_label'=>'Jamie','participant_id'=>4,'confidence'=>1],
]);
check9($f['source']==='manual_correction'&&$f['participant_id']===4&&$f['speaker_label']==='Jamie','manual correction priority failed');
pass9('explicit human correction overrides inference');

$f=vp3_speaker_fuse_v1([
 ['source'=>'verified_voice','speaker_label'=>'Wrong','participant_id'=>7,'confidence'=>.97],
 ['source'=>'manual_correction','speaker_label'=>'Jamie','participant_id'=>4,'confidence'=>1],
]);
check9(!$f['identity_conflict']&&$f['source']==='manual_correction'&&$f['participant_id']===4,'manual correction conflicted with lower evidence');
pass9('explicit correction overrides a conflicting inferred identity');

echo "SPEAKER_ATTRIBUTION_SECTION9A=PASS (8 canonical Cloud cases)\n";
