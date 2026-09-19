<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/vp3-public.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

const VP3_EXTENSION_PENDING_APPROVAL_SESSION_V2000='vp3_extension_pending_approval_v2000';

const VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100='vp3_extension_device_approval_v2100';

function vp3_extension_callback_v2100(string $redirectUri,array $params): string
{
    if(!vp3_extension_redirect_uri_valid_v2100($redirectUri))throw new RuntimeException('The Browser Companion callback is invalid.');
    return $redirectUri.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

// v21.00: the extension opens this page directly through
// chrome.identity.launchWebAuthFlow(). Capture only bounded device metadata and
// the validated chromiumapp callback, then clean the address bar before login.
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&isset($_GET['installation_id'])){
    try{
        $context=vp3_extension_device_context_v2100($_GET);
        $_SESSION[VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100]=$context+['stored_at'=>time()];
        redirect(url('/extension-connect.php').'?flow=v21');
    }catch(Throwable $e){
        $_SESSION[VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100]=[
            'error'=>$e->getMessage(),
            'stored_at'=>time(),
        ];
        redirect(url('/extension-connect.php').'?flow=v21');
    }
}

$v21Pending=$_SESSION[VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100]??null;
$v21Flow=((string)($_GET['flow']??'')==='v21')||is_array($v21Pending);
if($v21Flow){
    if(!is_array($v21Pending)||((int)($v21Pending['stored_at']??0))<(time()-1200)){
        unset($_SESSION[VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100]);
        $v21Pending=['error'=>'This Browser Companion connection expired. Start again from the extension.'];
    }

    if(!is_logged_in()&&empty($v21Pending['error'])){
        $returnTo=url('/extension-connect.php').'?flow=v21';
        redirect(url('/login.php?return_to='.rawurlencode($returnTo)));
    }

    $v21Error=trim((string)($v21Pending['error']??''));
    $v21User=current_user();
    $v21Pdo=db();
    if($v21Error===''&&(!$v21Pdo||!vp3_extension_device_token_schema_ready_v2100($v21Pdo))){
        $v21Error='VP3 Browser Companion is not ready. Ask an administrator to run the database upgrade.';
    }

    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$v21Error===''&&$v21User){
        if(!verify_csrf()){
            $v21Error='Session expired. Please try again.';
        }else{
            $decision=(string)($_POST['decision']??'');
            try{
                if($decision==='approve'){
                    $code=vp3_extension_device_code_issue_v2100($v21Pdo,(int)$v21User['id'],$v21Pending);
                    $redirect=vp3_extension_callback_v2100((string)$v21Pending['redirect_uri'],[
                        'code'=>$code,
                        'state'=>(string)$v21Pending['state'],
                    ]);
                    unset($_SESSION[VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100]);
                    header('Location: '.$redirect,true,302);
                    exit;
                }
                if($decision==='deny'){
                    $redirect=vp3_extension_callback_v2100((string)$v21Pending['redirect_uri'],[
                        'error'=>'access_denied',
                        'state'=>(string)$v21Pending['state'],
                    ]);
                    unset($_SESSION[VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100]);
                    header('Location: '.$redirect,true,302);
                    exit;
                }
                $v21Error='Choose Connect Browser or Cancel.';
            }catch(Throwable $e){
                $v21Error=$e->getMessage();
            }
        }
    }

    vp3_public_header('Connect Browser Companion — VP3','Connect Chrome to your signed-in VP3 account.',['compact'=>true]);
    ?>
    <main class="vp3-auth-shell">
      <section class="vp3-auth-visual">
        <div class="vp3-auth-visual-content">
          <div class="vp3-kicker">VP3 Browser Companion</div>
          <h1>Connect Chrome to VP3.</h1>
          <p>Your password stays on the VP3 website. Chrome receives one revocable device token for this browser.</p>
        </div>
      </section>
      <section class="vp3-auth-form-side">
        <div class="vp3-auth-card">
          <div class="vp3-kicker">Browser connection</div>
          <h1>Connect this browser</h1>
          <?php if($v21Error): ?>
            <div class="vp3-alert error" role="alert"><?= e($v21Error) ?></div>
            <p class="vp3-auth-intro">Return to the Browser Companion and start the connection again.</p>
          <?php else: ?>
            <p class="vp3-auth-intro">You're signed in as <strong><?= e((string)($v21User['display_name']??'VP3 user')) ?></strong>.</p>
            <dl style="display:grid;grid-template-columns:auto 1fr;gap:.6rem 1rem;margin:1.25rem 0;">
              <dt>Browser</dt><dd>Chrome</dd>
              <dt>Device</dt><dd><?= e((string)$v21Pending['device_name']) ?></dd>
              <dt>Extension</dt><dd><?= e((string)$v21Pending['extension_version']) ?></dd>
            </dl>
            <p class="vp3-auth-intro">The extension will use your current VP3 account and permissions. Changes to your account, Teams, or permissions apply automatically.</p>
            <form method="post" action="<?= e(url('/extension-connect.php').'?flow=v21') ?>" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
              <?= csrf_field() ?>
              <button class="vp3-btn primary" type="submit" name="decision" value="approve">Connect Browser →</button>
              <button class="vp3-btn" type="submit" name="decision" value="deny">Cancel</button>
            </form>
          <?php endif; ?>
        </div>
      </section>
    </main>
    <?php
    vp3_public_footer();
    exit;
}


