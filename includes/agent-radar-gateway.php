<?php
declare(strict_types=1);

const VP3_RADAR_GATEWAY_ACTIONS = ['allow','monitor','limit','block'];
const VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M = 30;
const VP3_RADAR_GATEWAY_CLASSES = ['ai_user_agent','ai_search','ai_crawler','automated_unknown'];

function vp3_radar_gateway_path_matches(string $pattern,string $path): bool
{
    $pattern=trim($pattern);
    if($pattern===''||$pattern==='*')return true;
    $path=$path!==''?$path:'/';
    $quoted=preg_quote($pattern,'~');
    $regex='~^'.str_replace('\\*','.*',$quoted).'$~';
    return (bool)preg_match($regex,$path);
}

function vp3_radar_gateway_policy_metadata(?string $json): array
{
    if(!$json)return [];
    $decoded=json_decode($json,true);
    return is_array($decoded)?$decoded:[];
}

function vp3_radar_gateway_policy_specificity(array $policy,array $property,array $contact): int
{
    $score=0;
    if((int)($policy['agent_contact_id']??0)===(int)($contact['id']??0)&&!empty($policy['agent_contact_id']))$score+=1000;
    if((int)($policy['property_id']??0)===(int)($property['id']??0)&&!empty($policy['property_id']))$score+=300;
    if(trim((string)($policy['operator_name']??''))!==''&&strcasecmp((string)$policy['operator_name'],(string)($contact['operator_name']??''))===0)$score+=150;
    if(trim((string)($policy['visitor_class']??''))!==''&&(string)$policy['visitor_class']===(string)($contact['visitor_class']??''))$score+=100;
    $pathPattern=trim((string)($policy['path_pattern']??'*'));
    if($pathPattern!==''&&$pathPattern!=='*')$score+=400+min(80,strlen($pathPattern));
    return $score;
}

