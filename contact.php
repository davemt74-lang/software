<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

$error = '';
$success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        $success = 'Thanks. Your message has been received.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? 'General Inquiry'));
        $message = trim((string)($_POST['message'] ?? ''));
        $allowedTopics = ['General Inquiry','Account / Support','Teams / Organization','Demo Request','Privacy / Data'];
        if (!in_array($topic, $allowedTopics, true)) $topic = 'General Inquiry';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            $error = 'Please enter your name, a valid email address, and a message.';
        } elseif (mb_strlen($name) > 120 || mb_strlen($email) > 190 || mb_strlen($topic) > 80 || mb_strlen($message) > 5000) {
            $error = 'One or more fields are too long.';
        } else {
            $pdo = db();
            $stored = false;
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare('INSERT INTO contact_messages (name,email,topic,message,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,NOW())');
                    $stmt->execute([$name,$email,$topic,$message,substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500)]);
                    $messageId = (int)$pdo->lastInsertId();
                    $stored = true;
                    create_notification_for_permission('messages.manage','contact_message','New contact message',$name . ' — ' . $topic,url('/admin/messages.php?view=' . $messageId),'contact_message',$messageId);
                } catch (Throwable $e) {
                    error_log('VP3 contact save failed: ' . $e->getMessage());
                }
            }

            $recipient = (string)setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'));
            $mailed = false;
            if ((bool)site_config('send_contact_email', false)) {
                $subject = 'VP3 — ' . preg_replace('/[\r\n]+/', ' ', $topic);
                $body = "Name: {$name}\nEmail: {$email}\n\n{$message}";
                $headers = ['From: ' . $recipient,'Reply-To: ' . $email,'Content-Type: text/plain; charset=UTF-8'];
                $mailed = @mail($recipient,$subject,$body,implode("\r\n",$headers));
            }

            if ($stored || $mailed) {
                flash('success','Thanks. Your message has been received.');
                redirect(url('/contact.php'));
            }
            $error = 'The message could not be submitted because contact delivery is not configured yet. Please try again later.';
        }
    }
}

vp3_public_header('Contact — VP3', 'Contact VP3 for support, teams, demos, privacy, or general questions.');
?>
<section class="vp3-public-hero"><div class="vp3-kicker">Connect with VP3</div><h1>How can we help?</h1><p>Questions about your account, a team setup, a demo, privacy, or the product itself can all start here.</p></section>
<main><section class="vp3-section"><div class="vp3-wrap vp3-contact-grid">
  <aside class="vp3-card"><div class="vp3-kicker">Contact</div><h2>Talk to the VP3 team.</h2><p>Use the form for product questions, account support, team and organization inquiries, demos, or privacy requests.</p><p><a class="vp3-btn" href="<?= e(url('/book-demo.php')) ?>">Book a demo →</a></p></aside>
  <div class="vp3-card">
    <?php if ($success): ?><div class="vp3-alert success"><?= e((string)$success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/contact.php')) ?>">
      <?= csrf_field() ?><div style="position:absolute;left:-9999px" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
      <div class="vp3-form-grid">
        <div class="vp3-field"><label for="name">Name</label><input id="name" name="name" type="text" autocomplete="name" maxlength="120" required value="<?= e($_POST['name'] ?? '') ?>"></div>
        <div class="vp3-field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" maxlength="190" required value="<?= e($_POST['email'] ?? '') ?>"></div>
        <div class="vp3-field full"><label for="topic">Inquiry type</label><select id="topic" name="topic"><?php foreach (['General Inquiry','Account / Support','Teams / Organization','Demo Request','Privacy / Data'] as $option): ?><option <?= (($_POST['topic'] ?? '') === $option) ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
        <div class="vp3-field full"><label for="message">Message</label><textarea id="message" name="message" maxlength="5000" required><?= e($_POST['message'] ?? '') ?></textarea></div>
        <div class="vp3-field full"><button class="vp3-btn primary" type="submit">Send Message →</button><p class="vp3-form-note">Messages are stored in the private admin inbox when the database is configured.</p></div>
      </div>
    </form>
  </div>
</div></section></main>
<?php vp3_public_footer(); ?>
