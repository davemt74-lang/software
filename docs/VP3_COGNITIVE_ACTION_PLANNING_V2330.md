# VP3 Cognitive Operations v23.30 — Cognitive Action Planning

## Purpose

v23.30 turns an existing v5.50 cognitive proposal into an explicit, bounded **Action Plan Contract**.

It answers four questions before a user chooses to act:

1. What should happen?
2. Which existing VP3 capability owns each step?
3. Where is approval or authority required?
4. What canonical evidence would count as success?

v23.30 does not create another planner, workflow engine, scheduler, worker, approval system, tool executor, or database table.

Canonical flow:

**Prioritized cognitive item → v5.50 proposal → v23.30 Action Plan Contract → user review/acceptance → v5.60 orchestration → existing authoritative capability → canonical outcome verification → learning**

## Existing authorities

- Cognitive Feed / v23.10 select and prioritize the current item.
- v5.50 owns the durable proposal and accept/dismiss state.
- the Cognitive Runtime registry owns object/capability registration and permission metadata.
- the owning domain module remains authoritative for the underlying object.
- the registered tool/capability remains authoritative for its own action boundary.
- v5.60 owns orchestration and handoff tracking.
- canonical domain/outcome evidence determines success.
- v5.40 owns outcome learning.
- Phase 19 and existing workflow runtimes remain authoritative where workflow execution is involved.

v23.30 writes none of those states.

## Action Plan Contract

The contract is derived on read from the current authorized plan.

Required fields:

- plan id / kind / status
- source object reference
- source authority/module
- plan fingerprint
- capability id
- capability owner/module
- capability kind: read / prepare / write / external
- risk level
- approval requirement
- execution readiness
- bounded action steps
- canonical success criteria
- failure/replan criteria
- source-change/supersession rule
- execution boundary

## Bounded steps

Every contract contains exactly five conceptual steps:

1. **Inspect current state**
   - owner: Cognitive Runtime + source module
   - read-only
   - source object must still be authorized

2. **Prepare handoff**
   - owner: Cognitive Planning
   - no domain mutation
   - explains evidence, risk, and intended capability

3. **Authorized handoff**
   - owner: registered capability module when a tool exists
   - otherwise: explicit user decision required
   - existing approval/confirmation requirements remain authoritative

4. **Verify canonical outcome**
   - owner: Cognitive Orchestration
   - a tool return is not treated as completion
   - success comes from canonical outcome evidence

5. **Close and learn**
   - owner: Cognitive Outcomes & Learning
   - successful/resolved outcomes close the run
   - unsuccessful/ignored outcomes require replan
   - source fingerprint changes supersede the plan

## Capability resolution

If a plan has a registered `tool_id`, v23.30 resolves its existing registry metadata:

- module
- kind
- risk
- requires approval

The contract can only preserve or strengthen that boundary. It may never weaken registry risk or approval requirements.

If the live capability's module no longer matches the plan source authority, the capability fails closed. If live risk increases or a new approval requirement appears after the proposal was created, the contract becomes **replan required** rather than silently strengthening an already-accepted plan. v5.60 rechecks the same boundary at materialization, card rendering, and handoff.

If a plan references a tool that is no longer registered, the contract fails closed as **capability unavailable**.

If a plan has no tool, the Action Plan Contract is **review-only**. It does not invent a tool. The user must choose a concrete existing VP3 action before execution can begin.

## Success criteria

For executable plans, success is:

> Canonical outcome evidence for the underlying authorized object reports **successful** or **resolved** after the authoritative handoff.

For review-only plans, success is not auto-claimed. The contract requires an explicit user choice of a concrete existing action or dismissal of the proposal.

The contract also preserves v5.60 rules:

- unsuccessful / ignored → failed verification + replan
- source fingerprint changed → superseded
- lost authorization → blocked
- plan dismissed → cancelled

## Presentation

The existing proactive-plan Universal Card gains:

- Capability
- Capability owner
- Approval boundary
- Execution readiness
- Success criteria
- five-step bounded action contract

The existing orchestration-run card shows the same contract while a plan is active.

Browser Companion receives these changes through the same Universal Card path; no Chrome-only planning logic is added.

## Safety invariants

- no new schema
- no new plan persistence
- no new tool registration
- no tool execution
- no automatic external writes
- no approval bypass
- no authority bypass
- no model-generated hidden steps
- no hidden chain-of-thought storage
- source is reauthorized every time the contract is built
- registered tool metadata is authoritative
- missing tool registration fails closed
- capability-module drift fails closed
- increased risk / newly-added approval requirements require replan
- v5.60 rechecks the live boundary before materialization and handoff
- review-only plans cannot claim execution readiness
- handoff request is never completion
- canonical outcome evidence is required for executable-plan completion
