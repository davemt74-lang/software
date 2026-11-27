<?php
declare(strict_types=1);

/** Declarative checks contain data and selectors, never executable test code. */
function vp3_coding_canvas_checks_v1(mixed $checks): array
{
    $bad=static fn()=>new InvalidArgumentException('Use up to three named workflow checks with bounded actions and an expected result.',422);
    $text=static function(mixed $value,int $limit,bool $empty=false)use($bad):string{
        if(!is_string($value)||(!$empty&&trim($value)==='')||strlen($value)>$limit||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw $bad();
        return $value;
    };
    if(!is_array($checks)||!array_is_list($checks)||count($checks)>3)throw $bad();
    $result=[];$actions=0;
    foreach($checks as $check){
        if(!is_array($check)||count($check)!==4||!is_array($check['steps']??null)||!array_is_list($check['steps'])
            ||count($check['steps'])<1||count($check['steps'])>12||!is_bool($check['persist']??null))throw $bad();
        $steps=[];
        foreach($check['steps'] as $step){
            if(!is_array($step)||!in_array($step['action']??null,['click','fill','check'],true)||++$actions>24)throw $bad();
            $action=$step['action'];$selector=$text($step['selector']??null,200);
            if(count($step)!==($action==='click'?2:3))throw $bad();
            $normalized=['action'=>$action,'selector'=>$selector];
            if($action==='fill')$normalized['value']=$text($step['value']??null,300,true);
            if($action==='check'){
                if(!is_bool($step['value']??null))throw $bad();
                $normalized['value']=$step['value'];
            }
            $steps[]=$normalized;
        }
        $expect=$check['expect']??null;
        if(!is_array($expect)||!in_array($expect['kind']??null,['text','value','checked'],true))throw $bad();
        $kind=$expect['kind'];$expected=['kind'=>$kind,'selector'=>$text($expect['selector']??null,200)];
        if($kind==='checked'){
            if(count($expect)!==3||!is_bool($expect['value']??null))throw $bad();
            $expected['value']=$expect['value'];
        }else{
            if(count($expect)!==($kind==='text'?4:3))throw $bad();
            $expected['value']=$text($expect['value']??null,300,$kind==='value');
            if($kind==='text'){
                if(!in_array($expect['match']??null,['contains','equals','absent'],true))throw $bad();
                $expected['match']=$expect['match'];
            }
        }
        $result[]=['name'=>$text($check['name']??null,80),'steps'=>$steps,'expect'=>$expected,'persist'=>$check['persist']];
    }
    if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>12000)throw $bad();
    return $result;
}

function vp3_coding_canvas_checks_hash_v1(array $checks): string
{
    return hash('sha256',json_encode(vp3_coding_canvas_checks_v1($checks),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function vp3_coding_canvas_check_report_v1(mixed $value): ?array
{
    if($value===null)return null;
    if(!is_string($value)||strlen($value)>8000)throw new RuntimeException('This workflow check report could not be verified.',409);
    $report=json_decode($value,true,12,JSON_THROW_ON_ERROR);
    if(!is_array($report)||!in_array($report['status']??null,['running','passed','failed'],true)
        ||!is_string($report['request_id']??null)||!preg_match('/\A[a-f0-9-]{36}\z/D',$report['request_id'])
        ||!is_string($report['source_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$report['source_hash'])
        ||!is_string($report['checks_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$report['checks_hash']))throw new RuntimeException('This workflow check report could not be verified.',409);
    return $report;
}
