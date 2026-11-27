<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-runtime-v1.php';

/** Read delivery evidence without invoking a specialist, provider, or worker. */
function vp3_initiative_engagement_status_v1(PDO $pdo,array $user,int $agentId,int $cid,array $health): array
{
    $view=['presence'=>'unavailable','gate'=>'unavailable','latest_tick'=>null,'specialist_scan'=>null,'latest_preparation'=>null,'last_post'=>null,
        'specialist_handoffs'=>null,'specialist_assignments'=>null,'curiosity_rule'=>null,'opportunity_checks'=>null,'recent_posts'=>null,
        'workflow_failures'=>null,'tick_checked'=>false,'preparation_checked'=>false,'post_checked'=>false];
    if(($health['state']??null)==='paused')return array_replace($view,['gate'=>'paused']);
    if(($health['state']??null)==='linked_elsewhere')return array_replace($view,['gate'=>'linked_elsewhere']);
    if(empty($health['enabled']))return $view;

    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$cid,$health,$view):array{
        try{
            $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
            $policy=$context['policy'];$live=$context['user'];$owner=(int)$live['id'];
            if(($policy['enabled']??null)!==true||($policy['conversation_id']??null)!==$cid
                ||($policy['revision']??null)!==($health['policy_revision']??null))return $view;
            $observation=null;
            $view['presence']=vp3_initiative_presence_v1($pdo,$live,$cid,false,$observation);
            $view['presence_age_seconds']=$observation['age_seconds']??null;
            $view['busy_reason']=$view['presence']==='busy'?($observation['busy_reason']??null):null;
            $view['gate']=vp3_initiative_report_gate_v1($pdo,$live,$cid,$agentId);
            $view['latest_tick']=vp3_initiative_foreground_tick_v1($live,$policy);
            $view['tick_checked']=true;
            try{
                require_once __DIR__.'/agent-coordination-schedule-v1.php';
                $view['specialist_scan']=vp3_coordination_scan_checkpoint_v1($live,$policy);
            }catch(Throwable $error){
                vp3_initiative_engagement_trace_v1('initiative.engagement_specialist_scan_unavailable',$error);
            }
            try{
                require_once __DIR__.'/agent-initiative-opportunity-schedule-v1.php';
                $view['opportunity_checks']=[];
                foreach(vp3_initiative_opportunity_checkpoint_v1($live,$policy) as $lane=>$check){
                    $runs=vp3_initiative_runtime_runs_v1($pdo,$owner,$agentId,$policy,opportunityLane:$lane);
                    $work=isset($runs[0])?vp3_initiative_engagement_preparation_v1($pdo,$owner,$runs[0]):null;
                    $view['opportunity_checks'][$lane]=['state'=>$check['state'],'at'=>gmdate(DATE_ATOM,$check['checked_at']),
                        'assignment'=>$work,'failure'=>$check['failure']??null];
                }
            }catch(Throwable $error){
                vp3_initiative_engagement_trace_v1('initiative.engagement_opportunity_checks_unavailable',$error);
            }
            try{
                require_once __DIR__.'/agent-coordination-rules-v1.php';
                $settings=vp3_coordination_rules_v1($pdo,$live,$agentId);
                $rule=$settings['rules']['curiosity']['open_question']??null;
                if(in_array($rule,['ask','watch'],true))$view['curiosity_rule']=$rule;
            }catch(Throwable $error){
                vp3_initiative_engagement_trace_v1('initiative.engagement_curiosity_rule_unavailable',$error);
            }
            try{
                $recent=vp3_initiative_runtime_runs_v1($pdo,$owner,$agentId,$policy);
                foreach($recent as $run){
                    if(($run['capability_key']??null)!=='initiative.prepare')continue;
                    $view['latest_preparation']=vp3_initiative_engagement_preparation_v1($pdo,$owner,$run);
                    break;
                }
                $view['preparation_checked']=true;
                $pending=vp3_initiative_runtime_runs_v1($pdo,$owner,$agentId,$policy,pending:true);
                $active=vp3_initiative_runtime_runs_v1($pdo,$owner,$agentId,$policy,executingOnly:true);
                $liveRuns=array_replace(array_column($pending,null,'id'),array_column($active,null,'id'));
                $view['specialist_assignments']=vp3_initiative_engagement_assignments_v1($pdo,$owner,$recent,$liveRuns);
            }catch(Throwable $error){
                vp3_initiative_engagement_trace_v1('initiative.engagement_assignment_unavailable',$error);
            }
            try{
                $view['workflow_failures']=vp3_initiative_engagement_workflow_failures_v1($pdo,$owner,$agentId,$policy);
            }catch(Throwable $error){
                vp3_initiative_engagement_trace_v1('initiative.workflow_failure_history_unavailable',$error);
            }
            try{
                $view['specialist_handoffs']=vp3_initiative_engagement_handoffs_v1($pdo,$live,$agentId,$policy,
                    $view['gate']);
            }catch(Throwable $error){
                vp3_initiative_engagement_trace_v1('initiative.engagement_handoffs_unavailable',$error);
            }

            $source=vp3_initiative_engagement_json_v1($pdo,'m.context_json','source');
            // Initiative Chat messages use the SQL clock. Legacy Gmail writers
            // explicitly save UTC dates in the same column.
            $localEpoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at');
            $query=$pdo->prepare("SELECT m.id,m.created_at,m.context_json,$source AS source,$localEpoch AS local_epoch FROM chat_messages m
                JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=?
                WHERE m.conversation_id=? AND m.role='assistant' AND m.user_id IS NULL
                AND $source IN ('agent_initiative','agent_initiative_morning_brief','agent_initiative_reminder',
                    'agent_connection_gmail_content','agent_connection_gmail_inbox_update','agent_coding_canvas')
                ORDER BY m.id DESC LIMIT 5");
            $query->execute([$owner,$cid]);
            $view['recent_posts']=[];
            foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
                $post=vp3_initiative_engagement_post_v1($pdo,$live,$agentId,$cid,$row);
                if($view['last_post']===null)$view['last_post']=$post;
                if(($post['delivery']??null)==='posted')$view['recent_posts'][]=$post;
            }
            $view['post_checked']=true;
        }catch(Throwable $e){
            vp3_initiative_engagement_trace_v1('initiative.engagement_status_unavailable',$e);
        }
        return $view;
    });
}

