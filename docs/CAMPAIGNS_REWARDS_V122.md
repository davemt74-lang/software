# Campaigns & Rewards V1.22 — Journey Intelligence & Optimization

V1.22 adds optimization to the V1.21 journey graph without creating a second analytics database, scheduler, or autonomous learning authority.

## No migration

V1.22 adds **no database tables or columns**. Journey settings remain versioned in `campaign_messages.template_json`. Outcomes remain canonical `campaign_deliveries` plus Reward Claim attribution. Agent recommendations reuse `campaign_agent_recommendations`.

## Journey templates

Built-in templates provide draft starting points for signup welcome, post-purchase, win-back, Reward expiration, and referral follow-up journeys. Applying a template creates **draft nodes only**. A human must review and activate the nodes through existing Campaign publish authority.

Template import chooses a unique journey key so it cannot silently shadow an already-active graph.

## Path and drop-off analytics

V1.22 derives journey entry, delivery, view, conversion, continuation, suppression, retry, dead-letter, and drop-off metrics from canonical delivery rows and journey-instance metadata. No analytics event is copied into a parallel store.

Drop-off is measured by journey instance: when a node has a configured next step, V1.22 compares the instances that entered the source step with the instances that later entered the expected next step.

## A/B outcome comparison

Weighted variants continue to use V1.21 deterministic assignment. V1.22 reports sent, delivered, viewed, claim-attributed conversion, view rate, and conversion rate by journey / logical step / variant. Recommendations require a meaningful observed sample before proposing a review.

V1.22 never automatically rewrites variant weights.

## Send-time optimization

Send-time signals use only historical messages with a recorded `sent_at` and sent/delivered/viewed state. Views and claim-attributed conversions contribute to the observed hour score. Optimization is **opt-in per message node** and requires a configurable minimum sample size.

If enabled and enough evidence exists, a due message can be deferred to the strongest observed hour. The contact timezone is used for the target clock time, with Merchant timezone as the fallback.

## Frequency and fatigue controls

Message nodes can set:

- maximum messages per 24 hours
- maximum messages per 7 days
- a fatigue window in days
- maximum messages in that fatigue window

The gate considers successful sent/delivered/viewed messages across the Merchant for the same contact and channel. When a cap is reached, delivery is deferred until the earliest qualifying message ages out of the window. Consent and V1.21 quiet-hours checks still run independently.

## Simulation

Campaign owners can dry-run a journey against a real Merchant CRM contact and trigger. Simulation resolves deterministic variants, branch conditions, consent state, frequency gates, and scheduled times without inserting deliveries, sending provider messages, issuing Rewards, or changing Campaign state. Loop detection and a 50-node traversal limit prevent runaway simulation.

## Agent recommendations

V1.22 can propose human-review recommendations when verified outcomes show:

- material journey drop-off
- meaningful observed A/B conversion-rate spread
- sufficient evidence for a send-time test

Recommendations reuse `campaign_agent_recommendations`, include evidence/impact previews, and set `auto_apply=false`. Human acceptance records the decision but does **not** mutate journey configuration. Editing and activation remain separate explicit human actions.

## Scheduler

Replace the V1.21 Campaign cron entry with:

`cron/campaigns-rewards-v122.php`

It runs V1.19 Campaign automation, V1.21 Reward-expiration enqueue, V1.22 optimized delivery dispatch, and V1.22 recommendation refresh. It remains CLI-only and does not add a daemon or second scheduler.
