<?php
declare(strict_types=1);

/**
 * VP3 transcription deeper intelligence v307.
 *
 * This layer deepens existing transcription plugins instead of introducing a
 * parallel intelligence surface. It reuses durable v302 items, v305 relations,
 * v306 outputs, the canonical CRM, Personal Knowledge, Agent Brain and Main
 * Chat ecosystem paths.
 */
const VP3_TRANSCRIPTION_DEEPER_V307 = 'transcription-deeper-intelligence-v307-20260907';
const VP3_TRANSCRIPTION_DEEPER_MAX_KNOWLEDGE_CANDIDATES_V307 = 8;
const VP3_TRANSCRIPTION_DEEPER_MAX_CRM_HISTORY_V307 = 20;
const VP3_TRANSCRIPTION_DEEPER_MAX_COMPARISON_TARGETS_V307 = 20;

function transcription_app_registry_v307(): array
{
    $registry=transcription_app_registry_v306();

    if (isset($registry['knowledge']['sections'][0])) {
        $registry['knowledge']['description']='Classifies durable knowledge against existing Agent Brain and Personal Knowledge before promotion.';
        $registry['knowledge']['sections'][0]['meta']=[
            'type','key','knowledge_state','match_source','match_title','match_similarity','temporary','evidence','confidence','conflict'
        ];
    }

    if (isset($registry['crm'])) {
        $registry['crm']['description']='Compares explicitly matched CRM contacts against relationship/activity history without changing CRM records.';
        $registry['crm']['sections']=[
            ['key'=>'status','title'=>'CRM match','primary'=>'message','meta'=>['state']],
            ['key'=>'matched_contacts','title'=>'Matched contacts','primary'=>'name','meta'=>['company','email','lead_stage','priority','next_follow_up']],
            ['key'=>'signals','title'=>'Relationship signals','primary'=>'signal','meta'=>['contact','type','evidence','confidence']],
            ['key'=>'relationship_changes','title'=>'Relationship changes','primary'=>'change','meta'=>['contact','change_type','prior_activity','evidence','confidence']],
            ['key'=>'objections','title'=>'Objections / concerns','primary'=>'objection','meta'=>['contact','state','evidence','confidence']],
            ['key'=>'buying_signals','title'=>'Buying signals','primary'=>'signal','meta'=>['contact','strength','evidence','confidence']],
            ['key'=>'promises','title'=>'Promises / commitments','primary'=>'promise','meta'=>['contact','owner','timing','evidence','confidence']],
            ['key'=>'next_best_actions','title'=>'Next-best actions','primary'=>'action','meta'=>['contact','priority','reason','evidence','confidence']],
            ['key'=>'recommended_updates','title'=>'Recommended CRM updates','primary'=>'update','meta'=>['contact','field','reason','evidence','confidence']],
        ];
    }

    if (isset($registry['opportunities']['sections'][0])) {
        $registry['opportunities']['description']='Ranks grounded opportunities by value, effort, urgency, confidence and validation path.';
        $registry['opportunities']['sections'][0]['meta']=[
            'type','why_it_matters','validation_step','potential_value','value_score','effort','effort_score','urgency','urgency_score','confidence','confidence_score','opportunity_score','rank','evidence'
        ];
    }

    if (isset($registry['research_brief'])) {
        $registry['research_brief']['description']='Verifies individual transcript claims against dated public-source research.';
        $sections=$registry['research_brief']['sections'];
        array_splice($sections,1,0,[
            ['key'=>'claims','title'=>'Claim verification','primary'=>'claim','meta'=>['verification','research_date','source_numbers','confidence']],
        ]);
        $registry['research_brief']['sections']=$sections;
    }

    if (isset($registry['changes'])) {
        $registry['changes']['description']='Compares against an explicit previous, selected, project-history or accepted-intelligence baseline.';
        foreach ($registry['changes']['sections'] as &$section) {
            $meta=(array)($section['meta']??[]);
            array_unshift($meta,'comparison_target');
            $section['meta']=array_values(array_unique($meta));
        }
        unset($section);
    }

    return $registry;
}

