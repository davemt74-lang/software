<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-runtime-v1.php';
require_once __DIR__.'/agent-initiative-followthrough-v1.php';
require_once __DIR__.'/agent-initiative-media-image-source-v1.php';

/** Favor the latest useful qualified play. Admit one concept and skip repeated items. */
function vp3_initiative_schedule_media_images_v1(PDO $pdo,array $user,array $binding,?DateTimeImmutable $now=null): string
{
    if($pdo->inTransaction()||($binding['enabled']??false)!==true||vp3_participant_scope_v1()!==null)return 'blocked';
    if(!agent_job_engine_schema_ready_v1900($pdo))return 'not_ready';
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $agent=vp3_initiative_binding_agent_v1($binding);
    return vp3_initiative_in_scope_v1($user,$agent,static function()use($pdo,$user,$binding,$now,$agent):string{
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        $owner=(int)$live['id'];
        $principal=vp3_initiative_principal_v1($pdo,$live,$agent);
        $cid=$binding['conversation_id'];
        if(vp3_initiative_runtime_runs_v1($pdo,$owner,$agent,$binding,pending:true,opportunityLane:'media_images'))return 'already_handled';
        $coverage=[];
        $candidates=vp3_initiative_media_image_evidence_v1($pdo,$live,$principal,$cid,$now,null,$coverage);
        if(!$candidates)return ($coverage['media_images']??null)==='unavailable'?'failed':'no_opportunity';

        return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$live,$binding,$candidates,$now,$owner,$agent,$cid):string{
            vp3_initiative_owner_lock_v1($pdo,$live);
            $fresh=vp3_initiative_access_v1($pdo,$live,$binding);
            if(vp3_initiative_runtime_runs_v1($pdo,$owner,$agent,$binding,pending:true,opportunityLane:'media_images'))return 'already_handled';
            $principal=vp3_initiative_principal_v1($pdo,$fresh,$agent);
            $proof=[];
            $current=vp3_initiative_media_image_evidence_v1($pdo,$fresh,$principal,$cid,$now,array_keys($candidates),$proof);
            $handled=false;
            foreach($candidates as $ref=>$pick){
                if(($current[$ref]['revision']??null)!==$pick['revision'])continue;
                $sources=[$ref=>$pick['revision']];
                $hash=hash('sha256','media-image-concept-v1|'.$pick['asset']);
                $work=['binding'=>$binding,'sources'=>$sources,'canonical_sources'=>$sources,'capability_sources'=>[],
                    'tool'=>'image.prepare','primary'=>$ref,'report'=>true,'opportunity_lane'=>'media_images',
                    'focus'=>'Prepare an original visual concept inspired by this verified play for private review.'];
                if(vp3_initiative_enqueue_v1($pdo,$fresh,'initiative.prepare',$work,$hash))return 'queued';
                $handled=true;
            }
            if(($proof['media_images']??null)==='unavailable')return 'failed';
            return $handled?'already_handled':'no_opportunity';
        });
    });
}
