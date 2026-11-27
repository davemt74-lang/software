# ML3 — artist listening dashboard

## Scope and release evidence

This phase adds authorized artist reporting to `/music-workspace.php`, using the existing canonical catalog, native listening evidence and VP3 provider sessions. It also completes the public Stoneward route profile on both sides of the streaming connection. The implementation starts from `software@ea69a419f4878b02170769bc440cb2f26b004799` and `stoneward@d4844b48cb36bcbbbd3f6c6061c80891d3e28930`.

The implementation PRs own the exact candidate commits, test results, merged commits and verified packages. Until those results are recorded, this document is a contract and implementation handoff, not a claim that release checks or live hosting have passed. ML2D's deployed-host and physical-device pilot remains separate.

## Reporting authority

An artist report requires an active, reloaded account and the selected enabled workspace's existing song-management authority: owner, manager or authorized administrator. A global `listening.view` grant is not an artist-report grant. Ordinary production/team access does not silently expose the artist's audience reporting.

The API rechecks current authority, constrains all source queries to the selected workspace and returns aggregate projections only. It does not expose listener account IDs, names, email addresses, listener hashes, session/play IDs, playback keys, media tickets, private metadata, site verification challenges or management credentials. No personal-history permission is created or changed.

The service is read-only. It does not create schema, change song publication, issue provider grants, write listening, import historical events or activate money. Unavailable prerequisites and failed reads must be reported as unavailable, never converted into zero listening.

## Metric and period contract

The report basis is `session_start_cohort_v1`: select sessions whose creation time falls in the chosen local calendar dates and show their accepted cumulative measurements as of the report snapshot. This answers how sessions begun during that period performed. It does not claim that every displayed millisecond occurred within those calendar dates.

This choice is necessary because existing event receipts do not retain an independently queryable event timestamp suitable for allocating historical listening into exact minute/day intervals. No such timeline is fabricated. Daily charts group by session-start date. A session crossing midnight belongs to its start-date cohort. An ongoing session can update that cohort after the initial report; results are explicitly provisional where relevant.

The request includes an IANA timezone. Local inclusive start/end dates become an exclusive upper UTC boundary using timezone-aware calendar arithmetic, including daylight-saving changes. Presets cover 7, 30 and 90 local dates; custom ranges are limited to 366 dates. The selected timezone and snapshot time remain visible. No server-default timezone or guessed fixed offset defines the period silently.

The initial service bounds source cohorts to 25,000 rows. A larger request must narrow its period or filters; it must not return a partial cohort as a complete total. Breakdown pagination never changes the report's total. The read uses a consistent database snapshot and verifies authority again before returning the result.

| Metric | Meaning |
|---|---|
| Playback attempts | Session begins recorded by the applicable source; a begin alone does not prove playback. |
| Playback starts | Versioned native/provider evidence has acknowledged the first playback transition. |
| Qualified plays | At least 10,000 accepted milliseconds under `legacy_10s_80pct_v1`. This is engagement, not financial eligibility. |
| Listening time | Accepted cumulative playing milliseconds for the selected session cohort. |
| Completion | At least 80% of usable server-observed duration in accepted listening. Natural ending and furthest position are separate. |
| Completion rate | Completed measured sessions divided by the stated measured-started sessions with usable duration. Unknown duration stays outside that denominator. |
| Delivery usage | Provider bytes emitted/reserved and request accounting, separately labeled. These are not listened time, unique people or paid plan allowances. |

Provider visitor keys identify a logical playback session, not a durable person across songs/sites. The dashboard does not invent a cross-site unique-listener count. Existing platform-only reports retain their separate authority and historical evidence scope.

## Native, external and legacy evidence

Versioned native rows in `music_listening_sessions_v1` also have compatibility rows in `track_play_sessions`. The artist report counts each native session once, excluding its legacy backing row from the legacy-only projection. Provider sessions are a separate source and must be included explicitly; the old administrator report does not include them.

