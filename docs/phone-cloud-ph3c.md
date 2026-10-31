# PH3C — Incoming voice and private voicemail

This is the first Cloud incoming-voice release, built on the merged PH2 and PH3A/B phone registry. It is **not** the PH3D real-time conversation worker.

## User outcome

1. A VP3 administrator selects an HTTPS callback URL in **Admin → Phone & Messaging**, and explicitly activates an already assigned Twilio number.
2. The number must belong to a member with an active agent and a subscription containing `phone.voice`. Twilio confirms the Voice URL update before the local inbound flag is enabled.
3. Calls to the number receive a short automated announcement using the assigned agent's name, explicit recording notice, and up to two minutes of voicemail. No LLM receives the call or audio.
4. Signed provider events create an account-scoped call receipt and idempotent recording metadata. The original owner stays fixed even if the phone number is later reassigned. Unknown callers are not authenticated.
5. The member opens **Phone → Calls & voicemail**. Recorded audio is retrieved on demand through a private same-origin endpoint using server-held Twilio credentials. No recording URL or credential is exposed in the HTML, and no audio is copied into the Cloud database.

## Callback URLs

- `/api/phone-voice-inbound-v130.php` — signed Twilio Voice POST; responds with TwiML or a generic `<Reject>`.
- `/api/phone-voice-complete-v130.php` — signed `<Record action>` POST; ends the call, never loops to greeting.
- `/api/phone-voice-recording-v130.php` — signed recording-completion POST; stores immutable owner-scoped metadata.
- `/api/phone-voice-audio-v130.php?id=...` — authenticated member-only playback proxy, bounded HTTPS audio download from Twilio.

The callback URLs are derived from the same exact public HTTPS origin. Carrier secret remains encrypted server-side. Public routes accept only bounded form POSTs; no browser cookies or caller phone number grant caller authority. Duplicate callbacks must not duplicate call or voicemail records.

## Release and activation checklist

- [ ] All code and runtime tests pass; all existing PR workflows green; merged code packaged.
- [ ] Deploy full Cloud ZIP while preserving runtime configuration, uploads and private files.
- [ ] Run `upgrade.php` to create `phone_voice_calls_v130` and `phone_voice_recordings_v130`.
- [ ] Configure/test Twilio credentials and the public inbound HTTPS callback.
- [ ] Confirm the account's voice subscription, recording-consent policy and number assignment, then enable **Incoming voicemail** for one test number.
- [ ] Call from a controlled phone. Check the greeting and beep, record a message, and verify signed completion, owner history, private playback, replay rejection and disabled-number rejection.

No carrier live-call claim is made by the automated test suite. Carrier costs, laws regarding recording consent and Twilio recording storage/retention must be reviewed before activation. Inbound greeting/voicemail is distinct from real-time AI calls, call transfers, business-hour routing, transcription, outbound calls, or HomeServer voice streaming, which are still future work.

## PH3C section scorecard

Source security, strict carrier signature validation, active agent/subscription authority, idempotent events, scoped storage, owner-only playback, admin activation gate, bounded transports, automated regressions, and exact merged release packaging are the ten evaluation categories. A 10/10 source certification requires all ten; actual carrier acceptance is tracked separately.
