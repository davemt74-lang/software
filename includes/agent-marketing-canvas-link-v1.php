<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-marketing-v1.php';
require_once __DIR__.'/coding-canvas-store-v1.php';
require_once __DIR__.'/coding-canvas-library-image-v1.php';

const VP3_MARKETING_PRIVATE_CANVAS_ROLE_V1='private_canvas_review_v1';

function vp3_marketing_canvas_candidate_v1(PDO $pdo,array $scope,string $id,int $revision,bool $lock=false): array
{
    $owner=(int)$scope['user']['id'];
    $agent=$scope['agent_id'];
    $canvas=vp3_coding_canvas_require_row_v1($pdo,$owner,$agent,vp3_coding_canvas_id_v1($id),$lock);
    if ((int)$canvas['conversation_id']!==$scope['conversation_id']) throw new DomainException('Choose a Canvas from this private Chat.',403);
    $details=vp3_coding_canvas_details_v1($pdo,$canvas,$revision);
    if (($details['revision']['test_passed']??null)!==true || !in_array($details['revision']['status'],['active','draft'],true)) {
        throw new DomainException('Test the current private Canvas version before linking it.',409);
    }
    $image=vp3_coding_canvas_library_image_proof_v1($pdo,$canvas,$revision);
    return ['id'=>$canvas['id'],'title'=>vp3_agent_activity_text_v1($canvas['title'],130),'version'=>$revision,
        'attached'=>$image['attached'],'proof'=>$image['token']];
}

function vp3_marketing_canvas_choices_v1(PDO $pdo,array $scope): array
{
    if (!schema_metadata_table_exists($pdo,'vp3_coding_canvases_v1')
        || !schema_metadata_table_exists($pdo,'campaign_creative_assets')) return [];
    vp3_coding_canvas_authority_v1($pdo,$scope['user'],$scope['agent_id']);
    $query=$pdo->prepare('SELECT id,draft_revision,active_revision FROM vp3_coding_canvases_v1
        WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND chat_hidden=0 ORDER BY updated_at DESC,id LIMIT 12');
    $query->execute([(int)$scope['user']['id'],$scope['agent_id'],$scope['conversation_id']]);
    $choices=[];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (array_unique([(int)$row['draft_revision'],(int)$row['active_revision']]) as $revision) {
            if ($revision<1) continue;
            try {
                $choice=vp3_marketing_canvas_candidate_v1($pdo,$scope,$row['id'],$revision);
                $choices[]=$choice;
            } catch (RuntimeException|DomainException $error) {
                if ($error instanceof PDOException || !in_array($error->getCode(),[403,404,409,422],true)) throw $error;
            }
        }
    }
    return $choices;
}

