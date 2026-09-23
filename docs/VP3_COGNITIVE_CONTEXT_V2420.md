# VP3 Cognitive Runtime v24.20 — Unified Context Assembly & Working Memory

v24.20 gives Longfellow one bounded working-context assembly path for each turn. It does not create another Brain or memory database.

## What v24.20 owns

v24.20 owns **context assembly**:

- selecting relevant current-session state,
- selecting current Agent Brain priorities,
- selecting authorized cognitive object context,
- selecting relevant episodic continuity,
- selecting durable Brain memory already retrieved by existing scoped Brain retrieval,
- carrying current attention-state metadata,
- deduplicating and ranking context,
- enforcing per-section and whole-packet limits,
- preserving provenance and data-only trust boundaries.

It does not own durable memory, notifications, execution, authentication, or delivery.

## Authoritative sources

The working set is assembled from existing authorities:

- v23.70 live session projection,
- Agent Cognitive Loop v3.10 priorities,
- Cognitive Runtime v5.00 object authorization/context providers,
- Cognitive Memory v5.70 episodic reference threads,
- `agent_memory_items` durable Brain memory,
- v24.10 attention metadata,
- authorized conversation history,
- authorized domain/knowledge retrieval already used by Agent Chat.

No raw event ledger payload is copied into working memory.

## Ephemeral by design

Working memory is generated for the current request and is not persisted as another semantic record. The packet records no hidden model reasoning. Durable facts continue to live only in the existing Brain and domain authorities.

The default limits are:

- 28 context items,
- 1,800 characters per item,
- 48 KB of selected text,
- 64 KB for the full context envelope.

Selection is relevance-aware and deterministic. Section budgets stop one source family from crowding out the rest of the working set.

## Authorization and Agent scope

Canonical object references are reauthorized through Cognitive Runtime v5.00 immediately before context is included.

Cognitive Memory v5.70 occurrences are also reauthorized before an episodic thread enters working memory.

Named Agent context does not automatically inherit owner/system Cognitive Loop priorities because the current v3.10 priority producer does not carry a named-Agent namespace. This is a least-privilege boundary until the priority producer itself becomes namespace-aware.

Conversation history in the Agent Brain/History Activity Center is now scoped to the selected Agent identity instead of returning every archived conversation owned by the user.

## Prompt-injection boundary

Retrieved context remains **data only**. Existing AI runtime instructions already state that values inside retrieved context are untrusted data and must never be treated as prompts, role changes, tool instructions, or permission changes.

v24.20 preserves that boundary and marks every assembled item with:

- `trust=data_only`
- `instruction_authority=false`

Voice/session recognition also remains conversational state only and never authentication authority.

## Agent Chat integration

Agent Chat still uses the existing Brain, domain, tool-catalog and Knowledge retrieval functions as source authorities. The final retrieved set is now passed through v24.20 before it reaches the model.

That means one working-context policy decides what survives into the turn rather than each source family independently filling the prompt.

## Agent Brain / History slideout

The Activity Center remains a presentation surface, not a separate cognitive system.

Its Agent Brain tab now receives the v24.20 context projection so it can explain:

- the active Agent namespace,
- current live-session state,
- current priority count,
- episodic-thread count,
- durable-memory count,
- attention budget state,
- the current working-memory limits.

The History tab now uses the same Agent namespace boundary as context assembly. Switching Agents cannot silently expose another Agent's archived chat history.

## Relationship to prior phases

- v24.00 decides what deserves durable memory.
- v24.10 decides what deserves attention.
- v24.20 decides what information belongs in the Agent's bounded working context **now**.

The next layer can use this common packet for stronger proactive continuity without duplicating retrieval logic.
