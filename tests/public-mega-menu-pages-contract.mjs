import fs from 'node:fs';

const requiredRoutes = [
  'product.php','ai-assistant.php','profile-agent-overview.php','personal-url.php',
  'services.php','transcriptions.php','ai-summary.php','teams.php','calendar-service.php','booking.php','ecommerce.php',
  'homeserver.php','cloud-vs-self-hosted.php','paired-devices.php','openrouter.php','model-choice.php','local-knowledge-overview.php','tools-skills.php',
  'pricing.php','pricing-monthly.php','pricing-weekly.php','pricing-yearly.php','token-packages.php',
  'about.php','about-team.php','mission.php','case-studies.php','testimonials.php','contact.php','social.php'
];

for (const file of requiredRoutes) {
  if (!fs.existsSync(file)) throw new Error(`Missing public route: ${file}`);
}

const index = fs.readFileSync('index.php', 'utf8');
const expectedMenuTargets = [
  '/ai-assistant.php','/profile-agent-overview.php','/personal-url.php','/homeserver.php',
  '/transcriptions.php','/ai-summary.php','/teams.php','/calendar-service.php','/booking.php','/ecommerce.php',
  '/cloud-vs-self-hosted.php','/paired-devices.php','/openrouter.php','/model-choice.php','/local-knowledge-overview.php','/tools-skills.php',
  '/pricing-monthly.php','/pricing-weekly.php','/pricing-yearly.php','/token-packages.php',
  '/about-team.php','/mission.php','/case-studies.php','/testimonials.php','/contact.php','/social.php'
];
for (const target of expectedMenuTargets) {
  if (!index.includes(target)) throw new Error(`Homepage does not link to ${target}`);
}

const megaStart = index.indexOf('<nav class="desktop-nav mega-nav"');
const megaEnd = index.indexOf('</nav>', megaStart);
if (megaStart < 0 || megaEnd < 0) throw new Error('Desktop mega menu markup not found');
const mega = index.slice(megaStart, megaEnd);
for (const oldAnchor of ['#platform','#features','#homeserver']) {
  if (mega.includes(`href="${oldAnchor}"`)) throw new Error(`Mega menu still contains placeholder ${oldAnchor}`);
}

const protectedContracts = {
  'calendar.php': ["includes/user-calendar-v1300.php", "require_permission('account.access')"],
  'local-knowledge.php': ['personal_knowledge.access', "redirect(url('/login.php'))"],
  'profile-agent.php': ["require_permission('account.access')", '$pdo = db();'],
  'team.php': ['artist_workspace_v104_ensure_schema()', "redirect(url('/login.php'))"]
};
for (const [file, needles] of Object.entries(protectedContracts)) {
  const source = fs.readFileSync(file, 'utf8');
  for (const needle of needles) {
    if (!source.includes(needle)) throw new Error(`Protected app route contract changed: ${file} missing ${needle}`);
  }
  if (source.includes('vp3_render_marketing_page(')) throw new Error(`Protected app route was replaced by marketing renderer: ${file}`);
}

const renderer = fs.readFileSync('includes/vp3-marketing-pages.php', 'utf8');
for (const slug of [
  'product','ai-assistant','profile-agent','personal-url','services','ai-summary','calendar','booking','ecommerce',
  'homeserver','cloud-vs-self-hosted','paired-devices','openrouter','model-choice','local-knowledge','tools-skills',
  'pricing-monthly','pricing-weekly','pricing-yearly','token-packages','team','mission','case-studies','testimonials','social'
]) {
  if (!renderer.includes(`'${slug}' => [`)) throw new Error(`Renderer missing page data: ${slug}`);
}

if (!renderer.includes("'/profile-agent.php' => '/profile-agent-overview.php'")) throw new Error('Profile Agent public route remap missing');
if (!renderer.includes("'/calendar.php' => '/calendar-service.php'")) throw new Error('Calendar public route remap missing');
if (!renderer.includes("'/local-knowledge.php' => '/local-knowledge-overview.php'")) throw new Error('Local Knowledge public route remap missing');
if (!renderer.includes("'/team.php' => '/about-team.php'")) throw new Error('Team public route remap missing');

// Public pages must share the homepage-style transparent mega menu.
const publicShell = fs.readFileSync('includes/vp3-public.php', 'utf8');
for (const needle of [
  'function vp3_public_mega_nav',
  'class="mega-nav vp3-public-mega-nav"',
  '/vp3-index-mega-menu.css',
  '/vp3-public-editorial.css'
]) {
  if (!publicShell.includes(needle)) throw new Error(`Shared public mega header contract missing: ${needle}`);
}
if (!publicShell.includes("if (!$compact):")) throw new Error('Compact auth header boundary missing');

// Auth pages use compact brand-only header: no context-specific floating auth CTA should be emitted by the compact branch.
for (const file of ['login.php','signup.php']) {
  const source = fs.readFileSync(file, 'utf8');
  if (!source.includes("'compact'=>true")) throw new Error(`${file} must use compact public header`);
}

// The informational renderer may keep its historical class names, but its CSS must be editorial rows rather than 2x2 colored squares.
const marketingCss = fs.readFileSync('vp3-marketing-pages.css', 'utf8');
if (!marketingCss.includes('.vp3-marketing-feature-grid{display:block')) throw new Error('Marketing pages are not using editorial row layout');
if (!marketingCss.includes('grid-template-columns:minmax(220px,.75fr)')) throw new Error('Editorial marketing row columns missing');
if (marketingCss.includes('.vp3-marketing-feature:nth-child(4n+1)') || marketingCss.includes('.vp3-marketing-feature:nth-child(4n+4)')) {
  throw new Error('Legacy alternating square-tile styling returned');
}

console.log(`Public mega menu/editorial pages contract OK: ${requiredRoutes.length} routes verified.`);
