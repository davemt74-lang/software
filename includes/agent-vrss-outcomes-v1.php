<?php
declare(strict_types=1);

/**
 * Evidence projection for the existing HomeServer VRSS actions, not a second executor.
 * Source: otro e15305a0bf947a85ae9eadfecc23d634ead18426, app/services/vrss*.py.
 * refresh_all returns only queued; other mutations use the vp3.vrss.v1 envelope.
 * Conversation enabled/muted are SQLite integers. Other action flags are JSON booleans.
 */
const VP3_VRSS_OUTCOME_SCHEMA_V1='vp3.vrss.action-outcome.v1';

function vp3_vrss_outcome_require_v1(bool $valid): void
{
    if(!$valid)throw new InvalidArgumentException('VRSS outcome evidence could not be verified.');
}

/** Normalize URL identity without fetching it or exposing it in the saved result. */
function vp3_vrss_outcome_url_v1(mixed $value,string $kind='post'): ?string
{
    if(!is_string($value)||strlen($value)>($kind==='feed'?2048:400)||preg_match('/[\s\\\\<>]/u',$value)||!filter_var($value,FILTER_VALIDATE_URL))return null;
    $p=parse_url($value);if(!is_array($p))return null;
    $scheme=strtolower((string)($p['scheme']??''));$host=strtolower((string)($p['host']??''));
    if(!in_array($scheme,$kind==='feed'?['http','https']:['https'],true)||$host===''||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||
        (isset($p['port'])&&$p['port']!==($scheme==='https'?443:80))||($kind!=='feed'&&isset($p['query'])))return null;
    $path=$p['path']??'/';
    if($kind==='actor'&&!str_ends_with($path,'/vrss/v1/actor'))return null;
    if(in_array($kind,['post','comment','repost'],true)){
        $segment=['post'=>'posts','comment'=>'comments','repost'=>'reposts'][$kind];
        if(!preg_match('~/vrss/v1/'.$segment.'/[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$~D',$path))return null;
    }
    return $scheme.'://'.$host.($path===''?'/':$path).(isset($p['query'])?'?'.$p['query']:'');
}

function vp3_vrss_outcome_follow_actor_v1(mixed $url): ?string
{
    $url=vp3_vrss_outcome_url_v1($url,'feed');if($url===null||!str_starts_with($url,'https://')||str_contains($url,'?'))return null;
    $url=rtrim($url,'/');
    foreach(['/vrss/v1/profile','/vrss/v1/rss','/vrss/v1/actor'] as $suffix)if(str_ends_with($url,$suffix)){$url=substr($url,0,-strlen($suffix));break;}
    return vp3_vrss_outcome_url_v1($url.'/vrss/v1/actor','actor');
}

/** Exact persisted-field allowlist. A historical generic success flag is not outcome evidence. */
function vp3_vrss_outcome_summary_v1(mixed $summary): ?array
{
    if(!is_array($summary)||($summary['schema']??null)!==VP3_VRSS_OUTCOME_SCHEMA_V1)return null;
    $matrix=[
        'vrss.follow'=>['subscription_saved'=>[true,[]]],
        'vrss.refresh_all'=>['refresh_queued'=>[false,['queued_count']]],
        'vrss.social.follow'=>['follow_pending'=>[false,[]],'follow_accepted'=>[true,[]]],
        'vrss.social.unfollow'=>['local_relationship_saved'=>[false,[]]],
        'vrss.social.block'=>['local_relationship_saved'=>[false,[]]],
        'vrss.social.unblock'=>['local_relationship_saved'=>[false,[]]],
        'vrss.social.remove'=>['local_relationship_saved'=>[false,[]]],
        'vrss.social.like'=>['reaction_pending'=>[false,['enabled']],'reaction_delivered'=>[true,['enabled']],'reaction_local_saved'=>[false,['enabled']]],
        'vrss.social.repost'=>['repost_pending'=>[false,['enabled']],'repost_published'=>[true,['enabled']],'repost_local_saved'=>[false,['enabled']]],
        'vrss.social.conversation'=>['conversation_saved'=>[true,['enabled','muted','revision']]],
        'vrss.social.comment'=>['comment_pending_delivery'=>[false,[]],'comment_approved'=>[true,[]]],
        'vrss.social.comment_delete'=>['comment_retraction_saved'=>[false,[]]],
        'vrss.social.moderate'=>['moderation_saved'=>[true,['approved','revision']]],
    ];
    $action=$summary['action']??null;$phase=$summary['phase']??null;
    if(!is_string($action)||!is_string($phase)||!isset($matrix[$action][$phase]))return null;
    [$final,$fields]=$matrix[$action][$phase];$keys=['schema','action','phase','final_verified',...$fields];
    if(array_diff(array_keys($summary),$keys)||array_diff($keys,array_keys($summary))||($summary['final_verified']??null)!==$final)return null;
    foreach(['enabled','muted','approved'] as $key)if(in_array($key,$fields,true)&&!is_bool($summary[$key]))return null;
    if(in_array('queued_count',$fields,true)&&(!is_int($summary['queued_count'])||$summary['queued_count']<0||$summary['queued_count']>100))return null;
    if(in_array('revision',$fields,true)&&(!is_int($summary['revision'])||$summary['revision']<1))return null;
    if($phase==='repost_published'&&$summary['enabled']!==true)return null;
    return $summary;
}

