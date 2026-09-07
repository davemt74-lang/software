<?php
declare(strict_types=1);

/** Extension-aware durable item/action adapter for v307 registry sections. */
const VP3_TRANSCRIPTION_DEEPER_ITEMS_V307 = 'transcription-deeper-items-v307-20260907';

function transcription_deeper_review_index_v307(?array $master): array
{
    if (!$master) return [];
    $analysis=is_array($master['analysis']??null)?$master['analysis']:[];
    $modules=transcription_app_modules_v306($analysis,$master);
    $registry=transcription_app_registry_v307();
    $index=[];
    foreach ($modules as $appId=>$module) {
        if (!isset($registry[$appId]) || !is_array($module)) continue;
        $result=is_array($module['result']??null)?$module['result']:[];
        foreach ((array)($registry[$appId]['sections']??[]) as $section) {
            $sectionKey=(string)($section['key']??'');
            $primary=(string)($section['primary']??'text');
            foreach ((array)($result[$sectionKey]??[]) as $item) {
                if (!is_array($item)) continue;
                $fingerprint=transcription_intelligence_source_fingerprint_v302($appId,$sectionKey,$item,$primary);
                $actions=is_array($item['actions']??null)?transcription_app_sanitize_value_v300($item['actions']):[];
                $relations=is_array($item['relations']??null)?transcription_app_sanitize_value_v300($item['relations']):[];
                $index[$fingerprint]=[
                    'item_id'=>(string)($item['item_id']??''),'review_state'=>(string)($item['review_state']??'unreviewed'),
                    'reviewed_at'=>(string)($item['reviewed_at']??''),'user_edited'=>!empty($item['user_edited']),
                    'edited_text'=>(string)($item['edited_text']??''),'actions'=>is_array($actions)?$actions:[],
                    'relations'=>is_array($relations)?$relations:[],
                ];
            }
        }
    }
    return $index;
}

function transcription_deeper_normalize_modules_v307(array $modules,array $priorReviewIndex=[]): array
{
    // Preserve all proven v302 behavior first, then cover v307-only sections.
    $modules=transcription_intelligence_normalize_modules_v302($modules,$priorReviewIndex);
    $registry=transcription_app_registry_v307();
    foreach ($modules as $appId=>&$module) {
        if (!isset($registry[$appId]) || !is_array($module)) continue;
        $result=is_array($module['result']??null)?$module['result']:[];
        foreach ((array)($registry[$appId]['sections']??[]) as $section) {
            $sectionKey=(string)($section['key']??'');
            if ($sectionKey==='' || !is_array($result[$sectionKey]??null)) continue;
            $primary=trim((string)($section['primary']??'text'))?:'text';
            foreach ($result[$sectionKey] as $offset=>&$item) {
                if (!is_array($item)) continue;
                $fingerprint=transcription_intelligence_source_fingerprint_v302($appId,$sectionKey,$item,$primary);
                $prior=$priorReviewIndex[$fingerprint]??[];
                $itemId=trim((string)($item['item_id']??$prior['item_id']??''));
                if ($itemId==='') $itemId='ti_'.substr($fingerprint,0,20);
                $state=strtolower(trim((string)($item['review_state']??$prior['review_state']??'unreviewed')));
                if (!in_array($state,['unreviewed','accepted','rejected'],true)) $state='unreviewed';
                $item['item_id']=$itemId;$item['plugin_id']=$appId;$item['section_key']=$sectionKey;
                $item['source_fingerprint']=$fingerprint;$item['review_state']=$state;
                $item['reviewed_at']=(string)($item['reviewed_at']??$prior['reviewed_at']??'');
                $item['user_edited']=!empty($item['user_edited'])||!empty($prior['user_edited']);
                $edited=trim((string)($item['edited_text']??$prior['edited_text']??''));
                if ($item['user_edited'] && $edited!=='') {$item['edited_text']=$edited;$item[$primary]=$edited;}
                else unset($item['edited_text']);
                $actions=is_array($item['actions']??null)?$item['actions']:(is_array($prior['actions']??null)?$prior['actions']:[]);
                if ($actions) $item['actions']=transcription_app_sanitize_value_v300($actions); else unset($item['actions']);
                $relations=is_array($item['relations']??null)?$item['relations']:(is_array($prior['relations']??null)?$prior['relations']:[]);
                if ($relations) $item['relations']=transcription_app_sanitize_value_v300($relations); else unset($item['relations']);
                $item['evidence_refs']=transcription_intelligence_evidence_refs_v302($item);
                $result[$sectionKey][$offset]=$item;
            }
            unset($item);
        }
        $module['result']=$result;
    }
    unset($module);
    if (function_exists('transcription_intelligence_prune_relations_v305')) transcription_intelligence_prune_relations_v305($modules);
    return $modules;
}