// Approval links necessarily arrive with a one-time secret. Move it into the
// server-side PHP session immediately, then redirect to a clean URL so the
// secret does not remain in page markup, subresource referrers, or later POSTs.
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    $incomingId=trim((string)($_GET['id']??''));
    $incomingToken=trim((string)($_GET['approval_token']??''));
    if($incomingId!==''||$incomingToken!==''){
        if(vp3_extension_valid_uuid_v2000($incomingId)&&preg_match('/^[a-f0-9]{64}$/',$incomingToken)){
            $_SESSION[VP3_EXTENSION_PENDING_APPROVAL_SESSION_V2000]=[
                'id'=>strtolower($incomingId),
                'approval_token'=>strtolower($incomingToken),
                'stored_at'=>time(),
            ];
        }else{
            unset($_SESSION[VP3_EXTENSION_PENDING_APPROVAL_SESSION_V2000]);
        }
        redirect(url('/extension-connect.php'));
    }
}

// Preserve the pending pairing through an ordinary VP3 sign-in without putting
// the approval secret into the login URL or login form. The existing funnel
// return_to handling safely brings the user back to this clean local path.
if(!is_logged_in()){
    $returnTo=url('/extension-connect.php');
    redirect(url('/login.php?return_to='.rawurlencode($returnTo)));
}

$pdo=db();
$user=current_user();
$error='';
$notice='';
$request=null;
$pending=$_SESSION[VP3_EXTENSION_PENDING_APPROVAL_SESSION_V2000]??null;
if(!is_array($pending)||((int)($pending['stored_at']??0))<(time()-1200)){
    unset($_SESSION[VP3_EXTENSION_PENDING_APPROVAL_SESSION_V2000]);
    $pending=null;
}
$id=trim((string)($pending['id']??''));
$approvalToken=trim((string)($pending['approval_token']??''));

if(!$pdo||!vp3_extension_schema_ready_v2000($pdo)){
    $error='VP3 Browser Companion is not ready. Ask an administrator to run the database upgrade.';
}elseif($id===''||$approvalToken===''){
    $error='This Browser Companion connection link is incomplete or expired.';
}else{
    $request=vp3_extension_connection_for_approval_v2000($pdo,$id,$approvalToken);
    if(!$request){
        unset($_SESSION[VP3_EXTENSION_PENDING_APPROVAL_SESSION_V2000]);
        $error='This Browser Companion connection request is not available.';
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'&&$request&&$user){
    if(!verify_csrf()){
        $error='Session expired. Please try again.';
    }else{
        $decision=(string)($_POST['decision']??'');
        try{
            $result=vp3_extension_connection_decide_v2000($pdo,$id,$approvalToken,(int)$user['id'],$decision);
            $status=(string)($result['status']??'');
            if($status==='approved')$notice='Browser Companion approved. Return to the extension to finish connecting.';
            elseif($status==='denied')$notice='Browser Companion connection denied.';
            elseif($status==='expired')$error='This Browser Companion connection request has expired. Start a new connection from the extension.';
            else $notice='This Browser Companion request has already been resolved.';
            $request=vp3_extension_connection_for_approval_v2000($pdo,$id,$approvalToken);
            if($status!=='pending')unset($_SESSION[VP3_EXTENSION_PENDING_APPROVAL_SESSION_V2000]);
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }
}

$status=(string)($request['request_status']??'');
$requested=$request?vp3_extension_json_array_v2000($request['requested_capabilities_json']??'[]'):[];
$capabilityLabels=[
    'team.destinations.read'=>'See the Team conversations you can share to',
    'team.share.create'=>'Share browser content with your Team',
    'team.chat.read'=>'Open authorized Team conversations',
    'agent.message'=>'Ask your VP3 Agent about browser context',
    'knowledge.write'=>'Save browser content to Knowledge',
    'task.propose'=>'Propose tasks from browser content',
    'notifications.read'=>'Receive VP3 extension notifications',
    'browser.asset.upload'=>'Upload browser-captured assets',
];

vp3_public_header('Connect Browser Companion — VP3','Approve a browser connection to your VP3 account.',['compact'=>true]);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">VP3 Browser Companion</div>
      <h1>Bring VP3 with you across the web.</h1>
      <p>Approve this browser only if you started the connection from the VP3 extension. The extension never receives your VP3 password.</p>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Browser connection</div>
      <h1>Connect this browser</h1>

      <?php if($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if($notice): ?><div class="vp3-alert success" role="status"><?= e($notice) ?></div><?php endif; ?>

      <?php if($request): ?>
        <dl style="display:grid;grid-template-columns:auto 1fr;gap:.6rem 1rem;margin:1.25rem 0;">
          <dt>Device</dt><dd><?= e((string)$request['device_name']) ?></dd>
          <dt>Browser</dt><dd><?= e((string)$request['browser_family']) ?></dd>
          <dt>Extension</dt><dd><?= e((string)$request['extension_version'] ?: 'Unknown version') ?></dd>
          <dt>Status</dt><dd><?= e(ucfirst((string)$request['request_status'])) ?></dd>
        </dl>

        <?php if($requested): ?>
          <h2 style="font-size:1rem;margin-top:1.4rem;">Requested access</h2>
          <ul style="padding-left:1.2rem;">
            <?php foreach($requested as $capability): ?>
              <li><?= e($capabilityLabels[$capability]??$capability) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if($status==='pending'): ?>
          <form method="post" action="<?= e(url('/extension-connect.php')) ?>" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
            <?= csrf_field() ?>
            <button class="vp3-btn primary" type="submit" name="decision" value="approve">Approve Browser →</button>
            <button class="vp3-btn" type="submit" name="decision" value="deny">Deny</button>
          </form>
        <?php elseif($status==='approved'): ?>
          <p class="vp3-auth-intro">This browser has been approved. Return to the extension to complete connection.</p>
        <?php elseif($status==='denied'): ?>
          <p class="vp3-auth-intro">This connection was denied. You can close this page.</p>
        <?php elseif($status==='expired'): ?>
          <p class="vp3-auth-intro">This connection request expired. Start a new connection from the extension.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
