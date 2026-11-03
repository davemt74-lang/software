<?php
declare(strict_types=1);

/** Production SQL/native authority; only external speech, media and inference are simulated. */
require_once __DIR__.'/profile-event-projection-pa4b.php';

function pa4b_reset(): void{pp_reset();}
function pa4b_owner(): array{return ps_owner();}
function pa4b_context(int $visitor=2,string $browser='b'): array{return ps_context($visitor,$browser);}
function pa4b_scope(array $extra=[]): array{return $extra+['conversation_id'=>11,'agent_id'=>7];}
/** A real native page event without a PA3 pickup turn or PA4A announcement. */
function pa4b_note(?array $ctx=null,string $type='profile_view',array $metadata=[]): array
{
    $ctx??=pa4b_context();$session=$ctx['session'];$session['visitor_user_id']=(int)($ctx['visitor']['id']??0)?:null;
    $event=profile_event_create(db(),1,$session,$type,10,7,$metadata,bin2hex(random_bytes(32)));
    if(!$event)throw new RuntimeException('Fixture event missing.');
    $attention=profile_attention_from_event(db(),$event,$session,$ctx['agent'],is_int($metadata['conversation_id']??null)?$metadata['conversation_id']:null,'SAVED_UNTRUSTED_SUMMARY');
    if(!$attention)throw new RuntimeException('Fixture attention missing.');
    $q=db()->prepare("SELECT * FROM notifications WHERE user_id=1 AND source_type='profile_event' AND source_id=?");$q->execute([(int)$event['id']]);$note=$q->fetch(PDO::FETCH_ASSOC);if(!$note)throw new RuntimeException('Fixture notification missing.');
    return ['context'=>$ctx,'event'=>$event,'attention'=>$attention,'notification'=>$note,'item'=>vp3_profile_attention_projection_pa4b(db(),vp3_profile_attention_source_pa4b(db(),pa4b_owner(),(int)$note['id']),pa4b_scope())];
}
function pa4b_message(string $text='PUBLIC_VISITOR_STATEMENT',int $visitor=2,string $browser='b'): array
{
    $started=pa3c_start(pa4b_context($visitor,$browser));$ctx=$started['context'];$cid=$started['conversation_id'];
    db()->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message) VALUES(?,'visitor',?,?)")->execute([$cid,$visitor?:null,$text]);$mid=(int)db()->lastInsertId();
    return pa4b_note($ctx,'message_received',['conversation_id'=>$cid,'message_id'=>$mid])+['public_conversation_id'=>$cid,'public_message_id'=>$mid];
}
function pa4b_input(array $note,array $extra=[]): array
{
    $item=$note['item']??$note;return pa4b_scope($extra+['notification_id'=>(int)$item['notification_id'],'attention_id'=>(int)$item['attention_id'],'state_revision'=>(int)$item['state_revision'],'wake_generation'=>(int)$item['wake_generation'],'request_id'=>bin2hex(random_bytes(16))]);
}
function pa4b_fresh(array $note): array
{
    $item=$note['item']??$note;return vp3_profile_attention_projection_pa4b(db(),vp3_profile_attention_source_pa4b(db(),pa4b_owner(),(int)$item['notification_id']),pa4b_scope());
}
function pa4b_snapshot(): array
{
    $out=[];foreach(['notifications','agent_attention_items','profile_attention_operations_pa4b','profile_attention_deliveries_pa4b','profile_events','profile_agent_messages','chat_messages','profile_event_projections_pa4b'] as $table)$out[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return $out;
}
function pa4b_readonly(callable $run): mixed
{
    $p=db();if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$p->exec('PRAGMA query_only=ON');else $p->exec('START TRANSACTION READ ONLY');
    try{return $run();}finally{if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$p->exec('PRAGMA query_only=OFF');else $p->commit();}
}