/** Keep recent terminal attempts visible even after the specialist resumed work. */
function vp3_initiative_engagement_workflow_failures_v1(PDO $pdo,int $owner,int $agentId,array $policy): array
{
    $failures=[];
    foreach(vp3_initiative_runtime_runs_v1($pdo,$owner,$agentId,$policy,failuresOnly:true) as $run){
        $detail=vp3_initiative_runtime_failure_v1($pdo,$owner,$run,false);
        if($detail===null)continue;
        $failures[]=$detail+['specialist'=>vp3_initiative_runtime_job_specialist_v1($run)];
        if(count($failures)>=12)break;
    }
    return $failures;
}

/** Describe the current work independently of scan coverage and past owner replies. */
function vp3_initiative_engagement_specialist_jobs_v1(array $engagement,array $health,int $now): array
{
    if(empty($health['enabled'])||in_array($health['state']??null,['paused','off','linked_elsewhere','unavailable'],true))return [];
    $assignments=$engagement['specialist_assignments']??[];
    $scans=$engagement['specialist_scan']??[];
    $scanAt=strtotime((string)($scans['at']??''))?:0;
    $assessment=$health['latest_assessment_run']??[];
    $verifiedAssessment=$health['last_assessment']??[];
    $lanes=['app-suggestions'=>'app_suggestions','image-generator'=>'media_images','brainstorm'=>'brainstorm','curiosity'=>'curiosity_analysis'];
    $monitors=['gmail'=>'Monitoring Gmail findings','planning'=>'Monitoring planning changes','tasks'=>'Monitoring tasks and reminders',
        'media'=>'Monitoring new watch and listen findings','profile'=>'Monitoring verified visitor conversations and follow-ups'];
    $jobs=[];
    foreach(['gmail','planning','tasks','media','profile','ob1','brainstorm','curiosity','app-suggestions','coding','image-generator'] as $family){
        $own=$assignments[$family]??null;
        $lane=isset($lanes[$family])?($engagement['opportunity_checks'][$lanes[$family]]['assignment']??null):null;
        $work=$own??$lane??[];
        if(($lane['status']??null)==='executing'||(($own['status']??null)!=='executing'&&($lane['run_id']??0)>($own['run_id']??0)))$work=$lane;
        foreach($health['active_jobs']??[] as $active){
            if(($active['specialist']??null)!==$family||($active['expiresAt']??0)<=$now*1000
                ||!preg_match('/\Aworkflow:([1-9][0-9]{0,17})\z/D',(string)($active['id']??''),$match))continue;
            if(($work['run_id']??null)!==(int)$match[1])$work=['run_id'=>(int)$match[1]];
            $work['status']='executing';
            $work['phase']=$active['phase']??'preparing';
            $work['lease_expires_at']=gmdate(DATE_ATOM,intdiv($active['expiresAt'],1000));
            if(!empty($active['at']))$work['at']=$active['at'];
            break;
        }
        $activity=$engagement['specialist_activity'][$family]??[];
        if(($work['status']??null)!=='executing'&&in_array($activity['state']??null,['scanning','working'],true)
            &&(strtotime($activity['expires_at']??'')?:0)>$now){
            $jobs[$family]=['state'=>'working','phase'=>'analyzing','action'=>$activity['state']==='scanning'
                ?'Checking incoming mail for changes':'Analyzing email contents','at'=>$activity['at'],'expires_at'=>$activity['expires_at']];
            continue;
        }
        if($family==='ob1'&&($work['status']??null)!=='executing'&&($assessment['run_id']??0)>0){
            $work=['status'=>match($assessment['status']??null){
                'retry_scheduled'=>'approved','completed'=>'completed','queued'=>'queued','paused'=>'paused',
                'interrupted'=>'interrupted','failed'=>'failed',default=>'unknown',
            },'run_id'=>$assessment['run_id'],'at'=>$assessment['at']??'','step'=>'initiative.plan'];
            if(($assessment['status']??null)==='retry_scheduled')$work['eligible_at']=$assessment['retry_at']??'';
            if(($assessment['reason_code']??null)==='assessment_sources_changed')$work['rechecking']=true;
        }
        $status=$work['status']??'';
        $runId=$work['run_id']??null;
        $at=$work['at']??'';
        $phase=$work['phase']??match($family){
            'ob1'=>'coordinating','brainstorm','coding','app-suggestions','image-generator'=>'brainstorming',default=>'analyzing',
        };
        $action=vp3_initiative_engagement_job_action_v1($family,$work,$phase);
        $record=['phase'=>$phase,'action'=>$action];
        if(is_int($runId)&&$runId>0)$record['run_id']=$runId;
        if(is_string($at)&&$at!=='')$record['at']=$at;
        if(!empty($work['input_specialists']))$record['inputs']=$work['input_specialists'];
        elseif(($work['origin_specialist']??null)==='media')$record['inputs']=['media'];
        if($family==='ob1'&&($verifiedAssessment['run_id']??null)===$runId&&!empty($verifiedAssessment['inputs'])){
            $record['inputs']=$verifiedAssessment['inputs'];
        }
        if($status==='executing'&&(strtotime($work['lease_expires_at']??'')?:0)>$now){
            $jobs[$family]=['state'=>'working','expires_at'=>$work['lease_expires_at']]+$record;
            continue;
        }
        $workAt=strtotime((string)$at)?:0;
        $oldFailure=in_array($status,['interrupted','failed'],true)&&isset($monitors[$family])
            &&$scanAt>max($workAt,$now-180)&&$workAt<$now-150
            &&in_array($scans['specialists'][$family]['coverage']??null,['limited','ready'],true);
        if(!$oldFailure&&in_array($status,['queued','approved','interrupted','paused','failed'],true)){
            $state=match($status){'approved','queued'=>empty($work['eligible_at'])?'queued':'waiting',default=>$status};
            if(!empty($work['rechecking'])){
                $state='waiting';$record['action']='Recheck changed inputs before coordinating';
            }
            if(!empty($work['eligible_at']))$record['next_at']=$work['eligible_at'];
            $jobs[$family]=['state'=>$state]+$record;
            continue;
        }
        $post=null;
        foreach($engagement['recent_posts']??[] as $item){
            if(($item['delivery']??null)==='posted'&&($item['specialist']??null)===$family
                &&(($item['run_id']??null)===$runId||$runId===null)){$post=$item;break;}
        }
        $handoff=$engagement['specialist_handoffs'][$family]??[];
        if($post===null&&$runId!==null&&($handoff['run_id']??null)===$runId&&($handoff['state']??null)==='posted')$post=$handoff;
        if(($work['delivery']??null)==='pending'){
            $jobs[$family]=['state'=>'waiting','phase'=>'presenting','action'=>'Verified result ready for Chat']+$record;
            continue;
        }
        $finishedAt=strtotime($post['at']??$at)?:0;
        if(($status==='completed'||$post!==null)&&($finishedAt>=$now-150||!isset($monitors[$family]))){
            $destination=match(true){
                ($post['result']??null)==='canvas_draft'=>'canvas_draft',
                ($work['opportunity_stage']??null)==='proposal_ready'||($post['result']??null)==='canvas_proposal'=>'canvas_proposal',
                ($work['opportunity_stage']??null)==='concept_ready'||($post['result']??null)==='image_concept'=>'image_proposal',
                $post!==null=>'chat',
                in_array($work['delivery_reason']??null,['specialist_report','native_presentation'],true)=>'top_of_mind',
                ($work['delivery']??null)==='private'=>'private_preparation',
                default=>null,
            };
            $action=match($destination){
                'chat'=>'Delivered a verified Chat update','canvas_draft'=>'Saved a private Canvas draft',
                'canvas_proposal'=>'Prepared a Canvas proposal','image_proposal'=>'Prepared an image concept',
                'top_of_mind'=>'Saved a specialist finding to Top of Mind','private_preparation'=>'Saved a private preparation',
                default=>$family==='ob1'?'Finished coordinating verified inputs':'Finished the verified assignment',
            };
            $jobs[$family]=['state'=>'completed','phase'=>$family==='ob1'?'coordinating':'presenting','action'=>$action]
                +($destination!==null?['destination'=>$destination]:[])
                +(is_int($post['message_id']??null)&&$post['message_id']>0?['message_id'=>$post['message_id']]:[])
                +(is_string($post['at']??null)?['at'=>$post['at']]:[])+$record;
        }elseif(isset($monitors[$family])&&$scanAt>=$now-180&&$scanAt<=$now
            &&in_array($scans['specialists'][$family]['coverage']??null,['limited','ready'],true)){
            $jobs[$family]=['state'=>'monitoring','phase'=>'monitoring','action'=>$monitors[$family],'at'=>$scans['at']];
        }
    }
    return $jobs;
}

