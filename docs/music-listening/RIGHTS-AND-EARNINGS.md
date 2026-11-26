# Rights, metadata and earnings specification

**Status:** product/financial specification. The initial metadata and readiness implementation is tracked in [ML1 implementation](ML1-IMPLEMENTATION.md); this document does not imply a deployed integration or active earnings/payment policy.

**Planning date:** November 26, 2026.

**Scope:** VP3 Music Workspace, the main Add Track flow, and their integration with Stoneward.

Read this with [MASTER-SPECIFICATION.md](MASTER-SPECIFICATION.md), [STREAMING-CONTRACT.md](STREAMING-CONTRACT.md), [SOURCE-AUDIT.md](SOURCE-AUDIT.md) and [DELIVERY-PLAN.md](DELIVERY-PLAN.md). ML1 covers metadata and catalog foundations; ML4 introduces earnings only after the relevant commercial decisions; ML5 introduces settlement. Drafting metadata and maintaining already-authorized free playback do not depend on choosing a royalty model.

## 1. Evidence and limits

The source audit inspected VP3 at `2b69d47b04bb2bcdd74402189b820eef22ed1235` and Stoneward at `253d1267c95ffefbcf3a79173429abbbba0c33fc`. These are audit baselines, distinct from the eventual implementation base.

- VP3's [playback endpoint](https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/api/playback.php) records listening and activity. No music rate, accrual ledger or artist payout implementation was found. Its [track credits schema](https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/upgrade-stonefellow-v105.sql) records contributions, not payment shares.
- Stoneward already has [rights parties, works, splits and licenses](https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/rights-core.php). These are editable current records with one rights work per catalog track. They are not immutable financial agreements or a royalty engine.
- Stoneward has [verified payment processing](https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/store-payments.php) and [paid Library grants](https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/account-data.php). A purchase or content grant does not prove a royalty accrual or bank payout.
- Historical notifications identify `stonefellow74-debug/vp3-working` and `claude/home-stereo-phase-9c-royalty-backoff`. Git authentication was unavailable and the connected repository lookup returned 404. Historical rates and behavior remain unverified; the repository has not been established to be deleted.

Recovering that historical implementation is a comparison task. Do not invent its rate, silently transplant unverified rules, or describe the current plan as restoration of an already-proven financial engine.

## 2. One shared song metadata form

The main **Add Track** entry and Music Workspace must use one reusable form, validation contract and authorized write path. Both entry points select an accessible workspace, create or update the same catalog identity, and preserve existing track, playlist and playback references. Reopening a draft must not create another track.

Use progressive sections with a small initial upload step. Users can save incomplete drafts; missing information is shown as unknown or unconfirmed. Publication, earnings activation and payout each evaluate only their relevant requirements.

| Form section | Fields and behavior |
|---|---|
| Recording basics | Title, display artist, featured artists, version/mix label, album/release association, description, genre, language, explicit-content flag, release date and artwork. Distinguish measured audio properties from user-entered values. |
| Writers and composition | Repeatable writers/composers/lyricists, contribution roles, work title, known ISWC and society work references. Link writer aliases to parties; do not require a VP3 login. |
| Publishers and affiliations | Repeatable publishers/administrators, writer-to-publisher relationships, PRO/CMO affiliation and relevant IPI/CAE identifiers. Keep label, publisher and distributor roles separate. |
| Recording and label | Existing ISRC, recording version, recording date where known, label and master-owner assertions, producer/performer credits, source recording relationships and copyright notices. |
| Shares and agreements | Explicit share basis, parties, percentages or other agreed allocation, effective period, relevant territory/use and supporting reference. Incomplete or conflicting allocations remain visible. |
| Availability and permissions | Intended audience, allowed uses, streaming/download/preview distinctions, territory/date restrictions, supporting authorization status and exceptions requiring review. |
| Files and provenance | Original upload/source reference, replacement/rendition relationship, checksum where available, technical measurements, provenance and confirmation status. Keep production stems distinct from released listening audio. |

