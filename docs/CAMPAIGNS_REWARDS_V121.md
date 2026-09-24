# Campaigns & Rewards V1.21 — Journey Orchestration & Delivery Providers

V1.21 extends V1.20 from delayed message steps into a durable journey graph while preserving the V1 database and authority model.

## No migration

V1.21 adds **no database tables or columns**. Journey node definitions remain versioned rows in `campaign_messages`, with orchestration settings in `template_json`. Runtime node instances remain rows in `campaign_deliveries`, with journey-instance, branch, retry, scheduling and provider state in `metadata_json`.

## Journey nodes

V1.21 supports four node types:

- **Message** — Email, SMS or VP3/Agent notification.
- **Decision** — evaluates an allowlisted CRM/trigger/Reward condition and chooses a true/false next step.
- **Wait until** — durable relative, fixed UTC, or local-time pause.
- **Exit** — explicitly ends the journey.

Each node has a logical `journey_key` + `step_key`. Multiple message variants may share one logical step by using different `variant_key` values and weights. Selection is deterministic for the Campaign/contact/journey instance, so retries never randomly switch variants.

V1.20 journey definitions continue to run through the V1.20 compatibility path.

## Branching and conversion exits

Decision fields are allowlisted; arbitrary SQL or expressions are never executed. Conditions may inspect canonical Merchant CRM state, trigger values, Reward state, Campaign state, or whether the current journey instance has a claim-attributed conversion.

A node may set `exit_on_conversion`. Before a due node executes, V1.21 checks the same journey instance for canonical Reward Claim attribution and exits without sending more messages when a conversion has already occurred.

## Timezone + quiet hours

V1.21 resolves timezone from the contact's CRM preference metadata when present, otherwise from the Merchant timezone. Message nodes can target a local send time. Wait nodes can target a local clock time.

When `respect_quiet_hours` is enabled, the delivery is re-scheduled to the end of the contact's quiet-hours window rather than being sent during that window. Quiet-hours JSON uses `start`, `end`, optional `timezone`, and optional `enabled`.

## Retry + dead letter

Provider failures use bounded exponential backoff stored on the canonical delivery row. Each node has a maximum attempt count and base backoff. Retryable failures move to `retry_wait`; after the limit they move to `dead_letter`. Non-retryable provider errors may go directly to dead letter.

A human with Campaign publish authority can requeue dead-letter deliveries. Provider webhooks cannot issue Rewards or bypass Campaign authority.

## Delivery providers

### SendGrid email

Set:

- `campaign_email_provider = sendgrid`
- `campaign_sendgrid_api_key`
- `campaign_sendgrid_from_email`
- optional `campaign_sendgrid_from_name`
- optional `campaign_sendgrid_api_base` for EU regional API use

V1.21 sends through SendGrid's v3 Mail Send endpoint and attaches `vp3_delivery_id` as a custom argument for Event Webhook correlation.

### Twilio SMS

Set:

- `campaign_sms_provider = twilio`
- `campaign_twilio_account_sid`
- `campaign_twilio_auth_token`
- either `campaign_twilio_from_number` or `campaign_twilio_messaging_service_sid`

When `site.base_url` is configured, the outbound SMS includes a delivery status callback to the V1.21 provider webhook.

## Provider webhooks

Public endpoint:

`/api/campaign-delivery-webhook-v121.php`

Twilio requests are validated with `X-Twilio-Signature` using the configured Auth Token and exact public callback URL. If a reverse proxy changes the externally visible URL, set `campaign_webhook_base_url`.

SendGrid Event Webhook requests require the signed Event Webhook headers and a PEM-formatted ECDSA public verification key in `campaign_sendgrid_webhook_public_key_pem`. Verification uses the timestamp + raw request bytes before any JSON transformation.

Provider events update only canonical delivery state and emit Campaign cognitive events.

## Scheduler

Replace the V1.20 Campaign cron entry with:

`cron/campaigns-rewards-v121.php`

It runs V1.19 Campaign automation, V1.21 Reward-expiration journey enqueue, and V1.21 delivery/orchestration dispatch. It remains CLI-only; no daemon or second scheduler is introduced.
