# Campaigns & Rewards V1.26 — Adaptive Campaign Optimization & Lifecycle Intelligence

V1.26 turns V1.25 decision evidence into a governed learning loop without creating a second Campaign, CRM, Reward, conversion, or scheduler authority.

## Durable outcome attribution

The new \`campaign_decision_outcomes\` table is an append-only evidence ledger. It records verified downstream observations that can be traced to a V1.25 decision: message views, Campaign-attributed conversions, Reward claims, and Journey completion. Evidence is read from canonical \`campaign_deliveries\`, \`campaign_journey_instances\`, and \`reward_issuances\` records. Existing Decision rows are never rewritten.

Attribution is deliberately conservative. Direct delivery and Journey Instance links are preferred. An admitted entry Decision can be associated to the nearest matching Journey Instance created after that Decision. Every row records its source and attribution method.

## Immutable optimization snapshots

The new \`campaign_optimization_snapshots\` table stores append-only observation windows. A snapshot contains:

- entry, holdout, conflict and dynamic-offer counts
- verified outcome counts and observed Campaign-attributed response rates
- Reward value and cost evidence when the canonical issuance contains it
- contact fatigue signals from actual recent sends/views/conversions
- decision-time lifecycle performance by CRM lifecycle stage
- offer-selection performance
- deterministic recommendations and the evidence hash used to produce them

If the exact evidence hash already has a recent snapshot, V1.26 reuses it instead of creating noise.

## Holdouts and measurement language

V1.25 holdouts remain deterministic. V1.26 reports treatment and holdout observations, but Campaign-attributed delivery/claim data alone is not represented as causal lift for holdouts that did not receive Campaign execution. The report explicitly marks this measurement scope. A true causal comparison can be added later when both groups share an external business outcome authority such as a canonical purchase ledger.

## Lifecycle intelligence

Lifecycle analysis uses the context saved at Decision time. It does not rewrite the contact's CRM lifecycle stage or create a second profile. Performance can therefore be reviewed by stage exactly as it was known when the Decision happened.

## Fatigue and diminishing response

Fatigue is diagnostic only. V1.26 measures recent Campaign sends per contact and flags contacts with repeated sends and no observed view/conversion signal. It can recommend reviewing cadence, but it cannot pause a Campaign, alter frequency controls, or mutate a Journey.

## Offer efficiency

Dynamic Reward decisions are grouped by the exact selected Reward evidence. V1.26 reports selections, no-eligible-offer decisions, attributed responses, and verified claimed value when available. Reward issuance, inventory, liability, budgets and claims remain owned by the existing Reward authorities.

## Agent governance

V1.26 writes proposed optimization recommendations into the existing \`campaign_agent_recommendations\` table. Recommendations include evidence references and always set \`requires_human_decision=true\` and \`auto_apply=false\`.

The Agent cannot:

- change a live Journey rule or Decision condition
- change holdout percentages or conflict groups
- change Campaign frequency controls
- attach/detach or issue a Reward outside canonical issuance
- pause, resume, cancel or publish a Campaign as part of optimization
- modify CRM lifecycle state

## Runtime and deployment

Run \`upgrade.php\` after deploying V1.26. Then move the Campaign cron to \`cron/campaigns-rewards-v126.php\`.

The V1.26 cron keeps all V1.25/V1.24 work and then performs outcome collection, immutable snapshot creation, and human-review optimization recommendation generation.
