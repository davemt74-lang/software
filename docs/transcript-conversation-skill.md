# Transcript Conversation skill

The Agent can discover authorized recordings by title/date, resolve a unique result, summarize saved source text, answer content questions, and keep a conversation-level source attachment. It reuses the existing transcript read, search, intelligence, review and Brain-highlight services.

- `summarize`: resolve `session_id`, `title` or `date`, retrieve source evidence, generate a concise answer with validated segment citations, and attach the source to this conversation.
- `discuss`: answer a follow-up from current source evidence. For large sources, retrieve relevant passages and disclose partial coverage.
- `attach`: use a recording as the default source for transcript questions in this conversation.
- `continue`: summarize the next uncovered character range. Intervening questions preserve summary progress.
- `detach`: stop using the attached source. Old UI selection does not silently restore it.
- Existing `list`, `read`, `search`, saved analysis/research reads and explicit analysis/highlight/item actions remain available.

The capability interpreter receives the active source reference and can delegate content follow-ups to transcript reads without requiring transcript keywords. This delegation is read-only. A unique catalog result also establishes a source reference so “tell me about this transcription” can work after discovery. Explicit selected/highlighted requests address the UI selection; ordinary follow-ups use the conversation source. Ambiguous title/date results require selection. A content request mistakenly classified as transcript capability inspection is passed to the transcript interpreter in read-only mode; that arbitration cannot execute analysis or a write. Genuine access questions remain capability inspection.

The attachment is server-written metadata in existing Chat message context, scoped by authenticated owner, Agent and conversation. It stores an ID, source fingerprint and summary cursor, not the whole transcript or new permanent Brain facts. Subsequent use rereads authorized current evidence. A source edit invalidates stale inference/cursors, and access is rechecked after generation. Transcript responses carrying an attachment are saved in Chat but are excluded from automatic assistant-to-Brain parsing. Existing explicit Brain-highlight actions retain their authorization and behavior.

Source evidence is bounded to 48,000 characters per answer. Small recordings can be summarized in full; large recordings disclose the actual covered range and support continuation. Follow-up retrieval searches current segments and supplies page, speaker and timestamp locators. The generated answer must cite only supplied segment IDs. Source text, titles and annotations are untrusted data; the answer generator has no execution tools. Existing Cloud/meeting processing policy applies. This is grounded conversation, not a claim that every large recording is held in a model context at once. No database migration or HomeServer publication is required.

Acceptance covers the reported two-turn `jcal` example, a unique catalog result followed by a content question, unrelated reminder turns, genuine capability questions, detach, ambiguous titles, complete and partial source coverage, continuation, foreign citations, edits/revocation during inference and cross-Agent isolation. Models in automated tests are controlled; deployed-provider language quality remains a user acceptance check.
