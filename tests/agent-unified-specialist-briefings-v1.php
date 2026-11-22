<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-coordination-work-v1.php';
require_once dirname(__DIR__).'/includes/agent-unified-specialist-briefings-v1.php';
function assert2b(bool $condition,string $why): void { if(!$condition)throw new RuntimeException($why); }
$hash=str_repeat('a',64);
$base=['title'=>'Saved source','summary'=>'Verified source finding','next_step'=>'Review the saved record.',
    'question'=>'Would you like to review this?','status'=>'ready','priority'=>'normal','revision'=>$hash,
    'url'=>'/chat.php?agent=system&conversation_id=40'];
$profile=$base+['ref'=>'profile:conversation:12','specialist'=>'profile',
    'profile_report'=>vp3_profile_specialist_sections_v1(['Saved visitor conversation'],['Review the visitor reply'],[],
        'Open visitor inbox',['Would you like to respond?']),
    'profile_source_kind'=>'conversation','profile_presentation_owned'=>true];
$media=array_replace($base,['ref'=>'media:track:8','specialist'=>'media','priority'=>'high',
    'summary'=>'A saved track is available for playback.','url'=>'/track.php?id=8']);
$marketing=array_replace($base,['ref'=>'marketing:campaign-readiness:9','specialist'=>'marketing',
    'summary'=>'A verified campaign needs review.','url'=>'/campaigns.php?merchant=2']);
$reports=vp3_unified_specialist_briefings_v1(['items'=>[$profile,$media,$marketing],
    'coverage'=>['profile'=>'ready','media'=>'ready','marketing'=>'limited']]);
assert2b(array_column($reports,'specialist_id')===['profile','media','marketing'],'Missing specialist briefing');
foreach($reports as $row){
    assert2b(array_keys($row['sections'])===['key_findings','actions_followups','open_questions','next_step','questions_for_you'],
        'Five report sections required');
    assert2b($row['source_revision']===$hash && str_starts_with($row['source_url'],'/'),'Bound source missing');
}
assert2b($reports[0]['presentation_owner']==='profile_native','Profile native notification must retain ownership');
assert2b($reports[2]['source_coverage']==='limited','Limited report coverage is material');
$bad=array_replace($media,['url'=>'https://outside.example/x','summary'=>'<script>alert(1)</script>Visit https://bad.example']);
$unsafe=vp3_unified_specialist_briefings_v1(['items'=>[$bad],'coverage'=>['media'=>'ready']]);
assert2b(count($unsafe)===1 && $unsafe[0]['source_url']===''
    && !str_contains($unsafe[0]['sections']['key_findings'][0],'<script>')
    && !str_contains($unsafe[0]['sections']['key_findings'][0],'https://bad.example'),'Unsafe media source projection');
assert2b(vp3_unified_specialist_briefings_v1(['items'=>[array_replace($media,['revision'=>'invalid'])],
    'coverage'=>['media'=>'ready']])===[],'Malformed source proof accepted');
assert2b(vp3_unified_specialist_briefings_v1(['items'=>[$media],'coverage'=>['media'=>'unavailable']])===[],
    'Unverified coverage presented as complete');
$choice=vp3_unified_specialist_briefings_v1(['items'=>[
    array_replace($media,['priority'=>'low','ref'=>'media:track:10']),
    array_replace($media,['priority'=>'high','ref'=>'media:track:11'])], 'coverage'=>['media'=>'ready']]);
assert2b($choice[0]['source_ref']==='media:track:11','Must prefer higher priority evidence');
assert2b(vp3_unified_specialist_briefings_v1(['items'=>[
    array_replace($profile,['profile_report'=>['bad'=>'shape']])],'coverage'=>['profile'=>'ready']])===[],
    'Malformed Profile report accepted');
$goal=['tool'=>'coordination.review','primary'=>'media:track:8','sources'=>['media:track:8'=>$hash],
    'coordination_id'=>$hash,'coordination_media_report_v1'=>true,
    'coordination_route'=>['specialist'=>'media','trigger'=>'catalog_pick','action'=>'ask']];
$result=vp3_coordination_saved_result_v1($goal,['media:track:8'=>$media],$goal['coordination_route']);
assert2b(($result['output']['media_report']['contract']??null)===VP3_COORDINATION_MEDIA_REPORT_V1,
    'Media must use versioned five-section Chat report');
assert2b(vp3_coordination_result_normalize_v1($result)===$result,'Media result failed strict normalization');
assert2b(str_contains($result['draft'],'Key findings') && str_contains($result['draft'],'Questions for you'),
    'Chat draft did not contain required report headings');
assert2b(vp3_coordination_saved_revisions_v1($goal,$result)===$result['output']['evidence_revisions'],
    'Saved report missing source proof');
$forged=$result;$forged['output']['media_report']['actions_followups']=['Already played for you'];
assert2b(vp3_coordination_result_normalize_v1($forged)===null,'Unverified completed action accepted');
$legacy=$goal;unset($legacy['coordination_media_report_v1']);
$old=vp3_coordination_saved_result_v1($legacy,['media:track:8'=>$media],$legacy['coordination_route']);
assert2b(!isset($old['output']['media_report']) && vp3_coordination_saved_revisions_v1($legacy,$old),
    'Old verified receipts were invalidated by the new version');
echo "PASS unified specialist five-section briefs, priority, privacy, source proofs, native presenter and legacy replay\n";
