<?php
declare(strict_types=1);

/**
 * Browser Companion v21.70 — explicit cross-web Cognitive Memory approvals.
 *
 * Persistent approval rows are reference-only. They never store a page URL,
 * title, selected text, page body, summary, browser timestamp or browsing log.
 * Current target context is resolved from canonical VP3 objects on every read.
 */
const VP3_BROWSER_MEMORY_V2170='browser-memory-v2170-20260920';
const VP3_BROWSER_MEMORY_LIST_LIMIT_V2170=30;
const VP3_BROWSER_MEMORY_CONTEXT_LIMIT_V2170=12;

require_once __DIR__.'/cognitive-runtime-v500.php';
require_once __DIR__.'/browser-source-feed-v2050.php';
require_once __DIR__.'/research-projects-v2060.php';
require_once __DIR__.'/knowledge.php';
require_once __DIR__.'/crm-v180.php';

function vp3_browser_memory_schema_ready_v2170(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('browser_memory_approvals_v2170');
}

function vp3_browser_memory_ensure_schema_v2170(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_browser_memory_schema_ready_v2170($pdo))return;
    if(!table_exists('users'))throw new RuntimeException('VP3 users must exist before Browser Memory.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_memory_approvals_v2170 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      target_type VARCHAR(40) NOT NULL,
      target_id VARCHAR(190) NOT NULL,
      target_scope VARCHAR(40) NOT NULL DEFAULT 'personal',
      approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      revoked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_memory_public_v2170 (public_id),
      UNIQUE KEY uq_browser_memory_target_v2170 (owner_user_id,agent_namespace,target_type,target_id,target_scope),
      INDEX idx_browser_memory_active_v2170 (owner_user_id,agent_namespace,revoked_at,approved_at),
      CONSTRAINT fk_browser_memory_owner_v2170 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_browser_memory_uuid_v2170(): string
{
    return vp3_cognitive_uuid_v500();
}

function vp3_browser_memory_target_type_v2170(mixed $value): string
{
    $type=vp3_cognitive_id_v500($value,40);
    return in_array($type,['browser_source','research_project','knowledge_item','public_profile','crm_contact'],true)?$type:'';
}

function vp3_browser_memory_target_id_v2170(mixed $value): string
{
    return mb_strimwidth(trim((string)$value),0,190,'');
}

function vp3_browser_memory_source_authorized_v2170(PDO $pdo,array $user,array $source): bool
{
    $uid=(int)($user['id']??0);$sourceId=(int)($source['id']??0);
    if($uid<1||$sourceId<1)return false;

    $follow=$pdo->prepare('SELECT 1 FROM browser_source_follows_v2050 WHERE user_id=? AND source_id=? LIMIT 1');
    $follow->execute([$uid,$sourceId]);
    if($follow->fetchColumn())return true;

    $stmt=$pdo->prepare("SELECT s.public_id
      FROM browser_share_sources_v2050 map
      INNER JOIN browser_shares_v2010 s ON s.id=map.browser_share_id
      WHERE map.source_id=? AND s.deleted_at IS NULL
      ORDER BY s.id DESC LIMIT 20");
    $stmt->execute([$sourceId]);
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $publicId){
        $share=vp3_browser_source_share_row_v2050($pdo,(string)$publicId);
        if($share&&vp3_browser_source_share_authorized_v2050($pdo,$share,$uid))return true;
    }
    return false;
}

function vp3_browser_memory_knowledge_v2170(PDO $pdo,array $user,int $knowledgeId): ?array
{
    if($knowledgeId<1||!table_exists('knowledge_items'))return null;
    $stmt=$pdo->prepare('SELECT * FROM knowledge_items WHERE id=? LIMIT 1');
    $stmt->execute([$knowledgeId]);$row=$stmt->fetch();
    if(!is_array($row))return null;

    $uid=(int)($user['id']??0);
    $scope=column_exists('knowledge_items','knowledge_scope')?(string)($row['knowledge_scope']??'system'):'system';
    $personal=$scope==='personal'&&(int)($row['created_by_user_id']??0)===$uid;
    if($personal){
        if(!personal_knowledge_available($user))return null;
    }else{
        if($scope!=='system'||empty($row['is_published'])||!has_permission('knowledge.access',$user))return null;
        if(!knowledge_visibility_allowed((string)($row['visibility']??''),$user))return null;
    }
    return $row+['_resolved_scope'=>$personal?'personal':'system'];
}

function vp3_browser_memory_target_v2170(PDO $pdo,array $user,string $type,string $id): ?array
{
    $type=vp3_browser_memory_target_type_v2170($type);
    $id=vp3_browser_memory_target_id_v2170($id);
    if($type===''||$id==='')return null;

    if($type==='browser_source'){
        $row=vp3_browser_source_row_by_public_id_v2050($pdo,$id);
        if(!$row||!vp3_browser_memory_source_authorized_v2170($pdo,$user,$row))return null;
        $public=vp3_browser_source_public_source_v2050($pdo,$row,(int)$user['id']);
        return [
            'type'=>$type,'id'=>(string)$public['id'],'scope'=>'personal',
            'title'=>vp3_cognitive_text_v500($public['title']??$public['domain']??'Browser source',190),
            'detail'=>vp3_cognitive_text_v500($public['domain']??'',300),
            'url'=>'/source.php?source='.rawurlencode((string)$public['id']),
        ];
    }

    if($type==='research_project'){
        $row=vp3_research_project_row_v2060($pdo,$id);
        if(!$row||vp3_research_project_role_v2060($pdo,$row,(int)$user['id'])==='')return null;
        $public=vp3_research_project_public_v2060($pdo,$row,(int)$user['id']);
        return [
            'type'=>$type,'id'=>(string)$public['id'],'scope'=>'personal',
            'title'=>vp3_cognitive_text_v500($public['title']??'Research project',190),
            'detail'=>vp3_cognitive_text_v500($public['description']??'',500),
            'url'=>'/research-project.php?project='.rawurlencode((string)$public['id']),
        ];
    }

    if($type==='knowledge_item'){
        $row=vp3_browser_memory_knowledge_v2170($pdo,$user,(int)$id);
        if(!$row)return null;
        return [
            'type'=>$type,'id'=>(string)(int)$row['id'],'scope'=>'personal',
            'title'=>vp3_cognitive_text_v500($row['title']??'Knowledge',190),
            'detail'=>vp3_cognitive_text_v500($row['description']??'',500),
            'url'=>'/knowledge.php',
        ];
    }

    if($type==='public_profile'){
        if(!table_exists('user_profiles')||!table_exists('users')||!ctype_digit($id))return null;
        $stmt=$pdo->prepare("SELECT p.user_id,p.username,p.bio,p.website_url,u.display_name
          FROM user_profiles p INNER JOIN users u ON u.id=p.user_id
          WHERE p.user_id=? AND p.is_public=1 AND u.is_active=1 LIMIT 1");
        $stmt->execute([(int)$id]);$row=$stmt->fetch();
        if(!is_array($row))return null;
        return [
            'type'=>$type,'id'=>(string)(int)$row['user_id'],'scope'=>'personal',
            'title'=>vp3_cognitive_text_v500($row['display_name']??('@'.(string)$row['username']),190),
            'detail'=>vp3_cognitive_text_v500($row['bio']??'',500),
            'url'=>'/profile.php?u='.rawurlencode((string)$row['username']),
        ];
    }

    if($type==='crm_contact'){
        if(!ctype_digit($id)||!crm_v180_can_manage($user)||!crm_v180_schema_ready($pdo))return null;
        $stmt=$pdo->prepare("SELECT c.id,c.name,c.email,c.company,l.id lead_id,l.stage
          FROM crm_contacts c LEFT JOIN crm_leads l ON l.contact_id=c.id
          WHERE c.id=? ORDER BY l.id DESC LIMIT 1");
        $stmt->execute([(int)$id]);$row=$stmt->fetch();
        if(!is_array($row))return null;
        return [
            'type'=>$type,'id'=>(string)(int)$row['id'],'scope'=>'personal',
            'title'=>vp3_cognitive_text_v500(trim((string)$row['name']).(trim((string)$row['company'])!==''?' · '.trim((string)$row['company']):''),190),
            'detail'=>vp3_cognitive_text_v500(!empty($row['lead_id'])?'CRM lead · '.str_replace('_',' ',(string)($row['stage']??'new')):'CRM contact',300),
            'url'=>!empty($row['lead_id'])?'/admin/crm-lead.php?id='.(int)$row['lead_id']:'/admin/crm.php',
        ];
    }

    return null;
}

function vp3_browser_memory_approval_row_v2170(PDO $pdo,int $uid,string $namespace,string $type,string $id): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM browser_memory_approvals_v2170
      WHERE owner_user_id=? AND agent_namespace=? AND target_type=? AND target_id=? AND target_scope='personal' LIMIT 1");
    $stmt->execute([$uid,$namespace,$type,$id]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_browser_memory_approval_by_public_v2170(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM browser_memory_approvals_v2170 WHERE public_id=? LIMIT 1');
    $stmt->execute([trim($publicId)]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_browser_memory_public_v2170(array $target,?array $approval): array
{
    return [
        'target_type'=>(string)$target['type'],
        'target_id'=>(string)$target['id'],
        'title'=>(string)$target['title'],
        'detail'=>(string)$target['detail'],
        'url'=>(string)$target['url'],
        'approved'=>$approval!==null&&empty($approval['revoked_at']),
        'approval_id'=>$approval!==null?(string)$approval['public_id']:'',
        'approved_at'=>$approval!==null&&!empty($approval['approved_at'])?(string)$approval['approved_at']:'',
    ];
}

function vp3_browser_memory_candidates_v2170(PDO $pdo,array $user,string $namespace,array $relations): array
{
    $refs=[];
    $source=is_array($relations['source']??null)?$relations['source']:null;
    if($source&&!empty($source['id']))$refs[]=['browser_source',(string)$source['id']];

    foreach(array_slice((array)($relations['research']??[]),0,4) as $row)
        if(is_array($row)&&!empty($row['id']))$refs[]=['research_project',(string)$row['id']];
    foreach(array_slice((array)($relations['knowledge']??[]),0,6) as $row)
        if(is_array($row)&&!empty($row['id']))$refs[]=['knowledge_item',(string)$row['id']];
    foreach(array_slice((array)($relations['profiles']??[]),0,4) as $row)
        if(is_array($row)&&!empty($row['id']))$refs[]=['public_profile',(string)$row['id']];
    foreach(array_slice((array)($relations['contacts']??[]),0,4) as $row)
        if(is_array($row)&&!empty($row['id']))$refs[]=['crm_contact',(string)$row['id']];

    $out=[];$seen=[];$uid=(int)$user['id'];
    foreach($refs as [$type,$id]){
        $key=$type.':'.$id;if(isset($seen[$key]))continue;$seen[$key]=true;
        $target=vp3_browser_memory_target_v2170($pdo,$user,$type,$id);if(!$target)continue;
        $approval=vp3_browser_memory_approval_row_v2170($pdo,$uid,$namespace,$type,$id);
        $out[]=vp3_browser_memory_public_v2170($target,$approval);
        if(count($out)>=VP3_BROWSER_MEMORY_CONTEXT_LIMIT_V2170)break;
    }
    return $out;
}

function vp3_browser_memory_list_v2170(PDO $pdo,array $user,string $namespace): array
{
    if(!vp3_browser_memory_schema_ready_v2170($pdo))return [];
    $stmt=$pdo->prepare("SELECT * FROM browser_memory_approvals_v2170
      WHERE owner_user_id=? AND agent_namespace=? AND revoked_at IS NULL
      ORDER BY approved_at DESC,id DESC LIMIT ".VP3_BROWSER_MEMORY_LIST_LIMIT_V2170);
    $stmt->execute([(int)$user['id'],$namespace]);
    $out=[];
    foreach($stmt->fetchAll()?:[] as $approval){
        $target=vp3_browser_memory_target_v2170($pdo,$user,(string)$approval['target_type'],(string)$approval['target_id']);
        if(!$target)continue;
        $out[]=vp3_browser_memory_public_v2170($target,$approval);
    }
    return $out;
}

function vp3_browser_memory_permission_v2170(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    if($operation!=='read'||(string)($ref['type']??'')!=='browser_memory_ref')return false;
    $approval=vp3_browser_memory_approval_by_public_v2170($pdo,(string)($ref['id']??''));
    if(!$approval||!empty($approval['revoked_at']))return false;
    if((int)$approval['owner_user_id']!==(int)($user['id']??0))return false;
    if((string)$approval['agent_namespace']!==$agentNamespace)return false;
    return vp3_browser_memory_target_v2170(
        $pdo,$user,(string)$approval['target_type'],(string)$approval['target_id']
    )!==null;
}

function vp3_browser_memory_context_v2170(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $approval=vp3_browser_memory_approval_by_public_v2170($pdo,(string)($ref['id']??''));
    if(!$approval||!vp3_browser_memory_permission_v2170($pdo,$user,$agentNamespace,$ref,'read')){
        throw new RuntimeException('Browser Memory reference is no longer available.');
    }
    $target=vp3_browser_memory_target_v2170($pdo,$user,(string)$approval['target_type'],(string)$approval['target_id']);
    if(!$target)throw new RuntimeException('Remembered VP3 object is no longer available.');
    return [
        'approval'=>[
            'id'=>(string)$approval['public_id'],
            'approved_at'=>(string)$approval['approved_at'],
            'reference_only'=>true,
            'source'=>'browser_companion_user_approval',
        ],
        'target'=>$target,
    ];
}

function vp3_browser_memory_card_v2170(PDO $pdo,array $user,string $agentNamespace,array $ref,string $mode): array
{
    $context=vp3_browser_memory_context_v2170($pdo,$user,$agentNamespace,$ref,['card'=>true]);
    $target=(array)$context['target'];
    return [
        'title'=>(string)($target['title']??'Remembered VP3 object'),
        'subtitle'=>'Approved Browser Memory · '.str_replace('_',' ',(string)($target['type']??'')),
        'status'=>'remembered',
        'summary'=>(string)($target['detail']??''),
        'timestamp'=>(string)($context['approval']['approved_at']??''),
        'metadata'=>[
            'reference_only'=>true,
            'target_type'=>(string)($target['type']??''),
        ],
        'badges'=>['User approved','Reference only'],
        'actions'=>[
            ['type'=>'open_url','label'=>'Open in VP3','url'=>(string)($target['url']??'/chat.php')],
        ],
    ];
}

function vp3_browser_memory_candidate_v2170(array $approval): array
{
    $public=(string)($approval['public_id']??'');
    $approved=(string)($approval['approved_at']??gmdate('Y-m-d H:i:s'));
    $ref=vp3_cognitive_object_ref_v500('browser_memory_ref',$public,'personal',['provenance'=>'browser_memory_approval_v2170']);
    return [
        'key'=>'browser-memory:'.$public,
        'fingerprint'=>hash('sha256',vp3_cognitive_json_v500([
            'browser_memory_ref',$public,(string)$approval['target_type'],(string)$approval['target_id'],$approved
        ])),
        'source'=>'browser_memory_approval',
        'updated_at'=>$approved,
        'attention'=>false,
        'score'=>0.7,
        'reason'=>'User explicitly approved this VP3 object for cross-web Agent memory.',
        'signals'=>['user_approved','reference_only'],
        'card_request'=>[
            'card_type'=>'browser_memory_ref',
            'object_ref'=>$ref,
            'display_mode'=>'compact',
        ],
    ];
}

function vp3_browser_memory_sync_approval_v2170(PDO $pdo,array $user,string $namespace,array $approval): void
{
    if(!function_exists('vp3_cognitive_memory_observe_candidates_v570'))return;
    if(!function_exists('vp3_cognitive_memory_schema_ready_v570')||!vp3_cognitive_memory_schema_ready_v570($pdo))return;
    vp3_cognitive_memory_observe_candidates_v570($pdo,$user,$namespace,[vp3_browser_memory_candidate_v2170($approval)]);
}

function vp3_browser_memory_approve_v2170(PDO $pdo,array $user,string $namespace,string $type,string $id): array
{
    $target=vp3_browser_memory_target_v2170($pdo,$user,$type,$id);
    if(!$target)throw new RuntimeException('Choose a durable VP3 object you are currently allowed to access.');
    $type=(string)$target['type'];$id=(string)$target['id'];$uid=(int)$user['id'];
    $existing=vp3_browser_memory_approval_row_v2170($pdo,$uid,$namespace,$type,$id);
    $public=$existing?(string)$existing['public_id']:vp3_browser_memory_uuid_v2170();

    $stmt=$pdo->prepare("INSERT INTO browser_memory_approvals_v2170
      (public_id,owner_user_id,agent_namespace,target_type,target_id,target_scope,approved_at,revoked_at)
      VALUES (?,?,?,?,?,'personal',UTC_TIMESTAMP(),NULL)
      ON DUPLICATE KEY UPDATE approved_at=UTC_TIMESTAMP(),revoked_at=NULL,updated_at=UTC_TIMESTAMP()");
    $stmt->execute([$public,$uid,$namespace,$type,$id]);
    $approval=vp3_browser_memory_approval_row_v2170($pdo,$uid,$namespace,$type,$id);
    if(!$approval)throw new RuntimeException('Browser Memory approval could not be reloaded.');
    vp3_browser_memory_sync_approval_v2170($pdo,$user,$namespace,$approval);
    return vp3_browser_memory_public_v2170($target,$approval);
}

function vp3_browser_memory_revoke_v2170(PDO $pdo,array $user,string $namespace,string $approvalPublicId): void
{
    $approval=vp3_browser_memory_approval_by_public_v2170($pdo,$approvalPublicId);
    if(!$approval||(int)$approval['owner_user_id']!==(int)$user['id']||(string)$approval['agent_namespace']!==$namespace){
        throw new RuntimeException('Browser Memory approval was not found.');
    }
    $pdo->prepare("UPDATE browser_memory_approvals_v2170
      SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
      WHERE id=?")->execute([(int)$approval['id']]);
}

function vp3_browser_memory_register_v2170(): void
{
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['browser_memory']))return;
    vp3_cognitive_register_module_v500([
        'module'=>'browser_memory',
        'version'=>'browser-memory-v2170',
        'objects'=>['browser_memory_ref'],
        'events'=>[],
        'permission_resolver'=>'vp3_browser_memory_permission_v2170',
        'context_provider'=>'vp3_browser_memory_context_v2170',
        'relationship_provider'=>null,
        'cards'=>['browser_memory_ref'=>'vp3_browser_memory_card_v2170'],
        'tools'=>[],
        'freshness_policy'=>['default_seconds'=>300],
        'sensitivity_policy'=>[
            'reference_only'=>true,
            'requires_user_approval'=>true,
            'stores_browsing_history'=>false,
        ],
        'surfaces'=>['memory','brief','away_digest','ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

vp3_browser_memory_register_v2170();
