import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const index=read('index.php');
const publicNav=read('includes/vp3-public.php');
const meetings=read('video-meetings.php');
const chrome=read('chrome-extension.php');
const chromeDownload=read('chrome-extension-download.php');
const manifest=JSON.parse(read('browser-companion/manifest.json'));

const count=(haystack,needle)=>haystack.split(needle).length-1;

const checks=[
  ['index links Chrome Extension desktop + mobile', count(index,"/chrome-extension.php")>=2 && /<strong>Chrome Extension<\/strong>/.test(index)],
  ['index links Meetings desktop + mobile', count(index,"/video-meetings.php")>=2 && /<strong>Meetings<\/strong>/.test(index)],
  ['shared public nav links Chrome Extension desktop + mobile', count(publicNav,"/chrome-extension.php")>=2],
  ['shared public nav links Meetings desktop + mobile', count(publicNav,"/video-meetings.php")>=2],
  ['Chrome Extension is under Product assistant navigation', index.indexOf('/chrome-extension.php')<index.indexOf('<summary>Services</summary>')],
  ['Meetings is under Services navigation', index.indexOf('/video-meetings.php')>index.indexOf('<summary>Services</summary>') && index.indexOf('/video-meetings.php')<index.indexOf('<summary>HomeServer</summary>')],
  ['Meetings page is public marketing surface', /vp3_public_header/.test(meetings) && /redirect_logged_in_public_page/.test(meetings) && !/require_permission\(/.test(meetings)],
  ['Chrome page is public marketing surface', /vp3_public_header/.test(chrome) && /redirect_logged_in_public_page/.test(chrome) && !/require_permission\(/.test(chrome)],
  ['Meetings canonical path', /canonical'\s*=>\s*'\/video-meetings\.php'/.test(meetings)],
  ['Chrome canonical path', /canonical'\s*=>\s*'\/chrome-extension\.php'/.test(chrome)],
  ['Meetings page describes intelligence and follow-through', /Meeting Intelligence/.test(meetings) && /follow-through/i.test(meetings)],
  ['Chrome page describes Browser Companion and approval boundaries', /Browser Companion/.test(chrome) && /approved browser actions/i.test(chrome)],
  ['Chrome public page does not expose extension approval flow', !/approval_token|installation_id|device_code/.test(chrome)],
  ['Chrome page links real extension download', /chrome-extension-download\.php/.test(chrome) && /Download Chrome Extension/.test(chrome)],
  ['Download endpoint uses current manifest version', /manifest\.json/.test(chromeDownload) && /\$manifest\['version'\]/.test(chromeDownload) && manifest.version==='22.8.0'],
  ['Download package has manifest at ZIP root', /\$zip->addFile\(\$root \. '\/' \. \$file, \$file\)/.test(chromeDownload)],
  ['Download package excludes README and dev material', !/README\.md/.test(chromeDownload) && !/tests\//.test(chromeDownload)],
  ['Download endpoint is public and bounded', !/require_permission\(|require_login\(/.test(chromeDownload) && /\$files = \[/.test(chromeDownload) && /Content-Type: application\/zip/.test(chromeDownload)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Public mega menu Meetings + Chrome Extension: ${checks.length}/${checks.length} passed`);
