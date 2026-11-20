<?php
declare(strict_types=1);

/**
 * Marketing Workspace 2B.2: a read-only selection for the existing canonical
 * private Canvas-to-Campaign link action. Never creates a second creative store.
 */
function vp3_marketing_workspace_canvas_options_v1(PDO $pdo,int $owner,array $scope,array $body): array
{
    if($owner<1 || (int)($scope['user']['id']??0)!==$owner || !has_permission('account.access',$scope['user'])) {
        throw new DomainException('Marketing owner access is required.',403);
    }
    if(!function_exists('vp3_coordination_marketing_owner_v1')) require_once __DIR__.'/agent-coordination-marketing-v1.php';
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner) {
        throw new DomainException('Marketing access changed. Reopen Chat.',403);
    }
    $campaignId=$body['campaign_id']??null;
    if(!is_int($campaignId)||$campaignId<1||$campaignId>9007199254740991) {
        throw new DomainException('Select an exact Campaign to link creative.',422);
    }
    if(!function_exists('campaigns_rewards_campaign_platform_v100'))require_once __DIR__.'/campaigns-rewards-domain-v100.php';
    $verify=static function()use($pdo,$campaignId,$owner):array{
        $row=campaigns_rewards_campaign_platform_v100($pdo,$campaignId);
        $merchantId=(int)($row['merchant_id']??0);
        if(!$row||$merchantId<1||(int)($row['merchant_owner_user_id']??0)!==$owner
            ||(string)($row['merchant_status']??'')!=='active'
            ||!empty($row['merchant_sandbox_mode'])||(string)($row['environment']??'')!=='production'
            ||!in_array($row['status']??null,['draft','scheduled','active','paused'],true)
            ||!campaigns_rewards_platform_can_v100($pdo,$merchantId,$owner,'campaigns.view')
            ||!campaigns_rewards_platform_can_v100($pdo,$merchantId,$owner,'campaigns.edit')
            ||!campaigns_rewards_platform_can_v100($pdo,$merchantId,$owner,'analytics.view')) {
            throw new DomainException('Campaign creative access is unavailable.',403);
        }
        return $row;
    };
    $campaign=$verify();
    $version=(int)($campaign['current_version_no']??0);
    if($version<0||$version>9007199254740991)throw new DomainException('Campaign version unavailable.',409);
    $choices=[];$linked=null;$available=false;
    if(!function_exists('schema_metadata_table_exists'))require_once __DIR__.'/schema-metadata.php';
    if(schema_metadata_table_exists($pdo,'vp3_coding_canvases_v1')
        &&schema_metadata_table_exists($pdo,'campaign_creative_assets')) {
        if(!function_exists('vp3_marketing_canvas_choices_v1'))require_once __DIR__.'/agent-marketing-canvas-link-v1.php';
        try {
            foreach(array_slice(vp3_marketing_canvas_choices_v1($pdo,$scope),0,24) as $candidate) {
                if(!is_array($candidate)||!is_string($candidate['id']??null)
                    ||!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$candidate['id'])
                    ||!is_int($candidate['version']??null)||$candidate['version']<1
                    ||!is_string($candidate['proof']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$candidate['proof']))continue;
                $choices[]=[
                    'id'=>$candidate['id'],'version'=>$candidate['version'],
                    'title'=>mb_substr((string)($candidate['title']??'Saved Canvas'),0,130),
                    'attached'=>($candidate['attached']??false)===true,
                    'proof'=>$candidate['proof'],
                ];
            }
            $artifact=vp3_marketing_canvas_artifact_v1($pdo,$scope,$campaignId,$version);
            if(is_array($artifact)&&in_array($artifact['state']??null,['linked','unavailable'],true)) {
                $linked=['state'=>$artifact['state'],'label'=>mb_substr((string)($artifact['label']??'Private Canvas'),0,180)];
            }
            $available=true;
        }catch(DomainException|RuntimeException $error) {
            if($error instanceof PDOException || !in_array((int)$error->getCode(),[403,404,409,422,503],true))throw $error;
            $choices=[];$linked=null;
        }
    }
    // Race fence: current Merchant and campaign version, Agent and Chat authority
    // must still match after reading any saved creative or media attachment proof.
    if(vp3_coordination_marketing_owner_v1($pdo,$scope)!==$owner) {
        throw new DomainException('Marketing access changed. Reopen Chat.',403);
    }
    $fresh=$verify();
    if((int)$fresh['current_version_no']!==$version) {
        throw new DomainException('Campaign version changed. Refresh creative selections.',409);
    }
    return ['creative'=>[
        'campaign'=>['id'=>$campaignId,'title'=>mb_substr((string)($fresh['name']??''),0,160),
            'version'=>$version],
        'choices'=>$choices,
        'linked'=>$linked,
        'coverage'=>$available?'ready':'unavailable',
        'link_endpoint'=>$available&&$choices ? url('/api/marketing-canvas-link-v1.php') : null,
    ]];
}
