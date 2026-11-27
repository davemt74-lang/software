<?php
declare(strict_types=1);

const GMAIL_EMAIL_CAPTURE_PROTOCOL_V1='vp3.gmail-email-capture.v1';

function gmail_email_capture_text_v1(mixed $value,int $limit): string
{
    if (!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$limit
        ||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) throw new DomainException('The selected email content is not valid text.',422);
    return trim($value);
}

function gmail_email_capture_input_v1(array $input): array
{
    $text=gmail_email_capture_text_v1($input['text']??'',6000);
    $mode=$input['mode']??'text';$image=$input['image']??'';
    if (!in_array($mode,['text','photo'],true)||!is_string($image)) throw new DomainException('Choose text or photo analysis.',422);
    if ($mode==='text'&&($text===''||$image!=='')) throw new DomainException('Select readable email text to analyze.',422);
    if ($mode==='photo') {
        if (strlen($image)>420000||!preg_match('~\Adata:image/jpeg;base64,([A-Za-z0-9+/]+={0,2})\z~D',$image,$m)) throw new DomainException('Capture a JPEG area up to 1280 pixels.',422);
        $bytes=base64_decode($m[1],true);$info=$bytes===false?false:@getimagesizefromstring($bytes);
        if (!$info||$info[2]!==IMAGETYPE_JPEG||$info[0]<1||$info[1]<1||max($info[0],$info[1])>1280) throw new DomainException('The selected photo cannot be read.',422);
    }
    return ['mode'=>$mode,'text'=>$text,'image'=>$image];
}

function gmail_email_capture_result_v1(string $answer): array
{
    require_once __DIR__.'/agent-tool-json-v1.php';
    try {$v=vp3_tool_json_object_v1($answer,['coverage','summary','extracted_text','product_query','dates'],48000);}
    catch (InvalidArgumentException|JsonException $e) {throw new DomainException('The analysis format could not be verified. Its received response was retained.',502,$e);}
    if (!in_array($v['coverage']??null,['readable','partial','unreadable'],true)||!is_array($v['dates']??null)||!array_is_list($v['dates'])||count($v['dates'])>5) throw new DomainException('The analysis format could not be verified.',502);
    $readText=static function(mixed $value,int $limit):string{
        try {return gmail_email_capture_text_v1($value,$limit);}
        catch (DomainException $e) {throw new DomainException('The analysis format could not be verified. Its received response was retained.',502,$e);}
    };
    $out=['coverage'=>$v['coverage'],'summary'=>$readText($v['summary']??null,2000),
        'extracted_text'=>$readText($v['extracted_text']??null,6000),
        'product_query'=>$readText($v['product_query']??null,160),'dates'=>[]];
    if ($out['summary']==='') throw new DomainException('The analysis did not include a readable result.',502);
    // Search phrases must not carry source URLs or address/token-like strings.
    if (preg_match('~https?://|www\.|[?&=]|\b[^\s@]+@[^\s@]+\b~i',$out['product_query'])) $out['product_query']='';
    foreach ($v['dates'] as $date) {
        if (!is_array($date)||array_diff(array_keys($date),['title','start_local','end_local','timezone','note'])) throw new DomainException('The suggested date could not be verified.',502);
        $d=[];foreach (['title'=>190,'start_local'=>16,'end_local'=>16,'timezone'=>80,'note'=>300] as $field=>$limit) $d[$field]=$readText($date[$field]??'', $limit);
        try {new DateTimeZone($d['timezone']);} catch (Throwable) {$d['timezone']='';}
        foreach (['start_local','end_local'] as $field) {
            $at=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$d[$field],new DateTimeZone($d['timezone']?:'UTC'));
            if (!$at||$at->format('Y-m-d\TH:i')!==$d[$field]) $d[$field]='';
        }
        $out['dates'][]=$d;
    }
    if ($out['coverage']==='unreadable') {$out['dates']=[];$out['product_query']='';$out['extracted_text']='';}
    return $out;
}
