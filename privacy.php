<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
$contactEmail = (string)setting('contact_email', (string)site_config('email', 'stonefellow74@gmail.com'));
vp3_public_header('Privacy Policy — VP3', 'How VP3 handles account, assistant, knowledge, workflow, and service information.');
?>
<section class="vp3-public-hero"><div class="vp3-kicker">VP3 legal</div><h1>Privacy Policy</h1><p>How VP3 handles account, assistant, knowledge, workflow, and service information.</p></section>
<main class="vp3-legal-wrap"><span class="vp3-updated">Last updated August 31, 2026</span>
<h2>1. Information we collect</h2><p>VP3 may collect information you provide directly, including account details, contact and demo-request information, profile details, and content you choose to upload, record, connect, or enter. We may also collect technical information needed to operate and secure the service, such as IP address, browser or device information, timestamps, and service activity.</p>
<h2>2. How we use information</h2><p>We use information to provide and secure VP3, authenticate accounts, operate your assistant, organize your knowledge and workflow data, respond to support and demo requests, improve reliability, prevent abuse, and communicate about the service.</p>
<h2>3. Files, recordings, knowledge and workspace content</h2><p>You retain ownership of the recordings, transcripts, notes, files, profile content, documents, knowledge, and other content you submit. VP3 processes that content only as needed to provide the features you request, subject to these terms and any additional agreement that applies to your account.</p>
<h2>4. AI features</h2><p>VP3 may use AI services to analyze, organize, transcribe, summarize, retrieve, or assist with workflows around content you provide. VP3 does not claim ownership of your content. Third-party AI providers, when enabled, may process limited data necessary to fulfill the requested feature.</p>
<h2>5. Sharing</h2><p>We do not sell your personal information. We may share information with service providers that help us host, secure, communicate, process requests, or operate VP3; when required by law; or as part of a business transaction subject to appropriate safeguards.</p>
<h2>6. Public profiles, teams and sharing controls</h2><p>VP3 may let you publish selected profile information, enable a Profile Agent or chat widget, share knowledge, or collaborate with a team. Information is exposed through those features only according to the settings, permissions, and sharing actions available to the account.</p>
<h2>7. Data retention and deletion</h2><p>We retain information for as long as needed to provide the service, meet legal or security obligations, resolve disputes, and enforce agreements. Account owners may contact us to request access, correction, or deletion where applicable.</p>
<h2>8. Security</h2><p>We use reasonable administrative and technical safeguards intended to protect account and workspace information. No internet service can guarantee absolute security, so users should also protect passwords and account access.</p>
<h2>9. Cookies and local storage</h2><p>VP3 may use cookies, sessions, and browser storage required for authentication, preferences, security, and core product functionality.</p>
<h2>10. Children</h2><p>VP3 is not directed to children under 13, and we do not knowingly collect personal information from children under 13.</p>
<h2>11. Changes and contact</h2><p>We may update this Privacy Policy as VP3 evolves. Material changes will be reflected by an updated date on this page. Questions or privacy requests can be sent to <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>.</p>
</main>
<?php vp3_public_footer(); ?>
