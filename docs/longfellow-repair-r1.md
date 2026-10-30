# Longfellow repair R1: authenticated identity and shared tool dispatch

Base: PH2 Cloud phone PR #639 (71590b7513850cd59f54a09bef988bd1e195bd82). Phone inventory, subscriptions, menu and admin settings are preserved. No HomeServer publication.

The capability snapshot and post-inference authority check now derive the agent ID from the authenticated principal, not the sanitized UI-context object. The sanitizer intentionally does not carry identity authority. This fixes named Longfellow conversations being rechecked as system-agent conversations.

Typed and streaming Chat share the existing work-control, Calendar, Radar, release and generic tool-dispatch sequence. Legacy send delegates to the canonical owner/Agent Chat runtime instead of maintaining a second pipeline. Calendar schema absence returns its verified upgrade blocker rather than falling through to a generic model response about access.

Regression coverage now uses the production Agent principal, resolver and SQL ownership boundary with real named Agent rows. Actual typed, streaming and legacy endpoints inspect capabilities, read real owned Calendar rows, diagnose receipts and reject foreign/wrong-Agent conversations. Model and infrastructure responses remain controlled fixtures; natural-language/model and real microphone behavior are not certified by this test. Updated historical source contracts follow delegation to the shared code instead of requiring old duplicated code in each endpoint.

Run the named-Agent endpoint suite, snapshot tests, Calendar recurrence tests, phone PH2 contracts and Recovery Baseline before merge. Subsequent repairs cover structured reminder outputs, today filters, voice continuity and proactive contamination.