function vp3_initiative_engagement_job_action_v1(string $family,array $work,string $phase): string
{
    if($phase==='presenting')return 'Verify and present the result';
    return match($family){
        'gmail'=>'Review email findings and a useful next step',
        'planning'=>'Review scheduling facts and conflicts',
        'tasks'=>'Review a task, list or reminder',
        'profile'=>'Review verified visitor conversations, calls and follow-ups',
        'media'=>($work['step']??null)==='youtube.playback_offer'?'Prepare a playback suggestion':'Review what was watched or played',
        'brainstorm'=>'Explore ideas across permitted specialist briefs',
        'ob1'=>match($phase){
            'analyzing','reviewing'=>'Review current specialist findings','brainstorming'=>'Find useful next steps across the evidence',
            default=>'Coordinate decisions across specialist inputs',
        },
        'app-suggestions','coding'=>($work['origin_specialist']??null)==='media'
            ?'Design a one-page Canvas from a watch or listen':'Prepare a private Canvas workflow',
        'image-generator'=>($work['origin_specialist']??null)==='media'
            ?'Develop an image concept from a watch or listen':'Develop a private image concept',
        default=>'Review the current assignment',
    };
}

/** Compare the two bounded, exact-policy reads without exposing the stored job inputs. */
function vp3_initiative_engagement_assignments_v1(PDO $pdo,int $owner,array $recent,array $active): array
{
    require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
    require_once __DIR__.'/agent-live-context-v1.php';
    $bySpecialist=[];
    $rank=static fn(string $status):int=>match($status){'executing'=>3,'queued','approved'=>2,default=>1};
    foreach(array_replace(array_column($recent,null,'id'),array_column($active,null,'id')) as $run){
        if(($run['capability_key']??null)!=='initiative.prepare')continue;
        $goal=json_decode((string)($run['goal']??''),true);
        if(!is_array($goal))continue;
        $specialist=match(true){
            ($goal['tool']??null)==='work.prepare_private'&&($goal['opportunity_lane']??null)==='missions'=>'mission',
            ($goal['tool']??null)==='chat.check_in'&&vp3_live_context_eyes_visit_ref_v1($goal['primary']??null)=>'eyes',
            default=>vp3_initiative_goal_specialist_v1($goal),
        };
        if($specialist===null)continue;
        $prior=$bySpecialist[$specialist]??null;
        if($prior!==null&&$rank($prior['status'])>=$rank($run['status']??''))continue;
        try{
            $verified=false;
            $assignment=vp3_initiative_engagement_preparation_v1($pdo,$owner,$run,$verified);
        }catch(Throwable $error){
            vp3_initiative_engagement_trace_v1('initiative.engagement_specialist_assignment_unavailable',$error);
            $verified=false;
        }
        if($verified&&($prior===null||$rank($assignment['status'])>$rank($prior['status']))){
            $assignment['specialist']=$specialist;
            $bySpecialist[$specialist]=$assignment;
        }elseif($prior===null){
            $bySpecialist[$specialist]=['run_id'=>(int)$run['id'],'specialist'=>$specialist,'status'=>'unavailable'];
        }
    }
    return $bySpecialist;
}

