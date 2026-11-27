<?php
declare(strict_types=1);

require_once __DIR__.'/agent-curiosity-v1.php';

/** Saved, historical reviews only: no provider read, byte access or model dispatch on discovery. */
function vp3_curiosity_document_reports_v1(PDO $pdo,array $user,array $binding,?string $exactRef=null): array
{
    if(!table_exists('agent_gmail_document_reviews_v1'))return [];
    require_once __DIR__.'/agent-gmail-documents-v1.php';
    require_once __DIR__.'/agent-initiative-specialist-reports-v1.php';
    if(vp3_initiative_specialist_gmail_scope_v1($pdo,$user,$binding)===null)return [];
    $scope=vp3_curiosity_private_scope_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding),(int)$binding['conversation_id']);
    $args=[(int)$user['id'],$scope['agent_id'],$scope['conversation_id'],time()-30*86400];$where='';
    if($exactRef!==null){
        if(!preg_match('/\Aattachment-review:([a-f0-9]{32})\z/D',$exactRef,$match))return [];
        $where=' AND id=?';$args[]=$match[1];
    }
    $q=$pdo->prepare("SELECT * FROM agent_gmail_document_reviews_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
        AND state='ready' AND updated_epoch>=?$where ORDER BY created_epoch DESC,id DESC LIMIT 48");
    $q->execute($args);
    $out=[];$seen=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        try{
            $origin=agent_gmail_document_origin_v1($pdo,$scope['user'],$scope['principal'],$scope['conversation_id'],$row);
            if($origin===null)continue;
            $s=$origin['source'];$part=$origin['part'];
            $key=agent_gmail_document_hash_v1([$s['connection'],$s['grant'],$s['fingerprint'],$s['account'],$s['message'],$part['part']]);
            if(isset($seen[$key]))continue;
            $seen[$key]=true;
            $review=agent_gmail_document_result_v1(json_encode($origin['review'],JSON_THROW_ON_ERROR),$part['mime']);
            if($review['coverage']==='unreadable')continue;
            $ref='attachment-review:'.$row['id'];
            $out[$ref]=['ref'=>$ref,'document_key'=>$key,'revision'=>agent_gmail_document_hash_v1([$row['source_hash'],$origin['bytes_sha256'],$review]),
                'title'=>vp3_curiosity_safe_text_v1($part['name'],180)?:'Reviewed attachment','coverage'=>$review['coverage'],
                'reviewed_at'=>(int)$row['updated_epoch'],'historical'=>true,
                'summary'=>vp3_curiosity_safe_text_v1($review['summary'],1200),
                'facts'=>array_map(static fn(array $fact):array=>['text'=>vp3_curiosity_safe_text_v1($fact['text'],300),'page'=>$fact['page']],array_slice($review['facts'],0,6)),
                'questions'=>array_map(static fn(string $text):string=>vp3_curiosity_safe_text_v1($text,500),$review['questions']),
                'href'=>url('/chat.php?agent='.$scope['agent_id'].'&conversation_id='.$scope['conversation_id'].'&attachment_review='.$row['id'])];
            if(count($out)>=6)break;
        }catch(PDOException $error){throw $error;}
        catch(RuntimeException|DomainException|JsonException|InvalidArgumentException $error){continue;}
    }
    return $out;
}
