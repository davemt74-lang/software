# VP3 Cognitive Runtime v24.10 — Unified Attention Engine

v24.10 turns the presentation rules that already existed across Agent Chat, Cognitive Presentation and Browser Companion into one interruption policy.

It does **not** replace the existing delivery systems.

```
Cognitive observations / Notifications / Browser attention candidates
                              ↓
                  Cognitive Attention v24.10
                  score · band · budget · cooldown
                              ↓
             selected presentation surface
                    ↙                     ↘
       Cognitive Presentation v5.10     Browser Companion v21.40+
       web digest / Agent Voice         claim / visual / voice receipts
```

## Authority split

v24.10 owns **attention policy**:

- how important a signal is,
- whether it may interrupt,
- whether voice is allowed,
- whether it belongs in notification, digest, brief, memory or no surface,
- interruption budget,
- repeat cooldown,
- focus/quiet/non-interruptible suppression.

Existing systems continue to own **delivery mechanics**:

- Cognitive Presentation v5.10 owns web presentation state, return-digest cursor and Agent Voice cursor.
- Browser Companion notification delivery v21.40 owns Chrome claims, visual delivery, voice delivery, snooze and dismiss receipts.
- The canonical notifications table remains the notification record authority.

There is no second notification queue and no second voice queue.

## User-global interruption budget

The default budget is **3 non-critical interruptions per 30 minutes** across:

- the system Agent,
- named Agents,
- web Agent Voice,
- Browser Companion visual alerts,
- Browser Companion voice.

Agent namespace remains on the receipt for provenance, but the budget is counted per user so multiple Agents cannot each spend a separate interruption allowance.

Critical signals can bypass the budget. They are still subject to privacy and interruptibility rules for voice.

## Attention bands

Signals are normalized to a 0–100 score using urgency, impact, goal relevance, confidence and novelty, with structured priority acting as a floor.

Current bands:

- **Critical:** 90+
- **Urgent:** 80–89.999
- **Elevated:** 65–79.999
- **Ambient:** 45–64.999
- **Quiet:** below 45

Legacy notification priority 100 is not treated as automatically critical. v24.10 normalizes notification semantics first:

- security, failure, error and risk can enter the critical band,
- approvals/confirmation are urgent,
- meetings/bookings/calendar items are urgent but budgeted,
- ordinary attention notifications are elevated/urgent based on their normalized signal.

## Surface rules

Direct user requests always stay in Chat.

A required user response normally becomes `ask_user` with a notification pair. Non-critical response requests are deferred to a brief during focus/quiet state or after the budget is exhausted.

Critical attention normally becomes a notification. It can use Agent Voice only when:

- Agent Voice is enabled,
- the current live session is interruptible,
- focus/quiet is not active,
- the content is non-sensitive,
- a voice-safe summary exists.

Urgent attention can interrupt within budget. Elevated attention becomes a brief or return digest. Ambient information becomes memory. Low-confidence/noisy information can remain unsurfaced.

## Preview vs reserve

Candidate discovery does not spend interruption budget.

Browser Companion first ranks candidates, previews v24.10 policy, and only after one candidate wins a delivery claim does it reserve an attention receipt. If the budget changes between preview and claim because another surface acted first, the Browser claim is released without surfacing.

This prevents a list of unseen candidates from consuming the user's budget.

## Cross-surface concurrency

Reservation uses a short MySQL/MariaDB advisory lock keyed to the user. This serializes simultaneous web/Chrome decisions around the final budget check. Signal fingerprints remain unique and idempotent even if advisory locking is unavailable.

## Repeat cooldown

A previously interruptive planned/delivered signal starts a **30-minute repeat cooldown** for the same signal key across Agent namespaces.

Silent, deferred and suppressed decisions do **not** start that cooldown, so a later escalation is still eligible.

Critical escalation is not blocked by the non-critical repeat rule.

## Voice and return digests

Agent Voice now passes through v24.10 for both individual notification speech and return-digest speech. The old digest early-return path no longer bypasses the attention engine.

Explicit STOP/suppression still advances the existing v5.10 voice cursor so the same proactive report is not replayed. v24.10 records that interruption as dismissed.

## Browser Companion

Chrome candidate lists are sorted before attention budgeting. Only the selected claim reserves budget.

Visual delivery and dismissal reconcile back into the central attention receipt, while the Browser delivery ledger remains the source of truth for Chrome claim tokens, snooze state, open state and per-device delivery state.

## Privacy

Attention receipts store policy metadata, not user content. They contain keys/fingerprints, source/category, score/band, selected surface, reason code, sensitivity flag and delivery state.

The receipt table does not copy notification title/body, message content, browser page text, URLs, voice text or model reasoning.

## Retention

Attention policy receipts are retained for 90 days per user and pruned during arbitration. Existing domain and delivery records keep their own independent retention policies.

## Relationship to v24.00

v24.00 decides **what deserves durable memory**.

v24.10 decides **what deserves the user's attention now**.

Those are intentionally separate decisions: something can be important enough to remember without being important enough to interrupt.
