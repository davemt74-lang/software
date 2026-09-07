<?php
declare(strict_types=1);

/**
 * VP3 transcription workflow configuration v304.
 *
 * Owns presets and run-profile semantics. The browser may persist a user's
 * choices, but it never owns the preset catalog or validation rules.
 */
const VP3_TRANSCRIPTION_WORKFLOW_V304 = 'transcription-workflow-v304-20260906';

function transcription_workflow_presets_v304(): array
{
    return [
        'meeting'=>[
            'id'=>'meeting','title'=>'Meeting','description'=>'Decisions, action items, questions, follow-ups, risks and milestones.',
            'apps'=>['basic','actions','decisions','qa','followup','risks','timeline'],
            'workflow'=>['live_analysis'=>true,'web_research'=>false,'depth'=>'standard','focus'=>'','context_mode'=>'authorized'],
        ],
        'product'=>[
            'id'=>'product','title'=>'Product','description'=>'Requirements, decisions, actions, blockers, opportunities and change tracking.',
            'apps'=>['basic','requirements','decisions','actions','risks','opportunities','changes'],
            'workflow'=>['live_analysis'=>false,'web_research'=>false,'depth'=>'deep','focus'=>'Product requirements, decisions, dependencies and validation.','context_mode'=>'authorized'],
        ],
        'sales'=>[
            'id'=>'sales','title'=>'Sales','description'=>'CRM signals, questions, objections/stance, opportunities and follow-up.',
            'apps'=>['basic','crm','qa','stance','opportunities','actions','followup'],
            'workflow'=>['live_analysis'=>true,'web_research'=>false,'depth'=>'standard','focus'=>'Relationship signals, needs, objections, commitments and next follow-up.','context_mode'=>'authorized'],
        ],
        'studio'=>[
            'id'=>'studio','title'=>'Studio','description'=>'Production notes, key moments, decisions, actions and retained project knowledge.',
            'apps'=>['basic','studio','moments','decisions','actions','followup','knowledge'],
            'workflow'=>['live_analysis'=>true,'web_research'=>false,'depth'=>'standard','focus'=>'Song, arrangement, performance, recording, mix and production decisions.','context_mode'=>'authorized'],
        ],
        'research'=>[
            'id'=>'research','title'=>'Research','description'=>'Topics, entities, questions, comparisons and public-source verification.',
            'apps'=>['basic','topics','entities','qa','changes','research_brief'],
            'workflow'=>['live_analysis'=>false,'web_research'=>true,'depth'=>'deep','focus'=>'Claims, entities, unresolved questions and facts requiring verification.','context_mode'=>'authorized'],
        ],
        'full'=>[
            'id'=>'full','title'=>'Full','description'=>'Runs the complete transcription intelligence registry. Highest analysis cost.',
            'apps'=>array_keys(transcription_app_registry_v301()),
            'workflow'=>['live_analysis'=>false,'web_research'=>false,'depth'=>'deep','focus'=>'','context_mode'=>'authorized'],
        ],
    ];
}

function transcription_workflow_defaults_v304(): array
{
    return [
        'preset'=>'custom',
        'live_analysis'=>false,
        'web_research'=>false,
        'depth'=>'standard',
        'focus'=>'',
        'context_mode'=>'authorized',
    ];
}

function transcription_workflow_normalize_v304(mixed $value): array
{
    $raw=is_array($value)?$value:[];
    $out=transcription_workflow_defaults_v304();
    $preset=strtolower(trim((string)($raw['preset'] ?? 'custom')));
    if ($preset !== 'custom' && !isset(transcription_workflow_presets_v304()[$preset])) $preset='custom';
    $depth=strtolower(trim((string)($raw['depth'] ?? $out['depth'])));
    if (!in_array($depth,['concise','standard','deep'],true)) $depth='standard';
    $context=strtolower(trim((string)($raw['context_mode'] ?? $out['context_mode'])));
    if (!in_array($context,['transcript','authorized'],true)) $context='authorized';
    $out['preset']=$preset;
    $out['live_analysis']=filter_var($raw['live_analysis'] ?? $out['live_analysis'],FILTER_VALIDATE_BOOL);
    $out['web_research']=filter_var($raw['web_research'] ?? $out['web_research'],FILTER_VALIDATE_BOOL);
    $out['depth']=$depth;
    $out['focus']=transcription_app_clean_v300((string)($raw['focus'] ?? ''),240);
    $out['context_mode']=$context;
    return $out;
}

