<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/agent-email-display-v1.php';
require dirname(__DIR__).'/includes/agent-connection-gmail-v1.php';

$checks = 0;
function display_check(bool $pass, string $label): void
{
    if (!$pass) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function display_dom(string $html): array
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $prior = libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    return [$document, new DOMXPath($document)];
}
function display_png(): string
{
    $chunk = static fn(string $name,string $value): string => pack('N',strlen($value)).$name.$value.hash('crc32b',$name.$value,true);
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,6,0,0,0))
        .$chunk('IDAT',gzcompress("\0\xff\0\0\xff")).$chunk('IEND','');
}

$raster = 'data:image/png;base64,'.base64_encode(display_png());
$source = '<!DOCTYPE html><html><head><title>Résumé &amp; café</title><style>'
    .'@import "https://tracker.example/font.css"; @font-face {font-family:Brand;src:url(https://tracker.example/font.woff)}'
    .'.card > h1 {color:#8b2252;font-family:Georgia,serif} '
    .'@media screen and (max-width:600px){.card{width:100%;padding:12px}}'
    .'</style></head><body onload="window.parent.evil=1" background="https://tracker.example/background">'
    .'<table class="card" width="600" cellpadding="12" cellspacing="0" bgcolor="#f8f1ef"><tbody><tr>'
    .'<td align="center" style="background-color:#fff;color:#333;border:1px solid #ccc"><h1>Résumé — café 你好 🎉</h1>'
    .'<p style="font-size:16px;line-height:1.5">Hello <strong>David</strong> &amp; welcome.</p>'
    .'<a id="valid-link" href="https://example.test/path?x=1&amp;y=2" target="_parent" rel="opener" ping="https://tracker.example/ping">Read more</a>'
    .'<a id="mailto" href="mailto:hello@example.test?subject=Hello">Email</a>'
    .'<a id="script-link" href="java&#x0A;script:alert(1)">Bad link</a>'
    .'<img id="remote" src="https://images.example/photo.png" srcset="https://tracker.example/2x.png 2x" onerror="alert(1)" data-vp3-remote-src="https://forged.example/image">'
    .'<img id="forged" data-vp3-remote-src="https://forged.example/image" data-vp3-loaded="true">'
    .'<img id="embedded" src="'.$raster.'" alt="Red dot">'
    .'<script>window.parent.evil=1</script><form action="https://tracker.example/post"><input name="secret" value="x"></form>'
    .'<iframe srcdoc="<script>alert(1)</script>"></iframe><svg><a href="javascript:alert(1)">Bad SVG</a></svg>'
    .'<meta http-equiv="refresh" content="0;url=https://tracker.example"><base href="https://tracker.example">'
    .'</td></tr></tbody></table></body></html>';
$packet = agent_email_display_v1($source);
display_check(is_array($packet) && $packet['version'] === 1 && !$packet['truncated'] && $packet['remote_images'] === 1,
    'private presentation packet counts only genuine deferred remote images');
[$document,$xpath] = display_dom($packet['html']);
display_check(str_contains($document->textContent, 'Résumé — café 你好 🎉')
    && str_contains($document->textContent, 'Hello David & welcome.'), 'HTML serialization preserves Unicode and entities');
display_check($xpath->query('//table[@cellpadding="12"][@bgcolor="#f8f1ef"]//td[@align="center"]')->length === 1
    && str_contains($xpath->query('//td')->item(0)->getAttribute('style'), 'background-color:#fff'),
    'email tables, spacing, colors and inline styling survive sanitization');
$css = $xpath->query('//style')->item(0)->textContent;
display_check(str_contains($css, '.card > h1') && str_contains($css, '@media screen and (max-width:600px)')
    && str_contains($css, 'font-family:Georgia,serif') && !str_contains($css, 'tracker.example'),
    'style blocks preserve child selectors, fonts and responsive media rules without external loads');
$link = $xpath->query('//*[@id="valid-link"]')->item(0);
display_check($link->getAttribute('href') === 'https://example.test/path?x=1&y=2'
    && $link->getAttribute('target') === '_blank' && $link->getAttribute('rel') === 'noopener noreferrer'
    && $link->getAttribute('referrerpolicy') === 'no-referrer' && !$link->hasAttribute('ping'),
    'links retain their destination with isolated navigation and no tracking ping');
display_check($xpath->query('//*[@id="mailto"]')->item(0)->getAttribute('href') === 'mailto:hello@example.test?subject=Hello'
    && !$xpath->query('//*[@id="script-link"]')->item(0)->hasAttribute('href'),
    'mailto is retained while entity-obfuscated executable links are removed');