/** Project each work specialist's latest shared Ask/Report assignment in this policy. */
function vp3_initiative_engagement_handoffs_v1(PDO $pdo,array $user,int $agentId,array $policy,string $gate,?string $only=null): array
{
    require_once __DIR__.'/agent-coordination-work-v1.php';
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    $rows=[];
    foreach(vp3_initiative_runtime_runs_v1($pdo,(int)$user['id'],$agentId,$policy,coordinationOnly:true) as $run){
        $goal=json_decode((string)$run['goal'],true);
        $route=$goal['coordination_route']??null;
        $specialist=$route['specialist']??null;
        if($specialist==='casual'&&in_array($goal['primary']??null,['casual:media','casual:music','casual:youtube','casual:youtube_history'],true)){
            $specialist='media';
        }
        if($only!==null&&$specialist!==$only)continue;
        if(($goal['tool']??null)!=='coordination.review'||isset($goal['followthrough'])||isset($goal['coordination_work'])
            ||!is_array($route)||(!vp3_coordination_chat_route_v1($route)
                &&!(in_array($specialist,['curiosity','media'],true)&&($route['action']??null)==='ask'))
            ||isset($rows[$specialist]))continue;
        if(($run['source_key']??null)!=='coordination:'.($goal['coordination_id']??''))continue;
        $status=$run['status']??null;
        [$state,$reason]=match($status){
            'approved','queued'=>['queued','assignment_queued'],
            'executing'=>vp3_initiative_engagement_preparation_v1($pdo,(int)$user['id'],$run)['status']==='executing'
                ?['working','assignment_running']:['blocked','claim_expired'],
            'paused'=>['blocked','assignment_paused'],
            'cancelled'=>['blocked','assignment_cancelled'],
            'failed'=>['blocked','assignment_failed'],
            default=>['unverified','result_unavailable'],
        };
        $messageId=null;$replyState=null;
        if($status==='completed'){
            $result=vp3_coordination_verified_run_v1($pdo,$user,$run);
            $delivery=$result['initiative_delivery']??null;
            if($delivery==='delivered'){
                $post=vp3_coordination_chat_projection_v1($pdo,$user,$policy,$run,$result);
                if($post!==null){
                    [$state,$reason]=['posted','saved_message'];
                    $messageId=$post['message_id'];
                    if(in_array($post['reply_state'],vp3_initiative_followthrough_states_v1(),true)){
                        $replyState=$post['reply_state'];
                    }
                }
            }elseif($delivery==='pending'){
                $state='waiting';
                $deliveryGate=$gate;
                if($deliveryGate==='ready'&&$route['specialist']==='media'){
                    require_once __DIR__.'/agent-media-entertainment-v1.php';
                    if(!vp3_media_chat_ready_v1($pdo,$user,$goal,$hold))$deliveryGate=$hold;
                }
                $pendingReason=$result['initiative_delivery_reason']??null;
                $reason=in_array($pendingReason,['result_unavailable','transcript_pacing'],true)?$pendingReason:
                    (in_array($deliveryGate,['ready','quiet_chat_required','owner_turn_pending','recent_proactive_post','playing',
                        'cooldown','new_activity_required','already_offered'],true)
                        ?($deliveryGate==='ready'&&$pendingReason==='delivery_held'?'delivery_held':$deliveryGate):'result_unavailable');
            }elseif(in_array($delivery,['private','withheld'],true)){
                $reason=$result['initiative_delivery_reason']??'private';
                $reason=in_array($reason,['private','duplicate','native_presentation','source_changed','policy_changed','rule_changed','result_unavailable','transcript_history'],true)
                    ?$reason:'result_unavailable';
                $state=in_array($reason,['private','duplicate','native_presentation','transcript_history'],true)?'skipped':'blocked';
            }
        }
        $rows[$specialist]=['state'=>$state,'reason'=>$reason,'run_id'=>(int)$run['id'],
            'message_id'=>$messageId,'reply_state'=>$replyState];
    }
    return $rows;
}

