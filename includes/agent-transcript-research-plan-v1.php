<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-json-v1.php';
require_once __DIR__.'/agent-public-research-plan-v1.php';

/** Derive a public query in private inference. Never use saved prose as a search query. */
function vp3_initiative_transcript_research_plan_v1(PDO $pdo,array $scope,array $source,?callable $model): ?array
{
    $research=$source['research']??[];
    $question=$research['question']??null;
    $next=$research['next_step']??'';
    if(($source['kind']??null)!=='transcript_research'||!is_string($question)||trim($question)===''||!is_string($next)){
        throw new RuntimeException('The saved transcript research item changed.',409);
    }
    $protocol='vp3.transcript-research-plan.v1';
    $prompt=json_encode(['question'=>mb_substr($question,0,900),'suggested_check'=>mb_substr($next,0,900)],
        JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);
    $answer=$model!==null?$model($prompt,$protocol):vp3_initiative_model_v1(
        $pdo,$scope['user'],$prompt,$protocol,$scope['agent_id'],false,null,['background'=>true]);
    try{
        $plan=vp3_tool_json_object_v1($answer,['decision','topic'],800);
    }catch(InvalidArgumentException|JsonException){
        throw new RuntimeException('Public transcript research planning is unavailable.',503);
    }
    $topic=$plan['topic']??null;
    $decision=$plan['decision']??null;
    if($decision==='skip'&&$topic==='')return null;
    if($decision!=='research'||!is_string($topic)||!vp3_topic_research_subject_valid_v1($topic)
        ||!preg_match('/\A[\p{L}\p{N}][\p{L}\p{N}\p{M} .,&()\x{2019}\x{2013}\x{2014}\x27-]*\z/uD',$topic)
        ||preg_match('/\b(?:private|confidential|proprietary|unpublished|password|passcode|secret key|api key|account number|access token)\b|\d{7,}/iu',$topic)){
        throw new RuntimeException('Public transcript research planning is unavailable.',503);
    }
    $terms=static fn(string $text):string=>' '.trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($text))??'').' ';
    $topicTerms=$terms($topic);
    if(str_contains($terms($question),$topicTerms)||str_contains($terms($next),$topicTerms)){
        throw new RuntimeException('Public transcript research planning is unavailable.',503);
    }
    preg_match_all('/\b(?i:private|confidential|internal|proprietary|unpublished)\h+((?:\p{Lu}[\p{L}\p{M}\p{N}-]*(?:\h+|\b)){1,4})/u',
        $question."\n".$next,$protected);
    foreach($protected[1] as $name){
        if(str_contains($topicTerms,$terms($name))){
            throw new RuntimeException('Public transcript research planning is unavailable.',503);
        }
    }
    return ['topic'=>$topic];
}