Public output uses a field-level allowlist: approved credits, display metadata and intentionally published identifiers may appear. Private notes, agreements, legal/contact details and payment-destination information remain restricted. An IPI may be public elsewhere; that does not authorize publishing every private party field stored beside it. A public projection must not expose protected media paths or permission documents.

The Agent may extract file metadata, suggest names or identify likely duplicates, then show proposed changes with their source. It must not fabricate identifiers, infer a publisher from a label name, mark an unknown affiliation as none, or turn a plausible match into confirmed ownership.

## 3. Identity model and identifiers

Keep four linked entities even if the initial UI presents one song form:

1. **Composition/work:** the underlying musical work and its creator relationships.
2. **Recording:** a particular performance, mix or recording version, linked to its work or works.
3. **Release:** a published package containing recordings, with its own ordering, artwork and commercial presentation.
4. **File/rendition:** a delivered encoding, preview, replacement file or production asset linked to the relevant recording.

A repaired MP3 or different encoding normally changes a file reference, not the recording identity. A materially different recording requires explicit version review. IFPI guidance says an unchanged recording retains its ISRC across formats/releases, while materially changed recordings need their own identification. Do not issue an ISRC for every file or automatically reuse one for every remaster. See [IFPI: when not to assign](https://isrc.ifpi.org/why-use-isrc/when-not-to-assign) and [when to assign](https://isrc.ifpi.org/why-use-isrc/when-to-assign).

ISWC identifies a musical work, not a recording, and does not encode ownership shares or establish authorization. See [CISAC/ISWC explanation](https://www.iswc.org/iswc). The application stores existing identifiers and their provenance; producing syntactically valid text is not registration or external verification. Creation/registration with an external authority is a separate, explicitly designed integration.

Represent a party independently of login: one person or organization may have multiple roles, names, identifiers and relationships. A party may later be linked to an authorized account without rewriting history. Preserve the name/identifier association rather than treating an IPI as an interchangeable account number; [BMI's IPI guidance](https://www.bmi.com/creators/faqs?tag=my-bmi-account) distinguishes name/AKA identifiers from BMI account numbers.

Cross-site identity includes origin installation, local catalog ID and canonical recording reference. Titles or matching filenames are insufficient to merge records. Duplicate suggestions require review before changing identity or financial references.

## 4. Credits, authority and share basis

The following facts must remain separate:

- Account/workspace authority to manage a record.
- Descriptive credit for a contribution.
- Documented permission to offer a particular use.
- An agreement defining economic entitlement.
- The beneficiary/payee and its payment destination.

Uploader, artist, producer, workspace owner and payee may be different parties. Removing a teammate's access must not erase historical credits or agreed financial entitlements. Workspace ownership transfer must not silently transfer previous obligations or replace payment destinations.

Every share set needs a declared basis and scope. Composition ownership, master ownership, writer performance shares, publisher performance shares and VP3 revenue participation are not one interchangeable percentage column. A complete allocation may need to total its declared denominator, but no universal combined 100% rule applies to all fields. For example, [BMI's registration terms](https://www.bmi.com/legal/work-registration-terms-and-conditions) describe different writer/publisher representations on 100% and 200% bases. This example informs the data model; it is not a universal rule for every society or agreement.

Store basis, denominator, source convention, party, role, effective dates, scope and agreement version. Show the basis beside every percentage. Never normalize a partial allocation into 100% without an explicit decision. Incomplete shares can remain a draft while blocking only the financial action that requires a complete allocation.

Provenance distinguishes imported, owner-entered, AI-suggested, owner-confirmed and externally verified values. Verification names its target: format checked, catalog matched, identifier registry matched, or agreement reviewed. None is a general declaration of legal clearance. Preserve conflicts rather than overwriting one source with another.

## 5. VP3 and Stoneward authority

VP3 owns canonical metadata and rights records for the VP3 catalog. Approved publication to a paired Stoneward site carries a versioned projection and an explicit field mapping. Stoneward's existing local catalog fields, native purchases, Library grants and print permissions remain meaningful records with their own origin. Importing them does not automatically grant VP3 authority to rewrite them.

For linked records, define which fields VP3 publishes, which remain local and how conflicts return for review. A reverse import must not silently replace a confirmed VP3 agreement. Preserve local IDs and source references throughout migration.

Stoneward's current readiness function checks composition/master totals and selected license conditions. It does not comprehensively evaluate stored start dates, territories or explicit `expired` status. Its one-work-per-track layout also cannot simply become the general work/recording model. Reuse its data and useful UI concepts through an adapter; reassess assumptions before extending it to financial decisions.

## 6. Four independent readiness states

ML1 implements separate catalog/playback readiness and explicit `not_configured` earnings/payout states. The funded-earnings, agreement and settlement conditions below remain requirements for later phases. See [ML1 implementation](ML1-IMPLEMENTATION.md) for the current checks and limits.

| State | Question answered | Examples of unresolved information |
|---|---|---|
| Catalog | Can this recording be reliably identified and maintained? | Missing title/version relationship; duplicate identity conflict. |
| Playback | May this audience obtain this media for the requested use, and does it work? | Missing file, expired grant, unavailable rendition. |
| Earnings | Is an eligible, funded calculation defined for this usage and payee arrangement? | No approved policy, incomplete allocation, disputed scope. |
| Payout | Can the approved payable amount be settled to the intended recipient? | Unverified destination, unsettled funding, applicable hold. |

Show reasons and the next useful action. A catalog-ready recording can be nonmonetized; a payable amount can have a pending payout. Preserve explicitly authorized free playback while money policy is unresolved. Never display an absent policy as a zero rate or missing financial data as a proven zero balance.

## 7. Commercial boundary and eligibility

Track three independent flows: hosting/service charges, fan purchases, and musician earnings. VP3 subscription revenue or Stoneward hosting fees do not automatically become a royalty pool. A paid download/unlock grants its defined entitlement; it does not necessarily fund a second payment for every subsequent listen. Hosting offsets against earnings require an explicit policy and visible entries.

Reuse VP3's [Commerce receipts and refund records](https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/agent-commerce-v800-part1.php) where applicable. Its generic platform fee is not a music rate. Preserve Stoneward's payment authority for its native orders. For financial reporting, use the distinctions in [verified conversion reporting](https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/fan-conversion-verified.php), which separate live evidence from tests/simulations; its older engagement paid-order classifier includes test states.

Before ML4 accruals, decide funding, unit of calculation, payer, beneficiaries, eligible uses and exclusions. A start, engagement-qualified play, completed listen, billable event and payable event are distinct facts. Define treatment of previews, own-track testing, production review, repeats, venue sessions, offline listening and external-site reports. No numerical rate or universal qualification threshold is selected here.

## 8. Calculation, statements and settlement

Calculate from accepted source evidence and an effective policy version. Correlate origin, play/session and event identities so native events and synchronized copies cannot accrue twice. Replayed processing must return the prior result. Browser observations are evidence with a stated trust level, not proof of human attention or automatic financial eligibility.

An immutable accrual records source references, recording/workspace/payee snapshots, policy and agreement versions, quantity, exact amount, currency and eligibility reason. Corrections use linked adjustment/reversal entries. Track deletion, metadata edits, account removal and future ownership changes must not erase the evidence supporting historical statements.

Choose precision and rounding before implementation: sub-cent accruals need exact decimal or integer scaled units, never floating-point balances. Define split allocation, remainder carry, provider-unit conversion and currency boundaries. Do not sum currencies or assume every settlement currency has two decimal places. Every allocation must conserve its input amount under the approved rounding policy.

Retain occurrence time and receipt time. Define reporting timezone, period cutoff, offline evidence acceptance and late-event treatment. A finalized statement stays reproducible; later corrections appear in a traceable adjustment period or an explicitly versioned replacement. Recalculation previews must not mutate finalized balances or resend payments.

Separate estimates, approved accruals, payable amounts, payout reservations, provider-confirmed settlement and reversals. A queued request, timeout or successful API submission is not a settled payout. Persist idempotency and provider correlation, reconcile ambiguous attempts before retrying, and release reservations only on a supported outcome.

Holds specify recording, share, payee, period or amount, with reason and review owner. A rights dispute need not freeze unrelated earnings. Playback restriction, accrual hold and payout hold are separate controls. Reconcile customer refunds to the original funding policy; do not invent clawback rules after payment.

## 9. Open financial decision register

All entries below are open unless superseded by a dated decision. David/product chooses commercial policy; implementation documents technical proposals and verifies them. This register does not add an approval barrier to authorized planning, metadata drafts or free playback.

| ID | Decision still required | Owner | Consequence / phase |
|---|---|---|---|
| F-01 | Historical royalty source recovery and which proven behavior to preserve | David/product + implementation | Record provenance; ML1 proceeds, no inferred legacy rate. |
| F-02 | Funding source and relationship between hosting, purchases and earnings | David/product | ML4 accrual waits for a funded model. |
| F-03 | Calculation unit, numerical rate/allocation method and effective dates | David/product | ML4 has no production default or zero placeholder. |
| F-04 | Eligible usage, exclusions, repeat/offline/venue rules and evidence strength | David/product with implementation proposal | ML4 eligibility must be reproducible. |
| F-05 | Beneficiary/share basis and treatment of incomplete or disputed allocations | David/product | ML4 allocation cannot assume uploader ownership. |
| F-06 | Currency set, precision, rounding and remainder policy | David/product with implementation proposal | Required before ML4 monetary storage/calculation. |
| F-07 | Statement timezone, cutoff, late events and finality/correction policy | David/product | Required before ML4 finalized statements. |
| F-08 | Refunds, reversals, negative balances and any hosting offset | David/product | ML4 adjustment behavior; no silent deduction. |
| F-09 | Payee identity, destination authority and financial role capabilities | David/product + implementation | ML4 private reporting; ML5 destination controls. |
| F-10 | Settlement provider, cadence, threshold, fees and evidence of completion | David/product | ML5 payment execution waits for a defined settlement model. |
| F-11 | Hold scope, release criteria and dispute-resolution responsibility | David/product | ML4/ML5 preserve unaffected amounts. |
| F-12 | Source correlation, replay, retention and recovery design | Implementation | ML4 correctness gate; preserve financial evidence across restores. |

Each resolved entry records the choice, effective version, rationale and impacted acceptance cases. Do not treat an empty register field as consent to a default.

## 10. Delivery and acceptance boundaries

**ML1:** deliver shared progressive metadata drafts, identities, provenance, scoped editing and compatible imports. Unknown identifiers and unresolved financial policy remain explicit. Existing authorized playback continues according to its actual grants.

**ML4:** introduce financial data only after the necessary register decisions. Compare a deterministic calculation preview against representative source evidence before activating real accruals. Label simulated calculations and keep them out of balances and statements.

**ML5:** settle only after provider behavior, destinations, reconciliation and statement handling are defined and verified. The Agent can explain and prepare work through existing authorized tools; a model-generated amount or narrative is never financial evidence.

Acceptance must cover duplicate and concurrent events, cross-site copies, file replacement, changed agreements, partial shares, multiple payees/workspaces, rounding conservation, separate currencies, delayed/offline events, finalized statements, refunds, provider timeouts and targeted holds. Verify that ordinary collaborators cannot change payment destinations and that a former collaborator's historical records survive membership removal.

Recovery must coordinate database and non-database records: Stoneward orders live in `storage/orders/`, while its upgrade backup is database-focused. Replaying a restored worker must reconcile existing provider attempts instead of sending again. Suspension stops new financial activity while retaining obligations, receipts and audit history. Passing these gates establishes implemented behavior; production totals and settlement remain unverified until exercised against the actual deployment.
