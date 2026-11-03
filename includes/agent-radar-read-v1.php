<?php
declare(strict_types=1);
require_once __DIR__.'/agent-observation-authority-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Probe only existing storage; discovery must not initialize Radar or seed its registry. */
function vp3_radar_read_ready_v1(PDO $pdo): bool
{
    try{
        foreach([
            'vp3_radar_properties'=>'id,owner_user_id,label',
            'vp3_agent_registry'=>'id,slug',
            'vp3_agent_contacts'=>'id,owner_user_id,agent_registry_id,display_name,operator_name,visitor_class,verification_status,risk_score,session_count,page_view_count,last_seen_at',
            'vp3_agent_policies'=>'id,owner_user_id,property_id,agent_contact_id,operator_name,visitor_class,path_pattern,action,priority,expires_at,metadata_json,is_active,updated_at',
            'vp3_radar_sessions'=>'id,owner_user_id',
            'vp3_radar_events'=>'id,owner_user_id',
            'vp3_agent_access_requests'=>'id,owner_user_id',
        ] as $table=>$columns)$pdo->query('SELECT '.$columns.' FROM '.$table.' WHERE 1=0');
        return true;
    }catch(Throwable $e){return false;}
}

function vp3_radar_services_v1(): void
{
    if(!function_exists('vp3_radar_gateway_scope_value'))require_once __DIR__.'/agent-radar-gateway.php';
    if(!function_exists('vp3_radar_access_profile_catalog'))require_once __DIR__.'/agent-radar-access-profiles.php';
}

/** Model-selected reads never accept a writer, another owner, or an arbitrary limit. */
function vp3_radar_plan_v1(array $plan): array
{
    $view=array_key_exists('view',$plan)?$plan['view']:'recent';
    if((array_key_exists('operation',$plan)?$plan['operation']:'radar_read')!=='radar_read'||($plan['ids']??null)!==['radar.read']||
        !in_array($view,['recent','high_risk','contact_policies','scoped_rules','access_profile'],true))throw new InvalidArgumentException('Invalid Radar read plan.');
    $allowed=['operation','ids','view'];
    if(in_array($view,['recent','high_risk','contact_policies'],true))$allowed[]='query';
    if($view==='contact_policies')$allowed[]='contact_id';
    if(array_diff(array_keys($plan),$allowed)||array_key_exists('query',$plan)&&array_key_exists('contact_id',$plan))throw new InvalidArgumentException('Unsupported Radar selector.');
    $out=['operation'=>'radar_read','ids'=>['radar.read'],'view'=>$view];
    if(array_key_exists('query',$plan)){
        if(!is_string($plan['query'])||!mb_check_encoding($plan['query'],'UTF-8')||mb_strlen($plan['query'])>160)throw new InvalidArgumentException('Invalid Radar text filter.');
        $out['query']=trim($plan['query']);
    }
    if(array_key_exists('contact_id',$plan)){
        if(!is_int($plan['contact_id'])||$plan['contact_id']<1)throw new InvalidArgumentException('Invalid Radar contact.');
        $out['contact_id']=$plan['contact_id'];
    }
    return $out;
}

function vp3_radar_authority_v1(PDO $pdo,array $user,array $principal,array $context): array
{
    return vp3_observation_authority_v1($pdo,$user,$principal,$context,static function(array $live)use($pdo):bool{
        return vp3_observation_personal_permission_v1($pdo,$live,'profile_agent.access');
    });
}

function vp3_radar_number_v1(mixed $value,int $min=0,int $max=PHP_INT_MAX): int
{
    if(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)&&strlen($value)<=strlen((string)PHP_INT_MAX)&&
        (strlen($value)<strlen((string)PHP_INT_MAX)||strcmp($value,(string)PHP_INT_MAX)<=0))$value=(int)$value;
    if(!is_int($value)||$value<$min||$value>$max)throw new RuntimeException('Radar numeric evidence is invalid.');
    return $value;
}

function vp3_radar_text_v1(mixed $value,int $max=190): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max)throw new RuntimeException('Radar text evidence is invalid.');
    return $value;
}

