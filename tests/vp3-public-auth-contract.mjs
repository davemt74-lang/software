import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const shell = read('includes/vp3-public.php');
const css = read('vp3-public.css');
const navCss = read('vp3-public-nav.css');
const auth = read('includes/auth.php');
const login = read('login.php');
const signup = read('signup.php');
const forgot = read('forgot-password.php');
const reset = read('reset-password.php');
const pricing = read('pricing.php');
const about = read('about.php');
const contact = read('contact.php');
const privacy = read('privacy.php');
const terms = read('terms.php');
const demo = read('book-demo.php');
const setup = read('setup.php');
const upgrade = read('upgrade.php');
const shows = read('shows.php');
const configExample = read('config-example.php');
const resetSql = read('sql/vp3-password-reset.sql');

assert.match(shell, /function vp3_public_header/, 'VP3 must own one canonical public header');
assert.match(shell, /function vp3_public_footer/, 'VP3 must own one canonical public footer');
assert.match(shell, />VP3</, 'canonical public shell must render VP3 branding');
assert.match(shell, /index\.php#features/, 'public shell must link to the canonical VP3 feature section');
assert.match(shell, /vp3-public-mobile-menu/, 'canonical public shell must expose responsive navigation');
assert.match(shell, /Open VP3/, 'authenticated visitors must get a product entry action instead of Sign in');
assert.match(navCss, /@media\(max-width:980px\)[\s\S]*vp3-public-mobile-menu/, 'responsive public navigation must activate below the desktop breakpoint');
assert.match(css, /\.vp3-auth-shell/, 'auth surfaces must share the VP3 design system');
assert.match(css, /vp3-mountain-bg\.svg/, 'public/auth system must reuse the VP3 mountain visual');

for (const [name, source] of Object.entries({login,signup,forgot,reset,pricing,about,contact,privacy,terms,demo,upgrade})) {
  assert.match(source, /includes\/vp3-public\.php/, `${name} must use the canonical VP3 public shell`);
  assert.doesNotMatch(source, /<span>Stonefellow<\/span>|>Stonefellow<\/a>|Sign in to Stonefellow|Create my Stonefellow account/, `${name} must not render the old public Stonefellow brand`);
}

assert.match(login, /forgot-password\.php/, 'Sign in must link to real password recovery');
assert.doesNotMatch(login, /Need help signing in\?[\s\S]*contact\.php/, 'Sign in must not use Contact as a fake lost-password flow');
assert.match(signup, /verify_csrf\(\)/, 'Signup must retain CSRF protection');
assert.match(signup, /password_hash\(\$password, PASSWORD_DEFAULT\)/, 'Signup must retain strong password hashing');
assert.match(signup, /accept_terms/, 'Signup must retain explicit legal acceptance');
assert.match(signup, /subscription_assign_default_trial\(\$userId\)/, 'signup must automatically assign the configured default trial');
assert.match(signup, /\$_SESSION\['subscription_onboarding'\]\s*=\s*1/, 'signup must hand the new account into package-aware onboarding');
assert.match(signup, /\$insert->execute\(\[\$email, password_hash\(\$password, PASSWORD_DEFAULT\), \$displayName, 'fan'\]\)/, 'signup must create one neutral compatibility identity before package assignment');
assert.doesNotMatch(signup, /signup_role_interest|How will you use VP3\?|\['artist','producer','supervisor','manager'\]/, 'public signup must not self-assign Artist or contextual Team roles');

assert.match(auth, /CREATE TABLE IF NOT EXISTS password_reset_tokens/, 'password recovery must have a canonical schema helper');
assert.match(auth, /random_bytes\(32\)/, 'reset tokens must use cryptographically secure randomness');
assert.match(auth, /hash\('sha256', \$token\)/, 'raw reset tokens must not be stored');
assert.match(auth, /INTERVAL 60 MINUTE/, 'reset links must expire');
assert.match(auth, /INTERVAL 5 MINUTE/, 'password-reset requests must have a database-backed per-account cooldown');
assert.match(auth, /created_at>=DATE_SUB\(NOW\(\),INTERVAL 5 MINUTE\)/, 'cooldown must be enforced from durable reset state');
assert.match(auth, /function password_reset_absolute_url/, 'password-reset email must build an absolute public URL');
assert.match(auth, /site_config\('base_url', ''\)/, 'password-reset origin must support explicit trusted configuration');
assert.match(auth, /\$_SERVER\['SERVER_NAME'\]/, 'fallback reset origin must use the server-configured name');
assert.doesNotMatch(auth, /HTTP_HOST/, 'password-reset links must not trust the request Host header');
assert.match(auth, /\$consume->rowCount\(\) !== 1/, 'single-use reset consumption must be atomic');
assert.match(auth, /WHERE id=\? AND user_id=\? AND token_hash=\? AND used_at IS NULL AND expires_at>NOW\(\)/, 'reset completion must revalidate the token during atomic consumption');
assert.match(auth, /password_hash\(\$password, PASSWORD_DEFAULT\)/, 'reset completion must hash the replacement password');
assert.match(forgot, /If an active VP3 account matches that email/, 'forgot-password response must avoid account enumeration');
assert.match(reset, /noindex,nofollow/, 'token-bearing reset page must not be indexed');
assert.match(resetSql, /CREATE TABLE IF NOT EXISTS password_reset_tokens/, 'manual deploy SQL must include the password-reset table');
assert.match(upgrade, /password_reset_ensure_schema\(\)/, 'existing installs must receive password recovery schema through upgrade');
assert.match(upgrade, /vp3_public_header\(/, 'database upgrade must not fall back to the legacy Stonefellow header');
assert.match(upgrade, /vp3_public_footer\(\)/, 'database upgrade must close with the VP3 shell');
assert.doesNotMatch(upgrade, /includes\/header\.php|includes\/footer\.php/, 'database upgrade must not render the legacy site shell');
assert.match(setup, /password_reset_ensure_schema\(\)/, 'fresh installs must receive password recovery schema');
assert.match(configExample, /'name' => 'VP3'/, 'fresh configuration must present the public product as VP3');
assert.match(configExample, /'email' => ''/, 'fresh VP3 configuration must not ship a legacy personal contact address');
assert.match(configExample, /'base_url' => ''/, 'fresh configuration must expose a trusted public origin for recovery links');
assert.match(configExample, /'send_password_reset_email' => false/, 'password-reset email configuration must be explicit');
assert.match(configExample, /dbname=vp3/, 'fresh database example must use VP3 naming');
assert.match(configExample, /'user' => 'vp3_user'/, 'fresh database user example must use VP3 naming');

assert.match(pricing, /subscription_packages\(true\)/, 'public pricing must read the live public package catalog');
assert.match(pricing, /subscription_package\(\(int\)\$package\['id'\]\)/, 'public pricing must load package entitlements from the canonical subscription runtime');
assert.match(pricing, /monthly_price_cents/, 'public pricing must render Admin-configured monthly prices');
assert.match(pricing, /annual_price_cents/, 'public pricing must render Admin-configured annual prices');
assert.doesNotMatch(pricing, /data-monthly="29"|data-monthly="59"|<h2>Professional<\/h2>|<h2>Team<\/h2>/, 'public pricing must not maintain a second hard-coded plan catalog');
assert.doesNotMatch(pricing, /subscription_ensure_schema\(/, 'public pricing must remain read-only and never perform subscription DDL');
assert.match(about, /Capture[\s\S]*Understand[\s\S]*Take action/i, 'About must explain the VP3 capture-understand-action model');
assert.match(contact, /Account \/ Support/, 'Contact must support VP3 account inquiries');
assert.doesNotMatch(contact, /stonefellow74@gmail\.com/, 'VP3 public Contact must not retain the legacy contact fallback');
assert.doesNotMatch(contact, /mailto:<\?=/, 'VP3 public Contact must not expose a legacy-branded configured email address');
assert.match(privacy, /Last updated September 6, 2026/, 'privacy revision date must reflect the VP3 rewrite');
assert.match(privacy, /We do not sell your personal information/, 'privacy no-sale commitment must be preserved');
assert.match(privacy, /VP3 contact page/, 'privacy requests must route through the VP3 public contact surface');
assert.match(terms, /Last updated September 6, 2026/, 'terms revision date must reflect the VP3 rewrite');
assert.match(terms, /You retain ownership of content you submit to VP3/, 'terms must preserve user content ownership');
assert.match(terms, /VP3 contact page/, 'terms questions must route through the VP3 public contact surface');
assert.match(demo, /Transcriptions & AI summaries/, 'demo request must reflect VP3 capabilities');
assert.match(demo, /Public requests never perform schema DDL/, 'demo requests must retain the no-public-DDL CRM boundary');

assert.match(shows, /redirect\(url\('\/index\.php'\)\)/, 'the obsolete Stonefellow public shows route must retire into the VP3 public site');
assert.doesNotMatch(shows, /Stonefellow \| Shows|Next Stonefellow Show/, 'legacy Stonefellow show marketing must no longer render');

console.log('vp3-public-auth contract: PASS');