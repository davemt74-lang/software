# Phase 16.2 — Knowledge Retrieval + Agent Context

## Status

Phase 16.2 adds owner-scoped Cloud Personal Knowledge retrieval to VP3 Agent Chat while preserving the HomeServer Local Knowledge privacy boundary.

## Runtime contract

- Agent Chat accepts `knowledge_scope` on send requests.
- Supported scopes are `all`, `off`, and `folder` with a canonical shared-folder ID.
- Missing scope defaults to `all` to preserve the existing Personal Knowledge behavior.
- Folder IDs are re-authorized server-side against `artist_transcript_folders_v177.created_by_user_id`.
- Retrieval uses `knowledge_chunks` joined to Personal `knowledge_items`; Cloud never trusts browser-supplied content.
- Both normal Chat (`api/chat-v236.php`) and streamed Chat (`api/chat-stream-v121.php`) use the same v16.2 retrieval service.

Example scope payloads:

```json
{"mode":"all"}
```

```json
{"mode":"folder","folder_id":42}
```

```json
{"mode":"off"}
```

## Agent Chat scope UI

Agent Chat exposes a compact **Knowledge** selector above the composer with three behaviors:

- **All personal knowledge** sends `{mode:"all", folder_id:0}`.
- **No personal knowledge** sends `{mode:"off", folder_id:0}`.
- Each owner folder is shown as **Folder · <name>** and sends `{mode:"folder", folder_id:<canonical id>}`.

`api/knowledge-scopes-v162.php` is a read-only discovery surface. It requires an authenticated user with Chat access and returns only `id` and `folder_name` rows whose `created_by_user_id` matches the signed-in user. It never returns a filesystem path or HomeServer Local Knowledge metadata.

`agent-context-v131.js` owns the browser selector and persists its selected value per signed-in user and active Agent. It enriches only Agent Chat `action:'send'` requests. Because this enrichment happens before the canonical `chat-voice.js` fetch routing is installed, the same selected `knowledge_scope` is carried by ordinary text turns and the fast `api/chat-stream-v121.php` voice path.

The browser selection is advisory only. The v16.2 retrieval service remains authoritative and re-checks folder ownership server-side before retrieval.

## Retrieval behavior

`includes/knowledge-retrieval-v162.php` performs deterministic relevance retrieval over existing Knowledge chunks. It caps query terms, candidate rows, result count, excerpt size, and the final context block. No external vector service is introduced.

The model receives a bounded `Cloud Personal Knowledge — UNTRUSTED EVIDENCE` context block. Retrieved text is explicitly reference data, not instructions. The prompt directs the model to ignore tool requests, policy changes, credential requests, or instruction overrides embedded in Knowledge content.

Results carry `[K#]` labels plus item, chunk, folder, excerpt, relevance score, and `cloud` provenance. The assistant message context persists the source metadata so existing clients can expose citations without receiving private file paths.

## Ownership and privacy

- `knowledge_items.created_by_user_id` is required to match the signed-in user.
- Retrieval is restricted to `knowledge_scope='personal'`.
- Canonical folder joins are owner-scoped.
- Folder discovery returns logical folder IDs/names only and is independently owner-scoped.
- Existing system/shared Knowledge context is preserved; only the old owner Personal Knowledge context entries are replaced by the v16.2 bounded retrieval block.
- HomeServer Local Knowledge is reported as `not_queried` in this phase.
- No `native_path`, `storage_path`, or Cloud file path is selected or exposed by the v16.2 retrieval service or browser discovery surface.
- HomeServer content is not silently blended into Cloud retrieval.

## Auditing

Each consulted Cloud Knowledge item uses the existing user-data usage audit seam (`user_data_usage_log_v236`) with the conversation ID and v16.2 purpose marker. The audit references logical Knowledge IDs and titles, never HomeServer filesystem paths.

## Compatibility

- Canonical Cloud folder table remains `artist_transcript_folders_v177`.
- Existing `knowledge_items` / `knowledge_chunks` schema remains authoritative.
- Native calendar/tool handling remains ahead of HomeServer/Cloud model routing.
- The canonical Cloud generator remains available as the defensive fallback if the v16.2 wrapper returns an unusable result structure.
- No Cloud database migration is required.
- No HomeServer protocol migration is required.
- Transcription intelligence remains v307; this phase does not mutate the transcription engine identity.

## Verification

`.github/workflows/knowledge-agent-context-v162.yml` runs PHP 8.1 and 8.3 linting, JavaScript syntax checks, the v16.2 behavior contract, the v16.2 privacy/ownership contract, the browser scope contract, and Phase 16.1/shared-folder regression contracts.

`tests/knowledge-agent-context-v162-browser-contract.mjs` locks the selector modes, canonical folder values, owner-only discovery, normal/streamed scope propagation, and the explicit HomeServer `not_queried` boundary.

The phase is complete only after the pull-request matrix and the exact post-merge `main` push matrix are green.
