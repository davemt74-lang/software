# VP3 Team Chat Runtime Retirement v3.80

Section 6 completes the runtime side of the v3.70 human-messaging consolidation.

## Canonical runtime

`api/team-chat-v320.php` is the only Team Chat API implementation. It is a compatibility surface over the canonical v3.70 human-messaging service, not a separate messaging domain.

Historical URLs remain deploy-safe shims:

- `api/team-chat-v81.php` → `api/team-chat-v320.php`
- `api/team-chat-v103.php` → `api/team-chat-v320.php`
- `api/team-chat-v109.php` → `api/team-chat-v320.php`

The shims contain no authorization rules, database queries, message persistence or role logic of their own.

## Persistence boundary

New Team direct messages use `human_conversations`, `human_messages`, `human_message_requests` and `human_conversation_reads_v370` through the v3.70 service.

`team_direct_messages` is retired runtime storage. The table may remain on upgraded databases only as migration input. The only PHP code permitted to reference it is `includes/human-messaging-v370.php`, whose idempotent migration copies historical rows into the canonical human ledger and records source mappings.

No API, page, widget or background runtime may read, insert, update or delete `team_direct_messages` after v3.80.

## Authorization boundary

Legacy Team Chat URLs no longer authorize from global Artist/Manager/Producer/Supervisor roles. The canonical adapter derives peers from current active workspace relationships, applies current Chat Settings, and delegates message lifecycle/blocks/read state to v3.70.

This means suspension or removal from the shared Team relationship immediately removes the legacy chat shortcut as well. Historical URLs cannot bypass current workspace authorization.

## Response compatibility

The v320 poll payload keeps the modern `users` directory for v109 clients and also exposes an `online` compatibility field for v81/v103 clients. `online` contains only the currently-online subset, matching the historical contract. Both arrays are generated from the same scoped canonical peer set.

History, send and read response shapes remain compatible with the historical widget contract while persistence and authorization are canonical.

## Invariants

1. There is exactly one Team Chat API implementation: v320.
2. v81, v103 and v109 are compatibility shims only.
3. No legacy Team Chat URL can use global roles as workspace authority.
4. No PHP runtime outside the v3.70 migration service may reference `team_direct_messages`.
5. Old clients may continue using their historical URL and poll response field while receiving the same canonical authorization and data as current clients.
6. Retiring the runtime does not delete legacy database rows; migration history remains available for verification and rollback analysis.
