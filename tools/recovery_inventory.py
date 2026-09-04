#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXCLUDED_PARTS = {'.git', '.cache', 'cache', 'tmp', 'node_modules', 'vendor'}
TEXT_EXTENSIONS = {
    '.php', '.js', '.mjs', '.cjs', '.css', '.md', '.txt', '.json', '.sql',
    '.yml', '.yaml', '.py', '.html', '.htaccess', ''
}
CODE_EXTENSIONS = {'.php', '.js', '.mjs', '.cjs', '.css', '.py', '.sql'}
VERSIONED_NAME_RE = re.compile(r'(?i)(?:^|[-_])v(\d+)(?=[-_.]|$)')
PHP_INCLUDE_RE = re.compile(r"(?:require|require_once|include|include_once)\s*(?:\(?\s*)?(?:__DIR__\s*\.\s*)?['\"]([^'\"]+)['\"]")
URL_LITERAL_RE = re.compile(r"['\"]([^'\"]+\.(?:php|js|css)(?:\?[^'\"]*)?)['\"]", re.I)
TABLE_RE = re.compile(
    r"(?i)\b(?:FROM|JOIN|INTO|UPDATE|TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?|ALTER\s+TABLE|REFERENCES)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?"
)


def is_excluded(path: Path) -> bool:
    return any(part in EXCLUDED_PARTS for part in path.parts)


def iter_files() -> list[Path]:
    files = []
    for path in ROOT.rglob('*'):
        if not path.is_file():
            continue
        rel = path.relative_to(ROOT)
        if is_excluded(rel):
            continue
        files.append(rel)
    return sorted(files, key=lambda p: p.as_posix().lower())


def read_text(rel: Path) -> str | None:
    path = ROOT / rel
    if rel.suffix.lower() not in TEXT_EXTENSIONS and rel.name != '.htaccess':
        return None
    try:
        return path.read_text(encoding='utf-8')
    except (UnicodeDecodeError, OSError):
        return None


def digest(rel: Path) -> str:
    h = hashlib.sha256()
    with (ROOT / rel).open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def clean_ref(value: str) -> str:
    value = value.strip().replace('\\', '/')
    value = value.split('?', 1)[0].split('#', 1)[0]
    while value.startswith('./'):
        value = value[2:]
    return value


def resolve_local_ref(source: Path, raw: str) -> Path | None:
    ref = clean_ref(raw)
    if not ref or ref.startswith(('http://', 'https://', '//', 'data:', '#')):
        return None
    if ref.startswith('/'):
        candidate = Path(ref.lstrip('/'))
    else:
        candidate = source.parent / ref
    parts: list[str] = []
    for part in candidate.parts:
        if part in ('', '.'):
            continue
        if part == '..':
            if parts:
                parts.pop()
            continue
        parts.append(part)
    return Path(*parts) if parts else None


def classify_runtime(rel: Path) -> str:
    p = rel.as_posix()
    if p.startswith('tests/'):
        return 'test'
    if p.startswith('tools/'):
        return 'tool'
    if p.startswith('api/') and rel.suffix == '.php':
        return 'api'
    if p.startswith('admin/') and rel.suffix == '.php':
        return 'admin-page'
    if p.startswith('includes/') and rel.suffix == '.php':
        return 'include'
    if len(rel.parts) == 1 and rel.suffix == '.php':
        return 'root-page'
    if rel.suffix in {'.js', '.mjs', '.cjs'}:
        return 'javascript'
    if rel.suffix == '.css':
        return 'stylesheet'
    if rel.suffix == '.sql':
        return 'schema-migration'
    if rel.suffix in {'.md', '.txt'}:
        return 'documentation'
    return 'other'


