# HomeServer native workspace writes

The existing `homeserver-workspace-sync-v1.php` exchanger exports a per-record revision and accepts `edit` and `mutate` for existing owned `crm_contacts`, personal `knowledge_items`, and `user_calendar_events`. Other records retain their exact native Cloud editor links.

A mutation specifies dataset, table-qualified key, mutation ID, expected record revision and an allowlisted map of changed fields. Current paired-session authority, account permission and source ownership are rechecked. Personal knowledge also requires its manage capability. Stale revisions return HTTP 409; unsupported fields or invalid calendar/contact values return 422; permission denial returns 403.

`homeserver_workspace_mutations_v1` is initialized before opening the exchanger transaction. A receipt and source update commit atomically. An exact retry returns the stored acknowledgement after current ownership/permission checks and does not rewrite a newer source revision. Reusing an ID with different arguments fails. Native CRM channel normalization, knowledge indexing and calendar validation remain source owned. Knowledge attachments remain intact; linked video meetings require their native lifecycle editor. No schedule or reminder execution records are created by this API.

Deploy Cloud before the matching HomeServer executable. Existing synchronization clients remain compatible with the additional revision property. SQLite and MySQL acceptance lives in `tests/workspace_native_actions.php`; the HomeServer repository also runs its production Python worker against the PHP HTTP fixture on Cloud main.
