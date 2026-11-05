# Live Top of Mind in the floating Agent's Brain

Upgrade baseline: `ffb30844a875bda26f85897e476f38755f5d6edb` (PR #725).

This section adds an independently testable read model to the existing floating Agent and Activity Center Brain view. It does not create another planner or work queue. Conversational follow-through is a separate continuing section.

## Acceptance criteria

1. The floating Agent's Brain control and the existing header open the same Brain view on desktop and mobile. Existing Brain content remains available under its original permissions.
2. Top of Mind reads the exact requested owner's Agent and saved conversation. The core work feed does not require the optional Brain memory interface entitlement and does not activate or unpause work.
3. The feed projects real canonical Initiative assessments, queued work, current execution, interruptions, failures, review waits, prepared results and verified completion. A running lease must be current; a completion must have matching action and receipt evidence.
4. Assessments with more than five situation sources remain visible as generic owned assessment progress without exposing unverified source-derived content.
5. Source, account, Agent, conversation and policy changes remove stale content. Participants and other accounts cannot read private owner work. Result links are tied to actual canonical saved results.
6. Status GETs perform no model calls, queue mutations, permission changes, schema provisioning or worker activation. Source reads are bounded and batched.
7. Live polling runs only while Brain is open and visible, uses active/idle intervals and failure backoff, and stops on close, hiding, navigation or a replaced session. Foreground Chat state is explicitly a local runtime observation rather than an invented background job.
8. A request deadline, aborted-transport fence and snapshot-age indicators prevent a stalled or late response from appearing live. A source outage does not fabricate successful work.
9. Deployed asset URLs change with the source bytes, including the member header, floating Agent adapter and drawer. Browser tests cover desktop/mobile, the actual PHP endpoint, permissions and interrupted transport.
10. All applicable official PR checks pass for the exact reviewed head, main is revalidated before merge, post-merge workflows pass, and the original successful production artifact's ZIP, SHA-256, manifest and packaged source files match the merged tree.

## Scope and evidence limits

The server feed covers canonical Initiative work. The local Chat indicator reports the observed current page's response/speech state. It does not imply that every legacy tool or background subsystem has a new work adapter, that a model is continuously reasoning, or that a hosting scheduler is installed. It displays concise objectives and execution evidence, not hidden reasoning or raw prompts.

Local test results and official GitHub checks are separate evidence. Final certification requires criterion 10; repository and artifact verification do not by themselves prove a particular installed account's provider or closed-browser worker configuration.
