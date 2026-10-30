#!/usr/bin/env python3
"""Audit assessed executor bindings; expose remaining discovery coverage without inventing support."""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = (root / 'includes/agent-capability-awareness-v1.php').read_text()
rows = re.findall(r"vp3_capability_row_v1\('([^']+)','[^']*','(read|write)','([^']+)'", source)
definitions = set()
for path in (root / 'includes').glob('*.php'):
    definitions.update(re.findall(r'\bfunction\s+([A-Za-z_][A-Za-z_0-9]*)\s*\(', path.read_text()))
assert rows, 'No assessed capability inventory'
assert len({row[0] for row in rows}) == len(rows), 'Duplicate capability identifiers'
missing = [executor for _, _, executor in rows if executor not in definitions]
assert not missing, 'Advertised executor definitions missing: ' + ', '.join(missing)
dispatch = (root / 'includes/agent-tool-authorization-v400.php').read_text()
dispatched = set(re.findall(r'\b([A-Za-z_][A-Za-z_0-9]*(?:_query_v[0-9a-z]+|_chat_tool))\s*\(', dispatch))
assessed = {executor for _, _, executor in rows}
report = {'assessed_capabilities': len(rows), 'executor_bindings_verified': True,
          'unassessed_dispatchers': sorted(dispatched - assessed),
          'coverage_note': 'Unassessed dispatchers remain outside this initial inventory; they are not disabled or advertised as unsupported.'}
print(json.dumps(report, indent=2))
print('CAPABILITY_INVENTORY_AUDIT=PASS')