function transcription_workflow_apply_preset_v304(string $presetId, array $current=[]): array
{
    $presetId=strtolower(trim($presetId));
    $preset=transcription_workflow_presets_v304()[$presetId] ?? null;
    if (!is_array($preset)) throw new RuntimeException('Unknown transcription workflow preset.');
    $workflow=transcription_workflow_normalize_v304((array)($preset['workflow'] ?? []));
    $workflow['preset']=$presetId;
    return [
        'apps'=>transcription_app_ids_v301($preset['apps'] ?? ['basic']),
        'workflow'=>$workflow,
    ];
}

function transcription_workflow_public_v304(): array
{
    $presets=[];
    foreach (transcription_workflow_presets_v304() as $preset) {
        $presets[]=[
            'id'=>$preset['id'],'title'=>$preset['title'],'description'=>$preset['description'],
            'apps'=>array_values($preset['apps']),'workflow'=>transcription_workflow_normalize_v304($preset['workflow'] ?? []),
        ];
    }
    return [
        'version'=>304,
        'batch_size'=>VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301,
        'presets'=>$presets,
        'defaults'=>transcription_workflow_defaults_v304(),
        'depth_options'=>[
            ['id'=>'concise','title'=>'Concise','description'=>'Top findings and shortest useful report.'],
            ['id'=>'standard','title'=>'Standard','description'=>'Balanced detail and analysis cost.'],
            ['id'=>'deep','title'=>'Deep','description'=>'More complete extraction and explanation.'],
        ],
        'context_options'=>[
            ['id'=>'transcript','title'=>'Transcript only','description'=>'Use the transcript as general context; specialized plugins may still use their explicitly required related/CRM context.'],
            ['id'=>'authorized','title'=>'Authorized context','description'=>'Allow relevant Agent Brain and Personal Knowledge context in addition to explicit plugin context.'],
        ],
    ];
}

function transcription_workflow_max_tokens_v304(array $workflow): int
{
    return match((string)($workflow['depth'] ?? 'standard')) {
        'concise'=>2400,
        'deep'=>5000,
        default=>3400,
    };
}

function transcription_workflow_profile_prompt_v304(array $workflow): string
{
    $depth=(string)($workflow['depth'] ?? 'standard');
    $depthInstruction=match($depth){
        'concise'=>'Be concise: keep only the highest-value supported findings and avoid redundant rows.',
        'deep'=>'Be thorough within the selected plugin contracts: preserve distinct supported findings, dependencies, uncertainty and evidence rather than collapsing useful detail.',
        default=>'Use balanced detail: include material supported findings without repetitive or low-value rows.',
    };
    $focus=trim((string)($workflow['focus'] ?? ''));
    $context=(string)($workflow['context_mode'] ?? 'authorized');
    $lines=[
        'RUN PROFILE:',
        'Depth: '.$depth.'. '.$depthInstruction,
        'General context mode: '.($context === 'transcript' ? 'transcript only' : 'authorized private context allowed').'.',
    ];
    if ($focus !== '') $lines[]='User analysis focus: '.$focus.'. Treat this as prioritization guidance, never as evidence or a fact.';
    return implode("\n",$lines);
}

