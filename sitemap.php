<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$paths = [
    '/index.php',
    '/product.php',
    '/ai-assistant.php',
    '/personal-url.php',
    '/profile-agent-overview.php',
    '/chrome-extension.php',
    '/services.php',
    '/transcriptions.php',
    '/ai-summary.php',
    '/annotations.php',
    '/teams.php',
    '/video-meetings.php',
    '/calendar-service.php',
    '/booking.php',
    '/ecommerce.php',
    '/agent-analytics.php',
    '/homeserver.php',
    '/cloud-vs-self-hosted.php',
    '/paired-devices.php',
    '/openrouter.php',
    '/model-choice.php',
    '/local-knowledge-overview.php',
    '/tools-skills.php',
    '/pricing.php',
    '/pricing-monthly.php',
    '/pricing-weekly.php',
    '/pricing-yearly.php',
    '/token-packages.php',
    '/about.php',
    '/about-team.php',
    '/mission.php',
    '/case-studies.php',
    '/testimonials.php',
    '/social.php',
    '/contact.php',
    '/privacy.php',
    '/terms.php',
];

$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($paths as $path) {
    echo "  <url><loc>".$xml(url($path))."</loc></url>\n";
}
echo "</urlset>\n";