/** Identify the saved post without exposing its email, transcript, or Chat text. */
function vp3_initiative_engagement_post_v1(PDO $pdo,array $user,int $agentId,int $cid,array $row): array
{
    $messageId=(int)$row['id'];
    $source=$row['source'];
    $context=json_decode((string)$row['context_json'],true);
    $gmail=in_array($source,['agent_connection_gmail_content','agent_connection_gmail_inbox_update'],true);
    $at=$gmail?agent_job_utc_timestamp_v1900($row['created_at']):(int)$row['local_epoch'];
    $post=['message_id'=>$messageId,'at'=>$at>0?gmdate(DATE_ATOM,$at):'',
        'source'=>$gmail?'gmail':($source==='agent_coding_canvas'?'coding':'initiative'),
        'run_id'=>null,'specialist'=>null,'result'=>null,'includes_gmail'=>null,'proof'=>'saved_message'];
    if($source==='agent_coding_canvas'){
        require_once __DIR__.'/coding-canvas-chat-v1.php';
        $runId=$context['coding_canvas_run_id']??null;
        $voice=null;
        $card=is_int($runId)&&$runId>0?vp3_coding_canvas_chat_card_v1($pdo,$user,$agentId,$cid,$runId,$messageId,$voice):null;
        if($card!==null){
            $post=array_replace($post,[
                'run_id'=>$runId,'specialist'=>'coding','result'=>'canvas_draft','includes_gmail'=>false,'proof'=>'receipt',
                'delivery'=>'posted','reply_state'=>null,
            ])+vp3_initiative_engagement_voice_post_v1($voice,$messageId,$card['status']==='draft');
        }
        return $post;
    }
    $notification=match($row['source']){
        'agent_initiative_morning_brief'=>'morning_brief','agent_initiative_reminder'=>'reminder',default=>null,
    };
    if($notification!==null){
        $reminder=$notification==='reminder'?($context['initiative_reminder']??[]):[];
        $family=$reminder['specialist']??null;
        $priority=$reminder['priority']??null;
        $original=$reminder['message_id']??null;
        $briefId=$context['morning_brief']['assessment_id']??null;
        $valid=$notification==='reminder'?(is_int($original)&&$original>0&&$original<$messageId):(is_int($briefId)&&$briefId>0);
        if(!$valid)return $post;
        return array_replace($post,['specialist'=>in_array($family,['gmail','planning','tasks','media','canvas','coding','research','image-generator','mission','connected-sites','transcripts'],true)?$family:null,
            'result'=>$notification,'includes_gmail'=>$family==='gmail','delivery'=>'posted','reply_state'=>null,
            'original_message_id'=>is_int($original)&&$original>0?$original:null,
            'priority'=>in_array($priority,['high','normal','low'],true)?$priority:null])
            +vp3_initiative_engagement_voice_post_v1($context['initiative_voice']??null,$messageId,true);
    }
    if($gmail){
        $post['specialist']='gmail';
        $post['result']=$source==='agent_connection_gmail_inbox_update'?'inbox_update':'report';
        $post['includes_gmail']=true;
        $post['delivery']='posted';
        $post['reply_state']=null;
        $post['silent_digest']=($context['gmail_inbox_update_v1']['routine_digest']??false)===true;
        return $post+vp3_initiative_engagement_voice_post_v1(null,$messageId,false);
    }
    try{
        require_once __DIR__.'/agent-initiative-followthrough-v1.php';
        $principal=vp3_initiative_principal_v1($pdo,$user,$agentId);
        $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$cid,$messageId);
    }catch(Throwable $error){
        vp3_initiative_engagement_trace_v1('initiative.engagement_post_receipt_unavailable',$error);
        return $post;
    }
    $goal=$origin['goal'];
    $output=$origin['result']['initiative_tool_result']['output']??[];
    $tool=$goal['tool']??'work.prepare_private';
    $coordination=$tool==='coordination.review';
    require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
    $specialist=match($tool){
        'work.prepare_private'=>($goal['opportunity_lane']??null)==='missions'?'mission':null,
        'chat.check_in'=>vp3_live_context_eyes_visit_ref_v1($output['eyes_visit_ref']??null)?'eyes':'casual',
        default=>vp3_initiative_goal_specialist_v1($goal),
    };
    $post['run_id']=(int)$origin['run']['id'];
    $post['specialist']=$specialist;
    $post['result']=match(true){
        $specialist==='eyes'=>'check_in',
        $coordination&&isset($output['gmail_reply_draft_id'])=>'draft',
        $coordination&&($output['state']??null)==='needs_owner',$tool==='chat.check_in'=>'question',
        $tool==='topic.research',$tool==='artist.research'=>'research',
        $tool==='youtube.playback_offer'=>'playback_offer',
        $tool==='canvas.prepare'=>'canvas_proposal',
        $tool==='image.prepare'=>'image_concept',
        isset($goal['followthrough'])=>'followthrough',
        default=>'report',
    };
    $post['proof']='receipt';
    $post['delivery']='posted';
    $state=$origin['state']['state'];
    $post['reply_state']=$state!=='open'||in_array($post['result'],['question','check_in','playback_offer','canvas_proposal','image_concept'],true)
        ?$state:null;
    $post['includes_gmail']=false;
    foreach(array_keys($output['evidence_revisions']??[]) as $ref){
        if(is_string($ref)&&str_starts_with($ref,'gmail:')){
            $post['includes_gmail']=true;
            break;
        }
    }
    return $post+vp3_initiative_engagement_voice_post_v1($origin['result']['initiative_voice']??null,$messageId,true);
}

