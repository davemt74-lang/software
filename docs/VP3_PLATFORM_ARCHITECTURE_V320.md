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
- Removing a Team membership must never delete the person's VP3 account, package or personal content.
- Commercial Team-seat limits come from package entitlements.

### Team General

- Each workspace has one canonical General conversation.
- The workspace owner and every current active Team member are automatically authorized.
- There is no separate chat invitation or participant grant for General.
- Authorization is evaluated from current workspace membership, so removal immediately removes General access.

### Human messaging

- Human conversations use `human_conversations`, `human_conversation_members`, `human_messages` and `human_message_requests`.
- Direct messages, friend chats, Team General and future group/channel conversations belong to the human messaging domain.
- Message text is not copied into generic activity/audit logs.

### Agent Chat

- Agent Chat remains the private user↔agent interface and retains its existing conversation/history system.
- Human messages must not be stored as Agent Chat messages.
- The Agent may later summarize or act on human conversations only through explicit, permission-aware tools.

### Profile Agent

- Public Profile Agent visitor conversations remain in `profile_agent_conversations` / `profile_agent_messages`.
- They are not social DMs and are not Team Chat.

## Commercial access and plugins

Packages and entitlements answer **what the member paid for / is allowed to use**. Plugin installations answer **what the member opted into**.

`music_workspace.access` is the commercial entitlement for Music Workspace.

`user_plugin_installations` records explicit plugin enable/disable state. Disabling a plugin hides/deactivates its working surface; it does not delete tracks, albums, releases, teams, messages or history.

Existing music users on Legacy Access are grandfathered while packages are remapped.

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
2. Team directories, presence, history and messages must be scoped to a shared workspace.
3. Blocks deny social DMs regardless of follow/friend state.
4. A pending Message Request permits only its initial requester message until accepted.
5. Team General authorization follows current Team membership dynamically.
6. Package changes do not mutate identity or Team relationships.
7. Plugin disablement preserves user data.
8. Agent Chat, Profile Agent conversations and human messages remain separate persistence domains.
9. Legacy role/account-type behavior is compatibility-only and may not be used to create new cross-workspace authority.

## Migration direction

Legacy Artist/Producer/Manager/Supervisor vocabulary can remain in database compatibility paths while user-facing and new authorization code moves to VP3 Member + capability/workspace terminology. Existing music data is migrated in place; no destructive rewrite is required.
