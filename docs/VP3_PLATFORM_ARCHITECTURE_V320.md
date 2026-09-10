# VP3 Platform Architecture v320

## Product definition

VP3 is the personal AI, transcription, identity and personal-URL platform. Music is a professional capability layer that a member may opt into; it is not the definition of the VP3 account.

## Canonical model

**Identity → Profile/Social → Teams → Entitlements → Plugins → Human Conversations**

Agent Chat and Profile Agent visitor conversations remain separate domains.

### Identity

- `users` is the durable VP3 identity.
- New public signups are ordinary VP3 members; professional music roles are not required to create an account.
- `users.role` and legacy account-type rows remain migration compatibility until old installations are fully remapped.
- Admin is platform authority. Manager and Producer authority is contextual to a workspace.

### Personal profile and URL

- `user_profiles` owns the member's username, `/username` URL, public/private profile settings and link-in-bio data.
- Music profile sections extend that personal identity when Music Workspace is enabled; they do not create a second identity.

### Social relationships

- Follow is directional.
- Friendship is accepted/mutual.
- Blocks override social discovery and direct messaging.
- Messaging privacy supports Friends only, People I follow, Followers and Anyone.
- Followers/strangers that are allowed to initiate contact enter Message Requests unless the recipient already follows them.

### Teams / workspaces

- Team membership is a resource relationship, not an account type.
- Manager and Producer are workspace roles.
- A VP3 member may participate in multiple workspaces.
- `workspace_memberships_v350` is the durable Team relationship ledger. Membership state is explicit: `active`, `suspended`, or `removed`.
- `artist_team_members` remains an active-only compatibility projection while legacy Music/Team callers are migrated. It must never contain suspended or removed memberships.
- A pending Team invitation is not a membership and does not consume a Team seat. Invitation acceptance rechecks the invited identity, expiration, workspace authority and current seat capacity.
- Workspace owners never create credentials or passwords for invited people. Invitees sign in to an existing VP3 identity or create their own identity using the invited email address.
- Suspending a Team membership immediately removes workspace authorization while preserving the relationship history and VP3 identity. Resuming reactivates the same relationship if capacity permits.
- Removing a Team membership preserves the person's VP3 account, package, profile, personal content and Team lifecycle history.
- Commercial Team-seat limits come from the composed effective entitlement resolver; suspended, removed and pending people do not consume active seats.

### Team General

- Each workspace has one canonical General conversation.
- The workspace owner and every current active Team member are automatically authorized.
- There is no separate chat invitation or participant grant for General.
- Authorization is evaluated from current active workspace membership, so suspension or removal immediately removes General access.
- Team General read/unread state is bookkeeping only and lives outside conversation membership; a read cursor never grants workspace access.
- Teammate direct-message shortcuts that rely on a shared workspace use the same active-membership boundary.

### Human messaging

- Human conversations use `human_conversations`, `human_conversation_members`, `human_messages` and `human_message_requests`.
- v3.70 adds `human_conversation_reads_v370` as authorization-neutral read/unread state and `human_message_legacy_links_v370` as the idempotent migration ledger.
- Direct messages, friend chats, Team General and future group/channel conversations belong to the human messaging domain.
- Direct-message creation, sending and Message Request resolution serialize the two user identities in stable numeric order before locking conversation/request state. Messaging schema DDL never runs inside those transactions.
- Message Request acceptance/decline is bound to the current recipient identity, current pending request, active users and block state. A pending request permits one initial requester message and no recipient reply until acceptance.
- Blocks are checked on access and mutation. A block therefore revokes an existing direct-message surface even if an older conversation row remains for history.
- Team General access is never copied into `human_conversation_members`; it is recalculated from the current active Team relationship on every access/send.
- The legacy Team Chat endpoint is a compatibility adapter over the canonical `human_*` message ledger. It must not create new `team_direct_messages` rows.
- Existing `team_direct_messages` history is migrated once and linked by source ID so rerunning the migration cannot duplicate messages. Legacy read state is migrated into the canonical read cursor.
- Message text is not copied into generic activity/audit logs.

### Agent Chat

