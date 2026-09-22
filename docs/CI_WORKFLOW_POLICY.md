# VP3 CI workflow policy

## Goal

Keep regression coverage while avoiding one GitHub Actions workflow per historical development phase.

Before this consolidation the repository had 150 active workflow definitions and a broad pull request could schedule roughly 144 workflow runs. The active set is now intentionally capped at 12 workflow files, with Production Deploy excluded from pull requests.

## Active gates

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

## Historical phases

Historical YAML definitions are stored in `.github/workflow-archive/`. The test files they reference remain in `tests/`; they have not been deleted.

When a current cumulative gate absorbs an older phase, the older workflow should stay archived. If a regression gap is found, add the missing contract to the appropriate cumulative gate rather than reactivating another permanent PR workflow.

## Release artifacts

`production-deploy-package.yml` is the sole general production deploy artifact workflow. Historical Browser Companion phase workflows must validate contracts only; they must not publish old phase ZIPs on normal pull requests.

Managed Browser Companion and HomeServer packages remain controlled by the Client Releases system.
