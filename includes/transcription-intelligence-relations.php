<?php
declare(strict_types=1);

/**
 * VP3 transcription intelligence relationships v305.
 *
 * Relationships are stored as reciprocal adjacency metadata on the existing
 * durable intelligence items. There is no parallel graph table or hidden
 * workflow. Building relationships is an explicit, bounded AI action.
 */
const VP3_TRANSCRIPTION_INTELLIGENCE_RELATIONS_V305 = 'transcription-intelligence-relations-v305-20260906';
const VP3_TRANSCRIPTION_RELATION_MAX_ITEMS_V305 = 80;
const VP3_TRANSCRIPTION_RELATION_MAX_EDGES_V305 = 60;

function transcription_intelligence_relation_types_v305(): array
{
    return ['supports','contradicts','depends_on','answers','follows_from','blocks','duplicates','related'];
}

function transcription_intelligence_relation_symmetric_v305(string $type): bool
{
    return in_array($type,['duplicates','related'],true);
}

function transcription_intelligence_relation_id_v305(string $sourceId, string $type, string $targetId): string
{
    $type=strtolower(trim($type));
    if (!in_array($type,transcription_intelligence_relation_types_v305(),true)) throw new RuntimeException('Unsupported intelligence relationship type.');
    $sourceId=trim($sourceId);$targetId=trim($targetId);
    if ($sourceId==='' || $targetId==='' || hash_equals($sourceId,$targetId)) throw new RuntimeException('A relationship needs two different intelligence items.');
    if (transcription_intelligence_relation_symmetric_v305($type) && strcmp($sourceId,$targetId)>0) [$sourceId,$targetId]=[$targetId,$sourceId];
    return 'tir_'.substr(hash('sha256',$sourceId.'|'.$type.'|'.$targetId),0,20);
}

function transcription_intelligence_relation_text_v305(array $item, string $appId, string $sectionKey): string
{
    $primary=transcription_intelligence_primary_v302($appId,$sectionKey);
    return transcription_app_clean_v300((string)($item[$primary]??$item['text']??$item['value']??''),900);
}

function transcription_intelligence_relation_catalog_v305(array $modules, string $currentHash): array
{
    $registry=transcription_app_registry_v301();
    $rows=[];
    foreach ($modules as $appId=>$module) {
        if (!isset($registry[$appId]) || !is_array($module)) continue;
        if ($currentHash==='' || !hash_equals($currentHash,(string)($module['source_hash']??''))) continue;
        $result=is_array($module['result']??null)?$module['result']:[];
        foreach ((array)($registry[$appId]['sections']??[]) as $section) {
            $sectionKey=(string)($section['key']??'');
            foreach ((array)($result[$sectionKey]??[]) as $item) {
                if (!is_array($item) || (string)($item['review_state']??'')==='rejected') continue;
                $itemId=trim((string)($item['item_id']??''));
                $text=transcription_intelligence_relation_text_v305($item,$appId,$sectionKey);
                if ($itemId==='' || $text==='') continue;
                $rows[]=[
                    'item_id'=>$itemId,
                    'plugin_id'=>$appId,
                    'plugin_title'=>(string)($registry[$appId]['title']??$appId),
                    'section_key'=>$sectionKey,
                    'section_title'=>(string)($section['title']??$sectionKey),
                    'text'=>$text,
                    'review_state'=>(string)($item['review_state']??'unreviewed'),
                    'evidence_refs'=>array_values(array_filter((array)($item['evidence_refs']??[]),static fn($ref):bool=>is_array($ref)&&max(0,(int)($ref['page']??0))>0)),
                ];
            }
        }
    }
    usort($rows,static function(array $a,array $b):int{
        $rank=static fn(array $row):int=>(string)($row['review_state']??'')==='accepted'?0:1;
        return $rank($a)<=>$rank($b) ?: strcmp((string)$a['item_id'],(string)$b['item_id']);
    });
    return array_slice($rows,0,VP3_TRANSCRIPTION_RELATION_MAX_ITEMS_V305);
}

