# VP3 Transcription Intelligence · Wave Two

Wave two extends the registry-backed transcription intelligence system without changing the one-plugin / one-result ownership model.

## UI contract

Every enabled transcription plugin receives its own AI Summary tab. The active tab renders only that plugin's independently persisted result payload. Plugins are enabled or disabled from Transcription App settings; disabling a plugin removes its tab but does not destructively overwrite another plugin's stored result.

## New plugins

### Questions & Answers
Tracks answered and unanswered questions separately. Answers require transcript evidence; unsupported participant identity remains unknown.

### Requirements & Constraints
Extracts requirements, acceptance criteria, constraints and dependencies. Suggestions are not promoted to requirements unless the transcript supports that classification.

### Follow-up Tracker
Builds a follow-up ledger from actions, commitments, unanswered questions and dependencies with owner/timing/status only when supported.

### Opportunity Finder
Surfaces grounded product, commercial, content, relationship, workflow and research opportunities. Each opportunity includes a validation step so hypotheses are not presented as facts.

### Comparison / Change Report
Compares against explicitly related prior transcripts only. A prior transcript is eligible when it shares the same project track or conversation. Results are separated into New, Changed, Contradicted, Resolved and Still Open. The plugin becomes stale when that related context changes.

### CRM Intelligence
Read-only analysis for authorized CRM users. Contacts are matched only from stored CRM IDs or exact email addresses present in the transcript. Name-only identity inference is prohibited. The plugin can recommend CRM changes but never writes them automatically, and it becomes stale when matched CRM context changes.

### Research Brief
Manual-only public research. Private transcript, Agent Brain and Knowledge context remain separate from public web evidence. The report includes an overview, verified/mixed/uncertain findings, real research sources and unresolved checks. Research Brief results age out after 24 hours even when the transcript has not changed.

### Sentiment & Stance
Topic-specific stance analysis: support, opposition, mixed, uncertainty or neutral. It may describe directly observable tone but cannot perform psychological profiling or diagnose mental/emotional state.

## Execution model

AI plugins execute in batches of four. A selected batch must return every requested plugin result before any result from that batch is accepted. Plugin results stage independently and are then persisted as independent modules with transcript source hash, generation time, provider/model, contract version and any relevant context hash.

Stats remains deterministic and token-free. Comparison, CRM Intelligence and Research Brief are manual-only because they depend on external or cross-record context and should not run repeatedly during live transcription.

## Freshness

Most plugins are current when their transcript source hash matches the live transcript. Comparison also checks its related-transcript context hash. CRM Intelligence also checks its matched CRM context hash. Research Brief additionally expires after 24 hours.

## Save behavior

Agent Brain and Personal Knowledge saves continue to compile the current plugin results into the existing transcription-analysis integration. The source remains the transcription intelligence system; public research is clearly separated from private transcript and memory context.

## No schema migration

Wave two uses the existing `artist_transcript_master_analysis_v237.analysis_json` module container. No new database tables or SQL migration are required.
