# Conversation handoff audit — 18 October 2026

Existing conversation recovery already preserves selected Agent/conversation and tab-scoped drafts, fences late results, blocks offline sends, and avoids automatic reconnect replay. Existing voice ownership, cancellation, native provider failures and Knowledge revision guards remain in place.

Confirmed gap: canonical Cloud Chat treated a failed or unverified HomeServer response as permission to start another Cloud inference after dispatch. A remote tool could already have completed before a timeout, provider failure, empty reply or rejected Knowledge receipt. The canonical runtime now blocks that second inference and uses the existing retained-draft / Check history recovery flow. Cloud fallback remains available when preflight never dispatched the turn. The v0.25 adapter resets attempt state before capability preflight so a prior attempt cannot contaminate the decision.

This applies to the canonical Cloud and extension Agent runtime. HomeServer requires no source change: its existing provider error path retains failed-run evidence and does not replay the turn. No schema, new UI panel, automatic retry, or exactly-once execution claim is introduced. Checking history cannot undo a completed external action; an explicit resend remains an owner decision.

Validation: `php tests/chat-handoff-continuity.php` exercises all sanitized failure classes, including contradictory success metadata, and preserves non-dispatched fallback. Existing complete Chat browser recovery and HomeServer inference cancellation suites remain acceptance checks.