function transcription_deeper_find_item_v307(array &$modules,string $appId,string $itemId): array
{
    $registry=transcription_app_registry_v307();
    if (!isset($registry[$appId],$modules[$appId])) throw new RuntimeException('Transcription plugin result not found.');
    $result=is_array($modules[$appId]['result']??null)?$modules[$appId]['result']:[];
    foreach ((array)($registry[$appId]['sections']??[]) as $section) {
        $sectionKey=(string)($section['key']??'');
        foreach ((array)($result[$sectionKey]??[]) as $offset=>$item) {
            if (is_array($item) && hash_equals((string)($item['item_id']??''),$itemId)) {
                return [$sectionKey,(int)$offset,(string)($section['primary']??'text')];
            }
        }
    }
    throw new RuntimeException('Transcription intelligence item not found.');
}

function transcription_deeper_review_item_v307(PDO $pdo,int $sessionId,array $master,string $appId,string $itemId,string $reviewState): array
{
    $reviewState=strtolower(trim($reviewState));
    if (!in_array($reviewState,['unreviewed','accepted','rejected'],true)) throw new RuntimeException('Choose accepted, rejected or unreviewed.');
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $modules=transcription_deeper_normalize_modules_v307($modules);
    [$sectionKey,$offset]=transcription_deeper_find_item_v307($modules,$appId,$itemId);
    if ($reviewState==='rejected' && function_exists('transcription_intelligence_remove_item_relations_v305')) transcription_intelligence_remove_item_relations_v305($modules,$itemId);
    $modules[$appId]['result'][$sectionKey][$offset]['review_state']=$reviewState;
    $modules[$appId]['result'][$sectionKey][$offset]['reviewed_at']=$reviewState==='unreviewed'?'':gmdate('c');
    $master['analysis']=transcription_deeper_persist_modules_v307($pdo,$sessionId,$modules);
    return $master;
}

function transcription_deeper_edit_item_v307(PDO $pdo,int $sessionId,array $master,string $appId,string $itemId,string $text): array
{
    $text=transcription_app_clean_v300($text,1400);
    if ($text==='') throw new RuntimeException('Edited intelligence text cannot be empty.');
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $modules=transcription_deeper_normalize_modules_v307($modules);
    [$sectionKey,$offset,$primary]=transcription_deeper_find_item_v307($modules,$appId,$itemId);
    $item=&$modules[$appId]['result'][$sectionKey][$offset];
    $previousText=transcription_app_clean_v300((string)($item[$primary]??''),1400);
    if ($previousText!==$text) {
        unset($item['actions']);
        if (function_exists('transcription_intelligence_remove_item_relations_v305')) transcription_intelligence_remove_item_relations_v305($modules,$itemId);
    }
    $item[$primary]=$text;$item['edited_text']=$text;$item['user_edited']=true;$item['review_state']='accepted';$item['reviewed_at']=gmdate('c');
    $master['analysis']=transcription_deeper_persist_modules_v307($pdo,$sessionId,$modules);
    return $master;
}

function transcription_deeper_item_snapshot_v307(array $master,string $appId,string $itemId): array
{
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $modules=transcription_deeper_normalize_modules_v307($modules);
    [$sectionKey,$offset,$primary]=transcription_deeper_find_item_v307($modules,$appId,$itemId);
    $item=$modules[$appId]['result'][$sectionKey][$offset]??null;
    if (!is_array($item)) throw new RuntimeException('Transcription intelligence item not found.');
    $text=transcription_app_clean_v300((string)($item[$primary]??''),1400);
    if ($text==='') throw new RuntimeException('This intelligence item has no actionable text.');
    return ['modules'=>$modules,'section_key'=>$sectionKey,'offset'=>$offset,'primary'=>$primary,'item'=>$item,'text'=>$text];
}

