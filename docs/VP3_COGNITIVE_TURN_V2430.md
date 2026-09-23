# VP3 Cognitive Runtime v24.30 — Cognitive Turn Orchestration

v24.30 makes v24.20 Working Context the canonical input to the Agent turn cycle and gives VP3 one response-shaping policy for Agent Chat.

## What v24.30 owns

v24.30 owns **turn orchestration**, not execution.

For each turn it:

- resolves the selected System Agent or user-owned Agent namespace,
- consumes a bounded v24.20 working-context packet,
- carries live goal/task/session continuity,
- classifies the requested turn shape,
- provides server-only turn guidance to the model,
- records observable turn metadata after the result,
- makes that observable state available to Agent Brain and History.

Supported turn types are:

- `answer`
- `ask_user`
- `present_update`
- `propose_action`
- `request_approval`
- `execute_authorized_action`
- `remain_silent` for non-user/proactive turns only

A direct user message never becomes `remain_silent`.

## Canonical flow

The turn path is now:

`User / event → v24.20 Working Context → v24.30 Turn Control → model or existing authorized runtime → observable turn outcome`

After the turn, existing outcome, memory, attention, and live-session systems continue to own their respective state.

## v24.20 enforcement

The v24.20 context builder remains the only working-context selector.

The primary user-Agent path and Knowledge path previously assembled their own final arrays after policy/Knowledge retrieval. v24.30 closes that gap by sending those final source arrays through v24.20 before the model receives them.

This preserves:

- item and byte budgets,
- namespace boundaries,
- episodic reauthorization,
- object-reference authorization,
- deterministic source selection,
- data-only retrieved context.

## Server-only turn control

Turn control is passed to the provider separately from retrieved context.

Retrieved records remain **untrusted data**. They cannot become instructions merely by appearing in Agent Brain, a browser page, Knowledge, a meeting, a message, a research object, or any other source.

The v24.30 control can shape the response but explicitly cannot grant:

- tool permission,
- execution authority,
- approval authority,
- authentication authority.

If an action is requested but no existing server-authorized runtime confirms execution, the Agent must not claim that the action happened.

## Agent identity

The selected user-owned Agent identity is resolved server-side. Its configured role/name may guide identity, tone, and task focus, but user-authored Agent instructions cannot override permissions, approval requirements, authentication, safety boundaries, or tool authorization.

## Existing tool/runtime authority is unchanged

v24.30 does not replace:

- Agent Tool Authorization v4.00,
- Agent Runtime Routing v4.20,
- HomeServer execution controls,
- existing approval gates,
- Cognitive Orchestration v5.60 plan/step execution.

Cognitive Orchestration v5.60 remains the durable multi-step plan/run engine. v24.30 is the per-turn response-decision layer above it.

## Observable turn metadata

The Assistant message may persist a bounded `cognitive_turn` object containing:

- turn type and status,
- Agent namespace,
- conversation ID,
- goal/task/project/context references,
- count and section distribution of relevant context,
- hashes identifying context references,
- safe action labels/types,
- execution-route evidence.

It does **not** persist:

- chain of thought,
- hidden model reasoning,
- the raw v24.20 working-context packet,
- raw event payloads,
- hidden tool payloads.

## Agent Brain / History slideout

The Activity Center now uses the same cognitive architecture.

Agent Brain can show a **Current Turn** section such as:

- Answer
- Progress update
- Waiting for you
- Proposed action
- Needs approval
- Authorized action

It can also show the bounded relevant-context count and active task/goal reference without exposing hidden reasoning.

History remains scoped to the selected Agent identity and can label Assistant entries with their recorded turn type.

## Relationship to the cognitive stack

- v24.00 decides what deserves durable memory.
- v24.10 decides what deserves attention.
- v24.20 decides what belongs in working context now.
- **v24.30 decides what kind of turn the Agent should produce next.**

The next logical layer is longer-running goal/task continuity across turns and surfaces.
