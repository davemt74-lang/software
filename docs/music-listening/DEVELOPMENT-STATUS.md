# Music listening development status

## Current state

**Planning and source inspection only.** This package defines intended behavior and implementation work. The documentation branch changes no application code, database schema, deployed hosting configuration, active grant, royalty policy or payment.

The user has requested a deep audit and outline, including VP3 listening/analytics, metadata, a main catalog/streaming API and standalone Stoneward import. The next implementation phase in this plan is ML1. An implementation request should use the handoff in [delivery plan](DELIVERY-PLAN.md); unresolved financial decisions do not block metadata drafts or the definition of authorized public streaming.

## Source snapshots

| Repository | Inspected snapshot | Meaning |
|---|---|---|
| `davemt74-lang/software` | `2b69d47b04bb2bcdd74402189b820eef22ed1235` | Detailed original VP3 music/permissions/finance/hosting audit |
| `davemt74-lang/stoneward` | `253d1267c95ffefbcf3a79173429abbbba0c33fc` | Standalone catalog/rights/player/telemetry/commerce and recovery inspection |
| `davemt74-lang/software` documentation base | `884e88342dd59d6c630e0c1abe097ff5739f6a56` | Refreshed main before preparing this package; intervening changes concern Gmail/Operator and shared integration files. Relevant source must be rechecked at implementation time. |

Pinned source references appear in [source audit](SOURCE-AUDIT.md). Source inspection does not identify the live deployment version or prove deployed runtime behavior. The missing direct-connector counterpart finding applies to these inspected checkouts; it does not rule out another branch or separately installed endpoint.

## Evidence available

| Check | Result and limit |
|---|---|
| `node tests/music-module-runtime.mjs` in original VP3 audit | Passed. Scoped JavaScript/runtime evidence only. |
| `node tests/music-workspace-resource-ownership-v330.mjs` in original VP3 audit | Passed. Structural/source contract evidence; not a live database isolation test. |
| PHP runtime/API checks | Not executed in the audit environment because PHP was unavailable. This is a verification gap, not a failed application assertion. |
| Stoneward tests during this audit | Not rerun. Test files and historical release notes are source material, not fresh verification. |
| Real iPhone/Android, deployed media headers, live hosting and joint VP3/Stoneward flow | Not performed. Required release evidence remains pending. |
| New metadata, provider API, import adapter, workspace dashboard, music accrual/payout implementation | Not implemented by this planning work. |

Documentation review checked the seven-document package for consistent requirements, phase dependencies, ownership, privacy and financial boundaries. The local documentation check verified 40 relative links and 49 pinned source targets/line anchors across 42 source files; all resolved. It also checked unique requirement/decision/acceptance labels and whitespace. These checks validate the handoff artifact, not application behavior. No application tests were rerun for this documentation-only change.

## Phase status

| Phase | State |
|---|---|
| ML0 — Audit and specification | Source audit consolidated; planning package ready for review. Product/financial open decisions remain identified. |
| ML1 — Shared catalog, metadata, identity and authority | Not started |
| ML2A — Native listening measurement and receipts | Not started |
| ML2B — VP3 catalog/streaming provider | Not started |
| ML2C — Stoneward import/source adapter | Not started |
| ML2D — Combined one-recording pilot | Not started |
| ML3 — Music Workspace analytics | Not started |
| ML4 — Funded earnings and statements | Not started; policy decisions required before real accrual |
| ML5 — Payouts and reconciliation | Not started; settlement/provider decisions required |
| ML6 — Artist Agent, support and release acceptance | Not started |
| MH1 — VP3-managed Stoneward hosting | Not started; actual operated runtime/provisioning must be established |

No phase is scored 10/10 by this source audit. Completion requires the named observable criteria and appropriate runtime evidence. A documentation review cannot certify playback, financial correctness or a live service.

## Next action

Review the planning package as a coherent whole, then begin ML1 from current main with the source paths and tests in the [ML1 handoff](DELIVERY-PLAN.md#ml1-engineering-handoff). Preserve ongoing Gmail/Operator work and existing Stoneward behavior. The first combined product milestone is ML1 plus ML2A–ML2D, not the complete royalty and managed-hosting launch.

Update this file after each implementation PR with its exact commit/PR, passed checks, live evidence still missing, changed decisions and the next bounded work item. Keep detailed policy choices in their owning registers to avoid conflicting copies.