Legacy-only sessions can be attributed only through an unambiguous authorized catalog/backing-track mapping. Their older timestamps, completion flags and identity quality do not become newly measured evidence. They remain a separately labeled legacy category and are not silently mixed into measured completion or the default audience view.

The default audience view excludes known artist/manager previews and staging tests. Anonymous production playback remains unclassified; the system cannot infer that the person listening is an independent fan. An explicit all-traffic view retains the evidence classes rather than hiding these distinctions. Partial/clipped intervals, muted/loop observations, missing source context, unknown duration and unresolved mappings remain coverage information.

## Workspace interface

The workspace adds Overview, Listening, Catalog and Sites & Streaming reporting while preserving existing entry points for songs/metadata, stems, releases, Player, transcription, team and artwork. Date/source/recording/site filters apply consistently to totals and drill-downs.

The page uses ordinary document scrolling and responsive controls. Loading, empty, unavailable and partial states have distinct copy. Superseded requests cannot replace a newer filter result. All catalog/site labels and URLs are rendered as data, not executable markup. A chart has a textual/table equivalent.

Catalog attention uses existing metadata/playback readiness and links to the authorized editor. Connected-site reporting exposes safe operational status, selected-song count, last observed playback and separately labeled delivery usage. Existing site-management actions continue through their current authorized page.

Earnings and Payments explicitly show **Not configured**. No absent rate is rendered as a zero rate, and no missing payout system is presented as a zero payable balance. ML4 and ML5 still own funded agreements, financial eligibility, precise calculation, statements, payout attempts and reconciliation.

## Public page attribution

The additive route profile admits Stoneward's actual public `/track/{id}`, `/release/{id}` and `/stonefellow-v120.php` routes. Track/release IDs follow the router's bounded public character set. Queries/fragments are removed; foreign origins, credentials, sensitive/unknown paths, encoded separators, traversal, controls and backslashes are rejected.

The registered site remains the verified origin. The initiating page remains client-reported with `page_verified: false`; a recognized URL does not prove attention or independent page verification. The source stays fixed for one playback session.

The wire protocol is unchanged. Older providers accept `source_url` and keep site/recording attribution while omitting newly recognized page forms. Updating a provider preserves an exact pending begin retry whose original receipt used the older restricted-path projection. New begin receipts include the optional `source_page_profile: public_music_pages_v2` marker, and the historical hash fallback is restricted to receipts without that marker. That compatibility path cannot rewrite the old source or allocate another session; a new marked session cannot change from an omitted route to a recognized route while reusing its play identity.

## Acceptance score

The score applies to the implemented ML3 dashboard and additive attribution scope, not the entire music product. Each required row must be supported by executed evidence before marking this scope 10/10.

| ID | Required result |
|---|---|
| M3-01 | Active account/workspace/role authority is enforced on every report; cross-workspace filters and stale membership fail. |
| M3-02 | Native, provider and legacy-only sources reconcile without duplicated native backing rows. |
| M3-03 | Local date boundaries, daylight-saving changes, cohort grouping and current snapshot semantics are correct and visible. |
| M3-04 | Starts, duration/completion denominators, preview/staging classes and partial/legacy coverage retain their real evidence meaning. |
| M3-05 | Public page attribution works through both implementations; unsafe context and conflicting retries stay rejected. |
| M3-06 | Catalog attention and site usage are workspace-scoped, safe, actionable and separate from money/listens. |
| M3-07 | The real workspace flow preserves existing navigation and works at desktop and phone widths without nested scrolling or overflow. |
| M3-08 | API output has no listener/credential leakage; limits, schema faults, stale requests and empty/unavailable states are honest. |
| M3-09 | Service, real HTTP, native MySQL and actual browser acceptance run against the reviewed source; relevant existing music regressions pass. |
| M3-10 | Applicable CI passes, merged source is verified and complete deployment archives match the exact compatible releases. |

Actual-host Apache/CDN/media headers, physical iPhone/Android interruptions, real site proof and production backup recovery remain ML2D evidence. A narrow browser viewport or simulated grant is not a substitute.
