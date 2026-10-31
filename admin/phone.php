<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';
require_once dirname(__DIR__).'/includes/phone-sms-outbound-v120.php';
require_once dirname(__DIR__).'/includes/phone-voice-v130.php';
require_login();require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$ready=vp3_phone_schema_ready_v100($pdo);$error='';$connectionTest='';

if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!verify_csrf()) $error='Session expired. Refresh and try again.';
    elseif (!$ready) $error='Run the VP3 Cloud database upgrade before configuring phone numbers.';
    else {
        try {
            $action=(string)($_POST['action']??'');
            if ($action==='save_twilio') {
                $sid=trim((string)($_POST['account_sid']??''));
                if (!preg_match('/^AC[a-f0-9]{32}$/iD',$sid)) throw new RuntimeException('Enter a valid Twilio Account SID.');
                $token=trim((string)($_POST['auth_token']??''));
                $old=trim((string)setting('phone_twilio_account_sid',''));
                if ($old!=='' && !hash_equals($old,$sid) && $token==='')
                    throw new RuntimeException('Enter an Auth Token when changing Twilio accounts.');
                if ($old!=='' && !hash_equals($old,$sid)) {
                    $existing=(int)$pdo->query('SELECT COUNT(*) FROM phone_cloud_numbers_v100')->fetchColumn();
                    if ($existing>0) throw new RuntimeException('This platform already tracks Twilio numbers. Reconcile or migrate that inventory before changing carrier accounts.');
                }
                if ($token!=='' && (strlen($token)<20 || strlen($token)>180 || preg_match('/\s/',$token)))
                    throw new RuntimeException('The Twilio Auth Token format is invalid.');
                $gateway=trim((string)($_POST['gateway_url']??''));
                if ($gateway!=='') {
                    $parts=parse_url($gateway);
                    if (!is_array($parts) || ($parts['scheme']??'')!=='https' || empty($parts['host'])
                        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))
                        throw new RuntimeException('Gateway address must be a clean HTTPS origin.');
                    $gateway=rtrim($gateway,'/');
                }
                if ($token!=='') save_setting('phone_twilio_auth_token',ai_encrypt_secret($token));
                save_setting('phone_twilio_account_sid',$sid);
                save_setting('phone_gateway_public_url',$gateway);
                flash('notice','Twilio settings saved. No numbers were purchased or activated.');
                redirect(url('/admin/phone.php'));
            }
            if ($action==='save_sms_callback') {
                $callback=vp3_phone_sms_validate_callback_url_v110((string)($_POST['callback_url']??''));
                save_setting('phone_sms_webhook_url_v110',$callback);
                flash('notice','Incoming SMS callback saved. Existing numbers were not activated.');
                redirect(url('/admin/phone.php#sms'));
            }
            if ($action==='activate_incoming_sms') {
                if (!vp3_phone_sms_schema_ready_v110()) throw new RuntimeException('Run the PH3A database upgrade first.');
                vp3_phone_sms_activate_v110($pdo,(int)($_POST['number_id']??0));
                flash('notice','Twilio incoming SMS webhook configured and inbox enabled for that number. Outbound sending remains disabled.');
                redirect(url('/admin/phone.php#numbers'));
            }
            if ($action==='disable_incoming_sms') {
                $pdo->prepare('UPDATE phone_cloud_numbers_v100 SET sms_enabled=0 WHERE id=?')
                    ->execute([(int)($_POST['number_id']??0)]);
                flash('notice','Incoming SMS storage disabled locally. Twilio may still call the signed endpoint, which will ignore this number.');
                redirect(url('/admin/phone.php#numbers'));
            }
            if ($action==='save_voice_callback') {
                $callback=vp3_phone_voice_validate_url_v130((string)($_POST['voice_callback_url']??''));
                save_setting('phone_voice_webhook_url_v130',$callback);
                flash('notice','Incoming voice callback saved. Existing numbers were not activated.');
                redirect(url('/admin/phone.php#voice'));
            }
            if ($action==='activate_incoming_voice') {
                if (!vp3_phone_voice_schema_ready_v130()) throw new RuntimeException('Run the PH3C database upgrade first.');
                vp3_phone_voice_activate_v130($pdo,(int)($_POST['number_id']??0));
                flash('notice','Twilio now forwards incoming calls for this number to automated voicemail.');
                redirect(url('/admin/phone.php#numbers'));
            }
            if ($action==='disable_incoming_voice') {
                $pdo->prepare('UPDATE phone_cloud_numbers_v100 SET voice_enabled=0 WHERE id=?')
                    ->execute([(int)($_POST['number_id']??0)]);
                flash('notice','Incoming calls disabled locally. Twilio may still reach the endpoint, which will reject them.');
                redirect(url('/admin/phone.php#numbers'));
            }
            if ($action==='save_sms_status_callback') {
                $callback=vp3_phone_sms_validate_status_url_v120((string)($_POST['status_callback_url']??''));
                save_setting('phone_sms_status_url_v120',$callback);
                flash('notice','Signed Twilio delivery-status callback saved. Live outbound sending remains unchanged.');
                redirect(url('/admin/phone.php#outbound'));
            }
            if ($action==='save_sms_monthly_cap') {
                $cap=(int)($_POST['monthly_cap']??0);
                if ($cap<1||$cap>100000) throw new RuntimeException('Set a monthly account safety cap between 1 and 100,000 outgoing texts.');
                save_setting('phone_sms_account_monthly_cap_v120',(string)$cap);
                flash('notice','Account-wide per-member monthly SMS safety cap saved.');
                redirect(url('/admin/phone.php#outbound'));
            }
            if ($action==='enable_outbound_sms') {
                if (!vp3_phone_sms_outbound_ready_v120()) throw new RuntimeException('Run the PH3B database upgrade first.');
                vp3_phone_sms_validate_status_url_v120(vp3_phone_sms_status_url_v120());
                if (empty($_POST['compliance_confirmed']) || trim((string)($_POST['authorization']??''))!=='ENABLE OUTBOUND SMS')
                    throw new RuntimeException('Confirm messaging compliance and type ENABLE OUTBOUND SMS exactly.');
                save_setting('phone_sms_outbound_live_v120','1');
                flash('notice','Outbound SMS gateway enabled. Every message still requires account-owner approval and remaining package allowance.');
                redirect(url('/admin/phone.php#outbound'));
            }
            if ($action==='disable_outbound_sms') {
                save_setting('phone_sms_outbound_live_v120','0');
                flash('notice','Outbound SMS gateway disabled for all accounts.');
                redirect(url('/admin/phone.php#outbound'));
            }
            if ($action==='test_connection') {
                $sid=vp3_phone_twilio_credentials_v100()[0];
                $account=vp3_phone_twilio_http_v100('GET','/2010-04-01/Accounts/'.$sid.'.json');
                $connectionTest='Twilio connection verified: '.(string)($account['friendly_name']??'account').' ('.(string)($account['status']??'status unknown').').';
            } elseif ($action==='import_numbers') {
                $count=vp3_phone_import_inventory_v100($pdo);
                flash('notice','Imported or reconciled '.$count.' Twilio phone number(s). Existing assignments were preserved.');
                redirect(url('/admin/phone.php#numbers'));
            } elseif ($action==='assign_number') {
                $pair=(string)($_POST['agent_pair']??'');
                if (!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D',$pair,$matches))
                    throw new RuntimeException('Choose a user and their own active agent.');
                vp3_phone_assign_v100($pdo,(int)($_POST['number_id']??0),(int)$matches[1],(int)$matches[2]);
                flash('notice','Phone number assigned. Its call and text channels remain disabled until the gateway is configured.');
                redirect(url('/admin/phone.php#numbers'));
            } elseif ($action==='purchase_request') {
                $purchased=vp3_phone_purchase_request_v100($pdo,(int)($_POST['request_id']??0),
                    (string)($_POST['confirmation']??''));
                flash('notice','Purchased '.(string)$purchased['number'].' through Twilio. Gateway activation is still required.');
                redirect(url('/admin/phone.php#numbers'));
            } elseif (!in_array($action,['test_connection'],true)) {
                throw new RuntimeException('Unknown phone administration action.');
            }
        } catch(Throwable $exception) {$error=$exception->getMessage();}
    }
}

