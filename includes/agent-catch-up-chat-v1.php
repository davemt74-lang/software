<?php
declare(strict_types=1);
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Explicit, read-only skill requests shared by typed and streamed Chat. */
function vp3_catch_up_chat_scope_v1(string $query): ?string
{
    $query=mb_strtolower(trim(preg_replace('/\s+/u',' ',$query)??''));
    $query=rtrim($query,"?.! ");
    $query=preg_replace('/^(?:please |(?:can|could) you )/u','',$query)??'';
    return match($query){
        'catch me up','what needs my attention','what needs my attention today'=>'all',
        'catch me up on work','what needs attention in my office','what should i focus on right now'=>'office',
        'catch me up on commerce','what needs attention in my store',
        'what needs my attention in my store','what needs attention in my business'=>'commerce',
        default=>null,
    };
}

function vp3_catch_up_chat_result_v1(array $brief): array
{
    $items=$brief['items'];
    $coverage=$brief['coverage'];
    $checked=(bool)array_intersect($coverage,['ready','limited']);
    $incomplete=(bool)array_intersect($coverage,['limited','unavailable','forbidden']);
    $blocked=$coverage!==[]&&!array_diff($coverage,['forbidden']);
    $sources=[];
    $lines=[];
    if($items){
        $lines[]='Here’s what needs your attention.';
        foreach($items as $item){
            $lines[]='• '.$item['title'].' — '.$item['reason'];
            $sources[]=['source'=>'agent-catch-up:'.$item['id'],'title'=>$item['source_label'],'url'=>$item['url']];
        }
    }else{
        $lines[]=$checked?'Nothing needs attention in the sources I could check.':
            ($blocked?'This Agent scope cannot access the sources for that brief.':'I couldn’t check the sources for that brief right now.');
    }
    if(($items||$checked)&&$incomplete)$lines[]='Some sources were limited, unavailable, or outside this Agent’s access.';
    $status=$items?'read_complete':($checked?'no_matches':($blocked?'blocked':'failed'));
    $reason=$status==='blocked'?'scope_restricted':($status==='failed'?'check_failed':'');
    return [
        'handled'=>true,
        'answer'=>implode("\n\n",$lines),
        'actions'=>[],
        'sources'=>$sources,
        'tool_receipt'=>vp3_tool_receipt_v1('brief.attention.read','read',$status,$reason,0,count($items)),
    ];
}
