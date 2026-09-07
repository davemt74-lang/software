<?php
declare(strict_types=1);

const VP3_TRANSCRIPTION_DEEPER_ADVANCED_V307 = 'transcription-deeper-advanced-v307-20260907';
const VP3_TRANSCRIPTION_DEEPER_MAX_CLAIMS_V307 = 14;
const VP3_TRANSCRIPTION_DEEPER_MAX_COMPARE_ITEMS_V307 = 80;

function transcription_deeper_item_catalog_v307(
    array $modules,
    string $currentHash,
    bool $acceptedOnly=false,
    array $includeApps=[]
): array {
    $registry=transcription_app_registry_v307();
    $exclude=['changes','research_brief','summary_output','action_plan'];
    $items=[];
    foreach ($modules as $appId=>$module) {
        if (!isset($registry[$appId]) || !is_array($module) || in_array($appId,$exclude,true)) continue;
        if ($includeApps && !in_array($appId,$includeApps,true)) continue;
        if ($currentHash!=='' && !hash_equals($currentHash,(string)($module['source_hash']??''))) continue;
        $result=is_array($module['result']??null)?$module['result']:[];
        foreach ((array)($registry[$appId]['sections']??[]) as $section) {
            $sectionKey=(string)($section['key']??'');
            $primary=(string)($section['primary']??'text');
            foreach ((array)($result[$sectionKey]??[]) as $row) {
                if (!is_array($row)) continue;
                $review=(string)($row['review_state']??'unreviewed');
                if ($review==='rejected' || ($acceptedOnly && $review!=='accepted')) continue;
                $text=transcription_app_clean_v300((string)($row[$primary]??$row['text']??$row['value']??''),1200);
                if ($text==='') continue;
                $id=trim((string)($row['item_id']??''));
                if ($id==='') {
                    $fingerprint=transcription_intelligence_source_fingerprint_v302($appId,$sectionKey,$row,$primary);
                    $id='ti_'.substr($fingerprint,0,20);
                }
                $refs=[];
                foreach ((array)($row['evidence_refs']??transcription_intelligence_evidence_refs_v302($row)) as $ref) {
                    if (!is_array($ref)) continue;
                    $page=max(0,(int)($ref['page']??0));
                    if ($page>0) $refs[$page]=['page'=>$page,'label'=>'Page '.$page];
                }
                ksort($refs);
                $items[$id]=[
                    'item_id'=>$id,'plugin_id'=>$appId,'plugin_title'=>(string)($registry[$appId]['title']??$appId),
                    'section_key'=>$sectionKey,'review_state'=>$review,'text'=>$text,'evidence_refs'=>array_values($refs),
                ];
                if (count($items)>=VP3_TRANSCRIPTION_DEEPER_MAX_COMPARE_ITEMS_V307) break 3;
            }
        }
    }
    ksort($items);
    return array_values($items);
}

function transcription_deeper_catalog_index_v307(array $catalog): array
{
    $out=[];
    foreach ($catalog as $item) {
        if (!is_array($item)) continue;
        $id=trim((string)($item['item_id']??''));
        if ($id!=='') $out[$id]=$item;
    }
    return $out;
}

function transcription_deeper_claim_catalog_v307(array $modules,string $currentHash): array
{
    $catalog=transcription_deeper_item_catalog_v307($modules,$currentHash,false,['basic','entities','knowledge']);
    $claims=[];
    foreach ($catalog as $item) {
        if (!is_array($item)) continue;
        $plugin=(string)($item['plugin_id']??'');
        $section=(string)($item['section_key']??'');
        // Basic key findings, explicit entities/data and durable knowledge are the
        // only source categories treated as externally verifiable claims.
        if ($plugin==='basic' && $section!=='key_findings') continue;
        if ($plugin==='knowledge' && !in_array($section,['items'],true)) continue;
        $claims[]=$item;
        if (count($claims)>=VP3_TRANSCRIPTION_DEEPER_MAX_CLAIMS_V307) break;
    }
    return $claims;
}

function transcription_deeper_research_hash_v307(array $research): string
{
    return transcription_app_context_hash_v301([
        'text'=>(string)($research['text']??''),'sources'=>(array)($research['sources']??[]),'meta'=>(array)($research['meta']??[]),
    ]);
}

