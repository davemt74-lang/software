import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const upgrade = fs.readFileSync(new URL('upgrade.php', root), 'utf8');
const home = fs.readFileSync(new URL('includes/homeserver-agent-v018.php', root), 'utf8');
const v20 = fs.readFileSync(new URL('includes/agent-compute-v020.php', root), 'utf8');
const v23 = fs.readFileSync(new URL('includes/agent-compute-v023.php', root), 'utf8');

for (const table of ['homeserver_chat_sessions', 'agent_compute_preferences', 'agent_compute_overrides']) {
  assert.match(
    upgrade,
    new RegExp(`table_exists\\('${table}'\\)`),
    `${table} must be part of upgrade.php completeness checks`,
  );
}

assert.match(upgrade, /homeserver_agent_v018_ensure_schema\(\$pdo\)/);
assert.match(upgrade, /agent_compute_v020_ensure_schema\(\$pdo\)/);
assert.match(upgrade, /agent_compute_v023_ensure_schema\(\$pdo\)/);
assert.match(upgrade, /HomeServer Agent continuity/);
assert.match(upgrade, /per-Agent compute policies/);

assert.match(home, /CREATE TABLE IF NOT EXISTS homeserver_chat_sessions/);
assert.match(v20, /CREATE TABLE IF NOT EXISTS agent_compute_preferences/);
assert.match(v23, /CREATE TABLE IF NOT EXISTS agent_compute_overrides/);

// Policy guard: every table created by a standalone VP3 cloud migration must be
// represented in the canonical one-click upgrade.php path. Migrations that only
// alter existing schema are valid; newly created tables must remain covered.
const cloudMigrations = fs.readdirSync(new URL('.', root))
  .filter(name => /^upgrade-vp3-.*\.sql$/i.test(name))
  .sort();
assert.ok(cloudMigrations.length > 0, 'Expected at least one standalone VP3 cloud migration.');

for (const name of cloudMigrations) {
  const sql = fs.readFileSync(new URL(name, root), 'utf8');
  const tables = [...sql.matchAll(/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/gi)]
    .map(match => match[1]);
  for (const table of tables) {
    assert.ok(
      upgrade.includes(table),
      `${name} creates ${table}; upgrade.php must install or verify that cloud table.`,
    );
  }
}

console.log(`VP3 cloud schema upgrade contract passed (${cloudMigrations.length} migrations)`);
