<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-source-registry-v1.php';
require_once __DIR__.'/agent-coordination-rules-v1.php';
require_once __DIR__.'/agent-profile-specialist-v1.php';
require_once __DIR__.'/agent-product-marketing-summary-v1.php';

/** Identify the specialist that owns an existing executor. Tool admission stays with its adapter. */
function vp3_initiative_tool_specialist_v1(string $tool): ?string
{
    return match ($tool) {
        'brainstorm.explore' => 'brainstorm',
        'tasks.read', 'tasks.lists.read' => 'tasks',
        'calendar.read', 'reminders.read' => 'planning',
        'artist.research', 'topic.research', 'knowledge.search' => 'research',
        'youtube.playback_offer' => 'media',
        'connected-sites.scan' => 'connected-sites',
        'canvas.prepare' => 'coding',
        'image.prepare', 'workbench.screen.review' => 'image-generator',
        'missions.prepare', 'missions.evidence.read', 'workbench.mission.review' => 'mission',
        'transcripts.read' => 'transcripts',
        'products.content.prepare', 'workbench.product.review' => 'commerce',
        'workbench.marketing.review' => 'marketing',
        'initiative.plan', 'coordination.review', 'work.prepare_private', 'chat.check_in', 'brain.read',
        'live.context.read' => 'ob1',
        default => null,
    };
}

/** Attribute a persisted assignment after its existing scope and receipt checks. */
function vp3_initiative_goal_specialist_v1(array $goal): ?string
{
    $tool = $goal['tool'] ?? '';
    if ($tool === 'reminders.read' && ($goal['office_entry'] ?? null) === true) return 'tasks';
    if ($tool === 'work.prepare_private' && is_string($goal['primary'] ?? null)
        && str_starts_with($goal['primary'],'specialist:profile:')
        && vp3_profile_specialist_ref_kind_v1(substr($goal['primary'],11))!==null) return 'profile';
    if($tool==='work.prepare_private'&&is_string($goal['primary']??null)
        &&str_starts_with($goal['primary'],'specialist:marketing:')
        &&vp3_product_marketing_ref_kind_v1(substr($goal['primary'],11))!==null)return 'marketing';
    if ($tool === 'coordination.review') {
        $route = $goal['coordination_route']['specialist'] ?? null;
        if ($route === 'casual' && is_string($goal['primary'] ?? null)) {
            return vp3_casual_starter_specialist_v1($goal['primary']) ?? match ($goal['primary']) {
                'casual:music', 'casual:youtube', 'casual:youtube_history', 'casual:media' => 'media',
                'casual:email' => 'gmail',
                'casual:today' => 'planning',
                'casual:resume' => 'tasks',
                default => 'ob1',
            };
        }
        $specialist = $goal['coordination_work']['specialist'] ?? $route;
        if ($specialist !== 'casual' && in_array($specialist, array_column(vp3_coordination_source_registry_v1(), 'specialist'), true)) {
            return $specialist;
        }
    }
    return is_string($tool) ? vp3_initiative_tool_specialist_v1($tool) : null;
}

/** Describe the registered report sources and ready tools supplied by the current assessment. */
function vp3_initiative_specialist_catalog_v1(array $tools): array
{
    $definitions = vp3_coordination_rule_definitions_v1();
    $catalog = [];
    foreach (vp3_coordination_source_registry_v1() as $source => $adapter) {
        if ($adapter['sweep'] === 'never') continue;
        $id = $adapter['specialist'];
        $catalog[$id] ??= ['label' => $definitions[$id]['label'] ?? $id,
            'state' => 'report_source', 'report_sources' => [], 'ready_tools' => []];
        $catalog[$id]['report_sources'][] = $source;
    }
    $labels = [
        'eyes'=>'Agent Eyes', 'app-suggestions'=>'App Suggestions',
        'research' => 'Research', 'image-generator' => 'Image Generator', 'coding' => 'Coding', 'mission' => 'Missions',
        'location' => 'Location Specialist', 'operator' => 'Operator Specialist', 'transcripts' => 'Transcripts', 'commerce' => 'Commerce', 'ob1' => 'OB1', 'brainstorm' => 'Brainstorming', 'development' => 'Agent Development',
    ];
    foreach ($labels as $id => $label) {
        $catalog[$id] ??= ['label' => $label, 'state' => match ($id) {
            'ob1' => 'coordinator', 'eyes', 'development', 'location', 'operator' => 'owner_requested', default => 'no_ready_tool',
        }, 'report_sources' => [], 'ready_tools' => []];
    }
    foreach ($tools as $id => $tool) {
        if (!is_string($id) || !is_array($tool) || ($tool['ready'] ?? null) !== true) continue;
        $specialist = vp3_initiative_tool_specialist_v1($id);
        if ($specialist === null) continue;
        $catalog[$specialist]['ready_tools'][] = $id;
        if ($catalog[$specialist]['state'] !== 'coordinator') $catalog[$specialist]['state'] = 'ready';
    }
    return $catalog;
}

/** Label a handoff using only an already verified snapshot and an admitted tool. */
function vp3_initiative_handoff_view_v1(string $tool,array $sources,array $evidence): ?array
{
    $to=vp3_initiative_tool_specialist_v1($tool);
    if($to===null)return null;
    $specialists=vp3_initiative_specialist_catalog_v1([]);
    $from=[];
    foreach($sources as $ref=>$revision){
        $source=$evidence[$ref]??null;
        if(!is_string($ref)||!preg_match('/\Aspecialist-result:[1-9][0-9]{0,17}\z/D',$ref)
            ||!is_string($revision)||!is_array($source)||($source['kind']??null)!=='specialist_result'
            ||($source['revision']??null)!==$revision)continue;
        $id=$source['specialist']??null;
        if(is_string($id)&&isset($specialists[$id]))$from[$id]=true;
    }
    return $from?['from'=>array_keys($from),'to'=>$to]:null;
}
