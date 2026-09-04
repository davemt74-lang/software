# Stonefellow Recovery Runtime Baseline

Status: recovery baseline reconstructed from the latest alternate-branch ZIP imported into `davemt74-lang/software` on 2026-09-04.

This document is the working map for future changes. It does **not** attempt to recreate the lost PR history. The recovered source tree is the source of truth.

## Operating rules

1. Verify the live file/runtime path before changing a subsystem.
2. Prefer canonical unversioned/current files over historical `vNN` snapshots when the active page explicitly rewrites to the canonical file.
3. Do not delete historical/versioned files until a reference scan and focused runtime test prove they are dead.
4. Keep privacy/authorization checks server-side even when the UI hides a feature.
5. Keep Chat/voice, Artist Listening, and Stem transport/audio changes narrowly scoped.
6. Use feature branches and exact-head CI for all new work.

## Core request bootstrap

`includes/bootstrap.php` is the common server bootstrap. It owns:

- session/cookie initialization
- `config.php` loading with safe fallback configuration
- helper and PDO initialization
- permissions and request gates
- authentication
- notifications
- Artist Workspace and Artist CMS services
- Agent Brain, proactive/runtime/action systems
- CRM
- user-owned agents and data policy
- shared-knowledge index
- AI provider/runtime configuration
- Chat policy/engine
- Profile Agent/runtime

The application is a direct PHP/MySQL + browser JavaScript application. There is no framework dependency manager in the recovered root.

## Canonical authenticated surfaces

### Agent Chat

Entry: `chat.php`

Current architecture:

`chat.php`
→ renders `chat-legacy-v108.php`
→ removes/replaces legacy Chat assets
→ selects user-owned agent identity through `includes/user-agent-system-v236.php`
→ routes current conversations through `api/chat-v236.php`
→ applies authorization/context through `includes/chat-agent-policy-v236.php`
→ loads canonical Chat voice/runtime assets

Important active assets/services:

- `chat.js`
- `chat-voice.js`
- `premium-voice-v117.js`
- `agent-context-v131.js`
- `chat-agent-identity-v236.js`
- `chat-recordings-v242.js`
- `api/chat-v236.php`
- `api/chat-recordings-v242.php`
- `api/artist-recordings-v198.php`

`chat-legacy-v108.php` remains a restoration/template source, not the desired long-term architecture.

### Account / Agent settings

Entry: `account.php`

The base page owns:

- account profile/photo
- password/security
- Agent Brain/SOUL.md
- account access cards

`member-shell-v77.js` conditionally loads `account-agent-settings-loader-v236.js`, which injects the current Agents & Data and Profile Dashboard runtimes.

Current agent/data service:

- `api/user-agent-system-v236.php`
- `includes/user-agent-system-v236.php`
- `includes/user-data-usage-v236.php`
- `includes/shared-knowledge-index-v236.php`

Current policy model distinguishes:

- owner-agent private access
- Profile Agent access
- relationship audiences
- explicit sharing with system/network AI
- per-agent allow/deny overrides

Do not collapse owner-private access into network sharing.

### Public Profile / Profile Agent

Entry: `profile.php`

Public route:

`/stonefellow/{username}` → `profile.php?username={username}`

Profile runtime combines:

- `user_profiles`
- Artist Workspace v181 public catalog
- user-owned Profile Agent
- profile visits/events
- Profile Agent conversations/messages
- `agent_attention_items`

Profile Agent storage/services are defined in `includes/profile-agent.php` and `includes/profile-agent-runtime.php`.

### Artist Listening / transcription

Entry: `artist-listening.php`

Current browser stack:

- `artist-listening-realtime.js`
- `artist-listening-recognition.js`
- `artist-listening-transcript.js`
- `artist-listening-workspace.js`
- `artist-listening.js`
- `artist-listening-recordings.js`
- `artist-listening-naming.js`
- `artist-listening-ai.js`
- `artist-listening-ui.js`
- `transcription-editor.js`

Current APIs:

- `api/artist-listening-v172.php` — base session/transcript operations
- `api/artist-listening-long-v237.php` — long transcript support
- `api/artist-listening-edit-v249.php` — editing
- `api/artist-listening-intelligence-v254.php` — current AI Summary/intelligence
- `api/artist-recordings-v198.php` — recording library

The current AI controller intentionally owns only AI Summary/intelligence. It must not take ownership of recording, transcript navigation, checkpoint persistence, or participant mapping.

### Stem Studio / DAW

Entry: `admin/stems.php`

Current architecture:

`admin/stems.php`
→ renders `admin/stems-legacy-v108.php`
→ rewrites old Stem runtime references
→ canonical core `admin/stem-editor.js`
→ transport/audio/editing modules
→ shared conversation/voice and agent tool bridges

Canonical transport/audio path:

