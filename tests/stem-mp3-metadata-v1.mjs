import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';

const root=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-mp3-metadata-'));
const fixtures=JSON.parse(fs.readFileSync('tests/fixtures/stem-mp3-metadata-v1.json','utf8')).fixtures;
const directory=path.join(root,'uploads/stems/track-9');fs.mkdirSync(directory,{recursive:true});
try{
  const cases=fixtures.map(item=>{
    const file=path.join(directory,item.name+'.mp3');fs.writeFileSync(file,Buffer.from(item.base64,'base64'));
    return {...item,file};
  });
  const source=Buffer.from(fixtures[0].base64,'base64');
  const tagSize=(source[6]<<21)|(source[7]<<14)|(source[8]<<7)|source[9];
  const audio=source.subarray(10+tagSize);
  const largeTag=Buffer.alloc(300000,0x20),size=largeTag.length;
  const header=Buffer.from([73,68,51,4,0,16,(size>>21)&127,(size>>14)&127,(size>>7)&127,size&127]);
  const large=path.join(directory,'large-id3.mp3');fs.writeFileSync(large,Buffer.concat([header,largeTag,Buffer.alloc(10),audio]));
  const invalid=path.join(directory,'invalid.mp3');fs.writeFileSync(invalid,Buffer.concat([header,largeTag]));
  const php=String.raw`
    define('STONEFELLOW_ROOT',$argv[1]);
    require 'includes/stems-v30.php';
    require 'includes/stem-project-health-v1.php';
    $files=json_decode($argv[2],true);$out=[];
    foreach($files as $file){$out[]=['native'=>stem_mp3_metadata_v1($file),
      'import'=>stem_audio_info($file,['length'=>9999],9999)];}
    $row=['id'=>9,'file_name'=>basename($files[0]),'file_path'=>'/uploads/stems/track-9/'.basename($files[0]),
      'duration_seconds'=>0,'start_offset_seconds'=>12.5,'stem_name'=>'Vocal','channels'=>0,'sample_rate'=>0];
    echo json_encode(['cases'=>$out,'shell_enabled'=>function_exists('shell_exec'),
      'recovered'=>stem_project_resolve_metadata_v1([$row]),'invalid'=>stem_mp3_metadata_v1($argv[3]),
      'source'=>stem_project_resolve_metadata_v1([$row+['other'=>'preserved']])]);
  `;
  const files=[...cases.map(x=>x.file),large];
  const data=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[
    '-d','disable_functions=shell_exec,exec,system,passthru','-r',php,root,JSON.stringify(files),invalid
  ],{encoding:'utf8'}));
  assert.equal(data.shell_enabled,false,'Shared-host fallback must run with shell execution disabled');
  cases.forEach((item,i)=>{
    const metadata=data.cases[i].native,stream=item.oracle.streams[0];
    assert(metadata,item.name+' was not recognized');
    assert.equal(metadata.channels,stream.channels);assert.equal(metadata.sample_rate,Number(stream.sample_rate));
    assert(Math.abs(metadata.duration_seconds-Number(item.oracle.format.duration))<.09,item.name+' source duration differs from the FFmpeg fixture');
    assert.deepEqual(data.cases[i].import,metadata,'Import must use actual MP3 duration rather than the unrelated RPP item length');
    assert(metadata.duration_seconds>1&&metadata.duration_seconds<2);
  });
  assert.equal(data.cases[0].native.metadata_method,'xing');
  assert.equal(data.cases[2].native.metadata_method,'frames','A non-seekable export without Xing must be counted from frames');
  assert.equal(data.cases[3].native.duration_seconds,data.cases[0].native.duration_seconds,'Large ID3 tags and their footer must not change audio duration');
  assert.equal(data.invalid,null,'An incomplete metadata tag is not MP3 audio');
  assert.equal(data.recovered[0].duration_metadata_recovered,true);
  assert.equal(data.recovered[0].duration_seconds,data.cases[0].native.duration_seconds);
  assert.equal(data.recovered[0].start_offset_seconds,12.5,'Metadata recovery must preserve timeline placement');
  assert.equal(data.source[0].other,'preserved');
  assert.equal(fs.readFileSync(cases[0].file).toString('base64'),fixtures[0].base64,'Metadata reads must preserve source bytes');
  console.log('STEM_MP3_METADATA=PASS shell-disabled mono/stereo/VBR/streamed exports, Xing and counted frames, large ID3 footer, actual source duration, existing-import metadata recovery and byte preservation');
}finally{fs.rmSync(root,{recursive:true,force:true});}