- Agent Chat remains the private user↔agent interface and retains its existing conversation/history system.
- v3.80 defines the canonical conversation principal as the signed-in VP3 `user_id` plus one exact Agent namespace.
- A positive `chat_conversations.user_agent_id` belongs to that user-owned Agent. `user_agent_id IS NULL` permanently means that user's system-Agent namespace; NULL is not unclaimed history.
- Text and streamed/voice Agent Chat use the same v3.80 principal, scope, conversation validation and conversation-creation boundary. Voice is transport only and does not change ownership.
- Existing conversations are authoritative about their Agent namespace. Explicit browser/cross-surface Agent context must match the stored namespace, including explicit system-Agent ID `0`; mismatches are rejected.
- Creating or selecting a user-owned Agent never reassigns older system-Agent conversations. Legacy automatic first-Agent history claiming is retired.
- Human messages must not be stored as Agent Chat messages.
- Human Conversations are not ambient Agent context. The Agent may later summarize or act on them only through explicit, permission-aware tools that first pass the current v3.70 conversation authorization boundary.
- The sticky Agent composer is intentionally focused on conversation/voice capture; the Video Editor shortcut is not part of the composer UI.

### Profile Agent

- Public Profile Agent visitor conversations remain in `profile_agent_conversations` / `profile_agent_messages`.
- They are not social DMs, Team Chat, or private Agent Chat.
- v3.90 defines the Profile Agent visitor conversation principal as the exact **owner + Profile Agent + visitor session** tuple. A conversation ID alone never authorizes a public read, poll, or message append.
- If an owner changes the Profile Agent, an older visitor conversation stays attached to the Agent that created it. The newly selected Agent cannot inherit or answer inside that older Agent's thread.
- Owner-side history access is deliberately broader than public visitor access: the profile owner may inspect and answer historical visitor threads after changing or retiring the Agent that originally represented the profile.
- Conversation state is authoritative: `open` permits automatic Profile Agent replies, `owner_joined` is human-owner takeover, and `resolved` is closed until a new visitor turn explicitly reopens the same authorized thread.
- Owner takeover is race-safe. Visitor turns and owner mutations serialize on the conversation row; after model generation the Profile Agent must re-lock and recheck that the conversation is still `open` before persisting an automatic reply.
- A visitor message received while the owner has joined is stored for the owner but does not trigger an Agent response. The owner must explicitly set the thread back to `open` to hand automatic replies back to the Profile Agent.
- Public browser continuity must discard a stale conversation ID when the Profile Agent principal changes instead of silently creating or reassigning a thread.

### User-owned Agent lifecycle

- `user_agents` is the durable identity anchor for a named user-owned Agent and for historical conversation ownership.
- v3.90 adds `retired_at` so removing an Agent from active use is a retirement operation rather than a hard delete.
- A retired Agent is inactive, cannot be selected for new Agent Chat/Profile Agent work, and is removed from normal active Agent settings surfaces.
- Retiring the currently selected Profile Agent disables/clears the public Profile Agent selection without deleting historical visitor conversations.
- Agent compute overrides may be cleaned up on retirement, but conversation rows, Profile Agent history, data-policy history and the durable Agent identity row remain intact.
- Retiring an Agent must not delete or reclassify Agent Chat or Profile Agent history. In particular, an Agent Chat conversation with a positive `user_agent_id` may never become the system-Agent namespace through retirement.

### Agent tool authorization and execution

- v4.00 makes the authenticated VP3 user the Agent-tool principal. Agent identity, conversation identity, packages, plugin state and legacy professional account roles never add resource authority.
- Professional production reads/actions resolve through the exact Music Workspace and exact track/resource boundary before data is returned.
- Owners and Managers operate only inside authorized workspaces. Producers remain limited to tracks explicitly assigned to them.
- Executable browser actions are rebuilt server-side; model/tool-provided `auto` flags, external schemes and unknown executable action types are not trusted.
- External provider work remains approval governed, and paired HomeServer actions remain executed by HomeServer rather than reproduced inside VP3.

### Agent Brain memory

- v4.10 makes the exact Agent namespace part of durable Brain ownership. Owner identity alone is not sufficient to retrieve, rank, reconcile or update an Agent memory.
- `agent_memory_items.user_agent_id` and `agent_chat_archive.user_agent_id` follow the same canonical Agent namespace as Agent Chat: positive ids identify exact user-owned Agents and SQL NULL is the system Agent.
- Archived Agent identity is durable even if a live conversation is later removed or an Agent is retired.
- Legacy memories recover Agent provenance from their source archive when possible. Ambiguous legacy owner-wide memory remains in the system-Agent namespace rather than being copied across Agents or guessed.
- Rolling conversation state, summaries, recurring themes, task/commitment reconciliation, archive retrieval and tool-history context remain scoped to the exact Agent.

