<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/profile-visitor-speech-pa4a.php';

// Execute the production presenter with the real persisted native/speech source.
// Provider transport remains simulated by the shared speech fixture.
$source=file_get_contents(dirname(__DIR__).'/api/chat-notifications-brain-v240.php');
$start=strpos($source,'function chat_notifications_v240_json');
$end=strpos($source,"\n\$user = current_user();");
if($start===false||$end===false)throw new RuntimeException('Notification API service boundary missing.');
eval(substr($source,$start,$end-$start));
$checks=0;
function psn_check(bool $ok,string $message): void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
ps_reset();$p=db();$ctx=ps_context(2);$item=ps_page($ctx);
$event=profile_event_create($p,1,$ctx['session'],'profile_view',10,7,[],'speech-governed-arrival');
profile_attention_from_event($p,$event,$ctx['session'],$ctx['agent']);
vp3_profile_speech_link_event_pa4a($p,$item,(int)$event['id']);
$q=$p->prepare("SELECT * FROM notifications WHERE user_id=1 AND source_type='profile_event' AND source_id=?");
$q->execute([(int)$event['id']]);$note=$q->fetch(PDO::FETCH_ASSOC);
psn_check(is_array($note),'Arrival retains a canonical notification.');
psn_check(vp3_profile_speech_notification_owned_pa4a($p,ps_owner(),$note),'Exact saved arrival owns narration.');
$beforeMessages=pa3_count('chat_messages');$beforeAttention=$p->query('SELECT * FROM agent_attention_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$present=chat_notifications_v240_present_attention($p,ps_owner(),['notification_id'=>(int)$note['id']]);
psn_check(empty($present['handled'])&&$present['presentation_owner']==='profile_speech_pa4a'&&$present['voice_allowed']===false,'Older presenter cannot duplicate governed arrival speech.');
$q->execute([(int)$event['id']]);psn_check($q->fetch(PDO::FETCH_ASSOC)===$note,'Presentation ownership does not mark or modify the notification.');
psn_check($p->query('SELECT * FROM agent_attention_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$beforeAttention,'Presentation ownership preserves attention state.');
psn_check(pa3_count('chat_messages')===$beforeMessages&&pa3_count('profile_agent_handoff_turns_pa3')===0,'No duplicate Chat turn or fake pickup is created.');
psn_check(count($GLOBALS['psProviderCalls'])===0,'Notification presentation never synthesizes audio.');
psn_check(!vp3_profile_speech_notification_owned_pa4a($p,['id'=>3],$note),'Foreign owner cannot claim arrival presentation.');
psn_check(!vp3_profile_speech_notification_owned_pa4a($p,ps_owner(),array_replace($note,['source_type'=>'profile_message'])),'Source type substitution cannot claim narration.');
$historic=profile_event_create($p,1,$ctx['session'],'profile_view',10,7,[],'historical-ungoverned-visit');
profile_attention_from_event($p,$historic,$ctx['session'],$ctx['agent']);
$q->execute([(int)$historic['id']]);$old=$q->fetch(PDO::FETCH_ASSOC);
psn_check(!vp3_profile_speech_notification_owned_pa4a($p,ps_owner(),$old),'Unlinked historical notifications retain their existing presenter.');
$p->prepare("UPDATE profile_events SET event_type='message_received' WHERE id=?")->execute([(int)$event['id']]);
psn_check(!vp3_profile_speech_notification_owned_pa4a($p,ps_owner(),$note),'Changed event lineage cannot claim arrival narration.');
echo 'PROFILE_SPEECH_NOTIFICATIONS_PA4A=PASS ('.$checks.' checks; '.$p->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
