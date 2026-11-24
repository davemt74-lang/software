# Phone contacts and call reminders

The Phone drawer's **Contacts** tab can add and edit an owner CRM contact without a previous call. The editor saves name, international phone number, personal relationship and private notes. A personal relationship such as friend, family or colleague is stored separately from the existing sales lifecycle. Contact notes are returned only by the private contact-detail action; they are not added to Phone lists, caller cards or Chat messages.

## Scheduled calls

A saved contact can have one-time, daily or weekly call reminders at a chosen local time and IANA time zone. Each schedule keeps its call notes/objectives and links to its canonical Calendar event. The editor supports 1–60 minute reminders and up to 20 active schedules per contact. Recent matching call receipts remain visible in the contact detail; history is not copied into a separate store.

Saving a schedule creates an ordinary owner Calendar event using the existing Operator contact/Agent source reference. Daily and weekly occurrences are projections of that event. Editing preserves the series identity, and canceling retains its historical row while removing future occurrences. Existing Calendar events and native bookings are checked for conflicts across the next 90 days of a recurring schedule. HomeServer calendar feeds are not included in that conflict check.

These are reminders for manually placed calls. The response and UI explicitly report that automatic dialing is not enabled. A reminder creates no carrier intent and grants no Auto Call permission. Any future executor still requires the separately saved owner/contact permissions and current destination binding.

Repeats follow the selected local wall time. Canonical recurrence skips occurrences whose start or end falls in a nonexistent spring-transition time. A compact Phone schedule whose elapsed interval cannot round-trip through the Calendar editor during an autumn clock change is rejected with a clear validation message. Events expanded in Calendar to all-day, longer-duration or longer-note forms remain visible and cancellable from Contacts, with editing directed to Calendar.

## Upgrade and access

Run the normal Cloud `upgrade.php` flow. The existing contact migration adds `crm_contacts.relationship` and `crm_contacts.notes`, preserving email identities, primary/alternate phone channels, sales lifecycle and contact IDs. No separate address book, schedule table or dispatch worker is installed. Before the optional migration, contact reading and existing calling remain available; the new editor reports the required upgrade. Incomplete optional Calendar storage disables scheduling while leaving contact details usable.

Writes require the current owner account, an assigned phone scope, a private context and CSRF. Contact and Calendar revisions prevent stale edits. A schedule save also checks the reviewed contact revision and current assigned line, so a changed destination cannot inherit a stale schedule request. Create retries use an owner/contact/Agent-bound source reference; the same request cannot silently create a second schedule or change its original details.

## Verification

`tests/phone-contact-management-v1.php` runs the same production helpers against SQLite or a disposable MySQL database. It covers ownership, private participant boundaries, field validation, primary/alternate phone preservation, stale revisions, duplicate submits, canonical recurrence, conflict detection, source binding and series cancellation. The existing Phone DOM suite covers explicit saves, failed-save retry identity, draft/focus preservation and late-response teardown. The browser release gate exercises the contact and schedule forms at desktop, 390px and 320px widths and retains screenshots for review. Daily Calendar service/editor tests run in the Phone PHP 8.1/8.3 gate.
