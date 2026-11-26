<?php
declare(strict_types=1);

/** Presentation-only HTML. Never use this field for AI context, logs, or Chat history. */
function agent_email_display_css_v1(string $css): string
{
    if (strlen($css) > 65536 || !mb_check_encoding($css, 'UTF-8')
        || preg_match('/[<\\\\\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $css)) return '';
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
    $css = preg_replace('~@(?:import|namespace|charset)\b[^;{}]*(?:;|$)|@font-face\s*\{[^{}]*\}~is', '', $css) ?? '';
    // Keep layout, typography, colors, and media queries. Drop all CSS network loads.
    // Rejecting escapes before comment normalization keeps obfuscated tokens
    // from becoming new URL functions after this pass. '<' is rejected rather
    // than stripped; removing it could synthesize a previously hidden url().
    $css = preg_replace('~(?:url|src|attr|image|(?:-webkit-)?image-set|(?:-webkit-)?cross-fade)\s*\([^;{}]*\)~is', 'none', $css) ?? '';
    $css = preg_replace('~(?:behavior|-moz-binding)\s*:[^;{}]*(?:;|$)|[^;{}]*expression\s*\([^;{}]*(?:;|$)~is', '', $css) ?? '';
    return $css;
}

function agent_email_display_url_v1(string $url, bool $image = false): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return '';
    if (!$image && preg_match('/\Amailto:[^<>"\s]+\z/Di', $url)) return $url;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return '';
    return $url;
}

/** Keep bounded raster data images; SVG and other active document formats are never embedded. */
function agent_email_display_data_image_v1(string $url): string
{
    if (strlen($url) > 2800000 || !preg_match('~\Adata:(image/(?:png|jpeg|gif|webp));base64,([a-zA-Z0-9+/=\r\n]+)\z~D', $url, $match)) return '';
    $bytes = base64_decode($match[2], true);
    if ($bytes === false || strlen($bytes) > 2097152) return '';
    $image = @getimagesizefromstring($bytes);
    if (!$image || ($image['mime'] ?? '') !== $match[1] || $image[0] < 1 || $image[1] < 1
        || $image[0] > 16000 || $image[1] > 16000 || $image[0] * $image[1] > 40000000) return '';
    return 'data:'.$match[1].';base64,'.base64_encode($bytes);
}

/** Sanitize before returning private email markup; the browser adds a second sandbox/CSP boundary. */
function agent_email_display_v1(string $html): ?array
{
    if (trim($html) === '' || !class_exists(DOMDocument::class) || !mb_check_encoding($html, 'UTF-8')) return null;
    // Oversized messages retain their ordinary text view, without truncating into broken markup.
    if (strlen($html) > 262144) return ['version'=>1, 'html'=>'', 'remote_images'=>0, 'truncated'=>true];
    $document = new DOMDocument('1.0', 'UTF-8');
    $prior = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
    }
    if (!$loaded) return null;
    $allowed = array_fill_keys(explode(' ', 'html head body title style div p span a img table thead tbody tfoot tr td th caption colgroup col h1 h2 h3 h4 h5 h6 pre code blockquote br hr b strong i em u s del ins small sup sub ul ol li dl dt dd font center address section article header footer main figure figcaption'), true);
    $drop = array_fill_keys(explode(' ', 'script noscript template form input button textarea select option iframe frame frameset object embed applet link meta base svg math video audio source track canvas xmp plaintext xml'), true);
    $remoteImages = 0;
    $count = 0;
    $styleBytes = 0;
    $clean = function(DOMNode $node, int $depth) use (&$clean, $document, $allowed, $drop, &$remoteImages, &$count, &$styleBytes): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (++$count > 12000 || $depth > 80) { $child->parentNode?->removeChild($child); continue; }
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction || $child instanceof DOMDocumentType) {
                $child->parentNode?->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (isset($drop[$tag]) || str_contains($tag, ':')) { $child->parentNode?->removeChild($child); continue; }
            if ($tag === 'style') {
                $css = agent_email_display_css_v1($child->textContent);
                $styleBytes += strlen($css);
                if ($css === '' || $styleBytes > 65536) { $child->parentNode?->removeChild($child); continue; }
                while ($child->attributes->length) $child->removeAttributeNode($child->attributes->item(0));
                $child->textContent = $css;
                continue;
            }
            $source = $tag === 'img' ? $child->getAttribute('src') : '';
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                $keep = false;
                if (in_array($name, ['class','id','title','lang','alt'], true)) $keep = strlen($value) <= 1000;
                elseif ($name === 'dir') $keep = in_array(strtolower($value), ['ltr','rtl','auto'], true);
                elseif ($name === 'style') {
                    $value = agent_email_display_css_v1($value);
                    $styleBytes += strlen($value);
                    $keep = $value !== '' && $styleBytes <= 65536;
                } elseif ($tag === 'a' && $name === 'href') { $value = agent_email_display_url_v1($value); $keep = $value !== ''; }
                elseif (in_array($name, ['align','valign','bgcolor','color','face'], true)) $keep = strlen($value) <= 160 && !preg_match('/[<>\\\\\x00-\x1f]/', $value);
                elseif (in_array($name, ['width','height','border','cellpadding','cellspacing','colspan','rowspan','size','start'], true)) {
                    $keep = preg_match('/\A\d{1,5}%?\z/D', $value) === 1 && (int)$value <= 20000;
                }
                if (!$keep || str_starts_with($name, 'on')) $child->removeAttributeNode($attribute);
                elseif ($value !== $attribute->value) $child->setAttribute($name, $value);
            }
            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
                $child->setAttribute('referrerpolicy', 'no-referrer');
            }
            if ($tag === 'img') {
                $data = agent_email_display_data_image_v1($source);
                if ($data !== '') $child->setAttribute('src', $data);
                elseif (($remote = agent_email_display_url_v1($source, true)) !== '') {
                    $child->setAttribute('data-vp3-remote-src', $remote);
                    $remoteImages++;
                }
                if (!$child->hasAttribute('alt')) $child->setAttribute('alt', $source !== '' ? 'Email image' : '');
                $child->setAttribute('referrerpolicy', 'no-referrer');
            }
            $clean($child, $depth + 1);
            if (!isset($allowed[$tag]) && $child->parentNode) {
                while ($child->firstChild) $child->parentNode->insertBefore($child->firstChild, $child);
                $child->parentNode->removeChild($child);
            }
        }
    };
    $clean($document, 0);
    $output = $document->saveHTML();
    if (!is_string($output) || strlen($output) > 350000) return null;
    return ['version'=>1, 'html'=>$output, 'remote_images'=>$remoteImages, 'truncated'=>false];
}
