<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$logoUrl = site_logo_url();
if ($logoUrl === '') {
    echo "/* No uploaded system logo; existing text branding remains visible. */\n";
    exit;
}

$encodedLogoUrl = json_encode(
    $logoUrl,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);

echo <<<CSS
/* Generated from the canonical site_logo_path setting. */
.chat-brand{
  display:block;
  flex:0 0 170px;
  width:170px;
  max-width:calc(100% - 48px);
  height:40px;
  min-height:40px;
  overflow:hidden;
  background-image:url({$encodedLogoUrl});
  background-repeat:no-repeat;
  background-position:left center;
  background-size:contain;
  color:transparent !important;
  font-size:0 !important;
  line-height:0 !important;
  text-indent:-9999px;
  white-space:nowrap;
}
CSS;
