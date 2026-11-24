<?php
declare(strict_types=1);

/** Dedicated Twilio Verify SMS service; never a Voice API, login or call grant. */
final class VP3OwnerPhoneVerifyProviderV1
{
    private string $account;
    private string $service;
    private string $key;
    private string $secret;
    private string $rateKey;
    private ?Closure $fixture;

    public function __construct(#[\SensitiveParameter] array $settings,?Closure $fixture=null)
    {
        if(($settings['enabled']??false)!==true
            || !self::sid($settings['account_sid']??null,'AC')
            || !self::sid($settings['service_sid']??null,'VA')
            || !self::sid($settings['api_key_sid']??null,'SK')
            || !is_string($settings['api_key_secret']??null)
            || strlen($settings['api_key_secret'])<16 || strlen($settings['api_key_secret'])>256
            || !is_string($settings['rate_key_hex']??null)
            || preg_match('/\A[0-9a-f]{64}\z/D',$settings['rate_key_hex'])!==1
            || count(array_unique(str_split($settings['rate_key_hex'],2)))<2)
            throw new DomainException('Phone verification is not configured.');
        $this->account=$settings['account_sid'];$this->service=$settings['service_sid'];
        $this->key=$settings['api_key_sid'];$this->secret=$settings['api_key_secret'];
        $this->rateKey=hex2bin($settings['rate_key_hex']);$this->fixture=$fixture;
    }
    public static function configured():?self
    {
        try{
            $settings=$GLOBALS['config']['operator_owner_phone_verify']??[];
            return is_array($settings)&&function_exists('curl_init')?new self($settings):null;
        }catch(Throwable){return null;}
    }
    private static function sid(mixed $v,string $prefix):bool
    {return is_string($v)&&preg_match('/\A'.$prefix.'[a-fA-F0-9]{32}\z/D',$v)===1;}
    private static function phone(string $v):void
    {if(preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$v)!==1)throw new DomainException('Saved number is invalid.');}
    public function scope():string
    {return hash_hmac('sha256','vp3.owner.verify.scope.v1'."\0".$this->account."\0".$this->service,$this->rateKey);}
    public function destination(string $phone):string
    {self::phone($phone);return hash_hmac('sha256','vp3.owner.verify.destination.v1'."\0".$phone,$this->rateKey);}

    private function request(string $path,#[\SensitiveParameter] array $fields):array
    {
        $url='https://verify.twilio.com/v2/Services/'.$this->service.'/'.$path;
        try{
            if($this->fixture!==null){
                $r=($this->fixture)($url,$fields);
                if(!is_array($r))throw new RuntimeException();
                return $r;
            }
            if(!function_exists('curl_init'))throw new RuntimeException();
            $h=curl_init($url);
            if($h===false)throw new RuntimeException();
            $body='';
            try{
                curl_setopt_array($h,[
                    CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields,'','&',PHP_QUERY_RFC3986),
                    CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
                    CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERNAME=>$this->key,CURLOPT_PASSWORD=>$this->secret,
                    CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                    CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_NOSIGNAL=>true,
                    CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
                        if(strlen($body)+strlen($chunk)>16384)return 0;
                        $body.=$chunk;return strlen($chunk);
                    },
                ]);
                $ok=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);
                if($ok!==true||$status<200||$status>=300)throw new RuntimeException();
                $decoded=json_decode($body,true,16,JSON_THROW_ON_ERROR);
                if(!is_array($decoded)||array_is_list($decoded))throw new RuntimeException();
                return $decoded;
            }finally{curl_close($h);}
        }catch(Throwable){
            // No raw provider error, URL, code, response or number is logged.
            throw new RuntimeException('Verification request could not be confirmed.');
        }
    }
    private function receipt(array $r,string $phone,?string $verification=null):array
    {
        if(($r['account_sid']??null)!==$this->account||($r['service_sid']??null)!==$this->service
            ||($r['to']??null)!==$phone||($r['channel']??null)!=='sms'
            ||!self::sid($r['sid']??null,'VE')||($verification!==null&&$r['sid']!==$verification)
            ||!in_array($r['status']??null,['pending','approved','canceled','max_attempts_reached','deleted','failed','expired'],true))
            throw new RuntimeException('Verification receipt did not match.');
        return ['sid'=>$r['sid'],'status'=>$r['status']];
    }
    public function start(string $phone,int $reservedAt,int $now):array
    {
        self::phone($phone);
        $r=$this->request('Verifications',['To'=>$phone,'Channel'=>'sms']);
        $v=$this->receipt($r,$phone);
        // A dedicated service must not return a pre-existing verification
        // belonging to another flow. Provider and Cloud clocks must be close.
        $date=$r['date_created']??null;
        if(!is_string($date)||preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)\z/D',$date)!==1)
            throw new RuntimeException('Verification creation time unavailable.');
        $created=strtotime($date);
        if($v['status']!=='pending'||$created===false||$created<$reservedAt-5||$created>$now+20)
            throw new RuntimeException('Verification start could not be confirmed.');
        return $v;
    }
    public function check(string $phone,string $verification,#[\SensitiveParameter] string $code):array
    {
        self::phone($phone);
        if(!self::sid($verification,'VE')||preg_match('/\A[0-9]{4,10}\z/D',$code)!==1)throw new DomainException('Invalid verification code.');
        return $this->receipt($this->request('VerificationCheck',['VerificationSid'=>$verification,'Code'=>$code]),$phone,$verification);
    }
}