function transcription_app_registry_public_v307(): array
{
    $out=[];
    foreach (transcription_app_registry_v307() as $app) {
        $out[]=[
            'id'=>$app['id'],'label'=>$app['label'],'title'=>$app['title'],'description'=>$app['description'],
            'execution'=>$app['execution'],'live'=>(bool)$app['live'],'view'=>$app['view'],'sections'=>$app['sections'],
        ];
    }
    return $out;
}

function transcription_deeper_projection_v307(array $modules): array
{
    $projection=transcription_output_projection_v306($modules);
    $projection['registry_version']=307;
    $projection['deeper_intelligence_version']=307;
    return $projection;
}

function transcription_deeper_persist_modules_v307(PDO $pdo,int $sessionId,array $modules): array
{
    $projection=transcription_deeper_projection_v307($modules);
    $json=json_encode($projection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Could not encode deeper transcription intelligence.');
    $stmt=$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?');
    $stmt->execute([$json,$sessionId]);
    if ($stmt->rowCount()<1) {
        $check=$pdo->prepare('SELECT session_id FROM artist_transcript_master_analysis_v237 WHERE session_id=? LIMIT 1');
        $check->execute([$sessionId]);
        if (!$check->fetchColumn()) throw new RuntimeException('The deeper transcription intelligence could not be updated.');
    }
    return $projection;
}

function transcription_deeper_normalize_text_v307(string $text): string
{
    $text=mb_strtolower(transcription_app_clean_v300($text,2200));
    $text=preg_replace('/[^\pL\pN]+/u',' ',(string)$text)??$text;
    return trim(preg_replace('/\s+/u',' ',(string)$text)??$text);
}

function transcription_deeper_terms_v307(string $text): array
{
    $parts=preg_split('/\s+/u',transcription_deeper_normalize_text_v307($text))?:[];
    $stop=['the','and','that','this','with','from','have','will','your','their','about','into','for','are','was','were','but','not','you','our','they','then','than'];
    $out=[];
    foreach ($parts as $part) {
        if (mb_strlen($part)<3 || in_array($part,$stop,true)) continue;
        $out[$part]=$part;
        if (count($out)>=60) break;
    }
    return array_values($out);
}

function transcription_deeper_similarity_v307(string $a,string $b): float
{
    $na=transcription_deeper_normalize_text_v307($a);
    $nb=transcription_deeper_normalize_text_v307($b);
    if ($na==='' || $nb==='') return 0.0;
    if (hash_equals($na,$nb)) return 1.0;
    $ta=transcription_deeper_terms_v307($na);
    $tb=transcription_deeper_terms_v307($nb);
    if (!$ta || !$tb) return 0.0;
    $intersection=count(array_intersect($ta,$tb));
    $union=count(array_unique(array_merge($ta,$tb)));
    return $union>0?$intersection/$union:0.0;
}

function transcription_deeper_knowledge_candidates_v307(PDO $pdo,array $user,string $text): array
{
    $uid=max(0,(int)($user['id']??0));
    if ($uid<1 || trim($text)==='') return [];
    $candidates=[];

    if (function_exists('search_knowledge') && function_exists('personal_knowledge_available') && personal_knowledge_available($user)) {
        try {
            foreach (search_knowledge($text,$user,VP3_TRANSCRIPTION_DEEPER_MAX_KNOWLEDGE_CANDIDATES_V307) as $row) {
                if ((string)($row['knowledge_scope']??'')!=='personal') continue;
                $candidateText=trim((string)($row['chunk_text']??''));
                if ($candidateText==='') continue;
                $candidates[]=[
                    'source'=>'personal_knowledge','id'=>(int)($row['id']??0),
                    'title'=>transcription_app_clean_v300((string)($row['title']??'Personal Knowledge'),190),
                    'text'=>transcription_app_clean_v300($candidateText,1800),
                ];
            }
        } catch (Throwable $e) {}
    }

    if (function_exists('agent_brain_schema_ready') && agent_brain_schema_ready() && table_exists('agent_memory_items')) {
        try {
            $stmt=$pdo->prepare(
                "SELECT id,memory_type,subject,memory_text,confidence,last_seen_at
                 FROM agent_memory_items
                 WHERE user_id=? AND is_active=1 AND memory_type NOT IN ('conversation_state','conversation_summary')
                 ORDER BY last_seen_at DESC,id DESC LIMIT 80"
            );
            $stmt->execute([$uid]);
            $brain=[];
            foreach ($stmt->fetchAll()?:[] as $row) {
                $candidateText=trim((string)($row['memory_text']??''));
                if ($candidateText==='') continue;
                $similarity=transcription_deeper_similarity_v307($text,$candidateText);
                if ($similarity<0.18) continue;
                $brain[]=[
                    'source'=>'agent_brain','id'=>(int)$row['id'],
                    'title'=>transcription_app_clean_v300((string)($row['subject']??$row['memory_type']??'Agent Brain'),190),
                    'text'=>transcription_app_clean_v300($candidateText,1800),'similarity'=>$similarity,
                ];
            }
            usort($brain,static fn(array $a,array $b):int=>($b['similarity']<=>$a['similarity']));
            foreach (array_slice($brain,0,VP3_TRANSCRIPTION_DEEPER_MAX_KNOWLEDGE_CANDIDATES_V307) as $row) $candidates[]=$row;
        } catch (Throwable $e) {}
    }

    $dedup=[];
    foreach ($candidates as $candidate) {
        $key=(string)$candidate['source'].':'.(int)$candidate['id'];
        $candidate['similarity']=$candidate['similarity']??transcription_deeper_similarity_v307($text,(string)$candidate['text']);
        if (!isset($dedup[$key]) || (float)$candidate['similarity']>(float)$dedup[$key]['similarity']) $dedup[$key]=$candidate;
    }
    $candidates=array_values($dedup);
    usort($candidates,static fn(array $a,array $b):int=>($b['similarity']<=>$a['similarity']));
    return array_slice($candidates,0,VP3_TRANSCRIPTION_DEEPER_MAX_KNOWLEDGE_CANDIDATES_V307);
}

function transcription_deeper_knowledge_state_v307(array $item,array $candidates): array
{
    $value=transcription_app_clean_v300((string)($item['value']??''),1800);
    $type=strtolower(trim((string)($item['type']??'')));
    $temporary=$type==='project_state' && (bool)preg_match('/\b(?:today|currently|for now|temporary|temporarily|this week|this month|at the moment|right now)\b/iu',$value);
    $best=$candidates[0]??null;
    $similarity=is_array($best)?max(0.0,min(1.0,(float)($best['similarity']??0))):0.0;
    $state='new';
    $reason='No sufficiently similar existing personal knowledge or Agent Brain memory was found.';

    if (!empty($item['conflict'])) {
        $state='conflicting';
        $reason='The source intelligence already identifies a conflict with authorized prior context.';
    } elseif ($temporary) {
        $state='temporary';
        $reason='The wording describes time-bounded project state and should not silently become permanent knowledge.';
    } elseif ($best && $similarity>=0.92) {
        $state='duplicate';
        $reason='A nearly identical existing knowledge item is already available.';
    } elseif ($best && $similarity>=0.70) {
        $newLen=max(1,mb_strlen(transcription_deeper_normalize_text_v307($value)));
        $oldLen=max(1,mb_strlen(transcription_deeper_normalize_text_v307((string)$best['text'])));
        if ($newLen>=(int)round($oldLen*1.18)) {
            $state='more_specific';
            $reason='The new intelligence substantially overlaps an existing item but adds material detail.';
        } else {
            $state='updates_existing';
            $reason='The new intelligence overlaps an existing item but is not identical and may update it.';
        }
    }

    return [
        'knowledge_state'=>$state,'temporary'=>$temporary,
        'match_source'=>(string)($best['source']??''),'match_id'=>(int)($best['id']??0),
        'match_title'=>(string)($best['title']??''),'match_similarity'=>(int)round($similarity*100),
        'match_reason'=>$reason,
    ];
}

function transcription_deeper_enrich_knowledge_v307(PDO $pdo,array $user,array &$modules): int
{
    $rows=&$modules['knowledge']['result']['items'];
    if (!is_array($rows)) return 0;
    $count=0;
    foreach ($rows as &$item) {
        if (!is_array($item)) continue;
        $value=trim((string)($item['value']??''));
        if ($value==='') continue;
        $candidates=transcription_deeper_knowledge_candidates_v307($pdo,$user,$value);
        foreach (transcription_deeper_knowledge_state_v307($item,$candidates) as $key=>$value) $item[$key]=$value;
        $count++;
    }
    unset($item);
    return $count;
}

function transcription_deeper_crm_history_v307(PDO $pdo,array $crm): array
{
    $out=[];
    foreach ((array)($crm['contacts']??[]) as $contact) {
        if (!is_array($contact)) continue;
        $leadId=max(0,(int)($contact['lead_id']??0));
        $contactId=max(0,(int)($contact['contact_id']??0));
        if ($leadId<1 || $contactId<1) continue;
        try {
            $activities=$pdo->prepare(
                'SELECT id,activity_type,summary,details_json,created_at FROM crm_activities WHERE lead_id=? ORDER BY created_at DESC,id DESC LIMIT '.VP3_TRANSCRIPTION_DEEPER_MAX_CRM_HISTORY_V307
            );
            $activities->execute([$leadId]);
            $history=[];
            foreach ($activities->fetchAll()?:[] as $row) {
                $history[]=[
                    'id'=>(int)$row['id'],'type'=>(string)$row['activity_type'],
                    'summary'=>transcription_app_clean_v300((string)$row['summary'],500),'created_at'=>(string)$row['created_at'],
                ];
            }
            $tasks=$pdo->prepare("SELECT id,task_type,title,status,due_at,created_at FROM crm_tasks WHERE lead_id=? AND status<>'completed' ORDER BY (due_at IS NULL),due_at ASC,id DESC LIMIT 10");
            $tasks->execute([$leadId]);
            $openTasks=[];
            foreach ($tasks->fetchAll()?:[] as $row) {
                $openTasks[]=[
                    'id'=>(int)$row['id'],'type'=>(string)$row['task_type'],'title'=>transcription_app_clean_v300((string)$row['title'],190),
                    'status'=>(string)$row['status'],'due_at'=>(string)($row['due_at']??''),
                ];
            }
            $out[$contactId]=['lead_id'=>$leadId,'history'=>$history,'open_tasks'=>$openTasks];
        } catch (Throwable $e) {
            $out[$contactId]=['lead_id'=>$leadId,'history'=>[],'open_tasks'=>[]];
        }
    }
    return $out;
}

function transcription_deeper_contact_for_signal_v307(array $contacts,string $label): ?array
{
    $needle=transcription_deeper_normalize_text_v307($label);
    if ($needle==='') return count($contacts)===1?$contacts[0]:null;
    foreach ($contacts as $contact) {
        if (!is_array($contact)) continue;
        foreach (['name','email','company'] as $field) {
            $value=transcription_deeper_normalize_text_v307((string)($contact[$field]??''));
            if ($value!=='' && ($value===$needle || str_contains($needle,$value) || str_contains($value,$needle))) return $contact;
        }
    }
    return count($contacts)===1?$contacts[0]:null;
}

function transcription_deeper_enrich_crm_v307(PDO $pdo,array $user,array $session,array $segments,array &$modules): array
{
    if (!isset($modules['crm']) || !function_exists('crm_v180_can_manage') || !crm_v180_can_manage($user)) return ['contacts'=>0,'history_hash'=>''];
    $crm=transcription_app_crm_context_v301($pdo,$user,$session,$segments);
    $contacts=(array)($crm['contacts']??[]);
    $history=transcription_deeper_crm_history_v307($pdo,$crm);
    $result=&$modules['crm']['result'];
    if (!is_array($result)) $result=[];
    $signals=is_array($result['signals']??null)?$result['signals']:[];
    $accepted=[];
    foreach ($signals as $signal) {
        if (!is_array($signal) || (string)($signal['review_state']??'')!=='accepted') continue;
        $accepted[]=$signal;
    }

    $changes=[];$objections=[];$buying=[];$promises=[];$actions=[];
    foreach ($accepted as $signal) {
        $text=transcription_app_clean_v300((string)($signal['signal']??''),900);
        if ($text==='') continue;
        $contact=transcription_deeper_contact_for_signal_v307($contacts,(string)($signal['contact']??''));
        $contactName=(string)($contact['name']??$signal['contact']??'Matched contact');
        $contactId=max(0,(int)($contact['contact_id']??0));
        $prior='';$best=0.0;
        foreach ((array)($history[$contactId]['history']??[]) as $row) {
            $similarity=transcription_deeper_similarity_v307($text,(string)($row['summary']??''));
            if ($similarity>$best) {$best=$similarity;$prior=(string)$row['summary'];}
        }
        $changes[]=[
            'change'=>$text,'contact'=>$contactName,'change_type'=>$best>=0.62?'recurring_signal':'new_signal',
            'prior_activity'=>$best>=0.62?$prior:'No similar prior CRM activity found.',
            'evidence'=>(string)($signal['evidence']??''),'confidence'=>(string)($signal['confidence']??'medium'),
        ];

        $lower=mb_strtolower($text.' '.(string)($signal['type']??''));
        if (preg_match('/\b(?:objection|concern|hesitat|too expensive|price concern|not ready|can\x27t|cannot|won\x27t|risk|problem|blocker)\b/iu',$lower)) {
            $objections[]=['objection'=>$text,'contact'=>$contactName,'state'=>$best>=0.62?'recurring':'new','evidence'=>(string)($signal['evidence']??''),'confidence'=>(string)($signal['confidence']??'medium')];
        }
        if (preg_match('/\b(?:buy|purchase|pricing|budget|demo|trial|ready|interested|sign up|start|contract|proposal|checkout)\b/iu',$lower)) {
            $buying[]=['signal'=>$text,'contact'=>$contactName,'strength'=>preg_match('/\b(?:ready|purchase|buy|contract|sign up)\b/iu',$lower)?'high':'medium','evidence'=>(string)($signal['evidence']??''),'confidence'=>(string)($signal['confidence']??'medium')];
        }
        if (preg_match('/\b(?:will|promise|follow up|send|call|schedule|circle back|next step|commit)\b/iu',$lower)) {
            $promises[]=['promise'=>$text,'contact'=>$contactName,'owner'=>'unknown','timing'=>'unknown','evidence'=>(string)($signal['evidence']??''),'confidence'=>(string)($signal['confidence']??'medium')];
        }
    }

    foreach ((array)($result['recommended_updates']??[]) as $update) {
        if (!is_array($update) || (string)($update['review_state']??'')!=='accepted') continue;
        $actions[]=[
            'action'=>transcription_app_clean_v300((string)($update['update']??''),900),'contact'=>(string)($update['contact']??''),
            'priority'=>'medium','reason'=>(string)($update['reason']??'Accepted CRM intelligence recommends this update.'),
            'evidence'=>(string)($update['evidence']??''),'confidence'=>(string)($update['confidence']??'medium'),
        ];
    }
    foreach ($contacts as $contact) {
        if (!is_array($contact)) continue;
        $contactId=max(0,(int)($contact['contact_id']??0));
        foreach ((array)($history[$contactId]['open_tasks']??[]) as $task) {
            $actions[]=[
                'action'=>(string)($task['title']??'CRM follow-up'),'contact'=>(string)($contact['name']??''),
                'priority'=>trim((string)($task['due_at']??''))!=='' && strtotime((string)$task['due_at'])<=time()+86400?'high':'medium',
                'reason'=>'Existing open CRM task'.(trim((string)($task['due_at']??''))!==''?' due '.(string)$task['due_at']:''),
                'evidence'=>'CRM task #'.(int)($task['id']??0),'confidence'=>'high',
            ];
        }
    }

    $result['relationship_changes']=array_slice($changes,0,20);
    $result['objections']=array_slice($objections,0,12);
    $result['buying_signals']=array_slice($buying,0,12);
    $result['promises']=array_slice($promises,0,12);
    $result['next_best_actions']=array_slice($actions,0,16);
    $historyHash=transcription_app_context_hash_v301($history);
    $result['__deep_meta']=['crm_history_hash'=>$historyHash,'generated_at'=>gmdate('c')];
    return ['contacts'=>count($contacts),'history_hash'=>$historyHash];
}

function transcription_deeper_level_score_v307(string $value,string $kind): int
{
    $value=strtolower(trim($value));
    return match($kind) {
        'value'=>match($value){'high'=>85,'medium'=>60,'low'=>35,default=>50},
        'effort'=>match($value){'low'=>25,'medium'=>55,'high'=>80,default=>55},
        'urgency'=>match($value){'high'=>85,'medium'=>60,'low'=>35,default=>45},
        'confidence'=>match($value){'high'=>90,'medium'=>65,'low'=>40,default=>55},
        default=>50,
    };
}

function transcription_deeper_effort_v307(string $text): string
{
    $text=mb_strtolower($text);
    if (preg_match('/\b(?:migrate|integrat|redesign|rebuild|implement|develop|launch|deploy|architecture)\b/u',$text)) return 'high';
    if (preg_match('/\b(?:email|call|ask|check|review|test|confirm|measure|survey|interview|verify)\b/u',$text)) return 'low';
    return 'medium';
}

function transcription_deeper_urgency_v307(string $text): string
{
    $text=mb_strtolower($text);
    if (preg_match('/\b(?:urgent|today|now|immediately|deadline|overdue|asap|this week)\b/u',$text)) return 'high';
    if (preg_match('/\b(?:soon|next|upcoming|this month|follow up)\b/u',$text)) return 'medium';
    return 'low';
}

function transcription_deeper_enrich_opportunities_v307(array &$modules): int
{
    $rows=&$modules['opportunities']['result']['items'];
    if (!is_array($rows)) return 0;
    foreach ($rows as &$item) {
        if (!is_array($item)) continue;
        $text=(string)($item['opportunity']??'').' '.(string)($item['why_it_matters']??'').' '.(string)($item['validation_step']??'');
        $value=(string)($item['potential_value']??'unknown');
        $effort=transcription_deeper_effort_v307($text);
        $urgency=transcription_deeper_urgency_v307($text);
        $confidence=(string)($item['confidence']??'medium');
        $valueScore=transcription_deeper_level_score_v307($value,'value');
        $effortScore=transcription_deeper_level_score_v307($effort,'effort');
        $urgencyScore=transcription_deeper_level_score_v307($urgency,'urgency');
        $confidenceScore=transcription_deeper_level_score_v307($confidence,'confidence');
        $score=(int)round(($valueScore*.40)+($urgencyScore*.25)+($confidenceScore*.25)+((100-$effortScore)*.10));
        $item['value_score']=$valueScore;$item['effort']=$effort;$item['effort_score']=$effortScore;
        $item['urgency']=$urgency;$item['urgency_score']=$urgencyScore;$item['confidence_score']=$confidenceScore;
        $item['opportunity_score']=max(0,min(100,$score));
    }
    unset($item);
    usort($rows,static fn(array $a,array $b):int=>(int)($b['opportunity_score']??0)<=>(int)($a['opportunity_score']??0));
    foreach ($rows as $index=>&$item) if (is_array($item)) $item['rank']=$index+1;
    unset($item);
    return count($rows);
}

function transcription_deeper_enrich_claims_v307(array &$modules): int
{
    $module=&$modules['research_brief'];
    if (!is_array($module)) return 0;
    $result=&$module['result'];
    if (!is_array($result)) return 0;
    $date='';
    $stamp=strtotime((string)($module['generated_at']??''))?:0;
    if ($stamp>0) $date=gmdate('Y-m-d',$stamp);
    $sourceCount=count((array)($result['sources']??[]));
    $claims=[];
    foreach ((array)($result['findings']??[]) as $finding) {
        if (!is_array($finding)) continue;
        $claim=transcription_app_clean_v300((string)($finding['finding']??$finding['claim']??''),1200);
        if ($claim==='') continue;
        $verification=strtolower(trim((string)($finding['verification']??'unresolved')));
        $verification=match($verification){'verified'=>'verified','mixed'=>'mixed','unsupported'=>'unsupported',default=>'unresolved'};
        $numbers=[];
        foreach ((array)($finding['source_numbers']??[]) as $number) {
            $number=(int)$number;
            if ($number>0 && $number<=$sourceCount && !in_array($number,$numbers,true)) $numbers[]=$number;
        }
        if (!$numbers && $verification==='verified') $verification='unresolved';
        $claims[]=[
            'claim'=>$claim,'verification'=>$verification,'research_date'=>$date,
            'source_numbers'=>$numbers,'confidence'=>(string)($finding['confidence']??($verification==='unresolved'?'low':'medium')),
        ];
    }
    $result['claims']=array_slice($claims,0,20);
    return count($claims);
}

function transcription_deeper_comparison_targets_v307(PDO $pdo,array $user,array $session): array
{
    $uid=max(0,(int)($user['id']??0));$sid=max(0,(int)($session['id']??0));
    $out=[
        ['id'=>'project_history','label'=>'Project / conversation history','mode'=>'project_history','session_id'=>0],
        ['id'=>'previous','label'=>'Previous transcription','mode'=>'previous','session_id'=>0],
        ['id'=>'accepted_intelligence','label'=>'Current accepted intelligence','mode'=>'accepted_intelligence','session_id'=>0],
    ];
    if ($uid<1 || $sid<1) return $out;
    try {
        $stmt=$pdo->prepare(
            "SELECT id,title,updated_at FROM artist_transcript_sessions_v172
             WHERE created_by_user_id=? AND id<>? AND status<>'discarded'
             ORDER BY updated_at DESC,id DESC LIMIT ".VP3_TRANSCRIPTION_DEEPER_MAX_COMPARISON_TARGETS_V307
        );
        $stmt->execute([$uid,$sid]);
        foreach ($stmt->fetchAll()?:[] as $row) {
            $out[]=[
                'id'=>'session:'.(int)$row['id'],'label'=>'Selected · '.(transcription_app_clean_v300((string)$row['title'],120)?:('Transcript #'.(int)$row['id'])),
                'mode'=>'selected_transcript','session_id'=>(int)$row['id'],'updated_at'=>(string)$row['updated_at'],
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

function transcription_deeper_apply_comparison_label_v307(array &$modules,string $label): void
{
    if (!isset($modules['changes']['result']) || !is_array($modules['changes']['result'])) return;
    foreach (['new','changed','contradicted','resolved','still_open'] as $section) {
        foreach ($modules['changes']['result'][$section]??[] as &$item) if (is_array($item)) $item['comparison_target']=$label;
        unset($item);
    }
}

function transcription_deeper_agent_memory_text_v307(array $modules,array $session): string
{
    $parts=[];
    foreach ((array)($modules['knowledge']['result']['items']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
        $state=(string)($item['knowledge_state']??'');
        if (in_array($state,['conflicting','updates_existing','more_specific'],true)) $parts[]='Knowledge '.$state.': '.(string)($item['value']??'');
    }
    foreach ((array)($modules['crm']['result']['objections']??[]) as $item) if (is_array($item) && (string)($item['review_state']??'')==='accepted') $parts[]='CRM objection: '.(string)($item['objection']??'');
    foreach ((array)($modules['crm']['result']['buying_signals']??[]) as $item) if (is_array($item) && (string)($item['review_state']??'')==='accepted') $parts[]='CRM buying signal: '.(string)($item['signal']??'');
    foreach ((array)($modules['opportunities']['result']['items']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted' || (int)($item['opportunity_score']??0)<70) continue;
        $parts[]='Opportunity '.(int)$item['opportunity_score'].'/100: '.(string)($item['opportunity']??'');
    }
    foreach ((array)($modules['research_brief']['result']['claims']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
        if (in_array((string)($item['verification']??''),['unsupported','mixed'],true)) $parts[]='Claim '.(string)$item['verification'].': '.(string)($item['claim']??'');
    }
    $parts=array_values(array_unique(array_filter(array_map('trim',$parts))));
    if (!$parts) return '';
    return mb_strimwidth('Deeper transcription intelligence for '.((string)($session['title']??'')?:('Transcript #'.(int)($session['id']??0))).":\n- ".implode("\n- ",$parts),0,5800,'…');
}

function transcription_deeper_write_agent_memory_v307(array $user,array $session,array $modules): int
{
    if (!function_exists('agent_brain_v122_upsert_system_memory')) return 0;
    $text=transcription_deeper_agent_memory_text_v307($modules,$session);
    if ($text==='') return 0;
    return agent_brain_v122_upsert_system_memory(
        $user,'transcription_deep','transcription-deep:'.(int)$session['id'],$text,
        ['source'=>'transcription_deeper_v307','session_id'=>(int)$session['id'],'title'=>(string)($session['title']??''),'updated_at'=>gmdate('c')],0.94
    );
}

function transcription_deeper_enrich_v307(
    PDO $pdo,array $user,array $session,array $segments,array $master,string $currentHash,array $comparison=[]
): array {
    $priorReview=transcription_intelligence_review_index_v302($master);
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $stats=['knowledge'=>0,'crm_contacts'=>0,'opportunities'=>0,'claims'=>0];
    if (isset($modules['knowledge'])) $stats['knowledge']=transcription_deeper_enrich_knowledge_v307($pdo,$user,$modules);
    if (isset($modules['crm'])) {
        $crm=transcription_deeper_enrich_crm_v307($pdo,$user,$session,$segments,$modules);
        $stats['crm_contacts']=(int)($crm['contacts']??0);
    }
    if (isset($modules['opportunities'])) $stats['opportunities']=transcription_deeper_enrich_opportunities_v307($modules);
    if (isset($modules['research_brief'])) $stats['claims']=transcription_deeper_enrich_claims_v307($modules);

    $mode=(string)($comparison['mode']??'project_history');
    if (!in_array($mode,['project_history','previous','selected_transcript','accepted_intelligence'],true)) $mode='project_history';
    $label=match($mode){
        'previous'=>'Previous transcription','selected_transcript'=>'Selected transcription','accepted_intelligence'=>'Current accepted intelligence',default=>'Project / conversation history'
    };
    transcription_deeper_apply_comparison_label_v307($modules,$label);

    $modules=transcription_intelligence_normalize_modules_v302($modules,$priorReview);
    $master['analysis']=transcription_deeper_persist_modules_v307($pdo,(int)$session['id'],$modules);
    $memoryId=transcription_deeper_write_agent_memory_v307($user,$session,$modules);
    return ['master'=>$master,'stats'=>$stats,'comparison'=>['mode'=>$mode,'label'=>$label,'session_id'=>max(0,(int)($comparison['session_id']??0))],'memory_id'=>$memoryId];
}

function transcription_app_status_v307(PDO $pdo,array $user,?array $session,?array $master,array $map): array
{
    $view=transcription_app_status_v306($pdo,$user,$session,$master,$map);
    $view['registry']=transcription_app_registry_public_v307();
    if ($master) {
        $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
        $view['master']=$master;
        $view['master']['analysis']=transcription_deeper_projection_v307($modules);
    }
    $view['deeper_intelligence']=['version'=>307,'enabled'=>true];
    return $view;
}

function transcription_app_analyze_v307(
    PDO $pdo,array $user,int $sessionId,string $mode,array $requestedApps,mixed $workflowInput=[],array $comparison=[]
): array {
    $workflow=transcription_workflow_normalize_v304($workflowInput);
    $result=transcription_app_analyze_v306($pdo,$user,$sessionId,$mode,$requestedApps,$workflow);
    $session=artist_listening_v172_session($pdo,$user,$sessionId);
    $segments=artist_listening_v172_segments($pdo,$sessionId);
    $map=artist_listening_transcript_page_map($segments);
    $master=is_array($result['master']??null)?$result['master']:null;

    if ($mode!=='live' && ($workflow['depth']??'standard')==='deep' && $master && empty($result['skipped'])) {
        $deep=transcription_deeper_enrich_v307($pdo,$user,$session,$segments,$master,(string)($map['source_hash']??''),$comparison);
        $master=$deep['master'];
        $result['deeper_intelligence']=$deep['stats'];
        $result['comparison']=$deep['comparison'];
        $result['agent_memory_id']=$deep['memory_id'];
    }

    $view=transcription_app_status_v307($pdo,$user,$session,$master,$map);
    $result['master']=$view['master'];
    $result['app_status']=$view['app_status'];
    $result['registry']=$view['registry'];
    $result['output_input']=$view['output_input']??($result['output_input']??[]);
    $result['comparison_targets']=transcription_deeper_comparison_targets_v307($pdo,$user,$session);
    return $result;
}
