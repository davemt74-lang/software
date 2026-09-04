import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const studio=fs.readFileSync('admin/stem-editor.js','utf8');
const transport=fs.readFileSync('admin/stem-transport-v200.js','utf8');
const media=fs.readFileSync('stem-media-v34.php','utf8');
const footer=fs.readFileSync('admin/_footer.php','utf8');

execFileSync(process.execPath,['--check','admin/stem-editor.js'],{stdio:'pipe'});
execFileSync('php',['-l','stem-media-v34.php'],{stdio:'pipe'});
execFileSync('php',['-l','admin/_footer.php'],{stdio:'pipe'});

assert.match(media,/STONEFELLOW_STEM_MEDIA_BUILD\s*=\s*'stem-media-byte-recovery-v229-20260902'/);
assert.match(media,/apache_setenv\('no-gzip', '1'\)/,'binary media must disable server compression');
assert.match(media,/header_remove\('Content-Encoding'\)/);
assert.doesNotMatch(media,/header\('Content-Encoding: identity'\)/,'binary media must not publish a potentially false encoding header');
assert.match(media,/Cache-Control: private, no-cache, no-transform, max-age=0/,'repaired media must revalidate instead of replaying a stale broken response');
assert.match(media,/REQUEST_METHOD[^\n]+HEAD/,'HEAD requests must be bodyless');
assert.match(media,/Content-Length:/,'media responses must publish an exact body length');
assert.match(media,/Content-Range:/,'partial responses must publish an exact range');
assert.match(media,/Accept-Ranges: bytes/);
assert.match(media,/stem_media_v229_inspect_file/,'media bytes must be inspected before streaming');
assert.match(media,/stem_media_v229_scan_mp3_frames/,'MP3 validation must require real MPEG frames');
assert.match(media,/invalid_signature/);
assert.match(media,/signature_mismatch/);

const bootstrapIndex=media.indexOf("require __DIR__ . '/includes/bootstrap.php'");
const bufferIndex=media.lastIndexOf('ob_start();',bootstrapIndex);
const displayErrorsIndex=media.lastIndexOf("ini_set('display_errors', '0')",bootstrapIndex);
assert.ok(bootstrapIndex>0 && bufferIndex>0 && bufferIndex<bootstrapIndex,'output buffering must begin before bootstrap');
assert.ok(displayErrorsIndex>0 && displayErrorsIndex<bootstrapIndex,'display errors must be disabled before bootstrap');