function transcription_workflow_run_plan_v304(array $requestedApps, array $workflow, string $mode='manual'): array
{
    $registry=transcription_app_registry_v301();
    $requested=transcription_app_ids_v301($requestedApps);
    $effective=$requested;
    if ($mode === 'live') $effective=array_values(array_filter($requested,static fn(string $id):bool=>!empty($registry[$id]['live'])));
    $ai=array_values(array_filter($effective,static fn(string $id):bool=>($registry[$id]['execution']??'')==='ai'));
    $det=array_values(array_filter($effective,static fn(string $id):bool=>($registry[$id]['execution']??'')==='deterministic'));
    $batches=(int)ceil(count($ai)/max(1,VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301));
    $manualOnly=array_values(array_filter($requested,static fn(string $id):bool=>empty($registry[$id]['live'])));
    $cost=match(true){
        $batches>=4=>'high',
        $batches>=2=>'medium',
        $batches===1=>'low',
        default=>'none',
    };
    return [
        'requested_apps'=>$requested,'effective_apps'=>$effective,'ai_apps'=>$ai,'deterministic_apps'=>$det,
        'ai_batches'=>$batches,'batch_size'=>VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301,'manual_only_apps'=>$manualOnly,
        'web_research'=>!empty($workflow['web_research']),'live_analysis'=>!empty($workflow['live_analysis']),
        'depth'=>(string)$workflow['depth'],'context_mode'=>(string)$workflow['context_mode'],'focus'=>(string)$workflow['focus'],
        'estimated_ai_cost'=>$cost,
    ];
}

function transcription_workflow_contexts_v304(
    PDO $pdo,array $user,array $session,array $segments,array $apps,array $baseContext,array $workflow
): array {
    if (($workflow['context_mode'] ?? 'authorized') === 'transcript') $baseContext=['brain'=>[],'knowledge'=>[]];
    return transcription_app_contexts_v301($pdo,$user,$session,$segments,$apps,$baseContext);
}

