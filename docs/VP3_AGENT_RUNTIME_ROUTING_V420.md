# VP3 Agent Runtime Routing v4.20

Section 10 makes Agent compute routing a single canonical decision boundary shared by text and streamed/voice Agent Chat.

## Canonical principal

The runtime decision is keyed by the authenticated VP3 user, the exact Agent namespace, and the current request surface. Agent identity selects configuration but never adds authorization. Conversation, Knowledge, tool, workspace, plugin, and memory authority remain governed by their own canonical boundaries.

## Compute modes

VP3 exposes three user-visible compute preferences:

- `auto` — prefer a paired/supported HomeServer and recover against it on each request; fall back to VP3 Cloud only when commercial cloud capacity and HomeServer cloud policy both allow it.
- `homeserver_only` — use HomeServer and never fall back to VP3 Cloud. VP3 also sends `cloud_allowed=false` to HomeServer, so HomeServer itself may not route the request through VP3 Cloud.
- `vp3_cloud` — execute directly through VP3 Cloud and do not contact HomeServer for scope, capability discovery, execution, or usage mirroring.

Per-Agent overrides remain sparse configuration layered over the account preference. They do not alter the Agent's data, workspace, plugin, tool, or messaging authority.

## One route plan

`vp3_agent_runtime_plan_v420()` resolves the current account preference, per-Agent override, cloud entitlement/token capacity, and—only for HomeServer-aware modes—the paired HomeServer scope and Agent Brain capability. It delegates the pure route choice to the existing AI Gateway v0.31 planner.

HomeServer receives cloud delegation permission only when all three conditions are true: the effective mode is `auto`, the authenticated HomeServer wrapper scope permits cloud, and VP3 confirms current cloud entitlement/token capacity. This prevents `homeserver_only` from becoming an indirect VP3 Cloud route and prevents Automatic mode from delegating cloud work that VP3 cannot legitimately fund.

Deterministic VP3 tools do not require an AI compute route and use a local `vp3_tool` plan. This prevents a tool-only request from probing HomeServer simply to determine where an unused language-model call would have run.

## Text and voice parity

`api/chat-v236.php` and `api/chat-stream-v121.php` use the same v4.20 plan and the same exact Agent principal. Voice is transport only.

Both paths:

1. authorize deterministic tools through the Section 8 v4.00 tool boundary;
2. plan AI compute only when no tool handled the request;
3. make one HomeServer recovery attempt when the plan permits it;
4. never fall back from `homeserver_only` and never permit HomeServer cloud delegation in that mode;
5. use VP3 Cloud only when the v4.20 plan permits it;
6. persist the same normalized execution provenance.

The streamed endpoint may emit a HomeServer answer as a single response delta because the paired HomeServer operation is currently request/response rather than token-streaming. That transport difference does not change routing or ownership.

## Direct VP3 Cloud isolation

An explicit `vp3_cloud` preference is a strict runtime boundary. Planning does not call HomeServer `tools.list`, capability discovery, relay status, or credentials. After execution, VP3 records the request in its own canonical execution ledger and does not mirror the completed cloud usage back to HomeServer.

## HomeServer compute sources

A successful HomeServer request can report one of three compute sources:

- `homeserver_local` — a model executed locally in HomeServer;
- `user_provider` — HomeServer used a provider configured by the user;
- `vp3_cloud` — HomeServer itself routed through VP3 Cloud under an Automatic-mode paired-wrapper policy that allowed cloud and had current VP3 capacity.

The last case remains source=`vp3_cloud` for billing truth but is recorded as actual route `homeserver_vp3_cloud`. This prevents cloud-through-HomeServer from being mislabeled as local compute and avoids a second VP3 usage-mirroring side effect.

## Canonical execution ledger

The existing `ai_execution_ledger` remains the single VP3 execution ledger. v4.20 extends it with:

- `runtime_version`
- `requested_route`
- `attempted_route`
- `actual_route`
- `route_reason`
- `fallback_reason`

The existing source/provider/model/token/cost fields remain intact. Older installations retain the legacy insert shape until the database upgrade adds the v4.20 route columns. Fresh setup and upgrade completion both install and verify the new columns.

## Invariants

1. Agent configuration never grants data or tool authority.
2. An explicit VP3 Cloud request never contacts HomeServer.
3. HomeServer-only requests never execute or fall back through VP3 Cloud, including cloud delegation inside HomeServer.
4. Automatic routing prefers HomeServer and permits any VP3 Cloud fallback/delegation only when HomeServer scope and VP3 commercial/token capacity both allow it.
5. Text and streamed/voice Agent Chat use the same runtime plan.
6. Both text and streamed/voice tool execution use the Section 8 authorization boundary.
7. HomeServer `compute_source=vp3_cloud` is never recorded as HomeServer-local compute.
8. One VP3 execution ledger records requested, attempted, and actual route plus fallback reason.
9. Tool-only requests do not probe model providers or HomeServer routing state.
10. A model/provider selection changes compute configuration only; it never widens the Agent principal or resource permissions.
