<?php
declare(strict_types=1);

/** Phone-only people use the canonical owner CRM; a missing email has no identity. */
function vp3_phone_caller_contacts_columns_v1(PDO $pdo): array
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $out=[];
        foreach($pdo->query('PRAGMA table_info("crm_contacts")')->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['name']]=['nullable'=>!(bool)$row['notnull'],'type'=>(string)$row['type']];
        return $out;
    }
    $q=$pdo->prepare('SELECT COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute(['crm_contacts']);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['COLUMN_NAME']]=['nullable'=>$row['IS_NULLABLE']==='YES','type'=>(string)$row['COLUMN_TYPE'],'collation'=>(string)($row['COLLATION_NAME']??'')];
    return $out;
}

function vp3_phone_caller_contacts_required_columns_v1(): array
{
    return ['id','owner_user_id','public_id','name','email','email_normalized','phone','company','source','status','lifecycle_stage','marketing_status','created_at','updated_at'];
}

function vp3_phone_caller_contacts_schema_ready_v1(PDO $pdo): bool
{
    try{
        $columns=vp3_phone_caller_contacts_columns_v1($pdo);
        foreach(vp3_phone_caller_contacts_required_columns_v1() as $name)if(!isset($columns[$name]))return false;
        return $columns['email_normalized']['nullable'];
    }catch(Throwable){return false;}
}

/** Phone contact notes extend the canonical CRM record, without a second address book. */
function vp3_phone_contact_details_schema_ready_v1(PDO $pdo): bool
{
    if(!vp3_phone_caller_contacts_schema_ready_v1($pdo))return false;
    try{$pdo->query('SELECT relationship,notes FROM crm_contacts WHERE 1=0');return true;}catch(PDOException){return false;}
}

/** Positions of structural tokens, excluding quoted SQL and comments. */
function vp3_phone_caller_contacts_sqlite_tokens_v1(string $sql): array
{
    $tokens=[];$depth=0;$length=strlen($sql);
    for($i=0;$i<$length;){
        $char=$sql[$i];
        if($char==="'"||$char==='"'||$char==='`'||$char==='['){
            $end=$char==='['?']':$char;$i++;
            while($i<$length){if($sql[$i]===$end){if($i+1<$length&&$sql[$i+1]===$end){$i+=2;continue;}$i++;break;}$i++;}
            continue;
        }
        if(substr($sql,$i,2)==='--'){$newline=strpos($sql,"\n",$i+2);$i=$newline===false?$length:$newline+1;continue;}
        if(substr($sql,$i,2)==='/*'){$end=strpos($sql,'*/',$i+2);if($end===false)throw new RuntimeException('The CRM table definition has an unfinished comment.');$i=$end+2;continue;}
        if($char==='('){$tokens[]=['value'=>'(','start'=>$i,'end'=>$i+1,'depth'=>$depth];$depth++;$i++;continue;}
        if($char===')'){$depth--;$tokens[]=['value'=>')','start'=>$i,'end'=>$i+1,'depth'=>$depth];$i++;continue;}
        if($char===','){$tokens[]=['value'=>',','start'=>$i,'end'=>$i+1,'depth'=>$depth];$i++;continue;}
        if(ctype_alpha($char)||$char==='_'){$start=$i++;while($i<$length&&(ctype_alnum($sql[$i])||$sql[$i]==='_'))$i++;$tokens[]=['value'=>strtoupper(substr($sql,$start,$i-$start)),'start'=>$start,'end'=>$i,'depth'=>$depth];continue;}
        $i++;
    }
    return $tokens;
}

/** Remove only this column's NOT NULL constraint; retain all other DDL verbatim. */
function vp3_phone_caller_contacts_sqlite_nullable_v1(string $create): string
{
    $tokens=vp3_phone_caller_contacts_sqlite_tokens_v1($create);$start=null;$parts=[];$end=null;
    foreach($tokens as $token){
        if($start===null){if($token['value']==='('&&$token['depth']===0)$start=$token['end'];continue;}
        if(($token['value']===','&&$token['depth']===1)||($token['value']===')'&&$token['depth']===0)){
            $parts[]=substr($create,$start,$token['start']-$start);$start=$token['end'];
            if($token['value']===')'){$end=$token['start'];break;}
        }
    }
    if($end===null)throw new RuntimeException('The CRM table definition could not be upgraded safely.');
    $changed=false;
    foreach($parts as &$part){
        if(!preg_match('/^\s*(?:"email_normalized"|`email_normalized`|\[email_normalized\]|email_normalized\b)/i',$part))continue;
        $columnTokens=vp3_phone_caller_contacts_sqlite_tokens_v1($part);
        foreach($columnTokens as $index=>$token){
            $next=$columnTokens[$index+1]??null;
            if($token['depth']!==0||$token['value']!=='NOT'||!$next||$next['value']!=='NULL'||$next['depth']!==0)continue;
            $from=$token['start'];$to=$next['end'];
            $prefix=substr($part,0,$from);
            if(preg_match('/\bCONSTRAINT\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]|[A-Za-z_][A-Za-z_0-9]*)\s*$/i',$prefix,$constraint,PREG_OFFSET_CAPTURE))$from=$constraint[0][1];
            if(preg_match('/^\s+ON\s+CONFLICT\s+(?:ROLLBACK|ABORT|FAIL|IGNORE|REPLACE)\b/i',substr($part,$to),$conflict))$to+=strlen($conflict[0]);
            $part=substr($part,0,$from).substr($part,$to);$changed=true;break;
        }
    }
    unset($part);
    if(!$changed)throw new RuntimeException('The CRM email constraint could not be upgraded safely.');
    $opening=strpos($create,'(');
    return substr($create,0,$opening+1).implode(',',$parts).substr($create,$end);
}

