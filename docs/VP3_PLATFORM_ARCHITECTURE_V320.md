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
- Teammate direct-message shortcuts that rely on a shared workspace use the same active-membership boundary.

### Human messaging

- Human conversations use `human_conversations`, `human_conversation_members`, `human_messages` and `human_message_requests`.
- Direct messages, friend chats, Team General and future group/channel conversations belong to the human messaging domain.
- Message text is not copied into generic activity/audit logs.

### Agent Chat

- Agent Chat remains the private user↔agent interface and retains its existing conversation/history system.
- Human messages must not be stored as Agent Chat messages.
- The Agent may later summarize or act on human conversations only through explicit, permission-aware tools.
- The sticky Agent composer is intentionally focused on conversation/voice capture; the Video Editor shortcut is not part of the composer UI.

### Profile Agent

- Public Profile Agent visitor conversations remain in `profile_agent_conversations` / `profile_agent_messages`.
- They are not social DMs and are not Team Chat.

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

## Migration direction

Legacy Artist/Producer/Manager/Supervisor vocabulary can remain in database compatibility paths while user-facing and new authorization code moves to VP3 Member + capability/workspace terminology. Existing music data is migrated in place; no destructive rewrite is required. The v3.50 Team lifecycle keeps `artist_team_members` only as an active compatibility projection until every legacy caller has moved to the durable workspace membership ledger. The v3.60 plugin lifecycle may materialize legacy Music workspace owners into `user_plugin_installations`, but explicit disabled rows remain authoritative and no professional content is rewritten or deleted.