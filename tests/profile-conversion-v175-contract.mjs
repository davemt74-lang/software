import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const profile = read('profile.php');
const cleanupCss = read('profile-cleanup-v174.css');
const conversionCss = read('profile-conversion-v175.css');

assert.ok(cleanupCss.includes('profile-conversion-v175.css'), 'canonical profile stylesheet must load conversion layer');
assert.ok(profile.includes('data-profile-panel="booking"'), 'Booking remains a public profile surface');
assert.ok(profile.includes('Book a time'), 'Booking cards keep a direct CTA');
assert.ok(profile.includes('data-profile-panel="products"'), 'Products remains a public profile surface');
assert.ok(profile.includes("$product['cta']"), 'Product cards keep product-specific CTA copy');
assert.ok(profile.includes('profile_commerce_products_for_profile_v900($pdo,$profile,true,40)'), 'Products stay on public Commerce projection');
assert.ok(profile.includes('agent_scheduling_public_events_v450'), 'Booking stays on public scheduling projection');
assert.ok(conversionCss.includes('.profile-booking-item:hover'), 'booking cards have conversion affordance');
assert.ok(conversionCss.includes('.profile-product-item.featured:before'), 'featured products have visible prominence');
assert.ok(conversionCss.includes(':focus-visible'), 'conversion controls have keyboard focus states');
assert.ok(conversionCss.includes('prefers-reduced-motion:reduce'), 'motion respects reduced-motion preferences');
assert.ok(conversionCss.includes('@media(max-width:760px)'), 'conversion layout includes mobile behavior');

console.log('profile-conversion-v175-contract: ok');
