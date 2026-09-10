# VP3 Agent Chat Principal & Conversation Boundary v3.80

## Purpose

Section 6 makes Agent Chat conversation identity explicit and stable. Agent Chat is a private VP3 user-to-Agent domain. Its persistence must not be confused with Human Conversations or Profile Agent visitor conversations.

## Canonical principal

Every Agent Chat conversation belongs to exactly two identity dimensions:

1. the signed-in VP3 `user_id`; and
2. the selected Agent namespace.

A positive `chat_conversations.user_agent_id` identifies one active user-owned Agent belonging to that VP3 user. `user_agent_id IS NULL` permanently identifies the universal/system Agent namespace for that user.

`NULL` is not an unclaimed migration state. Creating the first user-owned Agent must never move older system-Agent conversations into that Agent automatically.

## Text and voice

Text and streamed/voice Agent Chat use the same v3.80 boundary functions for:

- resolving an owned active Agent;
- generating the SQL ownership scope;
- validating an existing conversation;
- creating a new conversation; and
- deriving the Agent principal supplied to data policy.

Voice is transport only. Switching between text and voice does not change the conversation owner or selected Agent.

## Existing conversations

An existing conversation is authoritative about its Agent namespace. When browser or cross-surface context explicitly supplies `user_agent_id`, it must exactly match the stored conversation, including explicit system-Agent ID `0` versus a positive user-Agent ID. A mismatch is rejected instead of switching principals.

When a stream continuation omits Agent identity entirely, the stored conversation may supply it so legitimate continuity can resume safely.

## Cross-surface continuity

Stem Studio, Video Editor and other Agent-enabled surfaces may carry a conversation ID for continuity, but that ID is not itself authority. The receiving Agent Chat runtime must re-check signed-in user ownership and the exact Agent namespace before loading or appending history.

## Separate conversation domains

Agent Chat persists in `chat_conversations` and `chat_messages`.

Human Conversations persist in the canonical v3.70 `human_*` domain. They are not ambient Agent context. A future explicit Agent messaging/summarization tool must first pass `vp3_agent_chat_human_conversation_allowed_v380()`, which delegates authorization to the v3.70 Human Messaging rules before any conversation content is accessed.

Profile Agent visitor conversations remain in `profile_agent_conversations` / `profile_agent_messages` and are not Agent Chat history.

## Security invariants

1. A conversation may only be read, continued or deleted by its owning VP3 user in its exact Agent namespace.
2. `user_agent_id IS NULL` always means system Agent; it is never automatically reassigned.
3. Creating, activating or selecting a user-owned Agent does not mutate prior system-Agent history.
4. Explicit system-Agent context cannot reopen a user-owned Agent conversation, and explicit user-Agent context cannot reopen system-Agent history.
5. An inactive, foreign or nonexistent user Agent cannot become an Agent Chat principal.
6. Text and streamed/voice execution use the same principal/scope/create boundary.
7. Human message bodies are not automatically included in Agent Chat context.
8. Profile Agent message bodies are not Agent Chat history.
9. Human Conversation access by an Agent requires a future explicit tool plus the current v3.70 conversation authorization check.
10. Agent context, plugin capability state and HomeServer/cloud compute routing operate inside the already-resolved conversation principal; they do not redefine ownership.
