# Tracky V2.77 — Model Lifecycle, Drift Detection & Safe Rollouts

Tracky/HomeServer owns the lifecycle engine. VP3 Cloud mirrors governed status only.

Cloud can read active, shadow, canary, degraded state, golden-scenario status, environment-profile count, and recent lifecycle decisions.

Cloud cannot register candidates, promote models, assign canaries, activate models, evaluate drift, or roll back models.

Environment profile details, package checksums, lifecycle metadata, and local model artifacts remain on HomeServer/Tracky.

V2.77 uses a separate read-only `physical_model_lifecycle` cognitive module and `tracky.model_lifecycle` tool. The retained V2.71 physical-context contract remains unchanged.

`GET /api/tracky-model-lifecycle-v277.php` is read-only. No lifecycle write endpoint exists.
