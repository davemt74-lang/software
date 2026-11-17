<?php
declare(strict_types=1);

const VP3_SPECIALIST_STARTERS_V1 = [
    'starter-image'=>['specialist'=>'image-generator','title'=>'Image example','label'=>'Try an image',
        'question'=>'Want to try a sample image of a desert observatory under the stars?'],
    'starter-coding'=>['specialist'=>'coding','title'=>'Canvas example','label'=>'Try a demo canvas',
        'question'=>'Want to try a demo canvas with an interactive launch checklist?'],
    'starter-research'=>['specialist'=>'research','title'=>'Research example','label'=>'Try a research question',
        'question'=>'Want to see how Research compares public sources on speech-to-text accuracy?'],
    'starter-mission'=>['specialist'=>'mission','title'=>'Mission example','label'=>'Try a Mission plan',
        'question'=>'Want to try planning a Mission to compare three podcast microphones?'],
    'starter-tasks'=>['specialist'=>'tasks','title'=>'Tasks example','label'=>'Review saved tasks',
        'question'=>'Want to review your saved tasks together?'],
    'starter-gmail'=>['specialist'=>'gmail','title'=>'Gmail example','label'=>'Check urgent email',
        'question'=>'Want me to check your inbox for anything that needs attention today?'],
    'starter-media'=>['specialist'=>'media','title'=>'Media example','label'=>'See media options',
        'question'=>'Want to see what you can listen to or watch in Chat?'],
    'starter-canvas'=>['specialist'=>'canvas','title'=>'Canvas Apps example','label'=>'Open Canvas Apps',
        'question'=>'Want to see where your private Canvas apps and their saved data are managed?'],
    'starter-connected-sites'=>['specialist'=>'connected-sites','title'=>'Connected Sites example','label'=>'Open Connected Sites',
        'question'=>'Want to see what your connected sites let me read and report on?'],
    'starter-brainstorm'=>['specialist'=>'brainstorm','title'=>'Brainstorm example','label'=>'Explore an idea',
        'question'=>'Want to brainstorm a few ways to make your weekly planning easier?'],
    'starter-development'=>['specialist'=>'development','title'=>'Agent Development example','label'=>'Draft an example request',
        'question'=>'Want to try outlining a small change for review in Agent Development?'],
];

function vp3_casual_starter_specialist_v1(string $ref): ?string
{
    return str_starts_with($ref,'casual:')?VP3_SPECIALIST_STARTERS_V1[substr($ref,strlen('casual:'))]['specialist']??null:null;
}

function vp3_casual_starter_available_v1(PDO $pdo,array $scope,string $kind): bool
{
    $user=$scope['user'];
    $specialist=VP3_SPECIALIST_STARTERS_V1[$kind]['specialist']??null;
    if($specialist===null||!has_permission('chat.access',$user))return false;
    require_once __DIR__.'/agent-coordination-rules-v1.php';
    if(isset(vp3_coordination_rule_definitions_v1()[$specialist])){
        if(!agent_brain_schema_ready())return false;
        $choices=vp3_coordination_rules_v1($pdo,$user,$scope['agent_id'])['rules'][$specialist]??[];
        if(!in_array('ask',$choices,true))return false;
    }
    $owner=(int)$user['id'];
    switch($kind){
        case 'starter-image':
            require_once __DIR__.'/agent-image-generation-v1.php';
            vp3_image_queue_ready_v1($pdo,$user);
            return true;
        case 'starter-coding':
            require_once __DIR__.'/coding-canvas-store-v1.php';
            require_once __DIR__.'/coding-canvas-model-v1.php';
            vp3_coding_canvas_authority_v1($pdo,$user,$scope['agent_id'],true);
            return vp3_coding_canvas_model_state_v1($pdo,$user)['effective']!==null;
        case 'starter-research':
            require_once __DIR__.'/knowledge.php';
            return agent_brain_schema_ready()&&personal_capability_has_v242('agent_brain.access',$user)&&personal_knowledge_available($user);
        case 'starter-mission':
            require_once __DIR__.'/cloud-missions-v1.php';
            return cloud_mission_ready_v1($pdo);
        case 'starter-tasks':
        case 'starter-brainstorm':
            return agent_brain_schema_ready()&&personal_capability_has_v242('agent_brain.access',$user);
        case 'starter-gmail':
            if(!table_exists('agent_connections_v1'))return false;
            require_once __DIR__.'/agent-connections-v1.php';
            $gmail=agent_connections_summary_v1($owner);
            return !empty($gmail['provider_ready'])&&($gmail['connection']['status']??null)==='connected'
                &&!empty($gmail['connection']['permissions']['read']);
        case 'starter-media':
            require_once __DIR__.'/agent-idle-media-preferences-v1.php';
            if(vp3_casual_checkin_preference_v1($pdo,$owner)===false)return false;
            $media=vp3_idle_media_preferences_v1($pdo,$user,$scope['agent_id']);
            return (!empty($media['allow_music'])&&vp3_casual_checkin_music_v1($pdo,$scope,null)!==null)
                ||(!empty($media['allow_youtube'])&&vp3_casual_checkin_youtube_v1($pdo,$owner)!==null);
        case 'starter-canvas':
            require_once __DIR__.'/coding-canvas-store-v1.php';
            return vp3_coding_canvas_authority_v1($pdo,$user,$scope['agent_id'])!==null;
        case 'starter-connected-sites':
            if(table_exists('vp3_gelato_connections_v1')){
                require_once __DIR__.'/connected-gelato-v1.php';
                if(vp3_gelato_connections_v1($pdo,$owner)!==[])return true;
            }
            if(!table_exists('vp3_stoneward_connections_v1'))return false;
            require_once __DIR__.'/connected-stoneward-v1.php';
            return vp3_stoneward_connections_v1($pdo,$owner)!==[];
        case 'starter-development':
            require_once __DIR__.'/agent-development-v1.php';
            return vp3_dev_is_admin_v1($user)&&vp3_dev_schema_ready_v1($pdo)
                &&in_array(vp3_dev_control_v1($pdo,$owner)['mode'],['outline','code_stage'],true);
    }
    return false;
}

/** Starter offers are shared account-wide. A still-open offer does not block actual work. */
function vp3_casual_starter_offers_v1(array $delivery): array
{
    return array_filter(array_intersect_key(is_array($delivery['starter_offers']??null)?$delivery['starter_offers']:[],VP3_SPECIALIST_STARTERS_V1),
        static fn(mixed $message): bool=>is_int($message)&&$message>0);
}

function vp3_casual_starter_may_offer_v1(PDO $pdo,array $user,int $cid,array $delivery): bool
{
    if(count(vp3_casual_starter_offers_v1($delivery))===count(VP3_SPECIALIST_STARTERS_V1))return false;
    $json="CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END";
    // A genuine Chat turn starts onboarding. Pending replies to other specialists do not end it.
    $turn=$pdo->prepare("SELECT 1 FROM chat_messages WHERE conversation_id=? AND user_id=? AND role='user'
        AND JSON_EXTRACT($json,'$.casual_checkin_reply_v1') IS NULL
        AND JSON_EXTRACT($json,'$.initiative_reply_anchor_v1') IS NULL LIMIT 1");
    $turn->execute([$cid,(int)$user['id']]);
    return $turn->fetchColumn()!==false;
}
