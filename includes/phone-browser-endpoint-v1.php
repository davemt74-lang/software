<?php
declare(strict_types=1);
/** Public carrier boundary. Session state never grants carrier authority. */
require_once __DIR__.'/phone-browser-v1.php';
function vp3_phone_browser_endpoint_v1(string $kind): void
{
    header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
    $xml=in_array($kind,['voice','complete','disclosure'],true);if($xml)header('Content-Type: application/xml; charset=UTF-8');
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);return;}
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/x-www-form-urlencoded')){http_response_code(415);return;}
    if((int)($_SERVER['CONTENT_LENGTH']??0)>12000){http_response_code(413);return;}
    $pdo=db();if(!$pdo||!vp3_phone_workspace_schema_ready_v1($pdo)||!vp3_phone_twilio_state_v100()['configured']){http_response_code(503);return;}
    if(count($_POST)>60){http_response_code(413);return;}$formBytes=0;
    foreach($_POST as $key=>$value){if(!is_string($value)||strlen((string)$key)>100||strlen($value)>3000){http_response_code(422);return;}$formBytes+=strlen((string)$key)+strlen($value);}
    if($formBytes>12000){http_response_code(413);return;}
    if(isset($_GET['call'])&&!is_string($_GET['call'])){http_response_code(422);return;}
    $signature=(string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??'');$public=(string)($_GET['call']??'');
    try{
        http_response_code(200);
        if($kind==='voice'){echo vp3_phone_browser_voice_v1($pdo,$_POST,$signature);return;}
        if($kind==='disclosure'){echo vp3_phone_browser_disclosure_v1($pdo,$_POST,$signature,$public);return;}
        if($kind==='status'||$kind==='complete'){
            if($kind==='status'&&$public===''){
                require_once __DIR__.'/profile-browser-phone-v1.php';
                vp3_profile_browser_phone_parent_status_v1($pdo,$_POST,$signature);
                http_response_code(204);return;
            }
            $result=vp3_phone_browser_status_v1($pdo,$_POST,$signature,$public,$kind==='complete');
            if($kind==='status'){http_response_code(204);return;}
            if(!empty($result['fallback'])){
                $route=vp3_phone_voice_current_route_v131($pdo,(string)$result['call']['provider_call_sid']);
                if(!empty($route['accepted'])){
                    $q=$pdo->prepare('SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1');$q->execute([(int)$route['agent_id'],(int)$route['owner_user_id']]);$name=$q->fetchColumn();
                    if($name!==false&&($result['call']['incoming_choice']??'')==='agent'){
                        require_once __DIR__.'/phone-voice-conversation-v140.php';
                        if(vp3_phone_voice_live_config_v140($pdo,$route)){
                            $sid=(string)$result['call']['provider_call_sid'];
                            $start=$pdo->prepare("UPDATE phone_voice_calls_v130 SET state='conversation_active' WHERE provider='twilio' AND provider_call_sid=? AND state='voicemail_offered' AND owner_user_id=? AND agent_id=? AND phone_number_id=?");
                            $start->execute([$sid,$route['owner_user_id'],$route['agent_id'],$route['phone_number_id']]);
                            if($start->rowCount()===1){
                                if(vp3_profile_browser_phone_find_sid_v1($pdo,$sid)!==null){
                                    require_once __DIR__.'/profile-browser-phone-v1.php';
                                    vp3_profile_browser_phone_route_v1($pdo,$sid,'agent');
                                }
                                echo vp3_phone_voice_gather_v140((string)$name,vp3_phone_voice_callback_url_v130(),'Hello. I am '.$name.', an AI phone assistant. How can I help?',1);
                                return;
                            }
                        }
                    }
                    if($name!==false){
                        $sid=(string)$result['call']['provider_call_sid'];
                        if(vp3_profile_browser_phone_find_sid_v1($pdo,$sid)!==null){
                            require_once __DIR__.'/profile-browser-phone-v1.php';
                            vp3_profile_browser_phone_route_v1($pdo,$sid,'voicemail');
                        }
                        echo vp3_phone_voicemail_twiml_v1($pdo,$route,$sid,(string)$name,vp3_phone_voice_callback_url_v130());return;
                    }
                }
            }
            echo vp3_phone_browser_hangup_v1();return;
        }
        if($kind==='recording'){
            $result=vp3_phone_browser_recording_v1($pdo,$_POST,$signature,$public);http_response_code(204);return;
        }
        http_response_code(404);
    }catch(InvalidArgumentException|RuntimeException $error){
        $message=$error->getMessage();http_response_code(str_contains($message,'signature')||str_contains($message,'account mismatch')?403:422);
    }catch(Throwable){http_response_code(503);}
}
