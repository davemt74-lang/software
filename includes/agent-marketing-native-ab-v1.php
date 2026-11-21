<?php
declare(strict_types=1);

/**
 * Phase 2B.6G: owner-private view of EXISTING Campaign V1.22 journey message
 * variant counts. Per-message lifetime telemetry, NOT a randomized experiment
 * outcome ledger, unique audiences, significance, holdout lift, or causal proof.
 */
const VP3_MARKETING_NATIVE_AB_V1='vp3.marketing-native-ab-observation.v1';

function vp3_marketing_native_ab_project_v1(array $groups):array
{
    $result=[];
    foreach(array_slice($groups,0,12) as $group){
        if(!is_array($group))continue;
        $journey=$group['journey_key']??null;$step=$group['step_key']??null;
        if(!is_string($journey)||!is_string($step)
            ||preg_match('/\A[a-z0-9][a-z0-9_-]{0,49}\z/D',$journey)!==1
            ||preg_match('/\A[a-z0-9][a-z0-9_-]{0,49}\z/D',$step)!==1)continue;
        $variants=[];$seen=[];
        foreach((array)($group['variants']??[]) as $variant){
            if(!is_array($variant))continue;
            $key=$variant['variant_key']??null;
            if(!is_string($key)||preg_match('/\A[a-z0-9][a-z0-9_-]{0,29}\z/D',$key)!==1
                ||isset($seen[$key])||($variant['journey_key']??null)!==$journey
                ||($variant['step_key']??null)!==$step)continue;
            $counts=[];$valid=true;
            foreach(['sent','viewed','converted'] as $name){
                $value=$variant[$name]??null;
                if(!is_int($value)||$value<0||$value>9007199254740991){$valid=false;break;}
                $counts[$name]=$value;
            }
            if(!$valid||$counts['viewed']>$counts['sent']||$counts['converted']>$counts['sent'])continue;
            $seen[$key]=true;
            $variants[]=['variant_key'=>$key]+$counts;
        }
        if(count($variants)<2||count($variants)>4)continue;
        usort($variants,static fn(array $a,array $b)=>strcmp($a['variant_key'],$b['variant_key']));
        $sampleReady=true;
        foreach($variants as $variant)if($variant['sent']<20)$sampleReady=false;
        $result[]=['journey_key'=>$journey,'step_key'=>$step,
            'state'=>$sampleReady?'observed':'insufficient_sample',
            'variants'=>$variants,'unit'=>'journey_message_deliveries',
            'scope'=>'lifetime_message_level_descriptive_not_causal'];
        if(count($result)>=3)break;
    }
    return ['contract'=>VP3_MARKETING_NATIVE_AB_V1,
        'state'=>$result?'observed':'no_comparable_variants',
        'groups'=>$result,'limited'=>count($groups)>12,
        'scope'=>'lifetime_message_level_descriptive_not_causal'];
}

function vp3_marketing_native_ab_read_v1(PDO $pdo,int $owner,int $campaign):array
{
    $empty=['contract'=>VP3_MARKETING_NATIVE_AB_V1,'state'=>'unavailable',
        'groups'=>[],'limited'=>false,'scope'=>'lifetime_message_level_descriptive_not_causal'];
    if($owner<1||$campaign<1||!function_exists('schema_metadata_table_exists')
        ||!function_exists('campaigns_rewards_platform_can_v100')
        ||!function_exists('campaigns_rewards_owner_plugin_enabled_v100')
        ||!function_exists('campaigns_rewards_ab_comparison_v122'))return $empty;
    foreach(['campaigns','merchant_accounts','campaign_deliveries','campaign_messages'] as $table)
        if(!schema_metadata_table_exists($pdo,$table))return $empty;
    $q=$pdo->prepare("SELECT c.merchant_id FROM campaigns c JOIN merchant_accounts m ON m.id=c.merchant_id
        WHERE c.id=? AND m.owner_user_id=? AND m.status='active' AND m.sandbox_mode=0
          AND c.environment='production' LIMIT 1");
    $q->execute([$campaign,$owner]);$merchant=(int)$q->fetchColumn();
    if($merchant<1||!campaigns_rewards_owner_plugin_enabled_v100($pdo,$owner)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.view')
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'analytics.view'))return $empty;
    // V1.22 materializes every delivery row: refuse unbounded Chat reads.
    $count=$pdo->prepare('SELECT COUNT(*) FROM campaign_deliveries WHERE campaign_id=?');
    $count->execute([$campaign]);
    if((int)$count->fetchColumn()>2000)return $empty;
    try{$source=campaigns_rewards_ab_comparison_v122($pdo,$campaign,$owner);}
    catch(Throwable){return $empty;}
    if(!campaigns_rewards_owner_plugin_enabled_v100($pdo,$owner)
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'campaigns.view')
        ||!campaigns_rewards_platform_can_v100($pdo,$merchant,$owner,'analytics.view'))return $empty;
    return vp3_marketing_native_ab_project_v1($source);
}
