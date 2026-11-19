<?php
declare(strict_types=1);

// Render the production Profile template with an isolated page context. The
// storage/visibility services are exercised separately by profile-canvases-v1.
$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? 'gallery';
$profileCanvasMode = $mode !== 'gallery';
$isOwner = $chatPreview = $mode === 'owner';
$viewer = $isOwner ? ['id'=>1] : null;
$preview = false;
$username = 'fixture-owner';
$displayName = 'Taylor Reed';
$bio = 'Independent designer. Explore my work, tools and ideas.';
$roleLabel = 'Creator';
$avatar = $cover = '';
$profile = ['user_id'=>1,'username'=>$username,'is_public'=>1,'is_active'=>1];
$agent = $mode === 'disabled' ? null : ['display_name'=>'Taylor’s Guide'];
$profileOrbShape = 'orbit';
$agentGreeting = 'Welcome to my studio. How can I help?';
$profileToken = 'FIXTURE_PROFILE_TOKEN';
$conversationNamespace = str_repeat('a', 64);
$profileCanvasesUnavailable = false;
$profileCanvases = [
    ['title'=>'Creative studio','slug'=>'creative-studio','url'=>'/fixture-owner/canvas/creative-studio','revision'=>1],
    ['title'=>'Design notebook','slug'=>'design-notebook','url'=>'/fixture-owner/canvas/design-notebook','revision'=>2],
];
$profileTabs = ['about'=>'About','canvases'=>'Canvases'];
$activeTab = 'about';
$links = $shows = $bookingTypes = $campaigns = $commerceProducts = $productPromotions = $tracks = $albums = $photos = $posts = $merch = [];
$commerceNotice = null;
$checkoutAvailable = false;
$webmcpManifest = [];
$webmcpSessionProof = '';
$webmcpResume = null;
$profileCanvasRelease = $profileCanvases[$mode === 'second' ? 1 : 0];
$profileCanvasNonce = str_repeat('b', 48);
$artifact = [
    'html'=>'<header class="hero"><p class="eyebrow">TAYLOR REED / STUDIO</p><h1>'.($mode === 'second' ? 'Design notebook' : 'Make room for ideas.').'</h1><p>A full-page space for work in progress, helpful tools and fresh perspectives.</p><button id="explore">Explore the collection</button><p id="isolation"></p></header><section class="chapter"><h2>Designed to be explored</h2><p>Your public Canvas can be a portfolio, a useful tool, a campaign page or a personal introduction.</p><p id="response"></p></section><footer>Thanks for visiting the studio.</footer>',
    'css'=>'*{box-sizing:border-box}body{background:#f6f2ea;color:#242b28;font-family:system-ui,sans-serif}.hero{min-height:85vh;padding:100px clamp(24px,7vw,110px) 64px;background:#dce7df}.eyebrow{font-size:12px;letter-spacing:.15em;font-weight:700}h1{max-width:900px;font-size:clamp(42px,8vw,100px);line-height:1.02;letter-spacing:-.06em}p{max-width:56ch;font-size:18px;line-height:1.6}button{border:0;border-radius:30px;padding:16px 24px;background:#253a31;color:white;font-size:16px;cursor:pointer}.chapter{min-height:65vh;padding:72px clamp(24px,7vw,110px)}h2{font-size:clamp(32px,5vw,56px);letter-spacing:-.04em}footer{padding:40px;background:#253a31;color:white}#isolation{font-size:12px}',
    'js'=>"CanvasUI.text('#isolation', typeof document === 'undefined' && typeof parent === 'undefined' && typeof fetch === 'undefined' ? 'Isolated Canvas' : 'Unexpected access'); CanvasUI.on('#explore','click',()=>CanvasUI.text('#response','You opened this Canvas as a complete page.'));",
];
$profileCanvasPayload = json_encode(['artifact'=>$artifact,'sources'=>[],'basePath'=>'','fullPage'=>true],
    JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path): string { return $path; }
function system_agent_name(): string { return 'VP3'; }
function profile_public_url(string $username): string { return '/'.$username; }

$source = file_get_contents($root.'/profile.php');
$start = strpos($source, '<!doctype html>');
if ($start === false) throw new RuntimeException('Production Profile template was not found.');
$template = substr($source, $start);
$template = str_replace('__DIR__', var_export($root, true), $template);
eval('?>'.$template);
