# Agent core startup health correction

Validated starting revision: `38fb346f456e9c42d25a06e3e38c53651cd5f863` (PR #724).

## Failure and correction

The standalone Initiative API entrypoints called `vp3_participant_scope_v1()` without loading its canonical module. The rendered Chat page loaded that module later, but separate API requests do not inherit the page's PHP includes. The health catch converted the undefined-function error into `context_unavailable`; Chat rendered “Background preparation status is unavailable.” Test fixtures defined a substitute accessor and masked this production dependency.

Initiative now explicitly loads `participant-updates-v1.php`. A new regression starts from the actual application bootstrap, checks the accessor's real source, and proves participant denial before private database access. Existing dependent fixtures now use the canonical participant scope instead of declaring replacement permission functions.

The heavy read-only context projection also now releases the PHP session after authenticating the owner and validating the rendered page binding. A slow projection cannot hold the same-session lifecycle heartbeat behind it. A two-worker HTTP regression holds that projection open and verifies that the real activity API attaches exact saved-Chat health before the projection is released, without inference.

## Acceptance criteria and evidence

| Criterion | Verification |
|---|---|
| A standalone owner API has the real participant boundary | Real-bootstrap regression, including source reflection and owner database boundary |
| Participant presence cannot obtain owner background authority | Real-bootstrap denial, scope, replay and actual canonical participant fixtures |
| Slow projection cannot block core startup behind the PHP session | Two actual PHP workers sharing one session and a held heavy projection |
| Missing first health is distinct from a verified service/access failure | Chromium startup, missing response, explicit failure and Retry scenarios |
| A stalled heartbeat releases its client lock and can recover | Actual activity controller AbortController deadline and Retry browser regression |
| Optional worker observation failure cannot disable permitted open-Chat execution | Corrupt/locked/unreadable observation tests and actual canonical foreground pump |
| Observer reads do not create or chmod runtime storage | Filesystem regression and nonblocking shared-lock coverage |
| Account, Agent, conversation and page-binding isolation remains intact | Browser stale-context scenarios, real API lifecycle, scope and replay tests |
| Explicit Pause and malformed preferences remain preserved | Browser Pause/reload and real HTTP malformed-policy preservation |

Core activation remains automatic through accepted owner Chat activity. Retry requests a fresh status through the existing controller; it does not grant permissions, reset preferences, start another conversation engine, or manufacture active health. Text and voice delivery retain their existing independent guards.

## Verification limits

Local HTTP and browser tests use controlled identity/provider/source fixtures where documented. The standalone bootstrap regression specifically removes the substituted participant accessor that hid this defect. Native database concurrency is covered by GitHub's MySQL jobs. Repository tests and a verified deployment package do not establish the configuration or behavior of a particular installed website, its provider credentials, or a closed-browser scheduled worker.