$state=vp3_phone_twilio_state_v100();$numbers=[];$requests=[];$agents=[];
if ($ready) {
    $numbers=$pdo->query('SELECT n.*,u.display_name AS owner_name,a.display_name AS agent_name
      FROM phone_cloud_numbers_v100 n LEFT JOIN users u ON u.id=n.owner_user_id
      LEFT JOIN user_agents a ON a.id=n.agent_id ORDER BY n.id DESC LIMIT 150')->fetchAll();
    $requests=$pdo->query('SELECT r.*,u.display_name AS owner_name,a.display_name AS agent_name
      FROM phone_cloud_requests_v100 r INNER JOIN users u ON u.id=r.owner_user_id
      LEFT JOIN user_agents a ON a.id=r.agent_id
      WHERE r.status IN (\'pending\',\'purchasing\',\'review_required\') ORDER BY r.id DESC LIMIT 150')->fetchAll();
    $agents=$pdo->query('SELECT a.id,a.owner_user_id,a.display_name AS agent_name,u.display_name AS owner_name
      FROM user_agents a INNER JOIN users u ON u.id=a.owner_user_id WHERE a.is_active=1
      ORDER BY u.display_name,a.display_name LIMIT 250')->fetchAll();
}
$adminTitle='Phone & Messaging';$adminActive='phone';require __DIR__.'/_header.php';
?>
<style>
.vp3-phone-admin{max-width:1120px;margin:0 auto;padding:16px 18px 36px;color:#202630}
.vp3-phone-admin h2{margin:0 0 8px;font-size:20px}.vp3-phone-admin p{line-height:1.5;color:#596576}
.vp3-phone-admin .phone-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,310px),1fr));gap:14px}
.vp3-phone-admin .phone-card{background:#fff;border:1px solid #dce2e8;border-radius:13px;padding:18px;margin-bottom:16px}
.vp3-phone-admin .phone-field{display:grid;gap:5px;margin:10px 0;font-weight:700;font-size:12px}
.vp3-phone-admin input,.vp3-phone-admin select{width:100%;min-height:38px;border:1px solid #cbd4df;border-radius:8px;padding:8px;font:inherit;font-size:13px;background:#fff;color:#1f2937}
.vp3-phone-admin button{border:0;border-radius:8px;padding:10px 13px;background:#172335;color:#fff;font-weight:750;cursor:pointer}
.vp3-phone-admin button:disabled{background:#a9b4c1;cursor:not-allowed}
.vp3-phone-admin .phone-secondary{background:#e9eef4;color:#26384b}
.vp3-phone-admin .phone-alert{background:#fff5ec;border:1px solid #f0cdaa;border-radius:9px;padding:12px;margin-bottom:13px}
.vp3-phone-admin .phone-ok{background:#edfff2;border-color:#9bd5ae}
.vp3-phone-admin .phone-table{width:100%;border-collapse:collapse;font-size:12px}
.vp3-phone-admin .phone-table th,.vp3-phone-admin .phone-table td{padding:10px;border-bottom:1px solid #e5eaf0;text-align:left;vertical-align:top}
.vp3-phone-admin .phone-table th{color:#596576}.vp3-phone-admin .phone-table form{display:grid;gap:6px;min-width:180px}
.vp3-phone-admin .phone-scroll{overflow:auto}.vp3-phone-admin small{color:#66758a}
@media(max-width:640px){.vp3-phone-admin{padding:12px}.vp3-phone-admin .phone-card{padding:14px}.vp3-phone-admin .phone-table{min-width:620px}}
</style>
<div class="vp3-phone-admin">
  <?php if(!$ready): ?><div class="phone-alert" role="alert">Phone registry is not installed. <a href="<?= e(url('/upgrade.php')) ?>">Run the VP3 Cloud upgrade</a> before setting up Twilio.</div><?php endif; ?>
  <?php if($error!==''): ?><div class="phone-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if($connectionTest!==''): ?><div class="phone-alert phone-ok" role="status"><?= e($connectionTest) ?></div><?php endif; ?>
  <div class="phone-grid">
    <section class="phone-card">
      <h2>Twilio platform account</h2>
      <p>One platform-managed Twilio connection for now. Credentials stay encrypted on the Cloud server; they are not exposed to agents or users.</p>
      <p><strong><?= $state['configured']?'Connected credentials':'Not connected' ?></strong> · <?= e((string)$state['credential_state']) ?></p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_twilio">
        <label class="phone-field">Account SID<input name="account_sid" autocomplete="off" required pattern="AC[0-9a-fA-F]{32}" value="<?= e((string)$state['account_sid']) ?>"></label>
        <label class="phone-field">Auth Token<input type="password" name="auth_token" autocomplete="new-password" placeholder="<?= $state['has_secret']?'Saved — leave blank to keep':'Paste Twilio Auth Token' ?>"></label>
        <label class="phone-field">Public phone gateway HTTPS origin (optional for now)<input type="url" name="gateway_url" placeholder="https://phone.example.com" value="<?= e((string)$state['gateway_url']) ?>"></label>
        <button type="submit" <?= $ready?'':'disabled' ?>>Save Twilio settings</button>
      </form>
      <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="test_connection"><button class="phone-secondary" <?= $state['configured']?'':'disabled' ?>>Test Twilio connection</button></form>
    </section>
    <section class="phone-card">
      <h2>Activation & safety</h2>
      <p>Number purchases are billable and require an exact confirmation. Importing existing numbers does not purchase anything.</p>
      <p>SMS and incoming voicemail can be activated individually using signed HTTPS Cloud callbacks. Conversational calling and outbound calls remain disabled pending their own certification.</p>
      <p>Outgoing calls and texts will still require consent plus an owner approval for the precise action. Outbound: disabled by default; the separate SMS gateway can be enabled by an administrator.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="import_numbers"><button <?= $state['configured']&&$ready?'':'disabled' ?>>Import existing Twilio numbers</button></form>
      <p><small>SMS registration and voice/recording consent depend on number type, destination, and use case. No live sending is enabled by this page.</small></p>
    </section>
  </div>
  <section class="phone-card" id="sms"><h2>Incoming SMS gateway · PH3A</h2>
    <p>Configure the precise public Cloud callback URL. Twilio webhooks are checked against this URL and your saved Auth Token before any message is stored. No incoming content is forwarded to AI providers.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_sms_callback"><label class="phone-field">Signed HTTPS SMS webhook URL<input type="url" name="callback_url" required placeholder="https://your-cloud.example/api/phone-sms-inbound-v110.php" value="<?= e(vp3_phone_sms_callback_url_v110()) ?>"></label><button <?= $ready?'':'disabled' ?>>Save callback URL</button></form>
    <p><small>Activating a number sets its Twilio inbound SMS URL and enables your private Cloud inbox. It does not enable outbound messages. For live use, confirm that the public Cloud domain resolves and PHP can receive POST callbacks.</small></p>
  </section>
  <section class="phone-card" id="voice"><h2>Incoming calls & voicemail · PH3C</h2>
    <p>Each activated number answers with an automated greeting using its assigned agent name and records up to two minutes of voicemail. Recordings are accessed through an authenticated owner-only player; they are not sent to an AI provider. No real-time conversation or outbound calls are enabled.</p>
    <?php if(!vp3_phone_voice_schema_ready_v130()): ?><p class="phone-alert">Run the PH3C database upgrade before activation.</p><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_voice_callback"><label class="phone-field">Signed HTTPS voice webhook URL<input type="url" name="voice_callback_url" required placeholder="https://your-cloud.example/api/phone-voice-inbound-v130.php" value="<?= e(vp3_phone_voice_callback_url_v130()) ?>"></label><button <?= $ready?'':'disabled' ?>>Save voice callback URL</button></form>
    <p><small>Activation changes only the selected Twilio number's inbound Voice URL. Recording action and status callbacks are derived from the same HTTPS origin and individually signature-checked. Confirm call recording notice/consent rules for your use case before activating.</small></p>
  </section>
  <section class="phone-card" id="outbound"><h2>Authorized outgoing SMS · PH3B</h2>
    <p>Gateway state: <strong><?= vp3_phone_sms_live_enabled_v120()?'Enabled':'Disabled (safe default)' ?></strong>. A subscriber may draft texts, but sending requires their exact approval, a documented consent source, an active SMS package and available monthly allowance.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_sms_status_callback"><label class="phone-field">Public HTTPS delivery callback<input type="url" name="status_callback_url" required placeholder="https://your-cloud.example/api/phone-sms-status-v120.php" value="<?= e(vp3_phone_sms_status_url_v120()) ?>"></label><button <?= $ready?'':'disabled' ?>>Save delivery callback</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_sms_monthly_cap"><label class="phone-field">Per-member monthly outgoing SMS safety cap<input type="number" name="monthly_cap" min="1" max="100000" required value="<?= (int)setting('phone_sms_account_monthly_cap_v120','100') ?>"></label><button class="phone-secondary">Save account safety cap</button></form>
    <?php if(vp3_phone_sms_live_enabled_v120()): ?><form method="post" onsubmit="return confirm('Disable all outbound SMS sending?')"><?= csrf_field() ?><input type="hidden" name="action" value="disable_outbound_sms"><button class="phone-secondary">Disable outbound SMS</button></form>
    <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="enable_outbound_sms"><label class="phone-field"><input type="checkbox" name="compliance_confirmed" value="1" required> I have verified the Twilio account’s business messaging registration, approved use case, consent process and destination rules.</label><label class="phone-field">Type ENABLE OUTBOUND SMS<input name="authorization" placeholder="ENABLE OUTBOUND SMS" autocomplete="off" required></label><button <?= $ready&&vp3_phone_sms_status_url_v120()!==''?'':'disabled' ?>>Enable outgoing text gateway</button></form><?php endif; ?>
    <p><small>Messaging may incur carrier charges; Twilio account-level spend controls remain important. No bulk or automated marketing sends are provided by this feature.</small></p>
  </section>
  <section class="phone-card" id="requests"><h2>Member phone requests (<?= count($requests) ?>)</h2>
    <p>Members select an available number and their agent. You review and explicitly purchase it through Twilio.</p>
    <?php if(!$requests): ?><p>No pending requests.</p><?php else: ?><div class="phone-scroll"><table class="phone-table"><thead><tr><th>Member & agent</th><th>Requested number</th><th>Purchase approval</th></tr></thead><tbody>
    <?php foreach($requests as $request): ?><tr><td><?= e((string)$request['owner_name']) ?><br><small><?= e((string)$request['agent_name']) ?></small></td><td><strong><?= e((string)$request['phone_e164']) ?></strong><br><small><?= e((string)$request['status']) ?> · Confirm availability with Twilio</small></td><td><?php if($request['status']!=='pending'): ?><strong>Manual review required</strong><br><small>Import Twilio inventory and assign an existing number. Do not repeat a purchase without reconciliation.</small><?php else: ?><form method="post" onsubmit="return confirm('This action purchases a phone number on your Twilio account. Proceed?')"><?= csrf_field() ?><input type="hidden" name="action" value="purchase_request"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><label>Type PURCHASE <?= e((string)$request['phone_e164']) ?><input name="confirmation" autocomplete="off" placeholder="PURCHASE <?= e((string)$request['phone_e164']) ?>" required></label><button <?= $state['configured']?'':'disabled' ?>>Purchase & assign</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
  <section class="phone-card" id="numbers"><h2>Registered number inventory (<?= count($numbers) ?>)</h2>
    <?php if(!$numbers): ?><p>There are no imported or purchased numbers yet.</p><?php else: ?><div class="phone-scroll"><table class="phone-table"><thead><tr><th>Number</th><th>Member</th><th>Agent</th><th>State</th><th>Assign / reassign</th></tr></thead><tbody>
    <?php foreach($numbers as $number): ?><tr><td><strong><?= e((string)$number['phone_e164']) ?></strong></td><td><?= e((string)($number['owner_name']?:'Unassigned')) ?></td><td><?= e((string)$number['agent_name']) ?></td><td><?= e((string)$number['status']) ?><br><small>Incoming SMS: <?= (int)$number['sms_enabled']===1?'enabled':'disabled' ?> · Incoming calls: <?= (int)$number['voice_enabled']===1?'voicemail enabled':'disabled' ?></small></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_number"><input type="hidden" name="number_id" value="<?= (int)$number['id'] ?>"><select name="agent_pair" required><option value="">Choose a member’s agent</option><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['owner_user_id'] ?>:<?= (int)$agent['id'] ?>" <?= (int)$number['owner_user_id']===(int)$agent['owner_user_id']&&(int)$number['agent_id']===(int)$agent['id']?'selected':'' ?>><?= e((string)$agent['owner_name']) ?> — <?= e((string)$agent['agent_name']) ?></option><?php endforeach; ?></select><button class="phone-secondary">Assign</button></form>
    <?php if((int)$number['sms_enabled']===1): ?><form method="post" onsubmit="return confirm('Disable incoming SMS storage for this number?')"><?= csrf_field() ?><input type="hidden" name="action" value="disable_incoming_sms"><input type="hidden" name="number_id" value="<?= (int)$number['id'] ?>"><button class="phone-secondary">Disable incoming SMS</button></form>
    <?php else: ?><form method="post" onsubmit="return confirm('Configure Twilio to deliver incoming SMS for this assigned number to the VP3 Cloud inbox?')"><?= csrf_field() ?><input type="hidden" name="action" value="activate_incoming_sms"><input type="hidden" name="number_id" value="<?= (int)$number['id'] ?>"><button <?= $state['configured']&&vp3_phone_sms_callback_url_v110()!==''?'':'disabled' ?>>Enable incoming SMS</button></form><?php endif; ?>
    <?php if((int)$number['voice_enabled']===1): ?><form method="post" onsubmit="return confirm('Disable inbound call handling for this number?')"><?= csrf_field() ?><input type="hidden" name="action" value="disable_incoming_voice"><input type="hidden" name="number_id" value="<?= (int)$number['id'] ?>"><button class="phone-secondary">Disable incoming calls</button></form>
    <?php else: ?><form method="post" onsubmit="return confirm('Activate an automated voicemail greeting with recording for this number?')"><?= csrf_field() ?><input type="hidden" name="action" value="activate_incoming_voice"><input type="hidden" name="number_id" value="<?= (int)$number['id'] ?>"><button <?= $state['configured']&&vp3_phone_voice_schema_ready_v130()&&vp3_phone_voice_callback_url_v130()!==''?'':'disabled' ?>>Enable incoming voicemail</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
</div>
<?php require __DIR__.'/_footer.php'; ?>
