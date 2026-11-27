<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-followthrough-v1.php';

const VP3_INITIATIVE_OPPORTUNITY_LANES_V1=['app_suggestions','research','missions','media_images','curiosity_analysis','brainstorm'];
const VP3_INITIATIVE_OPPORTUNITY_INTERVAL_V1=120;
const VP3_INITIATIVE_OPPORTUNITY_STATES_V1=['checking','queued','already_handled','no_opportunity','not_ready','blocked','failed'];

/** Only fixed failure codes and bounded SQL metadata belong in a diagnostic checkpoint. */
function vp3_initiative_opportunity_failure_v1(mixed $value): ?array
{
    if(!is_array($value)||!in_array($value['phase']??null,['opportunity_check','source_check'],true)
        ||!in_array($value['code']??null,['storage_error','storage_schema','storage_contention','storage_duplicate',
            'invalid_internal_type','invalid_data','access_changed','state_changed','verification_unavailable','code_error','unconfirmed'],true))return null;
    $failure=['phase'=>$value['phase'],'code'=>$value['code']];
    $sqlState=$value['sqlstate']??null;
    $databaseCode=$value['database_code']??null;
    if(is_string($sqlState)&&preg_match('/\A[A-Z0-9]{5}\z/D',$sqlState))$failure['sqlstate']=$sqlState;
    if(is_int($databaseCode)&&$databaseCode>0&&$databaseCode<=65535)$failure['database_code']=$databaseCode;
    return $failure;
}

function vp3_initiative_opportunity_checkpoint_v1(array $user,array $policy): array
{
    $saved=agent_brain_v122_memory($user,'initiative_sweep','opportunity-scheduling')['metadata']??[];
    if(($saved['conversation_id']??null)!==$policy['conversation_id']
        ||($saved['revision']??null)!==$policy['revision'])return [];
    $lanes=[];
    foreach(VP3_INITIATIVE_OPPORTUNITY_LANES_V1 as $lane){
        $entry=$saved['lanes'][$lane]??null;
        if(!is_int($entry['checked_at']??null)||$entry['checked_at']<1||$entry['checked_at']>time()
            ||!in_array($entry['state']??null,VP3_INITIATIVE_OPPORTUNITY_STATES_V1,true))continue;
        $lanes[$lane]=['checked_at'=>$entry['checked_at'],'state'=>$entry['state']];
        if($entry['state']==='failed'&&($failure=vp3_initiative_opportunity_failure_v1($entry['failure']??null))!==null){
            $lanes[$lane]['failure']=$failure;
        }
    }
    return $lanes;
}

function vp3_initiative_opportunity_save_v1(array $user,array $policy,array $lanes): void
{
    if(agent_brain_v122_upsert_system_memory($user,'initiative_sweep','opportunity-scheduling','Initiative opportunity checks.',[
        'conversation_id'=>$policy['conversation_id'],'revision'=>$policy['revision'],'lanes'=>$lanes,
    ])<1)throw new RuntimeException('The opportunity check could not be recorded.',503);
}

/** Reserve at most one due check. Source discovery and worker execution run outside this reservation. */
function vp3_initiative_schedule_opportunities_v1(PDO $pdo,array $user,array $policy): void
{
    if($pdo->inTransaction())return;
    $now=time();
    $lanes=vp3_initiative_opportunity_checkpoint_v1($user,$policy);
    if(count($lanes)===count(VP3_INITIATIVE_OPPORTUNITY_LANES_V1)
        &&min(array_column($lanes,'checked_at'))>$now-VP3_INITIATIVE_OPPORTUNITY_INTERVAL_V1)return;
    $lane=null;
    try{
        $lane=vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$policy,$now):?string{
            vp3_initiative_owner_lock_v1($pdo,$user);
            $live=vp3_initiative_access_v1($pdo,$user,$policy);
            $lanes=vp3_initiative_opportunity_checkpoint_v1($live,$policy);
            $oldest=null;
            foreach(VP3_INITIATIVE_OPPORTUNITY_LANES_V1 as $id){
                $checked=$lanes[$id]['checked_at']??0;
                if($checked<=$now-VP3_INITIATIVE_OPPORTUNITY_INTERVAL_V1
                    &&($oldest===null||$checked<($lanes[$oldest]['checked_at']??0)))$oldest=$id;
            }
            if($oldest===null)return null;
            $lanes[$oldest]=['checked_at'=>$now,'state'=>'checking'];
            vp3_initiative_opportunity_save_v1($live,$policy,$lanes);
            return $oldest;
        });
    }catch(Throwable $error){
        vp3_initiative_opportunity_trace_v1($user,$lane,$error);
        return;
    }
    if($lane===null)return;
    $failure=null;
    try{
        $laneFile=match($lane){
            'app_suggestions'=>'app-suggestions-schedule-v1.php',
            'research'=>'research-schedule-v1.php',
            'missions'=>'mission-schedule-v1.php',
            'media_images'=>'media-image-schedule-v1.php',
            'brainstorm'=>'brainstorm-specialist-v1.php',
            'curiosity_analysis'=>'curiosity-analysis-v1.php',
        };
        require_once __DIR__.'/agent-initiative-'.$laneFile;
        $state=match($lane){
            'app_suggestions'=>vp3_initiative_schedule_app_suggestions_v1($pdo,$user,$policy),
            'research'=>vp3_initiative_schedule_research_v1($pdo,$user,$policy),
            'missions'=>vp3_initiative_schedule_missions_v1($pdo,$user,$policy),
            'media_images'=>vp3_initiative_schedule_media_images_v1($pdo,$user,$policy),
            'brainstorm'=>vp3_brainstorm_schedule_v1($pdo,$user,$policy),
            'curiosity_analysis'=>vp3_curiosity_analysis_schedule_v1($pdo,$user,$policy),
        };
        if(!in_array($state,VP3_INITIATIVE_OPPORTUNITY_STATES_V1,true)||$state==='checking'){
            $state='failed';
            $failure=['phase'=>'opportunity_check','code'=>'invalid_data'];
        }elseif($state==='failed'){
            $failure=['phase'=>'source_check','code'=>'verification_unavailable'];
        }
    }catch(Throwable $error){
        $state='failed';
        $failure=vp3_initiative_opportunity_failure_v1(vp3_initiative_coordination_failure_v1($error,'opportunity_check'));
        vp3_initiative_opportunity_trace_v1($user,$lane,$error);
    }
    try{
        vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$policy,$lane,$now,$state,$failure):void{
            vp3_initiative_owner_lock_v1($pdo,$user);
            $live=vp3_initiative_access_v1($pdo,$user,$policy);
            $lanes=vp3_initiative_opportunity_checkpoint_v1($live,$policy);
            if(($lanes[$lane]['checked_at']??null)!==$now)return;
            $lanes[$lane]['state']=$state;
            if($failure!==null)$lanes[$lane]['failure']=$failure;
            vp3_initiative_opportunity_save_v1($live,$policy,$lanes);
        });
    }catch(Throwable $error){
        vp3_initiative_opportunity_trace_v1($user,$lane,$error);
    }
}

function vp3_initiative_opportunity_trace_v1(array $user,?string $lane,Throwable $error): void
{
    if(function_exists('agent_runtime_v125_trace')){
        agent_runtime_v125_trace('initiative.opportunity_check_failed',[
            'user_id'=>(int)$user['id'],'lane'=>$lane,'error_class'=>get_class($error),
        ]);
    }
}