def main() -> int:
    parser = argparse.ArgumentParser(description='Build a static recovery inventory for the Stonefellow codebase.')
    parser.add_argument('--output', default='recovery-inventory.generated.md')
    parser.add_argument('--json-output', default='recovery-inventory.generated.json')
    parser.add_argument('--fail-on-missing-local-ref', action='store_true')
    args = parser.parse_args()

    files = iter_files()
    existing = {p.as_posix() for p in files}
    by_extension = Counter()
    by_kind = Counter()
    by_top_dir = Counter()
    size_by_kind = Counter()
    sha_groups: dict[str, list[Path]] = defaultdict(list)
    versioned: list[tuple[Path, int]] = []
    includes: dict[str, list[str]] = defaultdict(list)
    local_refs: dict[str, list[str]] = defaultdict(list)
    missing_refs: dict[str, list[str]] = defaultdict(list)
    tables = Counter()
    tables_by_file: dict[str, list[str]] = {}

    for rel in files:
        stat = (ROOT / rel).stat()
        ext = rel.suffix.lower() or rel.name.lower() if rel.name.startswith('.') else rel.suffix.lower() or '(none)'
        by_extension[ext] += 1
        kind = classify_runtime(rel)
        by_kind[kind] += 1
        size_by_kind[kind] += stat.st_size
        by_top_dir[rel.parts[0] if len(rel.parts) > 1 else '(root)'] += 1
        sha_groups[digest(rel)].append(rel)

        match = VERSIONED_NAME_RE.search(rel.name)
        if match:
            versioned.append((rel, int(match.group(1))))

        text = read_text(rel)
        if text is None:
            continue

        if rel.suffix == '.php':
            for raw in PHP_INCLUDE_RE.findall(text):
                target = resolve_local_ref(rel, raw)
                if target:
                    includes[rel.as_posix()].append(target.as_posix())

        if rel.suffix.lower() in {'.php', '.js', '.mjs', '.cjs', '.html'}:
            for raw in URL_LITERAL_RE.findall(text):
                target = resolve_local_ref(rel, raw)
                if not target:
                    continue
                t = target.as_posix()
                local_refs[rel.as_posix()].append(t)
                if t not in existing:
                    missing_refs[rel.as_posix()].append(t)

        if rel.suffix.lower() in {'.php', '.sql'}:
            found = sorted(set(TABLE_RE.findall(text)), key=str.lower)
            if found:
                tables_by_file[rel.as_posix()] = found
                tables.update(found)

    duplicate_groups = [group for group in sha_groups.values() if len(group) > 1]
    duplicate_groups.sort(key=lambda g: (-len(g), g[0].as_posix().lower()))
    version_counts = Counter(version for _, version in versioned)

    root_pages = sorted(p.as_posix() for p in files if classify_runtime(p) == 'root-page')
    admin_pages = sorted(p.as_posix() for p in files if classify_runtime(p) == 'admin-page')
    api_pages = sorted(p.as_posix() for p in files if classify_runtime(p) == 'api')
    includes_list = sorted(p.as_posix() for p in files if classify_runtime(p) == 'include')
    tests = sorted(p.as_posix() for p in files if classify_runtime(p) == 'test' and p.suffix.lower() in {'.mjs', '.cjs', '.php', '.py'})

    payload = {
        'total_files': len(files),
        'total_bytes': sum((ROOT / p).stat().st_size for p in files),
        'counts_by_extension': dict(by_extension.most_common()),
        'counts_by_kind': dict(by_kind.most_common()),
        'counts_by_top_directory': dict(by_top_dir.most_common()),
        'root_php_pages': root_pages,
        'admin_php_pages': admin_pages,
        'api_php_endpoints': api_pages,
        'php_includes': includes_list,
        'tests': tests,
        'database_tables_referenced': sorted(tables),
        'database_table_reference_counts': dict(tables.most_common()),
        'include_edges': {k: sorted(set(v)) for k, v in sorted(includes.items())},
        'missing_local_asset_refs': {k: sorted(set(v)) for k, v in sorted(missing_refs.items())},
        'versioned_file_count': len(versioned),
        'version_numbers': dict(sorted(version_counts.items())),
        'duplicate_content_groups': [[p.as_posix() for p in group] for group in duplicate_groups],
    }

    json_path = ROOT / args.json_output
    json_path.write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')

    lines: list[str] = []
    lines.append('# Generated Recovery Inventory')
    lines.append('')
    lines.append('Generated deterministically by `tools/recovery_inventory.py`. Do not hand-edit this file.')
    lines.append('')
    lines.append('## Repository totals')
    lines.append('')
    lines.append(f'- Files: **{len(files):,}**')
    lines.append(f'- Size: **{payload["total_bytes"] / 1024 / 1024:.2f} MiB**')
    lines.append(f'- Root PHP pages: **{len(root_pages)}**')
    lines.append(f'- Admin PHP pages: **{len(admin_pages)}**')
    lines.append(f'- API PHP endpoints: **{len(api_pages)}**')
    lines.append(f'- PHP include modules: **{len(includes_list)}**')
    lines.append(f'- Executable/static contract tests: **{len(tests)}**')
    lines.append(f'- Version-tagged filenames: **{len(versioned)}**')
    lines.append(f'- Duplicate-content groups: **{len(duplicate_groups)}**')
    lines.append('')

    lines.append('## Counts by runtime kind')
    lines.append('')
    for key, count in by_kind.most_common():
        lines.append(f'- `{key}`: {count}')
    lines.append('')

    lines.append('## Root PHP pages')
    lines.append('')
    lines.extend(f'- `{p}`' for p in root_pages)
    lines.append('')

    lines.append('## Admin PHP pages')
    lines.append('')
    lines.extend(f'- `{p}`' for p in admin_pages)
    lines.append('')

    lines.append('## API endpoints')
    lines.append('')
    lines.extend(f'- `{p}`' for p in api_pages)
    lines.append('')

    lines.append('## Database table names referenced by PHP/SQL')
    lines.append('')
    lines.extend(f'- `{name}` ({tables[name]} references)' for name in sorted(tables, key=str.lower))
    lines.append('')

    lines.append('## Versioned-file concentration')
    lines.append('')
    for version, count in sorted(version_counts.items()):
        if count >= 2:
            lines.append(f'- v{version}: {count} files')
    lines.append('')

    lines.append('## Duplicate-content groups')
    lines.append('')
    if duplicate_groups:
        for group in duplicate_groups[:80]:
            if len(group) < 2:
                continue
            size = (ROOT / group[0]).stat().st_size
            lines.append(f'- {len(group)} files · {size:,} bytes: ' + ', '.join(f'`{p.as_posix()}`' for p in group))
    else:
        lines.append('- None')
    lines.append('')

    lines.append('## Local PHP/JS/CSS references that do not resolve to a tracked file')
    lines.append('')
    if missing_refs:
        for source, refs in sorted(missing_refs.items()):
            clean = sorted(set(refs))
            if clean:
                lines.append(f'- `{source}` → ' + ', '.join(f'`{ref}`' for ref in clean))
    else:
        lines.append('- None found by the static literal-reference scan.')
    lines.append('')
    lines.append('> Note: dynamic URLs, runtime-generated paths, routed image/media endpoints, query-string rewriting, and references intentionally supplied by deployment are not fully resolvable through static scanning.')

    output_path = ROOT / args.output
    output_path.write_text('\n'.join(lines) + '\n', encoding='utf-8')

    print(f'RECOVERY_INVENTORY_FILES={len(files)}')
    print(f'RECOVERY_INVENTORY_ROOT_PHP={len(root_pages)}')
    print(f'RECOVERY_INVENTORY_ADMIN_PHP={len(admin_pages)}')
    print(f'RECOVERY_INVENTORY_API_PHP={len(api_pages)}')
    print(f'RECOVERY_INVENTORY_TESTS={len(tests)}')
    print(f'RECOVERY_INVENTORY_VERSIONED={len(versioned)}')
    print(f'RECOVERY_INVENTORY_DUPLICATE_GROUPS={len(duplicate_groups)}')
    print(f'RECOVERY_INVENTORY_MISSING_REF_SOURCES={len(missing_refs)}')
    print(f'RECOVERY_INVENTORY_REPORT={output_path.relative_to(ROOT)}')

    if args.fail_on_missing_local_ref and missing_refs:
        return 2
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