$remote = $xpath->query('//*[@id="remote"]')->item(0);
display_check(!$remote->hasAttribute('src') && !$remote->hasAttribute('srcset') && !$remote->hasAttribute('onerror')
    && $remote->getAttribute('data-vp3-remote-src') === 'https://images.example/photo.png',
    'remote image URLs are inert markers until the reader explicitly loads images');
$forged = $xpath->query('//*[@id="forged"]')->item(0);
display_check(!$forged->hasAttribute('data-vp3-remote-src') && !$forged->hasAttribute('data-vp3-loaded'),
    'sender-forged VP3 presentation attributes cannot impersonate sanitized images');
display_check($xpath->query('//*[@id="embedded"]')->item(0)->getAttribute('src') === $raster,
    'bounded embedded raster data images remain available without a network request');
display_check($xpath->query('//script|//form|//input|//iframe|//svg|//meta|//base|//link|//@onload|//@onerror|//@srcdoc')->length === 0,
    'scripts, forms, active documents, navigation directives and handlers are removed');

foreach ([
    'color:red;background:u\\72l(https://tracker.example/x)',
    '@im\\port "https://tracker.example/x";',
    'width:e\\78pression(alert(1))',
    'background:u<r>l(https://tracker.example/x)',
    'color:red;'."\0".'background:url(https://tracker.example/x)',
] as $index=>$attack) {
    display_check(agent_email_display_css_v1($attack) === '', 'escaped or reconstructed CSS attack '.($index+1).' is rejected before normalization');
}
foreach ([
    '.x{background:u/**/rl(https://tracker.example/x);color:red}',
    '@im/**/port "https://tracker.example/x";.x{color:red}',
    '.x{background:image-set("https://tracker.example/a" 1x,"https://tracker.example/b" 2x);color:red}',
    '.x{background:-webkit-image-set("https://tracker.example/a" 1x);color:red}',
    '.x{background:image("https://tracker.example/a");color:red}',
    '.x{background:src("https://tracker.example/a");color:red}',
    '.x{background:attr(data-vp3-remote-src url);color:red}',
    '.x{background:cross-fade("https://tracker.example/a","https://tracker.example/b",50%);color:red}',
] as $index=>$attack) {
    $clean = agent_email_display_css_v1($attack);
    display_check(!str_contains($clean, 'tracker.example') && !str_contains($clean, 'attr(') && str_contains($clean, 'color:red'),
        'network-capable CSS form '.($index+1).' is removed while ordinary declarations remain');
}
$legacy = agent_email_display_css_v1('.x{behavior:url(https://tracker.example/x);-moz-binding:url(https://tracker.example/x);width:expression(alert(1));color:blue}');
display_check(!preg_match('/behavior|-moz-binding|expression|tracker\.example/i', $legacy) && str_contains($legacy, 'color:blue'),
    'legacy active CSS declarations cannot survive sanitization');
display_check(agent_email_display_css_v1(str_repeat('x', 65537)) === '', 'CSS byte limits are enforced');

foreach ([
    '<math><mtext><table><mglyph><style><!--</style><img src="https://tracker.example/x" onerror="alert(1)">',
    '<svg><style><a id="</style><img src=x onerror=alert(1)>">bad</a></style></svg><p>Safe text</p>',
    '<noscript><p title="</noscript><img src=x onerror=alert(1)>">bad</p></noscript>',
    '<template><img src=x onerror=alert(1)></template><xmp><img src=x onerror=alert(1)></xmp>',
    '<div><style>body{background:url(https://tracker.example/x)}</style><img sRc="javascript:alert(1)" ONERROR="alert(1)"></div>',
    '<!--[if IE]><script>alert(1)</script><![endif]--><?instruction test?><custom onclick="alert(1)"><strong>Safe</strong></custom>',
] as $index=>$attack) {
    $clean = agent_email_display_v1($attack);
    display_check(is_array($clean), 'HTML parser attack '.($index+1).' yields a bounded presentation');
    [$parsed,$query] = display_dom($clean['html']);
    $unsafe = $query->query('//script|//svg|//math|//form|//template|//noscript|//xmp|//iframe|//object|//embed|//base|//meta|//@onerror|//@onclick|//@onload|//@srcset|//@srcdoc');
    display_check($unsafe->length === 0, 'HTML parser attack '.($index+1).' remains inert after serialization and reparse');
    foreach ($query->query('//*[@src]') as $element) display_check(str_starts_with($element->getAttribute('src'),'data:image/'),
        'reparsed image sources contain only verified raster data');
}

