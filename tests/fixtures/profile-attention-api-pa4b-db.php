<?php
declare(strict_types=1);
$action=$argv[1]??'';$config=$argv[2]??throw new RuntimeException('Fixture configuration required.');$input=json_decode($argv[3]??'{}',true,32,JSON_THROW_ON_ERROR);
if($action==='cleanup'){
    if(is_file($config)){$cfg=json_decode((string)file_get_contents($config),true,512,JSON_THROW_ON_ERROR);if(isset($cfg['database'])){if(!preg_match('/^vp3_profile_handoff_pa3_[a-f0-9]{12}$/D',$cfg['database']))throw new RuntimeException('Invalid isolated database.');(new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]))->exec('DROP DATABASE IF EXISTS `'.$cfg['database'].'`');}}exit;
}
putenv('VP3_PA3_CONFIG='.$config);require __DIR__.'/profile-attention-pa4b.php';
if(isset($input['now']))$GLOBALS['pa3Now']=(int)$input['now'];
if($action==='init'){pa4b_reset();$n=pa4b_message();echo json_encode(['driver'=>db()->getAttribute(PDO::ATTR_DRIVER_NAME),'item'=>$n['item'],'now'=>vp3_profile_attention_now_pa4b()],JSON_THROW_ON_ERROR);exit;}
if($action==='snapshot'){echo json_encode(pa4b_snapshot(),JSON_THROW_ON_ERROR);exit;}
if($action==='backlog'){pa4b_reset();$ctx=pa4b_context();for($i=0;$i<65;$i++)pa4b_note($ctx);echo json_encode(['now'=>vp3_profile_attention_now_pa4b()],JSON_THROW_ON_ERROR);exit;}
if($action==='wake'){echo json_encode(vp3_profile_attention_maintenance_pa4b(db()),JSON_THROW_ON_ERROR);exit;}
if($action==='revoke'){db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');exit;}
if($action==='undisclose'){db()->exec('UPDATE user_profiles SET share_visit_identity=0 WHERE user_id=2');exit;}
if($action==='unbound'){db()->exec("INSERT INTO notifications(user_id,type,title,body,target_url,source_type,source_id,is_read) VALUES(1,'profile_view','UNBOUND_NAME','UNBOUND_PUBLIC_INSTRUCTION','/private-target','','0',0)");echo (int)db()->lastInsertId();exit;}
if($action==='remove_note'){db()->prepare('DELETE FROM notifications WHERE id=?')->execute([(int)$input['notification_id']]);exit;}
if($action==='orphan'){db()->exec("INSERT INTO notifications(user_id,type,title,body,source_type,source_id,is_read) VALUES(1,'profile_view','UNVERIFIED_NAME','SECRET_ORPHAN','profile_event',999999,0)");exit;}
if($action==='no_chat'){db()->exec("DELETE FROM role_permissions WHERE permission_key='chat.access'");exit;}
if($action==='partial'){db()->exec('ALTER TABLE profile_attention_deliveries_pa4b RENAME COLUMN source_hash TO missing_source_hash');exit;}
if($action==='restore_partial'){db()->exec('ALTER TABLE profile_attention_deliveries_pa4b RENAME COLUMN missing_source_hash TO source_hash');exit;}
throw new RuntimeException('Unknown attention fixture action.');