### Agent compute and runtime routing

- v4.20 makes compute routing a canonical request boundary shared by text and streamed/voice Agent Chat.
- The routing principal is the authenticated user plus exact Agent namespace; per-Agent compute configuration selects a runtime but never widens data, tool, workspace, plugin or messaging authority.
- `auto` prefers a paired/supported HomeServer and permits VP3 Cloud fallback/delegation only when the paired HomeServer scope allows cloud and VP3 confirms current commercial/token capacity.
- `homeserver_only` never falls back to VP3 Cloud and passes cloud delegation disabled to HomeServer itself.
- `vp3_cloud` is a strict direct-cloud route: planning/execution does not contact HomeServer for scope, capability discovery, relay status, compute or usage mirroring.
- Deterministic VP3 tools do not probe an AI provider/runtime merely to produce a tool response.
- HomeServer compute is attributed distinctly as HomeServer local, user provider via HomeServer, or VP3 Cloud via HomeServer.
- `ai_execution_ledger` remains the canonical VP3 execution ledger and records v4.20 runtime version, requested route, attempted route, actual route, route reason and fallback reason alongside provider/model/token/cost facts.

## Commercial access and plugins

Packages and entitlements answer **what the member paid for / is allowed to use**. Plugin installations answer **what the member opted into**. These are separate state machines.

`music_workspace.access` is the commercial entitlement for Music Workspace. `user_plugin_installations` records explicit plugin enable/disable preference; it is not an entitlement ledger and it is not a workspace authorization table.

The v3.60 effective plugin state resolver composes commercial eligibility, the explicit installation preference, and narrowly-scoped legacy migration fallback. An explicit disable always wins. An explicit enabled preference whose entitlement is temporarily unavailable becomes `paused_entitlement`; entitlement loss does not erase the preference. Restoring eligibility therefore resumes the plugin without recreating its workspace or content.

A member who is commercially eligible but has never opted into a non-legacy plugin sees it as available rather than automatically enabled. Historical Music workspace owners may be materialized as enabled during migration, but migration never overwrites an explicit disabled installation.

Disabling a plugin hides/deactivates its working surface and removes its owner-specific Agent capability exposure; it does not delete tracks, albums, releases, teams, messages or history. Music Workspace collaborators derive access from current active Team membership plus the owning workspace's effective plugin state. A collaborator does not need to purchase or install the owner's Music plugin independently.

Existing music users on Legacy Access remain grandfathered while packages are remapped, subject to their explicit plugin preference.

## Music Workspace

Music Workspace is VP3's first professional plugin and may expose:

- multiuser player
- tracks and albums
- stems and version history
- recording/session notes
- releases and release calendar
- approvals
- collaborators and Team workspaces
- Producer tools
- Music Supervisor tools
- rights/splits/metadata
- music-aware Agent tools

A member can therefore be one VP3 identity with multiple workspace relationships and capabilities without becoming a separate “Producer account” or “Artist account.”

## Security invariants

