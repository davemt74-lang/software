import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {startMusicHistoryFixture} from './music-history-fixture-v1.mjs';

const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Actual PHP endpoint and database; inherited fixtures replace authentication and external providers. */
export async function startMusicPreferencesFixture() {
  const fixture = await startMusicHistoryFixture();
  try {
    for (const file of ['api/music-preferences-v1.php', 'tests/fixtures/music-preferences-v1.php']) {
      fs.mkdirSync(path.dirname(path.join(fixture.root, file)), {recursive:true});
      fs.copyFileSync(path.join(repository, file), path.join(fixture.root, file));
    }
    fs.appendFileSync(path.join(fixture.root, 'includes/bootstrap.php'),
      "\nrequire_once STONEFELLOW_ROOT.'/tests/fixtures/music-preferences-v1.php';\n");
    const preferences = JSON.parse(fixture.run(
      'vp3_music_preferences_schema_v1(db());echo json_encode(music_preferences_fixture_bindings_v1(db(),json_decode($argv[2],true,32,JSON_THROW_ON_ERROR),43),JSON_THROW_ON_ERROR);',
      JSON.stringify(fixture.seeded)));
    const pageFile = path.join(fixture.root, 'chat.php');
    let page = fs.readFileSync(pageFile, 'utf8');
    assert.equal(page.split('echo $html;').length, 2, 'The inherited history fixture page bootstrap changed.');
    page = page.replace('echo $html;', `$preferences=['endpoint'=>'/api/music-preferences-v1.php','csrf'=>csrf_token(),
  'userId'=>(int)($_SERVER['HTTP_X_USER']??11),'agentId'=>(int)($_GET['agent_id']??7),
  'conversationId'=>(int)($_GET['conversation_id']??43)];
$preferenceRuntime='<script>window.VP3_MUSIC_PREFERENCES_V1_CONFIG='.json_encode($preferences,JSON_HEX_TAG|JSON_HEX_AMP).';</script>'
  .'<script src="/assets/js/music-preferences-v1.js"></script>';
$html=str_replace('</head>','<link rel="stylesheet" href="/assets/css/music-preferences-v1.css"></head>',$html);
$html=str_replace('<script src="/chat.js"></script>',$preferenceRuntime.'<script src="/chat.js"></script>',$html);
echo $html;`);
    fs.writeFileSync(pageFile, page);
    return {...fixture, preferences,
      preferenceRequest:(action, fields = {}, options = {}) => fixture.postJson('/api/music-preferences-v1.php',
        {action, agent_id:7, conversation_id:43, ...fields}, options),
      get logs() { return fixture.logs; }};
  } catch (error) { fixture.close(); throw error; }
}
