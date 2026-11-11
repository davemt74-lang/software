<?php
declare(strict_types=1);

function vp3_artist_research_text_v1(mixed $value,int $limit): string
{
    if(!is_string($value))return '';
    $text=preg_replace('/[\p{Cc}\p{Cf}\s]+/u',' ',strip_tags($value));
    return mb_substr(trim($text??''),0,$limit);
}

/** A narrow, explicit intent. Ordinary discussion about an artist stays in Chat. */
function vp3_artist_research_plan_v1(string $message): ?array
{
    if(strlen($message)>640)return null;
    $message=trim(preg_replace('/\s+/u',' ',$message)??'');
    $message=preg_replace('/\A(?:please[, ]+|(?:can|could|would) you )+/iu','',$message)??$message;
    $mode='direct';$focus='overview';
    if(preg_match('/\A\/artist(?:\s+(research|delegate))?\s+(.+)\z/iu',$message,$match)){
        $mode=strtolower($match[1])==='delegate'?'delegate':'direct';
        $subject=$match[2];
    }elseif(preg_match('/\A(?:have|ask) (?:a|the) (?:music )?specialist (?:to )?research (?:the )?(?:artist|band|musician) (.+)\z/iu',$message,$match)){
        $mode='delegate';$subject=$match[1];
    }elseif(preg_match('/\A(?:research|look up) (?:the )?(?:artist|band|musician) (.+)\z/iu',$message,$match)){
        $subject=$match[1];
    }elseif(preg_match('/\A(?:find|look up|check)(?: me)? (?:the )?(latest news|new releases|upcoming shows|tour dates|official merch|related videos) (?:for|from|about) (.+)\z/iu',$message,$match)){
        $focus=match(strtolower($match[1])){
            'latest news','new releases'=>'news','upcoming shows','tour dates'=>'shows',
            'official merch'=>'merch','related videos'=>'videos',
        };
        $subject=$match[2];
        $performer=preg_match('/\A(?:the )?(?:artist|band|musician) (.+)\z/iu',$subject,$named)===1;
        if(!$performer&&in_array($focus,['news','videos'],true))return null;
        if($performer)$subject=$named[1];
    }else return null;

    $subject=rtrim(trim($subject),'?');$location='';
    if(preg_match('/\s+for (news|shows|merch|videos)[.!]?\z/iu',$subject,$match,PREG_OFFSET_CAPTURE)){
        $focus=strtolower($match[1][0]);$subject=substr($subject,0,$match[0][1]);
    }
    if(preg_match('/\s+near (.+)\z/iu',$subject,$match,PREG_OFFSET_CAPTURE)){
        $location=rtrim(trim($match[1][0]),'.!');$subject=substr($subject,0,$match[0][1]);
        if(preg_match('/\s+for (news|shows|merch|videos)\z/iu',$subject,$match,PREG_OFFSET_CAPTURE)){
            $focus=strtolower($match[1][0]);$subject=substr($subject,0,$match[0][1]);
        }
    }
    $artist=trim($subject," \t\"“”");
    return compact('mode','artist','location','focus');
}

