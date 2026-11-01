# PH3H — Live phone calling certification

PH3G code and its automated tests establish the Cloud/SIP/secondary-Agent
**contract**, not the behavior of a real telephone call. PH3H provides a
non-billable readiness inspection and the manual staging gates below.

## Non-billable inspection

1. Open **Admin → Phone**. On the exact imported Twilio number choose
   **Check PH3H streaming readiness**. Only an administrator can do this.
2. The diagnostic performs a **read-only Twilio IncomingPhoneNumbers GET** and
   compares its SID, E.164 number, Voice URL and POST method with the fixed
   Cloud webhook. It verifies installed PH3G tables, subscription/Agent
   ownership, Cloud secret/TLS SIP configuration and per-number HomeServer
   secondary Agent opt-in. It never initiates carrier calls or changes routing.
3. If the carrier GET cannot be completed, its result is `unverified` and
   staging readiness fails closed. No credentials or raw provider errors are
   shown on the page.
4. Run `python -m telephony.preflight` in the independent HomeServer LiveKit
   worker environment. The command checks Cloud resolver mode, explicit speech
   models, credential separation and LiveKit WSS settings without connecting.

Even if both reports show **ready for staging**, their `live_certified` fields
remain `false`. Do not enable production traffic based on readiness alone.

## Supervised staging call record

Use a dedicated test DID, an approved caller and the smallest safe call window.
With the provisioned Twilio SIP trunk, LiveKit room/dispatch and worker:

| Gate | Evidence to record | Must pass |
| --- | --- | --- |
| Carrier ingress | Twilio signed parent CallSid, child CallSid and expected DID | Yes |
| SIP metadata | Exact LiveKit `vp3.parent`, `vp3.issued`, `vp3.proof` mapping | Yes |
| Resolution | Valid proof accepted once; forged/expired/other-child rejected | Yes |
| Agent access | Separate HomeServer secondary Agent, read-only turn, no owner tools | Yes |
| Voice | Spoken AI disclosure, clean STT transcript and intelligible TTS | Yes |
| Barge-in | Interrupt a response; exactly one ordered canonical Chat turn | Yes |
| Revocation | Disable number/Agent mid-session; next turn denied | Yes |
| Failure | Disconnect/failed SIP and voicemail fallback work as intended | Yes |
| Privacy | No private account data, tokens or recorded media exposed | Yes |

Record timestamps, sanitized CallSids, operator, result and deviations outside
the public UI. Verify applicable consent and recording rules before calling.
There is deliberately **no automatic certification button**: pass/fail requires
actual provider audio and operator review. Cloud turn-based AI and voicemail
remain independently available if real-time service is not enabled.
