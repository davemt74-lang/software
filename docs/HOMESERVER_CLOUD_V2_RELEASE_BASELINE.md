# VP3 Cloud / HomeServer v2.0 release baseline

## Shared product version

Starting with this release, the VP3 Cloud HomeServer integration and the Windows HomeServer application use one shared product release number:

**2.0**

The Windows HomeServer application reports 2.0.
The VP3 Cloud HomeServer package reports 2.0.
Pairing, poll, and canonical status responses expose the shared Cloud release version.

Internal compatibility identifiers remain independent. Existing names such as `homeserver-https-pair-v1300.php`, `homeserver-https-poll-v1300.php`, `homeserver-cloud-pairing-v1200.php`, schema migration numbers, and protocol identifiers are not product version numbers and are intentionally preserved.

## Verified baseline carried into v2.0

The v2.0 line carries forward the working combination:

- HomeServer v19.5 Windows package
- VP3 Cloud v13.06 connection core
- one-time pairing key flow
- Windows DPAPI protection for the saved HomeServer HTTPS session
- outbound-only HTTPS relay from HomeServer to VP3 Cloud
- Cloud-to-HomeServer queued requests over that outbound session
- HomeServer result return over the same session
- automatic reconnect after restart or temporary network interruption
- canonical connection state shared by the Cloud settings page, status modal, and local HomeServer connection page

The v13.06 first-poll fix is part of the v2.0 baseline: MySQL schema/DDL work remains outside the active polling transaction, and internal relay-processing failures must not be misreported as authentication failures.

## Packaging contract

The matching artifacts are:

- `homeserver-v2.0.zip`
- `vp3-cloud-v2.0.zip`

The VP3 Cloud ZIP is cumulative for the HomeServer integration and is deployed by preserving paths, replacing the existing files, and running `upgrade.php` once.

The Windows HomeServer ZIP remains produced by the established PyInstaller + Inno Setup build. The executable and installer packaging architecture must remain unchanged unless a concrete defect requires a change.