function vp3_radar_gateway_decision(PDO $pdo,array $property,array $contact,string $path): array
{
    $owner=(int)($property['owner_user_id']??0);
    $propertyId=(int)($property['id']??0);
    $contactId=(int)($contact['id']??0);
    $default=['action'=>'monitor','allowed'=>true,'status_code'=>200,'policy_id'=>null,'limit_30m'=>null,'reason'=>'default_monitor'];
    if($owner<1||$propertyId<1||$contactId<1||!vp3_radar_schema_ready($pdo))return $default;

    $stmt=$pdo->prepare("SELECT * FROM vp3_agent_policies
      WHERE owner_user_id=? AND is_active=1
        AND (expires_at IS NULL OR expires_at>NOW())
        AND (property_id IS NULL OR property_id=?)
        AND (agent_contact_id IS NULL OR agent_contact_id=?)
        AND (operator_name='' OR LOWER(operator_name)=LOWER(?))
        AND (visitor_class='' OR visitor_class=?)
      ORDER BY priority ASC,id DESC LIMIT 100");
    $stmt->execute([$owner,$propertyId,$contactId,(string)($contact['operator_name']??''),(string)($contact['visitor_class']??'')]);
    $best=null;$bestScore=-1;
    foreach($stmt->fetchAll()?:[] as $policy){
        if(!vp3_radar_gateway_path_matches((string)($policy['path_pattern']??'*'),$path))continue;
        $score=vp3_radar_gateway_policy_specificity($policy,$property,$contact);
        if($score>$bestScore){$best=$policy;$bestScore=$score;continue;}
        if($score===$bestScore&&$best!==null&&(int)$policy['priority']<(int)$best['priority'])$best=$policy;
    }
    if(!$best)return $default;

    $action=strtolower(trim((string)$best['action']));
    if(!in_array($action,VP3_RADAR_GATEWAY_ACTIONS,true))$action='monitor';
    $metadata=vp3_radar_gateway_policy_metadata((string)($best['metadata_json']??''));
    $limit=null;
    if($action==='limit')$limit=max(1,min(10000,(int)($metadata['requests_per_30m']??VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M)));
    return [
        'action'=>$action,
        'allowed'=>$action!=='block',
        'status_code'=>$action==='block'?403:200,
        'policy_id'=>(int)$best['id'],
        'limit_30m'=>$limit,
        'reason'=>'policy_match',
    ];
}

function vp3_radar_gateway_session_request_count(PDO $pdo,int $propertyId,int $contactId): int
{
    if($propertyId<1||$contactId<1)return 0;
    $key=vp3_radar_external_session_key($propertyId,$contactId);
    $stmt=$pdo->prepare('SELECT request_count FROM vp3_radar_sessions WHERE property_id=? AND session_key=? LIMIT 1');
    $stmt->execute([$propertyId,$key]);
    $count=$stmt->fetchColumn();
    return $count===false?0:(int)$count;
}

function vp3_radar_gateway_apply_limit(PDO $pdo,array $property,array $contact,array $decision): array
{
    if(($decision['action']??'')!=='limit')return $decision;
    $limit=max(1,(int)($decision['limit_30m']??VP3_RADAR_GATEWAY_DEFAULT_LIMIT_30M));
    $used=vp3_radar_gateway_session_request_count($pdo,(int)$property['id'],(int)$contact['id']);
    $decision['used_30m']=$used;
    $decision['remaining_30m']=max(0,$limit-$used);
    if($used>=$limit){
        $decision['allowed']=false;
        $decision['status_code']=429;
        $decision['reason']='rate_limit_reached';
        $decision['retry_after']=1800;
    }
    return $decision;
}

function vp3_radar_gateway_log_denial(PDO $pdo,array $property,array $contact,string $path,string $method,array $decision): void
{
    $owner=(int)($property['owner_user_id']??0);
    if($owner<1)return;
    $action=(string)($decision['action']??'block');
    $status=(int)($decision['status_code']??403);
    $risk=max(40,(int)($contact['risk_score']??0));
    $severity=$risk>=70?'high':'medium';
    $name=trim((string)($contact['display_name']??''))?:'Automated agent';
    $summary=$name.' was '.($status===429?'rate limited':'blocked').' by Agent Gateway on '.(string)$property['domain'].$path.'.';
    $details=[
        'gateway_action'=>$action,
        'policy_id'=>$decision['policy_id']??null,
        'reason'=>$decision['reason']??'policy_match',
        'limit_30m'=>$decision['limit_30m']??null,
        'used_30m'=>$decision['used_30m']??null,
    ];
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_events
      (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
      VALUES (?,?,NULL,?,'agent_policy_blocked',?,?,?,?,90,?,?,?,NOW())");
    $stmt->execute([$owner,(int)$property['id'],(int)$contact['id'],$severity,$path,$method,$status,$risk,mb_strimwidth($summary,0,500,'…'),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $eventId=(int)$pdo->lastInsertId();
    create_notification(
        $owner,
        $status===429?'agent_activity_radar_rate_limited':'radar_security_action',
        'Agent Gateway · '.($status===429?'Rate limited':'Blocked').' · '.$name,
        $summary,
        url('/profile-agent.php?tab=radar'),
        'radar_event',
        $eventId
    );
}

function vp3_radar_gateway_contact_policy(PDO $pdo,int $ownerUserId,int $contactId): ?array
{
    if($ownerUserId<1||$contactId<1)return null;
    $stmt=$pdo->prepare("SELECT id,action,priority,expires_at,metadata_json,updated_at
      FROM vp3_agent_policies
      WHERE owner_user_id=? AND agent_contact_id=? AND property_id IS NULL
        AND operator_name='' AND visitor_class='' AND path_pattern='*' AND is_active=1
        AND (expires_at IS NULL OR expires_at>NOW())
      ORDER BY priority ASC,id DESC LIMIT 1");
    $stmt->execute([$ownerUserId,$contactId]);
    $row=$stmt->fetch();
    if(!$row)return null;
    $row['metadata']=vp3_radar_gateway_policy_metadata((string)($row['metadata_json']??''));
    unset($row['metadata_json']);
    return $row;
}

function vp3_radar_gateway_set_contact_policy(PDO $pdo,array $user,int $contactId,string $action,int $limit30m=30): array
{
    $owner=(int)($user['id']??0);
    $action=strtolower(trim($action));
    if($owner<1||$contactId<1)throw new RuntimeException('Agent contact not found.');
    if(!in_array($action,VP3_RADAR_GATEWAY_ACTIONS,true))throw new RuntimeException('Choose Allow, Monitor, Limit or Block.');
    $contact=$pdo->prepare('SELECT id,display_name FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');
    $contact->execute([$contactId,$owner]);
    $contactRow=$contact->fetch();
    if(!$contactRow)throw new RuntimeException('Agent contact not found.');
    $metadata=$action==='limit'?json_encode(['requests_per_30m'=>max(1,min(10000,$limit30m))],JSON_UNESCAPED_SLASHES):null;
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE vp3_agent_policies SET is_active=0,updated_at=NOW()
          WHERE owner_user_id=? AND agent_contact_id=? AND property_id IS NULL AND operator_name='' AND visitor_class='' AND path_pattern='*' AND is_active=1")
          ->execute([$owner,$contactId]);
        $stmt=$pdo->prepare("INSERT INTO vp3_agent_policies
          (owner_user_id,property_id,agent_contact_id,operator_name,visitor_class,path_pattern,action,priority,expires_at,metadata_json,is_active)
          VALUES (?,NULL,?,'','','*',?,10,NULL,?,1)");
        $stmt->execute([$owner,$contactId,$action,$metadata]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return [
        'contact_id'=>$contactId,
        'display_name'=>(string)$contactRow['display_name'],
        'policy'=>vp3_radar_gateway_contact_policy($pdo,$owner,$contactId),
    ];
}

function vp3_radar_gateway_contact_policy_map(PDO $pdo,int $ownerUserId,array $contactIds): array
{
    $map=[];
    foreach(array_values(array_unique(array_filter(array_map('intval',$contactIds),static fn(int $id):bool=>$id>0))) as $id){
        $policy=vp3_radar_gateway_contact_policy($pdo,$ownerUserId,$id);
        if($policy)$map[$id]=$policy;
    }
    return $map;
}

function vp3_radar_gateway_scope_value(string $scopeType,string $value): string
{
    $value=trim($value);
    if($scopeType==='operator'){
        $value=preg_replace('/\s+/',' ',$value)??$value;
        return mb_strimwidth($value,0,120,'');
    }
    if($scopeType==='class')return in_array($value,VP3_RADAR_GATEWAY_CLASSES,true)?$value:'';
    if($scopeType==='path'){
        if($value===''||$value[0]!=='/')return '';
        $value=preg_replace('/[?#].*$/','',$value)??$value;
        return mb_strimwidth($value,0,500,'');
    }
    return '';
}

function vp3_radar_gateway_scoped_rules(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1)return [];
    $stmt=$pdo->prepare("SELECT p.id,p.property_id,p.operator_name,p.visitor_class,p.path_pattern,p.action,p.priority,p.expires_at,p.metadata_json,p.updated_at,r.label AS property_label,r.domain AS property_domain
      FROM vp3_agent_policies p LEFT JOIN vp3_radar_properties r ON r.id=p.property_id
      WHERE p.owner_user_id=? AND p.agent_contact_id IS NULL AND p.is_active=1
        AND p.metadata_json LIKE '%\"source\":\"scoped_rule\"%'
        AND (p.expires_at IS NULL OR p.expires_at>NOW())
      ORDER BY p.updated_at DESC,p.id DESC LIMIT 100");
    $stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $meta=vp3_radar_gateway_policy_metadata((string)$row['metadata_json']);
        $row['scope_type']=(string)($meta['scope_type']??'');
        $row['scope_value']=(string)($meta['scope_value']??'');
        $row['limit_30m']=(int)($meta['requests_per_30m']??0);
        unset($row['metadata_json']);$out[]=$row;
    }
    return $out;
}

function vp3_radar_gateway_set_scoped_rule(PDO $pdo,array $user,string $scopeType,string $scopeValue,string $action,int $propertyId=0,int $limit30m=30): array
{
    $owner=(int)($user['id']??0);$scopeType=strtolower(trim($scopeType));$action=strtolower(trim($action));
    if($owner<1)throw new RuntimeException('Sign in to manage Agent Gateway.');
    if(!in_array($scopeType,['operator','class','path'],true))throw new RuntimeException('Choose Operator, Agent Class or Path.');
    if(!in_array($action,VP3_RADAR_GATEWAY_ACTIONS,true))throw new RuntimeException('Choose Allow, Monitor, Limit or Block.');
    $scopeValue=vp3_radar_gateway_scope_value($scopeType,$scopeValue);
    if($scopeValue==='')throw new RuntimeException($scopeType==='path'?'Paths must start with /.':'Enter a valid rule target.');
    if($propertyId>0){
        $property=vp3_radar_server_property_for_owner($pdo,$owner,$propertyId);
        if(!$property)throw new RuntimeException('Connected site not found.');
    }
    $operator=$scopeType==='operator'?$scopeValue:'';
    $class=$scopeType==='class'?$scopeValue:'';
    $path=$scopeType==='path'?$scopeValue:'*';
    $limit30m=max(1,min(10000,$limit30m));
    $meta=['source'=>'scoped_rule','scope_type'=>$scopeType,'scope_value'=>$scopeValue];
    if($action==='limit')$meta['requests_per_30m']=$limit30m;
    $json=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $pdo->beginTransaction();
    try{
        $deactivate=$pdo->prepare("UPDATE vp3_agent_policies SET is_active=0,updated_at=NOW()
          WHERE owner_user_id=? AND agent_contact_id IS NULL AND COALESCE(property_id,0)=? AND operator_name=? AND visitor_class=? AND path_pattern=?
            AND metadata_json LIKE '%\"source\":\"scoped_rule\"%' AND is_active=1");
        $deactivate->execute([$owner,$propertyId,$operator,$class,$path]);
        $insert=$pdo->prepare("INSERT INTO vp3_agent_policies
          (owner_user_id,property_id,agent_contact_id,operator_name,visitor_class,path_pattern,action,priority,expires_at,metadata_json,is_active)
          VALUES (?,?,NULL,?,?,?,?,50,NULL,?,1)");
        $insert->execute([$owner,$propertyId>0?$propertyId:null,$operator,$class,$path,$action,$json]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['rules'=>vp3_radar_gateway_scoped_rules($pdo,$owner)];
}

function vp3_radar_gateway_delete_scoped_rule(PDO $pdo,array $user,int $policyId): array
{
    $owner=(int)($user['id']??0);if($owner<1||$policyId<1)throw new RuntimeException('Gateway rule not found.');
    $stmt=$pdo->prepare("UPDATE vp3_agent_policies SET is_active=0,updated_at=NOW() WHERE id=? AND owner_user_id=? AND agent_contact_id IS NULL AND metadata_json LIKE '%\"source\":\"scoped_rule\"%' AND is_active=1");
    $stmt->execute([$policyId,$owner]);
    if($stmt->rowCount()<1)throw new RuntimeException('Gateway rule not found.');
    return ['rules'=>vp3_radar_gateway_scoped_rules($pdo,$owner)];
}

function vp3_radar_gateway_server_collect(PDO $pdo,array $property,array $payload): array
{
    $userAgent=mb_strimwidth(trim((string)($payload['user_agent']??'')),0,1000,'');
    $identity=vp3_radar_server_identity($pdo,$property,$userAgent);
    if(!$identity){
        return ['action'=>'ignore','allowed'=>true,'status_code'=>200,'policy_id'=>null,'reason'=>'not_automated','recorded'=>false];
    }
    $contact=$identity['contact'];
    $path=vp3_radar_external_path((string)($payload['path']??'/'));
    $method=vp3_radar_server_method((string)($payload['method']??'GET'));
    $decision=vp3_radar_gateway_decision($pdo,$property,$contact,$path);
    $decision=vp3_radar_gateway_apply_limit($pdo,$property,$contact,$decision);
    $decision['contact_id']=(int)$contact['id'];
    $decision['contact_name']=(string)$contact['display_name'];
    $decision['recorded']=false;

    if(empty($decision['allowed'])){
        vp3_radar_gateway_log_denial($pdo,$property,$contact,$path,$method,$decision);
        return $decision;
    }

    $decision['recorded']=vp3_radar_server_collect($pdo,$property,$payload);
    if(($decision['action']??'')==='limit'){
        $decision['used_30m']=((int)($decision['used_30m']??0))+($decision['recorded']?1:0);
        $decision['remaining_30m']=max(0,(int)$decision['limit_30m']-(int)$decision['used_30m']);
    }
    return $decision;
}