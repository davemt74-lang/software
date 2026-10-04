<?php
declare(strict_types=1);

const VP3_SPEAKER_ATTRIBUTION_V1='speaker-attribution-v1-20261004';
const VP3_SPEAKER_ATTRIBUTION_SOURCES=[
    'unknown','heuristic_acoustic','provider_diarization','verified_voice',
    'livekit_track','manual_correction','visual_corroboration','account_identity'
];

function vp3_speaker_text_v1(mixed $value,int $limit): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    return mb_strimwidth($text,0,$limit,'');
}

function vp3_speaker_source_v1(mixed $value): string
{
    $value=strtolower(trim((string)$value));
    return in_array($value,VP3_SPEAKER_ATTRIBUTION_SOURCES,true)?$value:'unknown';
}

function vp3_speaker_confidence_v1(mixed $value): float
{
    return round(max(0.0,min(1.0,is_numeric($value)?(float)$value:0.0)),4);
}

function vp3_speaker_identity_key_v1(array $evidence): string
{
    $participant=max(0,(int)($evidence['participant_id']??0));
    if($participant>0)return 'participant:'.$participant;
    $identity=vp3_speaker_text_v1($evidence['participant_identity']??'',160);
    return $identity!==''?'identity:'.$identity:'';
}

function vp3_speaker_evidence_v1(array $raw): array
{
    $source=vp3_speaker_source_v1($raw['source']??'unknown');
    $participant=max(0,(int)($raw['participant_id']??0));
    $participantIdentity=vp3_speaker_text_v1($raw['participant_identity']??'',160);
    $label=vp3_speaker_text_v1($raw['speaker_label']??'',190);
    $providerRef=vp3_speaker_text_v1($raw['provider_speaker_id']??'',190);
    $trackRef=vp3_speaker_text_v1($raw['track_id']??'',190);
    $identityCapable=in_array($source,['verified_voice','livekit_track','manual_correction','account_identity'],true);
    if(!$identityCapable&&$source!=='visual_corroboration'){
        $participant=0;$participantIdentity='';
    }
    // Visual evidence may carry the same opaque participant reference as a
    // trusted voice/manual source for corroboration/conflict, but is never
    // identity-capable on its own.
    $identityVerified=$identityCapable&&($participant>0||$participantIdentity!=='');
    return [
        'source'=>$source,
        'speaker_label'=>$label,
        'confidence'=>vp3_speaker_confidence_v1($raw['confidence']??0),
        'participant_id'=>$participant,
        'participant_identity'=>$participantIdentity,
        'identity_verified'=>$identityVerified,
        'authentication_authority'=>false,
        'overlap'=>!empty($raw['overlap']),
        'overlap_group'=>vp3_speaker_text_v1($raw['overlap_group']??'',80),
        'provider_ref_hash'=>$providerRef!==''?substr(hash('sha256',$providerRef),0,24):'',
        'track_ref_hash'=>$trackRef!==''?substr(hash('sha256',$trackRef),0,24):'',
        'observed_at'=>vp3_speaker_text_v1($raw['observed_at']??'',40),
    ];
}

function vp3_speaker_rank_v1(string $source): int
{
    return match($source){
        'manual_correction'=>700,
        'account_identity'=>650,
        'verified_voice'=>600,
        'livekit_track'=>550,
        'provider_diarization'=>350,
        'heuristic_acoustic'=>150,
        'unknown'=>0,
        default=>-1,
    };
}

function vp3_speaker_fuse_v1(array $rawEvidence): array
{
    $evidence=[];$visual=[];
    foreach(array_slice($rawEvidence,0,16) as $row){
        if(!is_array($row))continue;
        $item=vp3_speaker_evidence_v1($row);
        if($item['source']==='visual_corroboration')$visual[]=$item;
        else $evidence[]=$item;
    }
    usort($evidence,static function(array $a,array $b): int {
        $rank=vp3_speaker_rank_v1($b['source'])<=>vp3_speaker_rank_v1($a['source']);
        return $rank!==0?$rank:($b['confidence']<=>$a['confidence']);
    });

    $manual=array_values(array_filter($evidence,static fn(array $item): bool =>
        $item['source']==='manual_correction'&&$item['identity_verified']&&$item['confidence']>=0.72
    ));
    $conflictPool=$manual?:$evidence;
    $strongIdentities=[];
    foreach($conflictPool as $item){
        $key=vp3_speaker_identity_key_v1($item);
        if($key!==''&&$item['identity_verified']&&$item['confidence']>=0.72)$strongIdentities[$key]=true;
    }
    $identityConflict=count($strongIdentities)>1;

    $primary=$evidence[0]??vp3_speaker_evidence_v1(['source'=>'unknown']);
    if($identityConflict){
        $primary=vp3_speaker_evidence_v1([
            'source'=>'unknown','speaker_label'=>$primary['speaker_label'],
            'confidence'=>0,'overlap'=>$primary['overlap'],'overlap_group'=>$primary['overlap_group'],
        ]);
    }

    $key=vp3_speaker_identity_key_v1($primary);
    $visualCorroborated=false;$visualConflict=false;
    foreach($visual as $item){
        $visualKey=vp3_speaker_identity_key_v1($item);
        if($key!==''&&$visualKey===$key&&$item['confidence']>=0.58)$visualCorroborated=true;
        elseif($key!==''&&$visualKey!==''&&$visualKey!==$key&&$item['confidence']>=0.78)$visualConflict=true;
    }

    // Visual evidence may corroborate a voice/account/track/manual identity, but it
    // never creates a speaker identity and never authenticates a person.
    $identityConfidence=$primary['identity_verified']?$primary['confidence']:0.0;
    if($visualCorroborated)$identityConfidence=min(1.0,$identityConfidence+0.05);
    if($visualConflict)$identityConfidence=0.0;

    $overlap=$primary['overlap'];
    $overlapGroup=$primary['overlap_group'];
    foreach($evidence as $item){
        if($item['overlap']){$overlap=true;if($overlapGroup===''&&$item['overlap_group']!=='')$overlapGroup=$item['overlap_group'];}
    }

    return [
        'contract'=>VP3_SPEAKER_ATTRIBUTION_V1,
        'speaker_label'=>$primary['speaker_label']!==''?$primary['speaker_label']:'Speaker',
        'source'=>$primary['source'],
        'confidence'=>$primary['confidence'],
        'participant_id'=>($identityConflict||$visualConflict)?0:$primary['participant_id'],
        'participant_identity'=>($identityConflict||$visualConflict)?'':$primary['participant_identity'],
        'speaker_identity_verified'=>!$identityConflict&&!$visualConflict&&$primary['identity_verified'],
        'identity_confidence'=>round($identityConfidence,4),
        'authentication_authority'=>false,
        'visual_corroborated'=>$visualCorroborated,
        'visual_conflict'=>$visualConflict,
        'identity_conflict'=>$identityConflict,
        'overlap'=>$overlap,
        'overlap_group'=>$overlapGroup,
        'diarization_source'=>array_reduce($evidence,static function(string $current,array $item): string {
            if($item['source']==='livekit_track')return 'livekit_track';
            if($current!=='livekit_track'&&$item['source']==='provider_diarization')return 'provider_diarization';
            if($current==='none'&&$item['source']==='heuristic_acoustic')return 'heuristic_acoustic';
            return $current;
        },'none'),
        'evidence'=>array_values(array_merge($evidence,$visual)),
    ];
}
