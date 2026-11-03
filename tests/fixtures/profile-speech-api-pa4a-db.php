<?php
declare(strict_types=1);
$action=$argv[1]??'';$config=$argv[2]??throw new RuntimeException('Fixture configuration required.');$input=json_decode($argv[3]??'{}',true,32,JSON_THROW_ON_ERROR);
if($action==='cleanup'){
    if(is_file($config)){$cfg=json_decode((string)file_get_contents($config),true,512,JSON_THROW_ON_ERROR);if(isset($cfg['database'])){
        if(!preg_match('/^vp3_profile_handoff_pa3_[a-f0-9]{12}$/D',$cfg['database']))throw new RuntimeException('Invalid isolated database.');
        (new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]))->exec('DROP DATABASE IF EXISTS `'.$cfg['database'].'`');
    }}exit;
}
putenv('VP3_PA3_CONFIG='.$config);require __DIR__.'/profile-visitor-speech-pa4a.php';
if($action==='init'){
    ps_reset();$ctx=ps_context(2,'b');$item=ps_page($ctx);echo json_encode(['driver'=>db()->getAttribute(PDO::ATTR_DRIVER_NAME),'item_id'=>(int)$item['id'],'now'=>vp3_profile_handoff_now_pa3()],JSON_THROW_ON_ERROR);exit;
}
if(isset($input['now']))$GLOBALS['pa3Now']=(int)$input['now'];
if($action==='state'){
    $out=[];foreach(['profile_visitor_voices_pa4a','profile_speech_items_pa4a','profile_speech_attempts_pa4a','profile_agent_conversations','profile_agent_messages','profile_agent_handoff_turns_pa3','profile_calls_pa3c','profile_call_slots_pa3c','chat_messages'] as $table)$out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
if($action==='presence'){
    $q=db()->prepare('SELECT presence_sequence FROM profile_agent_handoff_presence_pa3 WHERE owner_user_id=1 AND tab_id=?');$q->execute([str_repeat('a',32)]);$sequence=(int)$q->fetchColumn()+1;
    vp3_profile_handoff_presence_pa3(db(),pa3_owner(),['tab_id'=>str_repeat('a',32),'chat_conversation_id'=>11,'chat_agent_id'=>7,'visible'=>true,'presence_sequence'=>$sequence]);exit;
}
if($action==='message'){
    pa3_age();$out=ps_message((string)($input['text']??'Saved visitor message.'),(string)($input['mode']??'agent'),ps_context((int)($input['visitor']??2),(string)($input['browser']??'b')),(int)($input['conversation_id']??0));
    if(!$out['item'])throw new RuntimeException('Speech admission hook did not persist its source.');echo json_encode(['item_id'=>(int)$out['item']['id'],'conversation_id'=>(int)$out['item']['public_conversation_id']],JSON_THROW_ON_ERROR);exit;
}
if($action==='foreign_thread'){
    pa3_login(3);$_COOKIE=['sf_profile_visitor'=>str_repeat('f',64),'sf_profile_conversation'=>str_repeat('f',64)];$ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'other-owner'),current_user());$out=vp3_profile_agent_public_start_v1(db(),$ctx);echo (int)$out['conversation']['conversation_id'];exit;
}
if($action==='restore'){
    db()->exec('UPDATE user_profiles SET profile_agent_enabled=1 WHERE user_id=1');db()->exec('UPDATE user_agents SET is_active=1 WHERE id=7');db()->exec('DELETE FROM user_blocks');db()->exec('UPDATE team_user_presence SET presence_mode=\'online\',social_chat_enabled=1,sound_enabled=1,agent_voice_enabled=1');exit;
}
if($action==='expire_audio'){
    $q=db()->prepare('UPDATE profile_speech_attempts_pa4a SET audio_expires_at_ms=? WHERE id=?');$q->execute([vp3_profile_handoff_now_pa3()-1,(int)$input['attempt_id']]);exit;
}
if($action==='age_attempt'){
    $q=db()->prepare('UPDATE profile_speech_attempts_pa4a SET lease_until_ms=? WHERE id=?');$q->execute([vp3_profile_handoff_now_pa3()-1,(int)$input['attempt_id']]);vp3_profile_speech_maintenance_pa4a(db());exit;
}
throw new RuntimeException('Unsupported speech HTTP fixture command.');
