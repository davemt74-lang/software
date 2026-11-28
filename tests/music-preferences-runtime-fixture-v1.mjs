import assert from 'node:assert/strict';
import {appendFileSync,copyFileSync,writeFileSync} from 'node:fs';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {startMusicHistoryRuntimeFixture} from './music-history-runtime-fixture-v1.mjs';

/** Add one explicit setup endpoint in the disposable fixture, never DDL in production reads. */
export async function startMusicPreferencesRuntimeFixture() {
  const f = await startMusicHistoryRuntimeFixture();
  try {
    const repository = fileURLToPath(new URL('../', import.meta.url));
    copyFileSync(join(repository,'api/music-preferences-v1.php'),join(f.root,'api/music-preferences-v1.php'));
    appendFileSync(join(f.root,'includes/bootstrap.php'),
      "\nfunction user_has_role(string $role,?array $user=null):bool{return in_array($role,user_account_types_for_user_id((int)(($user??current_user())['id']??0)),true);}\n"
      +"require_once __DIR__.'/artist-workspaces-v104.php';\nrequire_once __DIR__.'/artist-workspace-v181.php';\n");
    writeFileSync(join(f.root,'api/_fixture-music-preferences.php'),`<?php
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/music-preferences-v1.php';
header('Content-Type: application/json');
$body=json_decode((string)file_get_contents('php://input'),true);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($body['csrf_token']??null)!=='fixture'){http_response_code(403);echo '{"ok":false}';exit;}
if(($body['action']??null)==='install'){
    if(!column_exists('chat_conversations','artist_workspace_id'))db()->exec('ALTER TABLE chat_conversations ADD COLUMN artist_workspace_id INTEGER');
    vp3_music_preferences_schema_v1(db());echo '{"ok":true}';exit;
}
$state=[];foreach(['music_preferences_v1','music_preference_requests_v1','music_deliberate_skips_v1'] as $table)$state[$table]=db()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>true,'state'=>$state],JSON_THROW_ON_ERROR);
`);
    const installed = await f.json('/api/_fixture-music-preferences.php',{action:'install',csrf_token:'fixture'});
    assert.equal(installed.response.status,200,installed.raw);assert.equal(installed.data.ok,true);
    return {...f,
      preference:(action,extra={},headers={})=>f.json('/api/music-preferences-v1.php',
        {action,agent_id:7,conversation_id:14,csrf_token:'fixture',...extra},headers),
      preferenceState:async()=>(await f.json('/api/_fixture-music-preferences.php',{action:'state',csrf_token:'fixture'})).data.state,
      get logs(){return f.logs;},get cookie(){return f.cookie;}};
  } catch(error) {f.close();throw error;}
}
