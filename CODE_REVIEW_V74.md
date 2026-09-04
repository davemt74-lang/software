# Stonefellow v74 Code Review

## Requested change

Remove the background behind the Agent Chat footer and allow the canvas content
to scroll behind the footer.

## Implementation

The footer wrapper is now a transparent absolute overlay at the bottom of the
authenticated shell.

Removed from the footer wrapper:

- gradient background
- opaque footer band
- wrapper shadow
- wrapper backdrop blur

Preserved:

- visible Chat composer
- persistent Now Playing card
- sticky bottom placement
- interactive controls
- sufficient scroll padding so the final content remains reachable

The scrolling Chat/Player/Shows/Photos/Merch/About content now continues visually
behind the footer rather than stopping above a dedicated footer row.

## Database

No SQL changes are required for v74.

## Validation

- all packaged PHP files pass `php -l`
- all packaged JavaScript files pass `node --check`
- targeted v74 footer/player/regression checks pass
- requested-scope score: **10/10**
