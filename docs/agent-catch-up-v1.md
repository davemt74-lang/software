# Agent Chat Catch me up

The read-only **Catch me up** card and Chat skill use the same current source projection. The card reads on demand. In owner Chat, type or say “Catch me up,” “Catch me up on work,” or “What needs attention in my store?” Source permissions and named Agent scope apply on each read.

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
