<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$error = '';
$success = flash('success');
$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$company = trim((string)($_POST['company'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$role = trim((string)($_POST['role'] ?? ''));
$teamSize = trim((string)($_POST['team_size'] ?? ''));
$workflows = array_values(array_filter(array_map('strval', (array)($_POST['workflow'] ?? []))));
$allowedWorkflows = ['stems','sessions','rights','publishing','release','venue'];
$workflows = array_values(array_intersect($workflows, $allowedWorkflows));
$allowedRoles = ['artist','producer','supervisor','manager','venue','legal'];
if (!in_array($role, $allowedRoles, true)) { $role = ''; }
$allowedTeamSizes = ['1','2-5','6-20','21+'];
if (!in_array($teamSize, $allowedTeamSizes, true)) { $teamSize = ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        flash('success', 'Thanks. Your demo request has been received.');
        redirect(url('/book-demo.php'));
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter your name and a valid email address.';
    } elseif (mb_strlen($name) > 120 || mb_strlen($email) > 190 || mb_strlen($company) > 190 || mb_strlen($phone) > 80 || mb_strlen($notes) > 5000) {
        $error = 'One or more fields are too long.';
    } else {
        $labels = ['stems'=>'Stems & DAW projects','sessions'=>'Sessions & recording notes','rights'=>'Rights, splits & legal','publishing'=>'Publishing & metadata','release'=>'Release management','venue'=>'Shows, venues & events'];
        $workflowText = $workflows ? implode(', ', array_map(static fn(string $item): string => $labels[$item] ?? $item, $workflows)) : 'Not specified';
        $message = "Stonefellow demo request\n\n" .
            "Role: " . ($role !== '' ? $role : 'Not specified') . "\n" .
            "Team size: " . ($teamSize !== '' ? $teamSize : 'Not specified') . "\n" .
            "Workflows: {$workflowText}\n" .
            "Studio / company: " . ($company !== '' ? $company : 'Not provided') . "\n" .
            "Phone: " . ($phone !== '' ? $phone : 'Not provided') . "\n\n" .
            "Requested demo focus:\n" . ($notes !== '' ? $notes : 'Not provided');

        $pdo = db();
        $stored = false;
        $crmStored = false;
        $messageId = 0;
        if ($pdo && table_exists('contact_messages')) {
            try {
                $stmt = $pdo->prepare('INSERT INTO contact_messages (name,email,topic,message,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,NOW())');
                $stmt->execute([$name,$email,'Book a Demo',$message,substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500)]);
                $messageId = (int)$pdo->lastInsertId();
                $stored = true;

                // Public requests never perform schema DDL. The Admin CRM creates
                // its tables on first admin access and quietly imports any demo
                // requests collected before that point.
                if (
                    function_exists('crm_v180_create_demo_lead')
                    && function_exists('crm_v180_schema_ready')
                    && crm_v180_schema_ready($pdo)
                ) {
                    try {
                        $leadId = crm_v180_create_demo_lead([
                            'name' => $name,
                            'email' => $email,
                            'company' => $company,
                            'phone' => $phone,
                            'role' => $role,
                            'team_size' => $teamSize,
                            'workflows' => array_map(static fn(string $item): string => $labels[$item] ?? $item, $workflows),
                            'notes' => $notes,
                        ], $messageId, $pdo);
                        $crmStored = $leadId > 0;
                    } catch (Throwable $e) {
                        error_log('Stonefellow CRM lead creation failed: ' . $e->getMessage());
                    }
                }

                if (!$crmStored) {
                    create_notification_for_permission(
                        'messages.manage',
                        'contact_message',
                        'New demo request',
                        $name . ' — Book a Demo',
                        url('/admin/messages.php?view=' . $messageId),
                        'contact_message',
                        $messageId
                    );
                }
            } catch (Throwable $e) {
                error_log('Stonefellow demo request save failed: ' . $e->getMessage());
            }
        }

        $recipient = (string)setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'));
        $mailed = false;
        if ((bool)site_config('send_contact_email', false)) {
            $subject = 'Stonefellow — Book a Demo';
            $headers = ['From: ' . $recipient, 'Reply-To: ' . $email, 'Content-Type: text/plain; charset=UTF-8'];
            $mailed = @mail($recipient, $subject, "Name: {$name}\nEmail: {$email}\n\n{$message}", implode("\r\n", $headers));
        }

        if ($stored || $mailed) {
            flash('success', 'Thanks. Your demo request has been received. We will follow up with you soon.');
            redirect(url('/book-demo.php'));
        }
        $error = 'The demo request could not be submitted because the backend is not configured yet. Please use the contact page instead.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Book a Stonefellow demo and find the right AI Studio Assistant setup for your workflow."><title>Book a Demo — Stonefellow</title><link rel="stylesheet" href="<?= e(url('/marketing.css?v=180')) ?>"></head><body>
<header class="site-header"><div class="wrap nav"><a class="brand" href="<?= e(url('/index.php')) ?>"><span class="brand-mark"><span>S</span></span>Stonefellow</a><nav class="nav-links"><a href="<?= e(url('/index.php#product')) ?>">Product</a><a href="<?= e(url('/index.php#roles')) ?>">Solutions</a><a href="<?= e(url('/index.php#resources')) ?>">Resources</a><a href="<?= e(url('/pricing.php')) ?>">Pricing</a><a href="<?= e(url('/book-demo.php')) ?>">Book a demo</a></nav><div class="nav-actions"><a class="nav-login" href="<?= e(url('/login.php')) ?>">Log in</a><a class="btn btn-primary" href="<?= e(url('/signup.php')) ?>">Start free trial</a><button class="menu-btn" id="menuBtn" aria-label="Open menu" aria-controls="mobileMenu" aria-expanded="false">☰</button></div></div><div class="mobile-menu-overlay" id="mobileMenuOverlay" aria-hidden="true"></div><aside class="mobile-menu" id="mobileMenu" aria-hidden="true"><div class="mobile-menu-head"><a class="brand" href="<?= e(url('/index.php')) ?>"><span class="brand-mark"><span>S</span></span>Stonefellow</a><button class="mobile-menu-close" id="menuClose">×</button></div><nav class="mobile-menu-links"><a href="<?= e(url('/index.php#product')) ?>">Product <span>→</span></a><a href="<?= e(url('/index.php#roles')) ?>">Solutions <span>→</span></a><a href="<?= e(url('/pricing.php')) ?>">Pricing <span>→</span></a><a href="<?= e(url('/book-demo.php')) ?>">Book a demo <span>→</span></a></nav><div class="mobile-menu-actions"><a class="btn btn-light" href="<?= e(url('/signup.php')) ?>">Start free trial</a><a class="btn btn-secondary" href="<?= e(url('/login.php')) ?>">Log in</a></div></aside></header>
<main class="demo-shell"><section class="demo-left"><div class="eyebrow">Stonefellow demo</div><h1>See how your studio assistant works.</h1><p>Stonefellow gives music professionals one operating layer for stems, sessions, rights, releases, teams and venue workflows—without using AI to create the music.</p><div class="demo-benefits"><div class="demo-benefit">≋<br>Keep stems, sessions and versions organized</div><div class="demo-benefit">✓<br>Coordinate rights, publishing and releases</div><div class="demo-benefit">◎<br>Give every role the context it needs</div></div><div class="demo-visual"><div class="demo-mini-grid"><div class="demo-mini-card"><b>Velvet Dawn · Mix v3</b><br><br>Vocal ▰▰▰▰▱<br>Drums ▰▰▰▱▱<br>Bass ▰▰▰▰▱<br>Guitar ▰▰▱▱▱</div><div class="demo-mini-card"><b>Assistant tasks</b><br><br>Review split sheet<br><br>Confirm master<br><br>Prepare metadata</div></div></div></section>
<section class="demo-right"><form class="form-wrap" method="post" action="<?= e(url('/book-demo.php')) ?>"><h2>Find the right Stonefellow setup</h2><p class="form-intro">Answer a few questions and we will route your request to the workflow that best matches how you work.</p><?= csrf_field() ?><div style="position:absolute;left:-9999px" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
<?php if ($success): ?><div class="form-alert success"><?= e((string)$success) ?></div><?php endif; ?><?php if ($error): ?><div class="form-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="question"><h3>1. What do you want Stonefellow to help manage?</h3><p class="helper">Select all that apply.</p><div class="option-grid"><?php foreach (['stems'=>'Stems & DAW projects','sessions'=>'Sessions & recording notes','rights'=>'Rights, splits & legal','publishing'=>'Publishing & metadata','release'=>'Release management','venue'=>'Shows, venues & events'] as $value=>$label): ?><label class="check-option"><input type="checkbox" name="workflow[]" value="<?= e($value) ?>" <?= in_array($value,$workflows,true)?'checked':'' ?>> <?= e($label) ?></label><?php endforeach; ?></div></div>
<div class="question"><h3>2. Which role best describes you?</h3><div class="option-grid"><?php foreach (['artist'=>'Artist','producer'=>'Producer','supervisor'=>'Music supervisor','manager'=>'Manager','venue'=>'Venue / event team','legal'=>'Song / legal operations'] as $value=>$label): ?><label class="radio-option"><input type="radio" name="role" value="<?= e($value) ?>" <?= $role===$value?'checked':'' ?>> <?= e($label) ?></label><?php endforeach; ?></div></div>
<div class="question"><h3>3. How large is the team or operation?</h3><div class="option-grid"><?php foreach (['1'=>'Just me','2-5'=>'2–5 people','6-20'=>'6–20 people','21+'=>'21+ people'] as $value=>$label): ?><label class="radio-option"><input type="radio" name="team_size" value="<?= e($value) ?>" <?= $teamSize===$value?'checked':'' ?>> <?= e($label) ?></label><?php endforeach; ?></div></div>
<div class="question"><h3>4. Tell us where to follow up</h3><div class="field-grid"><div class="field"><label for="name">Name</label><input id="name" name="name" maxlength="120" autocomplete="name" required value="<?= e($name) ?>"></div><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?= e($email) ?>"></div><div class="field"><label for="company">Studio / company</label><input id="company" name="company" maxlength="190" value="<?= e($company) ?>"></div><div class="field"><label for="phone">Phone</label><input id="phone" name="phone" maxlength="80" autocomplete="tel" value="<?= e($phone) ?>"></div><div class="field full"><label for="notes">What would you like to see?</label><textarea id="notes" name="notes" maxlength="5000"><?= e($notes) ?></textarea></div></div></div><div class="submit-row"><span class="submit-note">The demo focuses on your existing music and workflow—not creative generation.</span><button class="btn btn-primary" type="submit">Request my demo →</button></div></form></section></main>
<footer class="footer"><div class="wrap"><div class="footer-grid"><div><a class="brand" href="<?= e(url('/index.php')) ?>"><span class="brand-mark"><span>S</span></span>Stonefellow</a><p>AI Studio Assistant for modern music operations.</p></div><div><h4>Product</h4><a href="<?= e(url('/index.php#product')) ?>">Features</a><a href="<?= e(url('/pricing.php')) ?>">Pricing</a></div><div><h4>Solutions</h4><a href="<?= e(url('/index.php#roles')) ?>">Music teams</a></div><div><h4>Resources</h4><a href="<?= e(url('/contact.php')) ?>">Contact</a></div><div><h4>Company</h4><a href="<?= e(url('/privacy.php')) ?>">Privacy</a><a href="<?= e(url('/terms.php')) ?>">Terms</a></div></div><div class="footer-bottom"><span>© 2026 Stonefellow. All rights reserved.</span><span>Built for real music workflows.</span></div></div></footer><script src="<?= e(url('/auth.js?v=180')) ?>"></script></body></html>