function transcription_deeper_research_date_v307(array $research,array $module): string
{
    $stamp=strtotime((string)($research['meta']['generated_at']??$module['generated_at']??''))?:0;
    return $stamp>0?gmdate('Y-m-d',$stamp):gmdate('Y-m-d');
}

function transcription_deeper_verify_claims_v307(
    array $user,array $module,array $modules,array $research,string $currentHash
): array {
    $claims=transcription_deeper_claim_catalog_v307($modules,$currentHash);
    $researchText=trim((string)($research['text']??''));
    $publicSources=[];
    foreach ((array)($research['sources']??[]) as $source) {
        if (!is_array($source)) continue;
        $url=trim((string)($source['url']??''));
        if ($url==='' || !str_starts_with($url,'https://')) continue;
        $publicSources[]=['number'=>count($publicSources)+1,'title'=>transcription_app_clean_v300((string)($source['title']??$url),220),'url'=>$url];
        if (count($publicSources)>=10) break;
    }
    if (!$claims || $researchText==='' || !$publicSources) {
        return ['claims'=>[],'provider'=>'','model'=>'','research_hash'=>transcription_deeper_research_hash_v307($research),'claim_count'=>count($claims)];
    }

    $prompt="Verify individual transcript-grounded claims using ONLY the supplied PUBLIC RESEARCH and PUBLIC SOURCES. The transcript claim catalog establishes what was asserted; it is not evidence that the claim is externally true. Never use Agent Brain, Personal Knowledge, CRM, private context or outside unstated knowledge. For every claim return exactly one status: verified, mixed, unsupported, or unresolved. verified means the supplied public research clearly supports the claim; mixed means material parts are supported and contradicted/qualified; unsupported means the supplied public research materially contradicts the claim or supports a conflicting conclusion; unresolved means the supplied research does not establish an answer. Source numbers must come only from PUBLIC SOURCES. A verified, mixed or unsupported result requires at least one source number. Do not invent URLs, dates or source numbers. Return ONLY JSON as {\"claims\":[{\"claim_id\":\"exact item id\",\"verification\":\"verified|mixed|unsupported|unresolved\",\"source_numbers\":[1],\"confidence\":\"high|medium|low\",\"reason\":\"brief source-grounded explanation\"}]}. Include every supplied claim_id exactly once.\n\nTRANSCRIPT CLAIM CATALOG:\n".
        json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n\nPUBLIC RESEARCH:\n".mb_strimwidth($researchText,0,9000,'…')."\n\nPUBLIC SOURCES:\n".
        json_encode($publicSources,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $ai=artist_listening_v237_ai($prompt,$user,3600);
    $decoded=transcription_app_decode_json_v300((string)$ai['answer']);
    $returned=is_array($decoded['claims']??null)?$decoded['claims']:[];
    $claimIndex=transcription_deeper_catalog_index_v307($claims);
    $byId=[];
    foreach ($returned as $row) {
        if (!is_array($row)) continue;
        $id=trim((string)($row['claim_id']??''));
        if ($id==='' || !isset($claimIndex[$id]) || isset($byId[$id])) continue;
        $verification=strtolower(trim((string)($row['verification']??'unresolved')));
        if (!in_array($verification,['verified','mixed','unsupported','unresolved'],true)) $verification='unresolved';
        $numbers=[];
        foreach ((array)($row['source_numbers']??[]) as $number) {
            $number=(int)$number;
            if ($number>0 && $number<=count($publicSources) && !in_array($number,$numbers,true)) $numbers[]=$number;
        }
        if (in_array($verification,['verified','mixed','unsupported'],true) && !$numbers) $verification='unresolved';
        $confidence=strtolower(trim((string)($row['confidence']??'low')));
        if (!in_array($confidence,['high','medium','low'],true)) $confidence='low';
        $byId[$id]=[
            'verification'=>$verification,'source_numbers'=>$numbers,'confidence'=>$confidence,
            'reason'=>transcription_app_clean_v300((string)($row['reason']??''),700),
        ];
    }

    $date=transcription_deeper_research_date_v307($research,$module);
    $result=[];
    foreach ($claims as $claim) {
        $id=(string)$claim['item_id'];
        $verified=$byId[$id]??['verification'=>'unresolved','source_numbers'=>[],'confidence'=>'low','reason'=>'The supplied public research did not establish a source-grounded verification result.'];
        $refs=[];
        foreach ((array)($claim['evidence_refs']??[]) as $ref) if (is_array($ref)&&max(0,(int)($ref['page']??0))>0) $refs[]='Page '.(int)$ref['page'];
        $result[]=[
            'claim'=>(string)$claim['text'],'verification'=>(string)$verified['verification'],'research_date'=>$date,
            'source_numbers'=>(array)$verified['source_numbers'],'confidence'=>(string)$verified['confidence'],'reason'=>(string)$verified['reason'],
            'transcript_item_id'=>$id,'transcript_plugin'=>(string)$claim['plugin_title'],'evidence'=>implode(', ',array_values(array_unique($refs))),
        ];
    }
    return [
        'claims'=>$result,'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model'],
        'research_hash'=>transcription_deeper_research_hash_v307($research),'claim_count'=>count($claims),
    ];
}

