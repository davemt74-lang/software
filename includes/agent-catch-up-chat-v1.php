<?php
declare(strict_types=1);
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Recognize whole owner-priority requests before model or mutation routing. */
function vp3_catch_up_chat_request_v1(string $query): ?array
{
    $query=str_replace('’',"'",mb_strtolower(trim(preg_replace('/\s+/u',' ',$query)??'')));
    $query=rtrim($query,"?.! ");
    $query=preg_replace('/^(?:hey[ ,]+)?(?:please[ ,]+)?(?:(?:can|could|would) you (?:please )?)?/u','',$query)??'';
    $scope=match($query){
        'catch me up','what needs my attention','what needs my attention today',
        'bring me up to speed','give me a quick catch-up','give me a quick catch up',
        'what are my priorities right now','what should be top of mind for me',"what's on my plate right now"=>'all',
        'catch me up on work','what needs attention in my office','what should i focus on right now'=>'office',
        'catch me up on commerce','what needs attention in my store',
        'what needs my attention in my store','what needs attention in my business'=>'commerce',
        default=>null,
    };
    if($scope!==null)return ['scope'=>$scope,'window'=>'current'];

    // The current projection has no complete change log or list-wide meeting cutoff.
    // Keep the time qualifier for the reply rather than treating it as a filter.
    $window=match(true){
        in_array($query,['what did i miss','did i miss anything',"what's changed"],true)=>'unspecified',
        preg_match('/^(?:what did i miss|what have i missed|tell me what i missed|did i miss anything|anything i missed) (?:while i was (?:away|gone|offline)|since i last checked)$/D',$query)===1=>'away',
        preg_match("/^(?:has anything changed|did anything change|what (?:has )?changed|what's changed|tell me (?:what changed|if anything changed)) since (?:this morning|earlier today|yesterday|i last checked)$/D",$query)===1=>'changes',
        preg_match('/^(?:what needs my attention|what should i (?:focus on|prioriti[sz]e|take care of)|tell me what needs my attention) before (?:my|the) next meeting$/D',$query)===1=>'meeting',
        default=>null,
    };
    return $window===null?null:['scope'=>'all','window'=>$window];
}

function vp3_catch_up_chat_scope_v1(string $query): ?string
{
    return vp3_catch_up_chat_request_v1($query)['scope']??null;
}

function vp3_catch_up_chat_result_v1(array $brief,string $window='current'): array
{
    $items=$brief['items'];
    $coverage=$brief['coverage'];
    $checked=(bool)array_intersect($coverage,['ready','limited']);
    $incomplete=(bool)array_intersect($coverage,['limited','unavailable','forbidden']);
    $blocked=$coverage!==[]&&!array_diff($coverage,['forbidden']);
    $sources=[];
    $lines=[];
    $scopeNote=match($window){
        'unspecified'=>'I don’t have a verified history of recent changes.',
        'away'=>'I don’t have a verified record of what changed while you were away.',
        'changes'=>'I don’t have a verified comparison for that time window.',
        'meeting'=>'I haven’t checked what’s due by your next meeting’s start time.',
        default=>'',
    };
    if($window==='meeting'&&array_filter($items,static fn(array $item):bool=>str_starts_with($item['id'],'meeting-prep:'))){
        $scopeNote='I found a follow-up for an upcoming meeting. '.$scopeNote;
    }
    if($scopeNote!==''&&($items||$checked))$lines[]=$scopeNote;
    if($items){
        $lines[]=$scopeNote===''?'Here’s what needs your attention.':'Here’s what needs your attention now.';
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
