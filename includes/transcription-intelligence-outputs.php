<?php
declare(strict_types=1);

/**
 * VP3 transcription intelligence outputs v306.
 *
 * Summary and Action Plan are derived only from current human-accepted durable
 * intelligence plus human-accepted v305 connections. They are persisted as
 * ordinary analysis modules in the existing master JSON; no new table or
 * frontend controller is introduced.
 */
const VP3_TRANSCRIPTION_INTELLIGENCE_OUTPUTS_V306 = 'transcription-intelligence-outputs-v306-20260906';
const VP3_TRANSCRIPTION_OUTPUT_MAX_SOURCE_ITEMS_V306 = 100;
const VP3_TRANSCRIPTION_OUTPUT_MAX_SOURCE_RELATIONS_V306 = 60;

function transcription_output_registry_v306(): array
{
    return [
        'summary_output'=>[
            'id'=>'summary_output','label'=>'Summary','title'=>'Summary','description'=>'Creates a reviewed executive summary from accepted transcription intelligence and accepted connections.','execution'=>'ai','live'=>false,'view'=>'sections',
            'sections'=>[
                ['key'=>'overview','title'=>'Executive summary','primary'=>'summary','meta'=>['source_plugins','evidence','confidence']],
                ['key'=>'key_points','title'=>'Key points','primary'=>'point','meta'=>['source_plugins','evidence','confidence']],
                ['key'=>'decisions','title'=>'Decisions','primary'=>'decision','meta'=>['source_plugins','evidence','confidence']],
                ['key'=>'risks','title'=>'Risks & blockers','primary'=>'risk','meta'=>['impact','source_plugins','evidence','confidence']],
                ['key'=>'open_questions','title'=>'Open questions','primary'=>'question','meta'=>['source_plugins','evidence','confidence']],
                ['key'=>'next_steps','title'=>'Next steps','primary'=>'next_step','meta'=>['owner','timing','source_plugins','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Derived output generated only from reviewed intelligence. It is not a raw-transcript analysis plugin.',
        ],
        'action_plan'=>[
            'id'=>'action_plan','label'=>'Action Plan','title'=>'Action Plan','description'=>'Builds a grounded action plan from accepted actions, commitments, requirements, follow-ups, blockers and accepted connections.','execution'=>'ai','live'=>false,'view'=>'sections',
            'sections'=>[
                ['key'=>'objective','title'=>'Objective','primary'=>'objective','meta'=>['source_plugins','evidence','confidence']],
                ['key'=>'actions','title'=>'Actions','primary'=>'action','meta'=>['owner','timing','priority','status','dependency','source_plugins','evidence','confidence']],
                ['key'=>'blockers','title'=>'Blockers','primary'=>'blocker','meta'=>['impact','owner','source_plugins','evidence','confidence']],
                ['key'=>'follow_ups','title'=>'Follow-ups','primary'=>'follow_up','meta'=>['owner','timing','status','source_plugins','evidence','confidence']],
            ],
            'legacy'=>[],
            'prompt'=>'Derived output generated only from reviewed intelligence. It is not a raw-transcript analysis plugin.',
        ],
    ];
}

function transcription_app_registry_v306(): array
{
    return array_replace(transcription_app_registry_v301(),transcription_output_registry_v306());
}

function transcription_app_registry_public_v306(): array
{
    $out=[];
    foreach (transcription_app_registry_v306() as $app) {
        $out[]=[
            'id'=>$app['id'],'label'=>$app['label'],'title'=>$app['title'],'description'=>$app['description'],
            'execution'=>$app['execution'],'live'=>(bool)$app['live'],'view'=>$app['view'],'sections'=>$app['sections'],
        ];
    }
    return $out;
}

function transcription_app_ids_v306(mixed $value): array
{
    $registry=transcription_app_registry_v306();
    if (!is_array($value)) return ['basic'];
    $out=[];
    foreach ($value as $item) {
        $id=strtolower(trim((string)$item));
        if (isset($registry[$id]) && !in_array($id,$out,true)) $out[]=$id;
    }
    return $out ?: ['basic'];
}

function transcription_output_ids_v306(): array
{
    return array_keys(transcription_output_registry_v306());
}

function transcription_output_only_modules_v306(array $analysis, ?array $master=null): array
{
    $stored=is_array($analysis['modules']??null)?$analysis['modules']:[];
    $out=[];
    foreach (transcription_output_registry_v306() as $id=>$app) {
        $module=$stored[$id]??null;
        if (!is_array($module)) continue;
        $result=is_array($module['result']??null)?transcription_app_sanitize_value_v300($module['result']):[];
        $out[$id]=[
            'app_id'=>$id,'source_hash'=>(string)($module['source_hash']??$master['source_hash']??''),
            'source_word_count'=>max(0,(int)($module['source_word_count']??$master['word_count']??0)),
            'generated_at'=>(string)($module['generated_at']??$master['generated_at']??''),
            'provider'=>(string)($module['provider']??$master['provider']??''),'model'=>(string)($module['model']??$master['model']??''),
            'contract_version'=>max(1,(int)($module['contract_version']??1)),'context_hash'=>(string)($module['context_hash']??''),
            'result'=>is_array($result)?$result:[],
        ];
    }
    return $out;
}

function transcription_app_modules_v306(array $analysis, ?array $master=null): array
{
    return transcription_app_modules_v301($analysis,$master)+transcription_output_only_modules_v306($analysis,$master);
}

function transcription_output_projection_v306(array $modules): array
{
    $projection=transcription_app_compat_projection_v300($modules);
    $projection['registry_version']=306;
    $projection['intelligence_items_version']=302;
    $projection['workflow_version']=304;
    $projection['relations_version']=305;
    $projection['outputs_version']=306;
    return $projection;
}

function transcription_output_persist_modules_v306(PDO $pdo,int $sessionId,array $modules): array
{
    $projection=transcription_output_projection_v306($modules);
    $json=json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not encode transcription intelligence outputs.');
    $stmt=$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?');
    $stmt->execute([$json,$sessionId]);
    if ($stmt->rowCount()<1) {
        $check=$pdo->prepare('SELECT session_id FROM artist_transcript_master_analysis_v237 WHERE session_id=? LIMIT 1');
        $check->execute([$sessionId]);
        if (!$check->fetchColumn()) throw new RuntimeException('The transcription outputs could not be updated.');
    }
    return $projection;
}

function transcription_output_normalize_master_v306(
    PDO $pdo,int $sessionId,array $master,array $priorReviewIndex=[],bool $persist=true
): array {
    $analysis=is_array($master['analysis']??null)?$master['analysis']:[];
    $outputs=transcription_output_only_modules_v306($analysis,$master);
    $normalized=transcription_intelligence_normalize_master_v302($pdo,$sessionId,$master,$priorReviewIndex,false);
    $base=transcription_app_modules_v301(is_array($normalized['analysis']??null)?$normalized['analysis']:[],$normalized);
    $modules=$base+$outputs;
    $projection=transcription_output_projection_v306($modules);
    if ($persist && $projection!==$analysis) transcription_output_persist_modules_v306($pdo,$sessionId,$modules);
    $normalized['analysis']=$projection;
    return $normalized;
}

function transcription_output_restore_v306(PDO $pdo,int $sessionId,array $master,array $outputs): array
{
    if (!$outputs) return $master;
    $base=transcription_app_modules_v301(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $modules=$base+$outputs;
    $master['analysis']=transcription_output_persist_modules_v306($pdo,$sessionId,$modules);
    return $master;
}

function transcription_output_source_text_v306(string $appId,string $sectionKey,array $item): string
{
    $primary=transcription_intelligence_primary_v302($appId,$sectionKey);
    return transcription_app_clean_v300((string)($item[$primary]??$item['text']??$item['value']??''),1400);
}

function transcription_output_reviewed_input_v306(array $modules,string $currentHash): array
{
    $registry=transcription_app_registry_v301();
    $items=[];
    foreach ($modules as $appId=>$module) {
        if (!isset($registry[$appId]) || !is_array($module)) continue;
        if ($currentHash==='' || !hash_equals($currentHash,(string)($module['source_hash']??''))) continue;
        $result=is_array($module['result']??null)?$module['result']:[];
        foreach ((array)($registry[$appId]['sections']??[]) as $section) {
            $sectionKey=(string)($section['key']??'');
            foreach ((array)($result[$sectionKey]??[]) as $item) {
                if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
                $itemId=trim((string)($item['item_id']??''));
                $text=transcription_output_source_text_v306($appId,$sectionKey,$item);
                if ($itemId==='' || $text==='') continue;
                $refs=[];
                foreach ((array)($item['evidence_refs']??[]) as $ref) {
                    if (!is_array($ref)) continue;
                    $page=max(0,(int)($ref['page']??0));
                    if ($page>0) $refs[$page]=['page'=>$page,'label'=>'Page '.$page];
                }
                ksort($refs);
                $items[$itemId]=[
                    'item_id'=>$itemId,'plugin_id'=>$appId,'plugin_title'=>(string)($registry[$appId]['title']??$appId),
                    'section_key'=>$sectionKey,'text'=>$text,'evidence_refs'=>array_values($refs),
                ];
                if (count($items)>=VP3_TRANSCRIPTION_OUTPUT_MAX_SOURCE_ITEMS_V306) break 3;
            }
        }
    }
    ksort($items);

    $relations=[];
    foreach ($items as $itemId=>$source) {
        $module=$modules[(string)$source['plugin_id']]??null;
        if (!is_array($module)) continue;
        $result=is_array($module['result']??null)?$module['result']:[];
        $rows=(array)($result[(string)$source['section_key']]??[]);
        $sourceItem=null;
        foreach ($rows as $row) if (is_array($row) && (string)($row['item_id']??'')===$itemId) {$sourceItem=$row;break;}
        if (!is_array($sourceItem)) continue;
        foreach ((array)($sourceItem['relations']??[]) as $relation) {
            if (!is_array($relation) || (string)($relation['review_state']??'')!=='accepted') continue;
            $relationId=trim((string)($relation['relation_id']??''));
            $otherId=trim((string)($relation['other_item_id']??''));
            if ($relationId==='' || !isset($items[$otherId]) || isset($relations[$relationId])) continue;
            if (!hash_equals($currentHash,(string)($relation['source_hash']??''))) continue;
            $direction=(string)($relation['direction']??'peer');
            if ($direction==='outgoing') {$from=$itemId;$to=$otherId;}
            elseif ($direction==='incoming') {$from=$otherId;$to=$itemId;}
            else { $pair=[$itemId,$otherId];sort($pair,SORT_STRING);[$from,$to]=$pair; }
            $relations[$relationId]=[
                'relation_id'=>$relationId,'type'=>(string)($relation['type']??'related'),'source_item_id'=>$from,'target_item_id'=>$to,
                'rationale'=>transcription_app_clean_v300((string)($relation['rationale']??''),420),
                'confidence'=>(string)($relation['confidence']??'medium'),
            ];
            if (count($relations)>=VP3_TRANSCRIPTION_OUTPUT_MAX_SOURCE_RELATIONS_V306) break 2;
        }
    }
    ksort($relations);
    return ['items'=>array_values($items),'relations'=>array_values($relations)];
}

function transcription_output_input_hash_v306(array $input,string $currentHash): string
{
    if (!$input['items']) return '';
    $json=json_encode(['source_hash'=>$currentHash,'items'=>$input['items'],'relations'=>$input['relations']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return hash('sha256',is_string($json)?$json:'');
}

function transcription_output_prompt_v306(array $ids,array $input,array $session): string
{
    $contracts=[];
    if (in_array('summary_output',$ids,true)) {
        $contracts[]='summary_output: {overview:[{summary,source_item_ids,confidence}],key_points:[{point,source_item_ids,confidence}],decisions:[{decision,source_item_ids,confidence}],risks:[{risk,impact,source_item_ids,confidence}],open_questions:[{question,source_item_ids,confidence}],next_steps:[{next_step,owner,timing,source_item_ids,confidence}]}.';
    }
    if (in_array('action_plan',$ids,true)) {
        $contracts[]='action_plan: {objective:[{objective,source_item_ids,confidence}],actions:[{action,owner,timing,priority,status,dependency,source_item_ids,confidence}],blockers:[{blocker,impact,owner,source_item_ids,confidence}],follow_ups:[{follow_up,owner,timing,status,source_item_ids,confidence}]}.';
    }
    return "Create final user-facing transcription outputs from REVIEWED INTELLIGENCE only. The accepted intelligence items below are the only factual source. ACCEPTED CONNECTIONS may explain how accepted items relate, but they are not independent facts. Do not use raw transcript text, unreviewed findings, rejected findings, Agent Brain, CRM or outside knowledge. Do not invent owners, timing, deadlines, priorities, dependencies, decisions, risks or certainty. When owner or timing is unsupported use unknown. Every output row must include source_item_ids containing one or more exact item IDs from REVIEWED INTELLIGENCE that directly support the row. Omit a row rather than using unsupported source IDs. Keep the output concise, useful and non-redundant.\n\nReturn ONLY JSON as {\"apps\":{...}} and include every requested output exactly once.\n\nREQUESTED OUTPUT CONTRACTS:\n".implode("\n",$contracts)."\n\nTRANSCRIPT TITLE:\n".transcription_app_clean_v300((string)($session['title']??''),190)."\n\nREVIEWED INTELLIGENCE:\n".json_encode($input['items'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n\nACCEPTED CONNECTIONS:\n".json_encode($input['relations'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function transcription_output_validate_result_v306(string $id,mixed $value,array $input): array
{
    $registry=transcription_output_registry_v306();
    if (!isset($registry[$id]) || !is_array($value)) return [];
    $sources=[];
    foreach ($input['items'] as $item) if (is_array($item) && trim((string)($item['item_id']??''))!=='') $sources[(string)$item['item_id']]=$item;
    $result=[];$total=0;
    foreach ((array)$registry[$id]['sections'] as $section) {
        $key=(string)$section['key'];$primary=(string)$section['primary'];$rows=[];
        foreach ((array)($value[$key]??[]) as $row) {
            if (!is_array($row)) continue;
            $text=transcription_app_clean_v300((string)($row[$primary]??''),1600);
            if ($text==='') continue;
            $sourceIds=[];
            foreach ((array)($row['source_item_ids']??[]) as $sourceId) {
                $sourceId=trim((string)$sourceId);
                if ($sourceId!=='' && isset($sources[$sourceId]) && !in_array($sourceId,$sourceIds,true)) $sourceIds[]=$sourceId;
                if (count($sourceIds)>=12) break;
            }
            if (!$sourceIds) continue;
            $pages=[];$plugins=[];
            foreach ($sourceIds as $sourceId) {
                $source=$sources[$sourceId];
                $plugins[(string)$source['plugin_title']]=(string)$source['plugin_title'];
                foreach ((array)$source['evidence_refs'] as $ref) if (is_array($ref) && (int)($ref['page']??0)>0) $pages[(int)$ref['page']='Page '.(int)$ref['page'];
            }
            ksort($pages);ksort($plugins);
            $clean=transcription_app_sanitize_value_v300($row);
            if (!is_array($clean)) $clean=[];
            $clean[$primary]=$text;
            $clean['source_item_ids']=$sourceIds;
            $clean['source_plugins']=array_values($plugins);
            $clean['evidence']=implode(', ',array_values($pages));
            $confidence=strtolower(trim((string)($clean['confidence']??'medium')));
            $clean['confidence']=in_array($confidence,['high','medium','low'],true)?$confidence:'medium';
            foreach (['owner','timing','priority','status','dependency','impact'] as $field) if (isset($clean[$field])) $clean[$field]=transcription_app_clean_v300((string)$clean[$field],300);
            $rows[]=$clean;$total++;
            if (count($rows)>=12) break;
        }
        $result[$key]=$rows;
    }
    return $total>0?$result:[];
}

function transcription_output_generate_v306(
    PDO $pdo,array $user,array $session,array $master,string $currentHash,array $ids
): array {
    $ids=array_values(array_intersect(transcription_output_ids_v306(),$ids));
    if (!$ids) return ['master'=>$master,'executed_apps'=>[],'plugin_errors'=>[]];
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $input=transcription_output_reviewed_input_v306($modules,$currentHash);
    $inputHash=transcription_output_input_hash_v306($input,$currentHash);
    if ($inputHash==='') throw new RuntimeException('Accept at least one current intelligence finding before generating Summary or Action Plan.');

    $ai=artist_listening_v237_ai(transcription_output_prompt_v306($ids,$input,$session),$user,4200);
    $decoded=transcription_app_decode_json_v300((string)$ai['answer']);
    $returned=is_array($decoded['apps']??null)?$decoded['apps']:[];
    $errors=[];$executed=[];
    foreach ($ids as $id) {
        $result=transcription_output_validate_result_v306($id,$returned[$id]??null,$input);
        if (!$result) {$errors[$id]='The AI provider returned no grounded '.$id.' output. Existing output was not overwritten.';continue;}
        $modules[$id]=[
            'app_id'=>$id,'source_hash'=>$currentHash,'source_word_count'=>max(0,(int)($master['word_count']??0)),
            'generated_at'=>gmdate('c'),'provider'=>(string)($ai['provider']??''),'model'=>(string)($ai['model']??''),
            'contract_version'=>1,'context_hash'=>$inputHash,'result'=>$result,
        ];
        $executed[]=$id;
    }
    if (!$executed && $errors) throw new RuntimeException((string)reset($errors));
    $master['analysis']=transcription_output_persist_modules_v306($pdo,(int)$session['id'],$modules);
    return ['master'=>$master,'executed_apps'=>$executed,'plugin_errors'=>$errors,'input_hash'=>$inputHash,'source_items'=>count($input['items']),'accepted_connections'=>count($input['relations'])];
}

function transcription_app_status_v306(PDO $pdo,array $user,?array $session,?array $master,array $map): array
{
    $base=transcription_app_status_v301($pdo,$user,$session,$master,$map);
    $analysis=is_array($master['analysis']??null)?$master['analysis']:[];
    $modules=transcription_app_modules_v306($analysis,$master);
    if ($master) $master['analysis']=transcription_output_projection_v306($modules);
    $currentHash=(string)($map['source_hash']??'');
    $input=transcription_output_reviewed_input_v306($modules,$currentHash);
    $inputHash=transcription_output_input_hash_v306($input,$currentHash);
    $status=$base['app_status']??[];
    foreach (transcription_output_registry_v306() as $id=>$app) {
        $module=$modules[$id]??null;$fresh=false;$reason='not_generated';
        if (is_array($module)) {
            if ($currentHash==='' || !hash_equals($currentHash,(string)($module['source_hash']??''))) $reason='transcript_changed';
            elseif ($inputHash==='' || !hash_equals($inputHash,(string)($module['context_hash']??''))) $reason='reviewed_intelligence_changed';
            else {$fresh=true;$reason='current';}
        }
        $status[$id]=[
            'generated'=>is_array($module),'fresh'=>$fresh,'fresh_reason'=>$reason,
            'generated_at'=>(string)($module['generated_at']??''),'provider'=>(string)($module['provider']??''),'model'=>(string)($module['model']??''),
            'word_count'=>max(0,(int)($module['source_word_count']??0)),
        ];
    }
    return ['master'=>$master,'app_status'=>$status,'registry'=>transcription_app_registry_public_v306(),'output_input'=>['accepted_items'=>count($input['items']),'accepted_connections'=>count($input['relations']),'input_hash'=>$inputHash]];
}

function transcription_app_analyze_v306(
    PDO $pdo,array $user,int $sessionId,string $mode,array $requestedApps,mixed $workflowInput=[]
): array {
    $requested=transcription_app_ids_v306($requestedApps);
    $outputIds=array_values(array_intersect($requested,transcription_output_ids_v306()));
    $baseIds=array_values(array_diff($requested,$outputIds));
    if ($mode==='live' && $outputIds) $outputIds=[];

    $session=artist_listening_v172_session($pdo,$user,$sessionId);
    $segments=artist_listening_v172_segments($pdo,$sessionId);
    $map=artist_listening_transcript_page_map($segments);
    $beforeStatus=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
    $beforeMaster=is_array($beforeStatus['master']??null)?$beforeStatus['master']:null;
    $priorOutputs=$beforeMaster?transcription_output_only_modules_v306(is_array($beforeMaster['analysis']??null)?$beforeMaster['analysis']:[],$beforeMaster):[];
    $reviewIndex=transcription_intelligence_review_index_v302($beforeMaster);

    $result=['skipped'=>false,'requested_apps'=>$requested,'executed_apps'=>[],'plugin_errors'=>[],'workflow'=>transcription_workflow_normalize_v304($workflowInput)];
    if ($baseIds) {
        $result=transcription_app_analyze_v304($pdo,$user,$sessionId,$mode,$baseIds,$workflowInput);
        $result['requested_apps']=$requested;
    }

    $latest=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
    $master=is_array($latest['master']??null)?$latest['master']:null;
    if (!$master) {
        $result += transcription_app_status_v306($pdo,$user,$session,null,$map);
        return $result;
    }
    $master=transcription_output_normalize_master_v306($pdo,$sessionId,$master,$reviewIndex,false);
    if ($priorOutputs) $master=transcription_output_restore_v306($pdo,$sessionId,$master,$priorOutputs);
    else $master['analysis']=transcription_output_persist_modules_v306($pdo,$sessionId,transcription_app_modules_v306((array)$master['analysis'],$master));

    if ($outputIds && $mode!=='live') {
        try {
            $generated=transcription_output_generate_v306($pdo,$user,$session,$master,(string)$map['source_hash'],$outputIds);
            $master=$generated['master'];
            $result['executed_apps']=array_values(array_unique(array_merge((array)($result['executed_apps']??[]),(array)$generated['executed_apps'])));
            $result['plugin_errors']=array_replace((array)($result['plugin_errors']??[]),(array)$generated['plugin_errors']);
            $result['output_source_items']=$generated['source_items']??0;
            $result['output_accepted_connections']=$generated['accepted_connections']??0;
        } catch (Throwable $e) {
            $message=ai_v100_safe_exception($e,'Transcription output generation failed.');
            foreach ($outputIds as $id) $result['plugin_errors'][$id]=$message;
            if (!$baseIds) throw new RuntimeException($message);
        }
    }

    $view=transcription_app_status_v306($pdo,$user,$session,$master,$map);
    $result['master']=$view['master'];$result['app_status']=$view['app_status'];$result['registry']=$view['registry'];$result['output_input']=$view['output_input'];
    return $result;
}
