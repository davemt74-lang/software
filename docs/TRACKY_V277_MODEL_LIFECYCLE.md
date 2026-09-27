# Tracky V2.77 — Model Lifecycle, Drift Detection & Safe Rollouts

## Authority

Tracky/HomeServer owns model registration, shadow evaluation, canary rollout, activation, drift response and rollback. VP3 Cloud is read-only.

Cloud does not promote, activate, rollback, install or train physical perception models.

## Lifecycle

New models move through `shadow → canary → active`. Tracky V2.77 requires verified V2.76 calibration evidence plus golden-scenario regression coverage before promotion.

Active regression can trigger local rollback to the prior known-good model. If no known-good fallback exists, the model is marked degraded rather than silently remaining healthy.

## Cloud synchronization

OTRO sends `physical_model_lifecycle.v1` as part of the existing `physical_context.v1` synchronization transaction.

The Cloud summary contains active/shadow/canary/degraded model key/version/status, known-good flags, canary percentage, aggregate environment-profile count and only the latest rollout action type/automatic flag/time.

Environment profile details, golden-scenario details, package checksums and full decision history remain local.

## Cognitive Runtime

V2.77 registers a separate read-only `physical_model_lifecycle` cognitive module with tool `tracky.model_lifecycle`. It does not alter the retained V2.71 read-only physical-context module.

## API and UI

`GET /api/tracky-lifecycle-v277.php` returns read-only lifecycle status. There is no Cloud write endpoint for promotion, activation or rollback.

`tracky.php` shows the joined lifecycle state plus active/shadow/canary/degraded counts.