function transcription_deeper_record_receipt_v307(PDO $pdo,int $sessionId,array $master,string $appId,string $itemId,string $actionKey,array $receipt): array
{
    $snapshot=transcription_deeper_item_snapshot_v307($master,$appId,$itemId);
    $modules=$snapshot['modules'];$sectionKey=(string)$snapshot['section_key'];$offset=(int)$snapshot['offset'];
    $item=&$modules[$appId]['result'][$sectionKey][$offset];
    $actions=is_array($item['actions']??null)?$item['actions']:[];
    $receipt['action_key']=$actionKey;$receipt['performed_at']=(string)($receipt['performed_at']??gmdate('c'));
    $actions[$actionKey]=transcription_app_sanitize_value_v300($receipt);$item['actions']=$actions;
    $master['analysis']=transcription_deeper_persist_modules_v307($pdo,$sessionId,$modules);
    return $master;
}

function transcription_deeper_knowledge_write_guard_v307(PDO $pdo,array $user,array &$item,string $text): void
{
    if ((string)($item['plugin_id']??'')!=='knowledge') return;
    $state=trim((string)($item['knowledge_state']??''));
    if ($state==='') {
        $classification=transcription_deeper_knowledge_state_v307($item,transcription_deeper_knowledge_candidates_v307($pdo,$user,$text));
        foreach ($classification as $key=>$value) $item[$key]=$value;
        $state=(string)$item['knowledge_state'];
    }
    if ($state==='duplicate') throw new RuntimeException('This knowledge is already present in Agent Brain or Personal Knowledge. Review the existing match instead of creating a duplicate.');
    if ($state==='temporary') throw new RuntimeException('This item is classified as temporary project state. Keep it in tasks/project context unless you intentionally edit it into durable knowledge.');
}

