<?php
declare(strict_types=1);

require_once __DIR__.'/agent-catch-up-chat-v1.php';

/** The reference selects an exact, still-visible revision of an already authorized source. */
function vp3_working_context_key_v1(int $owner,int $agent,int $conversationId,array $item): string
{
    return hash('sha256',json_encode([
        'working-context-v1',$owner,$agent,$conversationId,
        $item['id']??null,$item['title']??null,$item['reason']??null,$item['url']??null,
        $item['status']??null,$item['starts_at']??null,$item['source_updated_at']??null,$item['source_revision']??null,
    ],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
}

function vp3_working_context_request_v1(string $query,mixed $reference): ?string
{
    if($reference===null)return null;
    $query=str_replace('’',"'",mb_strtolower(trim(preg_replace('/\s+/u',' ',$query)??'')));
    $query=rtrim($query,"?.! ");
    if(!preg_match("/^(?:please )?(?:can you |could you )?(?:help me with (?:that|this)(?: item)?|tell me more(?: about (?:that|this))?|what should i do (?:about (?:that|this)|next)|what needs to happen next)$/D",$query)){
        return null;
    }
    return is_string($reference)&&preg_match('/^[a-f0-9]{64}$/D',$reference)?$reference:'';
}

function vp3_working_context_chat_v1(PDO $pdo,array $user,array $principal,int $conversationId,string $reference,?callable $authority=null): array
{
    $base=['handled'=>true,'actions'=>[],'sources'=>[]];
    $stale=$base+[
        'answer'=>'That update is no longer current. Ask what needs your attention to check the latest information.',
        'tool_receipt'=>vp3_tool_receipt_v1('brief.attention.read','read','no_matches'),
    ];
    if($reference===''||$conversationId<1)return $stale;
    if(!knowledge_retrieval_v162_owner_session($user,$principal)||!has_permission('chat.access',$user)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        return $base+[
            'answer'=>'This Agent scope cannot access that update.',
            'tool_receipt'=>vp3_tool_receipt_v1('brief.attention.read','read','blocked','scope_restricted'),
        ];
    }
    $agent=($principal['kind']??'')==='user_agent'?(int)($principal['agent_id']??0):0;
    try{
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],$authority);
        $brief=vp3_agent_catch_up_v1($pdo,$live,'all',$agent);
        foreach($brief['items'] as $item){
            if(hash_equals(vp3_working_context_key_v1((int)$live['id'],$agent,$conversationId,$item),$reference)){
                return array_replace($base,[
                    'answer'=>'Here’s the update I brought up. '.$item['title'].'. '.$item['reason'],
                    'tool_receipt'=>vp3_tool_receipt_v1('brief.attention.read','read','read_complete','',0,1),
                    'sources'=>[['source'=>'agent-catch-up:'.$item['id'],'title'=>$item['source_label'],'url'=>$item['url']]],
                ]);
            }
        }
        if(array_intersect($brief['coverage'],['limited','unavailable'])){
            return $base+[
                'answer'=>'I couldn’t verify that update from the sources available now.',
                'tool_receipt'=>vp3_tool_receipt_v1('brief.attention.read','read','failed','check_failed'),
            ];
        }
        return $stale;
    }catch(Throwable $e){
        $blocked=$e->getCode()===403;
        return $base+[
            'answer'=>$blocked?'This Agent scope cannot access that update.':'I couldn’t verify that update right now.',
            'tool_receipt'=>vp3_tool_receipt_v1('brief.attention.read','read',$blocked?'blocked':'failed',$blocked?'permission_denied':'check_failed'),
        ];
    }
}