function transcription_intelligence_relation_review_index_v305(array $modules): array
{
    $index=[];
    foreach ($modules as $module) {
        if (!is_array($module)) continue;
        foreach ((array)($module['result']??[]) as $rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $item) {
                if (!is_array($item)) continue;
                foreach ((array)($item['relations']??[]) as $relation) {
                    if (!is_array($relation)) continue;
                    $id=trim((string)($relation['relation_id']??''));
                    if ($id==='' || isset($index[$id])) continue;
                    $state=strtolower(trim((string)($relation['review_state']??'unreviewed')));
                    if (!in_array($state,['unreviewed','accepted','rejected'],true)) $state='unreviewed';
                    $index[$id]=['review_state'=>$state,'reviewed_at'=>(string)($relation['reviewed_at']??'')];
                }
            }
        }
    }
    return $index;
}

function transcription_intelligence_relation_item_map_v305(array &$modules): array
{
    $registry=transcription_app_registry_v301();
    $map=[];
    foreach ($modules as $appId=>&$module) {
        if (!isset($registry[$appId]) || !is_array($module)) continue;
        foreach ((array)($registry[$appId]['sections']??[]) as $section) {
            $sectionKey=(string)($section['key']??'');
            if (!is_array($module['result'][$sectionKey]??null)) continue;
            foreach ($module['result'][$sectionKey] as $offset=>&$item) {
                if (!is_array($item)) continue;
                $itemId=trim((string)($item['item_id']??''));
                if ($itemId==='') continue;
                $map[$itemId]=[
                    'app_id'=>$appId,'section_key'=>$sectionKey,'offset'=>(int)$offset,
                    'source_hash'=>(string)($module['source_hash']??''),'item'=>&$item,
                ];
            }
            unset($item);
        }
    }
    unset($module);
    return $map;
}

function transcription_intelligence_prune_relations_v305(array &$modules): void
{
    $map=transcription_intelligence_relation_item_map_v305($modules);
    foreach ($map as $itemId=>&$entry) {
        $item=&$entry['item'];
        $kept=[];
        foreach ((array)($item['relations']??[]) as $relation) {
            if (!is_array($relation)) continue;
            $otherId=trim((string)($relation['other_item_id']??''));
            $hash=(string)($relation['source_hash']??'');
            if ($otherId==='' || !isset($map[$otherId]) || $hash==='') continue;
            if (!hash_equals($hash,(string)$entry['source_hash']) || !hash_equals($hash,(string)$map[$otherId]['source_hash'])) continue;
            $type=strtolower(trim((string)($relation['type']??'')));
            if (!in_array($type,transcription_intelligence_relation_types_v305(),true)) continue;
            $kept[]=$relation;
            if (count($kept)>=12) break;
        }
        if ($kept) $item['relations']=$kept; else unset($item['relations']);
    }
    unset($entry);
}

function transcription_intelligence_remove_item_relations_v305(array &$modules, string $itemId): void
{
    $itemId=trim($itemId);
    if ($itemId==='') return;
    $map=transcription_intelligence_relation_item_map_v305($modules);
    foreach ($map as &$entry) {
        $item=&$entry['item'];
        $relations=[];
        foreach ((array)($item['relations']??[]) as $relation) {
            if (!is_array($relation)) continue;
            if ((string)($item['item_id']??'')===$itemId || (string)($relation['other_item_id']??'')===$itemId) continue;
            $relations[]=$relation;
        }
        if ($relations) $item['relations']=$relations; else unset($item['relations']);
    }
    unset($entry);
}

function transcription_intelligence_clear_relations_v305(array &$modules): void
{
    $map=transcription_intelligence_relation_item_map_v305($modules);
    foreach ($map as &$entry) unset($entry['item']['relations']);
    unset($entry);
}

function transcription_intelligence_relation_evidence_v305(array $left, array $right): array
{
    $leftPages=[];$rightPages=[];
    foreach ((array)($left['evidence_refs']??[]) as $ref) if (is_array($ref) && (int)($ref['page']??0)>0) $leftPages[(int)$ref['page']]=(int)$ref['page'];
    foreach ((array)($right['evidence_refs']??[]) as $ref) if (is_array($ref) && (int)($ref['page']??0)>0) $rightPages[(int)$ref['page']]=(int)$ref['page'];
    $pages=array_intersect_key($leftPages,$rightPages);
    if (!$pages) $pages=$leftPages+$rightPages;
    ksort($pages);
    return array_map(static fn(int $page):array=>['page'=>$page,'label'=>'Page '.$page],array_slice(array_values($pages),0,8));
}

