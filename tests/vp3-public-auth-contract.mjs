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
assert.match(signup, /\['artist','producer','supervisor','manager'\]/, 'signup must preserve canonical role-interest values for existing onboarding integrations');
assert.match(signup, /\['artist'=>'Personal','producer'=>'Creator','supervisor'=>'Professional','manager'=>'Team'\]/, 'VP3 may relabel role interests without changing their stored contract');
assert.match(signup, /\$_SESSION\['signup_role_interest'\] = \$roleInterest/, 'signup must preserve the downstream onboarding session contract');

assert.match(auth, /CREATE TABLE IF NOT EXISTS password_reset_tokens/, 'password recovery must have a canonical schema helper');
assert.match(auth, /random_bytes\(32\)/, 'reset tokens must use cryptographically secure randomness');
assert.match(auth, /hash\('sha256', \$token\)/, 'raw reset tokens must not be stored');
assert.match(auth, /INTERVAL 60 MINUTE/, 'reset links must expire');
assert.match(auth, /INTERVAL 5 MINUTE/, 'password-reset requests must have a database-backed per-account cooldown');
assert.match(auth, /created_at>=DATE_SUB\(NOW\(\),INTERVAL 5 MINUTE\)/, 'cooldown must be enforced from durable reset state');
assert.match(auth, /used_at IS NULL/, 'reset links must be single-use');
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
assert.match(configExample, /'send_password_reset_email' => false/, 'password-reset email configuration must be explicit');

assert.match(pricing, /data-monthly="29" data-annual="23"/, 'Professional pricing must remain $29 monthly / $23 annual');
assert.match(pricing, /data-monthly="59" data-annual="47"/, 'Team pricing must remain $59 monthly / $47 annual');
assert.match(pricing, /Personal URL/, 'pricing must reflect the new VP3 product');
assert.match(about, /Capture[\s\S]*Understand[\s\S]*Take action/i, 'About must explain the VP3 capture-understand-action model');
assert.match(contact, /Account \/ Support/, 'Contact must support VP3 account inquiries');
assert.doesNotMatch(contact, /mailto:<\?=/, 'VP3 public Contact must not expose a legacy-branded configured email address');
assert.match(privacy, /We do not sell your personal information/, 'privacy no-sale commitment must be preserved');
assert.match(privacy, /VP3 contact page/, 'privacy requests must route through the VP3 public contact surface');
assert.match(terms, /You retain ownership of content you submit to VP3/, 'terms must preserve user content ownership');
assert.match(terms, /VP3 contact page/, 'terms questions must route through the VP3 public contact surface');
assert.match(demo, /Transcriptions & AI summaries/, 'demo request must reflect VP3 capabilities');
assert.match(demo, /Public requests never perform schema DDL/, 'demo requests must retain the no-public-DDL CRM boundary');

assert.match(shows, /redirect\(url\('\/index\.php'\)\)/, 'the obsolete Stonefellow public shows route must retire into the VP3 public site');
assert.doesNotMatch(shows, /Stonefellow \| Shows|Next Stonefellow Show/, 'legacy Stonefellow show marketing must no longer render');

console.log('vp3-public-auth contract: PASS');