/** Setup/upgrade only. Runtime callers use readiness and never execute DDL. */
function vp3_phone_caller_contacts_ensure_schema_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Phone contact storage must be upgraded outside a transaction.');
    $columns=vp3_phone_caller_contacts_columns_v1($pdo);
    foreach(vp3_phone_caller_contacts_required_columns_v1() as $name)if(!isset($columns[$name]))throw new RuntimeException('Upgrade the canonical CRM before enabling phone contacts.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    if(!$columns['email_normalized']['nullable']){
        if(!$sqlite){
            // The canonical column is VARCHAR(190); retain its existing collation.
            $collation=(string)($columns['email_normalized']['collation']??'');
            if($collation!==''&&!preg_match('/^[A-Za-z0-9_]+$/D',$collation))throw new RuntimeException('The CRM email collation is unsupported.');
            $pdo->exec('ALTER TABLE crm_contacts MODIFY email_normalized VARCHAR(190)'.($collation!==''?' COLLATE '.$collation:'').' NULL');
        }else{
            $temporary='crm_contacts_phone_v1_migration';
            $q=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$temporary]);
            $unfinished=(bool)$q->fetchColumn();$q->closeCursor();
            if($unfinished)throw new RuntimeException('An unfinished phone contact migration needs review.');
            $original=(string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='crm_contacts'")->fetchColumn();
            $create=vp3_phone_caller_contacts_sqlite_nullable_v1($original);
            $create=preg_replace('/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(?:"crm_contacts"|`crm_contacts`|\[crm_contacts\]|crm_contacts)(?=\s*\()/i','CREATE TABLE "'.$temporary.'"',$create,1,$count);
            if($count!==1)throw new RuntimeException('The CRM table name could not be upgraded safely.');
            $definitions=$pdo->query("SELECT type,name,sql FROM sqlite_master WHERE tbl_name='crm_contacts' AND type IN ('index','trigger') AND sql IS NOT NULL ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
            $names=implode(',',array_map(static fn(string $name):string=>'"'.str_replace('"','""',$name).'"',array_keys($columns)));
            $sequence=null;
            if(preg_match('/\bAUTOINCREMENT\b/i',$original)){$q=$pdo->query("SELECT seq FROM sqlite_sequence WHERE name='crm_contacts'");$value=$q->fetchColumn();$q->closeCursor();if($value!==false)$sequence=(int)$value;}
            $foreignKeys=(int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
            $legacyAlter=(int)$pdo->query('PRAGMA legacy_alter_table')->fetchColumn();
            if($foreignKeys)$pdo->exec('PRAGMA foreign_keys=OFF');
            // Existing views and inbound references keep the canonical table name
            // while the replacement temporarily has a migration-only name.
            if(!$legacyAlter)$pdo->exec('PRAGMA legacy_alter_table=ON');
            try{
                $pdo->beginTransaction();
                $pdo->exec((string)$create);
                $pdo->exec('INSERT INTO "'.$temporary.'" ('.$names.') SELECT '.$names.' FROM crm_contacts');
                $pdo->exec('DROP TABLE crm_contacts');
                $pdo->exec('ALTER TABLE "'.$temporary.'" RENAME TO crm_contacts');
                foreach($definitions as $definition)$pdo->exec((string)$definition['sql']);
                if($sequence!==null){
                    $q=$pdo->prepare("UPDATE sqlite_sequence SET seq=MAX(seq,CAST(? AS INTEGER)) WHERE name='crm_contacts'");$q->execute([$sequence]);
                    if(!$q->rowCount()){$q=$pdo->prepare("INSERT INTO sqlite_sequence(name,seq) SELECT 'crm_contacts',CAST(? AS INTEGER) WHERE NOT EXISTS(SELECT 1 FROM sqlite_sequence WHERE name='crm_contacts')");$q->execute([$sequence]);}
                }
                $pdo->exec("UPDATE crm_contacts SET email_normalized=NULL WHERE email_normalized=''");
                if(!vp3_phone_caller_contacts_schema_ready_v1($pdo))throw new RuntimeException('Phone contact storage did not finish upgrading.');
                $pdo->commit();
            }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
            finally{if(!$legacyAlter)$pdo->exec('PRAGMA legacy_alter_table=OFF');if($foreignKeys)$pdo->exec('PRAGMA foreign_keys=ON');}
        }
    }
    $pdo->exec("UPDATE crm_contacts SET email_normalized=NULL WHERE email_normalized=''");
    if(!vp3_phone_caller_contacts_schema_ready_v1($pdo))throw new RuntimeException('Phone contact storage did not finish upgrading.');
    if(!isset(vp3_phone_caller_contacts_columns_v1($pdo)['notes']))$pdo->exec('ALTER TABLE crm_contacts ADD COLUMN notes TEXT NULL');
    if(!isset(vp3_phone_caller_contacts_columns_v1($pdo)['relationship']))$pdo->exec("ALTER TABLE crm_contacts ADD COLUMN relationship VARCHAR(80) NOT NULL DEFAULT ''");
    if(!isset(vp3_phone_caller_contacts_columns_v1($pdo)['connected_phone_id']))$pdo->exec('ALTER TABLE crm_contacts ADD COLUMN connected_phone_id CHAR(32) NULL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_call_shares_v1(owner_user_id BIGINT NOT NULL,call_id CHAR(32) NOT NULL,contact_id BIGINT NOT NULL,device_id CHAR(32) NOT NULL,exchange_id CHAR(32) NOT NULL,PRIMARY KEY(owner_user_id,exchange_id))'.($sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    if(!vp3_phone_contact_details_schema_ready_v1($pdo))throw new RuntimeException('Phone contact details did not finish upgrading.');
}
