<?php
declare(strict_types=1);

/** Read-only context from native arrival records. CRM recognition is not identity proof. */
function vp3_profile_arrival_context_v1(PDO $pdo,array $item,array $source,bool $lock=false): array
{
    $owner=(int)$item['owner_user_id'];$visitor=(int)$item['visitor_user_id'];$stamp=(int)$item['created_at_ms'];
    $voice=vp3_profile_speech_voice_row_pa4a($pdo,(int)$item['voice_assignment_id'],$lock);
    $principal=hash('sha256','profile-speech|'.$owner.'|'.$visitor.'|'.$item['credential_hash']);
    if(!$voice||(int)$voice['owner_user_id']!==$owner||(int)$voice['visitor_user_id']!==$visitor
        ||!hash_equals((string)$voice['credential_hash'],(string)$item['credential_hash'])
        ||!hash_equals((string)$voice['principal_hash'],$principal)
        ||!hash_equals((string)$item['source_key'],hash('sha256','arrival|'.$voice['id'].'|'.$item['arrival_generation'])))vp3_profile_speech_error_pa4a('PROFILE_SPEECH_UNAVAILABLE',403);

    $context=['contract'=>'vp3.profile-arrival-context.v1','visit_kind'=>'unknown','recorded_at_ms'=>$stamp,'prior_arrival_at_ms'=>null,'relationship'=>'none','prior_conversation'=>null,'coverage'=>'limited'];
    $clockValid=$stamp>0&&$stamp<=vp3_profile_speech_now_pa4a()&&(int)$voice['created_at_ms']>0&&(int)$voice['created_at_ms']<=$stamp;
    if($clockValid){
        // The saved native principal and application epoch must both agree.
        // An old ID, a CRM visit count, or a DB-clock DATETIME is not a clock proof.
        $q=$pdo->prepare("SELECT * FROM profile_speech_items_pa4a WHERE owner_user_id=? AND voice_assignment_id=? AND kind='arrival' AND id<? ORDER BY id DESC LIMIT 21".($lock?vp3_profile_handoff_lock_pa3($pdo):''));
        $q->execute([$owner,(int)$voice['id'],(int)$item['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$bounded=count($rows)>20;$checked=0;
        foreach(array_slice($rows,0,20) as $prior){
            if((int)$prior['created_at_ms']<1||(int)$prior['created_at_ms']>=$stamp||(int)$prior['arrival_generation']>=(int)$item['arrival_generation']
                ||(int)$prior['visitor_user_id']!==$visitor||!hash_equals((string)$prior['credential_hash'],(string)$item['credential_hash']))continue;
            try{vp3_profile_speech_source_pa4a($pdo,$prior,$lock,false);}catch(VP3ProfileSpeechException $e){continue;}
            $context['visit_kind']='returning';$context['prior_arrival_at_ms']=(int)$prior['created_at_ms'];$checked++;break;
        }
        if(!$rows&&(int)$item['arrival_generation']===1)$context['visit_kind']='first_recorded';
        $context['coverage']=!$bounded&&($checked>0||!$rows)?'ready':'limited';

        if($context['visit_kind']==='returning'){
            $q=$pdo->prepare("SELECT * FROM profile_speech_items_pa4a WHERE owner_user_id=? AND voice_assignment_id=? AND kind='visitor_message' AND id<? AND created_at_ms<? ORDER BY id DESC LIMIT 21".($lock?vp3_profile_handoff_lock_pa3($pdo):''));
            $q->execute([$owner,(int)$voice['id'],(int)$item['id'],$stamp]);$messages=$q->fetchAll(PDO::FETCH_ASSOC);if(count($messages)>20)$context['coverage']='limited';
            foreach(array_slice($messages,0,20) as $prior){
                if((int)$prior['created_at_ms']<1||(int)$prior['visitor_user_id']!==$visitor||!hash_equals((string)$prior['credential_hash'],(string)$item['credential_hash']))continue;
                try{$evidence=vp3_profile_speech_source_pa4a($pdo,$prior,$lock,false);}catch(VP3ProfileSpeechException $e){continue;}
                $excerpt=trim(preg_replace('/\s+/u',' ',(string)$evidence['text'])??'');$excerpt=mb_substr($excerpt,0,160);if($excerpt==='')continue;
                $context['prior_conversation']=['public_conversation_id'=>(int)$prior['public_conversation_id'],'turn_id'=>(int)$prior['turn_id'],'visitor_message_id'=>(int)$prior['visitor_message_id'],'recorded_at_ms'=>(int)$prior['created_at_ms'],'excerpt'=>$excerpt,'source_revision'=>hash('sha256',$prior['binding_hash'].'|'.$prior['created_at_ms'].'|'.$evidence['text_hash'])];break;
            }
        }
    }
    if(($source['identity_disclosed']??false)===true&&$visitor>0){
        // Relationship visibility follows today's explicit identity disclosure.
        // Missing optional relationship storage does not invent a relationship.
        try{$q=$pdo->prepare('SELECT relationship_scope,status FROM user_relationships WHERE owner_user_id=? AND related_user_id=? LIMIT 2'.($lock?vp3_profile_handoff_lock_pa3($pdo):''));$q->execute([$owner,$visitor]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)===1&&$rows[0]['status']==='accepted')$context['relationship']=match($rows[0]['relationship_scope']){'collaborator'=>'collaborator','connection'=>'connection',default=>'none'};
        }catch(PDOException $e){$context['relationship']='none';}
    }
    $label=$source['label'];$chat=$item['arrival_context']==='chat';
    $text=$context['visit_kind']==='returning'?$label.($chat?' returned and is chatting with your Profile Agent.':' returned to your profile.'):$label.($chat?' is chatting with your Profile Agent.':' visited your profile.');
    if($context['visit_kind']==='first_recorded')$text.=' This is their first recorded visit from this browser.';
    if($context['relationship']==='collaborator')$text.=' They are your collaborator.';elseif($context['relationship']==='connection')$text.=' They are your connection.';
    if($context['prior_conversation'])$text.=' Earlier visitor message: “'.$context['prior_conversation']['excerpt'].'”';
    $context['source_revision']=hash('sha256',json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $context+['text'=>$text];
}
