# Tracky V2.76 — Forecast Calibration & Physical-World Model Accuracy

## Authority

Tracky/HomeServer is the calibration authority. VP3 Cloud is a read-only semantic mirror.

Cloud does not record physical forecasts, settle their outcomes, train models, change confidence, or store Tracky's prediction/settlement ledger.

## Sync contract

OTRO sends `forecast_calibration.v1` as part of the existing `physical_context.v1` synchronization transaction. The payload is summary-only and includes model/version/kind/channel plus settled evidence count, original confidence aggregate, empirical accuracy, Brier score, and expected calibration error.

Cloud rejects confidence buckets, prediction records, settlement records, raw perception fields, and unknown summary/profile fields.

## Cognitive Runtime

V2.76 registers a separate read-only `physical_model_accuracy` cognitive module. The retained V2.71 `physical_context` module remains read-only and unchanged. The new tool is `tracky.model_accuracy`; it cannot train or settle models.

## UI and API

`tracky.php` shows verified settlement count and aggregate Brier/calibration error only when a governed report exists. Otherwise it says the system is awaiting evidence.

`GET /api/tracky-calibration-v276.php` returns the read-only report, summary and capability. No write endpoint is provided.

## Privacy

Raw frames, images, video, audio, embeddings, confidence buckets, individual predictions and individual settlements stay local to Tracky/HomeServer.
