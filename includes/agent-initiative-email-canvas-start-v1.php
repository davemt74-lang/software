<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-initiative-canvas-v1.php';
require_once __DIR__.'/agent-initiative-email-canvas-v1.php';
require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';

/** The current linked grant signs the immutable owner-started preparation. */
function vp3_initiative_email_canvas_start_proof_v1(array $scope, array $goal, string $requestId): string
{
    unset($goal['owner_canvas_start']);
    return agent_connection_gmail_inbox_key_v1($scope['policy'],
        'owner-email-canvas-start-v1:'.json_encode([$requestId,$goal], JSON_THROW_ON_ERROR));
}

function vp3_initiative_email_canvas_start_verify_v1(PDO $pdo, array $user, array $goal): bool
{
    $start=$goal['owner_canvas_start']??null;
    $binding=$goal['binding']??null;
    $primary=$goal['primary']??null;
    if(!is_array($start)||array_keys($start)!==['request_id','proof']||!is_array($binding)
        ||!is_string($primary)||!preg_match('/\Aemail-canvas:[a-f0-9]{64}\z/D',$primary)
        ||($goal['tool']??null)!=='canvas.prepare'||($goal['report']??null)!==true
        ||!is_array($goal['sources']??null)||array_keys($goal['sources'])!==[$primary]
        ||!is_string($start['request_id'])||!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$start['request_id'])
        ||!is_string($start['proof'])||!preg_match('/\A[a-f0-9]{64}\z/D',$start['proof']))return false;
    $scope=vp3_initiative_specialist_gmail_scope_v1($pdo,$user,$binding);
    return $scope!==null&&hash_equals(vp3_initiative_email_canvas_start_proof_v1($scope,$goal,$start['request_id']),$start['proof']);
}

/** Read the exact linked workflow before offering the owner control. */
function vp3_initiative_email_canvas_start_available_v1(PDO $pdo, array $user, int $aid, int $cid): bool
{
    if($cid<1)return false;
    $binding=vp3_initiative_policy_v1($user,$aid);
    if(($binding['enabled']??false)!==true||($binding['conversation_id']??null)!==$cid)return false;
    $user=vp3_initiative_access_v1($pdo,$user,$binding);
    vp3_coding_canvas_authority_v1($pdo,$user,$aid,true);
    $ref=array_key_first(vp3_initiative_email_canvas_evidence_v1($pdo,$user,$binding,null,true));
    return $ref!==null&&vp3_initiative_email_canvas_attempt_key_v1($pdo,(int)$user['id'],$binding,$ref)!==null;
}

function vp3_initiative_email_canvas_start_replay_v1(PDO $pdo, int $owner, array $binding, string $workKey, string $requestId): ?array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $value=static function(string $path)use($sqlite):string{
        $sql="JSON_EXTRACT(CASE WHEN JSON_VALID(goal) THEN goal ELSE '{}' END,'$path')";
        return $sqlite?$sql:"JSON_UNQUOTE($sql)";
    };
    $lookup=$pdo->prepare("SELECT id,status,goal FROM agent_workflow_runs WHERE owner_user_id=?
        AND source_kind='agent_initiative' AND capability_key='initiative.prepare'
        AND source_key=? AND ".$value('$.owner_canvas_start.request_id')."=?
        ORDER BY id DESC LIMIT 4");
    $lookup->execute([$owner,$workKey,$requestId]);
    foreach($lookup->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true,16,JSON_THROW_ON_ERROR);
        if(($goal['binding']??null)===$binding)return ['preparation_run_id'=>(int)$row['id'],'status'=>(string)$row['status']];
    }
    return null;
}

/** Queue a durable preparation after an explicit owner action in the Canvas host. */
function vp3_initiative_email_canvas_start_v1(PDO $pdo, array $user, int $aid, int $cid, string $requestId): array
{
    $requestId=vp3_coding_canvas_id_v1($requestId);
    $user=vp3_coding_canvas_scope_v1($pdo,$user,$aid,$cid);
    vp3_coding_canvas_authority_v1($pdo,$user,$aid,true);
    $binding=vp3_initiative_policy_v1($user,$aid);
    if($cid<1||($binding['enabled']??false)!==true||($binding['conversation_id']??null)!==$cid){
        throw new RuntimeException('Open the Chat linked to Initiative and Gmail.',403);
    }
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$binding,$requestId):array{
        vp3_initiative_owner_lock_v1($pdo,$user);
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        $scope=vp3_initiative_specialist_gmail_scope_v1($pdo,$live,$binding);
        $evidence=$scope===null?[]:vp3_initiative_email_canvas_evidence_v1($pdo,$live,$binding,null,true);
        $source=$evidence===[]?null:reset($evidence);
        if($source===null)throw new RuntimeException('There is no current verified email deadline workflow to build from.',409);
        $ref=$source['ref'];
        $owner=(int)$live['id'];
        $workKey=vp3_initiative_email_canvas_work_key_v1($ref);
        $replay=vp3_initiative_email_canvas_start_replay_v1($pdo,$owner,$binding,$workKey,$requestId);
        if($replay!==null)return $replay;
        $hash=vp3_initiative_email_canvas_attempt_key_v1($pdo,$owner,$binding,$ref);
        if($hash===null)throw new RuntimeException('There is already an email deadline board request in this Chat. Review it in Chat or Top of Mind.',409);
        $ready=vp3_initiative_canvas_readiness_v1($pdo,$live,$binding);
        if(!$ready['ready']){
            $code=match($ready['reason']){'busy'=>409,'budget_exhausted'=>402,'forbidden'=>403,default=>503};
            throw new RuntimeException($code===409?'The Coding Agent is already working on a draft.':'The Coding Agent is unavailable for this Chat.',$code);
        }
        $goal=['binding'=>$binding,'sources'=>[$ref=>$source['revision']],
            'canonical_sources'=>[$ref=>$source['revision']],'capability_sources'=>[],
            'tool'=>'canvas.prepare','primary'=>$ref,'focus'=>'Build the private email deadline board for the verified workflow.','report'=>true,
            'email_canvas_work_key'=>$workKey];
        $goal['owner_canvas_start']=['request_id'=>$requestId,'proof'=>vp3_initiative_email_canvas_start_proof_v1($scope,$goal,$requestId)];
        vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',$goal,$hash);
        $row=vp3_initiative_email_canvas_start_replay_v1($pdo,$owner,$binding,$workKey,$requestId);
        if(!$row)throw new RuntimeException('The Coding Agent preparation could not be queued.',409);
        return $row;
    });
}
