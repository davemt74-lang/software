# Capability awareness — Section 3

Reminders and personal Calendar now issue structured server receipts for their verified results. Reminder receipts are stored in the same idempotency transaction as the operation; replay returns the original result. Calendar receipts distinguish confirmed writes, completed reads with or without matches, pending confirmations and failed execution. Discovery records inspection or interpretation failure. Model text never becomes execution evidence.

Canonical typed/spoken, streaming and legacy Chat persist and return the same normalized receipt. The LLM can select a read-only diagnosis operation for questions such as “Did that work?” or “Why did that fail?”. Diagnosis uses only the latest assistant receipt from the exact owner/Agent/conversation scope, and never retrieves another account's tool history. Missing/invalid receipts and intervening unanswered turns are explicitly unverified; older success is not revived. Historical completion is not a claim that current permissions or records are unchanged. No new tables.

The runtime inventory audit rejects contradictory readiness, duplicate IDs and drifted executor bindings. tools/check_agent_capability_inventory.py verifies assessed function definitions and separately lists remaining unassessed dispatchers. This first release intentionally does not claim complete discovery or structured receipt coverage for every legacy integration.

Acceptance criteria and scoring (10 points per section): current server authority/scope 2; factual availability/results 2; consistent Chat paths 2; failure and stale-state handling 2; automated regression/certification 2. A section reaches 10/10 for its stated scope only after the full PR checks pass. These scores do not certify live microphone hardware or model interpretation, which remain deployment tests.

Tests: 30 snapshot/audit cases, 28 discovery cases, 25 receipt cases, 16 production-endpoint cases using a controlled model/infrastructure fixture, 53 reminder lifecycle cases, existing Calendar recurrence/ownership checks, the recovery baseline, and the existing real-browser Chat/reminder suites in CI. HomeServer source remains unchanged and unpublished; Cloud-routed discovery obeys the existing model and compute privacy policy.

Deploy the complete Cloud archive and hard-refresh. No new migration is required beyond the reminder upgrade from v1.354. If that has not been installed, run upgrade.php first.
