<?php
declare(strict_types=1);

/** One replaceable I/O boundary; authorization and ledger transitions stay in callers. */
if(!function_exists('agent_commerce_provider_http_pa2')){
    function agent_commerce_provider_http_pa2(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array
    {
        return agent_commerce_http_v800($method,$url,$headers,$body,$form);
    }
}

function agent_commerce_attempt_connection_pa2(PDO $pdo,array $order,array $attempt): array
{
    $owner=(int)($order['workspace_owner_user_id']??0)?:((int)($order['owner_user_id']??0));
    $connection=agent_commerce_connection_v800($pdo,(int)($attempt['connection_id']??0),$owner);
    if(!$connection||(int)($attempt['order_id']??0)!==(int)($order['id']??0)
        ||(string)($attempt['provider']??'')!==(string)($connection['provider']??'')
        ||(string)($attempt['provider_account_id']??'')===''
        ||!hash_equals((string)$attempt['provider_account_id'],(string)($connection['external_account_id']??''))){
        throw new RuntimeException('Commerce provider lineage could not be verified.',409);
    }
    return $connection;
}

/** Returns an authenticated relative-path request function. No provider writes occur here. */
function agent_commerce_provider_transport_pa2(PDO $pdo,array $connection,?callable $http=null): callable
{
    $http??='agent_commerce_provider_http_pa2';
    $provider=(string)($connection['provider']??'');$cfg=agent_commerce_config_v800();$headers=[];$base='';
    if($provider==='stripe'){
        $base='https://api.stripe.com';
        $headers=['Authorization: Bearer '.(string)$cfg['stripe']['secret_key'],'Stripe-Account: '.(string)$connection['external_account_id']];
    }elseif($provider==='square'){
        $base=agent_commerce_square_base_v800();
        $token=agent_commerce_decrypt_v800((string)($connection['access_token_ciphertext']??''));
        if($token==='')throw new RuntimeException('Commerce provider credentials are unavailable.',503);
        $expires=(string)($connection['token_expires_at']??'');
        if($expires!==''&&(strtotime($expires.' UTC')?:0)<=time()+300){
            $refresh=agent_commerce_decrypt_v800((string)($connection['refresh_token_ciphertext']??''));
            if($refresh==='')throw new RuntimeException('Commerce provider credentials require reconnection.',503);
            $reply=$http('POST',$base.'/oauth2/token',['Square-Version: 2026-08-19'],[
                'client_id'=>$cfg['square']['application_id'],'client_secret'=>$cfg['square']['client_secret'],
                'refresh_token'=>$refresh,'grant_type'=>'refresh_token'
            ],false);
            $data=$reply['json']??null;
            if(!is_array($data)||!is_string($data['access_token']??null)||$data['access_token']==='')throw new RuntimeException('Commerce credential refresh is unavailable.',503);
            $token=$data['access_token'];$nextRefresh=is_string($data['refresh_token']??null)?$data['refresh_token']:$refresh;$nextExpiry=null;
            if(is_string($data['expires_at']??null))try{$nextExpiry=(new DateTimeImmutable($data['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){}
            $pdo->prepare('UPDATE agent_commerce_provider_connections_v800 SET access_token_ciphertext=?,refresh_token_ciphertext=?,token_expires_at=?,last_verified_at=?,last_error=?,updated_at=? WHERE id=? AND owner_user_id=? AND external_account_id=?')
                ->execute([agent_commerce_encrypt_v800($token),agent_commerce_encrypt_v800($nextRefresh),$nextExpiry,gmdate('Y-m-d H:i:s'),'',gmdate('Y-m-d H:i:s'),(int)$connection['id'],(int)$connection['owner_user_id'],(string)$connection['external_account_id']]);
        }
        $headers=['Authorization: Bearer '.$token,'Square-Version: 2026-08-19'];
    }elseif($provider==='paypal'){
        $base=agent_commerce_paypal_base_v800();$pc=$cfg['paypal'];
        $reply=$http('POST',$base.'/v1/oauth2/token',['Authorization: Basic '.base64_encode($pc['client_id'].':'.$pc['client_secret'])],['grant_type'=>'client_credentials'],true);
        $token=$reply['json']['access_token']??null;
        if(!is_string($token)||$token==='')throw new RuntimeException('Commerce provider credentials are unavailable.',503);
        $headers=['Authorization: Bearer '.$token,'PayPal-Auth-Assertion: '.agent_commerce_paypal_assertion_v800((string)$connection['external_account_id'])];
        if((string)$pc['bn_code']!=='')$headers[]='PayPal-Partner-Attribution-Id: '.$pc['bn_code'];
    }else throw new RuntimeException('Commerce provider is unavailable.',503);
    return static function(string $method,string $path,mixed $body=null,bool $form=false,array $extraHeaders=[])use($http,$headers,$base):array{
        if(!preg_match('~^/[A-Za-z0-9/_?.=&%+:-]+$~D',$path)||str_contains($path,'://')||!in_array($method,['GET','POST'],true))throw new RuntimeException('Commerce provider request is invalid.',409);
        $reply=$http($method,$base.$path,array_merge($headers,$extraHeaders),$body,$form);
        if(!is_array($reply)||!is_array($reply['json']??null))throw new RuntimeException('Commerce provider response is unavailable.',503);
        return $reply['json'];
    };
}