function vp3_marketing_canvas_rows_v1(PDO $pdo,int $owner,int $campaign): array
{
    $query=$pdo->prepare('SELECT id,media_ref,content_json FROM campaign_creative_assets
        WHERE campaign_id=? AND created_by_user_id=? AND asset_role=? AND is_active=1 ORDER BY id DESC');
    $query->execute([$campaign,$owner,VP3_MARKETING_PRIVATE_CANVAS_ROLE_V1]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_marketing_canvas_scope_row_v1(PDO $pdo,array $scope,int $campaign): ?array
{
    foreach (vp3_marketing_canvas_rows_v1($pdo,(int)$scope['user']['id'],$campaign) as $row) {
        $saved=json_decode((string)$row['content_json'],true,8);
        if (is_array($saved) && ($saved['agent_id']??null)===$scope['agent_id']
            && ($saved['conversation_id']??null)===$scope['conversation_id']) return ['row'=>$row,'saved'=>$saved];
    }
    return null;
}

function vp3_marketing_canvas_artifact_v1(PDO $pdo,array $scope,int $campaign,int $campaignVersion): ?array
{
    if (!schema_metadata_table_exists($pdo,'campaign_creative_assets')) return null;
    $link=vp3_marketing_canvas_scope_row_v1($pdo,$scope,$campaign);
    if ($link===null) return null;
    $saved=$link['saved'];
    $artifact=['label'=>'Private Canvas link','state'=>'unavailable'];
    if (($saved['campaign_version']??null)!==$campaignVersion || !is_string($saved['canvas_id']??null)
        || !is_int($saved['canvas_version']??null) || !is_string($saved['proof']??null)
        || $link['row']['media_ref']!=='canvas:'.$saved['canvas_id']) return $artifact;
    try {
        vp3_coding_canvas_authority_v1($pdo,$scope['user'],$scope['agent_id']);
        $canvas=vp3_marketing_canvas_candidate_v1($pdo,$scope,$saved['canvas_id'],$saved['canvas_version']);
        if (!hash_equals($saved['proof'],$canvas['proof'])) return $artifact;
    } catch (RuntimeException|DomainException $error) {
        if ($error instanceof PDOException || !in_array($error->getCode(),[403,404,409,422],true)) throw $error;
        return $artifact;
    }
    return ['label'=>$canvas['title'].' · Version '.$canvas['version'] . ($canvas['attached']?' · Image attached':''),
        'state'=>'linked','href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'],$scope['conversation_id'])
            .'&canvas_id='.rawurlencode($canvas['id']).'&canvas_revision='.$canvas['version']];
}

function vp3_marketing_canvas_link_v1(PDO $pdo,array $scope,int $campaign,string $canvasId,int $version,
    int $expectedCampaignVersion,string $expectedCanvasProof): array
{
    $owner=vp3_coordination_marketing_owner_v1($pdo,$scope);
    if ($owner===null || !schema_metadata_table_exists($pdo,'campaign_creative_assets')) {
        throw new DomainException('Private campaign review is unavailable.',403);
    }
    return vp3_coding_canvas_atomic_v1($pdo,$owner,static function()use(
        $pdo,$scope,$owner,$campaign,$canvasId,$version,$expectedCampaignVersion,$expectedCanvasProof
    ):array {
        vp3_coding_canvas_authority_v1($pdo,$scope['user'],$scope['agent_id']);
        $row=campaigns_rewards_campaign_platform_v100($pdo,$campaign,$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
        if (!$row || (int)$row['merchant_owner_user_id']!==$owner || $row['merchant_status']!=='active'
            || !empty($row['merchant_sandbox_mode']) || $row['environment']!=='production'
            || !campaigns_rewards_platform_can_v100($pdo,(int)$row['merchant_id'],$owner,'campaigns.view')
            || !campaigns_rewards_platform_can_v100($pdo,(int)$row['merchant_id'],$owner,'campaigns.edit')
            || !campaigns_rewards_platform_can_v100($pdo,(int)$row['merchant_id'],$owner,'analytics.view')) {
            throw new DomainException('Campaign access changed.',403);
        }
        if ((int)$row['current_version_no']!==$expectedCampaignVersion) throw new DomainException('Campaign version changed. Refresh its report.',409);
        $canvas=vp3_marketing_canvas_candidate_v1($pdo,$scope,$canvasId,$version,true);
        if (!hash_equals($canvas['proof'],$expectedCanvasProof)) throw new DomainException('The Canvas image changed. Refresh its report.',409);
        $record=['agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],'campaign_version'=>$expectedCampaignVersion,
            'canvas_id'=>$canvas['id'],'canvas_version'=>$canvas['version'],'proof'=>$canvas['proof']];
        $prior=vp3_marketing_canvas_scope_row_v1($pdo,$scope,$campaign);
        if (($prior['saved']??null)===$record) return ['state'=>'linked'];
        if ($prior!==null) {
            $pdo->prepare('UPDATE campaign_creative_assets SET is_active=0 WHERE id=? AND campaign_id=? AND created_by_user_id=?')
                ->execute([(int)$prior['row']['id'],$campaign,$owner]);
        }
        $pdo->prepare('INSERT INTO campaign_creative_assets (campaign_id,asset_role,media_ref,content_json,created_by_user_id)
            VALUES (?,?,?,?,?)')->execute([$campaign,VP3_MARKETING_PRIVATE_CANVAS_ROLE_V1,'canvas:'.$canvas['id'],json_encode($record,JSON_THROW_ON_ERROR),$owner]);
        return ['state'=>'linked'];
    });
}