function transcription_app_analyze_v304(
    PDO $pdo,array $user,int $sessionId,string $mode,array $requestedApps,mixed $workflowInput=[]
): array {
    $workflow=transcription_workflow_normalize_v304($workflowInput);
    $registry=transcription_app_registry_v301();
    $requested=transcription_app_ids_v301($requestedApps);
    $plan=transcription_workflow_run_plan_v304($requested,$workflow,$mode);
    if ($mode === 'live' && empty($workflow['live_analysis'])) {
        $session=artist_listening_v172_session($pdo,$user,$sessionId);
        $segments=artist_listening_v172_segments($pdo,$sessionId);
        $map=artist_listening_transcript_page_map($segments);
        $status=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
        return ['skipped'=>true,'reason'=>'live_analysis_off','requested_apps'=>$requested,'run_plan'=>$plan,'workflow'=>$workflow]
            + transcription_app_status_v301($pdo,$user,$session,$status['master']??null,$map)
            + ['permissions'=>transcription_app_permissions_v300($user),'plugin_errors'=>[]];
    }

    $apps=$requested;
    if ($mode === 'live') {
        $apps=array_values(array_filter($requested,static fn(string $id):bool=>!empty($registry[$id]['live'])));
        if (!$apps) {
            $session=artist_listening_v172_session($pdo,$user,$sessionId);
            $segments=artist_listening_v172_segments($pdo,$sessionId);
            $map=artist_listening_transcript_page_map($segments);
            $status=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
            return ['skipped'=>true,'reason'=>'selected_plugins_manual_only','requested_apps'=>$requested,'run_plan'=>$plan,'workflow'=>$workflow]
                + transcription_app_status_v301($pdo,$user,$session,$status['master']??null,$map)
                + ['permissions'=>transcription_app_permissions_v300($user),'plugin_errors'=>[]];
        }
    }

    $session=artist_listening_v172_session($pdo,$user,$sessionId);
    $segments=artist_listening_v172_segments($pdo,$sessionId);
    $map=artist_listening_transcript_page_map($segments);
    $tags=transcription_app_tags_v300($session);
    $status=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
    $master=is_array($status['master']??null)?$status['master']:null;
    $analysis=is_array($master['analysis']??null)?$master['analysis']:[];
    $modules=transcription_app_modules_v301($analysis,$master);
    $words=(int)$map['total_words'];

    $priorForFreshness=transcription_app_prior_context_v301($pdo,$user,$session);
    $crmForFreshness=transcription_app_crm_context_v301($pdo,$user,$session,$segments);
    $freshHashes=['prior_hash'=>transcription_app_context_hash_v301($priorForFreshness),'crm_hash'=>transcription_app_context_hash_v301($crmForFreshness)];

    if ($mode === 'live') {
        if (!$master && $words<120) return ['skipped'=>true,'reason'=>'minimum_context','requested_apps'=>$requested,'run_plan'=>$plan,'workflow'=>$workflow]
            + transcription_app_status_v301($pdo,$user,$session,null,$map)+['permissions'=>transcription_app_permissions_v300($user),'plugin_errors'=>[]];
        $allFresh=true;
        foreach ($apps as $id) {
            $module=$modules[$id]??null;
            if (!is_array($module) || !transcription_app_module_fresh_v301($id,$module,(string)$map['source_hash'],$freshHashes)[0]) {$allFresh=false;break;}
        }
        $reportedCounts=array_map(static fn(string $id):int=>(int)($modules[$id]['source_word_count']??0),$apps);
        $delta=max(0,$words-($reportedCounts?max($reportedCounts):0));
        if ($allFresh && $delta<250) return ['skipped'=>true,'reason'=>'block_not_due','requested_apps'=>$requested,'run_plan'=>$plan,'workflow'=>$workflow]
            + transcription_app_status_v301($pdo,$user,$session,$master,$map)+['permissions'=>transcription_app_permissions_v300($user),'plugin_errors'=>[]];
    }

    $pending=[];$pluginErrors=[];$pageErrors=[];
    if (in_array('stats',$apps,true)) {
        $pending['stats']=[
            'app_id'=>'stats','source_hash'=>(string)$map['source_hash'],'source_word_count'=>$words,'generated_at'=>gmdate('c'),
            'provider'=>'vp3','model'=>'deterministic-stats-v300','contract_version'=>1,'context_hash'=>'',
            'result'=>transcription_app_stats_v300($segments,$session,$map),
        ];
    }

    $aiApps=array_values(array_filter($apps,static fn(string $id):bool=>($registry[$id]['execution']??'')==='ai'));
    $researchQueries=[];
    $provider=(string)($master['provider']??'vp3');
    $model=(string)($master['model']??'deterministic');
    $baseContext=['brain'=>[],'knowledge'=>[]];
    $contexts=$baseContext+['prior'=>$priorForFreshness,'crm'=>$crmForFreshness,'prior_hash'=>$freshHashes['prior_hash'],'crm_hash'=>$freshHashes['crm_hash']];
    $pages=[];

    if ($aiApps) {
        foreach ($map['pages'] as $page) {
            $number=(int)$page['page_number'];
            $saved=$status['pages'][(string)$number]??null;
            if (is_array($saved)&&!empty($saved['fresh'])) continue;
            if ((int)$page['word_count']<1) continue;
            try {
                if ((int)$page['word_count']>=120) artist_listening_v237_analyze_page($pdo,$user,$sessionId,$number,true);
                elseif ($mode==='manual') {
                    $ai=artist_listening_v237_ai(artist_listening_transcript_page_prompt($page,artist_listening_v237_participants($session)),$user,1400);
                    $json=json_encode(artist_listening_v237_parse_analysis((string)$ai['answer']),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                    if (is_string($json)) {
                        $stmt=$pdo->prepare('INSERT INTO artist_transcript_page_analysis_v237 (session_id,page_number,source_hash,source_word_count,start_segment_index,end_segment_index,analysis_json,provider,model,generated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),start_segment_index=VALUES(start_segment_index),end_segment_index=VALUES(end_segment_index),analysis_json=VALUES(analysis_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()');
                        $stmt->execute([$sessionId,$number,(string)$page['source_hash'],(int)$page['word_count'],(int)$page['start_segment_index'],(int)$page['end_segment_index'],$json,(string)$ai['provider'],(string)$ai['model']]);
                    }
                }
            } catch (Throwable $e) {
                $pageErrors[(string)$number]=ai_v100_safe_exception($e,'Transcript page analysis failed.');
                if ($mode==='live') break;
            }
            if ($mode==='live') break;
        }

        $status=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
        foreach ($map['pages'] as $page) {
            $saved=$status['pages'][(string)$page['page_number']]??null;
            if (is_array($saved)&&!empty($saved['fresh'])) $pages[]=['page'=>(int)$page['page_number'],'analysis'=>$saved['analysis']];
        }
        if (!$pages) throw new RuntimeException('There is not enough saved transcript analysis to run the selected plugins yet.');

        $terms=$tags;
        foreach ($pages as $page) foreach (['key_points','decisions','action_items','open_questions','research_queries'] as $key) foreach ((array)($page['analysis'][$key]??[]) as $item) $terms[]=(string)$item;
        $query=implode(' · ',array_slice(array_values(array_filter(array_map(static fn($x):string=>transcription_app_clean_v300((string)$x,160),$terms))),0,16));
        $baseContext=($workflow['context_mode']??'authorized')==='authorized' ? transcription_app_context_v300($user,$query) : ['brain'=>[],'knowledge'=>[]];
        $contexts=transcription_workflow_contexts_v304($pdo,$user,$session,$segments,$apps,$baseContext,$workflow);
        $profile=transcription_workflow_profile_prompt_v304($workflow);
        $maxTokens=transcription_workflow_max_tokens_v304($workflow);

        foreach (array_chunk($aiApps,VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301) as $batch) {
            try {
                $prompt=transcription_app_prompt_v301($batch,$tags,$pages,$contexts)."\n\n".$profile;
                $ai=artist_listening_v237_ai($prompt,$user,$maxTokens);
                $decoded=transcription_app_decode_json_v300((string)$ai['answer']);
                $returned=is_array($decoded['apps']??null)?$decoded['apps']:[];
                foreach ($batch as $id) {
                    if (!isset($returned[$id]) || !is_array($returned[$id])) {
                        $pluginErrors[$id]='The AI provider omitted the '.$registry[$id]['title'].' result. Run this plugin again.';
                        continue;
                    }
                    $contextHash=$id==='changes'?(string)$contexts['prior_hash']:($id==='crm'?(string)$contexts['crm_hash']:'');
                    $pending[$id]=[
                        'app_id'=>$id,'source_hash'=>(string)$map['source_hash'],'source_word_count'=>$words,'generated_at'=>gmdate('c'),
                        'provider'=>(string)$ai['provider'],'model'=>(string)$ai['model'],'contract_version'=>2,'context_hash'=>$contextHash,
                        'result'=>transcription_app_sanitize_value_v300($returned[$id]),
                    ];
                }
                foreach ((array)($decoded['research_queries']??[]) as $queryItem) {
                    $clean=transcription_app_clean_v300((string)$queryItem,220);
                    if ($clean!=='') $researchQueries[mb_strtolower($clean)]=$clean;
                    if (count($researchQueries)>=6) break;
                }
                $provider=(string)$ai['provider'];$model=(string)$ai['model'];
            } catch (Throwable $e) {
                $message=ai_v100_safe_exception($e,'Transcription plugin batch failed.');
                foreach ($batch as $id) if (!isset($pending[$id])) $pluginErrors[$id]=$message;
            }
        }
    }

    $researchQueries=array_values($researchQueries);
    $oldResearch=is_array($master['research']??null)?$master['research']:[];
    $gate=transcription_app_research_gate_v300($oldResearch,$researchQueries,$tags,$words);
    $research=$oldResearch;
    $researchOn=!empty($workflow['web_research']);
    if ($researchOn && $gate['due']) {
        $research=artist_listening_v237_research($gate['queries'],$user);
        $research['meta']=$gate+['generated_at'=>gmdate('c')];
    } elseif ($research && !isset($research['meta'])) {
        $research['meta']=$gate+['generated_at'=>(string)($master['generated_at']??'')];
    }

    if (in_array('research_brief',$apps,true) && isset($pending['research_brief'])) {
        if (!$researchOn) {
            $pending['research_brief']['result']=[
                'overview'=>[['summary'=>'Turn Web Research ON and run Analyze to build a public-source research brief.','status'=>'research_off']],
                'findings'=>[],'sources'=>[],'unresolved'=>[],
            ];
        } else {
            try {
                $brief=transcription_app_research_brief_v301($user,$pages,$research);
                if (isset($brief['result'])) {
                    $pending['research_brief']['result']=$brief['result'];
                    $pending['research_brief']['provider']=(string)($brief['provider']??$pending['research_brief']['provider']);
                    $pending['research_brief']['model']=(string)($brief['model']??$pending['research_brief']['model']);
                    $pending['research_brief']['generated_at']=gmdate('c');
                    $pending['research_brief']['context_hash']=transcription_app_context_hash_v301($research['meta']??$research);
                    $provider=(string)$pending['research_brief']['provider'];$model=(string)$pending['research_brief']['model'];
                }
            } catch (Throwable $e) {
                unset($pending['research_brief']);
                $pluginErrors['research_brief']=ai_v100_safe_exception($e,'Research Brief failed.');
            }
        }
    }

    if (!$pending && $pluginErrors) throw new RuntimeException(reset($pluginErrors) ?: 'The selected transcription plugins could not be analyzed.');

    foreach ($pending as $id=>$module) $modules[$id]=$module;
    $projection=transcription_app_compat_projection_v300($modules);
    $projection['registry_version']=304;
    $projection['workflow_version']=304;
    $analysisJson=json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $researchJson=json_encode($research,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($analysisJson)||!is_string($researchJson)) throw new RuntimeException('Could not encode transcription workflow results.');
    $freshPages=0;
    foreach (($status['pages']??[]) as $row) if (!empty($row['fresh'])) $freshPages++;
    $stmt=$pdo->prepare('INSERT INTO artist_transcript_master_analysis_v237 (session_id,source_hash,source_word_count,page_count,analyzed_page_count,analysis_json,research_json,provider,model,generated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),source_word_count=VALUES(source_word_count),page_count=VALUES(page_count),analyzed_page_count=VALUES(analyzed_page_count),analysis_json=VALUES(analysis_json),research_json=VALUES(research_json),provider=VALUES(provider),model=VALUES(model),generated_at=NOW()');
    $stmt->execute([$sessionId,(string)$map['source_hash'],$words,(int)$map['page_count'],$freshPages,$analysisJson,$researchJson,$provider,$model]);

    $latest=artist_listening_v237_analysis_status($pdo,$sessionId,$map);
    return [
        'skipped'=>false,'requested_apps'=>$requested,'executed_apps'=>array_keys($pending),'research_gate'=>$gate,
        'workflow'=>$workflow,'run_plan'=>$plan,'plugin_errors'=>$pluginErrors,'page_errors'=>$pageErrors,
        'context'=>[
            'agent_brain_items'=>count($contexts['brain']??[]),'knowledge_items'=>count($contexts['knowledge']??[]),
            'prior_transcripts'=>count($contexts['prior']??[]),'crm_contacts'=>count($contexts['crm']['contacts']??[]),
        ],
    ]+transcription_app_status_v301($pdo,$user,$session,$latest['master']??null,$map)+['permissions'=>transcription_app_permissions_v300($user)];
}