- `admin/stem-editor.js`
- `admin/stem-master-clock-v201.js`
- `admin/stem-buffer-scheduler-v202.js`
- `admin/stem-time-stretch-v203.js`
- `admin/stem-time-stretch-worklet-v203.js`
- `admin/stem-loop-planner-v204.js`
- `admin/stem-transport-v200.js`

Default editing additions:

- `admin/stem-editing-v209.js`
- `admin/stem-professional-editing-v210.js`

Advanced runtime (`?advanced_runtime=1`) additionally enables:

- v211 automation/mixer
- v212 recording takes
- v213 recording engine
- v214 render/export
- v215 audio engine
- v216 session safety

MIDI/composition exists in v217-v219 generation.

Stem agent/tool path includes:

- `admin/stem-agent-v131.js`
- `admin/stem-tool-bridge-v127.js`
- `admin/stem-advanced-tools-v128.js`
- `admin/stem-project-agent-v158.js`
- `admin/stem-command-bus-v159.js`

Treat `admin/stems-vNN.js` generations as historical/restoration candidates unless an active runtime reference proves otherwise.

## Artist Workspace / CMS

Current multi-artist architecture starts at v181 and includes:

- Artist Workspace profile/identity
- tracks/music
- albums
- shows
- media/photos
- posts
- merch
- release plans
- saved/favorite user interactions

Admin/current helper generations are v181-v186.

## Agent architecture

### User-owned agents

Current authority: `includes/user-agent-system-v236.php`

Roles:

- personal
- artist
- studio
- booking
- profile
- custom

A named user agent is powered by the Stonefellow system agent but has its own identity, instructions, conversation scope, profile-agent selection, voice setting and resource rules.

### Agent Brain

Recovered runtime includes:

- conversation archive
- memory extraction
- memory lifecycle
- task lifecycle
- semantic/context retrieval
- proactive suggestions/events
- activity awareness
- action/tool execution

`includes/agent-brain-context-v142.php` is the active context pathname loaded by bootstrap; older context files remain historical/compatibility sources.

### Knowledge

Core tables:

- `knowledge_items`
- `knowledge_chunks`

Shared/network discovery authority:

- `includes/shared-knowledge-index-v236.php`

Cross-user knowledge discovery must go through the shared pointer/index, then re-check live source state and policy before content enters context.

## Voice / conversation

Current Chat voice authority is the canonical `chat-voice.js` runtime plus `premium-voice-v117.js`.

Stem Studio currently uses `conversation-voice-v122.js` and `editor-voice-barge-v117.js` in addition to premium voice.

Voice code has high regression risk. Do not modify it as collateral to UI or unrelated feature work.

## Database ownership

The recovered code expects a MySQL/MariaDB-compatible schema. Schema knowledge currently exists in three forms:

1. `schema.sql`
2. `upgrade*.sql` and `upgrade.php`
3. runtime `*_ensure_schema()` functions

Until the production database schema is compared, do not assume `schema.sql` alone represents the deployed schema.

A future database compatibility report should compare the real schema against **code expectations**, not just the historical SQL file.

## Security boundaries to preserve

- PDO real prepared statements (`ATTR_EMULATE_PREPARES=false`)
- CSRF validation on state-changing requests
- session regeneration on authentication
- server-side role/permission gates
- resource ownership checks
- user-agent/data-sharing policy checks
- shared-knowledge live-policy recheck
- encrypted AI credentials stored separately from DB data
- upload MIME/extension/size validation
- `/private` and sensitive-file HTTP denial

## Known structural debt

### 1. Version sprawl

The tree contains many `vNN` snapshots, including duplicate-content generations. Git history should replace this practice going forward.

### 2. Legacy shell + runtime patch architecture

Several current pages are assembled by rendering an old page and rewriting/injecting the modern runtime:

- Chat
- Account
- Stem Studio
- parts of Artist Listening

Do not perform a broad rewrite during recovery. Canonicalize one subsystem at a time after CI is stable.

### 3. Schema creation in application runtime

Multiple runtime modules can create/alter tables. Move toward one migration ledger after the real database is audited.

### 4. Historical tests without workflow orchestration

The `tests/` directory survived, but the old `.github/workflows` directory did not. Recovery CI must be rebuilt from the current tests.

## Protected areas during recovery

Changes here require focused tests and should not be bundled with unrelated work:

- `includes/chat-agent-policy-v236.php`
- user data policy/shared-knowledge code
- `chat-voice.js` and premium/conversation voice assets
- Artist Listening transcript persistence/AI boundaries
- Stem master clock/scheduler/transport/audio pipeline

## Recovery completion criteria

The repository can be considered re-baselined when:

1. current runtime docs are committed;
2. deterministic inventory can be regenerated;
3. fresh CI runs PHP syntax + key existing Node/PHP contract tests;
4. production DB schema has been compared to code expectations;
5. dead/versioned files are classified before deletion;
6. future work resumes normal feature-branch/PR/exact-head-green workflow.