1. Global roles must never authorize access to an unrelated Team workspace.
2. Team directories, presence, history and messages must be scoped to a shared active workspace.
3. Blocks deny social DMs regardless of follow/friend state.
4. A pending Message Request permits only its initial requester message until accepted.
5. Team General authorization follows current active Team membership dynamically; suspension/removal revokes it immediately.
6. Package changes do not mutate identity or Team relationships.
7. Plugin disablement preserves user data.
8. Agent Chat, Profile Agent conversations and human messages remain separate persistence domains.
9. Legacy role/account-type behavior is compatibility-only and may not be used to create new cross-workspace authority.
10. Pending Team invitations never grant workspace access or consume active seats.
11. Team invitation acceptance is bound to the invited VP3 identity and current workspace capacity.
12. Membership lifecycle mutations are transaction-safe and may not run schema DDL inside an active database transaction.
13. Plugin installation preference never grants commercial eligibility or unrelated workspace authority.
14. Explicit plugin disablement overrides grandfathering and removes owner plugin capabilities from Agent context.
15. Entitlement loss pauses an enabled plugin without deleting its installation preference, workspace, Team relationships or content.
16. Plugin registry schema DDL may not execute inside an active plugin lifecycle transaction.
17. Human message read state never grants conversation or Team authorization.
18. Direct-message lifecycle mutations lock user identities in a stable order before conversation/request rows.
19. Legacy Team Chat may read/write only the canonical human message ledger after v3.70 migration; the historical Team DM table is migration input only.
20. Human message bodies may not be copied into generic Agent/activity/audit persistence.
21. Agent Chat conversation ownership is the tuple of VP3 user and exact Agent namespace; a conversation ID alone is never authority.
22. `chat_conversations.user_agent_id IS NULL` always means the system Agent and may not be automatically reassigned to a user-owned Agent.
23. Explicit Agent context must match an existing conversation's stored Agent namespace before history is loaded or appended.
24. Text and streamed/voice Agent Chat must use the same principal/scope/create boundary.
25. Human Conversations may enter Agent operations only through an explicit tool that rechecks canonical v3.70 conversation authorization; they are never ambient Chat context.
26. Public Profile Agent conversation authority is owner + exact Profile Agent + visitor session; conversation ID alone is insufficient.
27. Changing the selected Profile Agent must never move an existing visitor thread to the new Agent.
28. `owner_joined` prevents automatic Profile Agent replies until the owner explicitly returns the thread to `open`.
29. A Profile Agent must recheck current conversation status after model generation and before persisting an automatic reply.
30. A resolved visitor thread may reopen only from a new authorized visitor turn for the same owner + Agent + session principal.
31. Retiring a user Agent preserves its durable identity and must not delete or reclassify Agent Chat or Profile Agent history.
32. Retired Agents may not be mutated through active Agent settings APIs or selected for new Agent execution.
33. Agent tool authority derives from the authenticated user plus exact authorized resource/workspace; Agent identity, conversation, package, plugin state and legacy professional role do not add authority.
34. Agent Brain memory, archive history, rolling state, summaries, themes and task reconciliation are scoped to the exact Agent namespace; retired Agent history may not be reassigned.
35. Text and streamed/voice Agent Chat must use the same canonical v4.20 runtime route decision.
36. An explicit VP3 Cloud route must not contact HomeServer for scope, capability discovery, execution or usage mirroring.
37. HomeServer-only routing may never execute VP3 Cloud, including indirect cloud delegation inside HomeServer.
38. Automatic routing may permit VP3 Cloud fallback/delegation only when both HomeServer wrapper policy and current VP3 commercial/token capacity allow it.
39. Canonical execution accounting must distinguish requested route, attempted route, actual route and fallback reason, including VP3 Cloud reached through HomeServer.
40. Deterministic VP3 tool responses must not probe HomeServer or cloud compute merely to resolve an unused model route.

## Migration direction

Legacy Artist/Producer/Manager/Supervisor vocabulary can remain in database compatibility paths while user-facing and new authorization code moves to VP3 Member + capability/workspace terminology. Existing music data is migrated in place; no destructive rewrite is required. The v3.50 Team lifecycle keeps `artist_team_members` only as an active compatibility projection until every legacy caller has moved to the durable workspace membership ledger. The v3.60 plugin lifecycle may materialize legacy Music workspace owners into `user_plugin_installations`, but explicit disabled rows remain authoritative and no professional content is rewritten or deleted. The v3.70 messaging migration copies historical `team_direct_messages` into `human_messages` once, records each source mapping in `human_message_legacy_links_v370`, migrates read cursors, and leaves the legacy table as read-only migration history rather than an active message store. Section 6 does not migrate Agent Chat rows between principals: existing `user_agent_id IS NULL` conversations remain system-Agent history, while positive `user_agent_id` rows remain owned by that exact user-owned Agent. Section 7 adds `user_agents.retired_at` in place; existing Agents remain current because the new column defaults to NULL. Future Agent removal marks the row retired instead of deleting it, so existing positive `chat_conversations.user_agent_id` values and `profile_agent_conversations.profile_agent_id` values retain their original principal. Existing Profile Agent conversations are not reassigned when the profile owner selects a different Agent. Section 9 migrates durable Brain memory into the same exact Agent namespace, recovering provenance from archived chat where possible and leaving ambiguous owner-wide legacy memory in the system-Agent namespace. Section 10 extends the existing `ai_execution_ledger` in place with route-attribution fields; older rows remain valid historical execution records, while new v4.20 runs persist requested, attempted and actual routes without creating a second billing ledger.