/** Expose only the outcome of the exact saved claim. Codes never grant another voice turn. */
function vp3_initiative_engagement_voice_post_v1(mixed $value,int $messageId,bool $eligible): array
{
    $base=['voice_eligible'=>$eligible,'voice_status'=>null,'voice_at'=>null,'voice_suppression_reason'=>null];
    if($value===null)return $base;
    require_once __DIR__.'/agent-initiative-voice-v1.php';
    $voice=vp3_initiative_voice_metadata_v1($value);
    if($voice===null||$voice['message_id']!==$messageId)return $base;
    return array_replace($base,['voice_status'=>$voice['status'],
        'voice_at'=>$voice['status']==='claimed'?$voice['claimed_at']:$voice['finished_at'],
        'voice_suppression_reason'=>$voice['suppression_reason']??null]);
}

function vp3_initiative_engagement_trace_v1(string $event,Throwable $error): void
{
    if(function_exists('agent_runtime_v125_trace')){
        try{agent_runtime_v125_trace($event,['error_class'=>get_class($error)]);}
        catch(Throwable $ignored){}
    }
}

function vp3_initiative_engagement_json_v1(PDO $pdo,string $column,string $key): string
{
    $sql="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$.$key')";
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$sql:"JSON_UNQUOTE($sql)";
}

