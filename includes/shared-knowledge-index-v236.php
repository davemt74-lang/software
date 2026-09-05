<?php
declare(strict_types=1);

const STONEFELLOW_SHARED_KNOWLEDGE_INDEX_V236='shared-knowledge-index-v236-20260903';

function shared_knowledge_index_schema_ready_v236(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo && table_exists('shared_knowledge_index');
}

function shared_knowledge_index_ensure_schema_v236(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS shared_knowledge_index (
      knowledge_id INT UNSIGNED NOT NULL PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      source_version_hash CHAR(64) NOT NULL,
      title VARCHAR(190) NOT NULL DEFAULT '',
      topic_tags VARCHAR(1000) NOT NULL DEFAULT '',
      embedding_ref VARCHAR(190) NOT NULL DEFAULT '',
      share_scope VARCHAR(30) NOT NULL DEFAULT 'inherit',
      is_indexed TINYINT(1) NOT NULL DEFAULT 1,
      last_indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      revoked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_shared_knowledge_owner (owner_user_id,is_indexed,updated_at),
      INDEX idx_shared_knowledge_scope (is_indexed,share_scope,updated_at),
      CONSTRAINT fk_shared_knowledge_item_v236 FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id) ON DELETE CASCADE,
      CONSTRAINT fk_shared_knowledge_owner_v236 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function shared_knowledge_index_stopwords_v236(): array
{
    return array_fill_keys(['the','and','for','with','this','that','from','your','you','are','was','were','have','has','had','not','but','into','about','their','there','they','them','his','her','its','our','out','all','can','will','would','could','should','what','when','where','who','how','why','which','also','than','then'],true);
}

function shared_knowledge_index_tags_v236(string $text,int $limit=36): string
{
    $tokens=preg_split('/[^\pL\pN]+/u',mb_strtolower($text))?:[];
    $stop=shared_knowledge_index_stopwords_v236();$counts=[];
    foreach($tokens as $token){$token=trim($token);if(mb_strlen($token)<3||isset($stop[$token]))continue;$counts[$token]=($counts[$token]??0)+1;}
    arsort($counts,SORT_NUMERIC);
    return mb_strimwidth(implode(' ',array_slice(array_keys($counts),0,$limit)),0,1000,'');
}

function shared_knowledge_index_item_v236(PDO $pdo,int $knowledgeId): ?array
{
    $scopeColumn=column_exists('knowledge_items','knowledge_scope')?',knowledge_scope':'';
    $stmt=$pdo->prepare('SELECT id,title,description,content_text,visibility,is_published,created_by_user_id,updated_at'.$scopeColumn.' FROM knowledge_items WHERE id=? LIMIT 1');
    $stmt->execute([$knowledgeId]);$item=$stmt->fetch();if(!$item)return null;
    if(!isset($item['knowledge_scope']))$item['knowledge_scope']='system';
    $chunks=[];
    if(table_exists('knowledge_chunks')){$c=$pdo->prepare('SELECT chunk_text FROM knowledge_chunks WHERE knowledge_id=? ORDER BY chunk_index ASC,id ASC');$c->execute([$knowledgeId]);$chunks=$c->fetchAll(PDO::FETCH_COLUMN)?:[];}
    $item['chunk_texts']=$chunks;
    return $item;
}

function shared_knowledge_index_hash_v236(array $item): string
{
    return hash('sha256',json_encode([
      'id'=>(int)($item['id']??0),'owner'=>(int)($item['created_by_user_id']??0),'scope'=>(string)($item['knowledge_scope']??'system'),
      'title'=>(string)($item['title']??''),'description'=>(string)($item['description']??''),'content'=>(string)($item['content_text']??''),
      'chunks'=>array_values((array)($item['chunk_texts']??[])),'visibility'=>(string)($item['visibility']??''),'is_published'=>(int)($item['is_published']??0),'updated_at'=>(string)($item['updated_at']??''),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
}

function shared_knowledge_index_revoke_v236(PDO $pdo,int $knowledgeId): void
{
    $stmt=$pdo->prepare("UPDATE shared_knowledge_index SET is_indexed=0,revoked_at=COALESCE(revoked_at,NOW()),title='',topic_tags='',embedding_ref='',source_version_hash=REPEAT('0',64),share_scope='private',updated_at=NOW() WHERE knowledge_id=?");
    $stmt->execute([$knowledgeId]);
}

function shared_knowledge_index_sync_item_v236(PDO $pdo,int $knowledgeId): void
{
    if(!shared_knowledge_index_schema_ready_v236($pdo))shared_knowledge_index_ensure_schema_v236($pdo);
    $item=shared_knowledge_index_item_v236($pdo,$knowledgeId);
    if(!$item){$pdo->prepare('DELETE FROM shared_knowledge_index WHERE knowledge_id=?')->execute([$knowledgeId]);return;}
    $owner=(int)($item['created_by_user_id']??0);
    // This index is exclusively for owner-controlled personal knowledge.
    // System knowledge has its own permission/visibility retrieval path.
    if($owner<1||(string)($item['knowledge_scope']??'system')!=='personal'){
        shared_knowledge_index_revoke_v236($pdo,$knowledgeId);return;
    }
    $policy=user_data_policy_get_v236($pdo,$owner,'knowledge',(string)$knowledgeId);
    $shared=!empty($policy['stonefellow_shared'])&&!empty($item['is_published']);
    if(!$shared){shared_knowledge_index_revoke_v236($pdo,$knowledgeId);return;}
    $searchText=trim((string)$item['title'].' '.(string)$item['description'].' '.(string)$item['content_text'].' '.implode(' ',(array)$item['chunk_texts']));
    $hash=shared_knowledge_index_hash_v236($item);$tags=shared_knowledge_index_tags_v236($searchText);$scope=(string)($policy['audience_scope']??'inherit');
    $stmt=$pdo->prepare("INSERT INTO shared_knowledge_index (knowledge_id,owner_user_id,source_version_hash,title,topic_tags,embedding_ref,share_scope,is_indexed,last_indexed_at,revoked_at) VALUES (?,?,?,?,?,'',?,1,NOW(),NULL) ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id),source_version_hash=VALUES(source_version_hash),title=VALUES(title),topic_tags=VALUES(topic_tags),share_scope=VALUES(share_scope),is_indexed=1,last_indexed_at=NOW(),revoked_at=NULL");
    $stmt->execute([$knowledgeId,$owner,$hash,mb_strimwidth((string)$item['title'],0,190,''),$tags,$scope]);
}

function shared_knowledge_index_sync_owner_v236(PDO $pdo,int $ownerUserId): void
{
    if($ownerUserId<1)return;
    if(!shared_knowledge_index_schema_ready_v236($pdo))shared_knowledge_index_ensure_schema_v236($pdo);
    if(!column_exists('knowledge_items','knowledge_scope'))return;
    $stmt=$pdo->prepare("SELECT id FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' ORDER BY id ASC");$stmt->execute([$ownerUserId]);
    $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
    foreach($ids as $id)shared_knowledge_index_sync_item_v236($pdo,$id);
    if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$args=array_merge([$ownerUserId],$ids);$stale=$pdo->prepare("SELECT knowledge_id FROM shared_knowledge_index WHERE owner_user_id=? AND knowledge_id NOT IN ({$marks})");$stale->execute($args);foreach($stale->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)shared_knowledge_index_revoke_v236($pdo,(int)$id);}
    else{$stale=$pdo->prepare('SELECT knowledge_id FROM shared_knowledge_index WHERE owner_user_id=?');$stale->execute([$ownerUserId]);foreach($stale->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)shared_knowledge_index_revoke_v236($pdo,(int)$id);}
}

function shared_knowledge_index_sync_due_v236(PDO $pdo,int $limit=60): void
{
    if(!shared_knowledge_index_schema_ready_v236($pdo)||!table_exists('user_data_policies')||!table_exists('knowledge_items')||!column_exists('knowledge_items','knowledge_scope'))return;
    $limit=max(1,min(200,$limit));
    $sql="SELECT k.id FROM knowledge_items k
          LEFT JOIN user_data_policies pe ON pe.owner_user_id=k.created_by_user_id AND pe.resource_type='knowledge' AND pe.resource_id=CAST(k.id AS CHAR)
          LEFT JOIN user_data_policies pw ON pw.owner_user_id=k.created_by_user_id AND pw.resource_type='knowledge' AND pw.resource_id='*'
          LEFT JOIN shared_knowledge_index i ON i.knowledge_id=k.id
          WHERE k.knowledge_scope='personal' AND k.created_by_user_id IS NOT NULL AND k.is_published=1
            AND COALESCE(pe.stonefellow_shared,pw.stonefellow_shared,0)=1
            AND (i.knowledge_id IS NULL OR i.is_indexed=0 OR i.revoked_at IS NOT NULL OR k.updated_at>i.last_indexed_at)
          ORDER BY k.updated_at DESC,k.id DESC LIMIT {$limit}";
    try{$ids=$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[];}catch(Throwable $e){return;}
    foreach($ids as $id)shared_knowledge_index_sync_item_v236($pdo,(int)$id);
}

function shared_knowledge_index_candidates_v236(PDO $pdo,string $query,int $limit=40): array
{
    if(!shared_knowledge_index_schema_ready_v236($pdo)||!column_exists('knowledge_items','knowledge_scope'))return [];
    shared_knowledge_index_sync_due_v236($pdo,60);
    $terms=preg_split('/[^\pL\pN]+/u',mb_strtolower(trim($query)))?:[];$terms=array_values(array_unique(array_filter($terms,static fn($v)=>mb_strlen($v)>=2)));
    $rows=$pdo->query("SELECT i.knowledge_id,i.owner_user_id,i.source_version_hash,i.title,i.topic_tags,i.share_scope,i.last_indexed_at FROM shared_knowledge_index i INNER JOIN knowledge_items k ON k.id=i.knowledge_id AND k.knowledge_scope='personal' AND k.is_published=1 WHERE i.is_indexed=1 AND i.revoked_at IS NULL ORDER BY i.updated_at DESC,i.knowledge_id DESC LIMIT 500")->fetchAll()?:[];
    if(!$terms)return array_slice($rows,0,$limit);
    $scored=[];foreach($rows as $row){$hay=mb_strtolower((string)$row['title'].' '.(string)$row['topic_tags']);$score=0;foreach($terms as $term){if(str_contains($hay,$term))$score+=str_contains(mb_strtolower((string)$row['title']),$term)?3:1;}if($score>0)$scored[]=['score'=>$score,'row'=>$row];}
    usort($scored,static fn($a,$b)=>$b['score']<=>$a['score']);return array_slice(array_column($scored,'row'),0,$limit);
}
