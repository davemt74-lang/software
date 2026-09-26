# Tracky V2.73 — OTRO/Cloud Contract Join + Active Perception

## Baselines

- OTRO/HomeServer: `cea60855397983c32374092e650e15844efc66e8`
- VP3 Cloud: `f58e94f78494f24606702373e784b7c777d9d811`

HomeServer v2.4 continuity, native authority and reconnect reconciliation are foundational. V2.73 extends them; it does not replace, fork or bypass them.

## Canonical paths

Cloud → HomeServer:

`Agent/Tracky → homeserver_execution_v220_execute() → existing HTTPS request queue → OTRO remote_bridge → paired Tracky API → local perception provider`

HomeServer → Cloud semantic state:

`local perception provider → OTRO Tracky semantic authority → existing HomeServer HTTPS session → /api/tracky-sync-v270.php → VP3 Tracky Cloud projection`

No additional pairing flow, broker, inbound port, second Cloud relay, or cross-database ID authority is introduced.

## Active perception

Supported request families:

- `refresh_current_view`
- `check_room`
- `find_entity`
- `re_evaluate`

Active perception is a governed sensor read only. It cannot control devices or execute physical actions.

The Cloud setting is disabled by default. A user must enable it before Agent Chat can ask the HomeServer for a fresh perception result. Optional automatic refresh of stale physical state is separately disabled by default.

## v2.4 reconciliation gate

Both sides honor v2.4 reconciliation:

- OTRO checks `federated_data.reconciliation_state('vp3_cloud')` before a fresh local perception request.
- Cloud checks `homeserver_reconciliation_v246_state()` before sending the request.
- While reconciliation is pending, HomeServer-backed context remains stale and V2.73 does not claim a fresh check was performed.

## Provider boundary

OTRO V2.73 defines the local provider interface and semantic authority. It does not add Node or pretend that the browser-based Tracky runtime is an always-on HomeServer camera engine.

If no provider is attached, the capability is reported but active perception returns `provider_unavailable`. A later packaged perception phase can attach a local provider without changing the Cloud contract.

Provider output is fail-closed. Raw frames, video, audio, embeddings, camera URIs and filesystem paths are rejected from the semantic projection.

## Rooms and identity

OTRO's existing Room & Device Automation registry remains the canonical local room namespace. Tracky references those room IDs instead of maintaining a competing room registry.

## Same-session Cloud sync

OTRO semantic synchronization reuses the DPAPI-protected HomeServer HTTPS session and HomeServer device identity already created by pairing. The derived Cloud endpoint is `/api/tracky-sync-v270.php` on the same origin. No new token is created.

## Honest freshness

If a request completes and semantic sync succeeds, Agent Chat re-reads the Cloud projection before answering. If local perception succeeds but Cloud sync does not, the response explicitly says the older Cloud state is not freshly verified.

Offline, privacy-denied, provider-unavailable and reconciliation-pending states remain explicit.