function transcription_deeper_execute_action_v307(
    PDO $pdo,array $user,array $session,array $master,string $appId,string $itemId,string $action,int $targetId=0
): array {
    $allowedActions=['main_chat','agent_brain','agent_task','personal_knowledge','project_note','crm_note','crm_task'];
    $action=strtolower(trim($action));
    if (!in_array($action,$allowedActions,true)) throw new RuntimeException('Unsupported transcription intelligence action.');
    $snapshot=transcription_deeper_item_snapshot_v307($master,$appId,$itemId);
    $item=$snapshot['item'];transcription_intelligence_require_accepted_v303($item);
    $sectionKey=(string)$snapshot['section_key'];$text=(string)$snapshot['text'];
    if (in_array($action,['agent_brain','personal_knowledge'],true)) transcription_deeper_knowledge_write_guard_v307($pdo,$user,$item,$text);
    $operations=transcription_intelligence_operational_context_v303($pdo,$user,$session);
    if ($action==='project_note') $targetId=max(0,(int)($operations['project_note']['track_id']??0));
    $actionKey=transcription_intelligence_action_key_v303($action,$action==='project_note'?0:$targetId);
    $existing=transcription_intelligence_existing_receipt_v303($item,$actionKey);
    if ($existing && ($action!=='project_note'||(int)($existing['target_id']??0)===$targetId)) return ['master'=>$master,'receipt'=>$existing,'existing'=>true,'operations'=>$operations];

    $registry=transcription_app_registry_v307();$appTitle=(string)($registry[$appId]['title']??$appId);
    $evidence=transcription_intelligence_evidence_label_v303($item);$sourceLabel=trim((string)($session['title']??'Transcript'))?:'Transcript';
    $receipt=['action'=>$action,'record_id'=>0,'target_id'=>$targetId,'label'=>'','target_url'=>'','performed_at'=>gmdate('c')];
    if ($action==='main_chat') {
        if (empty($operations['main_chat']['available'])) throw new RuntimeException('Main AI Chat is unavailable for this account.');
        $message='Reviewed transcription intelligence from “'.$sourceLabel.'”. '.$appTitle.': '.$text;
        if ($evidence!=='') $message.=' Evidence: '.$evidence.'.';
        $conversationId=agent_chat_v101_append_ecosystem_message($user,$message,[
            'source'=>'transcription_intelligence','session_id'=>(int)$session['id'],'plugin_id'=>$appId,'section_key'=>$sectionKey,
            'item_id'=>$itemId,'evidence_refs'=>(array)($item['evidence_refs']??[]),'review_state'=>'accepted','target_url'=>url('/artist-listening.php'),
        ]);
        if ($conversationId<1) throw new RuntimeException('Could not send this intelligence item to Main AI Chat.');
        $receipt['record_id']=$conversationId;$receipt['label']='Sent to Main Chat';$receipt['target_url']=(string)$operations['main_chat']['url'];
    } elseif ($action==='agent_brain') {
        if (empty($operations['agent_brain']['available'])) throw new RuntimeException('Agent Brain is unavailable for this account.');
        $memoryId=agent_brain_v122_upsert_system_memory($user,'transcript_intelligence','transcription-item:'.$itemId,$text,[
            'source'=>'transcription_intelligence_v307','session_id'=>(int)$session['id'],'plugin_id'=>$appId,'section_key'=>$sectionKey,
            'item_id'=>$itemId,'evidence_refs'=>(array)($item['evidence_refs']??[]),'review_state'=>'accepted','knowledge_state'=>(string)($item['knowledge_state']??''),'source_title'=>$sourceLabel,'saved_at'=>gmdate('c')
        ],0.99);
        if ($memoryId<1) throw new RuntimeException('Could not save this item to Agent Brain.');
        $receipt['record_id']=$memoryId;$receipt['label']='Saved to Agent Brain';
    } elseif ($action==='agent_task') {
        if (empty($operations['agent_task']['available'])) throw new RuntimeException('Agent task storage is unavailable for this account.');
        $memoryId=transcription_intelligence_upsert_task_v303($pdo,$user,$session,$appId,$sectionKey,$item,$text);
        $kind=transcription_intelligence_task_kind_v303($appId,$sectionKey);$receipt['record_id']=$memoryId;$receipt['label']=$kind==='commitment'?'Created Agent commitment':'Created Agent task';
    } elseif ($action==='personal_knowledge') {
        if (empty($operations['personal_knowledge']['available'])) throw new RuntimeException('Personal Knowledge is unavailable for this account.');
        $knowledgeId=personal_knowledge_store($user,'transcription-intelligence-item:'.$itemId,mb_strimwidth($appTitle.' · '.$sourceLabel,0,190,'…'),$text,
            'Reviewed transcription intelligence · '.$appTitle.' · '.($evidence!==''?$evidence:'source transcript').' · state '.((string)($item['knowledge_state']??'new')));
        $receipt['record_id']=$knowledgeId;$receipt['label']='Saved to Personal Knowledge';
    } elseif ($action==='project_note') {
        $project=$operations['project_note']??[];
        if (empty($project['available'])||$targetId<1) throw new RuntimeException('No writable project track is linked to this transcript.');
        $note='[Reviewed transcription intelligence · '.$appTitle."]\n".$text;if ($evidence!=='') $note.="\nEvidence: ".$evidence;
        $stmt=$pdo->prepare('INSERT INTO track_notes (track_id,user_id,note) VALUES (?,?,?)');$stmt->execute([$targetId,(int)$user['id'],mb_strimwidth($note,0,65000,'…')]);
        $noteId=(int)$pdo->lastInsertId();if ($noteId<1) throw new RuntimeException('Could not create the project note.');
        $receipt['record_id']=$noteId;$receipt['label']='Added project note';
    } elseif ($action==='crm_note'||$action==='crm_task') {
        if (empty($operations['crm']['available'])) throw new RuntimeException('No explicitly matched CRM lead is available for this transcript.');
        $target=transcription_intelligence_validate_crm_target_v303((array)($operations['crm']['targets']??[]),$targetId);$leadId=(int)$target['lead_id'];
        if ($action==='crm_note') {
            $activityId=crm_v180_activity($pdo,$leadId,'transcript_intelligence',$text,(int)$user['id'],[
                'source_session_id'=>(int)$session['id'],'plugin_id'=>$appId,'section_key'=>$sectionKey,'item_id'=>$itemId,
                'evidence_refs'=>(array)($item['evidence_refs']??[]),'review_state'=>'accepted','deeper_intelligence_version'=>307,
            ]);
            if ($activityId<1) throw new RuntimeException('Could not add the CRM note.');$receipt['record_id']=$activityId;$receipt['label']='Added CRM note';
        } else {
            $taskId=crm_v180_create_task($pdo,$leadId,['title'=>$text,'task_type'=>'follow_up','assigned_user_id'=>0,'due_at'=>''],(int)$user['id']);
            if ($taskId<1) throw new RuntimeException('Could not create the CRM follow-up task.');$receipt['record_id']=$taskId;$receipt['label']='Created CRM task';
        }
        $receipt['target_id']=$leadId;$receipt['target_url']=url('/admin/crm-lead.php?id='.$leadId);
    }
    $master=transcription_deeper_record_receipt_v307($pdo,(int)$session['id'],$master,$appId,$itemId,$actionKey,$receipt);
    return ['master'=>$master,'receipt'=>$receipt,'existing'=>false,'operations'=>$operations];
}
