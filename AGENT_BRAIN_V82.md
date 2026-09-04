# Stonefellow Agent Brain v82

## What v82 adds

- A repository default `SOUL.md` used as the starting personality and operating style for every Stonefellow user.
- A private per-user copy at `private/agent-brains/<user-id>/SOUL.md`, created automatically on first Agent Chat or Account use.
- A new **Agent Brain** section in My Account for editing/resetting the user's private SOUL.md and reviewing memory/tool summaries.
- My Account now uses the main Stonefellow workspace sidebar; Profile, Security, Agent Brain and Access navigation remains inside the account canvas.
- Persistent chat archive in `agent_chat_archive`.
- Structured long-term memory in `agent_memory_items`.
- Conservative extraction of dates, file references, preferences, decisions, commitments and recurring themes from user messages.
- Existing chat history is backfilled into the brain on first use after the v82 database upgrade.
- Agent Chat retrieves relevant memory and advertises only Stonefellow tools available to the signed-in user's permissions.
- The user's private SOUL.md is included in remote AI prompts as personality/working-style context. Server permissions and security remain authoritative and cannot be overridden by SOUL.md.
- Browser conversational voice mode using feature-detected `SpeechRecognition`/`webkitSpeechRecognition` and `speechSynthesis`.
- Voice transcripts travel through the same Agent Chat endpoint and are archived as `input_mode=voice` before entering the memory parser.
- Agent responses are spoken in conversational mode, after which listening resumes automatically.

## Browser voice behavior

The microphone button is enabled only when the browser exposes the Web Speech recognition API. Chrome/Chromium-based browsers are the primary v82 target. Browsers without speech recognition keep normal typed Agent Chat available and the voice control is disabled. Speech recognition is feature-detected rather than browser-name detected.

## Database upgrade

Run `upgrade-stonefellow-v82.sql` or `/upgrade.php` after deployment. It creates:

- `agent_chat_archive`
- `agent_memory_items`

## Privacy / security

- User SOUL files are stored under the existing denied `/private/` directory with restrictive file permissions where supported.
- The default repository `SOUL.md` is denied by `.htaccess` from direct HTTP access.
- Only the signed-in account can edit its private soul through `account.php`.
- Agent memory is keyed to the signed-in `user_id`.
- SOUL instructions cannot override permission checks or cause restricted data to be added to AI context.