function vp3_initiative_engagement_time_v1(mixed $value): string
{
    $timestamp=agent_job_utc_timestamp_v1900(is_string($value)?$value:null);
    return $timestamp>0?gmdate(DATE_ATOM,$timestamp):'';
}

function vp3_initiative_engagement_preparation_v1(PDO $pdo,int $owner,array $run,?bool &$verified=null): array
{
    $id=(int)$run['id'];$goal=json_decode((string)$run['goal'],true);
    $step=$goal['tool']??'work.prepare_private';
    $specialist=$goal['coordination_route']['specialist']??null;
    if($specialist==='casual'&&in_array($goal['primary']??null,['casual:media','casual:music','casual:youtube','casual:youtube_history'],true))$specialist='media';
    $status=$run['status']??'';
    $query=$pdo->prepare("SELECT a.id,a.status,a.attempt_count,a.started_at,a.heartbeat_at,a.completed_at,a.available_at,a.result_json,
        (SELECT r.result_json FROM agent_workflow_receipts r
         WHERE r.run_id=a.run_id AND r.action_id=a.id AND r.owner_user_id=a.owner_user_id
         AND r.attempt_no=a.attempt_count AND r.status='completed' AND r.executor='cloud' ORDER BY r.id DESC LIMIT 1) AS proof
        FROM agent_workflow_actions a WHERE a.run_id=? AND a.owner_user_id=? AND a.sequence_no=1
        AND a.capability_key='initiative.prepare' AND a.execution_target='cloud' LIMIT 1");
    $query->execute([$id,$owner]);
    $action=$query->fetch(PDO::FETCH_ASSOC)?:[];
    $eligible=max(agent_job_utc_timestamp_v1900($run['next_attempt_at']??null),
        agent_job_utc_timestamp_v1900($action['available_at']??null));
    $result=$status==='completed'&&($action['status']??null)==='completed'&&is_string($action['result_json']??null)
        &&$action['result_json']===($action['proof']??null)?json_decode($action['result_json'],true):null;
    $delivery=$result['initiative_delivery']??null;
    $reason=$result['initiative_delivery_reason']??null;
    $output=$result['initiative_tool_result']['output']??[];
    $inputSpecialists=[];
    if($step==='coordination.review'&&($output['type']??null)==='initiative_coordination'
        &&is_array($output['evidence_revisions']??null)){
        foreach(array_keys($output['evidence_revisions']) as $ref){
            if(!is_string($ref))continue;
            $input=match(strtok($ref,':')){'gmail'=>'gmail','office'=>'tasks','planning'=>'planning','media'=>'media','profile'=>'profile',default=>null};
            if($input!==null)$inputSpecialists[$input]=true;
        }
    }
    if($step==='topic.research'&&$delivery==='private'&&($output['public_topic_unavailable']??null)===true){
        $reason='public_topic_unavailable';
    }
    $stage=null;
    if($step==='canvas.prepare'&&($output['type']??null)==='initiative_canvas'
        &&in_array($output['status']??null,['proposal_ready','draft_queued'],true))$stage=$output['status'];
    if($step==='missions.prepare'&&($output['type']??null)==='initiative_specialist_review'
        &&($output['status']??null)==='approval_pending')$stage='approval_pending';
    $messageId=filter_var($result['initiative_message_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $leaseAt=$status==='executing'&&($action['status']??null)==='executing'&&
        (int)($run['current_action_id']??0)===(int)$action['id']?agent_job_utc_timestamp_v1900($run['lease_expires_at']??null):0;
    $actionStatus=$action['status']??null;
    $released=(int)($run['current_action_id']??0)===0;
    $verified=match($status){
        'executing'=>$leaseAt>0&&!empty($run['lease_token'])&&!empty($run['lease_owner'])&&!empty($action['attempt_count']),
        'approved','queued','paused'=>$released&&$actionStatus==='queued',
        'completed'=>$released&&is_array($result)&&$result!==[]&&!array_is_list($result),
        'failed','cancelled'=>$released&&$actionStatus===$status,
        default=>false,
    };
    if($status==='executing'&&$leaseAt<=time())$status='interrupted';
    $heartbeat=in_array($status,['executing','interrupted'],true)?($action['heartbeat_at']??$run['heartbeat_at']??null):null;
    $marked=match($status){
        'executing','interrupted'=>$heartbeat??$action['started_at']??null,
        'completed','failed'=>$action['completed_at']??$run['completed_at']??null,
        'approved','queued'=>$run['updated_at']??$run['created_at']??null,
        'paused'=>$run['paused_at']??null,
        'cancelled'=>$run['cancelled_at']??$action['completed_at']??null,
        default=>null,
    };
    return ['run_id'=>$id,'action_id'=>isset($action['id'])?(int)$action['id']:null,
        'step'=>is_string($step)&&preg_match('/\A[a-z_]+(?:\.[a-z_]+){1,3}\z/D',$step)?$step:'work.prepare_private',
        'specialist'=>in_array($specialist,['gmail','planning','tasks','media','canvas','curiosity','casual','connected-sites','transcripts','marketing','commerce','profile'],true)?$specialist:null,
        'status'=>in_array($status,['approved','queued','executing','interrupted','completed','failed','paused','cancelled'],true)?$status:'unknown',
        'attempt'=>isset($action['attempt_count'])?(int)$action['attempt_count']:null,
        'at'=>vp3_initiative_engagement_time_v1($marked),
        'heartbeat_at'=>vp3_initiative_engagement_time_v1($heartbeat),
        'lease_expires_at'=>$leaseAt>0?gmdate(DATE_ATOM,$leaseAt):'',
        'eligible_at'=>$status==='approved'&&$eligible>time()?gmdate(DATE_ATOM,$eligible):'',
        'phase'=>vp3_initiative_runtime_job_phase_v1($run),
        'origin_specialist'=>is_string($goal['primary']??null)
            &&preg_match('/\A(?:media-image|youtube-play-analysis|native-artist-interest):/D',$goal['primary'])?'media':null,
        'input_specialists'=>array_keys($inputSpecialists),
        'delivery'=>in_array($delivery,['private','pending','withheld','delivered'],true)?$delivery:null,
        'delivery_reason'=>in_array($reason,['private','specialist_report','source_changed','policy_changed','rule_changed',
            'duplicate','native_presentation','meeting_changed','result_unavailable','public_topic_unavailable','transcript_history','transcript_pacing','delivery_held'],true)?$reason:null,
        'opportunity_stage'=>$step==='image.prepare'&&($output['type']??null)==='initiative_image_concept'
            &&($output['status']??null)==='concept_ready'?'concept_ready':$stage,
        'message_id'=>$delivery==='delivered'&&$messageId!==false?$messageId:null];
}