function vp3_radar_literal_v1(string $value,int $max=190): string
{
    $value=mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}]+/u',' ',$value)??''),0,$max);
    return str_replace(['\\','`','*','_','[',']','(',')','{','}','<','>','#','|','!'],['\\\\','\\`','\\*','\\_','\\[','\\]','\\(','\\)','\\{','\\}','\\<','\\>','\\#','\\|','\\!'],$value);
}

function vp3_radar_date_v1(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))throw new RuntimeException('Radar observation time is unavailable.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
    if(!$date||$errors!==false&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException('Radar observation time is invalid.');
    return $value;
}

function vp3_radar_metadata_v1(mixed $json): array
{
    if($json===null||$json==='')return [];
    if(!is_string($json)||strlen($json)>65536)throw new RuntimeException('Radar policy metadata is unavailable.');
    $value=json_decode($json,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($value)||!str_starts_with(ltrim($json),'{'))throw new RuntimeException('Radar policy metadata is invalid.');
    return $value;
}

/** The existing policy service returns this one current, unexpired contact override. */
function vp3_radar_contact_policy_v1(PDO $pdo,int $uid,int $contactId): ?array
{
    $q=$pdo->prepare("SELECT p.id,p.action,p.updated_at,SUBSTR(p.metadata_json,1,65537) metadata_json
      FROM vp3_agent_policies p INNER JOIN vp3_agent_contacts c ON c.id=p.agent_contact_id AND c.owner_user_id=p.owner_user_id
      WHERE p.owner_user_id=? AND p.agent_contact_id=? AND p.property_id IS NULL AND p.operator_name='' AND p.visitor_class='' AND p.path_pattern='*'
        AND p.is_active=1 AND (p.expires_at IS NULL OR p.expires_at>NOW()) ORDER BY p.priority ASC,p.id DESC LIMIT 1");
    $q->execute([$uid,$contactId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
    return vp3_radar_policy_v1($row);
}

function vp3_radar_policy_v1(array $row): array
{
    if(!in_array($row['action']??null,VP3_RADAR_GATEWAY_ACTIONS,true))throw new RuntimeException('Radar policy action is invalid.');
    $meta=vp3_radar_metadata_v1($row['metadata_json']??null);
    return ['id'=>vp3_radar_number_v1($row['id']??null,1),'action'=>$row['action'],'limit_30m'=>$row['action']==='limit'?vp3_radar_number_v1($meta['requests_per_30m']??null,1,10000):null,'updated_at'=>vp3_radar_date_v1($row['updated_at']??null)];
}

function vp3_radar_policy_text_v1(?array $policy): string
{
    return $policy===null?'profile default':($policy['action']==='limit'?'limit '.$policy['limit_30m'].' requests per 30 minutes':$policy['action']);
}

function vp3_radar_filter_sql_v1(array $plan,array &$params,string $alias='c'): string
{
    $query=$plan['query']??'';if($query==='')return '';
    $literal='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';$params[]=$literal;$params[]=$literal;
    return " AND ($alias.display_name LIKE ? ESCAPE '!' OR $alias.operator_name LIKE ? ESCAPE '!')";
}

/** Absence is distinct from a malformed or incomplete saved Access Profile. */
function vp3_radar_profile_v1(PDO $pdo,int $uid): ?array
{
    $q=$pdo->prepare("SELECT id,property_id,operator_name,visitor_class,path_pattern,action,priority,expires_at,updated_at,SUBSTR(metadata_json,1,65537) metadata_json
      FROM vp3_agent_policies WHERE owner_user_id=? AND agent_contact_id IS NULL AND is_active=1
        AND (priority=200 OR metadata_json LIKE '%\"source\"%access_profile%') ORDER BY updated_at DESC,id DESC LIMIT 7");
    $q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(!$rows)return null;
    $catalog=vp3_radar_access_profile_catalog();$slug=null;$actual=[];$updated='';$canonical=true;
    foreach($rows as $row){
        $meta=vp3_radar_metadata_v1($row['metadata_json']);$key=$meta['profile_slug']??null;
        if(($meta['source']??null)!=='access_profile'||!is_string($key)||!isset($catalog[$key])||($slug!==null&&$slug!==$key)||$row['property_id']!==null||
            $row['operator_name']!==''||$row['path_pattern']!=='*'||vp3_radar_number_v1($row['priority'])!==200||$row['expires_at']!==null)throw new RuntimeException('Radar Access Profile evidence is inconsistent.');
        $slug=$key;$actual[]=$row['visitor_class'].'|'.$row['action'];$updated=max($updated,vp3_radar_date_v1($row['updated_at']));
        $canonical=$canonical&&str_contains($row['metadata_json'],'"source":"access_profile"');
    }
    $expected=array_map(static fn(array $r):string=>$r['visitor_class'].'|'.$r['action'],$catalog[$slug]['rules']);sort($actual);sort($expected);
    if($actual!==$expected)throw new RuntimeException('Radar Access Profile rules are incomplete.');
    return ['slug'=>$slug,'label'=>$catalog[$slug]['label'],'description'=>$catalog[$slug]['description'],'updated_at'=>$updated,'policy_ids'=>array_map(static fn(array $row):int=>vp3_radar_number_v1($row['id'],1),$rows),'canonical_source_match'=>$canonical];
}

/** A changed record owner must invalidate previously fetched labels before they reach Chat. */
function vp3_radar_owned_rows_v1(PDO $pdo,int $uid,string $table,array $ids): void
{
    if(!$ids)return;
    if(!in_array($table,['vp3_agent_contacts','vp3_agent_policies','vp3_radar_properties'],true))throw new InvalidArgumentException('Unsupported Radar ownership check.');
    $ids=array_values(array_unique($ids));$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE owner_user_id=? AND id IN ('.$marks.')');$q->execute([$uid,...$ids]);
    if(vp3_radar_number_v1($q->fetchColumn())!==count($ids))throw new RuntimeException('Radar record ownership changed.',403);
}

/** Validate rule metadata against its actual columns and owned property, never metadata alone. */
function vp3_radar_scope_row_v1(array $row,int $uid): array
{
    $meta=vp3_radar_metadata_v1($row['metadata_json']??null);$type=$meta['scope_type']??null;$value=$meta['scope_value']??null;
    if(($meta['source']??null)!=='scoped_rule'||!in_array($type,['operator','class','path'],true)||!is_string($value)||$value===''||vp3_radar_number_v1($row['priority']??null)!==50)throw new RuntimeException('Radar rule evidence is invalid.');
    $expected=['operator'=>$type==='operator'?$value:'','class'=>$type==='class'?$value:'','path'=>$type==='path'?$value:'*'];
    if(($row['operator_name']??null)!==$expected['operator']||($row['visitor_class']??null)!==$expected['class']||($row['path_pattern']??null)!==$expected['path']||vp3_radar_gateway_scope_value($type,$value)!==$value)throw new RuntimeException('Radar rule target changed.');
    $property=$row['property_id']===null?0:vp3_radar_number_v1($row['property_id'],1);
    if($property>0&&vp3_radar_number_v1($row['property_owner']??null,1)!==$uid)throw new RuntimeException('Radar property ownership changed.',403);
    return vp3_radar_policy_v1($row)+['scope_type'=>$type,'scope_value'=>vp3_radar_text_v1($value,500),'property_id'=>$property,'property_label'=>$property>0?vp3_radar_text_v1($row['property_label']??null):'all connected sites','canonical_source_match'=>str_contains((string)$row['metadata_json'],'"source":"scoped_rule"')];
}

function vp3_radar_result_v1(string $answer,string $status='read_complete',int $count=0,string $cap='radar.read',string $operation='list',string $reason=''): array
{
    return ['handled'=>true,'answer'=>$answer,'actions'=>[['type'=>'open_url','label'=>'Review Agent Radar','url'=>url('/profile-agent.php?tab=radar'),'auto'=>false]],
        'sources'=>[['source'=>'radar:owned-records','title'=>'Your saved Radar and Gateway records']],
        'tool_receipt'=>vp3_tool_receipt_v1($cap,$operation,$status,$reason,0,$count)];
}

function vp3_radar_failure_v1(Throwable $e,bool $write=false,bool $attempted=false): array
{
    $reason=$attempted?'outcome_unverified':($e->getCode()===403?'permission_denied':($e->getCode()===409?'upgrade_required':($e instanceof InvalidArgumentException?'invalid_plan':'execution_failed')));
    $answer=$attempted?'The saved Gateway policy outcome could not be verified. Effects may already exist. Review Agent Radar before repeating this command.':
        ($reason==='permission_denied'?'Current owner, Agent, conversation or Profile Agent permissions do not permit this Radar operation.':
        ($reason==='upgrade_required'?'Radar storage is not ready. The existing Cloud database upgrade is required before this operation.':'The Radar records or requested policy could not be verified. This does not establish an empty list, a saved change or enforcement on connected sites.'));
    $result=vp3_radar_result_v1($answer,$reason==='permission_denied'?'blocked':'failed',0,$write?'radar.gateway.manage':'radar.read',$write?'set_policy':'list',$reason);$result['sources']=[];return $result;
}

function vp3_radar_read_v1(PDO $pdo,array $user,array $principal,array $plan,array $context=[]): array
{
    try{
        $plan=vp3_radar_plan_v1($plan);$live=vp3_radar_authority_v1($pdo,$user,$principal,$context);$uid=(int)$live['id'];
        if(!vp3_radar_read_ready_v1($pdo))throw new RuntimeException('Radar storage unavailable.',409);
        vp3_radar_services_v1();
        $view=$plan['view'];$lines=[];$more=false;$count=0;$contactIds=[];$policyIds=[];$propertyIds=[];
        if(in_array($view,['recent','high_risk'],true)){
            $params=[$uid];$filter=vp3_radar_filter_sql_v1($plan,$params);
            $q=$pdo->prepare('SELECT c.id,c.display_name,c.operator_name,c.visitor_class,c.verification_status,c.risk_score,c.session_count,c.page_view_count,c.last_seen_at FROM vp3_agent_contacts c WHERE c.owner_user_id=?'.($view==='high_risk'?' AND c.risk_score>=70':'').$filter.' ORDER BY '.($view==='high_risk'?'c.risk_score DESC,':'').'c.last_seen_at DESC,c.id DESC LIMIT 13');
            $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>12;
            foreach(array_slice($rows,0,12) as $row){
                $id=vp3_radar_number_v1($row['id'],1);$name=vp3_radar_text_v1($row['display_name']);$operator=vp3_radar_text_v1($row['operator_name'],120);
                $contactIds[]=$id;$policy=vp3_radar_contact_policy_v1($pdo,$uid,$id);if($policy)$policyIds[]=$policy['id'];
                $lines[]='Contact #'.$id.' · '.vp3_radar_literal_v1(($operator!==''?$operator.' · ':'').$name,320).' · classified '.vp3_radar_literal_v1(vp3_radar_text_v1($row['visitor_class'],40),40).
                    ' · recorded risk '.vp3_radar_number_v1($row['risk_score'],0,100).'/100 · '.vp3_radar_number_v1($row['session_count']).' recorded sessions · '.vp3_radar_number_v1($row['page_view_count']).' recorded views · last seen '.$row['last_seen_at'].
                    ' · saved policy '.vp3_radar_policy_text_v1($policy);
                vp3_radar_date_v1($row['last_seen_at']);
            }
            $answer=$view==='high_risk'?'Your stored Radar contacts scored 70 or above:':'Your recent stored Radar contacts:';
            $answer.=' Counts are recorded contact totals. Automated-agent classification is not authenticated proof of identity.';
        }elseif($view==='contact_policies'){
            $params=[$uid];$filter=vp3_radar_filter_sql_v1($plan,$params);
            if(isset($plan['contact_id'])){$filter.=' AND c.id=?';$params[]=$plan['contact_id'];}
            $q=$pdo->prepare("SELECT c.id,c.display_name,c.operator_name FROM vp3_agent_contacts c WHERE c.owner_user_id=?".$filter.
                " AND EXISTS(SELECT 1 FROM vp3_agent_policies p WHERE p.owner_user_id=c.owner_user_id AND p.agent_contact_id=c.id AND p.property_id IS NULL AND p.operator_name='' AND p.visitor_class='' AND p.path_pattern='*' AND p.is_active=1 AND (p.expires_at IS NULL OR p.expires_at>NOW())) ORDER BY c.last_seen_at DESC,c.id DESC LIMIT 13");
            $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>12;
            foreach(array_slice($rows,0,12) as $row){$id=vp3_radar_number_v1($row['id'],1);$policy=vp3_radar_contact_policy_v1($pdo,$uid,$id);if(!$policy)throw new RuntimeException('Radar policy changed during retrieval.');$contactIds[]=$id;$policyIds[]=$policy['id'];$lines[]='Contact #'.$id.' · '.vp3_radar_literal_v1(vp3_radar_text_v1($row['display_name'])).' · saved '.vp3_radar_policy_text_v1($policy).' · updated '.$policy['updated_at'];}
            $answer='Your current saved contact overrides:';
        }elseif($view==='scoped_rules'){
            $q=$pdo->prepare("SELECT p.id,p.property_id,p.operator_name,p.visitor_class,p.path_pattern,p.action,p.priority,p.updated_at,SUBSTR(p.metadata_json,1,65537) metadata_json,r.owner_user_id property_owner,r.label property_label FROM vp3_agent_policies p LEFT JOIN vp3_radar_properties r ON r.id=p.property_id WHERE p.owner_user_id=? AND p.agent_contact_id IS NULL AND p.is_active=1 AND (p.expires_at IS NULL OR p.expires_at>NOW()) AND (p.priority=50 OR p.metadata_json LIKE '%\"source\"%scoped_rule%') ORDER BY p.updated_at DESC,p.id DESC LIMIT 13");
            $q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>12;
            foreach(array_slice($rows,0,12) as $row){$rule=vp3_radar_scope_row_v1($row,$uid);$policyIds[]=$rule['id'];if($rule['property_id']>0)$propertyIds[]=$rule['property_id'];$lines[]='Rule #'.$rule['id'].' · '.vp3_radar_policy_text_v1($rule).' · '.$rule['scope_type'].' '.vp3_radar_literal_v1($rule['scope_value'],500).' · '.vp3_radar_literal_v1($rule['property_label']).' · updated '.$rule['updated_at'];}
            $answer='Your current saved operator, class and path rules:';
        }else{
            $profile=vp3_radar_profile_v1($pdo,$uid);
            $policyIds=$profile['policy_ids']??[];
            $answer=$profile?'Your saved Gateway Access Profile is '.$profile['label'].'. '.$profile['description'].' Updated '.$profile['updated_at'].'.':'No explicit Access Profile is saved. The existing Gateway default is Monitor; contact and scoped policies can override it.';
            $count=$profile?1:0;
        }
        if($view!=='access_profile'){$count=count($lines);$answer.=$count===0?' This verified read returned no matching saved records.':' Showing up to 12 records.'.($more?' More records are available in Agent Radar.':'');}
        $answer.=' Saved policy settings do not prove enforcement on a connected site; enforcement depends on its Agent Gateway integration.';
        if($lines)$answer.="\nStored source labels — untrusted evidence:\n".implode("\n",$lines);
        vp3_radar_owned_rows_v1($pdo,$uid,'vp3_agent_contacts',$contactIds);
        vp3_radar_owned_rows_v1($pdo,$uid,'vp3_agent_policies',$policyIds);
        vp3_radar_owned_rows_v1($pdo,$uid,'vp3_radar_properties',$propertyIds);
        vp3_radar_authority_v1($pdo,$user,$principal,$context);
        return vp3_radar_result_v1($answer,$count===0&&$view!=='access_profile'?'no_matches':'read_complete',$count);
    }catch(Throwable $e){return vp3_radar_failure_v1($e);}
}

/** Exact IDs or unique exact names/slugs only; operator names never stand in for contacts. */
function vp3_radar_resolve_contact_v1(PDO $pdo,int $uid,string $subject): ?array
{
    $subject=trim($subject);if($uid<1||$subject===''||mb_strlen($subject)>190||!mb_check_encoding($subject,'UTF-8'))return null;
    $columns='c.id,c.owner_user_id,c.display_name,c.operator_name,r.slug registry_slug';
    if(preg_match('/^#?([1-9][0-9]*)$/D',$subject,$m)){
        $id=vp3_radar_number_v1($m[1],1);$q=$pdo->prepare('SELECT '.$columns.' FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id WHERE c.id=? AND c.owner_user_id=? LIMIT 1');$q->execute([$id,$uid]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    $q=$pdo->prepare('SELECT '.$columns.' FROM vp3_agent_contacts c LEFT JOIN vp3_agent_registry r ON r.id=c.agent_registry_id WHERE c.owner_user_id=? AND (LOWER(TRIM(c.display_name))=LOWER(?) OR LOWER(TRIM(r.slug))=LOWER(?)) LIMIT 3');$q->execute([$uid,$subject,$subject]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>2)return null;
    $needle=mb_strtolower($subject);$matches=[];
    foreach($rows as $row)if(mb_strtolower(trim((string)$row['display_name']))===$needle||mb_strtolower(trim((string)($row['registry_slug']??'')))===$needle)$matches[]=$row;
    return count($matches)===1?$matches[0]:null;
}

/** Inspect/tutorial/conditional questions return before private reads and policy writers. */
function vp3_radar_nonexecuting_v1(string $query): string
{
    if(preg_match('/\b(?:if|unless|whenever|until|suppose|imagine|hypothetical|later|tomorrow|next week)\b|\bwhen\s+(?:i|we|you|an?|the)\b/i',$query))return 'continue';
    if(preg_match('/^\s*(?:please\s+)?(?:how\b(?!\s+many\b)|explain\b|teach\b|what (?:is|are) (?:an? |the )?(?:bot|crawler|agent radar|agent gateway)\b)|\b(?:tutorial|walkthrough|example|what would|how (?:to|do|can|should|would))\b/i',$query))return 'continue';
    if(preg_match('/\b(?:capabilit(?:y|ies)|can (?:you|we|i)|could (?:you|we|i)|would you|are you (?:able|allowed)|do you have (?:access|permission)|is it possible|what can you|(?:does|do)\b[^?\r\n]{0,100}\b(?:support|allow|handle))\b/i',$query))return 'inspect';
    return '';
}

/** Only supported anchored legacy commands reach the existing canonical writers. */
function vp3_radar_command_v1(string $query): ?array
{
    if(vp3_radar_nonexecuting_v1($query)!=='')return null;
    $q=trim($query);if(preg_match('/[\r\n]/',$q)||mb_strlen($q)>700)return null;
    vp3_radar_services_v1();
    $profiles=['Open Web'=>'open_web','Search Friendly'=>'search_friendly','Agent Friendly'=>'agent_friendly','Private'=>'private','Locked Down'=>'locked_down','Monitor All'=>'monitor_all'];
    if(preg_match('/^(?:please\s+)?(?:use|set|apply|switch|change|enable)\s+(?:(?:my\s+)?(?:Agent\s+Gateway\s+)?(?:access|gateway)\s+profile\s+(?:to\s+)?)?(Open Web|Search Friendly|Agent Friendly|Private|Locked Down|Monitor All)(?:\s+(?:access|gateway)\s+profile)?[.!]?$/i',$q,$m)){
        foreach($profiles as $label=>$slug)if(strcasecmp($m[1],$label)===0)return ['type'=>'profile','slug'=>$slug];
    }
    if(preg_match('/^(?:please\s+)?(block|allow|monitor)\s+operator\s+(.+?)[.!]?$/i',$q,$m)){
        $value=trim($m[2]," \t\"'");if($value===''||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>120||vp3_radar_gateway_scope_value('operator',$value)!==$value)throw new InvalidArgumentException('Use an exact operator name.');
        return ['type'=>'scoped','scope_type'=>'operator','scope_value'=>$value,'action'=>strtolower($m[1]),'limit'=>30];
    }
    if(preg_match('/^(?:please\s+)?(block|allow|monitor)\s+(?:all\s+)?(crawlers?|training crawlers?|ai search|search agents?|unknown automation|user-directed agents?)[.!]?$/i',$q,$m)){
        $value=strtolower($m[2]);$class=str_contains($value,'unknown')?'automated_unknown':(str_contains($value,'search')?'ai_search':(str_contains($value,'crawler')?'ai_crawler':'ai_user_agent'));
        return ['type'=>'scoped','scope_type'=>'class','scope_value'=>$class,'action'=>strtolower($m[1]),'limit'=>30];
    }
    if(preg_match('~^(?:please\s+)?(block|allow|monitor)\s+(?:agents?|bots?|automation)\s+(?:on|from)\s+(/[^\s?#]+)\s*$~i',$q,$m)){
        if(!mb_check_encoding($m[2],'UTF-8')||mb_strlen($m[2])>500||vp3_radar_gateway_scope_value('path',$m[2])!==$m[2])throw new InvalidArgumentException('Use an exact Gateway path pattern.');
        return ['type'=>'scoped','scope_type'=>'path','scope_value'=>$m[2],'action'=>strtolower($m[1]),'limit'=>30];
    }
    if(preg_match('/^(?:please\s+)?limit\s+(?:agent\s+)?(.+?)\s+to\s+([0-9]+)\s*(?:requests?)?(?:\s*(?:per|\/)\s*30\s*(?:minutes?|mins?|m))?(?:\s+everywhere|\s+across all sites|\s+on all sites)?[.!]?$/i',$q,$m))return ['type'=>'contact','subject'=>trim($m[1]," \t\"'"),'action'=>'limit','limit'=>vp3_radar_number_v1($m[2],1,10000)];
    if(preg_match('/^(?:please\s+)?(block|allow|monitor)\s+(?:agent\s+)?(.+?)(?:\s+everywhere|\s+across all sites|\s+on all sites|\s+on my sites|\s+from my sites)?[.!]?$/i',$q,$m)){
        $subject=trim($m[2]," \t\"'");if(preg_match('/\b(?:all|operator|on|from|if|when|unless)\b/i',$subject))return null;
        return ['type'=>'contact','subject'=>$subject,'action'=>strtolower($m[1]),'limit'=>30];
    }
    return null;
}

function vp3_radar_scoped_exact_v1(PDO $pdo,int $uid,array $command): ?array
{
    $type=$command['scope_type'];$value=$command['scope_value'];
    $q=$pdo->prepare("SELECT id,property_id,operator_name,visitor_class,path_pattern,action,priority,updated_at,SUBSTR(metadata_json,1,65537) metadata_json FROM vp3_agent_policies WHERE owner_user_id=? AND property_id IS NULL AND agent_contact_id IS NULL AND operator_name=? AND visitor_class=? AND path_pattern=? AND is_active=1 AND (expires_at IS NULL OR expires_at>NOW()) AND (priority=50 OR metadata_json LIKE '%\"source\"%scoped_rule%') ORDER BY priority ASC,id DESC LIMIT 2");
    $q->execute([$uid,$type==='operator'?$value:'',$type==='class'?$value:'',$type==='path'?$value:'*']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1)throw new RuntimeException('Gateway rule scope is ambiguous.');
    if(!$rows)return null;
    $rule=vp3_radar_scope_row_v1($rows[0],$uid);
    if($rule['scope_type']!==$type||$rule['scope_value']!==$value)throw new RuntimeException('Gateway rule target is not exact.');
    return $rule;
}

/** Canonical writers own their transactions. Never retry a write after an uncertain response. */
function vp3_radar_apply_command_v1(PDO $pdo,array $user,array $principal,array $context,string $query): array
{
    $attempted=false;
    try{
        $command=vp3_radar_command_v1($query);
        if(!$command)return vp3_radar_result_v1('Choose one exact Radar contact, operator, class, path or Access Profile before changing a policy. No request was applied.','clarification_required',0,'radar.gateway.manage','set_policy');
        $live=vp3_radar_authority_v1($pdo,$user,$principal,$context);$uid=(int)$live['id'];
        if(!vp3_radar_read_ready_v1($pdo))throw new RuntimeException('Radar storage unavailable.',409);
        $same=false;$contact=null;
        if($command['type']==='profile'){
            $before=vp3_radar_profile_v1($pdo,$uid);$same=($before['slug']??null)===$command['slug'];
        }elseif($command['type']==='contact'){
            $contact=vp3_radar_resolve_contact_v1($pdo,$uid,$command['subject']);
            if(!$contact)return vp3_radar_result_v1('Choose one exact owned Radar contact ID or a unique exact contact name. Operator names and partial matches do not select a contact. Review Agent Radar; no policy was changed.','clarification_required',0,'radar.gateway.manage','set_policy');
            $before=vp3_radar_contact_policy_v1($pdo,$uid,(int)$contact['id']);$same=$before!==null&&$before['action']===$command['action']&&($command['action']!=='limit'||$before['limit_30m']===$command['limit']);
        }else{$before=vp3_radar_scoped_exact_v1($pdo,$uid,$command);$same=$before!==null&&$before['action']===$command['action'];}
        if(!$same&&$before!==null&&in_array($command['type'],['profile','scoped'],true)&&empty($before['canonical_source_match']))throw new RuntimeException('Existing Gateway metadata cannot be replaced by its canonical writer.');
        $live=vp3_radar_authority_v1($pdo,$user,$principal,$context);
        if(!$same){
            $attempted=true;
            if($command['type']==='profile')$saved=vp3_radar_access_profile_apply($pdo,$live,$command['slug']);
            elseif($command['type']==='contact')$saved=vp3_radar_gateway_set_contact_policy($pdo,$live,(int)$contact['id'],$command['action'],$command['limit']);
            else $saved=vp3_radar_gateway_set_scoped_rule($pdo,$live,$command['scope_type'],$command['scope_value'],$command['action'],0,$command['limit']);
        }
        vp3_radar_authority_v1($pdo,$user,$principal,$context);
        if($command['type']==='profile'){
            $after=vp3_radar_profile_v1($pdo,$uid);
            if(($after['slug']??null)!==$command['slug']||(!$same&&($saved['profile']['slug']??null)!==$command['slug']))throw new RuntimeException('Saved Access Profile is unverified.');
            $target='Gateway Access Profile '.$after['label'];
        }elseif($command['type']==='contact'){
            $current=vp3_radar_resolve_contact_v1($pdo,$uid,'#'.$contact['id']);$after=vp3_radar_contact_policy_v1($pdo,$uid,(int)$contact['id']);
            if(!$current||!$after||$after['action']!==$command['action']||($command['action']==='limit'&&$after['limit_30m']!==$command['limit'])||
                (!$same&&(vp3_radar_number_v1($saved['contact_id']??null,1)!==(int)$contact['id']||vp3_radar_number_v1($saved['policy']['id']??null,1)!==$after['id'])))throw new RuntimeException('Saved contact policy is unverified.');
            $target=vp3_radar_literal_v1(vp3_radar_text_v1($current['display_name'])).' (contact #'.$current['id'].'): '.vp3_radar_policy_text_v1($after);
            if(!$same&&function_exists('vp3_agent_crm_audit_event'))vp3_agent_crm_audit_event($pdo,$uid,(int)$contact['id'],'agent_policy_changed','Gateway policy saved for contact #'.$contact['id'].'.',['previous_action'=>$before['action']??'profile_default','new_action'=>$after['action'],'previous_limit_30m'=>$before['limit_30m']??null,'new_limit_30m'=>$after['limit_30m'],'surface'=>'main_feed']);
        }else{
            $after=vp3_radar_scoped_exact_v1($pdo,$uid,$command);
            if(!$after||$after['action']!==$command['action'])throw new RuntimeException('Saved scoped policy is unverified.');
            $target=$after['scope_type'].' '.vp3_radar_literal_v1($after['scope_value'],500).': '.vp3_radar_policy_text_v1($after).' across connected sites';
        }
        vp3_radar_authority_v1($pdo,$user,$principal,$context);
        return vp3_radar_result_v1(($same?'The same Gateway policy is already saved; no new change was applied: ':'Gateway policy saved: ').$target.'. Enforcement on connected sites depends on their installed Agent Gateway integration. This receipt verifies the saved policy, not a live access test.',$same?'read_complete':'completed',1,'radar.gateway.manage','set_policy');
    }catch(Throwable $e){return vp3_radar_failure_v1($e,true,$attempted);}
}
