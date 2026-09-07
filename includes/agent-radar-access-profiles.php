<?php
declare(strict_types=1);

function vp3_radar_access_profile_catalog(): array
{
    return [
        'open_web'=>[
            'label'=>'Open Web',
            'description'=>'Allow recognized AI agents and crawlers. Monitor unknown automation.',
            'rules'=>[
                ['visitor_class'=>'','action'=>'monitor'],
                ['visitor_class'=>'ai_user_agent','action'=>'allow'],
                ['visitor_class'=>'ai_search','action'=>'allow'],
                ['visitor_class'=>'ai_crawler','action'=>'allow'],
                ['visitor_class'=>'automated_unknown','action'=>'monitor'],
            ],
        ],
        'search_friendly'=>[
            'label'=>'Search Friendly',
            'description'=>'Allow user-directed agents and AI search. Block training/general AI crawlers. Monitor unknown automation.',
            'rules'=>[
                ['visitor_class'=>'','action'=>'monitor'],
                ['visitor_class'=>'ai_user_agent','action'=>'allow'],
                ['visitor_class'=>'ai_search','action'=>'allow'],
                ['visitor_class'=>'ai_crawler','action'=>'block'],
                ['visitor_class'=>'automated_unknown','action'=>'monitor'],
            ],
        ],
        'agent_friendly'=>[
            'label'=>'Agent Friendly',
            'description'=>'Allow user-directed and search agents while monitoring crawler and unknown automation.',
            'rules'=>[
                ['visitor_class'=>'','action'=>'monitor'],
                ['visitor_class'=>'ai_user_agent','action'=>'allow'],
                ['visitor_class'=>'ai_search','action'=>'allow'],
                ['visitor_class'=>'ai_crawler','action'=>'monitor'],
                ['visitor_class'=>'automated_unknown','action'=>'monitor'],
            ],
        ],
        'private'=>[
            'label'=>'Private',
            'description'=>'Block automated visitors by default. Explicit per-contact Allow policies still override this default.',
            'rules'=>[
                ['visitor_class'=>'','action'=>'block'],
            ],
        ],
        'locked_down'=>[
            'label'=>'Locked Down',
            'description'=>'Block automated visitors by default and treat this as the strictest starting policy.',
            'rules'=>[
                ['visitor_class'=>'','action'=>'block'],
            ],
        ],
        'monitor_all'=>[
            'label'=>'Monitor All',
            'description'=>'Observe automated visitors without restricting access.',
            'rules'=>[
                ['visitor_class'=>'','action'=>'monitor'],
            ],
        ],
    ];
}

function vp3_radar_access_profile_public_catalog(): array
{
    $out=[];
    foreach(vp3_radar_access_profile_catalog() as $slug=>$profile){
        $rules=[];
        foreach($profile['rules'] as $rule)$rules[]=['visitor_class'=>(string)$rule['visitor_class'],'action'=>(string)$rule['action']];
        $out[$slug]=[
            'slug'=>$slug,
            'label'=>$profile['label'],
            'description'=>$profile['description'],
            'rules'=>$rules,
        ];
    }
    return array_values($out);
}

function vp3_radar_access_profile_current(PDO $pdo,int $ownerUserId): ?array
{
    if($ownerUserId<1)return null;
    $stmt=$pdo->prepare("SELECT metadata_json,updated_at FROM vp3_agent_policies WHERE owner_user_id=? AND is_active=1 AND agent_contact_id IS NULL AND metadata_json LIKE '%\"source\":\"access_profile\"%' ORDER BY updated_at DESC,id DESC LIMIT 1");
    $stmt->execute([$ownerUserId]);$row=$stmt->fetch();
    if(!$row)return null;
    $meta=vp3_radar_gateway_policy_metadata((string)$row['metadata_json']);$slug=(string)($meta['profile_slug']??'');$catalog=vp3_radar_access_profile_catalog();
    if($slug===''||!isset($catalog[$slug]))return null;
    return ['slug'=>$slug,'label'=>$catalog[$slug]['label'],'description'=>$catalog[$slug]['description'],'updated_at'=>(string)$row['updated_at']];
}

function vp3_radar_access_profile_apply(PDO $pdo,array $user,string $slug): array
{
    $owner=(int)($user['id']??0);$slug=strtolower(trim($slug));$catalog=vp3_radar_access_profile_catalog();
    if($owner<1)throw new RuntimeException('Sign in to manage Agent Gateway.');
    if(!isset($catalog[$slug]))throw new RuntimeException('Choose a valid Agent Gateway access profile.');
    $profile=$catalog[$slug];
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE vp3_agent_policies SET is_active=0,updated_at=NOW() WHERE owner_user_id=? AND agent_contact_id IS NULL AND metadata_json LIKE '%\"source\":\"access_profile\"%' AND is_active=1")->execute([$owner]);
        $insert=$pdo->prepare("INSERT INTO vp3_agent_policies (owner_user_id,property_id,agent_contact_id,operator_name,visitor_class,path_pattern,action,priority,expires_at,metadata_json,is_active) VALUES (?,NULL,NULL,'',?,'*',?,200,NULL,?,1)");
        foreach($profile['rules'] as $rule){
            $meta=json_encode(['source'=>'access_profile','profile_slug'=>$slug,'profile_label'=>$profile['label']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $insert->execute([$owner,(string)$rule['visitor_class'],(string)$rule['action'],$meta]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['profile'=>vp3_radar_access_profile_current($pdo,$owner),'catalog'=>vp3_radar_access_profile_public_catalog()];
}
