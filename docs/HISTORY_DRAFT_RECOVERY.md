# History-load draft ownership — 18 October 2026

Confirmed browser regression: the composer remained editable during Check history, but its input handler discarded those edits while a conversation load was pending. A delayed successful response restored the older saved draft over the newly edited message.

Cloud now persists edits under the conversation currently displayed until an authorized history response commits a new selection. The existing restoration/send guards continue to block dispatch while loading. This preserves edits during same-conversation recovery and saves navigation-time edits under the source conversation, without copying them to the destination. The existing bounded, expiring tab-session draft store is reused. The hosted Chat asset version is updated so installations load the fix.

The real browser fixture first failed with the old provider-failure draft replacing the new text. It now covers delayed Check history completion, Send blocked during that check, persistence through reload, edits made during navigation, source/destination isolation, and no automatic replay. Existing provider, Stop, voice, Knowledge, session and late-response acceptance remain part of the same suite. HomeServer's existing input handler already saves edits during history restoration; no native or extension source changes are required for this Cloud-specific gap.