function transcription_deeper_load_session_master_v307(PDO $pdo,array $user,int $sessionId): ?array
{
    if ($sessionId<1) return null;
    try {
        $session=artist_listening_v172_session($pdo,$user,$sessionId);
        $segments=artist_listening_v172_segments($pdo,$sessionId);
        $map=artist_listening_transcript_page_map($segments);
        $status=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
        $master=is_array($status['master']??null)?$status['master']:null;
        if (!$master || empty($master['fresh'])) return null;
        $master['session']=$session;$master['map']=$map;
        return $master;
    } catch (Throwable $e) {
        return null;
    }
}

function transcription_deeper_baseline_from_session_v307(PDO $pdo,array $user,int $sessionId,string $label): array
{
    $master=transcription_deeper_load_session_master_v307($pdo,$user,$sessionId);
    if (!$master) return ['label'=>$label,'mode'=>'selected_transcript','session_ids'=>[],'items'=>[],'hash'=>''];
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $items=transcription_deeper_item_catalog_v307($modules,(string)($master['map']['source_hash']??''),false);
    $hash=transcription_app_context_hash_v301(['session_id'=>$sessionId,'source_hash'=>(string)($master['map']['source_hash']??''),'items'=>$items]);
    return ['label'=>$label,'mode'=>'selected_transcript','session_ids'=>[$sessionId],'items'=>$items,'hash'=>$hash];
}