/** Fixed UI labels derived from validated phase evidence; never remote strings. */
function vp3_vrss_outcome_label_v1(mixed $summary): string
{
    $s=vp3_vrss_outcome_summary_v1($summary);if($s===null)return 'Unverified';
    if($s['phase']==='refresh_queued')return 'Queued';
    if(in_array($s['phase'],['follow_pending','reaction_pending','repost_pending','comment_pending_delivery'],true))return 'Pending';
    return in_array($s['phase'],['follow_accepted','reaction_delivered','repost_published'],true)?'Verified':'Saved locally';
}

/** Strip stored payloads to this schema, optionally bound to the original saved action. */
function vp3_vrss_saved_outcome_v1(mixed $safe,?string $expectedAction=null): array
{
    $unknown=['ok'=>false,'verified'=>false,'reconcile_pending'=>false,'rolled_back'=>false];
    if(!is_array($safe))return $unknown;
    $s=vp3_vrss_outcome_summary_v1($safe['vrss_outcome']??null);
    if($s===null||($expectedAction!==null&&$s['action']!==$expectedAction)||($safe['ok']??null)!==true||($safe['verified']??null)!==true||
        ($safe['reconcile_pending']??null)!==false||($safe['rolled_back']??null)!==false)return $unknown;
    return ['ok'=>true,'verified'=>true,'reconcile_pending'=>false,'rolled_back'=>false,'vrss_outcome'=>$s];
}

/**
 * verified means the recorded phase is evidenced, including local saves or queued work.
 * final_verified is narrower. The renderer must be used instead of generic completion text.
 * Unknown/malformed results remain consumed by the canonical confirmation, never replayed.
 */
