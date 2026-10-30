# Longfellow reminder interpretation and reads

Personal reminder reads could fail when the model echoed context fields into its plan. Tool interpretation now uses a dedicated system contract, independent of persona prompts. Both reminder and capability plans reject unknown fields, retry malformed output once, and distinguish provider unavailability from validation failure. Diagnostics contain stage, byte count and trace ID, never model output or reminder content. Reinterpretation precedes execution; no mutation is replayed.

Reminder list plans use `view: open|today|overdue|all`. Today is computed using the conversation's IANA timezone and includes completed occurrences, so asking what was planned today is distinct from asking what remains open. Existing reminder schedules, ownership, occurrence completion and delivery leases are preserved. No schema upgrade is introduced by this repair.

Validation: actual OpenAI/Claude payload builders with fixture HTTP; real SQLite reminder lifecycle and local-midnight reads; production typed, streaming and legacy endpoints with named Longfellow, malformed-output fixtures and no-mutation assertions. These tests do not certify a live AI provider or physical microphone.
