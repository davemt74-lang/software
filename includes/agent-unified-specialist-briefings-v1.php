<?php
declare(strict_types=1);

/**
 * The Brain's read-only view of verified Profile, Media, and Marketing sources.
 * No inference, scheduling, message delivery, or independent evidence store occurs here.
 */
function vp3_unified_specialist_briefings_v1(array $snapshot): array
{
    $coverage=is_array($snapshot['coverage']??null)?$snapshot['coverage']:[];
    $items=is_array($snapshot['items']??null)?$snapshot['items']:[];
    $selected=[];
    foreach($items as $source){
        if(!is_array($source))continue;
        $id=$source['specialist']??null;
        if(!in_array($id,['profile','media','marketing'],true)
            ||!in_array($coverage[$id]??null,['ready','limited'],true))continue;
        $ref=$source['ref']??null;$revision=$source['revision']??null;
        if(!is_string($ref)||strlen($ref)>196||preg_match('/\A'.preg_quote($id,'/').':[A-Za-z0-9:@_-]{1,180}\z/D',$ref)!==1
            ||!is_string($revision)||preg_match('/\A[a-f0-9]{64}\z/D',$revision)!==1)continue;
        $title=vp3_unified_briefing_text_v1($source['title']??null,160);
        $summary=vp3_unified_briefing_text_v1($source['summary']??null,480);
        $next=vp3_unified_briefing_text_v1($source['next_step']??null,320);
        $question=vp3_unified_briefing_text_v1($source['question']??null,320);
        if($title===''||$summary==='')continue;
        $priority=in_array($source['priority']??null,['high','normal','low'],true)?$source['priority']:'low';
        $sections=['key_findings'=>[$summary],'actions_followups'=>$next!==''?[$next]:[],
            'open_questions'=>[],'next_step'=>$next,'questions_for_you'=>$question!==''?[$question]:[]];
        if($id==='profile'){
            require_once __DIR__.'/agent-profile-specialist-v1.php';
            if(!vp3_profile_specialist_sections_valid_v1($source['profile_report']??null))continue;
            // The public visitor event presenter already owns delivery; a routine
            // view cannot displace a conversation, call, or genuine follow-up.
            if(vp3_profile_specialist_routine_visit_v1($source))continue;
            $report=$source['profile_report'];
            foreach($sections as $name=>$_){
                $value=$report[$name];
                $sections[$name]=is_array($value)
                    ?array_values(array_filter(array_slice(array_map(
                        static fn($text):string=>vp3_unified_briefing_text_v1($text,320),$value),0,4)))
                    :vp3_unified_briefing_text_v1($value,320);
            }
        }
        $href=$source['url']??null;
        if(!is_string($href)||strlen($href)>1900||!str_starts_with($href,'/')||str_starts_with($href,'//')
            ||str_contains($href,'\\')||preg_match('/[\x00-\x1f\x7f]/',$href))$href='';
        $candidate=['specialist_id'=>$id,'source_ref'=>$ref,'source_revision'=>$revision,
            'source_coverage'=>$coverage[$id], 'title'=>$title,'priority'=>$priority,
            'status'=>vp3_unified_briefing_text_v1($source['status']??'',40),
            'sections'=>$sections,'source_url'=>$href,
            'presentation_owner'=>($source['profile_presentation_owned']??false)===true?'profile_native':'coordination'];
        $rank=match($priority){'high'=>0,'normal'=>1,default=>2};
        $action=($next!==''||$question!=='')?0:1;
        $score=[$rank,$action];
        if(!isset($selected[$id])||$score<$selected[$id]['score'])$selected[$id]=['score'=>$score,'report'=>$candidate];
    }
    $out=[];
    foreach(['profile','media','marketing'] as $id){
        if(isset($selected[$id]))$out[]=$selected[$id]['report'];
    }
    return $out;
}

/** Source text is evidence, not an instruction or an HTML surface. */
function vp3_unified_briefing_text_v1(mixed $value,int $max): string
{
    if(!is_string($value))return '';
    $text=strip_tags($value);
    $text=preg_replace('~(?:https?://|\bwww\.)[^\s<>]+~iu','[link in source]',$text)??'';
    $text=preg_replace('/[<>\p{Cc}\p{Cf}]|\s+/u',' ',$text)??'';
    return mb_substr(trim($text),0,$max);
}
