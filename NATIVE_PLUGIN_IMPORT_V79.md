# Stonefellow v79 Native Plugin Import

Stonefellow v79 maps the small REAPER mixing plugin set used by Stonefellow sessions into native Web Audio processors in Stem Studio.

## Native mappings

- ReaComp / compressor -> Stonefellow Compressor
- ReaEQ / EQ / five-band EQ -> Stonefellow 5-Band EQ
- ReaDelay / delay / echo -> Stonefellow Delay
- ReaVerb / ReaVerbate / reverb -> Stonefellow Reverb
- ReaLimit / limiter / maximizer / brickwall -> Stonefellow Master Limiter

## Import behavior

The RPP importer preserves plugin order, bypass state, source plugin name, plugin format, preset name, FXID, mapping quality and confidence. Native chains are stored on each stem and on the project master bus, then seeded automatically when Stem Studio opens.

REAPER plugin binary state is not executed by Stonefellow. Where an opaque proprietary/serialized setting cannot be decoded reliably, Stonefellow uses a role-aware native starting value and labels the mapping as estimated rather than exact.

An imported master chain replaces the old default master EQ/compressor so the project is not double-processed. Local Studio state is versioned by the import signature so an older browser autosave cannot erase a newly imported plugin chain.

## Database upgrade

v79 adds:

- `track_stems.plugin_chain_json`
- `track_projects.master_plugin_chain_json`

Run `/upgrade.php` or import `upgrade-stonefellow-v79.sql` before using v79 production-file imports on an existing installation.
