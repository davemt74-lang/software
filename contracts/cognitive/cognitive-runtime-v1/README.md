# cognitive-runtime-v1

`cognitive-runtime-v1` is the machine-readable contract for **VP3 Cognitive Runtime v5.00**.

The runtime connects durable VP3 events and authorized domain objects to model-assisted cognition, one shared presentation decision, universal object cards, deterministic action policy, and outcome learning.

## Core boundary

The model may classify and correlate authorized context; identify opportunities, risks, commitments, unanswered questions, patterns and forecasts; propose registered actions; request registered object cards; and recommend a presentation surface.

The model may **not** grant itself resource access, invent executable tools/actions, execute SQL or browser/server side effects directly, emit trusted card HTML, bypass confirmations/approvals, widen Team/Profile Agent/Human Conversation/HomeServer boundaries, or make voice an alternate authority channel.

## Presentation rule

Background events do not automatically become individual Agent Chat turns.

The Presentation Arbiter chooses among:

`none | memory | brief | away_digest | notification | voice_announce | ask_user | chat_response`

`chat_response` is reserved for a direct user response or one explicit return digest.

## Cards

The LLM requests a registered card/object reference. The server reauthorizes the object, reads current canonical state, determines permitted actions, and renders the card.

## Meetings

Meeting Intelligence is part of v1. It covers preparation, reminders, recording/transcription linkage, post-meeting notes, commitments, follow-up opportunities, and continuity into the next meeting.

## Existing VP3 systems reused

- v19.2 Agent Event Infrastructure
- v4.10 Agent Brain memory scope
- v4.20 runtime/model routing
- v4.00 tool authorization
- action risk/approval plans
- durable Agent jobs
- notification ledger
- AI execution ledger

The contract intentionally disables the current v3.10 behavior that can automatically append an “Agent Brain priority update” message into Chat once v5.00 presentation is active.

## Compatibility

The exact `contract.json` digest is pinned in `SHA256`. Breaking wire changes require `cognitive-runtime-v2`.
