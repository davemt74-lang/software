# VP3 CI workflow policy

## Goal

Keep regression coverage while avoiding one GitHub Actions workflow per historical development phase.

Before this consolidation the repository had 150 active workflow definitions and a broad pull request could schedule roughly 144 workflow runs. Keep the active set explicitly reviewed, with Production Deploy excluded from pull requests. The `activeExpected` allowlist in `tests/ci-workflow-consolidation.mjs` is the authoritative current workflow inventory; the original count of 12 is no longer current.

## Gate coverage

The original cumulative gates retain the responsibilities below. The test allowlist also includes focused gates added after consolidation; this list is not a separate filename inventory.

1. Recovery Baseline — broad PHP lint + durable baseline contract suite.
2. Browser Companion v22.80 cumulative regression — retained Browser/transaction contracts through the frozen transaction family.
3. Client Release Intelligence — current Browser Companion/HomeServer release integration.
4. Cognitive Loop Release v23.60 — cumulative cognitive runtime and Agent Now contracts.
5. Video Meetings v18.23 — cumulative meeting-intelligence contracts.
6. Webhook + Event Infrastructure v19.2 — durable Agent jobs/workers/events.
7. Profile Commerce Secure File v11.30 — cumulative Profile Commerce/Commerce contracts.
8. Public Funnel + Onboarding Continuity — public/auth/funnel contracts.
9. HomeServer Runtime Journey — canonical Cloud/HomeServer runtime journey.
10. Package Entitlements v3.40 — subscription/package permission composition.
11. Team Workspaces v3.50 — canonical Team lifecycle.
12. Production Deploy Package — merged-main/manual artifact authority only.

## Gmail composer and styled email gate

`gmail-composer-v1.yml` is an intentional, path-filtered feature gate for the shared Gmail composer, image attachments, and private styled email reader. It validates the persisted draft and send lifecycle against native MySQL, retains Gmail provider and inference-boundary regressions, and runs browser coverage for the composer and styled reader. Its automatic triggers are limited to the relevant Gmail, email, shared Chat/runtime, and test paths; it can also be run manually.

Future Gmail regressions should extend this gate rather than introduce another permanent workflow. Keep its reviewed filename in the consolidation allowlist. This gate supplements existing cumulative coverage and does not publish production deployment packages.

## Historical phases

Historical YAML definitions are stored in `.github/workflow-archive/`. The test files they reference remain in `tests/`; they have not been deleted.

When a current cumulative gate absorbs an older phase, the older workflow should stay archived. If a regression gap is found, add the missing contract to the appropriate cumulative gate rather than reactivating another permanent PR workflow.

## Release artifacts

`production-deploy-package.yml` is the sole general production deploy artifact workflow. Historical Browser Companion phase workflows must validate contracts only; they must not publish old phase ZIPs on normal pull requests.

Managed Browser Companion and HomeServer packages remain controlled by the Client Releases system.
