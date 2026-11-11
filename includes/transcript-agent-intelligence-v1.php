<?php
declare(strict_types=1);
// Reuse the same engine as the Transcription Intelligence UI; no API-to-API requests.
foreach(['transcription-app-registry','transcription-apps-wave2','transcription-apps-wave2-save','transcription-intelligence-items','transcription-intelligence-relations','transcription-intelligence-actions','transcription-workflow-config','transcription-intelligence-outputs','transcription-deeper-intelligence','transcription-deeper-items','transcription-deeper-chat','transcription-deeper-report','transcription-deeper-advanced','video-meetings-v1800','video-meetings-homeserver-v1801'] as $module)require_once __DIR__.'/'.$module.'.php';

/** Identify suggestions from the registered report sections that carry next steps. */
function vp3_transcript_suggestion_v1(string $plugin, string $section, string $primary, array $item): ?array
{
    $followUp = is_string($item['follow_up'] ?? null) ? trim($item['follow_up']) : '';
    $nextCheck = is_string($item['next_check'] ?? null) ? trim($item['next_check']) : '';
    $field = match ($plugin.'.'.$section) {
        'actions.items', 'responses.items', 'decisions.commitments', 'basic.open_questions',
        'followup.items', 'followup.waiting_on', 'requirements.dependencies', 'changes.still_open',
        'crm.promises', 'crm.next_best_actions', 'crm.recommended_updates',
        'summary_output.open_questions', 'summary_output.next_steps',
        'action_plan.actions', 'action_plan.follow_ups' => $primary,
        'qa.unanswered' => $followUp !== '' ? 'follow_up' : $primary,
        'opportunities.items' => 'validation_step',
        'risks.items' => 'mitigation',
        'research_brief.unresolved' => $nextCheck !== '' ? 'next_check' : $primary,
        'research_brief.claims' => in_array($item['verification'] ?? '', ['unresolved', 'unsupported', 'mixed', 'uncertain'], true) ? $primary : '',
        default => '',
    };
    $text = $item[$field] ?? '';
    if ($field === '' || !is_string($text)) return null;
    $text = transcription_app_clean_v300($text, 280);
    if ($text === '' || in_array(mb_strtolower($text), ['unknown', 'none', 'n/a', 'not applicable'], true)) return null;
    $research = $plugin === 'research_brief' || ($plugin === 'opportunities' && ($item['type'] ?? '') === 'research');
    return ['kind' => $research ? 'research' : 'followup', 'text' => $text];
}

function vp3_transcript_suggestion_completion_v1(array $item): string
{
    $status = is_string($item['status'] ?? null) ? strtolower(trim($item['status'])) : '';
    if (in_array($status, ['completed', 'complete', 'done', 'closed', 'resolved', 'cancelled', 'canceled'], true)) return 'completed';
    // The canonical module reader has already validated restored action receipts.
    foreach ((array)($item['actions'] ?? []) as $key => $receipt) {
        if (!is_string($key) || !in_array(explode(':', $key, 2)[0], ['agent_task', 'crm_task', 'reminder'], true)) continue;
        if (is_array($receipt) && is_int($receipt['record_id'] ?? null) && $receipt['record_id'] > 0
            && is_string($receipt['performed_at'] ?? null) && strtotime($receipt['performed_at']) !== false) return 'tracked';
    }
    return 'open';
}