function transcription_deeper_resolve_comparison_v307(PDO $pdo,array $user,array $session,array $master,array $comparison): array
{
    $mode=strtolower(trim((string)($comparison['mode']??'project_history')));
    if (!in_array($mode,['project_history','previous','selected_transcript','accepted_intelligence'],true)) $mode='project_history';
    $sid=(int)$session['id'];$uid=(int)$user['id'];

    if ($mode==='selected_transcript') {
        $selected=max(0,(int)($comparison['session_id']??0));
        if ($selected<1 || $selected===$sid) throw new RuntimeException('Choose another owned transcript as the comparison target.');
        // artist_listening_v172_session inside the loader enforces owner access.
        $baseline=transcription_deeper_baseline_from_session_v307($pdo,$user,$selected,'Selected transcription #'.$selected);
        if (!$baseline['items']) throw new RuntimeException('The selected transcript has no current intelligence available for comparison.');
        $baseline['mode']=$mode;$baseline['session_id']=$selected;
        return $baseline;
    }

    if ($mode==='previous') {
        $stmt=$pdo->prepare("SELECT id,title FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND id<? AND status<>'discarded' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$uid,$sid]);$row=$stmt->fetch()?:null;
        if (!$row) throw new RuntimeException('There is no previous owned transcription available for comparison.');
        $previous=(int)$row['id'];
        $baseline=transcription_deeper_baseline_from_session_v307($pdo,$user,$previous,'Previous transcription · '.(transcription_app_clean_v300((string)$row['title'],120)?:('#'.$previous)));
        if (!$baseline['items']) throw new RuntimeException('The previous transcription has no current intelligence available for comparison.');
        $baseline['mode']=$mode;$baseline['session_id']=$previous;
        return $baseline;
    }

    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $currentHash=(string)($master['source_hash']??'');
    if ($mode==='accepted_intelligence') {
        $items=transcription_deeper_item_catalog_v307($modules,$currentHash,true);
        if (!$items) throw new RuntimeException('Accept at least one current intelligence finding before comparing against accepted intelligence.');
        return [
            'label'=>'Current accepted intelligence','mode'=>$mode,'session_id'=>$sid,'session_ids'=>[$sid],'items'=>$items,
            'hash'=>transcription_app_context_hash_v301(['mode'=>$mode,'source_hash'=>$currentHash,'items'=>$items]),
        ];
    }

    $trackId=max(0,(int)($session['project_track_id']??0));$conversationId=max(0,(int)($session['conversation_id']??0));
    if ($trackId<1 && $conversationId<1) throw new RuntimeException('This transcript is not linked to a project or conversation history. Choose Previous or a Selected transcript instead.');
    $where=[];$params=[$uid,$sid];
    if ($trackId>0) {$where[]='s.project_track_id=?';$params[]=$trackId;}
    if ($conversationId>0) {$where[]='s.conversation_id=?';$params[]=$conversationId;}
    $sql="SELECT s.id,s.title FROM artist_transcript_sessions_v172 s WHERE s.created_by_user_id=? AND s.id<>? AND s.status<>'discarded' AND (".implode(' OR ',$where).") ORDER BY s.updated_at DESC,s.id DESC LIMIT 4";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    $items=[];$sessionIds=[];$hashRows=[];
    foreach ($stmt->fetchAll()?:[] as $row) {
        $otherId=(int)$row['id'];$other=transcription_deeper_load_session_master_v307($pdo,$user,$otherId);
        if (!$other) continue;
        $otherModules=transcription_app_modules_v306(is_array($other['analysis']??null)?$other['analysis']:[],$other);
        $catalog=transcription_deeper_item_catalog_v307($otherModules,(string)($other['map']['source_hash']??''),false);
        foreach ($catalog as $item) {
            if (count($items)>=VP3_TRANSCRIPTION_DEEPER_MAX_COMPARE_ITEMS_V307) break 2;
            $item['baseline_session_id']=$otherId;$item['baseline_title']=transcription_app_clean_v300((string)$row['title'],120);$items[]=$item;
        }
        $sessionIds[]=$otherId;$hashRows[]=['session_id'=>$otherId,'source_hash'=>(string)($other['map']['source_hash']??''),'items'=>$catalog];
    }
    if (!$items) throw new RuntimeException('No current explicitly related project/conversation intelligence is available for comparison.');
    return [
        'label'=>'Project / conversation history','mode'=>'project_history','session_id'=>0,'session_ids'=>$sessionIds,'items'=>$items,
        'hash'=>transcription_app_context_hash_v301(['mode'=>'project_history','rows'=>$hashRows]),
    ];
}

function transcription_deeper_comparison_current_v307(array $master): array
{
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    return transcription_deeper_item_catalog_v307($modules,(string)($master['source_hash']??''),false);
}

function transcription_deeper_compare_v307(
    array $user,array $module,array $current,array $baseline
): array {
    if (!$current) throw new RuntimeException('The current transcript has no intelligence available for comparison.');
    if (empty($baseline['items'])) throw new RuntimeException('The selected comparison baseline is empty.');
    $prompt="Compare CURRENT TRANSCRIPT INTELLIGENCE against one EXPLICIT BASELINE. Use only the supplied catalogs. Do not infer a different baseline, identity, date, decision or resolution. Classify rows only as new, changed, contradicted, resolved, or still_open. Every new/changed/contradicted/still_open row must cite one or more exact current_item_ids. Every resolved row must cite one or more exact baseline_item_ids. changed means the same underlying subject materially changed; contradicted means current and baseline claims cannot both be true as stated; resolved means a baseline open issue/risk/question no longer remains in current intelligence with evidence of resolution, not merely that it is absent. Return ONLY JSON as {\"new\":[{\"change\":\"...\",\"current_item_ids\":[\"ti_...\"],\"confidence\":\"high|medium|low\"}],\"changed\":[{\"change\":\"...\",\"before\":\"...\",\"after\":\"...\",\"baseline_item_ids\":[\"...\"],\"current_item_ids\":[\"...\"],\"confidence\":\"...\"}],\"contradicted\":[{\"change\":\"...\",\"prior\":\"...\",\"current\":\"...\",\"baseline_item_ids\":[\"...\"],\"current_item_ids\":[\"...\"],\"confidence\":\"...\"}],\"resolved\":[{\"change\":\"...\",\"baseline_item_ids\":[\"...\"],\"current_item_ids\":[\"...\"],\"confidence\":\"...\"}],\"still_open\":[{\"change\":\"...\",\"baseline_item_ids\":[\"...\"],\"current_item_ids\":[\"...\"],\"confidence\":\"...\"}]}.\n\nCOMPARISON TARGET: ".(string)$baseline['label']."\n\nEXPLICIT BASELINE:\n".json_encode($baseline['items'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n\nCURRENT TRANSCRIPT INTELLIGENCE:\n".json_encode($current,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $ai=artist_listening_v237_ai($prompt,$user,4200);
    $decoded=transcription_app_decode_json_v300((string)$ai['answer']);
    $currentIndex=transcription_deeper_catalog_index_v307($current);$baseIndex=transcription_deeper_catalog_index_v307((array)$baseline['items']);
    $result=[];
    foreach (['new','changed','contradicted','resolved','still_open'] as $section) {
        $rows=[];
        foreach ((array)($decoded[$section]??[]) as $row) {
            if (!is_array($row)) continue;
            $text=transcription_app_clean_v300((string)($row['change']??''),1200);if ($text==='') continue;
            $currentIds=[];$baseIds=[];
            foreach ((array)($row['current_item_ids']??[]) as $id) {$id=trim((string)$id);if(isset($currentIndex[$id])&&!in_array($id,$currentIds,true))$currentIds[]=$id;}
            foreach ((array)($row['baseline_item_ids']??[]) as $id) {$id=trim((string)$id);if(isset($baseIndex[$id])&&!in_array($id,$baseIds,true))$baseIds[]=$id;}
            if ($section==='new' && !$currentIds) continue;
            if (in_array($section,['changed','contradicted','still_open'],true) && (!$currentIds || !$baseIds)) continue;
            if ($section==='resolved' && !$baseIds) continue;
            $confidence=strtolower(trim((string)($row['confidence']??'medium')));if(!in_array($confidence,['high','medium','low'],true))$confidence='medium';
            $pages=[];
            foreach ($currentIds as $id) foreach ((array)($currentIndex[$id]['evidence_refs']??[]) as $ref) if(is_array($ref)&&max(0,(int)($ref['page']??0))>0)$pages[(int)$ref['page']]='Page '.(int)$ref['page'];
            ksort($pages);
            $clean=['change'=>$text,'compared_to'=>(string)$baseline['label'],'comparison_target'=>(string)$baseline['label'],'evidence'=>implode(', ',array_values($pages)),'confidence'=>$confidence,'current_item_ids'=>$currentIds,'baseline_item_ids'=>$baseIds];
            foreach (['before','after','prior','current'] as $field) if(isset($row[$field]))$clean[$field]=transcription_app_clean_v300((string)$row[$field],900);
            $rows[]=$clean;if(count($rows)>=16)break;
        }
        $result[$section]=$rows;
    }
    $result['__deep_meta']=[
        'comparison_mode'=>(string)$baseline['mode'],'comparison_session_id'=>max(0,(int)($baseline['session_id']??0)),
        'comparison_session_ids'=>array_values(array_map('intval',(array)($baseline['session_ids']??[]))),
        'comparison_label'=>(string)$baseline['label'],'comparison_hash'=>(string)$baseline['hash'],'generated_at'=>gmdate('c'),
    ];
    return ['result'=>$result,'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model']];
}

function transcription_deeper_advanced_finalize_v307(
    PDO $pdo,array $user,array $session,array $master,array $workflow,array $comparison,array $requestedApps
): array {
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $currentHash=(string)($master['source_hash']??'');$errors=[];$stats=[];

    if (in_array('research_brief',$requestedApps,true) && !empty($workflow['web_research']) && isset($modules['research_brief'])) {
        try {
            $verified=transcription_deeper_verify_claims_v307($user,$modules['research_brief'],$modules,(array)($master['research']??[]),$currentHash);
            if ($verified['claims']) {
                $modules['research_brief']['result']['claims']=$verified['claims'];
                $modules['research_brief']['result']['__deep_meta']=array_replace(
                    (array)($modules['research_brief']['result']['__deep_meta']??[]),
                    ['research_hash'=>(string)$verified['research_hash'],'claim_count'=>(int)$verified['claim_count'],'claim_verified_at'=>gmdate('c')]
                );
                if ((string)$verified['provider']!=='') $modules['research_brief']['provider']=(string)$verified['provider'];
                if ((string)$verified['model']!=='') $modules['research_brief']['model']=(string)$verified['model'];
                $modules['research_brief']['generated_at']=gmdate('c');
                $stats['claims']=(int)$verified['claim_count'];
            }
        } catch (Throwable $e) {
            $errors['research_brief']=ai_v100_safe_exception($e,'Claim verification failed. Existing Research Brief was preserved.');
        }
    }

    if (in_array('changes',$requestedApps,true) && isset($modules['changes'])) {
        try {
            $baseline=transcription_deeper_resolve_comparison_v307($pdo,$user,$session,$master,$comparison);
            $current=transcription_deeper_comparison_current_v307($master);
            $compared=transcription_deeper_compare_v307($user,$modules['changes'],$current,$baseline);
            $modules['changes']['result']=$compared['result'];$modules['changes']['provider']=$compared['provider'];$modules['changes']['model']=$compared['model'];$modules['changes']['generated_at']=gmdate('c');
            $stats['comparison_items']=count((array)$baseline['items']);$stats['comparison_mode']=(string)$baseline['mode'];
        } catch (Throwable $e) {
            $errors['changes']=ai_v100_safe_exception($e,'Advanced comparison failed. Existing Comparison result was preserved.');
        }
    }

    $prior=transcription_deeper_review_index_v307($master);
    $modules=transcription_deeper_normalize_modules_v307($modules,$prior);
    $master['analysis']=transcription_deeper_persist_modules_v307($pdo,(int)$session['id'],$modules);
    return ['master'=>$master,'errors'=>$errors,'stats'=>$stats];
}

function transcription_deeper_advanced_status_v307(PDO $pdo,array $user,array $session,?array $master,array $map): array
{
    $view=transcription_app_status_v307($pdo,$user,$session,$master,$map);
    if (!$master) return $view;
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);

    if (isset($modules['research_brief'])) {
        $meta=(array)($modules['research_brief']['result']['__deep_meta']??[]);
        $stored=(string)($meta['research_hash']??'');
        if ($stored!=='' && !hash_equals($stored,transcription_deeper_research_hash_v307((array)($master['research']??[])))) {
            $view['app_status']['research_brief']['fresh']=false;$view['app_status']['research_brief']['fresh_reason']='research_context_changed';
        }
    }

    if (isset($modules['crm'])) {
        $meta=(array)($modules['crm']['result']['__deep_meta']??[]);$stored=(string)($meta['crm_history_hash']??'');
        if ($stored!=='') {
            $segments=artist_listening_v172_segments($pdo,(int)$session['id']);$crm=transcription_app_crm_context_v301($pdo,$user,$session,$segments);
            $current=transcription_app_context_hash_v301(transcription_deeper_crm_history_v307($pdo,$crm));
            if (!hash_equals($stored,$current)) {$view['app_status']['crm']['fresh']=false;$view['app_status']['crm']['fresh_reason']='crm_history_changed';}
        }
    }

    if (isset($modules['changes'])) {
        $meta=(array)($modules['changes']['result']['__deep_meta']??[]);$stored=(string)($meta['comparison_hash']??'');
        if ($stored!=='') {
            $comparison=['mode'=>(string)($meta['comparison_mode']??'project_history'),'session_id'=>max(0,(int)($meta['comparison_session_id']??0))];
            try {
                $baseline=transcription_deeper_resolve_comparison_v307($pdo,$user,$session,$master,$comparison);
                if (!hash_equals($stored,(string)$baseline['hash'])) {$view['app_status']['changes']['fresh']=false;$view['app_status']['changes']['fresh_reason']='comparison_context_changed';}
            } catch (Throwable $e) {
                $view['app_status']['changes']['fresh']=false;$view['app_status']['changes']['fresh_reason']='comparison_context_unavailable';
            }
        }
    }
    return $view;
}
