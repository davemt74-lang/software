# Chat conversation recovery

The normal canvas stays a conversation plus composer. Stop appears only during a request. Failure/offline recovery adds one dismissible line with an optional Check history action. Technical session codes are bounded to ten entries in memory and appear only in Agent Brain; message text and provider errors are excluded.

## Recovery contract

- Failed, timed-out and stopped messages remain editable drafts. Recovery never sends, retries or repeats tools automatically. A stopped transport can still finish on the server: check canonical history before explicitly sending again.
- Drafts use tab session storage, separated by signed-in user, Agent and conversation. At most eight drafts of 32,000 characters survive for 24 hours. They are not a second conversation database.
- The selected conversation is a 30-day local hint per user/Agent; every restored ID is authorized by the normal server endpoint. A validated explicit conversation link takes priority. An unavailable history load blocks sending until the user opens a conversation or chooses New chat.
- Offline submit is blocked before automatic routing. Reconnect restores no request. Authorization loss clears this context’s recovery data and stops capture/output. Deletion clears that conversation’s draft.
- A request owns a conversation revision and an abort controller. Stop/navigation releases the composer immediately; a late result cannot modify a different conversation. A 120-second canonical send timeout preserves the draft.
- A successful reply is independent of sidebar history refresh. History failure cannot restore a successfully sent message as a failed draft.
- Voice waits for conversation restoration and actual recognition startup. It pauses when an unsent draft exists, so listening cannot erase a failed/interrupted message.

## Validation

`node tests/chat-canvas-voice-browser.mjs` runs the production Chat, tools, voice lease, voice controller, automatic planner and knowledge scripts in Chromium against controlled API/speech services. It checks compact desktop/mobile layout, natural speech routing, one spoken response, failed-route pause, Stop, provider failure, scoped draft/selection refresh restoration, offline submit, successful replies despite history failure, late-response isolation, session cleanup and Brain-only status diagnostics. Existing fast voice, proactive voice and notification contracts remain release gates.

These checks certify state and transport behavior. Actual microphone hardware, native speech services, provider credentials and real speaker acoustics still require a smoke test on the installed system. No backend schema changes are introduced.
