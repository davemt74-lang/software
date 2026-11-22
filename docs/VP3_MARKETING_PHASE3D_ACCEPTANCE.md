# Phase 3D: Campaign brief and private creative continuity

The owner authorized this build after Phases 3A–3C shipped in merged commit
`2568230c0e0d19e70a0cb26fddf91e2c2650def1` (PR #827).

## Scope and scoring

The starting score for this new section is 4/10: existing creative production
and Campaign review each earn two points. Completion requires all five areas:

| Area | Required evidence | Points |
| --- | --- | --- |
| Existing creative production | Canonical copy, image jobs, saved graphics and source/media checks remain authoritative | 2 |
| Campaign review | Exact reviewed draft saves, Merchant permissions and publication controls remain authoritative | 2 |
| Saved source continuity | An exact, revalidated opportunity brief is saved atomically with a reviewed private Campaign | 2 |
| Context and return | Saved Campaign objective, selected product/photo, saved creative and return to Campaign remain bound to the same owner/Agent/Chat | 2 |
| Follow-through and acceptance | Canonical milestones reach the shared Marketing Chat/Brain loop; denial, stale-state, retry, mobile and release tests pass | 2 |

The final software score is awarded only after focused tests, review, the complete
PR and merged-main checks, and byte-verified production packaging. Real host
acceptance remains a deployment exercise.

## Implementation boundaries

- Reuse `campaign_creative_assets` with the private role
  `private_marketing_brief_v1`; no migration or parallel campaign/creative store.
- Only canonical product-plan and completed-Campaign opportunities initialize a
  brief. Browser text cannot authorize a source association.
- An owner-selected save binds the source ref/revision into its existing signed
  preview and idempotency receipt. A removed or changed source blocks a new save.
- The saved original proposal is provenance. Current Campaign objective and
  revision govern subsequent private production; a changed Campaign requires a
  fresh creative review. Product-plan briefs retain their exact product.
- Copy and image generation use the current workbench, model controls, private
  source bytes and image receipts. No provider call happens just by opening a
  brief, selecting media or returning to a Campaign.
- Campaign-bound graphics remain private review work. The Product promotion
  publication path cannot bypass Campaign approval.
- `marketing:campaign-brief:<campaign_id>` supplies semantic milestones to the
  existing coordinator. There is no additional worker, publisher or notification
  system. Routine scanner timestamps do not create new source revisions.