display_check(agent_email_display_data_image_v1('data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"></svg>')) === ''
    && agent_email_display_data_image_v1('data:image/png;base64,'.base64_encode('<html>fake png</html>')) === '',
    'embedded SVG and raster MIME spoofing are rejected');
display_check(agent_email_display_url_v1('https://user:pass@example.test') === ''
    && agent_email_display_url_v1('//example.test/x') === ''
    && agent_email_display_url_v1('javascript:alert(1)') === ''
    && agent_email_display_url_v1("https://example.test\n/x") === '', 'URL schemes, credentials and control characters are constrained');
$oversize = agent_email_display_v1(str_repeat('x',262145));
display_check($oversize === ['version'=>1,'html'=>'','remote_images'=>0,'truncated'=>true],
    'oversized HTML produces an explicit text fallback rather than partial markup');
display_check(agent_email_display_v1("bad\xffencoding") === null && agent_email_display_v1('   ') === null,
    'invalid UTF-8 and empty markup do not create a presentation frame');
$depth = agent_email_display_v1(str_repeat('<div>',100).'Text'.str_repeat('</div>',100));
display_check(is_array($depth) && substr_count($depth['html'],'<div>') <= 80, 'nested HTML depth is bounded');
$many = agent_email_display_v1(str_repeat('<span>x</span>',13000));
display_check(is_array($many) && substr_count($many['html'],'<span>') <= 12000, 'DOM node work is bounded');

$encode = static fn(string $text): string => rtrim(strtr(base64_encode($text),'+/','-_'),'=');
$message = ['id'=>'display-message','threadId'=>'display-thread','labelIds'=>['INBOX'],'snippet'=>'Snippet',
    'payload'=>['mimeType'=>'multipart/alternative','headers'=>[['name'=>'From','value'=>'Sender <sender@example.test>'],
        ['name'=>'Subject','value'=>'Résumé']],'parts'=>[
            ['mimeType'=>'text/plain','body'=>['data'=>$encode("Plain résumé\nSecond line")]],
            ['mimeType'=>'text/html','body'=>['data'=>$encode('<html><head><style>p{color:red}</style></head><body><p>Styled résumé</p></body></html>')]],
            ['mimeType'=>'text/html','filename'=>'untrusted-attachment.html','body'=>['data'=>$encode('<p>Attached private document</p>')]],
            ['mimeType'=>'message/rfc822','parts'=>[['mimeType'=>'text/html','body'=>['data'=>$encode('<p>Forwarded attachment</p>')]]]],
        ]]];
$plain = agent_connection_gmail_read_message_v1($message);
$display = agent_connection_gmail_read_message_v1($message,true);
display_check(!array_key_exists('display',$plain) && $plain['body'] === "Plain résumé\nSecond line",
    'default provider reads remain text-only for AI, Chat history and worker compatibility');
$packet = $display['display']; unset($display['display']);
display_check($display === $plain && str_contains(html_entity_decode($packet['html'],ENT_QUOTES|ENT_HTML5,'UTF-8'),'Styled résumé')
    && !str_contains($packet['html'],'Attached private document') && !str_contains($packet['html'],'Forwarded attachment'),
    'display opt-in changes only the presentation field and excludes HTML/rfc822 attachments');
$read = agent_connection_gmail_read_v1('fixture-access','display-message',static fn()=>['status'=>200,'json'=>$message],true);
display_check(isset($read['display']) && $read['body'] === $plain['body'], 'exact provider read forwards explicit presentation opt-in');
$withoutHtml = $message;
$withoutHtml['payload']['parts'] = [$message['payload']['parts'][0]];
display_check(agent_connection_gmail_read_message_v1($withoutHtml,true) === agent_connection_gmail_read_message_v1($withoutHtml),
    'plain-text mail stays unchanged even in the styled reader');
$htmlOnly = $message;
$htmlOnly['payload']['parts'] = [$message['payload']['parts'][1]];
display_check(agent_connection_gmail_read_message_v1($htmlOnly)['body'] === 'Styled résumé'
    && !isset(agent_connection_gmail_read_message_v1($htmlOnly)['display']), 'HTML-only mail still gives the existing plain-text fallback to ordinary callers');
echo 'PASS '.$checks." email presentation checks.\n";