/** Project saved plugin results for attention without executing any plugin or action. */
function vp3_transcript_plugin_attention_v1(array $master, array $modules, array $appStatus, array $map, int $sessionId, int $analyzedPages, bool $includeReports = false): array
{
    $limit = 24;
    $inventory = $queues = [];
    $suggestionCounts = ['followup' => 0, 'research' => 0];
    $normalized = transcription_deeper_normalize_modules_v307($modules);
    foreach (transcription_app_registry_v307() as $plugin => $definition) {
        if (!isset($modules[$plugin])) continue;
        $module = $modules[$plugin];
        $fresh = !empty($master['fresh']) && !empty($appStatus[$plugin]['fresh']);
        $reviewCounts = ['accepted' => 0, 'unreviewed' => 0, 'rejected' => 0];
        $completionCounts = ['open' => 0, 'tracked' => 0, 'completed' => 0];
        $queue = $seen = [];
        $report = [];
        $suggestionCount = 0;
        if ($includeReports && $fresh) {
            $reportFields = match ($plugin) {
                'basic' => ['summary', 'analysis'],
                'stats' => ['total_words', 'duration_label', 'speaker_count', 'question_count'],
                default => [],
            };
            foreach ($reportFields as $field) {
                $value = $module['result'][$field] ?? null;
                if ((!is_string($value) && !is_int($value)) || trim((string)$value) === '') continue;
                $report[] = ucfirst(str_replace('_', ' ', $field)).': '.transcription_app_clean_v300((string)$value, 120);
            }
        }
        foreach ($definition['sections'] as $section) {
            $key = $section['key'];
            $sectionReported = false;
            foreach ((array)($normalized[$plugin]['result'][$key] ?? []) as $index => $item) {
                $itemId = $item['item_id'] ?? null;
                if (!is_string($itemId) || !preg_match('/\A[A-Za-z0-9_.:-]{1,190}\z/D', $itemId) || isset($seen[$itemId])) continue;
                $seen[$itemId] = true;
                $review = $item['review_state'];
                $reviewCounts[$review]++;
                $suggestion = vp3_transcript_suggestion_v1($plugin, $key, $section['primary'], $item);
                $completion = vp3_transcript_suggestion_completion_v1($item);
                if ($includeReports && $fresh && !$sectionReported && $review !== 'rejected' && $completion === 'open') {
                    $content = $item[$section['primary']] ?? '';
                    if (is_string($content) && trim($content) !== '') {
                        $report[] = $section['title'].': '.transcription_app_clean_v300($content, 120);
                        $sectionReported = true;
                    }
                }
                if ($suggestion === null) continue;
                $completionCounts[$completion]++;
                if (!$fresh || $review === 'rejected' || $completion !== 'open') continue;
                $suggestionCount++;
                $suggestionCounts[$suggestion['kind']]++;
                if (count($queue) >= $limit) continue;

                $references = [];
                $raw = $master['analysis']['modules'][$plugin]['result'][$key][$index] ?? [];
                $rawRefs = is_array($raw) && ($raw['item_id'] ?? null) === $itemId ? (array)($raw['evidence_refs'] ?? []) : [];
                foreach (array_merge($item['evidence_refs'], $rawRefs) as $reference) {
                    $page = $reference['page'] ?? null;
                    if (!is_int($page) || $page < 1 || $page > $map['page_count']) continue;
                    $references[$page] = ['page' => $page, 'label' => 'Page '.$page];
                }
                ksort($references);
                $references = array_values(array_slice($references, 0, 4, true));
                $origin = transcription_intelligence_origin_v309(['id' => $sessionId], $plugin, $key, [
                    'item_id' => $itemId,
                    'evidence_refs' => $references,
                ]);
                $queue[] = $suggestion + [
                    'plugin' => $plugin,
                    'section' => $key,
                    'item_id' => $itemId,
                    'review_state' => $review,
                    'completion_state' => $completion,
                    'evidence_refs' => $references,
                    'source_hash' => (string)$module['source_hash'],
                    'source_url' => $origin['source_url'],
                ];
            }
        }
        $inventory[] = [
            'plugin' => $plugin,
            'label' => $definition['title'],
            'state' => $fresh ? 'fresh' : 'stale',
            'fresh_reason' => !empty($master['fresh']) ? ($appStatus[$plugin]['fresh_reason'] ?? 'not_checked') : 'transcript_changed',
            'generated_at' => transcription_app_clean_v300((string)$module['generated_at'], 40),
            'review_counts' => $reviewCounts,
            'completion_counts' => $completionCounts,
            'suggestion_count' => $suggestionCount,
            'coverage' => [
                'source_words' => (int)$module['source_word_count'],
                'transcript_words' => (int)$map['total_words'],
                'current_page_analyses' => $analyzedPages,
                'transcript_pages' => (int)$map['page_count'],
            ],
        ] + ($includeReports ? ['report_excerpt' => mb_strimwidth(implode("\n", $report), 0, 480, '…')] : []);
        if ($queue) $queues[] = $queue;
    }

    // Keep a large single plugin from crowding every other module out of the read.
    $suggestions = [];
    $totalSuggestions = array_sum($suggestionCounts);
    for ($index = 0; $index < $limit && count($suggestions) < $totalSuggestions; $index++) {
        foreach ($queues as $queue) {
            if (isset($queue[$index])) $suggestions[] = $queue[$index];
            if (count($suggestions) === $limit) break 2;
        }
    }
    return ['modules' => $inventory, 'suggestions' => $suggestions, 'suggestion_counts' => $suggestionCounts,
        'truncated' => $totalSuggestions > count($suggestions)];
}

