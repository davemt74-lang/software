#!/usr/bin/env python3
"""Audit assessed executor bindings; expose remaining discovery coverage without inventing support."""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = (root / 'includes/agent-capability-awareness-v1.php').read_text()
rows = re.findall(r"vp3_capability_row_v1\('([^']+)','[^']*','(read|write)','([^']+)'", source)
definitions = set()
locations = {}
for path in (root / 'includes').glob('*.php'):
    text = path.read_text()
    for match in re.finditer(r'\bfunction\s+([A-Za-z_][A-Za-z_0-9]*)\s*\(', text):
        definitions.add(match[1])
        locations[match[1]] = {'file': str(path.relative_to(root)), 'line': text[:match.start()].count('\n') + 1}
assert rows, 'No assessed capability inventory'
assert len({row[0] for row in rows}) == len(rows), 'Duplicate capability identifiers'
missing = [executor for _, _, executor in rows if executor not in definitions]
assert not missing, 'Advertised executor definitions missing: ' + ', '.join(missing)
dispatch = (root / 'includes/agent-tool-authorization-v400.php').read_text()
wrappers = {'vp3_agent_chat_tool_query_v1', 'vp3_agent_tool_execute_query_v400'}
explicit = {'agent_work_control_chat_v173', 'homeserver_agent_read_v230_query',
            'vp3_profile_webmcp_return_followthrough_v195', 'vp3_agent_profile_webmcp_plan_v193'}
dispatched = set()
for match in re.finditer(r'\b([A-Za-z_][A-Za-z_0-9]*)\s*\(', dispatch):
    name = match[1]
    if dispatch[max(0, match.start()-12):match.start()].rstrip().endswith('function'):
        continue
    if name not in wrappers and (name in explicit or re.search(r'(?:_query_v[0-9a-z]+|_chat_tool)$', name)):
        dispatched.add(name)
assert explicit <= dispatched, 'Audit dropped a nonstandard dispatcher binding'
assert dispatched <= definitions, 'Dispatched executor definition is missing'
assessed = {executor for _, _, executor in rows}
report = {'assessed_capabilities': len(rows), 'executor_bindings_verified': True,
          'legacy_dispatchers_found': len(dispatched),
          'assessed_dispatchers': sorted(dispatched & assessed),
          'unassessed_dispatchers': sorted(dispatched - assessed),
          'unassessed_source_locations': {name: locations[name] for name in sorted(dispatched - assessed)},
          'coverage_note': 'Unassessed dispatchers remain outside this initial inventory; they are not disabled or advertised as unsupported.'}
print(json.dumps(report, indent=2))
print('CAPABILITY_INVENTORY_AUDIT=PASS')
