<?php
declare(strict_types=1);

/**
 * Owner-private callback number and requested Agent outreach preferences.
 * This is not an assigned Twilio line, CRM contact, verification receipt,
 * scheduling authority or permission to place a call.
 */
final class VP3OperatorOwnerPhoneV1
{
    public static function ready(PDO $pdo):bool
    {
        try{
            $pdo->query('SELECT owner_user_id,phone_e164,phone_revision,revision,updates_requested,hello_requested,update_scope,timezone,window_start,window_end,max_calls_per_day,min_gap_minutes,max_duration_seconds,consent_epoch,updated_epoch FROM phone_operator_owner_preferences_v1 WHERE 1=0');
            return true;
        }catch(PDOException){return false;}
    }

    public static function install(PDO $pdo):void
    {
        if($pdo->inTransaction())throw new RuntimeException('Install owner phone preferences outside a transaction.');
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['mysql','sqlite'],true))throw new RuntimeException('Unsupported database.');
        $ownerType=$driver==='mysql'?'INT UNSIGNED':'INTEGER';
        $suffix=$driver==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $pdo->exec("CREATE TABLE IF NOT EXISTS phone_operator_owner_preferences_v1(
            owner_user_id $ownerType PRIMARY KEY,
            phone_e164 VARCHAR(20) NOT NULL DEFAULT '',
            phone_revision BIGINT NOT NULL,
            revision BIGINT NOT NULL,
            updates_requested SMALLINT NOT NULL DEFAULT 0,
            hello_requested SMALLINT NOT NULL DEFAULT 0,
            update_scope VARCHAR(16) NOT NULL DEFAULT 'important',
            timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
            window_start CHAR(5) NOT NULL DEFAULT '09:00',
            window_end CHAR(5) NOT NULL DEFAULT '17:00',
            max_calls_per_day INTEGER NOT NULL DEFAULT 1,
            min_gap_minutes INTEGER NOT NULL DEFAULT 240,
            max_duration_seconds INTEGER NOT NULL DEFAULT 180,
            consent_epoch BIGINT NOT NULL DEFAULT 0,
            updated_epoch BIGINT NOT NULL,
            FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        )".$suffix);
        if(!self::ready($pdo))throw new RuntimeException('Owner phone preferences could not be installed.');
    }

    private static function check(bool $ok,string $message='Your account authorization could not be verified.'):void
    {
        if(!$ok)throw new DomainException($message);
    }

    /** No request-selected account; recheck the exact owner session and live password fingerprint. */
    private static function owner(PDO $pdo,array $user,bool $lock=false):int
    {
        $owner=$user['id']??null;
        self::check(is_int($owner)&&$owner>0&&(int)($_SESSION['user_id']??0)===$owner
            &&function_exists('has_permission')&&has_permission('account.access',$user)
            &&(!function_exists('vp3_participant_scope_v1')||vp3_participant_scope_v1()===null));
        $q=$pdo->prepare('SELECT id,is_active,password_hash FROM users WHERE id=?'
            .($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $fingerprint=$_SESSION['auth_password_fingerprint']??null;
        self::check($row!==false&&(int)$row['is_active']===1&&is_string($fingerprint)&&$fingerprint!==''
            &&hash_equals(hash('sha256',$owner.':'.$row['password_hash']),$fingerprint));
        return $owner;
    }

    public static function defaults():array
    {
        return ['phone_e164'=>'','phone_revision'=>0,'revision'=>0,'updates_requested'=>false,'hello_requested'=>false,
            'update_scope'=>'important','timezone'=>'UTC','window_start'=>'09:00','window_end'=>'17:00',
            'max_calls_per_day'=>1,'min_gap_minutes'=>240,'max_duration_seconds'=>180,
            'consent_epoch'=>0,'updated_epoch'=>0,'verified'=>false,'execution_enabled'=>false];
    }

    private static function row(PDO $pdo,int $owner,bool $lock=false):?array
    {
        $q=$pdo->prepare('SELECT * FROM phone_operator_owner_preferences_v1 WHERE owner_user_id=?'
            .($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$owner]);$r=$q->fetch(PDO::FETCH_ASSOC);
        return $r===false?null:$r;
    }

    private static function view(?array $row):array
    {
        $out=self::defaults();
        if($row!==null){
            foreach($out as $key=>$default){
                if(!array_key_exists($key,$row))continue;
                $out[$key]=is_bool($default)?(bool)$row[$key]:(is_int($default)?(int)$row[$key]:(string)$row[$key]);
            }
        }
        // No caller or stored flag can certify the number or authorize execution here.
        $out['verified']=false;$out['execution_enabled']=false;
        return $out;
    }

    /** The full number is returned only to authenticated private owner settings. */
    public static function read(PDO $pdo,array $user,bool $lock=false):array
    {
        $owner=self::owner($pdo,$user,$lock);
        self::check(self::ready($pdo),'Install the Account phone preferences database upgrade.');
        return self::view(self::row($pdo,$owner,$lock));
    }

    public static function normalize(mixed $value):string
    {
        self::check(is_string($value)&&strlen($value)<=64,'Enter a valid international phone number.');
        $s=trim($value);
        if($s==='')return '';
        // Require an explicit international prefix; never guess a country.
        self::check(preg_match('/\A\+[0-9 ().-]+\z/D',$s)===1,'Use an international number beginning with + and the country code.');
        $digits=preg_replace('/[^0-9]/','',$s);
        self::check(is_string($digits)&&preg_match('/\A[1-9][0-9]{7,14}\z/D',$digits)===1,'Enter a valid international phone number.');
        return '+'.$digits;
    }

    private static function revision(mixed $value):int
    {
        self::check(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,14})\z/D',$value)===1,'Refresh Account settings before saving.');
        return (int)$value;
    }

    private static function limit(mixed $value,int $min,int $max):int
    {
        self::check(is_string($value)&&preg_match('/\A[1-9][0-9]{0,5}\z/D',$value)===1,'Choose permitted calling limits.');
        $n=(int)$value;self::check($n>=$min&&$n<=$max,'Choose permitted calling limits.');
        return $n;
    }

    private static function checkbox(array $input,string $key):bool
    {
        if(!array_key_exists($key,$input))return false;
        self::check($input[$key]==='1','Invalid call preference.');
        return true;
    }

    private static function transaction(PDO $pdo,array $user,int $expected,int $now,callable $apply):array
    {
        self::check($now>0&&$now<4102444800&&!$pdo->inTransaction(),'Account settings are temporarily unavailable.');
        self::check(self::ready($pdo),'Install the Account phone preferences database upgrade.');
        $pdo->beginTransaction();
        try{
            // MySQL serializes first save and subsequent changes on the live user row.
            // SQLite obtains the write lock before reading the preference revision.
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
                $id=$user['id']??0;
                $q=$pdo->prepare('UPDATE users SET id=id WHERE id=?');$q->execute([is_int($id)?$id:0]);
            }
            $owner=self::owner($pdo,$user,true);
            $before=self::view(self::row($pdo,$owner,true));
            self::check($before['revision']===$expected,'Account settings changed. Refresh before saving.');
            $after=$apply($before);
            if($after===$before){$pdo->commit();return $before;}
            $after['revision']=$expected+1;$after['updated_epoch']=$now;
            $columns=['phone_e164','phone_revision','revision','updates_requested','hello_requested','update_scope',
                'timezone','window_start','window_end','max_calls_per_day','min_gap_minutes','max_duration_seconds','consent_epoch','updated_epoch'];
            $values=array_map(static fn(string $k)=>is_bool($after[$k])?(int)$after[$k]:$after[$k],$columns);
            if($expected===0){
                $pdo->prepare('INSERT INTO phone_operator_owner_preferences_v1(owner_user_id,'.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns)+1,'?')).')')
                    ->execute(array_merge([$owner],$values));
            }else{
                $q=$pdo->prepare('UPDATE phone_operator_owner_preferences_v1 SET '.implode('=?,',$columns).'=? WHERE owner_user_id=? AND revision=?');
                $q->execute(array_merge($values,[$owner,$expected]));
                self::check($q->rowCount()===1,'Account settings changed. Refresh before saving.');
            }
            $pdo->commit();
            return $after;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    public static function saveNumber(PDO $pdo,array $user,array $input,int $now):array
    {
        $phone=self::normalize($input['phone_e164']??null);
        $expected=self::revision($input['expected_revision']??null);
        return self::transaction($pdo,$user,$expected,$now,static function(array $row)use($phone):array{
            if($phone===$row['phone_e164'])return $row;
            $row['phone_e164']=$phone;
            $row['phone_revision']++;
            // New number or removal never inherits earlier permission.
            $row['updates_requested']=false;$row['hello_requested']=false;$row['consent_epoch']=0;
            return $row;
        });
    }

    public static function savePreferences(PDO $pdo,array $user,array $input,int $now):array
    {
        $expected=self::revision($input['expected_revision']??null);
        $updates=self::checkbox($input,'updates_requested');
        $hello=self::checkbox($input,'hello_requested');
        $scope=$input['update_scope']??null;
        $zone=$input['timezone']??null;$from=$input['window_start']??null;$to=$input['window_end']??null;
        self::check(in_array($scope,['important','eligible'],true)&&is_string($zone)&&strlen($zone)<=80
            &&($zone==='UTC'||in_array($zone,DateTimeZone::listIdentifiers(),true))
            &&is_string($from)&&is_string($to)
            &&preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$from)===1
            &&preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$to)===1&&strcmp($from,$to)<0,
            'Choose a valid time zone, update scope and same-day calling window.');
        $max=self::limit($input['max_calls_per_day']??null,1,3);
        $gap=self::limit($input['min_gap_minutes']??null,120,10080);
        $duration=self::limit($input['max_duration_seconds']??null,60,300);
        self::check($duration%60===0,'Choose a whole-minute call duration.');
        return self::transaction($pdo,$user,$expected,$now,static function(array $r)use($updates,$hello,$scope,$zone,$from,$to,$max,$gap,$duration,$now):array{
            self::check($r['phone_e164']!==''||(!$updates&&!$hello),'Save your personal phone number first.');
            return array_replace($r,['updates_requested'=>$updates,'hello_requested'=>$hello,'update_scope'=>$scope,
                'timezone'=>$zone,'window_start'=>$from,'window_end'=>$to,'max_calls_per_day'=>$max,
                'min_gap_minutes'=>$gap,'max_duration_seconds'=>$duration,'consent_epoch'=>($updates||$hello)?$now:0]);
        });
    }
}
