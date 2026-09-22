import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const index=read('index.php');
const css=read('vp3-index-ai-assistants.css');
const demo=read('book-demo.php');
const funnel=read('includes/vp3-funnel.php');
const services=read('services.php');
const chrome=read('chrome-extension.php');
const pricing=read('pricing.php');
const client=read('vp3-public-funnel.js');
const endpoint=read('api/public-funnel-event.php');

const checks=[];
const add=(name,ok)=>checks.push([name,Boolean(ok)]);
add('Homepage canonical metadata',/<link rel="canonical"/.test(index)&&/VP3 AI Assistants — From Signal to Outcome/.test(index));
add('Homepage captures funnel origin',/vp3_funnel_capture_public_source\('index'\)/.test(index));
add('Homepage uses funnel-aware signup and demo URLs',/vp3_funnel_url\('\/signup\.php'\)/.test(index)&&/vp3_funnel_url\('\/book-demo\.php'\)/.test(index));
add('Homepage operating loop',['Capture','Understand','Coordinate','Sell','Measure','Agent follows through'].every(x=>index.includes(x)));
add('Homepage current surface coverage',['/chrome-extension.php','/annotations.php','/video-meetings.php','/profile-agent-overview.php','/teams.php','/booking.php','/ecommerce.php','/agent-analytics.php','/homeserver.php'].every(x=>index.includes(x)));
add('Homepage explicit CTA instrumentation',/home_hero_get_vp3/.test(index)&&/home_hero_book_demo/.test(index)&&/home_capability/.test(index)&&/home_choice_pricing/.test(index));
add('Homepage styles responsive current story',/\.home-lifecycle-grid/.test(css)&&/\.home-surface-grid/.test(css)&&/\.home-journey-steps/.test(css)&&/@media\(max-width:620px\)/.test(css));
add('Demo choices cover current platform',['Browser Companion + Annotations','Meetings + Transcription + AI Summary','Calendar + Booking','Ecommerce + Agent Analytics','HomeServer + private AI'].every(x=>demo.includes(x)));
add('Funnel recognizes current public sources',['chrome-extension','annotations','video-meetings','agent-analytics'].every(x=>funnel.includes("'"+x+"'")));
add('Funnel preserves first-touch origin',/\$capture\['origin'\]/.test(funnel)&&/\['origin', 'source', 'plan', 'billing'\]/.test(funnel));
add('Services discovery clicks instrumented',(services.match(/data-vp3-cta="service_discovery"/g)||[]).length===9);
add('Chrome download click instrumented',/data-vp3-cta="chrome_download"/.test(chrome));
add('Pricing plan clicks instrumented',/data-vp3-cta="pricing_plan"/.test(pricing));
add('CTA beacon is first-party and navigation-safe',/navigator\.sendBeacon/.test(client)&&/credentials:'same-origin'/.test(client));
add('CTA endpoint is bounded',/vp3_funnel_token\(\$_POST\['cta'\]/.test(endpoint)&&/vp3_funnel_token\(\$_POST\['target'\]/.test(endpoint)&&/vp3_funnel_event\('cta_click'/.test(endpoint));
add('CTA endpoint avoids fingerprinting and DDL',!/REMOTE_ADDR|HTTP_USER_AGENT|session_id\(|CREATE TABLE|ALTER TABLE/.test(endpoint));

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Homepage + Public Funnel Completion: ${checks.length}/${checks.length} passed`);
