<?php
function phone_agent_fixture_v1(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
      INSERT OR IGNORE INTO user_agents(id,owner_user_id,is_active,display_name) VALUES(7,1,1,'Longfellow'),(8,1,1,'Other'),(9,2,1,'Foreign'),(10,1,0,'Inactive');
      CREATE TABLE IF NOT EXISTS phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,phone_e164 TEXT,status TEXT,sms_enabled INTEGER,voice_enabled INTEGER);
      INSERT OR IGNORE INTO phone_cloud_numbers_v100 VALUES(1,1,7,'+15550001001','active',1,0),(2,1,8,'+15550001002','active',1,0),(3,2,9,'+15550001003','active',1,0),(5,1,10,'+15550001005','active',1,0);
      CREATE TABLE IF NOT EXISTS phone_cloud_requests_v100(id INTEGER PRIMARY KEY);
      CREATE TABLE IF NOT EXISTS phone_sms_threads_v110(id INTEGER PRIMARY KEY,owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,contact_e164 TEXT,unread_count INTEGER,last_message_at TEXT);
      INSERT OR IGNORE INTO phone_sms_threads_v110 VALUES(11,1,1,7,'+15550002001',7,'2026-10-30 12:00:00'),(12,1,2,8,'+15550002002',8,'2026-10-30 13:00:00'),(13,2,3,9,'+15550002003',9,'2026-10-30 14:00:00'),(14,1,1,8,'+15550002004',1,'2026-10-30 15:00:00'),(15,1,5,10,'+15550002005',1,'2026-10-30 16:00:00');
      CREATE TABLE IF NOT EXISTS phone_sms_messages_v110(id INTEGER PRIMARY KEY,thread_id INTEGER,owner_user_id INTEGER,direction TEXT,body TEXT,delivery_status TEXT,created_at TEXT);
      INSERT OR IGNORE INTO phone_sms_messages_v110 VALUES(101,11,1,'inbound','Fixture pizza pickup at six.','received','2026-10-30 12:00:00'),(102,12,1,'inbound','SECRET other Agent SMS','received','2026-10-30 13:00:00'),(103,13,2,'inbound','SECRET foreign SMS','received','2026-10-30 14:00:00');
      CREATE TABLE IF NOT EXISTS phone_sms_contacts_v110(id INTEGER PRIMARY KEY);
      CREATE TABLE IF NOT EXISTS phone_sms_outbox_v120(id INTEGER PRIMARY KEY)");
}
