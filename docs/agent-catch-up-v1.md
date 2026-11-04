# Agent Chat Catch me up

In owner Chat, select **Catch me up** in Agent Brief, or type or say “Catch me up,” “Catch me up on work,” or “What needs attention in my store?” Each request uses the same read-only projection, with source permissions and named Agent scope checked on each read.

You can also ask “What did I miss?”, “What did I miss while I was away?”, “Has anything changed since this morning?”, or “What needs my attention before my next meeting?” Chat verifies the records as they stand and labels the limits of its evidence for changes and meeting cutoffs. Requests about a specific record or action continue through their owning Chat tools.

The brief surfaces up to three actionable records with a reason and a link to the owning app. Each source reports ready, limited, unavailable, or forbidden. The brief qualifies its answer when coverage is incomplete. It runs without model inference and performs no domain writes or notification acknowledgement.

Calendar coverage uses Cloud records. Approved meeting follow-ups appear through due tasks. Commerce coverage includes owned Profile Commerce refund and payment review, production campaign journeys needing review, and eligible expiring campaign rewards held in the user’s Inbox. Private Commerce reads use the system Agent in this release.

| Domain | Existing tools or surfaces | Brief scope |
| --- | --- | --- |
| Office & Team | Reminders, Calendar, tasks, lists, scheduling, and meetings | Current office priorities where the source can be checked |
| Commerce & Growth | Products, orders, refund requests, Campaigns, Rewards, and vouchers | Current supported commerce exceptions within the signed-in scope |
| Transcripts & Knowledge | Transcript reads, analysis, reviewed highlights, and Knowledge search | Existing tools |
| Music, Media & Entertainment | Media search, library reads, playback, and image generation | Existing tools |
| Social & Communications | Feeds, Profile activity, phone and messaging | Existing tools |
| Profile Agent | Owner inspection and Profile-scoped handoffs | Existing tools |
| Agent Eyes | Explicit screen sharing, watch zones, and saved setups | Existing tools |
| Hosting & Subdomains | Site status, DNS, and deployment actions | Existing Hosting routes |
| Apps & HomeServer | App discovery, status, and authorized operations | Existing tools |

The capability catalog describes the read. It grants no domain access. Source availability is checked on request.

## Everyday Working Context

Calendar, due reminders, open tasks, actionable Cloud Missions, and Profile call follow-ups share one ranking. A meeting or conflict close to its start rises above a non-urgent item. A Mission waiting for assignment approval or an unfinished prerequisite can lead when Calendar is quiet. Recently failed Missions contribute a review item. Routine running jobs stay in Agent Teams.

Profile call coverage includes recent unanswered incoming offers and open tasks already saved from reviewed findings with an exact authorized call transcript. A saved task that also appears in the ordinary task source occupies one slot. Call history, transcript content, and task creation continue through their owning features. The Mission link opens its exact Agent Teams record for review.

The existing Agent Brief shows one leading work item while it is open. **Catch me up** can expand the view to three items in a regular Chat reply. The Brief uses the existing presentation firewall and page session binding. It does not create a notification, claim a reminder, change call state, or start work. This section leaves proactive speech to the existing attention policy. The browser fixtures exercise the displayed ranking, stale responses and account changes, while source tests verify the canonical data and permissions.

## Greeting preview

On a build containing the experiment, add `greeting_preview=1` to the owner Chat URL and open Agent Brief. The preview shows whether the Agent would mention one linked follow-up before its upcoming Cloud meeting or stay quiet. An eligible follow-up is still open, carried forward through the existing meeting continuity workflow, and linked to the exact upcoming calendar event. The meeting must start in the next 30 minutes. Recent Chat interaction, active Agent work, and unavailable evidence keep the preview quiet.

The decision uses the same current priority as Agent Brief. Close the follow-up and refresh the Brief to see the decision change. The preview runs on request and only displays inside Agent Brief. It creates no Chat turn, audio, listening window, or delivery receipt.