function transcription_intelligence_relation_prompt_v305(array $catalog): string
{
    return "Build a cross-plugin relationship graph for durable transcription intelligence items. Link ONLY items from different plugins. Every relationship must be supported by the item texts and their transcript evidence; do not add new facts, identities, deadlines or conclusions. Prefer a small high-value graph over dense weak links. Use related only when a more specific relationship is not justified. duplicates means materially the same finding, not merely the same topic. contradicts requires a real incompatibility. answers requires one item to resolve the question/issue represented by another. depends_on and blocks must have a clear dependency direction.\n\nAllowed relationship types: supports, contradicts, depends_on, answers, follows_from, blocks, duplicates, related.\n\nReturn ONLY JSON: {\"relations\":[{\"source_item_id\":\"ti_...\",\"target_item_id\":\"ti_...\",\"type\":\"supports\",\"rationale\":\"short evidence-grounded reason\",\"confidence\":\"high|medium|low\"}]}. Return an empty relations array when no strong cross-plugin links exist. Maximum " . VP3_TRANSCRIPTION_RELATION_MAX_EDGES_V305 . " relationships.\n\nINTELLIGENCE ITEMS:\n" . json_encode($catalog,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function transcription_intelligence_build_relations_v305(
    PDO $pdo,array $user,array $session,array $master,string $currentHash
): array {
    $modules=transcription_app_modules_v301(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $modules=transcription_intelligence_normalize_modules_v302($modules);
    transcription_intelligence_prune_relations_v305($modules);
    $catalog=transcription_intelligence_relation_catalog_v305($modules,$currentHash);
    $plugins=[];
    foreach ($catalog as $row) $plugins[(string)$row['plugin_id']]=true;
    if (count($catalog)<2 || count($plugins)<2) throw new RuntimeException('Generate current intelligence from at least two plugins before building connections.');

    $reviewIndex=transcription_intelligence_relation_review_index_v305($modules);
    $byId=[];
    foreach ($catalog as $row) $byId[(string)$row['item_id']]=$row;
    $ai=artist_listening_v237_ai(transcription_intelligence_relation_prompt_v305($catalog),$user,3200);
    $decoded=transcription_app_decode_json_v300((string)$ai['answer']);
    $raw=is_array($decoded['relations']??null)?$decoded['relations']:[];
    $relations=[];
    foreach ($raw as $candidate) {
        if (!is_array($candidate)) continue;
        $source=trim((string)($candidate['source_item_id']??''));
        $target=trim((string)($candidate['target_item_id']??''));
        $type=strtolower(trim((string)($candidate['type']??'')));
        if (!isset($byId[$source],$byId[$target]) || $source===$target || !in_array($type,transcription_intelligence_relation_types_v305(),true)) continue;
        if ((string)$byId[$source]['plugin_id']===(string)$byId[$target]['plugin_id']) continue;
        if (transcription_intelligence_relation_symmetric_v305($type) && strcmp($source,$target)>0) [$source,$target]=[$target,$source];
        $relationId=transcription_intelligence_relation_id_v305($source,$type,$target);
        if (isset($relations[$relationId])) continue;
        $rationale=transcription_app_clean_v300((string)($candidate['rationale']??''),420);
        if ($rationale==='') continue;
        $confidence=strtolower(trim((string)($candidate['confidence']??'medium')));
        if (!in_array($confidence,['high','medium','low'],true)) $confidence='medium';
        $review=$reviewIndex[$relationId]??['review_state'=>'unreviewed','reviewed_at'=>''];
        $relations[$relationId]=[
            'relation_id'=>$relationId,'source_item_id'=>$source,'target_item_id'=>$target,'type'=>$type,
            'rationale'=>$rationale,'confidence'=>$confidence,'source_hash'=>$currentHash,'generated_at'=>gmdate('c'),
            'review_state'=>(string)$review['review_state'],'reviewed_at'=>(string)$review['reviewed_at'],
            'evidence_refs'=>transcription_intelligence_relation_evidence_v305($byId[$source],$byId[$target]),
        ];
        if (count($relations)>=VP3_TRANSCRIPTION_RELATION_MAX_EDGES_V305) break;
    }

    transcription_intelligence_clear_relations_v305($modules);
    $map=transcription_intelligence_relation_item_map_v305($modules);
    foreach ($relations as $relation) {
        $source=(string)$relation['source_item_id'];$target=(string)$relation['target_item_id'];$type=(string)$relation['type'];
        if (!isset($map[$source],$map[$target])) continue;
        $symmetric=transcription_intelligence_relation_symmetric_v305($type);
        $sourceAdj=$relation+[
            'direction'=>$symmetric?'peer':'outgoing','other_item_id'=>$target,
            'other_plugin_id'=>(string)$byId[$target]['plugin_id'],'other_plugin_title'=>(string)$byId[$target]['plugin_title'],
            'other_section_key'=>(string)$byId[$target]['section_key'],'other_text'=>(string)$byId[$target]['text'],
        ];
        unset($sourceAdj['source_item_id'],$sourceAdj['target_item_id']);
        $targetAdj=$relation+[
            'direction'=>$symmetric?'peer':'incoming','other_item_id'=>$source,
            'other_plugin_id'=>(string)$byId[$source]['plugin_id'],'other_plugin_title'=>(string)$byId[$source]['plugin_title'],
            'other_section_key'=>(string)$byId[$source]['section_key'],'other_text'=>(string)$byId[$source]['text'],
        ];
        unset($targetAdj['source_item_id'],$targetAdj['target_item_id']);
        $map[$source]['item']['relations'][]=transcription_app_sanitize_value_v300($sourceAdj);
        $map[$target]['item']['relations'][]=transcription_app_sanitize_value_v300($targetAdj);
    }
    transcription_intelligence_prune_relations_v305($modules);
    $master['analysis']=transcription_intelligence_persist_modules_v302($pdo,(int)$session['id'],$modules);
    return [
        'master'=>$master,'relations_summary'=>transcription_intelligence_relations_summary_v305($master),
        'provider'=>(string)($ai['provider']??''),'model'=>(string)($ai['model']??''),'catalog_items'=>count($catalog),
    ];
}

function transcription_intelligence_review_relation_v305(
    PDO $pdo,int $sessionId,array $master,string $relationId,string $reviewState
): array {
    $reviewState=strtolower(trim($reviewState));
    if (!in_array($reviewState,['unreviewed','accepted','rejected'],true)) throw new RuntimeException('Choose accepted, rejected or unreviewed.');
    $relationId=trim($relationId);
    if ($relationId==='') throw new RuntimeException('Choose an intelligence relationship.');
    $modules=transcription_app_modules_v301(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $modules=transcription_intelligence_normalize_modules_v302($modules);
    $found=0;$map=transcription_intelligence_relation_item_map_v305($modules);
    foreach ($map as &$entry) {
        $item=&$entry['item'];
        if (!is_array($item['relations']??null)) continue;
        foreach ($item['relations'] as &$relation) {
            if (!is_array($relation) || !hash_equals((string)($relation['relation_id']??''),$relationId)) continue;
            $relation['review_state']=$reviewState;
            $relation['reviewed_at']=$reviewState==='unreviewed'?'':gmdate('c');
            $found++;
        }
        unset($relation);
    }
    unset($entry);
    if ($found<1) throw new RuntimeException('Intelligence relationship not found.');
    $master['analysis']=transcription_intelligence_persist_modules_v302($pdo,$sessionId,$modules);
    return $master;
}

function transcription_intelligence_relations_summary_v305(?array $master): array
{
    if (!$master) return ['total'=>0,'accepted'=>0,'rejected'=>0,'unreviewed'=>0,'types'=>[]];
    $modules=transcription_app_modules_v301(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $seen=[];$summary=['total'=>0,'accepted'=>0,'rejected'=>0,'unreviewed'=>0,'types'=>[]];
    foreach ($modules as $module) {
        if (!is_array($module)) continue;
        foreach ((array)($module['result']??[]) as $rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $item) {
                if (!is_array($item)) continue;
                foreach ((array)($item['relations']??[]) as $relation) {
                    if (!is_array($relation)) continue;
                    $id=(string)($relation['relation_id']??'');
                    if ($id==='' || isset($seen[$id])) continue;
                    $seen[$id]=true;$summary['total']++;
                    $state=(string)($relation['review_state']??'unreviewed');
                    if (!isset($summary[$state])) $state='unreviewed';
                    $summary[$state]++;
                    $type=(string)($relation['type']??'related');
                    $summary['types'][$type]=($summary['types'][$type]??0)+1;
                }
            }
        }
    }
    ksort($summary['types']);
    return $summary;
}