/** Recognize explicit public-topic research without taking over ordinary Chat. */
function vp3_public_research_plan_v1(string $message): ?array
{
    $artist=vp3_artist_research_plan_v1($message);
    if($artist!==null)return $artist;
    if(strlen($message)>640)return null;
    $message=trim(preg_replace('/\s+/u',' ',$message)??'');
    $message=preg_replace('/\A(?:please[, ]+|(?:can|could|would) you )+/iu','',$message)??$message;
    if(preg_match('/\A(\/research task|research task|stop public research for task)(?:\s+(.*))?\z/iu',$message,$task)){
        $mode='task';$task_id=0;$task_title='';$topic=null;
        if(preg_match('/\A(?:#?([1-9][0-9]{0,17})|"([^"]+)"|“([^”]+)”)(?=\s|:|[.!?]?\z)(.*)\z/u',$task[2]??'',$parts)){
            $task_id=(int)$parts[1];$task_title=trim($parts[2]!==''?$parts[2]:$parts[3]);$rest=trim($parts[4]);
            $stop=strcasecmp($task[1],'stop public research for task')===0;
            if(($stop&&preg_match('/\A[.!?]?\z/D',$rest))
                ||preg_match('/\Aoff[.!?]?\z/iu',$rest))$topic='';
            elseif(!$stop&&preg_match('/\A(?::\s*|on\s+)(.+)\z/iu',$rest,$subject)){
                $candidate=trim(rtrim($subject[1],'?')," \t\"“”");
                if($candidate!=='')$topic=$candidate;
            }
        }
        return compact('mode','task_id','task_title','topic');
    }
    if(preg_match('/\A(?:have|ask) (?:a|the) specialist (?:to )?research (?:the )?topic (.+)\z/iu',$message,$match)){
        $mode='delegate';
    }elseif(preg_match('/\A(?:\/research|research (?:the )?topic) (.+)\z/iu',$message,$match)){
        $mode='direct';
    }else return null;
    $topic=trim(rtrim($match[1],'?')," \t\"“”");
    return compact('mode','topic');
}

function vp3_topic_research_subject_valid_v1(string $topic): bool
{
    $contact='[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}'
        .'|(?<![\p{L}\p{N}])(?:\+\d(?:[ .()-]*\d){8,}|(?:1[ .-]?)?(?:\(\d{3}\)|\d{3})[ .-]?\d{3}[ .-]?\d{4})(?![\p{L}\p{N}])';
    return $topic!==''&&vp3_artist_research_text_v1($topic,120)===$topic
        &&!preg_match('~https?://|www\.|[<>]|'.$contact.'~iu',$topic);
}

function vp3_topic_research_youtube_subject_v1(array $results): string
{
    $topic=$results['query']??'';
    return empty($results['suggestion_kind'])&&is_string($topic)&&vp3_topic_research_subject_valid_v1($topic)?$topic:'';
}

function vp3_public_research_plan_valid_v1(array $plan): bool
{
    if(!array_key_exists('topic',$plan))return vp3_artist_research_plan_valid_v1($plan);
    if(($plan['mode']??null)==='task'){
        $id=$plan['task_id']??null;$title=$plan['task_title']??null;
        return is_int($id)&&$id>=0&&is_string($title)&&($id>0)!==($title!=='')
            &&vp3_artist_research_text_v1($title,190)===$title&&is_string($plan['topic'])
            &&($plan['topic']===''||vp3_topic_research_subject_valid_v1($plan['topic']));
    }
    return in_array($plan['mode']??null,['direct','delegate'],true)
        &&is_string($plan['topic'])&&vp3_topic_research_subject_valid_v1($plan['topic']);
}

function vp3_public_research_source_ref_valid_v1(string $kind,string $ref): bool
{
    return (in_array($kind,['artist','topic'],true)&&preg_match('/\A'.$kind.'-interest:[a-f0-9]{64}\z/D',$ref))
        ||($kind==='artist'&&preg_match('/\Anative-artist-interest:[a-f0-9]{64}\z/D',$ref))
        ||($kind==='topic'&&(preg_match('/\Atask-research:[1-9][0-9]{0,17}\z/D',$ref)
            ||vp3_public_research_transcript_ref_v1($ref)));
}

function vp3_public_research_transcript_ref_v1(string $ref): bool
{
    return preg_match('/\Atranscript-research:[1-9][0-9]{0,17}:[a-f0-9]{64}\z/D', $ref) === 1;
}

function vp3_artist_research_plan_valid_v1(array $plan): bool
{
    return in_array($plan['mode']??null,['direct','delegate'],true)
        &&in_array($plan['focus']??null,['overview','news','shows','merch','videos'],true)
        &&is_string($plan['artist']??null)&&$plan['artist']!==''
        &&is_string($plan['location']??null)
        &&vp3_artist_research_text_v1($plan['artist'],120)===$plan['artist']
        &&vp3_artist_research_text_v1($plan['location'],80)===$plan['location']
        &&!preg_match('~https?://|[<>]~iu',$plan['artist'].' '.$plan['location']);
}
