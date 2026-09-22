# Historical GitHub Actions workflows

These workflow definitions are preserved verbatim for release/phase provenance after the September 2026 CI consolidation.

Files in this directory are intentionally **not active GitHub Actions workflows**. Their contract tests remain in `tests/` and the current cumulative gates in `.github/workflows/` cover the maintained product families.

To restore a historical workflow for investigation, move or copy it back to `.github/workflows/` on a temporary branch. Do not make historical phase workflows permanent pull-request triggers again unless they cover a gap that is not represented by a cumulative gate.

Current CI policy:
- 11 pull-request validation workflows at most.
- Production Deploy runs only after merge to `main` or by manual dispatch.
- Recovery Baseline runs on pull requests, `main`, manual dispatch, and nightly.
- Historical workflow definitions stay here for auditability; historical test files are not deleted.