assert.match(studio,/contentType\.startsWith\('audio\/'\)/,'browser decoding must reject non-audio responses');
assert.match(studio,/stem\.waveformError \|\|\s*stem\.mediaUnavailable/,'failed waveforms must not be queued repeatedly');
assert.doesNotMatch(studio,/stem\.waveformError = true;\s*stem\.mediaUnavailable = true/,'waveform extraction failure must not mark otherwise playable audio offline');
assert.match(studio,/stem\.waveformError = true;\s*console\.warn\(\s*'Waveform extraction failed'/,'waveform extraction failure must be recorded without changing media availability');
assert.doesNotMatch(studio,/stonefellow:stem-media-offline/,'canonical editor must not duplicate transport-owned offline handling');
assert.match(transport,/stonefellow:stem-media-offline[\s\S]*?stem\.mediaUnavailable\s*=\s*true/,'canonical transport must mark the matching stem unavailable');
assert.match(footer,/stonefellow:stem-media-offline/,'verified media failures must publish the canonical offline event');
assert.match(studio,/if \(stem\.mediaUnavailable\) \{[\s\S]*?return;/,'offline stems must not block the seek barrier');
assert.match(studio,/stem\.mediaUnavailable \|\|\s*!stem\.trimNode/,'offline stems must be excluded from advanced transport preparation');

assert.match(footer,/\$stemMediaHealthV229\s*=\s*basename\([\s\S]*?=== 'stems\.php'/,'offline UI must be scoped to Stem Studio only');
assert.match(footer,/data-stem-media-health-v229/);
assert.match(footer,/data-stem-media-status/,'offline UI must label the matching track, arrange lane, and mixer channel');
assert.match(footer,/method:'HEAD'/,'a failed media element may diagnose status with a bodyless request only');
assert.match(footer,/MISSING MEDIA/);
assert.match(footer,/INVALID MEDIA/);

function riffWav(dataBytes=2048){
  const data=Buffer.alloc(dataBytes);
  for(let i=0;i<data.length;i++) data[i]=i%251;
  const fmt=Buffer.alloc(16);
  fmt.writeUInt16LE(1,0);       // PCM
  fmt.writeUInt16LE(1,2);       // mono
  fmt.writeUInt32LE(48000,4);
  fmt.writeUInt32LE(96000,8);   // byte rate
  fmt.writeUInt16LE(2,12);      // block align
  fmt.writeUInt16LE(16,14);     // bit depth
  const out=Buffer.alloc(44+data.length);
  out.write('RIFF',0,'ascii');
  out.writeUInt32LE(36+data.length,4);
  out.write('WAVE',8,'ascii');
  out.write('fmt ',12,'ascii');
  out.writeUInt32LE(16,16);
  fmt.copy(out,20);
  out.write('data',36,'ascii');
  out.writeUInt32LE(data.length,40);
  data.copy(out,44);
  return out;
}

function mpeg1Layer3Frame(fill=0x2a){
  // FF FB 90 64 = MPEG1 Layer III, 128 kbps, 44.1 kHz, no padding.
  // Its frame length is floor(144000 * 128 / 44100) = 417 bytes.
  const frame=Buffer.alloc(417,fill);
  Buffer.from([0xff,0xfb,0x90,0x64]).copy(frame,0);
  return frame;
}

const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'stonefellow-media-v229-'));
try {
  const healthScriptMatch=footer.match(/<script data-stem-media-health-v229>([\s\S]*?)<\/script>/);
  assert.ok(healthScriptMatch?.[1],'Stem-only offline UI script must be present');
  const healthScriptPath=path.join(tmp,'stem-media-health-v229.js');
  fs.writeFileSync(healthScriptPath,healthScriptMatch[1]);
  execFileSync(process.execPath,['--check',healthScriptPath],{stdio:'pipe'});

  const wavPath=path.join(tmp,'valid.wav');
  const truncatedWavPath=path.join(tmp,'truncated.wav');
  const mp3Path=path.join(tmp,'valid.mp3');
  const id3OnlyPath=path.join(tmp,'id3-only.mp3');
  const corruptPath=path.join(tmp,'broken.mp3');
  const mismatchPath=path.join(tmp,'wav-named-mp3.mp3');
  const missingPath=path.join(tmp,'missing.wav');
  const wav=riffWav(4096);
  const truncatedWav=Buffer.from(wav);
  truncatedWav.writeUInt32LE(4097,40);
  const id3Header=Buffer.from([0x49,0x44,0x33,0x04,0x00,0x00,0x00,0x00,0x00,0x00]);
  const mp3=Buffer.concat([
    id3Header,
    mpeg1Layer3Frame(0x2a),
    mpeg1Layer3Frame(0x35)
  ]);

  fs.writeFileSync(wavPath,wav);
  fs.writeFileSync(truncatedWavPath,truncatedWav);
  fs.writeFileSync(mp3Path,mp3);
  fs.writeFileSync(id3OnlyPath,Buffer.concat([id3Header,Buffer.alloc(2048,0)]));
  fs.writeFileSync(corruptPath,'<!doctype html><html><body>login/error page</body></html>');
  fs.writeFileSync(mismatchPath,wav);

  const php=String.raw`
    define('STONEFELLOW_STEM_MEDIA_LIBRARY_ONLY', true);
    require 'stem-media-v34.php';
    $wav=$argv[1]; $truncatedWav=$argv[2]; $mp3=$argv[3]; $id3only=$argv[4]; $broken=$argv[5]; $mismatch=$argv[6]; $missing=$argv[7];
    $ranges=[
      'full'=>stem_media_v229_range('', filesize($wav)),
      'first'=>stem_media_v229_range('bytes=0-1023', filesize($wav)),
      'second'=>stem_media_v229_range('bytes=1024-2047', filesize($wav)),
      'suffix'=>stem_media_v229_range('bytes=-256', filesize($wav)),
      'bad'=>stem_media_v229_range('bytes=999999-', filesize($wav)),
      'zero_suffix'=>stem_media_v229_range('bytes=-0', filesize($wav)),
    ];
    echo json_encode([
      'wav'=>stem_media_v229_inspect_file($wav),
      'truncatedWav'=>stem_media_v229_inspect_file($truncatedWav),
      'mp3'=>stem_media_v229_inspect_file($mp3),
      'id3only'=>stem_media_v229_inspect_file($id3only),
      'broken'=>stem_media_v229_inspect_file($broken),
      'mismatch'=>stem_media_v229_inspect_file($mismatch),
      'missing'=>stem_media_v229_inspect_file($missing),
      'ranges'=>$ranges,
      'first_body'=>base64_encode(stem_media_v229_read_slice($wav,0,1024)),
      'second_body'=>base64_encode(stem_media_v229_read_slice($wav,1024,1024)),
    ], JSON_UNESCAPED_SLASHES);
  `;

  const raw=execFileSync('php',['-r',php,wavPath,truncatedWavPath,mp3Path,id3OnlyPath,corruptPath,mismatchPath,missingPath],{encoding:'utf8'});
  const result=JSON.parse(raw);

  assert.equal(result.wav.ok,true);
  assert.equal(result.wav.format,'wav');
  assert.equal(result.wav.mime,'audio/wav');
  assert.equal(result.wav.size,wav.length);
  assert.equal(result.truncatedWav.ok,false);
  assert.equal(result.truncatedWav.reason,'invalid_signature','WAV chunks that extend beyond EOF must be rejected before streaming');

  assert.equal(result.mp3.ok,true);
  assert.equal(result.mp3.format,'mp3');
  assert.equal(result.mp3.mime,'audio/mpeg');
  assert.equal(result.mp3.size,mp3.length);

  assert.equal(result.id3only.ok,false);
  assert.equal(result.id3only.reason,'invalid_signature','an ID3 tag without playable MPEG frames must not be served as MP3');
  assert.equal(result.broken.ok,false);
  assert.equal(result.broken.reason,'invalid_signature','HTML/error bytes must never be served as audio/mpeg');
  assert.equal(result.mismatch.ok,false);
  assert.equal(result.mismatch.reason,'signature_mismatch','extension and real media signature must agree');
  assert.equal(result.missing.ok,false);
  assert.equal(result.missing.reason,'missing');

  assert.deepEqual(result.ranges.full,{
    ok:true,status:200,start:0,end:wav.length-1,length:wav.length,content_range:''
  });
  assert.equal(result.ranges.first.status,206);
  assert.equal(result.ranges.first.start,0);
  assert.equal(result.ranges.first.end,1023);
  assert.equal(result.ranges.first.length,1024);
  assert.equal(result.ranges.first.content_range,`bytes 0-1023/${wav.length}`);
  assert.equal(result.ranges.second.status,206);
  assert.equal(result.ranges.second.start,1024);
  assert.equal(result.ranges.second.end,2047);
  assert.equal(result.ranges.second.length,1024);
  assert.equal(result.ranges.second.content_range,`bytes 1024-2047/${wav.length}`);
  assert.equal(result.ranges.suffix.status,206);
  assert.equal(result.ranges.suffix.length,256);
  assert.equal(result.ranges.bad.status,416);
  assert.equal(result.ranges.bad.content_range,`bytes */${wav.length}`);
  assert.equal(result.ranges.zero_suffix.status,416);

  assert.deepEqual(Buffer.from(result.first_body,'base64'),wav.subarray(0,1024),'first range body must equal the source bytes exactly');
  assert.deepEqual(Buffer.from(result.second_body,'base64'),wav.subarray(1024,2048),'second range body must equal the source bytes exactly');
} finally {
  fs.rmSync(tmp,{recursive:true,force:true});
}

console.log('STEM_MEDIA_FAILURE_V229=PASS');