function vp3_vrss_action_outcome_v1(array $receipt,array $request): array
{
    $unknown=['ok'=>false,'verified'=>false,'reconcile_pending'=>false,'rolled_back'=>false];
    try{
        $action=$request['action']??null;$args=$request['arguments']??null;
        $argumentKeys=[
            'vrss.follow'=>['url'],'vrss.refresh_all'=>[],'vrss.social.follow'=>['url'],
            'vrss.social.unfollow'=>['actor'],'vrss.social.block'=>['actor'],'vrss.social.unblock'=>['actor'],'vrss.social.remove'=>['actor'],
            'vrss.social.like'=>['url','enabled','request_id'],'vrss.social.repost'=>['url','enabled','request_id'],
            'vrss.social.conversation'=>['url','enabled','muted','expected_revision','request_id'],
            'vrss.social.comment'=>['url','body','parent','request_id'],
            'vrss.social.comment_delete'=>['url','comment_id','request_id'],
            'vrss.social.moderate'=>['url','comment_id','decision','expected_revision','request_id'],
        ];
        vp3_vrss_outcome_require_v1(is_string($action)&&isset($argumentKeys[$action])&&is_array($args));
        vp3_vrss_outcome_require_v1(!array_diff(array_keys($args),$argumentKeys[$action])&&!array_diff($argumentKeys[$action],array_keys($args)));
        vp3_vrss_outcome_require_v1(($receipt['contract']??null)==='vp3.app.agent-action-result.v1'&&($receipt['app_key']??null)==='vp3.feed'&&($receipt['action']??null)===$action&&
            ($receipt['risk']??null)===($action==='vrss.refresh_all'?'background':'write')&&is_array($receipt['result']??null));
        foreach([$receipt,$receipt['result']] as $layer){
            vp3_vrss_outcome_require_v1(!array_key_exists('ok',$layer)||$layer['ok']===true);
            vp3_vrss_outcome_require_v1(!array_key_exists('error',$layer)||$layer['error']===''||$layer['error']===null);
        }
        $r=$receipt['result'];
        if($action!=='vrss.refresh_all'){
            vp3_vrss_outcome_require_v1(($r['ok']??null)===true&&($r['contract']??null)==='vp3.vrss.v1'&&is_string($r['viewer_id']??null)&&
                preg_match('/^urn:uuid:[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$r['viewer_id'])===1);
        }
        if(array_key_exists('replayed',$r))vp3_vrss_outcome_require_v1(is_bool($r['replayed']));
        if(isset($args['request_id']))vp3_vrss_outcome_require_v1(is_string($args['request_id'])&&preg_match('/^[A-Za-z0-9._:-]{16,80}$/D',$args['request_id'])===1);
        foreach(['enabled','muted'] as $key)if(array_key_exists($key,$args))vp3_vrss_outcome_require_v1(is_bool($args[$key]));
        if(array_key_exists('expected_revision',$args))vp3_vrss_outcome_require_v1(is_int($args['expected_revision'])&&$args['expected_revision']>=($action==='vrss.social.moderate'?1:0)&&$args['expected_revision']<PHP_INT_MAX);
        $s=['schema'=>VP3_VRSS_OUTCOME_SCHEMA_V1,'action'=>$action,'phase'=>'','final_verified'=>false];
        if($action==='vrss.refresh_all'){
            vp3_vrss_outcome_require_v1(is_int($r['queued']??null)&&$r['queued']>=0&&$r['queued']<=100);
            $s['phase']='refresh_queued';$s['queued_count']=$r['queued'];
        }elseif($action==='vrss.follow'){
            vp3_vrss_outcome_require_v1(vp3_vrss_outcome_url_v1($args['url'],'feed')!==null&&is_string($r['subscription_id']??null)&&
                preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$r['subscription_id'])===1&&is_bool($r['replayed']??null));
            $s['phase']='subscription_saved';$s['final_verified']=true;
        }elseif($action==='vrss.social.follow'){
            $actor=vp3_vrss_outcome_follow_actor_v1($args['url']);
            vp3_vrss_outcome_require_v1($actor!==null&&vp3_vrss_outcome_url_v1($r['actor']??null,'actor')===$actor&&in_array($r['state']??null,['pending','accepted'],true));
            vp3_vrss_outcome_require_v1($r['state']!=='accepted'||($r['replayed']??null)===true);
            $s['phase']=$r['state']==='accepted'?'follow_accepted':'follow_pending';$s['final_verified']=$r['state']==='accepted';
        }elseif(in_array($action,['vrss.social.unfollow','vrss.social.block','vrss.social.unblock','vrss.social.remove'],true)){
            vp3_vrss_outcome_require_v1(vp3_vrss_outcome_url_v1($args['actor'],'actor')!==null);
            vp3_vrss_outcome_require_v1($action==='vrss.social.unblock'?($r['unblocked']??null)===true:($r['state']??null)===($action==='vrss.social.block'?'blocked':'removed'));
            $s['phase']='local_relationship_saved';
        }elseif(in_array($action,['vrss.social.like','vrss.social.repost'],true)){
            $repost=$action==='vrss.social.repost';$v=$r[$repost?'repost':'reaction']??null;$object=vp3_vrss_outcome_url_v1($args['url']);
            vp3_vrss_outcome_require_v1(is_array($v)&&$object!==null&&vp3_vrss_outcome_url_v1($v['object']??null)===$object&&is_bool($r['replayed']??null));
            foreach($repost?['reposted','can_repost','can_undo','pending','published']:['liked','can_like','pending'] as $key)vp3_vrss_outcome_require_v1(is_bool($v[$key]??null));
            $flag=$repost?'reposted':'liked';$count=$repost?'reposts':'likes';
            vp3_vrss_outcome_require_v1($v[$flag]===$args['enabled']&&array_key_exists($count,$v)&&($v[$count]===null||(is_int($v[$count])&&$v[$count]>=0&&$v[$count]<=1000))&&
                in_array($v['state']??null,['pending','delivered','cancelled','idle'],true)&&
                (is_int($v['checked_at']??null)||is_float($v['checked_at']??null))&&is_finite((float)$v['checked_at'])&&$v['checked_at']>=0);
            vp3_vrss_outcome_require_v1(!$v['pending']||$v['state']==='pending');
            $s['enabled']=$args['enabled'];
            if($repost){
                vp3_vrss_outcome_require_v1(is_string($v['url']??null)&&($v['url']===''||vp3_vrss_outcome_url_v1($v['url'],'repost')!==null));
                vp3_vrss_outcome_require_v1($v['pending']===($v['state']==='pending')&&(!$v['reposted']||$v['can_undo']));
                vp3_vrss_outcome_require_v1(!$v['published']||($v['reposted']&&$v['can_repost']&&$v['can_undo']&&!$v['pending']&&$v['state']==='delivered'&&$v['url']!==''));
                $s['phase']=$v['pending']?'repost_pending':($v['published']?'repost_published':'repost_local_saved');$s['final_verified']=$v['published'];
            }else{
                vp3_vrss_outcome_require_v1((!$v['liked']||$v['can_like'])&&$v['pending']===($v['can_like']&&$v['state']==='pending'));
                $s['phase']=$v['pending']?'reaction_pending':($v['state']==='delivered'?'reaction_delivered':'reaction_local_saved');$s['final_verified']=$s['phase']==='reaction_delivered';
            }
        }elseif($action==='vrss.social.conversation'){
            $v=$r['conversation']??null;$object=vp3_vrss_outcome_url_v1($args['url']);
            vp3_vrss_outcome_require_v1(is_array($v)&&$object!==null&&vp3_vrss_outcome_url_v1($v['object']??null)===$object&&is_bool($r['replayed']??null));
            $actor=substr($object,0,strrpos($object,'/vrss/v1/posts/')).'/vrss/v1/actor';
            vp3_vrss_outcome_require_v1(vp3_vrss_outcome_url_v1($v['actor']??null,'actor')===$actor&&is_int($v['revision']??null)&&$v['revision']===$args['expected_revision']+1);
            foreach(['enabled','muted'] as $key){
                vp3_vrss_outcome_require_v1(is_int($v[$key]??null)&&in_array($v[$key],[0,1],true)&&(bool)$v[$key]===$args[$key]);
                $s[$key]=(bool)$v[$key];
            }
            vp3_vrss_outcome_require_v1(is_string($v['error']??null)&&$v['error']==='');
            $s['phase']='conversation_saved';$s['final_verified']=true;$s['revision']=$v['revision'];
        }else{
            $v=$r['comment']??null;$object=vp3_vrss_outcome_url_v1($args['url']);
            vp3_vrss_outcome_require_v1(is_array($v)&&$object!==null&&vp3_vrss_outcome_url_v1($v['object']??null)===$object&&vp3_vrss_outcome_url_v1($v['id']??null,'comment')!==null&&is_bool($r['replayed']??null));
            if($action==='vrss.social.comment'){
                vp3_vrss_outcome_require_v1(is_string($args['body'])&&$args['body']!==''&&strlen($args['body'])<=2000&&is_string($args['parent'])&&($args['parent']===''||vp3_vrss_outcome_url_v1($args['parent'],'comment')!==null)&&in_array($v['state']??null,['approved','pending_delivery'],true));
                $comment=vp3_vrss_outcome_url_v1($v['id'],'comment');
                $samePostOwner=substr($comment,0,strrpos($comment,'/vrss/v1/comments/'))===substr($object,0,strrpos($object,'/vrss/v1/posts/'));
                vp3_vrss_outcome_require_v1($samePostOwner===($v['state']==='approved'));
                $s['phase']=$v['state']==='approved'?'comment_approved':'comment_pending_delivery';$s['final_verified']=$v['state']==='approved';
            }else{
                $id=vp3_vrss_outcome_url_v1($args['comment_id'],'comment');vp3_vrss_outcome_require_v1($id!==null&&vp3_vrss_outcome_url_v1($v['id'],'comment')===$id);
                if($action==='vrss.social.comment_delete'){
                    vp3_vrss_outcome_require_v1(($v['state']??null)==='deleted');$s['phase']='comment_retraction_saved';
                }else{
                    vp3_vrss_outcome_require_v1(in_array($args['decision'],['approve','hide'],true)&&($v['state']??null)===($args['decision']==='approve'?'approved':'hidden')&&is_int($v['revision']??null)&&$v['revision']===$args['expected_revision']+1);
                    $s['phase']='moderation_saved';$s['final_verified']=true;$s['approved']=$args['decision']==='approve';$s['revision']=$v['revision'];
                }
            }
        }
        $s=vp3_vrss_outcome_summary_v1($s);vp3_vrss_outcome_require_v1($s!==null);
        return ['ok'=>true,'verified'=>true,'reconcile_pending'=>false,'rolled_back'=>false,'vrss_outcome'=>$s];
    }catch(Throwable $e){return $unknown;}
}

/** Render only validated saved evidence, including after confirmation replay. */
function vp3_vrss_action_text_v1(mixed $safe,bool $replay=false): string
{
    $safe=vp3_vrss_saved_outcome_v1($safe);$s=$safe['vrss_outcome']??null;
    if($s===null){
        return 'The '.($replay?'saved ':'').'VRSS action outcome could not be verified. Effects may already exist. This confirmation will not execute again. Check Feed before preparing another request.';
    }
    $answer=match($s['phase']){
        'subscription_saved'=>'The feed subscription was saved on HomeServer. This receipt does not confirm that all source posts were imported.',
        'refresh_queued'=>$s['queued_count']===0?'HomeServer reported no followed feeds to queue for refresh.':'HomeServer queued '.$s['queued_count'].' followed feed'.($s['queued_count']===1?'':'s').' for refresh. The refresh results are still pending.',
        'follow_pending'=>'The VRSS follow request was saved and is pending remote acceptance.',
        'follow_accepted'=>'HomeServer reported that the VRSS follow relationship was accepted.',
        'local_relationship_saved'=>match($s['action']){
            'vrss.social.unfollow'=>'The VRSS unfollow was saved locally. Remote delivery is not confirmed by this receipt.',
            'vrss.social.block'=>'The VRSS profile was blocked locally. Remote delivery is not confirmed by this receipt.',
            'vrss.social.unblock'=>'The VRSS profile was unblocked locally. This does not create a new follow relationship.',
            default=>'The VRSS follower was removed locally. Remote delivery is not confirmed by this receipt.',
        },
        'reaction_pending'=>'The '.($s['enabled']?'like':'unlike').' was saved locally and is waiting for delivery.',
        'reaction_delivered'=>'HomeServer reported delivery of the '.($s['enabled']?'like':'unlike').'.',
        'reaction_local_saved'=>'The '.($s['enabled']?'like':'unlike').' preference was saved locally. Remote delivery remains unverified.',
        'repost_pending'=>'The '.($s['enabled']?'repost':'undo-repost').' request was saved locally and is waiting for delivery.',
        'repost_published'=>'HomeServer reported the attributed repost as published after checking the original author.',
        'repost_local_saved'=>'The '.($s['enabled']?'repost':'undo-repost').' preference was saved locally. Publication or remote removal remains unverified.',
        'conversation_saved'=>'The conversation settings were saved locally: '.($s['enabled']?'followed':'not followed').', '.($s['muted']?'muted':'unmuted').', revision '.$s['revision'].'. This does not follow the author’s profile.',
        'comment_pending_delivery'=>'The comment was saved locally and is waiting for delivery. Remote publication also requires the post owner’s review.',
        'comment_approved'=>'HomeServer recorded the comment as approved on its own post.',
        'comment_retraction_saved'=>'The comment retraction was recorded locally. This receipt does not confirm removal from a remote post.',
        'moderation_saved'=>'The comment moderation decision was saved locally as '.($s['approved']?'approved':'hidden').', revision '.$s['revision'].'.',
    };
    return $replay?'Saved result; this confirmation was not executed again. '.$answer.' This is the recorded outcome, not a fresh remote check.':$answer;
}
