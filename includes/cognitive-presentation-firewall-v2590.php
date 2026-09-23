<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v25.90 — Presentation Firewall.
 *
 * All v25.90 current-state UI presentation must pass through this allowlist.
 * Internal evidence remains available to cognition, never as direct UI copy.
 */
const VP3_COGNITIVE_PRESENTATION_FIREWALL_V2590='vp3-cognitive-presentation-firewall-v2590-20260923';
const VP3_COGNITIVE_PRESENTATION_CONTRACT_V2590='vp3-presentation-object-v1';

function vp3_cognitive_presentation_firewall_text_v2590(mixed $value,int $limit=300): string
{
    $text=preg_replace('/[\x00-\x1F\x7F]+/u',' ',(string)$value)??'';
    $text=preg_replace('/\s+/u',' ',trim($text))??'';
    $text=preg_replace('/\b(?:system prompt|chain of thought|tool trace|raw json|confidence vector|retrieval labels?)\b\s*:?/iu','',$text)??$text;
    if(function_exists('vp3_cognitive_text_v500'))return vp3_cognitive_text_v500($text,$limit);
    return mb_strimwidth($text,0,max(1,$limit),'…');
}

function vp3_cognitive_presentation_firewall_href_v2590(mixed $value): string
{
    $href=trim((string)$value);
    if($href===''||strlen($href)>240||$href[0]!=='/'||str_starts_with($href,'//'))return '';
    if((bool)preg_match('/[\x00-\x20<>"\']/',$href))return '';
    return $href;
}

function vp3_cognitive_presentation_firewall_forbidden_key_v2590(string $key): bool
{
    return (bool)preg_match('/(?:raw|payload|prompt|system|instruction|reasoning|chain|trace|token|secret|credential|cookie|header|memory|confidence|embedding|vector|retrieval|event_uuid|causation|correlation)/i',$key);
}

function vp3_cognitive_presentation_firewall_validate_v2590(array $candidate): array
{
    $allowed=['type','title','summary','status','next_action','action_label','href','meta'];
    $dropped=0;
    foreach(array_keys($candidate) as $key){
        if(!in_array((string)$key,$allowed,true)||vp3_cognitive_presentation_firewall_forbidden_key_v2590((string)$key))$dropped++;
    }
    $meta=[];
    $candidateMeta=is_array($candidate['meta']??null)?$candidate['meta']:[];
    foreach(['domain','freshness','event_type','surface'] as $key){
        if(!array_key_exists($key,$candidateMeta))continue;
        $value=vp3_cognitive_presentation_firewall_text_v2590($candidateMeta[$key],120);
        if($value!=='')$meta[$key]=$value;
    }
    return [
        'presentation'=>[
            'contract'=>VP3_COGNITIVE_PRESENTATION_CONTRACT_V2590,
            'build'=>VP3_COGNITIVE_PRESENTATION_FIREWALL_V2590,
            'type'=>vp3_cognitive_presentation_firewall_text_v2590($candidate['type']??'status',40)?:'status',
            'title'=>vp3_cognitive_presentation_firewall_text_v2590($candidate['title']??'Current state',160)?:'Current state',
            'summary'=>vp3_cognitive_presentation_firewall_text_v2590($candidate['summary']??'',420),
            'status'=>vp3_cognitive_presentation_firewall_text_v2590($candidate['status']??'current',40)?:'current',
            'next_action'=>vp3_cognitive_presentation_firewall_text_v2590($candidate['next_action']??'',300),
            'action_label'=>vp3_cognitive_presentation_firewall_text_v2590($candidate['action_label']??'',80),
            'href'=>vp3_cognitive_presentation_firewall_href_v2590($candidate['href']??''),
            'meta'=>$meta,
        ],
        'audit'=>[
            'dropped_top_level_fields'=>$dropped,
            'allowlist_only'=>true,
            'external_links_allowed'=>false,
            'raw_internal_evidence_allowed'=>false,
        ],
    ];
}

function vp3_cognitive_presentation_from_current_state_v2590(array $state): array
{
    $attention=is_array($state['attention_candidate']??null)?$state['attention_candidate']:null;
    $session=is_array($state['session']??null)?$state['session']:[];
    $activity=is_array($state['activity']??null)?$state['activity']:[];
    if($attention){
        $domain=str_replace('_',' ',(string)($attention['domain']??'VP3'));
        $candidate=[
            'type'=>'attention',
            'title'=>'Needs attention',
            'summary'=>(string)($attention['label']??'Recent item').' in '.$domain.'.',
            'status'=>'attention',
            'next_action'=>'Review the current item and decide whether any action is needed.',
            'action_label'=>'Review current state',
            'href'=>'',
            'meta'=>[
                'domain'=>(string)($attention['domain']??''),
                'event_type'=>(string)($attention['event_type']??''),
                'freshness'=>!empty($attention['fresh'])?'fresh':'stale',
            ],
        ];
        return vp3_cognitive_presentation_firewall_validate_v2590($candidate)['presentation'];
    }
    if($session){
        $surface=(string)($session['current_surface']??'VP3');
        $status=(string)($session['status']??'active');
        return vp3_cognitive_presentation_firewall_validate_v2590([
            'type'=>'session',
            'title'=>'Current session',
            'summary'=>ucfirst($status).' on '.$surface.'.',
            'status'=>$status,
            'next_action'=>'Continue the current work or review recent cross-domain changes.',
            'action_label'=>'Review current state',
            'href'=>'',
            'meta'=>['surface'=>$surface],
        ])['presentation'];
    }
    if($activity){
        $surface=(string)($activity['surface']??'VP3');
        $status=(string)($activity['state']??'idle');
        return vp3_cognitive_presentation_firewall_validate_v2590([
            'type'=>'activity',
            'title'=>'Current state',
            'summary'=>ucfirst($status).' on '.$surface.'.',
            'status'=>$status,
            'next_action'=>'Review recent cross-domain changes.',
            'action_label'=>'Review current state',
            'href'=>'',
            'meta'=>['surface'=>$surface],
        ])['presentation'];
    }
    return vp3_cognitive_presentation_firewall_validate_v2590([
        'type'=>'status','title'=>'Current state','summary'=>'No active VP3 session is materialized.','status'=>'idle',
        'next_action'=>'Open the Agent Brain when you want to review recent activity.','action_label'=>'Review current state','href'=>''
    ])['presentation'];
}
