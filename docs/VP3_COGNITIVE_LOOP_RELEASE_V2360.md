# VP3 Cognitive Operations v23.60 — Cognitive Loop Release Hardening

## Release scope

v23.60 freezes the completed cognitive feature family:

- v23.00 — Cognitive Operations Core
- v23.10 — Unified Agent Priority Queue
- v23.20 — Proactive Opportunity Detection
- v23.30 — Cognitive Action Planning
- v23.40 — Proactive Agent Now + Agent Voice
- v23.50 — Outcome Learning & Cognitive Calibration

No new intelligence behavior is introduced in v23.60.

The purpose of this phase is to prove that the complete loop works as one bounded architecture and that the exact production deploy package contains the complete release.

## End-to-end cognitive loop

The release gate verifies this path:

**Canonical VP3 state / observations**
→ Cognitive Runtime v5.00
→ Cognitive Feed v5.30
→ v23.20 deterministic opportunity detection
→ v5.40 bounded learning
→ v23.10 selected priority queue
→ v5.50 plan proposal
→ v23.30 action contract
→ v5.60 orchestration handoff
→ canonical outcome evidence
→ v5.40 learning
→ v23.50 calibration
→ v23.40 Agent Now / persisted return briefing
→ Agent Chat and Browser Companion

## Release invariants

- one Cognitive Runtime
- one Cognitive Feed
- one learning/outcome ledger
- one planning/orchestration lineage
- no v23.x schema
- no model-granted authority
- no automatic external writes
- no approval bypass
- no execution bypass
- plan acceptance is not execution
- handoff request is not completion
- only canonical outcome evidence closes executable work
- Needs Attention / Next Up cannot be learned away
- bounded opportunity scan absence is not canonical deletion
- Browser Companion cannot execute Cognitive Runtime tools
- Agent Chat and Browser Companion use the same plan-learning semantics
- Agent Voice keeps one canonical cursor
- pure opportunities cannot create standalone voice interruptions
- calibration may only preserve or reduce proactive preview density
- calibration may not reorder the queue

## Production package contract

The Production Deploy Package must contain the exact runtime files required for the completed cognitive loop, including:

- v5.00-v5.70 Cognitive Runtime files
- v23.00-v23.60 Cognitive Operations files
- Agent Chat Cognitive Feed JS/CSS
- Cognitive Feed / Planning / Orchestration APIs
- Browser Companion Cognitive Now API
- Browser notification/voice runtime
- bootstrap and upgrade paths

The package must continue excluding:
- tests
- tools
- GitHub workflow files
- repository markdown documentation

## Runtime readiness

`vp3_cognitive_release_readiness_v2360()` is diagnostic-only. It checks that the expected modules/functions are loaded and, when a PDO connection is supplied, that the canonical v5.00-v5.70 schemas are ready.

It does not:
- mutate data
- repair schema
- create work
- execute tools
- change ranking
- change learning
- change voice delivery

## Release candidate standard

v23.60 is 10/10 only when one exact head passes:

- PHP 8.1
- PHP 8.3
- JavaScript syntax
- v23.60 end-to-end release contract
- v23.x backwards audit
- v23.00-v23.50 phase contracts
- v5.00-v5.70 retained contracts
- Browser Companion Cognitive Now
- Browser Companion proactive notifications / Agent Voice
- production deploy package integrity
- post-merge exact-tree comparison with zero file differences
