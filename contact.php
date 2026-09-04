<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
redirect_logged_in_public_page();

$error = '';
$success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        // Honeypot: pretend success to avoid helping bots.
        $success = 'Thanks. Your message has been received.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? 'General Inquiry'));
        $message = trim((string)($_POST['message'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            $error = 'Please enter your name, a valid email address, and a message.';
        } elseif (mb_strlen($name) > 120 || mb_strlen($email) > 190 || mb_strlen($topic) > 80 || mb_strlen($message) > 5000) {
            $error = 'One or more fields are too long.';
        } else {
            $pdo = db();
            $stored = false;

            if ($pdo) {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO contact_messages
                         (name, email, topic, message, ip_address, user_agent, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())'
                    );
                    $stmt->execute([
                        $name,
                        $email,
                        $topic,
                        $message,
                        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    ]);
                    $messageId = (int)$pdo->lastInsertId();
                    $stored = true;

                    create_notification_for_permission(
                        'messages.manage',
                        'contact_message',
                        'New contact message',
                        $name . ' — ' . $topic,
                        url('/admin/messages.php?view=' . $messageId),
                        'contact_message',
                        $messageId
                    );
                } catch (Throwable $e) {
                    error_log('Contact save failed: ' . $e->getMessage());
                }
            }

            $recipient = (string)setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'));
            $mailed = false;

            if ((bool)site_config('send_contact_email', false)) {
                $subject = 'Stonefellow — ' . preg_replace('/[\r\n]+/', ' ', $topic);
                $body = "Name: {$name}\nEmail: {$email}\n\n{$message}";
                $headers = [
                    'From: ' . $recipient,
                    'Reply-To: ' . $email,
                    'Content-Type: text/plain; charset=UTF-8',
                ];
                $mailed = @mail($recipient, $subject, $body, implode("\r\n", $headers));
            }

            if ($stored || $mailed) {
                flash('success', 'Thanks. Your message has been received.');
                redirect(url('/contact.php'));
            }

            $error = 'The message could not be submitted because the backend is not configured yet. You can email Stonefellow directly below.';
        }
    }
}

$pageTitle = 'Stonefellow | Contact';
$pageDescription = 'Contact Stonefellow for booking, press, collaborations and general inquiries.';
$activePage = 'contact';
require __DIR__ . '/includes/header.php';
?>
<main>
  <section class="page-hero">
    <img class="hero-image" src="<?= e(url('/images/stonefellow-studio.png')) ?>" alt="Stonefellow in the recording studio">
    <div class="hero-overlay"></div>
    <div class="page-hero-content">
      <p class="eyebrow">Connect</p>
      <h1>Contact</h1>
      <p class="subhead">Booking, collaboration, press and general Stonefellow inquiries.</p>
    </div>
  </section>

  <section class="section">
    <div class="wrap contact-grid">
      <aside class="contact-card">
        <h3>Stonefellow</h3>
        <p>For booking, music, press, collaborations or general questions, use the form or email directly.</p>
        <a class="contact-email" href="mailto:<?= e(setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'))) ?>">
          <?= e(setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'))) ?>
        </a>
      </aside>

      <div class="contact-card">
        <?php if ($success): ?><p style="color:#d8c6ad"><?= e($success) ?></p><?php endif; ?>
        <?php if ($error): ?><p style="color:#e78a7d"><?= e($error) ?></p><?php endif; ?>

        <form method="post" action="<?= e(url('/contact.php')) ?>">
          <?= csrf_field() ?>
          <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="name">Name</label>
              <input id="name" name="name" type="text" autocomplete="name" maxlength="120" required value="<?= e($_POST['name'] ?? '') ?>">
            </div>
            <div class="field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" autocomplete="email" maxlength="190" required value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="field full">
              <label for="topic">Inquiry Type</label>
              <select id="topic" name="topic">
                <?php foreach (['General Inquiry','Booking','Press / Media','Collaboration','Music / Licensing'] as $option): ?>
                  <option <?= (($_POST['topic'] ?? '') === $option) ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field full">
              <label for="message">Message</label>
              <textarea id="message" name="message" maxlength="5000" required><?= e($_POST['message'] ?? '') ?></textarea>
            </div>
            <div class="field full">
              <button class="form-submit" type="submit">Send Message</button>
              <p class="form-note">Messages are saved securely to the Stonefellow admin inbox when the database is configured.</p>
            </div>
          </div>
        </form>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
