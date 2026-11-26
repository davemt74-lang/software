<?php
declare(strict_types=1);

/** Call progress trusts a server-signed review, never a model's accepted flag. No key is created by a read. */
function transcription_review_proof_v1(int $session,string $sourceHash,string $app,string $section,array $item):string
{
    if($session<1||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash)||($item['review_state']??'')!=='accepted'
        ||!is_string($item['reviewed_at']??null)||strtotime($item['reviewed_at'])===false||!function_exists('ai_master_key'))return '';
    try{$key=ai_master_key(false);}catch(Throwable){return '';}
    if(!is_string($key)||strlen($key)!==32)return '';
    $pages=[];foreach((array)($item['evidence_refs']??[]) as $ref)if(is_array($ref)&&is_int($ref['page']??null)&&$ref['page']>0)$pages[$ref['page']]=$ref['page'];ksort($pages);
    return hash_hmac('sha256',json_encode(['transcription-owner-review-v1',$session,$sourceHash,$app,$section,
        (string)($item['item_id']??''),(string)($item['question']??''),(string)($item['answer']??''),array_values($pages),$item['reviewed_at']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$key);
}
function transcription_review_proven_v1(int $session,string $sourceHash,string $app,string $section,array $item):bool
{
    $proof=$item['owner_review_proof_v1']??null;
    if(!is_string($proof)||!preg_match('/^[a-f0-9]{64}$/D',$proof))return false;
    $expected=transcription_review_proof_v1($session,$sourceHash,$app,$section,$item);
    return $expected!==''&&hash_equals($expected,$proof);
}
