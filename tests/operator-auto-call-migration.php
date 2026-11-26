<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-operator-auto-call-schema-v1.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(vp3_operator_auto_call_schema_ready_v1($db))throw new RuntimeException('Empty database incorrectly marked ready');
vp3_operator_auto_call_ensure_schema_v1($db);
$db->exec('DROP TABLE phone_operator_consent_reviews_v1');
if(vp3_operator_auto_call_schema_ready_v1($db))throw new RuntimeException('Partial migration incorrectly marked ready');
vp3_operator_auto_call_ensure_schema_v1($db);
vp3_operator_auto_call_ensure_schema_v1($db);
if(!vp3_operator_auto_call_schema_ready_v1($db))throw new RuntimeException('Incomplete Operator migration');
foreach(['phone_operator_consent_reviews_v1','phone_operator_recipient_optouts_v1','phone_operator_contact_policies_v1'] as $table){
    if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Migration created a contact preference');
}
echo "PASS fresh, partial and repeated Operator migration\n";
