# VP3 Cognitive Runtime v24.40 — Goal & Task Continuity

v24.40 makes existing VP3 work resumable across turns and surfaces without introducing another task manager.

## Architectural rule

v24.40 is a **continuity projection**, not a new durable work system.

It reads from existing authoritative systems:

- Agent Goals / milestones / commitments,
- Agent Workflow Runs and dependency edges,
- accepted Cognitive Orchestration v5.60 plans,
- Video Meeting commitments and follow-through,
- Browser transaction continuity,
- v23.70 live-session state.

It does not create a second goal table, task table, workflow queue, approval system, or execution path.

## Unified continuity states

Existing subsystem states are projected into a small shared vocabulary:

- `working`
- `ready`
- `needs_approval`
- `needs_user`
- `blocked`
- `repair_needed`
- `needs_planning`
- `needs_review`
- `verifying`
- `scheduled`
- `tracking`
- `paused`
- `planned`

The source subsystem remains authoritative. The normalized state exists only so Agent Chat, Working Context, and Agent Brain can reason consistently about what is still open.

## Agent scope

Agent Workflows already carry `agent_id`, so v24.40 scopes them to the selected Agent.

Cognitive Orchestration v5.60 already carries `agent_namespace`, so accepted plans remain namespace-scoped.

The older Goal Strategy tables, meeting commitment state, and Browser transaction continuity are owner-scoped but not Agent-namespaced. v24.40 therefore exposes those owner-global authorities only to the System Agent until their underlying schemas carry an explicit Agent namespace.

This prevents a named Agent from silently inheriting unrelated owner-global work.

## Resume behavior

The highest-value open continuity item becomes the current resumable focus.

A user message such as “continue” now behaves according to authoritative state:

- `needs_approval` → request approval; do not execute.
- `needs_user` → ask for the required user decision.
- open/resumable work → continue from the existing task/goal/workflow context.
- no open continuity → normal answer behavior.

No duplicate task is created merely because the user says “continue.”

## Working Context

v24.20 now includes one bounded `continuity` section.

The section contains a compact projection of open work and its source references. It is data-only and grants no execution authority.

The broader v24.20 packet limits still apply, so continuity cannot crowd out the rest of working memory.

## Live session

When a turn resolves a canonical `goal_ref`, `task_ref`, or `project_ref`, those references are flowed back into the existing v23.70 live-session record.

That lets the current session accurately reflect what the Agent is working on without storing a second continuity record.

## Agent Brain

The Activity Center Agent Brain now includes **Open Work** from the same v24.40 projection used by the runtime.

It can show:

- what is currently working,
- what is ready,
- what is blocked,
- what is scheduled,
- what needs approval,
- what is waiting for the user,
- what is being verified or tracked.

This is observable workflow state, not model reasoning.

## Performance bounds

Continuity retrieval is intentionally bounded:

- at most 12 open Agent Workflow rows inspected,
- at most 4 owner-global goals inspected,
- at most 8 accepted Cognitive Plan runs inspected,
- at most 20 meeting commitments scanned with at most 4 surfaced,
- at most 8 active Browser continuity rows inspected with a batched proposal-count query,
- at most 16 unified continuity items returned,
- at most 8 items included in Working Context,
- at most 6 items shown in Agent Brain.

## Relationship to prior phases

- v24.00 — what becomes durable memory.
- v24.10 — what deserves attention.
- v24.20 — what belongs in Working Context.
- v24.30 — what kind of turn the Agent should produce.
- **v24.40 — what existing goal/task/work the Agent should resume across turns and surfaces.**

The next phase can build on this unified continuity layer for stronger proactive follow-through and cross-surface handoff without duplicating work records.
