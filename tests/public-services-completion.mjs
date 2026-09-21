import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const services=read('services.php');
const annotations=read('annotations.php');
const analytics=read('agent-analytics.php');
const nav=read('includes/vp3-public.php');
const index=read('index.php');
const sitemap=read('sitemap.php');
const css=read('vp3-marketing-pages.css');

const checks=[];
const add=(name,ok)=>checks.push([name,Boolean(ok)]);
const servicePaths=[
  '/transcriptions.php','/ai-summary.php','/annotations.php',
  '/teams.php','/video-meetings.php','/calendar-service.php',
  '/booking.php','/ecommerce.php','/agent-analytics.php'
];

add('Services canonical metadata',/canonical'\s*=>\s*'\/services\.php'/.test(services));
add('Services is public',/vp3_public_header/.test(services)&&!/require_permission\(|require_login\(/.test(services));
add('Six-stage operating loop is present',['Capture','Understand','Coordinate','Sell','Measure','Agent follows through'].every(x=>services.includes(x)));
add('All nine services are linked from Services',servicePaths.every(path=>services.includes(path)));
add('Services mirrors the three mega-menu groups',/Capture \+ understand/.test(services)&&/Coordinate/.test(services)&&/Book \+ sell/.test(services));
add('Annotations canonical metadata',/canonical'\s*=>\s*'\/annotations\.php'/.test(annotations));
add('Annotations capture coverage',/highlights, screenshots, notes, and source context/i.test(annotations)&&/Source-linked capture/.test(annotations));
add('Annotations research + collaboration coverage',/Save and organize/.test(annotations)&&/Comments stay attached/.test(annotations)&&/Use the right visibility/.test(annotations));
add('Annotations Agent + Browser Companion integration',/Ask VP3/.test(annotations)&&/Browser Companion/.test(annotations)&&annotations.includes('/chrome-extension.php'));
add('Agent Analytics canonical metadata',/canonical'\s*=>\s*'\/agent-analytics\.php'/.test(analytics));
add('Agent Analytics funnel coverage',/Profile visits/.test(analytics)&&/Booking intent/.test(analytics)&&/Product intent/.test(analytics)&&/Verified conversions/.test(analytics));
add('Agent Analytics revenue is per-currency',/per-currency revenue/i.test(analytics)&&/without combining unlike currencies/i.test(analytics));
add('Agent Analytics intelligence coverage',/Period comparisons/.test(analytics)&&/Top targets/.test(analytics)&&/Traffic sources/.test(analytics)&&/Opportunity signals/.test(analytics));
add('Agent Analytics privacy boundary',/without making visitor identity the product/i.test(analytics));
add('Sitemap is XML',/Content-Type: application\/xml/.test(sitemap)&&/<urlset/.test(sitemap));
add('Sitemap includes new service routes',['/services.php','/annotations.php','/agent-analytics.php','/video-meetings.php','/chrome-extension.php'].every(path=>sitemap.includes(path)));
add('Shared header references real sitemap',/\/sitemap\.php/.test(nav)&&fs.existsSync('sitemap.php'));
add('Services completion CSS exists',/\.vp3-service-flow/.test(css)&&/\.vp3-detail-path/.test(css)&&/\.vp3-metric-grid/.test(css));

const extractPhpPaths=text=>{
  const out=new Set();
  for(const m of text.matchAll(/url\('([^']+\.php)(?:\?[^']*)?'\)/g)) out.add(m[1]);
  return [...out];
};
const localLinks=[...new Set([...extractPhpPaths(index),...extractPhpPaths(nav),...extractPhpPaths(services),...extractPhpPaths(annotations),...extractPhpPaths(analytics)])];
const missing=localLinks.filter(path=>!fs.existsSync(path.replace(/^\//,'')));
add('Public homepage/nav/service PHP links resolve to repository routes',missing.length===0);

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Public Services completion: ${checks.length}/${checks.length} passed; ${localLinks.length} local PHP routes checked`);
