<?php
declare(strict_types=1);

require_once (string)getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-conversation-checkout-pa5.php';

session_start();
function redirect(string $destination): never
{
    header('Location: '.$destination);
    exit;
}
// Requests explicitly marked as fixture login change the server-side principal.
// Unmarked requests use the current session, as in production.
if(isset($_SERVER['HTTP_X_FIXTURE_LOGIN']))pa3_login((int)$_SERVER['HTTP_X_FIXTURE_LOGIN']);
$GLOBALS['pa5ProviderHook']=static function(): void {
    if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('Provider called with the public PHP session lock held.');
    $mutation=$_SERVER['HTTP_X_FIXTURE_SWITCH_DURING_CHECKOUT']??'';
    if($mutation==='')return;
    session_start(['use_cookies'=>false,'use_trans_sid'=>false]);
    if($mutation==='logout')session_destroy();
    else {
        $tokens=$_SESSION['profile_chat_tokens']??[];
        pa3_login((int)$mutation);
        $_SESSION['profile_chat_tokens']=$tokens;
        session_write_close();
    }
};
