<?php
declare(strict_types=1);

const VP3_TRANSCRIPTION_DEEPER_CHAT_V307 = 'transcription-deeper-chat-v307-20260907';

function transcription_deeper_chat_signals_v307(array $master): array
{
    $modules=transcription_app_modules_v306(is_array($master['analysis']??null)?$master['analysis']:[],$master);
    $signals=[];

    foreach ((array)($modules['knowledge']['result']['items']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
        $state=(string)($item['knowledge_state']??'');
        if (!in_array($state,['conflicting','updates_existing','more_specific'],true)) continue;
        $signals[]=['priority'=>$state==='conflicting'?165:132,'kind'=>'knowledge_'.$state,'text'=>'Knowledge '.str_replace('_',' ',$state).': '.(string)($item['value']??''),'item_id'=>(string)($item['item_id']??'')];
    }

    foreach ((array)($modules['crm']['result']['buying_signals']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
        $signals[]=['priority'=>(string)($item['strength']??'')==='high'?160:145,'kind'=>'crm_buying_signal','text'=>'CRM buying signal'.(trim((string)($item['contact']??''))!==''?' for '.(string)$item['contact']:'').': '.(string)($item['signal']??''),'item_id'=>(string)($item['item_id']??'')];
    }
    foreach ((array)($modules['crm']['result']['objections']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
        $signals[]=['priority'=>154,'kind'=>'crm_objection','text'=>'CRM objection'.(trim((string)($item['contact']??''))!==''?' for '.(string)$item['contact']:'').': '.(string)($item['objection']??''),'item_id'=>(string)($item['item_id']??'')];
    }

    foreach ((array)($modules['opportunities']['result']['items']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
        $score=max(0,(int)($item['opportunity_score']??0));
        if ($score<80) continue;
        $signals[]=['priority'=>140+min(20,(int)floor(($score-80)/2)),'kind'=>'ranked_opportunity','text'=>'Opportunity '.$score.'/100: '.(string)($item['opportunity']??'').' Validation: '.(string)($item['validation_step']??''),'item_id'=>(string)($item['item_id']??'')];
    }

    foreach ((array)($modules['research_brief']['result']['claims']??[]) as $item) {
        if (!is_array($item) || (string)($item['review_state']??'')!=='accepted') continue;
        $verification=(string)($item['verification']??'');
        if (!in_array($verification,['unsupported','mixed'],true)) continue;
        $signals[]=['priority'=>$verification==='unsupported'?158:136,'kind'=>'claim_'.$verification,'text'=>'Research claim '.str_replace('_',' ',$verification).': '.(string)($item['claim']??''),'item_id'=>(string)($item['item_id']??'')];
    }

    $dedup=[];
    foreach ($signals as $signal) {
        $text=transcription_app_clean_v300((string)($signal['text']??''),700);
        if ($text==='') continue;
        $key=(string)($signal['kind']??'signal').'|'.(string)($signal['item_id']??'').'|'.$text;
        $signal['text']=$text;$signal['hash']=hash('sha256',$key);
        if (!isset($dedup[$signal['hash']]) || (int)$signal['priority']>(int)$dedup[$signal['hash']]['priority']) $dedup[$signal['hash']]=$signal;
    }
    $signals=array_values($dedup);
    usort($signals,static fn(array $a,array $b):int=>(int)$b['priority']<=>(int)$a['priority']);
    return array_slice($signals,0,4);
}

function transcription_deeper_chat_notify_v307(array $user,array $session,array $master): array
{
    if (!function_exists('agent_chat_v101_append_ecosystem_message') || !has_permission('chat.access',$user)) return ['sent'=>false,'reason'=>'chat_unavailable'];
    $signals=transcription_deeper_chat_signals_v307($master);
    if (!$signals) return ['sent'=>false,'reason'=>'no_high_value_signals'];
    $uid=max(0,(int)($user['id']??0));$sid=max(0,(int)($session['id']??0));
    if ($uid<1 || $sid<1) return ['sent'=>false,'reason'=>'invalid_owner'];
    $fingerprint=hash('sha256',implode('|',array_map(static fn(array $row):string=>(string)$row['hash'],$signals)));
    $subject='transcription-deep-notice:'.$sid;
    if (function_exists('agent_brain_v122_memory')) {
        $prior=agent_brain_v122_memory($user,'transcription_deep_notice',$subject);
        if (is_array($prior)) {
            $meta=$prior['metadata']??[];
            if (is_array($meta) && hash_equals((string)($meta['fingerprint']??''),$fingerprint)) return ['sent'=>false,'reason'=>'already_sent','fingerprint'=>$fingerprint];
        }
    }

    $title=trim((string)($session['title']??''))?:('Transcript #'.$sid);
    $message='Deeper transcription intelligence from “'.$title.'” found '.count($signals).' item'.(count($signals)===1?'':'s').' worth your attention:';
    foreach ($signals as $signal) $message.="\n• ".(string)$signal['text'];
    $message.="\nI kept these tied to reviewed transcript intelligence; open Artist Listening to inspect the evidence or take an action.";
    $conversationId=agent_chat_v101_append_ecosystem_message($user,$message,[
        'source'=>'transcription_deeper_v307','session_id'=>$sid,'signal_hashes'=>array_column($signals,'hash'),
        'target_url'=>url('/artist-listening.php?session='.$sid),'deep_version'=>307,
    ]);
    if ($conversationId<1) return ['sent'=>false,'reason'=>'append_failed'];

    if (function_exists('agent_brain_v122_upsert_system_memory')) {
        agent_brain_v122_upsert_system_memory($user,'transcription_deep_notice',$subject,mb_strimwidth($message,0,5800,'…'),[
            'source'=>'transcription_deeper_v307','session_id'=>$sid,'fingerprint'=>$fingerprint,'conversation_id'=>$conversationId,
            'signal_hashes'=>array_column($signals,'hash'),'sent_at'=>gmdate('c'),
        ],0.99);
    }
    return ['sent'=>true,'conversation_id'=>$conversationId,'fingerprint'=>$fingerprint,'signals'=>count($signals)];
}
