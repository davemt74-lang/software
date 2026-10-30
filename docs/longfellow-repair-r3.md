# Longfellow voice continuity and proactive evidence

Automatic microphone restarts previously imported any composer text. After Chat restored a failed request as a draft, the next spoken phrase could be appended to that old request. Draft continuation now requires explicit microphone activation or user editing. Recognition result indexes still deduplicate cumulative events; turn and action idempotency remain in the existing Chat transport.

Transient recognition interruptions now receive bounded backoff for four retry attempts within a rolling failure window; permission and missing-device failures still stop immediately. Exceptions while preparing a voice request release pending state, allowing the next turn without page reload. No failed or uncertain request is automatically replayed.

Chat submission no longer labels the user's question as a task. Proactive builders exclude Chat activity from automatic resume suggestions, count only successful tools for behavior patterns, and use the direct candidate source once for editor resumes and tool patterns. Shown resume suggestions do not become fresh operational opportunities. Historical rows remain available. Proactive speech waits for canonical typed/voice turn ownership and stops waiting at its deadline without interrupting the user.

Coverage includes actual proactive builders over SQLite, notification speech lifecycle fixtures, existing voice/PH2 contracts and a browser session of 20 mixed turns with cumulative finals, three speech-service aborts and two provider failures. Provider and speech services in those browser scenarios are fixtures; physical microphone and live provider testing remains deployment acceptance.
