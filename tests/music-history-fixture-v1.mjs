import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {startMusicChatFixture} from './music-chat-fixture-v1.mjs';
import {startMusicHistoryRuntimeFixture} from './music-history-runtime-fixture-v1.mjs';

const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Extend the current MC1 player fixture; do not replace its media, receipts or isolated queue tracks. */
export async function startMusicHistoryFixture() {
  const fixture = await startMusicChatFixture();
  try {
    for (const file of ['api/music-history-v1.php', 'tests/fixtures/music-history-v1.php',
      'tests/fixtures/stem-mp3-metadata-v1.json']) {
      fs.copyFileSync(path.join(repository, file), path.join(fixture.root, file));
    }
    fs.appendFileSync(path.join(fixture.root, 'includes/bootstrap.php'), `
require_once STONEFELLOW_ROOT.'/tests/fixtures/music-history-v1.php';
require_once STONEFELLOW_ROOT.'/includes/music-history-chat-v1.php';
`);
    const seeded = JSON.parse(fixture.run(`music_history_fixture_schema_v1(db());
$seed=music_history_fixture_seed_v1(db(),time()-600,8);
$seed['older']=music_history_fixture_native_v1(db(),41,$seed['clock']-86400,11,6);
echo json_encode($seed,JSON_THROW_ON_ERROR);`));

    // The bounded Chat fixture runs the actual intent/store/bind services. The
    // separate runtime fixture tests production typed and NDJSON controllers.
    const fixtureFile = path.join(fixture.root, 'tests/fixtures/music-chat-v1.php');
    let source = fs.readFileSync(fixtureFile, 'utf8');
    const replace = (before, after) => {
      assert.equal(source.split(before).length, 2, 'Music Chat fixture integration point changed: ' + before.slice(0, 100));
      source = source.replace(before, after);
    };
    replace("$tool=vp3_music_chat_execute_v1($pdo,$user,$principal,$cid,$userMessage,$query,$input,null,'music_chat_http_provider_v1');",
      "$tool=vp3_music_history_chat_execute_v1($pdo,$user,$principal,$cid,$userMessage,$query,$input);\n"
      + "                if($tool===null)$tool=vp3_music_chat_execute_v1($pdo,$user,$principal,$cid,$userMessage,$query,$input,null,'music_chat_http_provider_v1');");
    replace('$tool=vp3_music_chat_bind_reply_v1($pdo,$user,$principal,$cid,$message,$tool);',
      '$tool=vp3_music_chat_bind_reply_v1($pdo,$user,$principal,$cid,$message,$tool);\n'
      + '                    $tool=vp3_music_history_chat_bind_reply_v1($pdo,$user,$principal,$cid,$message,$tool);');
    replace("$context=isset($tool['music_chat_v1'])?['music_chat_v1'=>vp3_music_chat_result_reference_v1($tool['music_chat_v1'])]:[];",
      "$context=isset($tool['music_chat_v1'])?['music_chat_v1'=>vp3_music_chat_result_reference_v1($tool['music_chat_v1'])]:[];\n"
      + "                if(isset($tool['music_history_snapshot_v1']))$context['music_history_v1']=$tool['music_history_snapshot_v1'];\n"
      + "                if(isset($tool['music_history_followup_v1']))$context['music_history_followup_v1']=$tool['music_history_followup_v1'];\n"
      + "                if($context)$context['skip_brain_archive']=true;");
    replace("->execute([$tool['answer'],json_encode($context,JSON_THROW_ON_ERROR),$message]);",
      "->execute([isset($tool['music_history_snapshot_v1'])?'Retained listening history was displayed in this Chat.'"
      + ":(isset($tool['music_chat_v1']['history_operation'])?'The selected history entry is available in this Chat.':$tool['answer']),"
      + "json_encode($context,JSON_THROW_ON_ERROR),$message]);");
    fs.writeFileSync(fixtureFile, source);
    fs.writeFileSync(path.join(fixture.root, 'chat.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
ob_start();music_chat_http_page_v1();$html=ob_get_clean();
$history=['endpoint'=>'/api/music-history-v1.php','csrf'=>csrf_token(),'userId'=>(int)($_SERVER['HTTP_X_USER']??11),
    'agentId'=>(int)($_GET['agent_id']??7)];
$runtime='<script>window.VP3_MUSIC_HISTORY_CONFIG_V1='.json_encode($history,JSON_HEX_TAG|JSON_HEX_AMP).';</script>'
    .'<script src="/assets/js/music-history-v1.js"></script>';
$html=str_replace('</head>','<link rel="stylesheet" href="/assets/css/music-history-v1.css"></head>',$html);
$html=str_replace('<script src="/chat.js"></script>',$runtime.'<script src="/chat.js"></script>',$html);
$player='<section class="chat-canvas-view chat-player-canvas" data-chat-view="player" hidden><header class="chat-player-page-head"><div><span>Stonefellow</span><h1>Player</h1><p>Your signed-in music home.</p></div></header></section>';
$html=str_replace('<div id="chatWelcome" hidden></div>','<div id="chatWelcome" hidden></div>'.$player,$html);
$html=str_replace('<header class="fixture-top">','<header class="fixture-top"><button type="button" class="vp3-music-history-launch" data-chat-view-target="player">Player</button> ',$html);
echo $html;
`);
    return {...fixture, seeded,
      historyRequest:(action, fields = {}, options = {}) => fixture.postJson('/api/music-history-v1.php',
        {action, agent_id:7, conversation_id:43, ...fields}, options),
      get logs() { return fixture.logs; }};
  } catch (error) { fixture.close(); throw error; }
}

/** Same browser renderer with the actual typed and NDJSON Chat controllers. */
export async function startMusicHistoryVoiceBrowserFixture(playerFixture) {
  const runtime = await startMusicHistoryRuntimeFixture();
  try {
    let html = await (await playerFixture.get('/chat.php?conversation_id=43')).text();
    const replaceGlobal = (name, value) => {
      const start = html.indexOf('window.' + name + '=');
      assert.ok(start >= 0, 'Missing fixture bootstrap ' + name);
      const end = html.indexOf(';', start);
      html = html.slice(0,start) + 'window.' + name + '=' + JSON.stringify(value) + html.slice(end);
    };
    replaceGlobal('STONEFELLOW_CHAT', {userId:1,agentId:7,endpoint:'/api/chat-v236.php?agent=7',csrf:'fixture',initialConversationId:14,initialView:'chat'});
    replaceGlobal('VP3_MUSIC_CHAT_CONFIG_V1', {userId:1,agentId:7,endpoint:'/api/music-chat-v1.php',csrf:'fixture'});
    replaceGlobal('VP3_MUSIC_HISTORY_CONFIG_V1', {userId:1,agentId:7,endpoint:'/api/music-history-v1.php',csrf:'fixture'});
    replaceGlobal('VP3_YOUTUBE_PLAYBACK_V1', {endpoint:'/api/chat-youtube-playback-v1.php',csrf:'fixture',captureKey:'c'.repeat(32),resumeCapture:true,watchCapture:true});
    html = html.replace('<textarea id="chatInput"', '<button type="button" class="vp3-music-history-launch" id="chatVoiceButtonLegacyDormant">Voice</button><span id="chatVoiceStatus" hidden></span><textarea id="chatInput"');
    html = html.replace('<script src="/chat.js"></script>', `<script>
window.STONEFELLOW_CHAT_VOICE_BOOT={button:document.getElementById('chatVoiceButtonLegacyDormant'),legacyDormant:true};
window.StonefellowAgentContext={refresh:async()=>({surface:'chat',user_agent_id:7}),snapshot:()=>({surface:'chat',user_agent_id:7}),setConversationId(){}};
</script><script src="/chat.js"></script><script src="/chat-voice.js"></script>`);
    const files = ['chat.js','chat.css','chat-voice.js','chat-youtube-v1.js','chat-youtube-v1.css',
      'assets/js/music-listening-v1.js','assets/js/music-chat-v1.js','assets/js/music-history-v1.js',
      'assets/css/music-chat-v1.css','assets/css/music-history-v1.css'];
    for (const file of files) {
      fs.mkdirSync(path.dirname(path.join(runtime.root,file)),{recursive:true});
      fs.copyFileSync(path.join(repository,file),path.join(runtime.root,file));
    }
    fs.writeFileSync(path.join(runtime.root,'history-browser.html'),html);
    const router = path.join(runtime.root,'router.php');
    const source = fs.readFileSync(router,'utf8');
    const marker = "$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);";
    assert.equal(source.split(marker).length,2,'Runtime fixture static-file boundary changed');
    fs.writeFileSync(router,source.replace(marker,marker + '\nif(in_array($path,'
      + '[' + ['/history-browser.html',...files.map(file=>'/'+file)].map(file=>"'"+file+"'").join(',') + '],true))return false;'));
    return runtime;
  } catch (error) { runtime.close(); throw error; }
}