function vp3_transcript_intelligence_v1(PDO $pdo,array $user,array $principal,int $id,string $view='analysis',string $plugin='',int $offset=0): array
{
    $s=vp3_transcript_session_v1($pdo,$user,$principal,$id);
    if(!artist_listening_v237_schema_ready())throw new RuntimeException('Transcript analysis storage needs the database upgrade.');
    $map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$id));$saved=artist_listening_v237_analysis_status($pdo,$id,$map);$m=$saved['master'];
    $p=['session_id'=>$id,'title'=>$s['title'],'source_hash'=>$map['source_hash'],'page_count'=>$map['page_count'],'generated'=>is_array($m),'fresh'=>!empty($m['fresh']),'text'=>'','sources'=>[],
        'plugin_attention'=>['modules'=>[],'suggestions'=>[],'suggestion_counts'=>['followup'=>0,'research'=>0],'truncated'=>false]];
    if(!$m)return $p;
    $p['generated_at']=$m['generated_at'];$p['analyzed_pages']=$saved['analyzed_pages'];
    $mods=transcription_app_modules_v306($m['analysis'],$m);
    $status=$p['fresh']?transcription_deeper_advanced_status_v307($pdo,$user,$s,$m,$map):[];
    $p['plugin_attention']=vp3_transcript_plugin_attention_v1($m,$mods,$status['app_status']??[],$map,$id,$saved['analyzed_pages'],in_array($view,['analysis','discussion'],true));
    if(!$p['fresh']||$view==='discussion')return $p;
    $items=[];$counts=['accepted'=>0,'unreviewed'=>0,'rejected'=>0];
    foreach($mods as $key=>$mod){
        if($plugin!==''&&$key!==$plugin)continue;
        if($view==='research'&&$key!=='research_brief')continue;
        $fresh=!empty($status['app_status'][$key]['fresh']);
        $items[$key]=['fresh'=>$fresh,'fresh_reason'=>$status['app_status'][$key]['fresh_reason']??'not_checked','result'=>$fresh?($mod['result']??[]):[]];
        foreach((array)($mod['result']??[]) as $rows)if(is_array($rows))foreach($rows as $row)if(is_array($row)&&isset($row['item_id'])){$review=$row['review_state']??'unreviewed';if(isset($counts[$review]))$counts[$review]++;}
    }
    $p['review_counts']=$counts;
    if($view==='initiative'){
        $p['fresh']=!empty($items['actions']['fresh']);
        $actions=[];
        foreach(($items['actions']['result']['items']??[]) as $row){
            if(!is_array($row)||($row['review_state']??'')!=='accepted'||!is_string($row['action']??null))continue;
            $action=trim(mb_substr($row['action'],0,180));
            if($action!=='')$actions[]=$action;
            if(count($actions)>=3)break;
        }
        $p['accepted_actions']=$actions;
        return $p;
    }
    if($view==='research'){
        $p['fresh']=!empty($status['app_status']['research_brief']['fresh']);
        if(!$p['fresh']){$p['fresh_reason']=$status['app_status']['research_brief']['fresh_reason']??'not_generated';return $p;}
        $items['public_research']=['text'=>$m['research']['text']??'','error'=>$m['research']['error']??''];
        foreach(array_slice((array)($m['research']['sources']??[]),0,20) as $source){$url=(string)($source['url']??'');if(filter_var($url,FILTER_VALIDATE_URL)&&str_starts_with($url,'https://'))$p['sources'][]=['title'=>mb_substr((string)($source['title']??$url),0,220),'url'=>$url];}
    }
    $lines=[];
    $walk=function($v,$label='',$depth=0)use(&$walk,&$lines){
        if(is_array($v)){
            foreach($v as $key=>$value){
                if(in_array((string)$key,['__deep_meta','source_fingerprint','relations','edited_text'],true))continue;
                // Keep the Actions plugin and Action Plan sections, not their item receipt maps.
                if($key==='actions'&&$depth>=3)continue;
                $walk($value,is_int($key)?$label:$label.($label!==''?' · ':'').str_replace('_',' ',(string)$key),$depth+1);
            }
        }elseif(is_scalar($v)&&trim((string)$v)!=='')$lines[]=$label.': '.(is_bool($v)?($v?'yes':'no'):(string)$v);
    };
    $walk($items);$text=implode("\n",$lines);$p['text']=mb_substr($text,$offset,16000);$p['next_offset']=$offset+mb_strlen($p['text'])<mb_strlen($text)?$offset+mb_strlen($p['text']):null;
    return $p;
}
function vp3_transcript_analyze_v1(PDO $pdo,array $user,array $principal,int $id,array $apps,string $focus,bool $research,?callable $runner=null): array
{
    $s=vp3_transcript_session_v1($pdo,$user,$principal,$id);
    $registry=transcription_app_registry_v307();
    if(!$apps||count($apps)>8||array_diff($apps,array_keys($registry)))throw new RuntimeException('Choose up to eight existing transcript analysis modules.');
    if($research&&!in_array('research_brief',$apps,true))$apps[]='research_brief';
    if(function_exists('video_meeting_transcription_assert_cloud_ai_v1801'))video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$s);
    $workflow=['depth'=>'standard','live_analysis'=>false,'web_research'=>$research,'context_mode'=>'transcript','focus'=>mb_substr($focus,0,240)];
    // One explicit engine invocation; uncertain or failed analysis is never automatically replayed.
    $r=$runner?$runner($pdo,$user,$id,'manual',$apps,$workflow):transcription_app_analyze_v307($pdo,$user,$id,'manual',$apps,$workflow);
    return ['session_id'=>$id,'title'=>$s['title'],'executed_apps'=>$r['executed_apps']??[],'errors'=>$r['plugin_errors']??[],'skipped'=>!empty($r['skipped']),'reason'=>$r['reason']??''];
}
function vp3_transcript_intelligence_chat_v1(PDO $pdo,array $user,array $principal,array $plan,int $id): array
{
    $op=$plan['operation'];
    if(in_array($op,['run_analysis','run_research'],true)){
        $r=vp3_transcript_analyze_v1($pdo,$user,$principal,$id,$plan['apps']??['basic'],(string)($plan['query']??''),$op==='run_research');
        $ok=!$r['errors']&&!$r['skipped']&&count($r['executed_apps'])>0;
        $answer=$ok?'Transcript analysis completed using: '.implode(', ',$r['executed_apps']).'. Findings remain interpretations until reviewed; external verification is separate.':($r['skipped']?'No analysis was run: '.($r['reason']?:'the engine skipped this request').'.':'The analysis did not fully complete. Some pages may have updated; inspect the saved result before requesting another run.');
        return ['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[['source'=>'transcript:'.$id,'title'=>$r['title'],'url'=>url('/artist-listening.php?session='.$id)]],'tool_receipt'=>vp3_tool_receipt_v1('transcripts.analyze',$op,$ok?'completed':'failed',$ok?'':'execution_failed',$id,count($r['executed_apps']))];
    }
    $p=vp3_transcript_intelligence_v1($pdo,$user,$principal,$id,$op==='read_research'?'research':'analysis',$op==='read_analysis'?(string)($plan['plugin']??''):'',(int)($plan['offset']??0));
    if(!$p['generated'])$answer='No saved AI analysis exists for this transcript. Ask me to analyze it.';
    elseif(!$p['fresh'])$answer='The saved '.($op==='read_research'?'research':'analysis').' is stale or unavailable'.(!empty($p['fresh_reason'])?' ('.$p['fresh_reason'].')':' because the transcript changed').'. Ask me to refresh it; I have not run a new analysis or web search.';
    else{$answer='Transcript #'.$id.' · '.$p['title'].' · AI interpretation, not transcript quotations or independently verified user facts. Generated '.$p['generated_at'].'. Review counts: '.json_encode($p['review_counts'])."\n".$p['text'];if($p['next_offset']!==null)$answer.="\nContinues at character offset ".$p['next_offset'].'.';}
    $r=vp3_transcript_result_v1($answer,$op,'read_complete',$id,$p['generated']?1:0,$p);
    foreach($p['sources'] as $source)$r['sources'][]=['source'=>'transcript-research:'.$id,...$source];return $r;
}
