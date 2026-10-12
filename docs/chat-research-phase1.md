# Chat multi-source research — Phase 1

The Chrome Research runtime had an unreachable message handler: both canonical project actions and Browser Research runtime actions used `research_action`. JavaScript selected the first project route, so runtime List/Start/State/Save/Cancel reached the wrong API.

Extension 22.10.1 uses `research_runtime_action` for the existing bounded research runtime and preserves `research_action` for canonical project creation and assignment. Server permissions, website grants, capture limits, draft-save review and stable extension identity remain authoritative.

Acceptance requires actual listener dispatch to the correct authenticated API for every runtime lifecycle action and project write, matching extension/request versions, retained connection/error tests, research/multi-site/transaction contracts, syntax, packaged Chrome checks and every current-head Cloud workflow. Phase score is 10/10 only when all these gates pass and the PR is merged. Subsequent Chat integration begins after this gate.
